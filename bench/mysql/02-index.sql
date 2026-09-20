-- Q14 聚簇索引/二级索引/回表/覆盖索引  Q15 最左前缀  Q16 索引失效  Q17 EXPLAIN
-- 用法: docker exec -i learn-mysql mysql -uroot -proot -t < bench/mysql/02-index.sql

USE learn;

SELECT '=========== Q17: EXPLAIN 关键字段 ===========' AS '';

EXPLAIN SELECT * FROM users WHERE id = 12345;
EXPLAIN SELECT * FROM users WHERE email = 'u12345@example.com';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age = 30;
EXPLAIN SELECT * FROM users WHERE age = 30;
EXPLAIN SELECT * FROM users WHERE name LIKE '%user123%';

SELECT '=========== Q14: 回表 vs 覆盖索引 ===========' AS '';

-- 回表：SELECT * 需要拿二级索引里没有的列，逐行回聚簇索引
SELECT '--- 回表 SELECT * ---' AS '';
EXPLAIN ANALYZE SELECT * FROM users WHERE city = '北京' AND age = 30;

-- 覆盖索引：只取索引里已有的列
SELECT '--- 覆盖索引（只取 city, age）---' AS '';
EXPLAIN ANALYZE SELECT city, age FROM users WHERE city = '北京' AND age = 30;

-- 用 Handler 计数器看回表次数（IO 层面的证据）
FLUSH STATUS;
SELECT COUNT(*) FROM (SELECT * FROM users WHERE city = '北京' AND age = 30) t;
SHOW SESSION STATUS WHERE Variable_name IN
  ('Handler_read_key','Handler_read_next','Handler_read_rnd_next','Handler_read_rnd');

SELECT '=========== Q15: 最左前缀原则 ===========' AS '';

SELECT '--- 命中：(city) ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京';
SELECT '--- 命中：(city, age) ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age = 30;
SELECT '--- 只用 (age)：跳过最左列 → 全表扫描 ---' AS '';
EXPLAIN SELECT * FROM users WHERE age = 30;
SELECT '--- (age, city)：顺序反了，但优化器能重排后仍走索引 ---' AS '';
EXPLAIN SELECT * FROM users WHERE age = 30 AND city = '北京';

SELECT '=========== Q16: 索引失效的几种写法 ===========' AS '';

SELECT '--- 1) 对索引列做函数运算 ---' AS '';
EXPLAIN SELECT * FROM users WHERE DATE(created_at) = '2026-01-01';
SELECT '--- 改写成范围查询就有效 ---' AS '';
EXPLAIN SELECT * FROM users WHERE created_at >= '2026-01-01' AND created_at < '2026-01-02';

SELECT '--- 2) 前导通配符 LIKE ---' AS '';
EXPLAIN SELECT * FROM users WHERE email LIKE '%12345@example.com';
SELECT '--- 去掉前导 % 就有效 ---' AS '';
EXPLAIN SELECT * FROM users WHERE email LIKE 'u12345@%';

SELECT '--- 3) 隐式类型转换（email 是 varchar，传数字）---' AS '';
EXPLAIN SELECT * FROM users WHERE email = 12345;

SELECT '--- 4) OR 连接非索引列 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' OR name = 'user1';

SELECT '--- 5) 违反最左前缀 + 范围后续列失效 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age > 20;
