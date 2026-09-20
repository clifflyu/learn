-- Q37 幂等实测：四种防护方案的对照表结构
-- 用法：docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 < bench/mq/mysql/01-q37-setup.sql
DROP DATABASE IF EXISTS q37_idem;
CREATE DATABASE q37_idem DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE q37_idem;

-- 方案 1：无防护。out_trade_no 只有普通索引，SELECT-then-INSERT 存在竞态窗口
CREATE TABLE t_noguard (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  out_trade_no VARCHAR(64)  NOT NULL,
  user_id      INT          NOT NULL,
  amount       DECIMAL(10,2) NOT NULL,
  status       TINYINT      NOT NULL DEFAULT 1,
  created_at   DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY idx_otn (out_trade_no)
) ENGINE=InnoDB;

-- 方案 2：数据库唯一索引兜底。重复写入由 1062 拒掉
CREATE TABLE t_uniq (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  out_trade_no VARCHAR(64)  NOT NULL,
  user_id      INT          NOT NULL,
  amount       DECIMAL(10,2) NOT NULL,
  status       TINYINT      NOT NULL DEFAULT 1,
  created_at   DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_otn (out_trade_no)
) ENGINE=InnoDB;

-- 方案 3：INSERT ... ON DUPLICATE KEY UPDATE
CREATE TABLE t_upsert (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  out_trade_no VARCHAR(64)  NOT NULL,
  user_id      INT          NOT NULL,
  amount       DECIMAL(10,2) NOT NULL,
  status       TINYINT      NOT NULL DEFAULT 1,
  pay_count    INT          NOT NULL DEFAULT 0,
  created_at   DATETIME(3)  NOT NULL,
  updated_at   DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_otn (out_trade_no)
) ENGINE=InnoDB;

-- 方案 4：Redis SETNX 令牌。表本身无唯一约束，完全靠 Redis 挡
CREATE TABLE t_redis (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  out_trade_no VARCHAR(64)  NOT NULL,
  user_id      INT          NOT NULL,
  amount       DECIMAL(10,2) NOT NULL,
  status       TINYINT      NOT NULL DEFAULT 1,
  created_at   DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY idx_otn (out_trade_no)
) ENGINE=InnoDB;

-- 方案 5：幂等记录表（本地消息表 / 去重表的标准形态）
--    state: 0=processing 1=success 2=failed(可重试)
CREATE TABLE t_idem_record (
  idem_key     VARCHAR(128) NOT NULL,
  state        TINYINT      NOT NULL DEFAULT 0,
  biz_id       VARCHAR(64)  NULL,
  result_body  VARCHAR(255) NULL,
  created_at   DATETIME(3)  NOT NULL,
  updated_at   DATETIME(3)  NOT NULL,
  PRIMARY KEY (idem_key),
  KEY idx_state (state)
) ENGINE=InnoDB;

SELECT 'q37_idem ready' AS msg;
