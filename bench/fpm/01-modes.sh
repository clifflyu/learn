#!/bin/sh
# Q4 实测：三种进程管理模式下的 worker 数量行为
# 必须在宿主机跑（要 docker exec 改容器内配置）
# 用法: sh bench/fpm/01-modes.sh

C=learn-php
CONF=/usr/local/etc/php-fpm.d/application.conf
URL=http://localhost:9311/bench/fpm/slow.php?ms=2500

# 只数首参数恰为 "php-fpm: pool www" 的进程。
# 用子串匹配会把 `sh -c '...php-fpm...'` 这类自身也数进去，多算 1。
workers() {
    docker exec $C sh -c '
        n=0
        for p in /proc/[0-9]*; do
            c=$(tr "\0" "\n" < $p/cmdline 2>/dev/null | head -1)
            [ "$c" = "php-fpm: pool www" ] && n=$((n+1))
        done
        echo $n'
}

set_pm() {
    docker exec $C sh -c "
        sed -i 's/^pm = .*/pm = $1/'                            $CONF
        sed -i 's/^pm.max_children = .*/pm.max_children = $2/'  $CONF
        sed -i 's/^pm.start_servers = .*/pm.start_servers = $3/' $CONF
        sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = $4/' $CONF
        sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = $5/' $CONF
        supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
    sleep 2
}

echo "宿主机内存: $(free -m | awk '/Mem:/{print $7" MB 可用 / "$2" MB 总量"}')"
echo "pool 上限 pm.max_children = 6，下面每轮都重启 FPM 保证是干净状态"
echo
printf "%-10s %-14s %-22s %s\n" "模式" "空闲" "并发 6 请求期间" "请求结束 2s 后"
printf "%s\n" "------------------------------------------------------------------------"

for spec in "static 6 0 0 0" "dynamic 6 2 1 3" "ondemand 6 0 0 0"; do
    set -- $spec
    set_pm "$1" "$2" "$3" "$4" "$5"

    idle=$(workers)

    for i in 1 2 3 4 5 6; do
        curl -s -o /dev/null "$URL" &
    done
    # 采样三次，看进程池爬升过程
    samples=""
    for t in 1 2 3; do
        sleep 0.7
        samples="$samples$(workers) "
    done
    wait
    sleep 2
    after=$(workers)

    printf "%-10s %-14s %-22s %s\n" "$1" "$idle" "$samples" "$after"
done

echo
echo "恢复默认配置（dynamic / max_children=5）"
set_pm dynamic 5 2 1 3
