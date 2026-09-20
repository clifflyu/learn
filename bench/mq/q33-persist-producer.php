<?php
/**
 * Q33 持久化三件套：队列持久化 / 消息持久化 各管什么
 *
 * 用法：docker exec learn-php php /app/bench/mq/q33-persist-producer.php <queue> <n> <durable:0|1> <persistent:0|1>
 */
declare(strict_types=1);

const RABBIT = ['host' => 'q32-rabbit', 'port' => 5672, 'login' => 'guest', 'password' => 'guest', 'vhost' => '/'];
const EX = 'q33.persist.ex';

$qname      = $argv[1] ?? 'q33.p.both';
$n          = (int) ($argv[2] ?? 1000);
$durable    = (bool) (int) ($argv[3] ?? 1);
$persistent = (bool) (int) ($argv[4] ?? 1);

$c = new AMQPConnection(RABBIT);
$c->connect();
$ch = new AMQPChannel($c);

$q = new AMQPQueue($ch);
$q->setName($qname);
if ($durable) {
    $q->setFlags(AMQP_DURABLE);
}
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

$props = $persistent ? ['delivery_mode' => 2] : [];
for ($i = 0; $i < $n; $i++) {
    $e->publish(json_encode(['i' => $i, 'q' => $qname]), $qname, AMQP_MANDATORY, $props);
}
$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
    try {
        if (!$ch->waitForConfirm(0.3)) {
            break;
        }
    } catch (Throwable $ex) {
        break;
    }
}
printf("  %-24s queue_durable=%d message_persistent=%d → ack=%d nack=%d\n",
    $qname, $durable, $persistent, $acked, $nack);
$c->disconnect();
