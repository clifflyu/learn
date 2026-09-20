#!/bin/sh
# Q38 从输入 URL 到页面返回：分段计时
#
# 在【宿主机】跑。curl 8.14.1（宿主） / 7.88.1（容器）
#
# 用法：sh /opt/learn/bench/network/q38-timing.sh
#
# 说明：curl 的 time_* 是累加量，单位秒
#   time_namelookup      DNS 解析完成
#   time_connect         TCP 三次握手完成
#   time_appconnect      TLS 握手完成（HTTP 明文恒为 0）
#   time_starttransfer   收到第一个响应字节（TTFB）
#   time_total           整个请求结束
# 所以各阶段本身的耗时 = 后一个减前一个。

FMT='  code=%{http_code}  dns=%{time_namelookup}  tcp=%{time_connect}  tls=%{time_appconnect}  ttfb=%{time_starttransfer}  total=%{time_total}  size=%{size_download}\n'
CONT=learn-php

echo "=============================================================="
echo "1. 本机 nginx+PHP（宿主机 -> localhost:9311 -> docker-proxy -> nginx -> php-fpm）"
echo "   5 次，冷连接（每次 curl 进程独立）"
echo "=============================================================="
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "$FMT" http://localhost:9311/bench/network/hello.php
done

echo
echo "=============================================================="
echo "2. 同一个 nginx，静态文件（不过 FastCGI）"
echo "=============================================================="
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "$FMT" http://localhost:9311/bench/network/static.txt
done

echo
echo "=============================================================="
echo "3. 容器内部直连 nginx:80（去掉 docker-proxy 的 NAT 转发）"
echo "=============================================================="
for i in 1 2 3 4 5; do
  docker exec $CONT curl -s -o /dev/null -w "$FMT" http://127.0.0.1/bench/network/hello.php
done

echo
echo "=============================================================="
echo "4. 外部 HTTPS 站点（含 DNS + TCP + TLS）"
echo "=============================================================="
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "$FMT" https://www.baidu.com/
done

echo
echo "=============================================================="
echo "5. 外部 HTTPS，但用 --resolve 预置解析结果（DNS 阶段被跳过）"
echo "=============================================================="
IP=$(getent ahostsv4 www.baidu.com | head -1 | awk '{print $1}')
echo "  www.baidu.com -> $IP"
for i in 1 2 3; do
  curl -s -o /dev/null --resolve "www.baidu.com:443:$IP" -w "$FMT" https://www.baidu.com/
done

echo
echo "=============================================================="
echo "6. 连接复用：一次 curl 请求两个 URL（同一条 TCP/TLS 连接）"
echo "   num_connects=1 的是第一个请求（含握手），=0 的复用连接，只剩一个 RTT"
echo "=============================================================="
R='  url=%{url_effective}  connects=%{num_connects}  dns=%{time_namelookup}  tcp=%{time_connect}  tls=%{time_appconnect}  ttfb=%{time_starttransfer}  total=%{time_total}\n'
curl -s -o /dev/null -o /dev/null -w "$R" https://www.baidu.com/ https://www.baidu.com/
curl -s -o /dev/null -o /dev/null -w "$R" http://localhost:9311/bench/network/static.txt http://localhost:9311/bench/network/static.txt

echo
echo "=============================================================="
echo "7. 重定向链：301 也要多花一个 RTT（http 目录 -> 加斜杠）"
echo "=============================================================="
curl -s -o /dev/null -w "$FMT" http://localhost:9311/bench/network

echo
echo "=============================================================="
echo "8. 后端变慢 1 秒时，TTFB 与 total 的位移（证明时间去哪了）"
echo "=============================================================="
docker exec $CONT sh -c 'cat > /app/bench/network/_sleep1.php <<EOF
<?php sleep(1); echo "slept\n";
EOF'
curl -s -o /dev/null -w "$FMT" http://localhost:9311/bench/network/_sleep1.php
