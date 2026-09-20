-- Q16 索引失效：把「真失效」和「优化器选了全表扫」区分开
-- 关键区分法：加 FORCE INDEX 再看计划 —— 能用却不用 = 成本选择；加了还是 NULL = 真失效
-- 用法: docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 -t < bench/mysql/10-index-fail.sql

USE learn;

SELECT '===== 1) 对索引列做函数/运算：真失效 =====' AS '';
SELECT '--- DATE(created_at) = ... → ALL，possible_keys 为 NULL，连候选都没有 ---' AS '';
EXPLAIN SELECT * FROM users WHERE DATE(created_at) = '2026-01-01';
SELECT '--- FORCE INDEX 也救不回来，key 仍是 NULL ---' AS '';
EXPLAIN SELECT * FROM users FORCE INDEX (idx_created) WHERE DATE(created_at) = '2026-01-01';
SELECT '--- 改写成范围：range，key_len=5（DATETIME 5 B），1370 行 ---' AS '';
EXPLAIN SELECT * FROM users WHERE created_at >= '2026-01-01' AND created_at < '2026-01-02';

SELECT '===== 2) 隐式类型转换：只有「字符串列 = 数字」这一侧真的失效 =====' AS '';
SELECT '--- varchar 列 = 数字（列被转成 double）→ ALL，possible_keys 有 idx_email 但 key=NULL ---' AS '';
EXPLAIN SELECT * FROM users WHERE email = 12345;
SELECT '--- int 列 = 字符串 → const 点查，照样走主键 ---' AS '';
EXPLAIN SELECT * FROM users WHERE id = '12345';
SELECT '--- 更坑的：int 列 = "12345abc"，计划是 const，且真的返回了 id=12345 这一行 ---' AS '';
EXPLAIN SELECT * FROM users WHERE id = '12345abc';
SELECT id, name FROM users WHERE id = '12345abc';
SHOW WARNINGS;

SELECT '===== 3) 前导通配符 =====' AS '';
EXPLAIN SELECT * FROM users WHERE email LIKE '%12345@example.com';
SELECT '--- 去掉前导 % 就是 range ---' AS '';
EXPLAIN SELECT * FROM users WHERE email LIKE 'u12345@%';

SELECT '===== 4) OR：不是「失效」，是成本问题 =====' AS '';
SELECT '--- city 命中 20%（10 万行）→ 优化器直接全表扫，possible_keys 两个都在 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' OR email = 'u1@example.com';
SELECT '--- 加 INDEX_MERGE hint 强制走 index_merge：Using sort_union ---' AS '';
EXPLAIN SELECT /*+ INDEX_MERGE(users idx_city_age, idx_email) */ * FROM users
  WHERE city = '北京' OR email = 'u1@example.com';
SELECT '--- 两边都选择性高时，优化器自己就会选 index_merge：Using union(PRIMARY,idx_email) ---' AS '';
EXPLAIN SELECT * FROM users WHERE id BETWEEN 1 AND 5 OR email = 'u1@example.com';
SELECT '--- 都是范围时是 sort_union（要先排序再归并）---' AS '';
EXPLAIN SELECT * FROM users WHERE created_at > '2026-09-19 00:00:00' OR email = 'u1@example.com';
SELECT '--- OR 里有一个无索引列，就彻底没救 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' OR name = 'user1';

SELECT '===== 5) 最反直觉：范围查询后面的列不是「失效」，是「不能用于定位」=====' AS '';
SELECT '--- city=北京 AND age>20 → ALL（成本选择，不是用不上）---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age > 20;
SELECT '--- FORCE INDEX 证明完全可用，range + key_len=131（city 130 B + age 1 B 都进了定位）---' AS '';
EXPLAIN SELECT * FROM users FORCE INDEX (idx_city_age) WHERE city = '北京' AND age > 20;
SELECT '--- 对照：a=1 AND b>2 AND c=3（第 3 列只能靠 ICP 过滤，key_len 停在 8）见 09-leftmost.sql ---' AS '';
SELECT '--- 换成等值条件，优化器立刻改主意 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age = 30;

SELECT '===== 6) MySQL 8.0 的 skip scan：违反最左前缀未必全表扫 =====' AS '';
SELECT '--- 只给 age（跳过最左列 city），覆盖索引 → 自带 Using index for skip scan ---' AS '';
EXPLAIN SELECT age FROM users WHERE age = 30;
SELECT '--- 把 skip_scan 关掉做对照：退化成 type=index 全索引扫，扫满 498653 行 ---' AS '';
SET SESSION optimizer_switch = 'skip_scan=off';
EXPLAIN SELECT age FROM users WHERE age = 30;
SET SESSION optimizer_switch = 'skip_scan=on';
SELECT '--- 但 SELECT * 时回表太贵，skip scan 不划算 → 仍然 ALL ---' AS '';
EXPLAIN SELECT * FROM users WHERE age = 30;

SELECT '===== 7) 函数写在常量侧不影响索引 =====' AS '';
EXPLAIN SELECT * FROM users WHERE created_at > NOW() - INTERVAL 1 DAY;
SELECT '--- 写在列侧就废了 ---' AS '';
EXPLAIN SELECT * FROM users WHERE YEAR(created_at) = 2026;

SELECT '===== 8) <> 与 NOT IN：不是不用索引，是「用索引扫全表」=====' AS '';
SELECT '--- type=index（扫全部索引项）而不是 ALL，因为要查的列都在索引里 ---' AS '';
EXPLAIN SELECT COUNT(*) FROM users WHERE city <> '北京';
EXPLAIN SELECT COUNT(*) FROM users WHERE city NOT IN ('北京');

SELECT '===== 9) 覆盖索引能让「看着失效」的写法换个便宜的死法 =====' AS '';
SELECT '--- 前导通配符 + 只查索引列 → type=index，全索引扫 ---' AS '';
EXPLAIN SELECT email FROM users WHERE email LIKE '%12345@example.com';
FLUSH STATUS;
SELECT email FROM users WHERE email LIKE '%12345@example.com';
SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next','Handler_read_rnd_next','Handler_read_prev');
SELECT '--- 对照：无索引列查询 → type=ALL，全表扫 ---' AS '';
EXPLAIN SELECT name FROM users WHERE name = 'user1';
FLUSH STATUS;
SELECT name FROM users WHERE name = 'user1';
SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next','Handler_read_rnd_next','Handler_read_prev');
