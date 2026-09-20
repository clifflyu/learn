#!/bin/sh
# Q39 自签一条完整的证书链：Root CA -> Intermediate CA -> 服务器证书(leaf)
#
# 在【宿主机】跑。openssl 3.5.7（宿主）。
# 产物全部落在 bench/network/tls/out/ 下（已被 .gitignore 忽略私钥）。
#
# 关键点：服务器必须下发 leaf + intermediate（"全链"），
#        只发 leaf 的话浏览器/curl 无法把链补到 Root，直接报错。

set -e
DIR=$(cd "$(dirname "$0")" && pwd)
OUT="$DIR/out"
rm -rf "$OUT"
mkdir -p "$OUT"
cd "$OUT"

# ---------- 1. Root CA：自签，自己就是信任锚 ----------
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout rootCA.key -out rootCA.crt -days 3650 \
  -subj "/C=CN/O=Learn Bench/CN=Q39 Root CA" \
  -addext "basicConstraints=critical,CA:TRUE" \
  -addext "keyUsage=critical,keyCertSign,cRLSign" 2>/dev/null

# ---------- 2. Intermediate CA：由 Root 签发 ----------
openssl req -new -newkey rsa:2048 -nodes \
  -keyout intermediate.key -out intermediate.csr \
  -subj "/C=CN/O=Learn Bench/CN=Q39 Intermediate CA" 2>/dev/null

openssl x509 -req -in intermediate.csr \
  -CA rootCA.crt -CAkey rootCA.key -CAcreateserial \
  -out intermediate.crt -days 1825 -sha256 \
  -extfile /dev/stdin <<'EOF' 2>/dev/null
basicConstraints=critical,CA:TRUE,pathlen:0
keyUsage=critical,keyCertSign,cRLSign
EOF

# ---------- 3. 服务器证书（leaf）：由 Intermediate 签发 ----------
openssl req -new -newkey rsa:2048 -nodes \
  -keyout server.key -out server.csr \
  -subj "/C=CN/O=Learn Bench/CN=q39.local" 2>/dev/null

openssl x509 -req -in server.csr \
  -CA intermediate.crt -CAkey intermediate.key -CAcreateserial \
  -out server.crt -days 825 -sha256 \
  -extfile /dev/stdin <<'EOF' 2>/dev/null
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:q39.local,DNS:localhost,IP:127.0.0.1
EOF

# ---------- 4. 全链：nginx 的 ssl_certificate 必须是 leaf 在前、CA 在后 ----------
cat server.crt intermediate.crt > fullchain.crt

echo "===== 链上三张证书 ====="
for f in rootCA.crt intermediate.crt server.crt; do
  printf "%-18s " "$f"
  openssl x509 -in "$f" -noout -subject -issuer | tr '\n' ' '
  echo
done
echo
echo "===== 各证书 PEM 字节数 ====="
wc -c rootCA.crt intermediate.crt server.crt fullchain.crt
echo
echo "===== leaf 的 SAN / EKU ====="
openssl x509 -in server.crt -noout -text | grep -A1 "Subject Alternative Name"
openssl x509 -in server.crt -noout -text | grep -A1 "Extended Key Usage"
echo
echo "===== 用 Root CA 校验全链 ====="
openssl verify -CAfile rootCA.crt -untrusted intermediate.crt server.crt || true
echo
echo "===== 只用 leaf 校验（会失败，证明 intermediate 必须下发）====="
openssl verify -CAfile rootCA.crt server.crt || true
