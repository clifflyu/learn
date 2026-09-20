#!/bin/sh
# Q42 真实制造 502 与 504，并把 nginx error log 里的原始报错抓出来。
#
# 在【宿主机】跑，但 curl 全部在【容器内】执行。
# 原因：宿主机的 8081 已被另一个容器（yishou-api）占用，从宿主机打 8081
# 会打到别的 nginx 上，拿到的是它的 404（实测踩过这个坑）。
# 容器内的 8081 只有本脚本挂的 vhost，干净。
#
#   nginx 1.30.5 / php-fpm 8.4.25（都在 learn-php 容器里）
#
# 干的事：
#   1) 挂一个 listen 8081、fastcgi_read_timeout=3s 的 vhost
#   2) 正常请求            -> 200
#   3) PHP 睡 10s          -> 504 + "upstream timed out"
#   4) 停掉 php-fpm        -> 502 + "connect() failed (111: Connection refused)"
#   5) 给 FPM 池加 request_terminate_timeout=2s、PHP 睡 10s
#                          -> 502 + "recv() failed (104: Connection reset by peer)"
#   6) 另起坏上游 proxy_pass -> 502 + "upstream prematurely closed connection"
#   6b) 坏上游改回非法 HTTP 字节 -> nginx 只记 error，却把垃圾当 HTTP/0.9 透传
#   7) PHP 直接 exit       -> 200（空 body），不是 502
#   8) 全部还原：删 vhost、恢复 fpm 配置、重启 nginx 与 php-fpm

set -e
DIR=$(cd "$(dirname "$0")" && pwd)
CONT=learn-php
CONF=/etc/nginx/conf.d/98-q42.conf
POOL=/usr/local/etc/php-fpm.d/application.conf
BAK=/tmp/q42-application.conf.bak
URL="http://127.0.0.1:8081/bench/network/q42/502-504.php"

cleanup() {
  docker exec $CONT sh -c "rm -f $CONF; [ -f $BAK ] && cp $BAK $POOL && rm -f $BAK; true" >/dev/null 2>&1 || true
  docker exec $CONT supervisorctl restart nginx:nginxd >/dev/null 2>&1 || true
  docker exec $CONT supervisorctl restart php-fpm:php-fpmd >/dev/null 2>&1 || true
  echo
  echo "[cleanup] vhost 已删、fpm 配置已还原、nginx 与 php-fpm 已重启"
}
trap cleanup EXIT INT TERM

# 容器内 curl
c() { docker exec $CONT curl "$@"; }
mark() { docker logs $CONT 2>&1 | wc -l; }
# 这个容器是共用的：别的实验也在打 9311，日志里会混进它们的行。
# 只保留本脚本的 8081/8082 两个 vhost 以及 q42 路径相关的 error。
since() {
  docker logs $CONT 2>&1 | tail -n +$(( $1 + 1 )) \
    | grep -E "\[error\]|\[crit\]|\[alert\]" \
    | grep -E ":8081|:8082|q42" | sed 's/^/    /' || true
  docker logs $CONT 2>&1 | tail -n +$(( $1 + 1 )) \
    | grep -E "\[error\]|\[crit\]|\[alert\]" | grep -qE ":8081|:8082|q42" \
    || echo "    （无 error 级日志）"
}
kill_bad() {
  docker exec $CONT sh -c 'for p in /proc/[0-9]*; do grep -qa 02-bad-upstream "$p/cmdline" 2>/dev/null && kill "${p#/proc/}"; done' 2>/dev/null || true
  sleep 0.4
}

echo "===== 0. 挂 vhost（listen 8081, fastcgi_read_timeout 3s），重启 nginx ====="
docker cp "$DIR/98-q42.conf" $CONT:$CONF >/dev/null
docker exec $CONT supervisorctl restart nginx:nginxd >/dev/null
sleep 1
echo "  nginx -t: $(docker exec $CONT nginx -t 2>&1 | tail -1)"

echo
echo "===== 1. 正常请求（对照组）====="
c -s -o /dev/null -w "  GET ?mode=ok    -> HTTP %{http_code}  %{time_total}s\n" "$URL?mode=ok"

