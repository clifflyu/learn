#!/usr/bin/env bash
# Q8 附：把 q8-upgrade-traps.php 在 7.3 / 7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 上都跑一遍
#
#   bash /opt/learn/bench/php/q8-upgrade-traps.sh
#
# 输出每格是 OK / Deprecated / Warning / Notice / Error，看清「哪个版本开始报、报的是弃用还是致命」。
set -u

SCRIPT=/app/bench/php/q8-upgrade-traps.php
VERSIONS="7.3 7.4 8.0 8.1 8.2 8.3 8.4"
RAW=/tmp/q8-traps.$$

run_one() {
  case "$1" in
    7.3) docker run --rm --entrypoint php -v /opt/learn:/app \
           docker.m.daocloud.io/webdevops/php-nginx:7.3 "$SCRIPT" 2>&1 ;;
    8.4) docker exec learn-php php "$SCRIPT" 2>&1 ;;
    *)   docker run --rm --entrypoint php -v /opt/learn:/app "php:$1-cli" "$SCRIPT" 2>&1 ;;
  esac
}

: > "$RAW"
for v in $VERSIONS; do
  run_one "$v" | sed "s/^/$v|/" | tee -a "$RAW" >/dev/null
done

awk -F'|' -v versions="$VERSIONS" '
  $1 ~ /^[0-9]+\.[0-9]+$/ && NF>=3 && $2 != "PHP" {
    if (!($2 in seen)) { seen[$2]=1; order[++n]=$2 }
    cell[$2, $1] = $3
  }
  END {
    split(versions, vers, " ")
    printf "%-26s", "操作"
    for (j=1;j<=length(vers);j++) printf " %-10s", vers[j]
    printf "\n"
    for (i=1;i<=n;i++) {
      l = order[i]
      printf "%-26s", l
      for (j=1;j<=length(vers);j++) {
        c = cell[l, vers[j]]
        if (c == "") c = "缺数据"
        printf " %-10s", c
      }
      printf "\n"
    }
  }' "$RAW"

echo
echo "=== 每个操作在各版本的原始报错（最早出现的那次）==="
LC_ALL=C awk -F'|' 'NF>=4 && $2!="PHP" { key=$2; if (!(key in got)) { got[key]=1; printf "%-26s %-6s %-10s %s\n", $2, $1, $3, $4 } }' "$RAW"

echo
echo "原始数据保留在 $RAW"
