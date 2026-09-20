#!/bin/sh
# Q44 实测：排查「接口突然变慢」的第一步 —— 用 FPM 自带的 access log 按接口聚合耗时
#
# 这个镜像里 FPM 的池配置有这么一行（原始配置第 354 行）：
#   access.format = "[php-fpm:access] %R - %u %t \"%m %r%Q%q\" %s %f %{mili}d %{kilo}M %C%%"
# 其中 %{mili}d = 请求耗时（毫秒）、%{kilo}M = 峰值内存（KB）、%C%% = CPU 占用。
# 也就是说：不用装任何 APM，每个请求的「耗时 / 内存峰值 / CPU」就已经在日志里了。
# access.log 指向 /docker.stdout，所以要从 docker logs 里捞。
#
# 日志行长这样（-F'"' 切三段）：
#   [php-fpm:access] 127.0.0.1 -  20/Sep/2026:05:51:25 +0000 "GET /bench/ops/q44-api.php?down=8000" 200 /app/bench/ops/q44-api.php 8017.827 2048 0.00%
#    $1 = 前缀（第 4 个字段是时间戳）  $2 = 请求行  $3 = "状态码 脚本 耗时ms 峰值KB CPU%"
#
# 用法: sh bench/ops/q44-access-digest.sh [日志行数]
#
# 注意：这台机器上同时有别的实测在跑，日志里混着它们的请求，看路径分辨。

N=${1:-20000}
TMP=$(mktemp)

docker logs --tail "$N" learn-php 2>&1 | grep 'php-fpm:access' | awk -F'"' '
    {
        split($2, r, " "); uri = r[2]; sub(/\?.*/, "", uri)
        split($3, t, " "); ms = t[3]
        if (uri == "" || ms == "") next
        print uri "\t" ms
    }' > "$TMP"

if [ ! -s "$TMP" ]; then
    echo "没捞到 access 行，把行数调大一点"
    rm -f "$TMP"
    exit 1
fi

echo "===== 按 URI 聚合（耗时单位 ms，来自 FPM access log 的 %{mili}d） ====="
printf "%-40s %6s %10s %10s %10s %10s\n" "URI" "次数" "avg" "P50" "P99" "max"
sort -k1,1 -k2,2n "$TMP" | awk -F'\t' '
    { u=$1; v=$2+0; n[u]++; s[u]+=v; a[u SUBSEP n[u]]=v }
    END {
        for (u in n) {
            c = n[u]
            p50 = a[u SUBSEP (int(c*0.5)+1)]
            p99 = a[u SUBSEP (int(c*0.99)+1)]
            printf "%s\t%d\t%.1f\t%d\t%d\t%d\n", u, c, s[u]/c, p50, p99, a[u SUBSEP c]
        }
    }' | sort -k5 -rn | head -12 \
    | awk -F'\t' '{printf "%-40s %6s %10s %10s %10s %10s\n", $1, $2, $3, $4, $5, $6}'

echo
echo "===== 最慢的 3 个请求（含时间戳，用来对齐「什么时候开始变慢」） ====="
docker logs --tail "$N" learn-php 2>&1 | grep 'php-fpm:access' | awk -F'"' '
    { split($1, a, " "); split($3, t, " "); printf "%12s  %s  %s\n", t[3], a[4], $2 }' \
    | sort -rn | head -3

echo
echo "===== 最慢的那个 URI，按分钟聚合，找拐点（哪一分钟开始变慢） ====="
SLOWEST=$(sort -k1,1 -k2,2n "$TMP" | awk -F'\t' '{ if ($2+0 > m[$1]) m[$1]=$2+0 } END { for (u in m) printf "%d\t%s\n", m[u], u }' \
    | sort -rn | head -1 | cut -f2-)
echo "URI = $SLOWEST"
docker logs --tail "$N" learn-php 2>&1 | grep 'php-fpm:access' | grep -F "$SLOWEST" | awk -F'"' '
    { split($1, a, " "); split($3, t, " "); print substr(a[4], 1, 17) "\t" t[3] }' \
    | sort -k1,1 -k2,2n | awk -F'\t' '
        { t=$1; v=$2+0; c[t]++; s[t]+=v; if (v>m[t]) m[t]=v }
        END { for (x in c) printf "%s  n=%-4d avg=%-9.1f max=%.1f\n", x, c[x], s[x]/c[x], m[x] }' \
    | sort | tail -8

rm -f "$TMP"
