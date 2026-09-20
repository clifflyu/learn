-- Q44 实测数据准备：慢 SQL 现场
-- 用法: docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 < bench/ops/q44-sql-setup.sql
--
-- 注意：库名用 q44_slow，不碰 learn 库。
-- status 用 n % 5、created_at 用 FLOOR(n/5) % 365 —— 两者基底不同（5 与 365/5=73 互质），
-- 保证「任意状态 × 任意日期」都有数据，避免取模基数相同导致某些组合 0 行。

DROP DATABASE IF EXISTS q44_slow;
CREATE DATABASE q44_slow;
USE q44_slow;

-- 默认 1000 层，1M 行的递归 CTE 会被截断
SET SESSION cte_max_recursion_depth = 1100000;

CREATE TABLE customers (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(32)  NOT NULL,
  city       VARCHAR(32)  NOT NULL,
  created_at DATETIME     NOT NULL
) ENGINE=InnoDB;

CREATE TABLE orders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED     NOT NULL,
  status      TINYINT UNSIGNED NOT NULL,   -- 0 待付款 1 已付款 2 已发货 3 已完成 4 已取消
  amount      DECIMAL(10,2)    NOT NULL,
  created_at  DATETIME         NOT NULL,
  note        VARCHAR(64)      NOT NULL
  -- 除了主键一个索引都没有：status / created_at / customer_id 上全是空的。
  -- 很多线上大表就是这么长起来的——「按状态 + 时间范围查订单」是「按客户查订单」
  -- 在这里都只能全表扫描，后面慢 SQL 和 N+1 两个实验都建在这个前提上。
) ENGINE=InnoDB;

INSERT INTO customers (name, city, created_at)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 5000)
SELECT CONCAT('customer-', n),
       ELT(1 + (n % 5), '北京', '上海', '广州', '深圳', '杭州'),
       NOW() - INTERVAL (n % 365) DAY
FROM seq;

INSERT INTO orders (customer_id, status, amount, created_at, note)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 1000000)
SELECT
  (n % 5000) + 1,
  n % 5,
  ROUND(10 + (n % 9900) / 100, 2),
  NOW() - INTERVAL (FLOOR(n / 5) % 365) DAY,
  CONCAT('order note ', n)
FROM seq;

ANALYZE TABLE orders;

SELECT COUNT(*) AS 订单总数 FROM orders;
SELECT status, COUNT(*) AS 行数 FROM orders GROUP BY status;
