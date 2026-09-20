#!/bin/sh
# Q45 实测：从「机器 CPU 高」到「哪个进程在烧」，四步
#
# 1. docker stats        → 哪个容器（粒度是容器，不是进程）
# 2. 宿主机 top          → 哪个进程（所有容器的进程混在一起，靠 cmdline 认人）
# 3. /proc/<pid>/cgroup  → 这个宿主 pid 属于哪个容器（top 里看不出来）
# 4. 进容器读 /proc      → 容器内的 pid 是另一套编号，要靠 cmdline 对上
#
# 用法: sh bench/ops/q45-cpu-locate.sh
#       （脚本自己起一个烧 CPU 的进程，测完杀掉）

C=learn-php
BURN=/app/bench/ops/q45-cpu-burn.php

echo "===== 0. 起一个烧 CPU 的进程（模拟线上某个接口在死循环/大计算） ====="
docker exec -d $C php $BURN 40
sleep 3

echo
echo "===== 1. docker stats：哪个容器 ====="
docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' | sort -k2 -rn | head -5
echo "→ 一眼看出 learn-php 高。但容器里可能同时跑着 cron / 队列 / 多个接口，"
echo "  这个数字是「容器整体」，下一步要找进程。"

echo
echo "===== 2. 宿主机 top：哪个进程 ====="
top -bn1 -o %CPU 2>/dev/null | head -12
echo "→ top 看不到容器归属：所有容器的进程都混在同一个 pid 空间里。"

echo
echo "===== 3. 宿主 pid → 容器：读 /proc/<pid>/cgroup ====="
HPID=$(pgrep -f "q45-cpu-burn.php" | head -1)
echo "候选宿主 pid = $HPID  cmdline = $(tr '\0' ' ' < /proc/$HPID/cmdline)"
echo "/proc/$HPID/cgroup:"
cat /proc/$HPID/cgroup
CID=$(sed 's/.*docker-\(.\{12\}\).*/\1/' /proc/$HPID/cgroup)
echo "→ 容器 id 前 12 位 = $CID"
docker ps --no-trunc --format '{{.ID}}\t{{.Names}}\t{{.Image}}' | grep "^$CID" | cut -c1-100
echo "→ 这一步是 docker top 失效（容器里没 ps）时的替代方案。"

echo
echo "===== 4. 容器内的 pid 是另一套编号，用 cmdline 对上 ====="
docker exec $C sh -c 'for p in /proc/[0-9]*; do
    c=$(tr "\0" " " < $p/cmdline 2>/dev/null)
    case "$c" in "php /app/bench/ops/q45-cpu-burn.php"*) echo "${p#/proc/} -> $c";; esac
done'
echo "→ 宿主机上是 $HPID，容器里是另一个号：pid namespace 隔离。"

echo
echo "===== 5. 进容器按 CPU 排序（容器内没有 ps/top） ====="
docker exec $C sh /app/bench/ops/q45-proc-top.sh 2 2>&1 | head -8

echo
echo "===== 6. 收尾：杀掉烧 CPU 的进程 ====="
docker exec $C sh -c 'for p in /proc/[0-9]*; do
    c=$(tr "\0" " " < $p/cmdline 2>/dev/null)
    case "$c" in "php /app/bench/ops/q45-cpu-burn.php"*) kill "${p#/proc/}" && echo "killed ${p#/proc/}";; esac
done'
sleep 1
echo "还有一个坑：pkill -f q45-cpu-burn 会把自己的 shell 一起杀掉——"
echo "监控脚本的 cmdline 里就带着这个模式串。模式要锚定到 cmdline 开头，别用裸子串。"
