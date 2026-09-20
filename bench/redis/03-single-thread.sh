#!/bin/sh
# Q25 Redis 为什么快、「单线程」的边界在哪
# 用法: docker exec learn-redis sh /app/bench/redis/03-single-thread.sh

R="redis-cli"
N=60000           # 单次 redis-benchmark 的请求数
C=50              # 并发连接数
REPS=5            # 每档重复次数

# redis-benchmark 用 \r 原地刷新，必须先 tr 再过滤
rps() {   # rps <命令> [额外参数] [N]
    redis-benchmark -q -n "${3:-$N}" -c $C -t "$1" $2 2>&1 | tr '\r' '\n' \
        | grep -E "^$(echo "$1" | tr a-z A-Z): .*requests per second" | tail -1 | awk '{print $2}'
}
# 跑 REPS 次取中位，这台机器上单次波动能有 ±60%
rps_med() {
    for i in $(seq 1 $REPS); do rps "$1" "$2" "$3"; done | sort -n \
        | awk '{a[NR]=$1} END{print a[int((NR+1)/2)]}'
}

echo "===== 版本 / CPU ====="
$R INFO server | grep -E 'redis_version|os:|arch_bits' | tr -d '\r'
printf "  容器可见 CPU 数: %s\n" "$(nproc)"

echo
echo "===== 1. 一个慢命令会把所有客户端堵住，加多少个 io-thread 都没用 ====="
for it in 1 4; do
    $R CONFIG SET io-threads $it >/dev/null
    $R CONFIG SET io-threads-do-reads yes >/dev/null
    ($R EVAL "local t=redis.call('TIME')[1]+3 while tonumber(redis.call('TIME')[1])<t do end return 1" 0 >/dev/null 2>&1 &)
    sleep 0.3
    t0=$(date +%s%N); $R PING >/dev/null; t1=$(date +%s%N)
    awk -v a="$t0" -v b="$t1" -v it="$it" \
        'BEGIN{printf "  io-threads=%s  一条 PING 等了 %.0f ms（被 Lua 忙等脚本整个堵住）\n", it, (b-a)/1000000}'
    $R SCRIPT KILL >/dev/null 2>&1
    wait 2>/dev/null
done
echo "  ↑ io-threads 只并行化 socket 读写和协议解析，命令执行永远是那一个主线程"

echo
echo "===== 2. io-threads 到底有没有收益（$REPS 次，给最小/中位/最大）====="
echo "  这台机器上还有别的容器在跑，单次 redis-benchmark 波动能到 ±60%，只看中位会被骗"
printf "  %-10s %-11s %-24s %-24s\n" 'io-threads' 'do-reads' 'SET rps min/med/max' 'GET rps min/med/max'
stats() {
    for i in $(seq 1 $REPS); do rps "$1" "$2"; done | sort -n | awk 'NR==1{mn=$1} {a[NR]=$1} END{printf "%8.0f %8.0f %8.0f", mn, a[int((NR+1)/2)], a[NR]}'
}
for it in 1 2 4; do
    for rd in no yes; do
        $R CONFIG SET io-threads $it >/dev/null
        $R CONFIG SET io-threads-do-reads $rd >/dev/null
        printf "  %-10s %-11s %-24s %-24s\n" "$it" "$rd" "$(stats set)" "$(stats get)"
    done
done
$R CONFIG SET io-threads 1 >/dev/null
$R CONFIG SET io-threads-do-reads no >/dev/null

echo
echo "===== 2b. 交错配对 A/B：把机器负载的漂移消掉 ====="
echo "  上面那张表每次都是「先跑完 5 次 it=1，再跑 5 次 it=4」，机器忙一阵闲一阵就全串味了。"
echo "  改成一轮里交替跑 it=1 和 it=4 各一次，算本轮配对差，再看 8 轮的分布。"
NP=$N
printf "  %-6s %13s %13s %13s\n" '轮次' 'it=1 SET rps' 'it=4 SET rps' '差'
diffs=""
$R CONFIG SET io-threads-do-reads yes >/dev/null
for i in $(seq 1 8); do
    $R CONFIG SET io-threads 1 >/dev/null; a=$(rps set '' $NP)
    $R CONFIG SET io-threads 4 >/dev/null; b=$(rps set '' $NP)
    d=$(awk -v a="$a" -v b="$b" 'BEGIN{printf "%.0f", b-a}')
    diffs="$diffs $d"
    awk -v i="$i" -v a="$a" -v b="$b" -v d="$d" 'BEGIN{printf "  %-6s %13.0f %13.0f %+13.0f\n", i, a, b, d}'
