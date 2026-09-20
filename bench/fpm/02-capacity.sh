#!/bin/sh
# Q4 实测：pm.max_children 打满时请求会怎样
# 必须在宿主机跑
# 用法: sh bench/fpm/02-capacity.sh

C=learn-php
CONF=/usr/local/etc/php-fpm.d/application.conf
URL='http://localhost:9311/bench/fpm/slow.php?ms=2000'

workers() {
    docker exec $C sh -c '
        n=0
        for p in /proc/[0-9]*; do
            c=$(tr "\0" "\n" < $p/cmdline 2>/dev/null | head -1)
            [ "$c" = "php-fpm: pool www" ] && n=$((n+1))
        done
        echo $n'
}

set_max() {
    docker exec $C sh -c "
        sed -i 's/^pm = .*/pm = static/'                       $CONF
        sed -i 's/^pm.max_children = .*/pm.max_children = $1/' $CONF
        supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
    sleep 2
}

for MAX in 2 10; do
    set_max $MAX
    echo "===== pm = static, pm.max_children = $MAX ====="
    echo "worker 数: $(workers)"
    echo "同时发 10 个耗时 2 秒的请求（超出并发能力的会排队）："
    echo
    seq 1 10 | xargs -P 10 -I{} curl -s -o /dev/null \
        -w "%{http_code}  %{time_total}s\n" "$URL" | sort -k2 -n | cat -n | \
        awk '{printf "  第 %2s 个返回: HTTP %s 耗时 %s\n", $1, $2, $3}'
    echo
done

echo "恢复默认（dynamic / max_children=5）"
docker exec $C sh -c "
    sed -i 's/^pm = .*/pm = dynamic/'                       $CONF
    sed -i 's/^pm.max_children = .*/pm.max_children = 5/'   $CONF
    supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
