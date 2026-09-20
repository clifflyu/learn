<?php
/**
 * Q33/Q34 生产者：发 N 条持久化消息并等待 confirm
 * 用法：docker exec learn-php php /app/bench/mq/q34-producer.php <queue> <n> <base>
 */
declare(strict_types=1);

const RABBIT = ['host' => 'q32-rabbit', 'port' => 5672, 'login' => 'guest', 'password' => 'guest', 'vhost' => '/'];
const EX = 'q33.orders.ex';

$qname = $argv[1] ?? 'q33.orders';
$n     = (int) ($argv[2] ?? 20);
$base  = (int) ($argv[3] ?? 0);

$c = new AMQPConnection(RABBIT);
$c->connect();
$ch = new AMQPChannel($c);

// 队列持久化
$q = new AMQPQueue($ch);
$q->setName($qname);
$q->setFlags(AMQP_DURABLE);
$q->declareQueue();

$e = new AMQPExchange($ch);
$e->setName(EX);
$e->setType(AMQP_EX_TYPE_DIRECT);
$e->setFlags(AMQP_DURABLE);
$e->declareExchange();
$q->bind(EX, $qname);

$ch->confirmSelect();
$acked = $nack = 0;
$last  = 0;
$ch->setConfirmCallback(
    function ($t, $m, $qq = null) use (&$acked, &$last) { $acked += $m ? ($t - $last) : 1; $last = $t; return true; },
    function ($t, $m, $qq = null) use (&$nack, &$last) { $nack += $m ? ($t - $last) : 1; $last = $t; return true; }
);

for ($i = 0; $i < $n; $i++) {
    $body = json_encode([
        'msg_no'   => (string) ($base + $i),
        'order_no' => 'ORD-' . str_pad((string) ($base + $i), 6, '0', STR_PAD_LEFT),
        'amount'   => 100.00,
    ], JSON_UNESCAPED_UNICODE);
    // AMQP_DELIVERY_MODE_PERSISTENT = 2
    $e->publish($body, $qname, AMQP_MANDATORY, ['delivery_mode' => 2]);
}
// 排空 confirm
$deadline = microtime(true) + 10;
while (microtime(true) < $deadline) {
    try {
        if (!$ch->waitForConfirm(0.3)) {
            break;
        }
    } catch (Throwable $ex) {
        break;
    }
}
printf("produced %d messages to %s (ack=%d nack=%d, persistent)\n", $n, $qname, $acked, $nack);
$c->disconnect();
