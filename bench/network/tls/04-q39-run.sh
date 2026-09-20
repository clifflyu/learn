#!/bin/sh
# Q39 完整实验：证书链 / 校验 / 协议与套件 / 可控 RTT 下的握手轮次 / 明文嗅探
#
# 在【宿主机】跑。依赖：01-make-chain.sh 已经生成 out/ 下的证书链。
#   nginx 1.30.5 / openssl 3.0.20 / curl 7.88.1（容器内）
#   python3（宿主，做链路模拟器）
#
# 跑完自动删除容器里的 99-q39.conf 并重启 nginx（恢复原样）。

set -e
DIR=$(cd "$(dirname "$0")" && pwd)
CONT=learn-php
CONF=/etc/nginx/conf.d/99-q39.conf
CIP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $CONT)
PROXY_PIDS=""

cleanup() {
  for p in $PROXY_PIDS; do kill $p 2>/dev/null || true; done
  docker exec $CONT rm -f $CONF /tmp/q39cert*.pem 2>/dev/null || true
  docker exec $CONT supervisorctl restart nginx:nginxd >/dev/null 2>&1 || true
  echo
  echo "[cleanup] 已删除 $CONF、杀掉代理、重启 nginx，容器恢复原样"
}
trap cleanup EXIT INT TERM

start_proxy() { # $1=listen $2=target $3=delay $4=out
  python3 "$DIR/03-proxy.py" --listen "$1" --target "$2" --delay "$3" --out "$4" >>"$4.proxy.log" 2>&1 &
  PROXY_PIDS="$PROXY_PIDS $!"
  sleep 0.6
}

echo "===== 0. 把 HTTPS 站点挂到容器 nginx 的 8443 ====="
docker cp "$DIR/99-q39.conf" $CONT:$CONF >/dev/null
docker cp "$DIR/out/rootCA.crt" $CONT:/tmp/q39-rootCA.crt >/dev/null
docker exec $CONT supervisorctl restart nginx:nginxd >/dev/null
sleep 1
echo "  nginx -t: $(docker exec $CONT nginx -t 2>&1 | tail -1)"
echo "  站点地址: https://q39.local:8443  (容器 IP $CIP)"

echo
echo "===== 1. 证书链：openssl s_client -showcerts ====="
docker exec $CONT openssl s_client -connect 127.0.0.1:8443 -servername q39.local \
  -showcerts </dev/null 2>/dev/null > /tmp/q39-sclient.txt || true
grep -E "^ *[0-9]+ s:|^ *i:|^ *a:|Verify return code|Peer signing digest|Server Temp Key" /tmp/q39-sclient.txt

echo
echo "----- 链上每张证书（从 s_client 抓下来的原始 PEM 里切）-----"
rm -f /tmp/q39c*.pem
awk '/BEGIN CERTIFICATE/{i++; inb=1} inb{print > ("/tmp/q39c" i ".pem")} /END CERTIFICATE/{inb=0}' /tmp/q39-sclient.txt
for f in /tmp/q39c1.pem /tmp/q39c2.pem; do
  [ -s "$f" ] || continue
  printf "  %5s B PEM / %5s B DER  " "$(wc -c < $f)" "$(openssl x509 -in "$f" -outform DER | wc -c)"
  openssl x509 -in "$f" -noout -subject | tr '\n' ' '
  echo
done

echo
echo "===== 2. 协议版本与加密套件 ====="
for opt in "" "-tls1_3" "-tls1_2" "-tls1_1"; do
  printf "  %-9s : " "${opt:-默认}"
  docker exec $CONT sh -c "echo | openssl s_client -connect 127.0.0.1:8443 -servername q39.local $opt 2>&1" \
    | grep -E "^New,|^ *Cipher *:|alert protocol|alert number" | tr -s ' ' | tr '\n' '|'
  echo
done

echo
echo "===== 3. 证书校验 ====="
printf "  带 -CAfile rootCA.crt : "
docker exec $CONT sh -c 'echo | openssl s_client -connect 127.0.0.1:8443 -servername q39.local -CAfile /tmp/q39-rootCA.crt 2>/dev/null | grep -m1 "Verify return code"'
printf "  不带（容器默认信任库）: "
docker exec $CONT sh -c 'echo | openssl s_client -connect 127.0.0.1:8443 -servername q39.local 2>/dev/null | grep -m1 "Verify return code"'

