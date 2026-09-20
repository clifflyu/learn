#!/bin/sh
# Q40 session 并发实测：同一个 session 的两个并发请求会不会互相阻塞。
#
# 在【宿主机】跑，curl 打到 http://localhost:9311。
#   PHP 8.4.25 / phpredis / Redis 7.4.11
#
# 每次都发【两个并发请求，共用同一个 session id，各自持锁 1 秒】：
#   真并发   -> 总耗时 ≈ 1.0 s
#   被串行化 -> 总耗时 ≈ 2.0 s
#
# 四组：
#   A files handler + 持锁到结束   （PHP 默认行为）
#   B files handler + 提前放锁
#   C redis handler + 不锁        （phpredis 默认）
#   D redis handler + lock=1      （显式要求加锁）

set -e
B=http://localhost:9311/bench/network/q40/session-slow.php
PHP="docker exec learn-php php"

echo "PHP   : $($PHP -r 'echo PHP_VERSION;')"
echo "Redis : $(docker exec learn-redis redis-cli INFO server | grep redis_version | tr -d '\r' | cut -d: -f2)"
echo "session.name 用 Q40SESS，避免影响同容器里别人正在跑的 session 实验"
echo

pair() { # $1=描述 $2=query
  SID=$($PHP -r 'echo bin2hex(random_bytes(16));')
  echo "  $1"
  python3 - "$B?$2" "$SID" <<'PY'
import json, subprocess, sys, time
url, sid = sys.argv[1], sys.argv[2]
cookie = f"Q40SESS={sid}"
t0 = time.time()
ps = [subprocess.Popen(["curl", "-s", "-H", f"Cookie: {cookie}", url],
                       stdout=subprocess.PIPE, text=True) for _ in range(2)]
outs = [p.communicate()[0] for p in ps]
t1 = time.time()
print(f"    两个并发请求总耗时: {t1-t0:.3f} s")
for i, o in enumerate(outs, 1):
    try:
        d = json.loads(o)
        print(f"    请求{i}: handler={d['handler']:<5} lock={d['lock']} "
              f"locking_enabled={d['locking_enabled']} counter={d['counter']} "
              f"起={d['started_at']} 止={d['ended_at']}")
    except Exception:
        print(f"    请求{i}: {o.strip()[:120]}")
PY
}

echo "===== A. files handler，脚本持有 session 到结束（PHP 默认）====="
pair "两个请求都 sleep 1s 才收尾" "handler=files&mode=hold"

echo
echo "===== B. files handler，读完立刻 session_write_close() ====="
pair "两个请求都在 sleep 之前放锁" "handler=files&mode=early"

echo
echo "===== C. redis handler，不额外加锁（phpredis 默认）====="
pair "两个请求都 sleep 1s 才收尾" "handler=redis&mode=hold"

echo
echo "===== D. redis handler，save_path 里带 lock=1（phpredis 5.3 之前的写法）====="
echo "     phpredis 版本: $($PHP -r 'echo phpversion("redis");')"
pair "两个请求都 sleep 1s 才收尾" "handler=redis&mode=hold&lock=1"

echo
echo "===== D2. redis handler，ini_set redis.session.locking_enabled=1（现在的写法）====="
pair "两个请求都 sleep 1s 才收尾" "handler=redis&mode=hold&lock=2"

echo
echo "===== E. 存储位置与 key 名 ====="
$PHP -r 'echo "    files 默认 save_path = ", var_export(ini_get("session.save_path"), true), "  =>  ", sys_get_temp_dir(), "/sess_*\n";'
printf "    redis 里的 session key（phpredis 会加 PHPREDIS_SESSION: 前缀）：\n"
docker exec learn-redis redis-cli --scan --pattern 'PHPREDIS_SESSION*' 2>/dev/null | head -3 | sed 's/^/      /'
printf "    redis 中一个 session 值的实际内容：\n"
K=$(docker exec learn-redis redis-cli --scan --pattern 'PHPREDIS_SESSION*' 2>/dev/null | head -1)
[ -n "$K" ] && docker exec learn-redis redis-cli GET "$K" | sed 's/^/      /'

echo
echo "===== F. cookie 体积 ====="
$PHP -r '
$sid = bin2hex(random_bytes(16));
printf("    session id            : %d 字节\n", strlen($sid));
printf("    Set-Cookie 整行        : %d 字节  (%s)\n",
  strlen("Set-Cookie: Q40SESS=$sid; path=/; HttpOnly"),
  "Set-Cookie: Q40SESS=$sid; path=/; HttpOnly");
'
