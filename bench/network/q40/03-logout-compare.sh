#!/bin/sh
# Q40 「退出登录」这一个动作，在 Session 和 JWT 下分别做了什么。
#
# 在【宿主机】跑，打 http://localhost:9311。

B=http://localhost:9311/bench/network/q40/jwt-vs-session.php
JAR=/tmp/q40-jar.txt
rm -f "$JAR"

j() { curl -s "$@" | tr -d '\n' | sed 's/  */ /g'; }
hr() { echo "--------------------------------------------------------------"; }

echo "===== Session 方案 ====="
hr
echo "1) 登录"
j -c "$JAR" -b "$JAR" "$B?act=login-session"
echo
echo "2) 带着 cookie 访问受保护资源"
j -c "$JAR" -b "$JAR" "$B?act=me-session"
echo
echo "3) 退出登录（session_destroy）"
j -c "$JAR" -b "$JAR" "$B?act=logout-session"
echo
echo "4) 用【同一个 cookie】再访问一次"
j -c "$JAR" -b "$JAR" "$B?act=me-session"
echo
echo "   ⇒ 401。服务端把状态删了，旧凭证立刻失效。"

echo
echo "===== JWT 方案 ====="
hr
echo "1) 签发"
TOKEN=$(curl -s "$B?act=login-jwt" | python3 -c 'import json,sys; print(json.load(sys.stdin)["token"])')
echo "   token 前 40 字节: $(echo "$TOKEN" | cut -c1-40)..."
echo "2) 用 Bearer token 访问受保护资源"
j -H "Authorization: Bearer $TOKEN" "$B?act=me-jwt"
echo
echo "3) 退出登录"
j "$B?act=logout-jwt"
echo
echo "4) 用【同一个 token】再访问一次"
j -H "Authorization: Bearer $TOKEN" "$B?act=me-jwt"
echo
echo "   ⇒ 依然是 200。服务端从头到尾没有这个 token 的任何记录，"
echo "     所以它不仅没法「删掉它」，甚至不知道它存在过。"
echo
echo "5) 唯一的止损办法：等它自己过期（本例 exp 是 1 小时）"
echo "   要提前吊销，就得把 jti 存进黑名单并每次请求都查 —— 那又回到了有状态。"