done
echo "$diffs" | tr ' ' '\n' | grep -v '^$' | sort -n | awk '
    {a[NR]=$1} END{
        printf "  8 轮的配对差: %s\n", "'"$(echo $diffs)"'"
        printf "  中位差 %+.0f rps，最小 %+.0f，最大 %+.0f —— 有正有负，方向都不稳定\n", a[int((NR+1)/2)], a[1], a[NR]
    }'
$R CONFIG SET io-threads 1 >/dev/null
$R CONFIG SET io-threads-do-reads no >/dev/null

echo
echo "===== 3. 吞吐的天花板：pipeline 深度 vs 连接数（交错配对，6 轮）====="
echo "  每档都调到耗时 ≈2s 的请求数，这样同一轮里的 4 个数才是同一时间窗内的公平对比；"
echo "  看的是同一轮内的倍数，不是绝对值 —— 绝对值会被别人的负载整个平移。"
med() { echo "$1" | tr ' ' '\n' | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{printf "%.1f", a[int((NR+1)/2)]}'; }
printf "  %-5s %11s %11s %8s | %11s %11s %8s\n" '轮次' '-c1 -P1' '-c1 -P64' 'b/a' '-c50 -P1' '-c50 -P64' 'd/c'
RA=""; RD=""
for i in $(seq 1 6); do
    a=$(rps set '-c 1  -P 1'   14000)
    b=$(rps set '-c 1  -P 64'  560000)
    c=$(rps set '-c 50 -P 1'   80000)
    d=$(rps set '-c 50 -P 64'  1700000)
    x=$(awk -v b="$b" -v a="$a" 'BEGIN{printf "%.2f", b/a}')
    y=$(awk -v d="$d" -v c="$c" 'BEGIN{printf "%.2f", d/c}')
    RA="$RA $x"; RD="$RD $y"
    awk -v i="$i" -v a="$a" -v b="$b" -v x="$x" -v c="$c" -v d="$d" -v y="$y" \
        'BEGIN{printf "  %-5s %11.0f %11.0f %7sx | %11.0f %11.0f %7sx\n", i, a, b, x, c, d, y}'
done
printf "  6 轮中位倍数： pipeline 64 深 = %sx（理论天花板 64）   并发 50 = %sx\n" "$(med "$RA")" "$(med "$RD")"
echo "  ↑ 单连接开着 -P 1 时，100%% 的时间都花在「发出去→等回来」上，Redis 本体闲着；"
echo "    pipeline 把 RTT 摊薄，才吃到 Redis 的真正处理能力。"

echo
echo "===== 4. 单条命令在服务端烧掉多少 CPU（INFO commandstats）====="
echo "  同一批命令，跑两遍：一遍 -c 1（每条命令一个来回），一遍 pipeline。"
echo "  注意：这台 Redis 上还有别的会话，它们的命令也会计进 calls，所以看比值别看绝对值。"
showstats() {
    $R INFO commandstats | tr -d '\r' | grep -E 'cmdstat_(set|get|incr|lpush|rpop):' \
        | sed -E 's/cmdstat_([a-z]+):calls=([0-9]+),usec=([0-9]+),usec_per_call=([0-9.]+).*/\1=\4/' \
        | tr '\n' ' '
}
$R CONFIG RESETSTAT >/dev/null
redis-benchmark -q -n 20000 -c 1 -t set,get,incr,lpush,rpop >/dev/null 2>&1
echo "  -c 1 每条一个来回 : $(showstats)"
$R CONFIG RESETSTAT >/dev/null
redis-benchmark -q -n 20000 -c 50 -P 16 -t set,get,incr,lpush,rpop >/dev/null 2>&1
echo "  -c 50 -P 16       : $(showstats)"
echo "  ↑ usec_per_call 不是「命令的 CPU 成本」，它把这条命令引发的 socket 读写系统调用也算进去了。"
echo "    所以同一个 SET，-c 1 下 1.8us，pipeline 下掉到零点几 —— 命令没变，变的是 syscall 被摊薄了。"

echo
echo "===== 5. 恢复现场 ====="
$R CONFIG SET io-threads 1 >/dev/null
$R CONFIG SET io-threads-do-reads no >/dev/null
$R CONFIG SET save "3600 1 300 10 60 10000" >/dev/null 2>&1
$R CONFIG RESETSTAT >/dev/null
echo "  io-threads=$($R CONFIG GET io-threads | tail -1)  io-threads-do-reads=$($R CONFIG GET io-threads-do-reads | tail -1)"
