#!/bin/sh
# Q33 消费端：手动 ack / 未 ack 时崩溃 的消息归属变化
# 在宿主机跑（脚本用 rabbitmqctl 观察 broker 内部计数）
#
# 用法：sh bench/mq/q33-rabbit-ack.sh
set -u

Q=q33.ackdemo
MYSQL="docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4"
RCTL="docker exec q32-rabbit rabbitmqctl"

show() {
    printf "  %-34s" "$1"
    $RCTL list_queues name messages_ready messages_unacknowledged 2>/dev/null \
        | grep -E "^$Q" | awk '{printf "ready=%s unacked=%s\n", $2, $3}'
}

echo "########## Q33 消费端：手动 ack 与崩溃重投 ##########"
echo
$MYSQL -e "USE q37_idem; TRUNCATE t_mq_order;" 2>/dev/null
$RCTL purge_queue "$Q" >/dev/null 2>&1

echo "--- 1) 发 10 条，全部 ready ---"
docker exec learn-php php /app/bench/mq/q34-producer.php "$Q" 10 900 2>&1 | sed 's/^/  /'
sleep 1
show "生产完成"

echo
echo "--- 2) 消费者取走 1 条、不 ack、挂住 6 秒 ---"
# hold 模式：取 1 条，不 ack，6 秒后 SIGKILL
docker exec learn-php php /app/bench/mq/q34-consumer.php hold "$Q" t_mq_order hold1 >/dev/null 2>&1 &
HOLDPID=$!
sleep 3
show "消费者持有消息期间"
echo "  ↑ 消息从 ready 移到 unacked：还在 broker 手里，没丢，但也没被确认"
sleep 5
wait $HOLDPID 2>/dev/null
sleep 1
show "消费者被 SIGKILL 之后"
echo "  ↑ 连接一断，broker 把 unacked 的消息全部退回 ready —— 这就是「至少一次」的来源"

echo
echo "--- 3) 正常消费者逐条 ack，队列排空 ---"
docker exec learn-php php /app/bench/mq/q34-consumer.php ack "$Q" t_mq_order ok1 2>&1 | tail -1 | sed 's/^/  /'
sleep 1
show "全部 ack 之后"
echo
$MYSQL -t -e "USE q37_idem; SELECT COUNT(*) AS 落库行数, COUNT(DISTINCT order_no) AS 业务订单数 FROM t_mq_order;" 2>/dev/null
$RCTL purge_queue "$Q" >/dev/null 2>&1
