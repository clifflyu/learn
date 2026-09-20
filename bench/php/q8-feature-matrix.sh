#!/usr/bin/env bash
# Q8 附：把 q8-feature-matrix.php 在 7.3 / 7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 上都跑一遍，转成矩阵
#
#   bash /opt/learn/bench/php/q8-feature-matrix.sh
#
# 每格显示 OK / ERR / -（函数不存在）：一眼看出每个特性是从哪个版本开始有的。
# 7.3 用的是环境里已有的 webdevops/php-nginx:7.3（Docker Hub 上没有可用的 php:7.3-cli tag）。
# 7.4 也放进来，是因为升级路线通常要在 7.4 上先停一站，7.4 的弃用清单比 8.x 还长。
set -u

SCRIPT=/app/bench/php/q8-feature-matrix.php
VERSIONS="7.3 7.4 8.0 8.1 8.2 8.3 8.4"
RAW=/tmp/q8-matrix.$$

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
    printf "%-28s", "特性"
    for (j=1;j<=length(vers);j++) printf " %-7s", vers[j]
    printf "\n"
    for (i=1;i<=n;i++) {
      l = order[i]
      printf "%-28s", l
      for (j=1;j<=length(vers);j++) {
        c = cell[l, vers[j]]
        if      (c == "OK")      s = "OK"
        else if (c == "missing") s = "-"
        else if (c == "")        s = "缺数据"
        else                     s = "ERR"
        printf " %-7s", s
      }
      printf "\n"
    }
  }' "$RAW"

echo
echo "原始数据（含每个报错原文）保留在 $RAW"
