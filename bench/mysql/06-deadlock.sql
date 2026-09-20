-- Q20 死锁复现：两个会话以相反顺序加锁
-- 传入参数 @tag 区分 A / B
USE learn;

BEGIN;
-- A: 先锁 id=1；B: 先锁 id=2（由调用方用不同文件控制顺序）
UPDATE stock SET qty = qty - 1 WHERE id = 1;
SELECT SLEEP(2);
UPDATE stock SET qty = qty - 1 WHERE id = 2;
COMMIT;
