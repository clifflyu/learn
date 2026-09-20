-- Q46 秒杀防超卖 + Q47 订单超时关闭  实测数据准备
-- 用法: docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 < bench/design/01-setup.sql
--
-- 只动自己的库 q46_seckill，不碰 learn 库。

DROP DATABASE IF EXISTS q46_seckill;
CREATE DATABASE q46_seckill DEFAULT CHARACTER SET utf8mb4;
USE q46_seckill;

-- ---------- Q46 秒杀 ----------
-- stock：库存行。sold 冗余一份「已卖」，方便直接读，不用 COUNT(orders)
CREATE TABLE stock (
  goods_id   INT UNSIGNED NOT NULL PRIMARY KEY,
  stock      INT          NOT NULL,          -- 剩余库存
  sold       INT          NOT NULL DEFAULT 0, -- 已卖（成功扣减次数）
  version    INT          NOT NULL DEFAULT 0,
  updated_at DATETIME(3)  NOT NULL
) ENGINE=InnoDB;

-- orders：订单表。这张表是「卖了多少」的唯一事实来源（ground truth），
-- 库存字段算错了，订单条数不会骗人。
-- uk_goods_user 同时兼职「一人一单」防刷 + Q47 的幂等唯一约束。
CREATE TABLE orders (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  goods_id   INT UNSIGNED    NOT NULL,
  user_id    INT UNSIGNED    NOT NULL,
  status     TINYINT         NOT NULL DEFAULT 0,  -- 0=待支付 1=已支付 2=已关闭
  created_at DATETIME(3)     NOT NULL,
  expire_at  DATETIME(3)     NULL,                -- Q47 超时时间
  closed_at  DATETIME(3)     NULL,
  UNIQUE KEY uk_goods_user (goods_id, user_id),
  KEY idx_status_expire (status, expire_at)       -- Q47 轮询扫表用
) ENGINE=InnoDB;

INSERT INTO stock (goods_id, stock, sold, updated_at) VALUES (1, 100, 0, NOW(3));

SELECT VERSION() AS mysql版本;
SELECT * FROM stock;

-- ---------- Q47 订单超时关闭 ----------
-- 关闭尝试流水：**不加任何唯一约束**，每一次「关闭动作」都记一条。
-- 用它数「同一张订单被关了几次」——这才是重复关闭的直接证据。
CREATE TABLE close_attempt (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id   BIGINT UNSIGNED NOT NULL,
  source     VARCHAR(16)     NOT NULL,
  worker     VARCHAR(16)     NOT NULL,
  created_at DATETIME(3)     NOT NULL
) ENGINE=InnoDB;

-- 关闭流水表：每一次「关闭动作」都在这里留一条，用于验证幂等
CREATE TABLE close_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id   BIGINT UNSIGNED NOT NULL,
  source     VARCHAR(16)     NOT NULL,   -- poll / redis / zset
  worker     VARCHAR(32)     NOT NULL,
  created_at DATETIME(3)     NOT NULL,
  UNIQUE KEY uk_order (order_id)         -- 同一个订单只允许关闭一次
) ENGINE=InnoDB;

SELECT 'q46_seckill 初始化完成' AS 状态;
