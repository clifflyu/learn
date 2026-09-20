#!/bin/sh
# Q9 追问：config:cache / route:cache 对请求生命周期的影响（实测）
#   docker exec learn-php sh /app/bench/framework/22-q9-cache.sh
#
# 这台机器是共用的（load average 常年在 10~50，8 核），单次请求的绝对耗时噪声很大，
# 所以每个阶段重复 RUNS 次，取各 bootstrap 项耗时最小值（最小值最接近"没有外部干扰"）。
set -e
ROOT=/tmp/lara
RUNS=${RUNS:-3}

# 从 trace 日志里取出"第 2 次请求"那一段，算出每个 bootstrapper 自己的耗时
boot_times() {
    awk '
      /===== 第 2 次请求/ { f=1; next }
      f && /\[11\]/ {
          t = $1 + 0
          if (n != "") printf "%-24s %8.3f\n", n, t - p
          n = $NF; p = t
      }
      f && /\[15\]/ { printf "%-24s %8.3f\n", n, ($1 + 0) - p; exit }
    ' "$1"
}

# 同一阶段跑 RUNS 次，逐项取最小
min_of_runs() {
    : > /tmp/q9-acc.txt
    i=0
    while [ "$i" -lt "$RUNS" ]; do
        sh /app/bench/framework/21-q9-trace.sh > /tmp/q9-run-"$1"-"$i".txt 2>&1
        cp /tmp/q9-trace.log /tmp/q9-trace-"$1"-"$i".log
        boot_times /tmp/q9-trace-"$1"-"$i".log >> /tmp/q9-acc.txt
        i=$((i + 1))
    done
    # 注意 || 的顺序：写成 `$2 < m[$1] || !($1 in m)` 会在比较时把 m[$1] 自动建出来，
    # 于是 `in` 永远为真、最小值永远取不到（mawk 实测踩到，所有值都是 0）。
    sort -k1,1 -k2,2n /tmp/q9-acc.txt \
        | awk '{ if (!($1 in m) || $2 + 0 < m[$1] + 0) m[$1] = $2 + 0 } END { for (k in m) printf "%-24s %8.3f ms\n", k, m[k] }' \
        | sort
}

echo "########## 阶段 1：默认（无配置缓存），跑 $RUNS 次取最小 ##########"
min_of_runs nocache

echo
echo "########## 阶段 2：php artisan config:cache 之后，跑 $RUNS 次取最小 ##########"
cd "$ROOT"
php artisan config:cache >/dev/null 2>&1 && echo "config:cache OK"
min_of_runs withcache

echo
echo "########## 阶段 3：route:cache ######"
php artisan route:cache 2>&1 | tail -2
ls -l bootstrap/cache/routes-*.php 2>/dev/null || true
echo "清掉配置缓存后单独试 route:cache 的路由是否还能用："
php artisan optimize:clear >/dev/null 2>&1
php artisan route:cache >/dev/null 2>&1
PORT=8098
php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -S 127.0.0.1:$PORT -t public public/index.php >/tmp/q9-routecache-server.log 2>&1 &
SRV=$!
sleep 1
curl -s -o /dev/null -w 'route:cache 之后 GET /lifecycle → HTTP %{http_code}\n' "http://127.0.0.1:$PORT/lifecycle" || true
kill "$SRV" 2>/dev/null || true
wait "$SRV" 2>/dev/null || true

echo
echo "########## 清理 ##########"
php artisan optimize:clear 2>&1 | tail -3
