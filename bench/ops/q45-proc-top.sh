#!/bin/sh
# Q45 实测：容器内没有 ps / top 时的「top」——两次采样 /proc/*/stat 求差
#
# /proc/<pid>/stat 的 utime / stime 是「进程累计消耗的 CPU 时间」，单位 jiffies
# （getconf CLK_TCK 通常是 100，即 1 jiffy = 10ms）。两个时刻各读一次：
#     CPU% = (Δutime + Δstime) ÷ CLK_TCK ÷ 采样秒数 × 100
# 注意 stat 里的 comm 字段可能带空格和括号，所以要先砍掉最后一个 ")" 再数字段。
#
# 顺带读 /proc/<pid>/status 的两个字段，用来区分「在算」和「在等」：
#   State                    R = 在跑 / S = 睡眠
#   voluntary_ctxt_switches  主动让出 CPU 的次数（等 IO、等锁、sleep 才会涨）
#
# 后半段依赖 FPM 状态页：宿主机上先 sh bench/ops/q44-status.sh on（跑完 off 还原配置）
#
# 用法: docker exec learn-php sh /app/bench/ops/q45-proc-top.sh [采样秒数]

WINDOW=${1:-2}
TICK=$(getconf CLK_TCK 2>/dev/null || echo 100)

snap() {
    for p in /proc/[0-9]*; do
        [ -r "$p/stat" ] || continue
        pid=${p#/proc/}
        # cmdline 里可能有换行（php -r '...' 这种），要一起换成空格，否则整个表会被撑破
        cmd=$(tr '\0\n' '  ' < "$p/cmdline" 2>/dev/null | cut -c1-44)
        [ -z "$cmd" ] && cmd="[$(cat "$p/comm" 2>/dev/null)]"
        # 砍掉 "pid (comm) " 前缀：comm 里可能有空格/括号，用最后一个 ") " 切
        rest=$(sed 's/.*) //' "$p/stat" 2>/dev/null)
        # shellcheck disable=SC2086
        set -- $rest
        state=$1
        # ${12} 必须带花括号，$12 会被解析成 ${1}2
        utime=${12}
        stime=${13}
        vol=$(awk '/^voluntary_ctxt_switches:/{print $2}' "$p/status" 2>/dev/null)
        nonvol=$(awk '/^nonvoluntary_ctxt_switches:/{print $2}' "$p/status" 2>/dev/null)
        echo "$pid|$state|$utime|$stime|${vol:-0}|${nonvol:-0}|$cmd"
    done
}

T0=$(date +%s%N)
snap > /tmp/q45-before.txt
T1=$(date +%s%N)
sleep "$WINDOW"
T2=$(date +%s%N)
snap > /tmp/q45-after.txt
T3=$(date +%s%N)

# 两次采样之间「真正过去了多久」要用时间戳算，不能用 sleep 的秒数：
# 采样本事就要遍历上百个 /proc 并多次 fork，快照自己是要花时间的。
# 每个 pid 的实际窗口 = T2-T1 再加/减它在快照里的位置偏差（≤ 一次快照的耗时）。
# 所以 CPU% 拿来看排序很准，要绝对值就老实用两个时间戳单独夹一次（见本脚本末尾）。
US=$(awk -v a="$T1" -v b="$T2" 'BEGIN{printf "%.3f", (b-a)/1e9}')
SNAPMS=$(awk -v a="$T0" -v b="$T3" 'BEGIN{printf "%.2f", (b-a)/1e9}')

echo "采样窗口 ${US}s（sleep ${WINDOW}s + 快照耗时 ${SNAPMS}s）   CLK_TCK=$TICK（1 jiffy = $((1000 / TICK))ms）"
echo
printf "%-7s %6s %8s %8s %7s %8s %8s  %s\n" "PID" "State" "Δutime" "Δstime" "CPU%" "Δvolctx" "Δnonvol" "CMD"
printf "%s\n" "------------------------------------------------------------------------------------------------"

awk -F'|' -v t="$TICK" -v w="$US" '
    NR==FNR { u[$1]=$3; s[$1]=$4; v[$1]=$5; nv[$1]=$6; next }
    {
        du = $3 - u[$1]; ds = $4 - s[$1]; dv = $5 - v[$1]; dn = $6 - nv[$1]
        if (du < 0 || ds < 0) next          # 进程换了 pid，跳过
        printf "%-7s %6s %8d %8d %6.1f%% %8d %8d  %s\n", $1, $2, du, ds, (du+ds)/(t*w)*100, dv, dn, $7
    }
' /tmp/q45-before.txt /tmp/q45-after.txt | sort -k5 -rn | head -14

echo
echo "读法："
echo "  这张表的 CPU% 只能用来排序，别当绝对值：每个 pid 的真实窗口"
echo "  = 上面那个窗口 + 它在两次快照里的位置差。进程数一直在变（短命进程一茬茬地生灭），"
echo "  位置差可以是 ±1 秒，2 秒窗口上就是 ±50% 的误差。要绝对值就只夹一个 pid，"
echo "  像下面最后一段那样自己打时间戳。"
echo "  State=R，Δutime 大           → 真的在烧 CPU，下一步上 profiler 找函数"
echo "  State=S，Δutime≈0            → 它在等，不是 CPU 问题"
echo "  两个上下文切换计数器是补充证据："
echo "    Δnonvoluntary 涨 = 被调度器抢占（说明它是真的在跑，只是被抢）"
echo "    Δvoluntary   涨 = 自己主动让出（等 IO / 锁 / sleep；一次长 poll 只会 +1）"
echo "  一个 php-fpm worker 顶多烧满 1 个核：单进程 CPU% 到 100% 就是满了"
echo
echo "===== 单独看那个「卡在下游」的 worker（对比上面烧 CPU 的那行） ====="
# 用 FPM 状态页找出正在服务慢请求的 worker，再对它做同样的两段采样。
# 这个脚本是在容器里跑的，所以走容器内的 nginx（80），不是宿主机的 9311
STATUS='http://127.0.0.1/fpm-status.php'
WPID=$(curl -s "$STATUS?full" | awk '/^pid:/{p=$2} /^request URI:/{if ($3 ~ /down=/) print p}')
if [ -n "$WPID" ]; then
    # 只夹这一个 pid，时间戳精确到纳秒，这里算出来的 CPU% 是可以当绝对值用的
    A0=$(date +%s%N)
    A=$(sed 's/.*) //' "/proc/$WPID/stat" | awk '{print $12, $13}')
    VA=$(awk '/^nonvoluntary_ctxt_switches:/{print $2}' "/proc/$WPID/status")
    sleep "$WINDOW"
    B0=$(date +%s%N)
    B=$(sed 's/.*) //' "/proc/$WPID/stat" | awk '{print $12, $13}')
    VB=$(awk '/^nonvoluntary_ctxt_switches:/{print $2}' "/proc/$WPID/status")
    # A/B 是 "utime stime" 两个字段，先各自求和再算，别把字段号弄错
    CPU=$(echo "$A $B $A0 $B0" | awk -v t="$TICK" \
        '{du=$1+$2; ds=$3+$4; printf "%.1f%%", (ds-du)/(t*($6-$5)/1e9)*100}')
    echo "worker pid = $WPID，正在跑 down= 的请求"
    echo "  State            = $(awk '/^State:/{print $2, $3}' /proc/$WPID/status)"
    echo "  第一次 utime/stime = $A"
    echo "  ${WINDOW}s 后          = $B   ← 一动不动"
    echo "  CPU%（精确夹这个 pid）= $CPU"
    echo "  Δnonvoluntary    = $((VB - VA))"
    echo "  当前系统调用     = $(cat /proc/$WPID/syscall 2>&1 | cut -c1-40)"
else
    echo "（没有正在跑的 down= 请求，跳过；跑之前先发一个慢请求）"
fi
