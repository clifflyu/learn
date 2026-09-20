-- Q10 N+1 实测数据准备。库名 q10_n1（独立库，不碰 learn）
-- 用法：docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 < bench/framework/10-q10-setup.sql
DROP DATABASE IF EXISTS q10_n1;
CREATE DATABASE q10_n1 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE q10_n1;

CREATE TABLE users (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(64)  NOT NULL,
  email      VARCHAR(128) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE posts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(200) NOT NULL,
  content    TEXT         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_posts_user_id (user_id)
) ENGINE=InnoDB;

-- 5000 个用户
SET SESSION cte_max_recursion_depth = 100000;
INSERT INTO users (name, email)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 5000)
SELECT CONCAT('user_', LPAD(n, 5, '0')), CONCAT('user', n, '@example.com') FROM seq;

-- 25000 篇帖子：每个用户正好 5 篇（user_id 独立于 id 递增，分布均匀）
INSERT INTO posts (user_id, title, content)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 25000)
SELECT (n % 5000) + 1, CONCAT('post title ', n), REPEAT('x', 200) FROM seq;

ANALYZE TABLE users, posts;

SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM posts) AS posts;
SELECT user_id, COUNT(*) AS c FROM posts GROUP BY user_id ORDER BY c DESC LIMIT 3;
