#!/bin/sh
# Q33 存储端：队列持久化 与 消息持久化 各管什么 —— 重启 broker 看结果
# 在宿主机跑（脚本要 docker restart）
#
# 用法：sh bench/mq/q33-persist.sh
set -u

RCTL="docker exec q32-rabbit rabbitmqctl"
N=1000

# rabbitmqctl 在 broker 刚起来时会因为节点未就绪而打 Usage，需要重试
rctl_queues() {
    i=0
    while [ $i -lt 30 ]; do
        out=$($RCTL list_queues name messages messages_ready durable 2>/dev/null)
        if echo "$out" | grep -q "^name"; then
            echo "$out" | grep -E "name|q33\.p\."
            return 0
        fi
        i=$((i+1))
        sleep 2
    done
    echo "  (rabbitmqctl 30 次重试仍未就绪)"
}

echo "########## Q33 存储端：持久化三件套 ##########"
echo

# 清掉上轮残留（不存在的队列会报错，忽略）
for q in q33.p.transient q33.p.durable-queue q33.p.both; do
    $RCTL delete_queue "$q" >/dev/null 2>&1
done
$RCTL delete_exchange q33.persist.ex >/dev/null 2>&1

echo "--- 1) 各发 ${N} 条 ---"
docker exec learn-php php /app/bench/mq/q33-persist-producer.php q33.p.transient      "$N" 0 0
docker exec learn-php php /app/bench/mq/q33-persist-producer.php q33.p.durable-queue "$N" 1 0
docker exec learn-php php /app/bench/mq/q33-persist-producer.php q33.p.both           "$N" 1 1
sleep 2

echo
echo "--- 2) 重启 broker 之前 ---"
rctl_queues

echo
echo "--- 3) docker restart q32-rabbit ---"
docker restart q32-rabbit >/dev/null 2>&1
for i in $(seq 1 60); do
    if docker exec q32-rabbit rabbitmq-diagnostics -q ping >/dev/null 2>&1; then
        echo "  broker 在 ${i}s 后就绪"
        break
    fi
    sleep 1
done
sleep 3

echo
echo "--- 4) 重启之后 ---"
rctl_queues
echo
echo "  结论："
echo "    q33.p.transient      队列本身没落盘 → 队列消失，${N} 条一起没"
echo "    q33.p.durable-queue  队列落盘了，但消息只在内存 → 队列还在，消息归零"
echo "    q33.p.both           队列 + 消息都落盘 → 队列和 ${N} 条消息都在"

echo
echo "--- 清理 ---"
for q in q33.p.transient q33.p.durable-queue q33.p.both; do
    $RCTL delete_queue "$q" >/dev/null 2>&1
done
$RCTL delete_exchange q33.persist.ex >/dev/null 2>&1
echo done
