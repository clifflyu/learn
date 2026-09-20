-- Q41 SQL 注入实测用的独立库，不碰 learn 库。
-- 灌数据：
--   docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 < bench/network/q41/00-setup.sql

DROP DATABASE IF EXISTS q41_sqli;
CREATE DATABASE q41_sqli DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE q41_sqli;

CREATE TABLE users (
  id       INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(64)  NOT NULL,
  password VARCHAR(64)  NOT NULL,
  role     VARCHAR(16)  NOT NULL DEFAULT 'user',
  email    VARCHAR(128) NOT NULL DEFAULT '',
  UNIQUE KEY uk_username (username)
) ENGINE=InnoDB;

-- 「攻击者不该看到」的表：注入成功就能整表拖走
CREATE TABLE credit_cards (
  id      INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  card_no VARCHAR(32) NOT NULL,
  bank    VARCHAR(32) NOT NULL
) ENGINE=InnoDB;

INSERT INTO users (username, password, role, email) VALUES
  ('admin',  'S3cr3t!Pass', 'admin', 'admin@q41.local'),
  ('alice',  'alice123',    'user',  'alice@q41.local'),
  ('bob',    'bob123',      'user',  'bob@q41.local');

INSERT INTO credit_cards (user_id, card_no, bank) VALUES
  (1, '6222-0000-0000-0001', 'ICBC'),
  (2, '6222-0000-0000-0002', 'CCB'),
  (3, '6222-0000-0000-0003', 'ABC');

SELECT '--- users ---' AS '';
SELECT id, username, role FROM users;
SELECT '--- credit_cards ---' AS '';
SELECT id, card_no, bank FROM credit_cards;
SELECT CONCAT('mysql ', VERSION()) AS '';
