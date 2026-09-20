#!/bin/sh
# Q41 XSS / CSRF 的 curl 验证。
#
# 在【宿主机】跑。curl 8.14.1。
# 注意：curl 不是浏览器，它【不会】执行 JS、也【不会】自动带 cookie 发跨站请求。
# 所以这里验证的是「服务端有没有把输入原样输出」和「服务端有没有校验来源」，
# 真正触发 XSS / CSRF 需要浏览器 —— 这两步在文档里标注为「需浏览器」。
#
# 用法：sh bench/network/q41/03-xss-csrf.sh

B='http://localhost:9311/bench/network/q41'
XSS_PAYLOAD='<script>alert(document.cookie)</script>'
IMG_PAYLOAD='<img src=x onerror="document.title=%27XSS%27">'

hr() { echo "------------------------------------------------------------"; }

echo "===== 1. 反射型 XSS：服务端有没有转义 ====="
hr
printf "不安全模式，payload=%s\n" "$XSS_PAYLOAD"
curl -s "$B/xss-demo.php?safe=0&q=$XSS_PAYLOAD" | grep -A1 'id="reflect"' | head -2
hr
printf "安全模式（safe=1），同一个 payload\n"
curl -s "$B/xss-demo.php?safe=1&q=$XSS_PAYLOAD" | grep -A1 'id="reflect"' | head -2
hr
echo "判定：不安全模式下 <script> 原样出现在 HTML 里（浏览器会执行）；"
echo "      安全模式下变成 &lt;script&gt;（浏览器当文本显示）。"

echo
echo "===== 2. 存储型 XSS：写进去再读出来 ====="
hr
curl -s -X POST -d "comment=$XSS_PAYLOAD" "$B/xss-demo.php?safe=0" -o /dev/null -w "写入 HTTP %{http_code}\n"
printf "再访问（不安全模式）:\n"
curl -s "$B/xss-demo.php?safe=0" | grep -A1 "^<ul>" | head -3
printf "再访问（安全模式）:\n"
curl -s "$B/xss-demo.php?safe=1" | grep -A1 "^<ul>" | head -3
curl -s "$B/xss-demo.php?clear=1" -o /dev/null

echo
echo "===== 3. cookie 的 HttpOnly / SameSite 响应头 ====="
hr
curl -s -D - -o /dev/null "$B/xss-demo.php" | grep -i "^set-cookie"

echo
echo "===== 4. CSRF：模拟浏览器发出的跨站 POST ====="
hr
echo "浏览器做的是：带着银行的 cookie，从 evil 站点的表单 POST 到银行。"
echo "curl 把这套请求原样复现一遍 —— 先用 cookie jar 登录，再带 Origin/Referer 发 POST。"
JAR=/tmp/q41-jar.txt
rm -f "$JAR"

for mode in none origin token; do
  echo
  echo "── mode=$mode ──"
  curl -s -c "$JAR" -b "$JAR" -o /dev/null "$B/csrf-bank.php?mode=$mode&reset=1"
  TOKEN=$(curl -s -c "$JAR" -b "$JAR" "$B/csrf-bank.php?mode=$mode" \
          | grep -o 'name="csrf_token" value="[a-f0-9]*"' | sed 's/.*value="//;s/"//')
  BAL0=$(curl -s -c "$JAR" -b "$JAR" "$B/csrf-bank.php?mode=$mode" | grep -o 'id="balance">[0-9]*' | grep -o '[0-9]*')
  echo "   转账前余额: $BAL0"
  # 攻击者能构造的字段只有 to / amount（token 它拿不到，因为同源策略读不到银行页面）
  curl -s -c "$JAR" -b "$JAR" -X POST \
    -H "Origin: http://evil.example" \
    -H "Referer: http://evil.example/prize" \
    -d "to=attacker&amount=9999" \
    "$B/csrf-bank.php?mode=$mode" | grep -oE '转账[^<]*' | head -1 | sed 's/^/   服务端响应: /'
  BAL1=$(curl -s -c "$JAR" -b "$JAR" "$B/csrf-bank.php?mode=$mode" | grep -o 'id="balance">[0-9]*' | grep -o '[0-9]*')
  echo "   转账后余额: $BAL1"
  [ "$BAL0" = "$BAL1" ] && echo "   ⇒ 余额没变，攻击失败" || echo "   ⇒ 余额从 $BAL0 变 $BAL1，攻击成功"
  echo "   服务端流水:"
  docker exec learn-php cat /tmp/q41-bank-log.txt 2>/dev/null | sed 's/^/     /'
done

echo
echo "===== 5. 攻击者页面 csrf-evil.php 实际发出的表单 ====="
hr
curl -s "$B/csrf-evil.php?mode=none" | grep -A3 '<form'

echo
echo "===== 6. PHP 默认的 session cookie 属性 ====="
hr
docker exec learn-php php -r 'foreach (["session.cookie_httponly","session.cookie_samesite","session.cookie_secure","session.use_strict_mode","session.gc_maxlifetime"] as $k) printf("  %-28s = %s\n", $k, var_export(ini_get($k), true));'
