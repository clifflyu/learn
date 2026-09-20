#!/bin/sh
# Q44 实测②：接口变慢，但慢在「等下游」
#
# 用法: sh bench/ops/q44-downstream.sh
#
# 排查链条：
#   客户端 curl -w         → 只知道「服务端返回慢」，分不出是等 DB、等下游还是算得慢
#   FPM 状态页 ?full       → 找到正在跑这个 URI 的 worker 的 pid
#   /proc/<pid>/wchan      → 看它阻塞在哪个内核函数（等 socket = poll）
#   /proc/<pid>/stat       → 看它的 CPU 时间有没有涨（没涨 = 不是 CPU 问题）
#   下游直连计时           → 证明时间全部花在对方身上
#
# 依赖 FPM 状态页：跑之前先 sh bench/ops/q44-status.sh on（跑完 off 还原配置）

C=learn-php
BASE=http://localhost:9311
STATUS=$BASE/fpm-status.php
API=$BASE/bench/ops/q44-api.php

echo "===== 1. 客户端 curl -w：分段计时只能看到「卡在服务端」 ====="
printf "%-22s %10s %10s %10s %10s\n" "请求" "connect" "pretransfer" "starttransfer" "total"
for q in "" "?down=1500"; do
    curl -s -o /dev/null -w "%{time_connect} %{time_pretransfer} %{time_starttransfer} %{time_total}\n" \
        "$API$q" | awk -v q="${q:-（无下游）}" \
        '{printf "%-22s %10.4f %10.4f %10.4f %10.4f\n", q, $1, $2, $3, $4}'
done
echo "→ connect 一直是毫秒级：网络没问题，慢在服务端处理。客户端到此为止，下一步必须进服务端。"

echo
echo "===== 2. 服务端分段计时（应用自己埋点，回写响应头） ====="
for q in "" "?down=1500"; do
    printf "%-22s " "${q:-（无下游）}"
    curl -sD - -o /dev/null "$API$q" | grep -i '^x-time' | tr -d '\r' | tr '\n' ' '
    echo
done
echo "→ 一眼看出 1500ms 全在 downstream，self 和 db 没变。"

echo
echo "===== 3. 请求卡住时，这个 worker 到底在干什么（读 /proc） ====="
echo "--- 后台发一个 8 秒的请求 ---"
curl -s -o /dev/null "$API?down=8000" &
sleep 2

echo "--- FPM 状态页 ?full：找正在跑这个 URI 的 worker ---"
# 状态页要从容器外取：9311 是宿主机的映射端口，容器内的 nginx 是 80
WORKER=$(curl -s "$STATUS?full" | awk '
    /^pid:/         {pid=$2}
    /^state:/       {st=$2}
    /^request URI:/ {uri=$3; if (uri ~ /down=8000/) print pid" "st" "uri}')
echo "worker = $WORKER"
PID=$(echo "$WORKER" | cut -d' ' -f1)

if [ -n "$PID" ]; then
    echo
    echo "--- /proc/$PID/status 关键行（State） ---"
    docker exec $C sh -c "grep -E '^(State|VmRSS|Threads):' /proc/$PID/status"
    echo "--- /proc/$PID/wchan（阻塞在哪个内核函数） ---"
    docker exec $C sh -c "cat /proc/$PID/wchan; echo"
    echo "--- /proc/$PID/syscall（当前系统调用） ---"
    docker exec $C sh -c "cat /proc/$PID/syscall 2>/dev/null | cut -c1-60; echo"
    echo "--- /proc/$PID/stat 的 utime/stime（CPU 时间，单位 jiffies） ---"
    docker exec $C sh -c "awk '{print \"utime=\" \$14 \"  stime=\" \$15}' /proc/$PID/stat"
    sleep 2
    echo "--- 2 秒后再看一次 utime/stime：几乎不动 = 没在烧 CPU ---"
    docker exec $C sh -c "awk '{print \"utime=\" \$14 \"  stime=\" \$15}' /proc/$PID/stat"
fi

echo
echo "--- 同一时刻直连下游，量化「是谁的责任」 ---"
printf "下游自身耗时: %s s\n" "$(curl -s -o /dev/null -w '%{time_total}' "$BASE/bench/ops/q44-downstream.php?ms=800")"
printf "接口整条耗时: %s s\n" "$(curl -s -o /dev/null -w '%{time_total}' "$API?down=800")"
echo "→ 两者几乎相等，接口自己的开销可以忽略：瓶颈在下游。"

wait
echo
echo "===== 4. 对照：worker 真的在算的时候，utime 是涨的 ====="
echo "（用 bench/ops/q45-cpu-burn.php，见 Q45）"
