#!/bin/sh
# Q45 实测：FPM worker 的 VmRSS 曲线，以及 pm.max_requests 到底修了什么
#
# 池子锁成 static + max_children=1，保证所有请求都落在同一个 worker 上。
#
# 用法: sh bench/ops/q45-fpm-rss.sh > /tmp/q45-rss.txt 2>&1   （输出较长，建议重定向）
#       跑完记得执行 sh bench/ops/q45-restore-fpm.sh

C=learn-php
CONF=/usr/local/etc/php-fpm.d/application.conf
URL='http://localhost:9311/bench/ops/q45-leak-web.php'
N=12

# 这个镜像里重启 php-fpm 会顺带重启 nginx，中间有几秒请求是失败的。
# 所以重启后要等到「探针 200 且 worker 数 = 1」才能开始测，否则曲线里有空洞。
workers() {
    docker exec $C sh -c '
        n=0
        for p in /proc/[0-9]*; do
            c=$(tr "\0" "\n" < $p/cmdline 2>/dev/null | head -1)
            [ "$c" = "php-fpm: pool www" ] && n=$((n+1))
        done
        echo $n'
}

set_pool() {   # $1 = max_requests（0 = 不重启）
    docker exec $C sh -c "
        sed -i 's/^pm = .*/pm = static/'                         $CONF
        sed -i 's/^pm.max_children = .*/pm.max_children = 1/'     $CONF
        sed -i 's/^;*pm.max_requests = .*/pm.max_requests = $1/'  $CONF
        supervisorctl restart php-fpm:php-fpmd" >/dev/null 2>&1
    # 重启 php-fpm 之后 nginx 也会被带着重启一次（webdevops 镜像的 supervisor 联动），
    # 中间有几秒连接会被拒。所以要等到「连续 3 次探针都 200 且 worker 数 = 1」才算稳。
    sleep 3
    i=0
    ok=0
    while [ $i -lt 60 ]; do
        code=$(curl -s -o /dev/null -m 2 -w '%{http_code}' "$URL?mode=clean")
        if [ "$code" = "200" ] && [ "$(workers)" = "1" ]; then
            ok=$((ok + 1))
            [ $ok -ge 3 ] && return 0
        else
            ok=0
        fi
        sleep 0.5
        i=$((i + 1))
    done
    echo "（警告：池子没稳定到 1 个 worker，实际 $(workers)）"
}

run() {   # $1 = mode, $2 = kb
    printf "%5s %8s %10s %8s  %s\n" "请求" "pid" "RSS(MB)" "static_n" "曲线（每格 2MB）"
    i=1
    while [ $i -le $N ]; do
        OUT=$(curl -s "$URL?mode=$1&kb=$2")
        PID=$(echo "$OUT" | sed -n 's/.*pid=\([0-9]*\).*/\1/p')
        RSS=$(echo "$OUT" | sed -n 's/.*rss_kb=\([0-9]*\).*/\1/p')
        SN=$(echo "$OUT"  | sed -n 's/.*static_n=\([0-9]*\).*/\1/p')
        BAR=$(awk -v k="$RSS" 'BEGIN{for(i=0;i<int(k/2048);i++) printf "#"}')
        printf "%5d %8s %10.1f %8s  %s\n" "$i" "$PID" \
            "$(awk -v k="$RSS" 'BEGIN{printf "%.1f", k/1024}')" "$SN" "$BAR"
        i=$((i + 1))
    done
}

echo "===== A. 重请求把 RSS 顶到峰值：分配 40MB 后释放，pm.max_requests 关闭 ====="
set_pool 0
run peak 40960

echo
echo "===== B. 同样的请求，打开 pm.max_requests = 5 ====="
set_pool 5
run peak 40960
echo "→ pid 每 5 个请求换一次，RSS 被砍回基线再爬回去：pm.max_requests 的作用是"
echo "  定期把「内存高水位」归零，而不是修好泄漏。"

echo
echo "===== C. FPM 里函数内 static 会不会跨请求存活（每次追加 4MB） ====="
set_pool 0
run leak 4096
echo "→ static_n 恒为 1、RSS 不动：FPM 每个请求结束时会把 static / 全局变量全部清掉，"
echo "  所以「在 FPM 里用 static 数组泄漏」是行不通的 —— 真泄漏发生在常驻进程里。"

echo
echo "全部跑完。还原配置： sh bench/ops/q45-restore-fpm.sh"