echo
echo "===== 2. 504：后端处理时间 > fastcgi_read_timeout（PHP sleep 10s，超时 3s）====="
M=$(mark)
c -s -o /dev/null -w "  GET ?mode=slow  -> HTTP %{http_code}  %{time_total}s\n" "$URL?mode=slow&n=10" || true
echo "  nginx 返回给客户端的响应体："
c -s "$URL?mode=slow&n=10" | sed 's/^/    /'
echo "  nginx error log 原文："
since $M

echo
echo "===== 3. 502（一）：后端进程根本没在监听 ====="
M=$(mark)
docker exec $CONT supervisorctl stop php-fpm:php-fpmd >/dev/null
sleep 0.5
c -s -o /dev/null -w "  停掉 php-fpm 后 -> HTTP %{http_code}  %{time_total}s\n" "$URL?mode=ok" || true
echo "  curl -v 看到的响应头："
c -sv "$URL?mode=ok" 2>&1 | grep -E "^< " | sed 's/^/    /' | head -6
echo "  nginx error log 原文："
since $M
docker exec $CONT supervisorctl start php-fpm:php-fpmd >/dev/null
sleep 1

echo
echo "===== 4. 502（二）：上游「回了半个响应就断」 ====="
echo "  做法：给 FPM 池加 request_terminate_timeout=2s，PHP 睡 10s，worker 被 master SIGKILL"
M=$(mark)
docker exec $CONT sh -c "cp $POOL $BAK && printf '\nrequest_terminate_timeout = 2s\n' >> $POOL"
docker exec $CONT supervisorctl restart php-fpm:php-fpmd >/dev/null
sleep 1
c -s -o /dev/null -w "  GET ?mode=slow（FPM 侧 2s 杀 worker）-> HTTP %{http_code}  %{time_total}s\n" \
  "$URL?mode=slow&n=10" || true
echo "  nginx error log 原文："
since $M
echo "  php-fpm 侧原文："
docker logs $CONT 2>&1 | tail -n +$(( $M + 1 )) | grep -iE "WARNING|terminated|Killed|exited" | head -3 | sed 's/^/    /' || true
docker exec $CONT sh -c "cp $BAK $POOL && rm -f $BAK"
docker exec $CONT supervisorctl restart php-fpm:php-fpmd >/dev/null
sleep 1

echo
echo "===== 5. 502（三）：上游收下请求后直接关闭连接 ====="
echo "  做法：另起一个 vhost (8082) proxy_pass 到 9099，9099 上跑一个"
echo "        收下请求、一个字节都不回就 FIN 的 python 服务。"
echo "        线上后端被 OOM kill 掉就是这个形态。"
kill_bad
M=$(mark)
docker exec -d $CONT python3 /app/bench/network/q42/02-bad-upstream.py --mode close
docker cp "$DIR/98-q42b.conf" $CONT:/etc/nginx/conf.d/98-q42b.conf >/dev/null
docker exec $CONT supervisorctl restart nginx:nginxd >/dev/null
sleep 1
c -s -o /dev/null -w "  GET http://127.0.0.1:8082/ -> HTTP %{http_code}  %{time_total}s\n" \
  "http://127.0.0.1:8082/" || true
echo "  nginx error log 原文："
since $M
kill_bad

echo
echo "===== 5b. 同一个坏上游改成回「不是 HTTP 的字节」，结果很意外 ====="
kill_bad
M=$(mark)
docker exec -d $CONT python3 /app/bench/network/q42/02-bad-upstream.py --mode garbage
sleep 0.8
c -sv -o /dev/null "http://127.0.0.1:8082/" 2>&1 | grep -E "^< |Received|Closing" | sed 's/^/    /'
echo "  nginx error log 原文："
since $M
echo "  ↑ nginx 记了 error，却【没有】返回 502，而是把垃圾当 HTTP/0.9 透传了。"
kill_bad
docker exec $CONT rm -f /etc/nginx/conf.d/98-q42b.conf
docker exec $CONT supervisorctl restart nginx:nginxd >/dev/null

echo
echo "===== 6. 反例：PHP 里 exit 掉，其实【不是】502 ====="
echo "  很多人以为「后端没输出」就会 502。实际 php-fpm 会正常回一个"
echo "  空的 FastCGI 响应，nginx 照样返回 200。"
M=$(mark)
c -s -o /dev/null -w "  GET ?mode=exit  -> HTTP %{http_code}  %{time_total}s  size=%{size_download}B\n" "$URL?mode=exit" || true
echo "  nginx error log 原文："
since $M

echo
echo "===== 7. 还原 ====="
