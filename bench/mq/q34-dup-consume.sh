#!/bin/sh
# Q34 重复消费实测：至少一次投递 + 消费端崩溃 → 重复消费 → 幂等挡住
# 必须在宿主机跑（脚本里用 docker exec 观察 broker 状态）
#
# 用法：sh bench/mq/q34-dup-consume.sh
set -u

Q=q33.orders
PHP="docker exec learn-php php /app/bench/mq/q34-consumer.php"
MYSQL="docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4"
RCTL="docker exec q32-rabbit rabbitmqctl"

echo "############ Q34 重复消费实测 ############"
echo

# 0) 重置
$RCTL purge_queue "$Q" >/dev/null 2>&1
$MYSQL -e "USE q37_idem; TRUNCATE t_mq_order; TRUNCATE t_mq_order_idem;" 2>/dev/null

# 1) 生产者发 20 条持久化消息，等 confirm
echo "--- 1) 发 20 条持久化消息 ---"
docker exec learn-php php /app/bench/mq/q34-producer.php "$Q" 20 100
sleep 2
$RCTL list_queues name messages_ready messages_unacknowledged 2>/dev/null | grep -E "name|$Q"

echo
echo "--- 2) 无幂等防护的消费者：处理完 20 条、ack 之前崩溃 ---"
$PHP crash "$Q" t_mq_order c1 2>&1
sleep 2
echo "  broker 状态（未 ack 的 20 条已被重新投递为 ready）："
$RCTL list_queues name messages_ready messages_unacknowledged 2>/dev/null | grep -E "name|$Q"
echo "  MySQL 里已落库行数（崩溃前已写入）："
$MYSQL -N -e "SELECT CONCAT('    t_mq_order rows = ', COUNT(*)) FROM q37_idem.t_mq_order" 2>/dev/null

echo
echo "--- 3) 消费者重连，把这 20 条又处理了一遍（这次正常 ack）---"
$PHP ack "$Q" t_mq_order c2 2>&1
sleep 1
$MYSQL -t -e "USE q37_idem;
SELECT COUNT(*) AS 落库行数, COUNT(DISTINCT order_no) AS 业务订单数,
       COUNT(*)-COUNT(DISTINCT order_no) AS 重复行数 FROM t_mq_order;
SELECT order_no, COUNT(*) AS 出现次数 FROM t_mq_order GROUP BY order_no HAVING COUNT(*)>1 LIMIT 3;" 2>/dev/null
echo "  ↑ 无幂等防护：一个业务订单被消费了两次，落库两条"

echo
echo "--- 4) 换成有幂等防护的消费者（order_no 唯一索引），同样崩溃一次 ---"
$RCTL purge_queue "$Q" >/dev/null 2>&1
docker exec learn-php php /app/bench/mq/q34-producer.php "$Q" 20 200
sleep 2
$PHP crash "$Q" t_mq_order_idem c1 2>&1 | tail -2
sleep 2
$PHP ack "$Q" t_mq_order_idem c2 2>&1 | tail -3
sleep 1
$MYSQL -t -e "USE q37_idem;
SELECT COUNT(*) AS 落库行数, COUNT(DISTINCT order_no) AS 业务订单数,
       COUNT(*)-COUNT(DISTINCT order_no) AS 重复行数 FROM t_mq_order_idem;" 2>/dev/null
echo "  ↑ 有幂等防护：重复投递被 1062 挡回，一个订单只有一条"

echo
echo "--- 5) 队列清空情况 ---"
$RCTL purge_queue "$Q" >/dev/null 2>&1
$RCTL list_queues name messages messages_ready messages_unacknowledged 2>/dev/null | grep -E "name|$Q"
