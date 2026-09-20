#!/bin/sh
# Q20 死锁复现：两个并发会话，以相反顺序锁同一批行 → InnoDB 检出死锁并回滚一方
# 用法: sh bench/mysql/06-deadlock.sh

MYSQL="docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4"

# 打开死锁日志，方便事后查现场
docker exec learn-mysql mysql -uroot -proot -e \
  "SET GLOBAL innodb_print_all_deadlocks = ON;" 2>/dev/null

$MYSQL -e "USE learn; DROP TABLE IF EXISTS stock;
  CREATE TABLE stock (id INT PRIMARY KEY, qty INT NOT NULL) ENGINE=InnoDB;
  INSERT INTO stock VALUES (1,100),(2,100);"

echo "初始状态:"; $MYSQL -t -e "SELECT * FROM learn.stock;"

echo
echo "A、B 同时开始，A 先锁 id=1，B 先锁 id=2，2 秒后各自去锁对方持有的行"

# 会话 A：1 → 2
$MYSQL -e "USE learn;
  BEGIN;
  UPDATE stock SET qty=qty-1 WHERE id=1;
  SELECT SLEEP(2);
  UPDATE stock SET qty=qty-1 WHERE id=2;
  COMMIT;" 2>&1 | grep -iE 'deadlock|ERROR' &

# 会话 B：2 → 1
$MYSQL -e "USE learn;
  BEGIN;
  UPDATE stock SET qty=qty-1 WHERE id=2;
  SELECT SLEEP(2);
  UPDATE stock SET qty=qty-1 WHERE id=1;
  COMMIT;" 2>&1 | grep -iE 'deadlock|ERROR' &

wait

echo
echo "===== InnoDB 死锁现场：SHOW ENGINE INNODB STATUS 的 LATEST DETECTED DEADLOCK ====="
# 注意：innodb_print_all_deadlocks=ON 只保证写错误日志；docker logs 里能不能捞到取决于日志落盘时机，
# 而 SHOW ENGINE INNODB STATUS 里的这一段是内存里**最近一次**死锁的原文，最可靠。
#
# 【踩过的坑】不要写 sed -n '/LATEST DETECTED DEADLOCK/,/^---/p'：
#   LATEST DETECTED DEADLOCK 这一行的**紧后面**就是一行 '-----'（段分隔线），
#   所以 sed 的区间在这里立刻结束，只会打出 2 行标题，现场全丢。
#   正确做法是用 awk 从标题打到 TRANSACTIONS 段头为止。
docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 -N -e \
  "SHOW ENGINE INNODB STATUS\G" 2>/dev/null | awk '/^LATEST DETECTED DEADLOCK/{f=1} f{print} f&&/^TRANSACTIONS/{exit}' | head -60

echo
echo "===== 同一段现场（从容器错误日志里捞，作为对照）====="
docker logs learn-mysql 2>&1 | awk '/LATEST DETECTED DEADLOCK/{f=1} f{print}' | head -60

echo
echo "===== 死锁计数 ====="
docker exec learn-mysql mysql -uroot -proot -t -e \
  "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks','Innodb_row_lock_waits','Innodb_row_lock_time_avg');" 2>/dev/null
