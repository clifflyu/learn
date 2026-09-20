#!/bin/sh
# Q44 实测①：慢 SQL —— 从慢查询日志定位到 EXPLAIN，再到加索引验证
#
# 用法: sh bench/ops/q44-sql-slow.sh
#
# 关键点：MySQL 的 slow.log 是全局的，别的库也在往里写。
# 所以每条 SQL 都带唯一注释 /*q44:*\/，只 grep 自己的标记，不去动别人的日志。

M="docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4"
LOG=/var/lib/mysql/slow.log

# 慢查询阈值：排查期临时降到 0.05s，让长尾全都现形。测完恢复 0.1s。
docker exec learn-mysql mysql -uroot -proot -e "SET GLOBAL long_query_time = 0.05" 2>/dev/null
BEFORE=$(docker exec learn-mysql sh -c "wc -c < $LOG")

echo "===== 1. 慢查询长什么样（应用侧只看到「接口慢」，日志侧才有真相） ====="
$M -t <<'SQL' 2>/dev/null
USE q44_slow;
SET profiling = 1;
/*q44:slow*/ SELECT SQL_NO_CACHE id, customer_id, amount, created_at FROM orders
  WHERE status = 0 AND created_at >= NOW() - INTERVAL 110 DAY
  ORDER BY created_at DESC LIMIT 20;
SHOW PROFILES;
SQL

echo
echo "===== 2. 慢查询日志里新增了什么（只看 q44_slow 的条目） ====="
echo "--- Query_time 那一行是排障起点：Rows_examined ÷ Rows_sent 就是信号 ---"
docker exec learn-mysql sh -c "tail -c +$((BEFORE + 1)) $LOG | grep -B 4 'q44:slow'"

echo
echo "===== 3. EXPLAIN：全表扫 + filesort ====="
$M -t <<'SQL' 2>/dev/null
USE q44_slow;
/*q44:slow*/ EXPLAIN SELECT id, customer_id, amount, created_at FROM orders
  WHERE status = 0 AND created_at >= NOW() - INTERVAL 110 DAY
  ORDER BY created_at DESC LIMIT 20;
SQL

echo
echo "===== 4. EXPLAIN ANALYZE：真实耗时与真实行数（8.0.18+ 才有） ====="
$M -t <<'SQL' 2>/dev/null
USE q44_slow;
/*q44:slow*/ EXPLAIN ANALYZE SELECT id, customer_id, amount, created_at FROM orders
  WHERE status = 0 AND created_at >= NOW() - INTERVAL 110 DAY
  ORDER BY created_at DESC LIMIT 20;
SQL

# 计时用 SHOW PROFILES 的 Duration（服务端实际执行耗时），各跑 5 次。
# 同一台 MySQL 上有别的库在跑，单次数字有抖动，所以 A/B 都要跑在「表已被
# EXPLAIN ANALYZE 预热过」的同一条件下，取全部 5 个值而不是挑一个好看的。
timing() {
    $M 2>/dev/null <<SQL | grep -F "q44:$1" | cut -f2
USE q44_slow;
SET profiling = 1;
$(i=1; while [ $i -le 5 ]; do
  echo "/*q44:$1*/ SELECT SQL_NO_CACHE id, customer_id, amount, created_at FROM orders
    WHERE status = 0 AND created_at >= NOW() - INTERVAL 110 DAY
    ORDER BY created_at DESC LIMIT 20;"
  i=$((i + 1))
done)
SHOW PROFILES;
SQL
}

echo
echo "===== 5. 加索引前的稳定基线（表已被上一步预热，连跑 5 次） ====="
BEFORE_T=$(timing slow)
echo "$BEFORE_T"

echo
echo "===== 6. 加一个联合索引 (status, created_at) ====="
$M -t <<'SQL' 2>/dev/null
USE q44_slow;
ALTER TABLE orders ADD INDEX idx_status_created (status, created_at);
ANALYZE TABLE orders;
SQL

$M -t 2>/dev/null <<'SQL'
USE q44_slow;
EXPLAIN SELECT id, customer_id, amount, created_at FROM orders
  WHERE status = 0 AND created_at >= NOW() - INTERVAL 110 DAY
  ORDER BY created_at DESC LIMIT 20;
SQL

echo "--- 同样的查询，加索引后连跑 5 次 ---"
AFTER_T=$(timing slow-fixed)
echo "$AFTER_T"

echo
echo "===== 7. 还原：删掉索引，让这个库保持「有问题」的状态 ====="
$M -e "USE q44_slow; ALTER TABLE orders DROP INDEX idx_status_created; ANALYZE TABLE orders;" 2>/dev/null
docker exec learn-mysql mysql -uroot -proot -e "SET GLOBAL long_query_time = 0.1" 2>/dev/null
echo "long_query_time 已恢复 0.1s"

echo
echo "===== 8. 表有多大（全表扫描的成本从哪来） ====="
$M -t -e "SELECT TABLE_NAME, TABLE_ROWS, ROUND(DATA_LENGTH/1024/1024) AS 数据MB, ROUND(INDEX_LENGTH/1024/1024) AS 索引MB FROM information_schema.TABLES WHERE TABLE_SCHEMA='q44_slow';" 2>/dev/null
