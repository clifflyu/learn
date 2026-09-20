-- Q15 最左前缀原则：用 key_len 精确读出「索引用到了第几列」
-- key_len 是唯一能直接看出「用到哪几列」的字段：INT = 4 B，所以 4/8/12 就是 1/2/3 列
-- 用法: docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 -t < bench/mysql/09-leftmost.sql

USE learn;

DROP TABLE IF EXISTS t_abc;
CREATE TABLE t_abc (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  a  INT NOT NULL,
  b  INT NOT NULL,
  c  INT NOT NULL,
  d  VARCHAR(64) NOT NULL,
  KEY idx_abc (a, b, c)
) ENGINE=InnoDB;

-- 三列用互不相同的取模基数，保证任意组合都有足够行数（同基数的列会强相关，聚合会出假结论）
SET SESSION cte_max_recursion_depth = 300000;
INSERT INTO t_abc (a, b, c, d)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 200000)
SELECT n % 10, n % 97, n % 997, CONCAT('row', n) FROM seq;
ANALYZE TABLE t_abc;

SELECT COUNT(*) AS 总行数, COUNT(DISTINCT a) AS a基数, COUNT(DISTINCT b) AS b基数, COUNT(DISTINCT c) AS c基数 FROM t_abc;

SELECT '===== 1) 前缀长度与 key_len 的对应 =====' AS '';
SELECT '--- a：key_len 应为 4 ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1;
SELECT '--- a,b：key_len 应为 8 ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 AND b = 2;
SELECT '--- a,b,c：key_len 应为 12 ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 AND b = 2 AND c = 3;

SELECT '===== 2) 不用最左列 a：整条索引用不上 =====' AS '';
SELECT '--- 只给 b ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE b = 2;
SELECT '--- 只给 c ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE c = 3;
SELECT '--- b,c 都给，但没有 a ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE b = 2 AND c = 3;

SELECT '===== 3) 有 a，但跳过 b 直接给 c：只能用到 a（key_len=4）=====' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 AND c = 3;

SELECT '===== 4) 乱序不影响：优化器会按索引顺序重排 =====' AS '';
EXPLAIN SELECT * FROM t_abc WHERE c = 3 AND b = 2 AND a = 1;

SELECT '===== 5) 范围查询会截断后续列（key_len 停在 b）=====' AS '';
SELECT '--- a=1 AND b>2 AND c=3：c 不能用于定位，只能靠 ICP 过滤 ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 AND b > 2 AND c = 3;
SELECT '--- 把 c 提到 b 前面（改成等值在前、范围在后）---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 AND c = 3 AND b > 2;

SELECT '===== 6) ORDER BY 能不能白嫖索引顺序（会话级 Sort_* 计数器，不受其他会话干扰）=====' AS '';
SELECT '--- WHERE a=1 ORDER BY b,c：索引本身有序 → 期望 No filesort ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 ORDER BY b, c LIMIT 10;
FLUSH STATUS;
SELECT * FROM t_abc WHERE a = 1 ORDER BY b, c LIMIT 10;
SHOW SESSION STATUS WHERE Variable_name IN ('Sort_scan','Sort_range','Sort_rows','Handler_read_next','Handler_read_prev');

SELECT '--- WHERE a=1 ORDER BY c：跳过 b 排 c → 期望 filesort ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 ORDER BY c LIMIT 10;
FLUSH STATUS;
SELECT * FROM t_abc WHERE a = 1 ORDER BY c LIMIT 10;
SHOW SESSION STATUS WHERE Variable_name IN ('Sort_scan','Sort_range','Sort_rows','Handler_read_next','Handler_read_prev');

SELECT '--- WHERE a=1 ORDER BY b DESC, c DESC：反向扫描也能白嫖 ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 ORDER BY b DESC, c DESC LIMIT 10;
FLUSH STATUS;
SELECT * FROM t_abc WHERE a = 1 ORDER BY b DESC, c DESC LIMIT 10;
SHOW SESSION STATUS WHERE Variable_name IN ('Sort_scan','Sort_range','Sort_rows','Handler_read_next','Handler_read_prev');

SELECT '--- WHERE a=1 ORDER BY b ASC, c DESC：混合方向 → filesort ---' AS '';
EXPLAIN SELECT * FROM t_abc WHERE a = 1 ORDER BY b ASC, c DESC LIMIT 10;
FLUSH STATUS;
SELECT * FROM t_abc WHERE a = 1 ORDER BY b ASC, c DESC LIMIT 10;
SHOW SESSION STATUS WHERE Variable_name IN ('Sort_scan','Sort_range','Sort_rows','Handler_read_next','Handler_read_prev');

SELECT '===== 7) GROUP BY 能否用索引 =====' AS '';
SELECT '--- GROUP BY a,b（索引前缀）---' AS '';
EXPLAIN SELECT a, b, COUNT(*) FROM t_abc GROUP BY a, b;
SELECT '--- GROUP BY b（不是前缀）---' AS '';
EXPLAIN SELECT b, COUNT(*) FROM t_abc GROUP BY b;
