#!/bin/sh
# Q44 实测④：进程池打满 —— 接口变慢的第四种成因，也是唯一「应用代码完全没问题」的那种
#
# 和 bench/fpm/02-capacity.sh 的区别：那个证明的是「池子不够只会排队、不会报错」，
# 这里量的是**排队造成的延迟阶梯**，以及一个要命的副作用：
# 池子满的时候连 FPM 状态页都跟着排队——监控在最需要它的时候变钝了。
#
# 依赖 FPM 状态页：跑之前先 sh bench/ops/q44-status.sh on（跑完 off 还原配置）
# 用法: sh bench/ops/q44-pool.sh

C=learn-php
CONF=/usr/local/etc/php-fpm.d/application.conf
URL='http://localhost:9311/bench/fpm/slow.php?ms=200'    # 每个请求占住 worker 200ms
STATUS='http://localhost:9311/fpm-status.php'

workers() {
    docker exec $C sh -c '
        n=0
        for p in /proc/[0-9]*; do
            c=$(tr "\0" "\n" < $p/cmdline 2>/dev/null | head -1)
            [ "$c" = "php-fpm: pool www" ] && n=$((n+1))
        done
        echo $n'
}

# 改完配置要等新 worker 起来、旧 worker 退干净，否则测的是上一轮的池子
set_pool() {
    docker exec $C sh -c "
        sed -i 's/^pm = .*/pm = static/'                       $CONF
        sed -i 's/^pm.max_children = .*/pm.max_children = $1/' $CONF
        supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
    i=0
    while [ $i -lt 40 ]; do
        [ "$(workers)" = "$1" ] && return 0
        sleep 0.5
        i=$((i + 1))
    done
    echo "（警告：worker 数没稳定到 $1，实测值是 $(workers)）"
}

echo "===== 1. 延迟阶梯：20 个并发请求，每个占住 worker 200ms ====="
echo "理想情况（池子够）：每个请求约 200ms。池子越小，后面的请求等得越久。"
echo
printf "%-13s %8s %9s %9s %9s %10s\n" "max_children" "worker" "P50(ms)" "P99(ms)" "max(ms)" "总墙钟(s)"
printf "%s\n" "--------------------------------------------------------------------------"

for MAX in 2 5 20; do
    set_pool $MAX
    W=$(workers)
    TMP=$(mktemp)
    S=$(date +%s%N)
    seq 1 20 | xargs -P 20 -I{} curl -s -o /dev/null -w '%{time_total}\n' "$URL" > "$TMP"
    E=$(date +%s%N)

    read P50 P99 MAXT <<EOF
$(sort -n "$TMP" | awk '{a[NR]=$1} END{printf "%.0f %.0f %.0f", a[int(NR*0.5)]*1000, a[int(NR*0.99)]*1000, a[NR]*1000}')
EOF
    rm -f "$TMP"
    printf "%-13s %8s %9s %9s %9s %10s\n" "$MAX" "$W" "$P50" "$P99" "$MAXT" \
        "$(awk -v s=$S -v e=$E 'BEGIN{printf "%.2f", (e-s)/1e9}')"
done

echo
echo "→ max_children=2 时是标准的阶梯：前 2 个 200ms，接着 2 个 400ms …… 最后 2 个约 2s。"
echo "  排队延迟 = ceil(并发数 ÷ 池子大小) × 单请求耗时，和「代码有多快」无关。"

echo
echo "===== 2. 池子满的时候，连状态页都要排队（监控变钝） ====="
set_pool 2
echo "先占满池子：10 个请求 × 2000ms，池子只有 2 个 worker → 大约 10 秒内池子都是满的"
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "$URL&ms=2000" & done
sleep 1
echo "池子满时取状态页: $(curl -s -o /dev/null -m 60 -w '%{time_total}' "$STATUS") s"
wait
sleep 3
echo "池子空时取状态页: $(curl -s -o /dev/null -m 60 -w '%{time_total}' "$STATUS") s"

echo
echo "压测累计的计数器（listen queue > 0 = 确实排过队）："
curl -s "$STATUS" | grep -E 'listen queue|max children reached|max active processes|total processes'

echo
echo "===== 3. 还原池子配置 ====="
docker exec $C sh -c "
    sed -i 's/^pm = .*/pm = dynamic/'                     $CONF
    sed -i 's/^pm.max_children = .*/pm.max_children = 6/' $CONF
    supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
sleep 2
echo "pm = dynamic / pm.max_children = 6 / worker 数 = $(workers)"
