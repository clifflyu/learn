#!/bin/sh
# Q9：把 Laravel 的请求生命周期真实跑一遍，打印打点顺序
#
#   docker exec learn-php sh /app/bench/framework/21-q9-trace.sh
#
# 会用到 /tmp/lara/pristine（原始骨架备份），第一次跑时自动创建。
set -e

ROOT=/tmp/lara
PRISTINE=/tmp/lara.pristine

# 1) 首次运行时留一份干净备份，便于反复打补丁
if [ ! -d "$PRISTINE" ]; then
    echo "== 建立原始骨架备份 $PRISTINE =="
    cp -a "$ROOT" "$PRISTINE"
fi

# 2) 恢复干净副本（vendor 不动，只覆盖被打点改过的文件 + 路由 + 中间件）
for f in public/index.php bootstrap/app.php routes/web.php \
         vendor/laravel/framework/src/Illuminate/Foundation/Application.php \
         vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php \
         vendor/laravel/framework/src/Illuminate/Routing/Router.php \
         vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php; do
    cp -a "$PRISTINE/$f" "$ROOT/$f"
done
rm -f "$ROOT/app/Http/Middleware/Q9RouteMiddleware.php"

# 3) 打点
rm -f /tmp/q9-trace.log
php /app/bench/framework/20-q9-patch.php

# 4) 起内置服务器（-t public 指定文档根，index.php 兜住所有路径）
#    opcache.enable_cli 默认是 Off，这里显式打开，让第 2 次请求能反映"预热后"的真实占比；
#    file_update_protection 默认 2 秒会让刚打补丁写过的文件不被缓存，一并关掉。
PORT=${Q9_PORT:-8099}
php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 \
    -S 127.0.0.1:"$PORT" -t "$ROOT/public" "$ROOT/public/index.php" >/tmp/q9-server.log 2>&1 &
SRV=$!
sleep 1
if ! kill -0 "$SRV" 2>/dev/null; then
    echo "服务器没起来（端口 $PORT 被占？）："
    cat /tmp/q9-server.log
    exit 1
fi

# 5) 发真实 HTTP 请求：第 1 次冷启动，第 2 次预热后
echo
echo "== 第 1 次请求（冷启动）=="
curl -s -o /tmp/q9-body.txt -w 'HTTP %{http_code}  %{time_total}s\n' "http://127.0.0.1:$PORT/lifecycle" || true
echo "body: $(cat /tmp/q9-body.txt)"

printf '\n===== 第 2 次请求（同一个 worker，OPcache 已热）=====\n' >> /tmp/q9-trace.log
echo
echo "== 第 2 次请求（预热后）=="
curl -s -o /dev/null -w 'HTTP %{http_code}  %{time_total}s\n' "http://127.0.0.1:$PORT/lifecycle" || true

kill "$SRV" 2>/dev/null || true
wait "$SRV" 2>/dev/null || true

# 6) 打印 trace
echo
echo "=================== /tmp/q9-trace.log ==================="
cat /tmp/q9-trace.log