echo
echo "===== 4. curl 完整 HTTPS 请求 ====="
printf "  --cacert 正确        : "
docker exec $CONT curl -s -o /dev/null -w "http=%{http_code} tls=%{time_appconnect}s\n" \
  --cacert /tmp/q39-rootCA.crt --resolve q39.local:8443:127.0.0.1 https://q39.local:8443/bench/network/hello.php
printf "  --cacert 缺失(应失败): "
docker exec $CONT sh -c 'curl -s -o /dev/null --resolve q39.local:8443:127.0.0.1 https://q39.local:8443/bench/network/hello.php 2>/dev/null; echo "curl 退出码=$?"'
printf "  -k 跳过校验(会成功)  : "
docker exec $CONT curl -sk -o /dev/null -w "http=%{http_code}\n" --resolve q39.local:8443:127.0.0.1 https://q39.local:8443/bench/network/hello.php

echo
echo "===== 5. 可控 RTT 下测握手轮次（链路模拟器，单向 25ms => RTT 50ms）====="
echo "     注意：curl 只有 --tlsv1.2 / --tlsv1.3，没有 -tls1_2 这种写法；"
echo "     要真正钉死版本必须再加 --tls-max。"
start_proxy 127.0.0.1:9443 "$CIP:8443" 25 /tmp/q39local
printf "  TLS1.2 (强制) : "
curl -s -o /dev/null -w "connect=%{time_connect}s appconnect=%{time_appconnect}s ttfb=%{time_starttransfer}s\n" \
  --tlsv1.2 --tls-max 1.2 --cacert "$DIR/out/rootCA.crt" \
  --resolve q39.local:9443:127.0.0.1 https://q39.local:9443/bench/network/hello.php
printf "  TLS1.3 (强制) : "
curl -s -o /dev/null -w "connect=%{time_connect}s appconnect=%{time_appconnect}s ttfb=%{time_starttransfer}s\n" \
  --tlsv1.3 --tls-max 1.3 --cacert "$DIR/out/rootCA.crt" \
  --resolve q39.local:9443:127.0.0.1 https://q39.local:9443/bench/network/hello.php

echo
echo "----- 逐条 TLS 记录时间线（t+ 相对代理建连时刻）-----"
for n in 1 2; do
  echo "  ==== 第 $n 条连接 ===="
  python3 "$DIR/05-records.py" /tmp/q39local $n
done

echo
echo "===== 6. 明文嗅探：SNI 与证书在线上是否可读 ====="
python3 - <<'PY'
import glob, os
needles = {
    "SNI 主机名 'q39.local'": b"q39.local",
    "证书主体 'Q39 Intermediate CA'": b"Q39 Intermediate CA",
    "证书主体 'Learn Bench'": b"Learn Bench",
}
for f in sorted(glob.glob("/tmp/q39local.[12].*.bin")):
    data = open(f, "rb").read()
    d = "客户端->服务端" if f.endswith("c2s.bin") else "服务端->客户端"
    print(f"  {os.path.basename(f):<24} {d}  {len(data):>6} B")
    for name, n in needles.items():
        print(f"      {'★命中' if n in data else ' 未见'}  {name}")
PY

echo
echo "===== 7. 远端同样验证一遍：走 100ms 单向延迟的链路 ====="
echo "  站点选用 www.taobao.com（实测同时支持 TLS1.2 与 TLS1.3），"
echo "  因为 www.baidu.com 实测只支持到 TLS1.2，--tls-max 1.3 会被"
echo "  'tlsv1 alert protocol version' 直接拒掉："
curl -sv --tlsv1.3 --tls-max 1.3 -o /dev/null https://www.baidu.com/ 2>&1 \
  | grep -E "alert|SSL connect error" | sed 's/^/    /'
IP=$(getent ahostsv4 www.taobao.com | head -1 | awk '{print $1}')
start_proxy 127.0.0.1:9444 "$IP:443" 100 /tmp/q39remote
for v in "--tlsv1.2 --tls-max 1.2" "--tlsv1.3 --tls-max 1.3"; do
  echo "  $v"
  for i in 1 2 3; do
    printf "    第%d次 " "$i"
    curl -s -o /dev/null -w 'connect=%{time_connect} appconnect=%{time_appconnect} ttfb=%{time_starttransfer} total=%{time_total}\n' \
      $v --resolve www.taobao.com:9444:127.0.0.1 https://www.taobao.com:9444/
  done
done

echo
echo "----- taobao 第 1、2 条连接的 TLS 记录时间线 -----"
python3 "$DIR/05-records.py" /tmp/q39remote 1 2>/dev/null | head -22
