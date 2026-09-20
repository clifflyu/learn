-- Q17 EXPLAIN 字段解读：type 阶梯 + Extra 语义 + ICP 开关的真实代价
-- 用法: docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 -t < bench/mysql/11-explain.sql

USE learn;

SELECT '===== 1) type 阶梯：从最好到最差逐个造出来 =====' AS '';
SELECT '--- system：先试 1 行表（实测 InnoDB 下给的是 index，拿不到 system）---' AS '';
DROP TABLE IF EXISTS one_row;
CREATE TABLE one_row (id INT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO one_row VALUES (1);
ANALYZE TABLE one_row;
EXPLAIN SELECT * FROM one_row;
SELECT '--- const：主键/唯一索引等值，优化期就能定值 ---' AS '';
EXPLAIN SELECT * FROM users WHERE id = 12345;
SELECT '--- eq_ref：join 时被驱动表用主键/唯一索引等值匹配 ---' AS '';
EXPLAIN SELECT u.name, o.id FROM users u JOIN one_row o ON o.id = u.id;
SELECT '--- ref：非唯一索引等值 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age = 30;
SELECT '--- range：索引范围 ---' AS '';
EXPLAIN SELECT * FROM users WHERE created_at >= '2026-01-01' AND created_at < '2026-01-02';
SELECT '--- index：扫全部索引项 ---' AS '';
EXPLAIN SELECT COUNT(*) FROM users WHERE city <> '北京';
SELECT '--- ALL：全表扫 ---' AS '';
EXPLAIN SELECT * FROM users WHERE name = 'user1';
SELECT '--- index_merge：多个索引取并集 ---' AS '';
EXPLAIN SELECT * FROM users WHERE id BETWEEN 1 AND 5 OR email = 'u1@example.com';

SELECT '===== 2) key_len 怎么读：它等于「参与定位的键部分的字节数」=====' AS '';
SELECT '--- varchar(32) utf8mb4 = 32*4+2 = 130 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京';
SELECT '--- 再加 tinyint age = 130+1 = 131 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age = 30;
SELECT '--- varchar(64) utf8mb4 = 64*4+2 = 258 ---' AS '';
EXPLAIN SELECT * FROM users WHERE email = 'u12345@example.com';
SELECT '--- datetime = 5 ---' AS '';
EXPLAIN SELECT * FROM users WHERE created_at >= '2026-01-01' AND created_at < '2026-01-02';
SELECT '--- 允许 NULL 的列要 +1（NULL 标志位）；本例列都是 NOT NULL 所以没体现 ---' AS '';

SELECT '===== 3) rows 与 filtered：估算值，不是实际值 =====' AS '';
SELECT '--- rows 是估算的行数，filtered 是「过滤后剩余百分比」的估算 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京';
SELECT '--- 实际值是 100000，估算值明显偏大（索引统计信息是采样出来的）---' AS '';
SELECT COUNT(*) AS 实际行数 FROM users WHERE city = '北京';
SELECT '--- rows × filtered = 优化器认为要交给下一步的行数 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age = 30;
SELECT '--- 对照实际 ---' AS '';
SELECT COUNT(*) AS 实际行数 FROM users WHERE city = '北京' AND age = 30;

SELECT '===== 4) Extra：三个「用了索引」的说法完全不是一回事 =====' AS '';
SELECT '--- NULL：索引里就能判定，不需要回表也不需要 server 层过滤 ---' AS '';
EXPLAIN SELECT * FROM users WHERE city = '北京' AND age = 30;
SELECT '--- Using index（Covering index）：查询的列全在索引里，不回表 ---' AS '';
EXPLAIN SELECT city, age FROM users WHERE city = '北京' AND age = 30;
SELECT '--- Using index condition（ICP）：条件被下推到引擎层，在索引上先过滤再回表 ---' AS '';
EXPLAIN SELECT * FROM users FORCE INDEX (idx_city_age) WHERE city = '北京' AND age > 20;
SELECT '--- Using where：server 层收到行之后再过滤 ---' AS '';
EXPLAIN SELECT * FROM users WHERE name = 'user1';
SELECT '--- Using filesort / Using temporary ---' AS '';
EXPLAIN SELECT name FROM users WHERE city = '北京' GROUP BY name ORDER BY name;
SELECT '--- Using index for skip scan ---' AS '';
EXPLAIN SELECT age FROM users WHERE age = 30;

-- ICP 的耗时对比见 12-icp.php（Extra 字段的差异在这里体现，耗时差异在那里量）
SELECT '===== 6) EXPLAIN 三种格式 =====' AS '';
EXPLAIN FORMAT=TREE SELECT * FROM users WHERE city = '北京' AND age = 30;
EXPLAIN FORMAT=JSON SELECT * FROM users WHERE city = '北京' AND age = 30;
EXPLAIN ANALYZE SELECT * FROM users WHERE city = '北京' AND age = 30;
