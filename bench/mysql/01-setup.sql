-- Q13~Q24 实测数据准备
-- 用法: docker exec -i learn-mysql mysql -uroot -proot < bench/mysql/01-setup.sql

DROP DATABASE IF EXISTS learn;
CREATE DATABASE learn;
USE learn;

CREATE TABLE users (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(32)  NOT NULL,
  age        TINYINT UNSIGNED NOT NULL,
  city       VARCHAR(32)  NOT NULL,
  email      VARCHAR(64)  NOT NULL,
  created_at DATETIME     NOT NULL
) ENGINE=InnoDB;

-- 50 万行，用递归 CTE 生成，避免依赖外部文件
SET SESSION cte_max_recursion_depth = 600000;

INSERT INTO users (name, age, city, email, created_at)
WITH RECURSIVE seq(n) AS (
  SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 500000
)
SELECT
  CONCAT('user', n),
  18 + (n % 50),
  -- city 用 FLOOR(n/50)、age 用 n%50，两者独立，保证任意组合都有数据
  ELT(1 + (FLOOR(n / 50) % 5), '北京', '上海', '广州', '深圳', '杭州'),
  CONCAT('u', n, '@example.com'),
  NOW() - INTERVAL (n % 365) DAY
FROM seq;

ALTER TABLE users ADD INDEX idx_city_age (city, age);
ALTER TABLE users ADD INDEX idx_email (email);
ALTER TABLE users ADD INDEX idx_created (created_at);

ANALYZE TABLE users;

SELECT COUNT(*) AS 总行数, COUNT(DISTINCT city) AS 城市数 FROM users;
SELECT city, COUNT(*) AS 行数 FROM users GROUP BY city;
