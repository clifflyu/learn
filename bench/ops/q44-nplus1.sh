#!/bin/sh
# Q44 实测③：代码问题 —— N+1 把一条慢 SQL 放大成「接口超时」
#
# 用法: sh bench/ops/q44-nplus1.sh
#
# 两个修法是正交的，别混着讲：
#   改写（N+1 → 一条 SQL）  修的是「发多少条 SQL」  31 条 → 2 条
#   加索引                  修的是「每条 SQL 多慢」 0.4s → 5ms

API=http://localhost:9311/bench/ops/q44-nplus1.php
M="docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4"

echo "===== 1. 同一张表、同一份数据，只换写法（30 个客户） ====="
printf "%-8s %-10s %-12s %s\n" "mode" "SQL 条数" "耗时" "说明"
for m in n1 in join; do
    OUT=$(curl -s "$API?mode=$m&n=30")
    Q=$(echo "$OUT" | awk -F'= ' '/SQL 数/{split($2,a," "); print a[1]}')
    T=$(echo "$OUT" | awk -F'= ' '/^耗时/{print $2}')
    case $m in
        n1)   DESC="循环里发 SQL，每次都是全表扫描" ;;
        in)   DESC="一条 IN 批量捞回，应用层聚合" ;;
        join) DESC="一条 GROUP BY" ;;
    esac
    printf "%-8s %-10s %-12s %s\n" "$m" "$Q" "$T" "$DESC"
done

echo
echo "===== 2. 加索引（customer_id, status, created_at） ====="
$M -t <<'SQL' 2>/dev/null
USE q44_slow;
ALTER TABLE orders ADD INDEX idx_cust_status_created (customer_id, status, created_at);
ANALYZE TABLE orders;
EXPLAIN SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS amt FROM orders
 WHERE customer_id = 1234 AND status = 0 AND created_at >= NOW() - INTERVAL 110 DAY;
SQL

echo "--- 同样的三种写法，加索引后重测 ---"
printf "%-8s %-10s %-12s %s\n" "mode" "SQL 条数" "耗时" "说明"
for m in n1 in join; do
    OUT=$(curl -s "$API?mode=$m&n=30")
    Q=$(echo "$OUT" | awk -F'= ' '/SQL 数/{split($2,a," "); print a[1]}')
    T=$(echo "$OUT" | awk -F'= ' '/^耗时/{print $2}')
    printf "%-8s %-10s %-12s\n" "$m" "$Q" "$T"
done
echo "→ N+1 也变快了，但仍是 31 条 SQL：索引治不好 N+1，改写才能。"

echo
echo "===== 3. 还原索引（保持 orders 表「只有主键」的问题状态） ====="
$M -e "USE q44_slow; ALTER TABLE orders DROP INDEX idx_cust_status_created; ANALYZE TABLE orders;" 2>/dev/null
echo done
