#!/bin/sh
# Q44 实测：FPM 自带的「慢请求调用栈」开关，以及它在容器里为什么是哑的
#
# 生产上定位「慢在哪一行」有两条路：
#   1) request_slowlog_timeout + slowlog  → FPM 自带的，零成本，不用装扩展
#   2) 采样 profiler（excimer / php-spx）  → 更细，能看到热点占比
# 这条路 1 在这个环境里跑不通，原因本身就是个值得记住的坑：
# FPM 打调用栈靠 ptrace(PTRACE_ATTACH) 挂到 worker 上读它的栈，而容器默认没有
# CAP_SYS_PTRACE（docker 的 CapEff 里就没有这一位），于是它只在 error log 里留一行
#   ERROR: failed to ptrace(ATTACH) child 39679: Operation not permitted (1)
# 然后静默放弃 —— 不看 error log 会以为「我的请求不够慢」。
#
# 用法: sh bench/ops/q44-slowlog.sh    （跑完自动还原配置）

C=learn-php
CONF=/usr/local/etc/php-fpm.d/application.conf
SLOWLOG=/var/log/php-fpm.slow.log
URL='http://localhost:9311/bench/ops/q44-slowreq.php'

echo "===== 1. 打开 request_slowlog_timeout（1 秒）并指定 slowlog 落盘位置 ====="
docker exec $C sh -c "
    sed -i 's|^;*request_slowlog_timeout = .*|request_slowlog_timeout = 1s|' $CONF
    sed -i \"s|^;*slowlog = .*|slowlog = $SLOWLOG|\" $CONF
    : > $SLOWLOG
    supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
sleep 3
docker exec $C sh -c "grep -n '^request_slowlog_timeout\|^slowlog' $CONF"
echo "（FPM 的 error_log = /proc/self/fd/2，也就是容器的 stderr，用 docker logs 看）"

echo
echo "===== 2. 发一个超过阈值的请求（rows=1500，实测约 1.3s） ====="
curl -s -o /dev/null -m 30 -w '  客户端看到的总耗时: %{time_total} s\n' "$URL?rows=1500"

echo
echo "===== 3. slowlog 文件里有什么 ====="
docker exec $C sh -c "wc -c $SLOWLOG; cat $SLOWLOG"
echo "→ 空的。但请求确实慢了 —— 真相只在 FPM 的 error log 里："
docker logs --since 3m $C 2>&1 | grep -i "ptrace\|slowlog" | tail -3

echo
echo "===== 4. 结论 ====="
echo "容器没有 CAP_SYS_PTRACE（本环境 CapEff=00000000a80425fb，第 19 位为 0），"
echo "FPM 挂不上 worker，打不出调用栈 —— 这个开关在容器里默认是哑的。"
echo "真要用：给容器加 SYS_PTRACE（或 --privileged），验证过一次再依赖它；"
echo "否则直接用采样型 profiler（本仓库用 excimer，见 bench/ops/q45-excimer.php）。"

echo
echo "===== 5. 还原配置 ====="
sh /opt/learn/bench/ops/q45-restore-fpm.sh
docker exec $C sh -c "rm -f $SLOWLOG"
