<?php
/**
 * Q33 / Q34 消费端：手动 ack、崩溃重投、至少一次投递导致的重复消费
 *
 * 用法：docker exec learn-php php /app/bench/mq/q34-consumer.php <mode> <queue> <table> [tag]
 *   mode = hold    取走消息但不 ack，挂住（外部用 rabbitmqctl 观察 ready/unacked）
 *   mode = crash   处理完消息后 SIGKILL 自己（模拟消费端崩溃，消息未 ack）
 *   mode = ack     处理完逐条 ack（正确姿势）
 *   mode = idle    只声明队列，不消费
 *
 * table 决定是否有幂等防护：
 *   t_mq_order      —— 无唯一约束，重复消费就产生重复数据
 *   t_mq_order_idem —— order_no 唯一，重复消费被 1062 挡回
 */
declare(strict_types=1);

const RABBIT = ['host' => 'q32-rabbit', 'port' => 5672, 'login' => 'guest', 'password' => 'guest', 'vhost' => '/'];
const DSN    = 'mysql:host=learn-mysql;port=3306;dbname=q37_idem;charset=utf8mb4';

$mode  = $argv[1] ?? 'ack';
$qname = $argv[2] ?? 'q33.orders';
$table = $argv[3] ?? 't_mq_order';
$tag   = $argv[4] ?? 'c1';

$pdo = new PDO(DSN, 'root', 'root', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$c = new AMQPConnection(RABBIT);
$c->connect();
$ch = new AMQPChannel($c);
$q  = new AMQPQueue($ch);
$q->setName($qname);
$q->setFlags(AMQP_DURABLE);
$q->declareQueue();

function log_line(string $s): void
{
    fwrite(STDERR, sprintf("[%s] %s\n", date('H:i:s'), $s));
}

if ($mode === 'idle') {
    echo "queue {$qname} declared\n";
    exit(0);
}

// 一次最多取 20 条，避免无限循环
$got = 0;
$dup = 0;
$ins = 0;
while ($got < 20) {
    $msg = $q->get(AMQP_NOPARAM);      // 注意：不带 AUTOACK
    if ($msg === null || $msg === false) {
        break;
    }
    $got++;
    $body = json_decode($msg->getBody(), true);

    try {
        $st = $pdo->prepare(
            "INSERT INTO {$table} (msg_no,order_no,amount,consumer,created_at) VALUES (?,?,?,?,NOW(3))"
        );
        $st->execute([$body['msg_no'], $body['order_no'], $body['amount'], $tag]);
        $ins++;
        log_line("消费 msg_no={$body['msg_no']} order_no={$body['order_no']} → 落库成功");
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            $dup++;
            log_line("消费 msg_no={$body['msg_no']} order_no={$body['order_no']} → 1062 已存在，跳过");
        } else {
            throw $e;
        }
    }

    if ($mode === 'hold') {
        if ($got === 1) {
            log_line("已取 {$got} 条，不 ack，挂 6 秒供外部观察");
            sleep(6);
        }
        // hold 模式不 ack，也不继续取
        break;
    }

    if ($mode === 'ack') {
        $q->ack($msg->getDeliveryTag());
    }
}

if ($mode === 'hold') {
    log_line("hold 结束，模拟消费者崩溃（SIGKILL），未 ack 的消息由 broker 重新入队");
    posix_kill(getmypid(), SIGKILL);
    exit(0);
}

if ($mode === 'crash') {
    log_line("处理了 {$got} 条（落库 {$ins}，重复 {$dup}），ack 之前 SIGKILL 自己");
    posix_kill(getmypid(), SIGKILL);
    exit(0);
}

log_line("done: 取 {$got} 条，落库 {$ins}，重复挡回 {$dup}");
$c->disconnect();
