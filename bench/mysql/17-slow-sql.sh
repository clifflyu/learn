#!/bin/sh
# Q21 慢 SQL 排查：从「发现慢」到「定位到行」的完整链路
#
# 这条链路要能跑通，必须四个东西都在：
#   1) slow_query_log=ON + long_query_time 足够低（本实例 0.1s）
#   2) 慢日志里要有 Rows_examined / Rows_sent（8.0.14+ 开 log_slow_extra 还能有一堆）
#   3) performance_schema.events_statements_summary_by_digest（聚合视角，不用自己聚合日志）
#   4) EXPLAIN ANALYZE（真正执行 + 给真实行数）
#
# 注意：mysqldumpslow 是 perl 脚本，Oracle 的 mysql:8.4 镜像里没带（实测 which 找不到），
#       所以本脚本用 awk 现场做 mysqldumpslow 的活（按 Query_time 排序取 TOP N）。
#
# 用法: sh bench/mysql/17-slow-sql.sh

M="docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4"
SLOW=/var/lib/mysql/slow.log
MARK=q21probe     # 打在 SQL 注释里，用来在几百兆的慢日志里只捞自己造的这几条

echo "========== 0) 先确认「慢」能被记下来 =========="
$M -t -e "SELECT @@slow_query_log AS slow_on, @@long_query_time AS threshold,
                 @@slow_query_log_file AS logfile, @@log_slow_extra AS extra;" 2>/dev/null
# 打开 log_slow_extra：8.0.14 起慢日志能带上更多现场（Rows_affected / tmp 表 / 排序方式等）
$M -e "SET GLOBAL log_slow_extra = ON;" 2>/dev/null
echo "  已 SET GLOBAL log_slow_extra = ON"

BEFORE=$(docker exec learn-mysql sh -c "wc -c < $SLOW")
echo "  当前慢日志大小 = $BEFORE 字节"

echo
echo "========== 1) 造 4 类真实的慢 SQL（都打上 /*$MARK*/ 标记）=========="
sh -c "docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4" <<'SQL' 2>/dev/null
USE learn;
-- (a) 「看起来该走索引」但优化器两害相权：走覆盖索引 range 要读 19 万条索引记录，
--     全表扫要读 50 万行 —— 它选索引。实测 63.9ms，够不上 100ms 阈值，所以压根不会进慢日志。
--     （它是一个**反例**：不是所有慢查询都能从慢日志里看到，阈值之下的要靠 digest 或 EXPLAIN ANALYZE 抓）
SELECT /*q21probe_a*/ COUNT(*) FROM users WHERE city = '北京' AND age > 20;

-- (b) 前置通配符 LIKE：索引直接废掉，扫 50 万行只返回 5 行 —— 典型的「查得少、看得多」
SELECT /*q21probe_b*/ * FROM users WHERE email LIKE '%12345@example.com';

-- (c) 大结果集排序：ORDER BY 一个没索引的列 + LIMIT 10
SELECT /*q21probe_c*/ * FROM users ORDER BY name LIMIT 10;

-- (d) 范围扫返回行太少：能走 created_at 索引，但 36 万行都满足条件（read_next=360309），
--     选择性太差 —— 有索引≠快，关键看「扫了多少行 / 返回多少行」
SELECT /*q21probe_d*/ COUNT(*) FROM users WHERE created_at > '2026-01-01';
SQL

echo "  已执行 (a)~(d)"
AFTER=$(docker exec learn-mysql sh -c "wc -c < $SLOW")
echo "  慢日志增长 = $((AFTER - BEFORE)) 字节"

echo
echo "========== 2) 慢日志原始条目（只捞我造的这几条）=========="
# 【踩过的坑】不能写 grep -A 12 "$MARK"：
#   慢日志里 SQL 是**写在它自己的指标块后面**的（# Time / # Query_time / SET timestamp; 然后才是 SQL），
#   所以「-A 后面 12 行」捞到的是**下一条** SQL 的指标块 —— 打印出来 SQL 和指标错位一条，
#   差点把 b 的耗时安到 c 头上。正确做法是用 -B 4 往上取属于本条的指标块。
docker exec learn-mysql sed -n "1,\$p" $SLOW \
  | grep -B 4 -A 1 "$MARK" | sed 's/^/  /'

