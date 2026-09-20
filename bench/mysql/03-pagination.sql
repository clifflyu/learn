-- Q22 深分页优化  Q21 慢 SQL 慢在哪
-- 用法: docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 -t < bench/mysql/03-pagination.sql

USE learn;

SELECT '=========== Q22: 深分页三种写法 ===========' AS '';

SELECT '--- 1) 传统 LIMIT offset, n（翻到第 2 万页）---' AS '';
EXPLAIN ANALYZE SELECT * FROM users ORDER BY id LIMIT 400000, 20;

SELECT '--- 2) 延迟关联：先用覆盖索引取 id，再回表 ---' AS '';
EXPLAIN ANALYZE
SELECT u.* FROM users u
JOIN (SELECT id FROM users ORDER BY id LIMIT 400000, 20) t ON u.id = t.id;

SELECT '--- 3) 游标 / keyset 分页：记住上一页最后一个 id ---' AS '';
EXPLAIN ANALYZE SELECT * FROM users WHERE id > 400000 ORDER BY id LIMIT 20;

SELECT '=========== 浅分页做对照 ===========' AS '';
SELECT '--- LIMIT 0, 20 ---' AS '';
EXPLAIN ANALYZE SELECT * FROM users ORDER BY id LIMIT 0, 20;

SELECT '=========== Q14 补充：回表 vs 覆盖索引的 EXPLAIN ANALYZE ===========' AS '';

SELECT '--- 覆盖索引：COUNT(*) 只需索引里的列，计划是 Covering index ---' AS '';
EXPLAIN ANALYZE SELECT COUNT(*) FROM users WHERE city = '北京' AND age = 30;

SELECT '--- 回表：要取 name，必须回聚簇索引，计划是 Index lookup ---' AS '';
EXPLAIN ANALYZE SELECT SUM(LENGTH(name)) FROM users WHERE city = '北京' AND age = 30;
