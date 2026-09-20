#!/usr/bin/env bash
# Q8 附：7.3 / 8.4 吞吐对比，两个版本交替跑 5 轮，每行取最小值
#
#   bash /opt/learn/bench/php/q8-throughput-interleaved.sh
#
# 两个版本不能同进程跑，宿主机又有别的负载，所以必须交错：
# 顺序跑（先把 7.3 跑完再跑 8.4）会把某一时段的抖动全算到同一个版本头上。
set -u

SCRIPT=/app/bench/php/q8-throughput-interleaved.php
V73="docker.m.daocloud.io/webdevops/php-nginx:7.3"
OUT=/tmp/q8-throughput.$$
: > "$OUT"

for round in 1 2 3 4 5; do
  for ver in 84 73; do
    if [ "$ver" = "84" ]; then
      docker exec learn-php php "$SCRIPT" 2>&1 | sed 's/^/84|/'
    else
      docker run --rm --entrypoint php -v /opt/learn:/app "$V73" "$SCRIPT" 2>&1 | sed 's/^/73|/'
    fi | tee -a "$OUT"
  done
done

echo
echo "=== 5 轮交错，每行取最小值（8.4.25 vs 7.3.33）==="
awk -F'|' '
  # 每个「版本+项目」收集 5 个样本，报 min / 中位数 / max
  # 只报 min 不稳：某一轮被调度器冷落一下就会冒出一个偏低的离群点，直接决定结论
  # （实测 json_decode 那一行，7.3 的 5 个样本是 .166 .295 .309 .314 .349，min 恰好是离群点）
  NF==3 && $3 ~ /^[0-9.]+$/ {
    key = $1 SUBSEP $2
    n_s[key]++; s[key, n_s[key]] = $3+0
    if (!($2 in seen)) { seen[$2]=1; order[++n] = $2 }
  }
  END {
    printf "%-28s %19s %19s %9s\n", "项目", "8.4 中位[min~max]", "7.3 中位[min~max]", "7.3/8.4"
    for (i=1;i<=n;i++) {
      k = order[i]
      line = sprintf("%-28s", k)
      for (j=1;j<=2;j++) {
        v = (j==1 ? "84" : "73"); key = v SUBSEP k
        if (!(key in n_s)) { line = line sprintf(" %19s", "-"); continue }
        cnt = n_s[key]
        for (x=1;x<=cnt;x++) tmp[x] = s[key, x]
        # 插入排序，cnt 很小
        for (x=2;x<=cnt;x++) { t=tmp[x]; y=x-1; while (y>=1 && tmp[y]>t) { tmp[y+1]=tmp[y]; y-- } tmp[y+1]=t }
        med = tmp[int((cnt+1)/2)]
        line = line sprintf(" %10.4f[%.4f~%.4f]", med, tmp[1], tmp[cnt])
        if (j==1) m84 = med; else { m73 = med; line = line sprintf(" %8.2fx", m73/m84) }
      }
      print line
    }
  }' "$OUT"

echo
echo "原始数据（每行 <版本>|<项目>|<秒>）保留在 $OUT，可自行复核聚合"
