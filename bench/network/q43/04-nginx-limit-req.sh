#!/bin/sh
# Q43 生产里最常见的限流实现：nginx 的 limit_req（漏桶/令牌桶）+ limit_conn。
#
# 在【宿主机】跑，但压测循环整体在容器内执行 —— 两点原因：
#   1) 容器里访问自己就是 127.0.0.1，所有请求共享同一个 $binary_remote_addr 桶
#   2) 每次请求都单独 docker exec 的话，进程启动开销本身就把它压到 ~14 次/秒，
#      比 rate=10r/s 的阈值还低，根本触发不了限流（第一版就踩了这个坑）
#
#   nginx/1.30.5
#
# 只【新增】一个 /etc/nginx/conf.d/97-q43-limit.conf，不动任何已有配置文件，
# 退出时删掉并重启 nginx 复原。
set -e
C=learn-php
CONF=/etc/nginx/conf.d/97-q43-limit.conf
P=/bench/network/q43

cleanup() {
  docker exec $C rm -f "$CONF" /app/bench/network/q43/ping-nolimit.txt 2>/dev/null || true
  docker exec $C supervisorctl restart nginx:nginxd >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

echo "nginx: $(docker exec $C nginx -v 2>&1 | sed 's/nginx version: //')"
echo "PHP  : $(docker exec $C php -r 'echo PHP_VERSION;')"
echo

printf 'pong\n' > /tmp/q43-ping.txt
docker cp /tmp/q43-ping.txt "$C:/app/bench/network/q43/ping.txt" >/dev/null

# 注意 -i：docker exec 不带 -i 时 stdin 不接管道，heredoc 根本进不去容器
docker exec -i $C sh -c "cat > $CONF" <<'NGINX'
# Q43 临时配置：limit_req = 漏桶，limit_conn = 并发连接数限流
limit_req_zone  $binary_remote_addr zone=q43req:1m  rate=10r/s;
limit_conn_zone $binary_remote_addr zone=q43conn:1m;

server {
    listen 8083;
    server_name _;
    root /app;

    # burst=5 nodelay：桶容量 5，超出的请求【立即】拒绝，不排队
    location = /bench/network/q43/ping.txt {
        limit_req  zone=q43req burst=5 nodelay;
        limit_req_status 429;
        limit_conn q43conn 3;
    }
    # 对照组：同一个文件，不加限流
    location = /bench/network/q43/ping-nolimit.txt {
        alias /app/bench/network/q43/ping.txt;
    }
}
NGINX
docker exec $C cp /app/bench/network/q43/ping.txt /app/bench/network/q43/ping-nolimit.txt

# 压测循环放进容器里跑，写成脚本 cp 进去，避免每层引号转义
cat > /tmp/q43-loop.sh <<'LOOP'
#!/bin/sh
# $1=url  $2=次数  $3=每次之间的间隔(秒, 可省)
i=1
while [ "$i" -le "$2" ]; do
  curl -s -o /dev/null -w '%{http_code} ' "$1"
  i=$((i + 1))
  [ -n "$3" ] && sleep "$3"
done
echo
LOOP
docker cp /tmp/q43-loop.sh "$C:/tmp/q43-loop.sh" >/dev/null

docker exec $C supervisorctl restart nginx:nginxd >/dev/null
sleep 1

echo "===== A. 28 个请求连续打出，rate=10r/s burst=5 nodelay ====="
docker exec $C sh /tmp/q43-loop.sh "http://127.0.0.1:8083$P/ping.txt" 28
echo "     ↑ 前 6 个 200（burst+1），其余立刻 429；中间零星几个 200 是这 0.5 秒里"
echo "       按 10r/s 补出来的令牌，不是漏放"

echo
echo "===== B. 对照组：同一个文件不限流，同样 28 个请求 ====="
docker exec $C sh /tmp/q43-loop.sh "http://127.0.0.1:8083$P/ping-nolimit.txt" 28
echo "     ↑ 全部 200，差别完全来自 limit_req"

echo
echo "===== C. 停 1 秒后（桶按 10r/s 补满）再打 12 个 ====="
docker exec $C sleep 1
docker exec $C sh /tmp/q43-loop.sh "http://127.0.0.1:8083$P/ping.txt" 12
echo "     ↑ 又放行 6 个：停下这 1 秒攒回来的额度被一次性兑现 —— 这就是『桶容量』的意义"

echo
echo "===== D. 被限流后，每 200ms 试一次，共 10 次 ====="
docker exec $C sh /tmp/q43-loop.sh "http://127.0.0.1:8083$P/ping.txt" 10 0.2
echo "     ↑ rate=10r/s 即每 100ms 补 1 个令牌，200ms 一次基本每次都能过"

echo
echo "===== E. 429 长什么样 ====="
cat > /tmp/q43-429.sh <<'LOOP'
#!/bin/sh
i=1
while [ "$i" -le 30 ]; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$1")
  printf '%s ' "$code"
  if [ "$code" != "200" ]; then
    echo
    echo "--- 第一次被拒的完整响应头 ---"
    curl -s -D - -o /dev/null "$1" | sed 's/^/       /'
    break
  fi
  i=$((i + 1))
done
echo
LOOP
docker cp /tmp/q43-429.sh "$C:/tmp/q43-429.sh" >/dev/null
docker exec $C sh /tmp/q43-429.sh "http://127.0.0.1:8083$P/ping.txt"