echo
echo "========== 3) awk 版 TOP N：按 Query_time 排序（等价 mysqldumpslow -s t）=========="
docker exec learn-mysql sh -c "awk '
  /^# Query_time:/ { qt=\$3; lt=\$5; rs=\$7; re=\$9 }
  /^SET timestamp=/     { getline sql;
                          n[re\"/\"rs]++;
                          t[re\"/\"rs]+=qt;
                          cnt[re\"/\"rs]++;
                          if (qt+0 > max[re\"/\"rs]) max[re\"/\"rs]=qt+0 }
  END { printf \"  %-18s %8s %12s %12s   %s\n\", \"Rows_examined/Sent\", \"次数\", \"累计Query_time\", \"单次最大\", \"样本SQL\";
        for (k in cnt) printf \"  %-18s %8d %12.3f %12.3f\n\", k, cnt[k], t[k], max[k] }
' $SLOW" 2>/dev/null

echo
echo "  ⚠ 上面是全实例的聚合（同实例还有别的库在跑），下面只看我造的这几条："
docker exec learn-mysql sh -c "awk -v m=$MARK '
  /^# Query_time:/ { qt=\$3; lt=\$5; rs=\$7; re=\$9; head=\$0 }
  /^SET timestamp=/ { getline sql; if (index(sql,m)>0) printf \"  %-46s Query_time=%s Lock_time=%s Rows_sent=%s Rows_examined=%s\n\", substr(sql,1,46), qt, lt, rs, re }
' $SLOW" 2>/dev/null

echo
echo "========== 4) 慢日志里 log_slow_extra=ON 带出来的现场字段 =========="
docker exec learn-mysql grep -A 20 "$MARK" $SLOW | grep -E "^# (Query_time|Bytes_sent|Rows_affected|Sort_merge_passes|Sort_scan|Sort_range|Created_tmp_tables|Created_tmp_disk_tables|Created_tmp_files|Handler_rnd_next|Handler_reads|InnoDB_pages_read|InnoDB_rows_read|InnoDB_rows_examined|InnoDB_rows_affected)" | head -40 | sed 's/^/  /'

echo
echo "========== 5) 聚合视角：performance_schema.events_statements_summary_by_digest =========="
$M -t -e "
SELECT LEFT(DIGEST_TEXT, 52) AS stmt,
       COUNT_STAR                          AS 次数,
       ROUND(SUM_TIMER_WAIT/1e9, 1)        AS 累计ms,
       ROUND(AVG_TIMER_WAIT/1e9, 2)        AS 平均ms,   -- AVG_TIMER_WAIT 单位是皮秒，/1e9 才是 ms（曾写成 /1e6，结果大了 1000 倍）
       SUM_ROWS_EXAMINED                   AS 扫描行,
       SUM_ROWS_SENT                       AS 返回行,
       ROUND(SUM_ROWS_EXAMINED/GREATEST(SUM_ROWS_SENT,1)) AS 放大倍数,
       SUM_NO_INDEX_USED                   AS 没走索引,
       SUM_CREATED_TMP_DISK_TABLES         AS 磁盘临时表
FROM performance_schema.events_statements_summary_by_digest
WHERE SCHEMA_NAME = 'learn' AND COUNT_STAR > 0
ORDER BY SUM_TIMER_WAIT DESC LIMIT 12;" 2>/dev/null

echo
echo "========== 6) 定位到具体一条：EXPLAIN + EXPLAIN ANALYZE =========="
echo "  --- (a) 优化器实际选了什么（注意：不是全表扫，是覆盖索引 range）---"
$M -t -e "USE learn; EXPLAIN SELECT COUNT(*) FROM users WHERE city = '北京' AND age > 20;" 2>/dev/null
echo "  --- (a) 强制走索引后的代价对比（EXPLAIN ANALYZE 给真实行数和真实耗时）---"
$M -t -e "USE learn;
EXPLAIN ANALYZE SELECT /*+ NO_INDEX(users) */ COUNT(*) FROM users WHERE city = '北京' AND age > 20;" 2>/dev/null
$M -t -e "USE learn;
EXPLAIN ANALYZE SELECT COUNT(*) FROM users FORCE INDEX (idx_city_age) WHERE city = '北京' AND age > 20;" 2>/dev/null

echo
echo "========== 7) 优化前后：同一条 SQL 的慢日志对照 =========="
echo "  优化前（(d) 那条：没有可用索引，走 created_at 的 range 扫 36 万行）："
docker exec learn-mysql awk -v m="${MARK}_d" '
  /^# Query_time:/ { head=$0 } /^SET timestamp=/ { getline sql; if (index(sql,m)>0) print "    " head }
' $SLOW 2>/dev/null | tail -2
# 加覆盖索引，把回表消掉
$M -e "USE learn; ALTER TABLE users ADD INDEX idx_created_city_age (created_at, city, age);" 2>/dev/null
$M -e "USE learn;
SELECT /*q21probe_d_after*/ COUNT(*) FROM users WHERE created_at > '2026-01-01';" 2>/dev/null
echo "  优化后（加了 (created_at, city, age) 覆盖索引）："
docker exec learn-mysql awk -v m="${MARK}_d_after" '
  /^# Query_time:/ { head=$0 } /^SET timestamp=/ { getline sql; if (index(sql,m)>0) print "    " head }
' $SLOW 2>/dev/null | tail -2
$M -t -e "USE learn; EXPLAIN SELECT COUNT(*) FROM users WHERE created_at > '2026-01-01';" 2>/dev/null

echo
echo "========== 8) 收尾 =========="
$M -e "USE learn; ALTER TABLE users DROP INDEX idx_created_city_age;" 2>/dev/null
$M -e "SET GLOBAL log_slow_extra = OFF;" 2>/dev/null
echo "  已删除临时索引、已还原 log_slow_extra=OFF"
echo
echo "  排查顺序（本脚本走的就是这条链）:"
echo "    慢日志/监控发现 → digest 聚合排序 → 挑出 TopN → EXPLAIN 看计划"
echo "    → EXPLAIN ANALYZE 看真实行数与耗时 → 定位到「扫了多少行、返回多少行、卡在哪一步」"
echo "    → 加索引/改 SQL → 用同一条 SQL 的慢日志复测确认"
