<?php
/**
 * Q32 为什么要用消息队列：三个收益里可以量的部分
 *
 *   A 同步耦合：下单接口里同步调用 3 个下游（各 50ms）→ 接口 RT
 *   B 异步解耦：下单接口只发 3 条 MQ 消息 → 接口 RT
 *   C 削峰填谷：瞬时涌入 3000 条，消费者只有 1000 条/秒 的能力
 *
 * 用法：docker exec learn-php php /app/bench/mq/q32-sync-vs-async.php
 */
declare(strict_types=1);

const RABBIT = ['host' => 'q32-rabbit', 'port' => 5672, 'login' => 'guest', 'password' => 'guest', 'vhost' => '/'];
const EX = 'q32.ex';
const Q  = 'q32.orders';
const DOWNSTREAM_MS = 50;
const REQS = 200;

function conn(): AMQPConnection
{
    $c = new AMQPConnection(RABBIT);
    $c->connect();
    return $c;
}

function makeQueue(AMQPConnection $c): AMQPQueue
{
    $ch = new AMQPChannel($c);
    $q = new AMQPQueue($ch);
    $q->setName(Q);
    $q->setFlags(AMQP_DURABLE);
    $q->declareQueue();
    $e = new AMQPExchange($ch);
    $e->setName(EX);
    $e->setType(AMQP_EX_TYPE_DIRECT);
    $e->setFlags(AMQP_DURABLE);
    $e->declareExchange();
    $q->bind(EX, Q);
    return $q;
}

/** 清空队列（AUTOACK，否则会留下 unacked） */
function purgeQueue(AMQPConnection $c): int
{
    $ch = new AMQPChannel($c);
    $q = new AMQPQueue($ch);
    $q->setName(Q);
    $q->setFlags(AMQP_PASSIVE);
    $n = 0;
    $guard = 0;
    while (($m = $q->get(AMQP_AUTOACK)) !== null && $m !== false) {
        $n++;
        if (++$guard > 200000) {
            break;
        }
    }
    return $n;
}

function downstream(int $ms = DOWNSTREAM_MS): void
{
    usleep($ms * 1000);          // 模拟一次 RPC / DB 写入
}

function stats(array $lat): string
{
    sort($lat);
    $p = fn(float $q) => $lat[(int) floor($q * (count($lat) - 1))];
    return sprintf("p50=%.1f ms  p95=%.1f ms  max=%.1f ms", $p(0.50), $p(0.95), $p(0.99));
}

$c = conn();
makeQueue($c);
purgeQueue($c);
$asyncAvg = 0;

// ============================================================ A 同步
echo "=== A 同步耦合：一个下单请求里同步做 3 件事（各 " . DOWNSTREAM_MS . " ms）===\n";
$lat = [];
for ($i = 0; $i < REQS; $i++) {
    $t0 = microtime(true);
    downstream();
    downstream();
    downstream();
    $lat[] = (microtime(true) - $t0) * 1000;
}
echo "  接口 RT：" . stats($lat) . "\n";
$syncAvg = array_sum($lat) / count($lat);
printf("  平均 %.1f ms ≈ 3 个下游耗时相加（%d ms）\n", $syncAvg, 3 * DOWNSTREAM_MS);
echo "  可用性：3 个下游串行依赖，任一挂掉这个请求就失败\n\n";

// ============================================================ B 异步
echo "=== B 异步解耦：下单请求只发 3 条消息，不等下游 ===\n";
$ch = new AMQPChannel($c);
$e = new AMQPExchange($ch);
$e->setName(EX);

// B1：只 publish，不等 confirm（接口侧最常见的写法，confirm 交给后台异步处理）
$lat = [];
for ($i = 0; $i < REQS; $i++) {
    $t0 = microtime(true);
    foreach (['sms', 'points', 'dw'] as $topic) {
        $e->publish(json_encode(['order' => $i, 'topic' => $topic]), Q, AMQP_MANDATORY, ['delivery_mode' => 2]);
    }
    $lat[] = (microtime(true) - $t0) * 1000;
}
echo "  B1 只发不等确认： " . stats($lat) . "\n";
$asyncAvg = array_sum($lat) / count($lat);
printf("     平均 %.2f ms，是同步的 %.1f%%（快 %.0fx）\n", $asyncAvg, 100 * $asyncAvg / $syncAvg, $syncAvg / $asyncAvg);

// B2：publish 后同步等 confirm，接口 RT 里就多了一次 broker 往返
$ch->confirmSelect();
$acked = 0;
$last  = 0;
$ch->setConfirmCallback(
    function ($t, $m, $qq = null) use (&$acked, &$last) { $acked += $m ? ($t - $last) : 1; $last = $t; return true; },
    function ($t, $m, $qq = null) { return true; }
);
$lat = [];
for ($i = 0; $i < REQS; $i++) {
    $t0 = microtime(true);
    foreach (['sms', 'points', 'dw'] as $topic) {
        $e->publish(json_encode(['order' => $i, 'topic' => $topic]), Q, AMQP_MANDATORY, ['delivery_mode' => 2]);
    }
    $deadline = microtime(true) + 2;
    while ($acked < ($i + 1) * 3 && microtime(true) < $deadline) {
        try {
            $ch->waitForConfirm(0.05);
        } catch (Throwable $ex) {
            break;
        }
    }
    $lat[] = (microtime(true) - $t0) * 1000;
}
echo "  B2 发完同步等确认：" . stats($lat) . "\n";
$async2Avg = array_sum($lat) / count($lat);
printf("     平均 %.2f ms，是同步的 %.1f%%\n", $async2Avg, 100 * $async2Avg / $syncAvg);
echo "  ↑ 可靠性不是免费的：等到 confirm 才算「发成功」，接口 RT 里就多了一次 broker 往返\n";
echo "  可用性：只要 broker 活着就能接单，下游挂了消息堆在队列里，不影响下单\n\n";
$ch->close();

// ============================================================ C 削峰
echo "=== C 削峰填谷 ===\n";
$pending = purgeQueue($c);
printf("  队列里已有 %d 条待消费的消息（B 阶段发的）\n", $pending);

// 瞬时涌入：生产者尽可能快地灌 3000 条
$ch = new AMQPChannel($c);
$ch->confirmSelect();
$acked = 0;
$last  = 0;
$ch->setConfirmCallback(
    function ($t, $m, $qq = null) use (&$acked, &$last) { $acked += $m ? ($t - $last) : 1; $last = $t; return true; },
    function ($t, $m, $qq = null) { return true; }
);
$e = new AMQPExchange($ch);
$e->setName(EX);
$BURST = 3000;
$t0 = microtime(true);
for ($i = 0; $i < $BURST; $i++) {
    $e->publish(json_encode(['burst' => $i]), Q, AMQP_NOPARAM, ['delivery_mode' => 2]);
}
$deadline = microtime(true) + 20;
while ($acked < $BURST && microtime(true) < $deadline) {
    try {
        if (!$ch->waitForConfirm(0.2)) {
            break;
        }
    } catch (Throwable $ex) {
        break;
    }
}
$burstDt = microtime(true) - $t0;
printf("  瞬时涌入 %d 条：生产端耗时 %.3f s（%.0f 条/秒），接口侧全部立即返回\n", $BURST, $burstDt, $BURST / $burstDt);
printf("  此刻队列积压 = %d 条（这就是「峰」被存下来的地方）\n", $ch->getConnection() ? (function () use ($c) {
    $c2 = conn();
    $ch2 = new AMQPChannel($c2);
    $q2 = new AMQPQueue($ch2);
    $q2->setName(Q);
    $q2->setFlags(AMQP_PASSIVE);
    $n = $q2->declareQueue();
    $c2->disconnect();
    return $n;
})() : 0);
$ch->close();

// 消费者能力有限：每条 1ms
$ch = new AMQPChannel($c);
$q  = new AMQPQueue($ch);
$q->setName(Q);
$q->setFlags(AMQP_PASSIVE);
$PER_MSG_MS = 1;
$t0 = microtime(true);
$done = 0;
$guard = 0;
while (($m = $q->get(AMQP_NOPARAM)) !== null && $m !== false) {
    usleep($PER_MSG_MS * 1000);
    $q->ack($m->getDeliveryTag());
    $done++;
    if (++$guard > 200000) {
        break;
    }
}
$consumeDt = microtime(true) - $t0;
printf("  消费者只有 %d ms/条 的处理能力：消化 %d 条用了 %.3f s（%.0f 条/秒）\n",
    $PER_MSG_MS, $done, $consumeDt, $done / $consumeDt);
printf("  涌入 %.0f 条/秒 vs 消化 %.0f 条/秒 → 峰值的 %.0f 倍被队列吸收，请求没有被丢弃也没有把下游打挂\n",
    $BURST / $burstDt, $done / $consumeDt, ($BURST / $burstDt) / ($done / $consumeDt));
echo "  → 代价是「最终一致」：下单立刻返回成功，短信/积分在 " . round($consumeDt, 1) . " 秒内陆续完成\n";

purgeQueue($c);
$c->disconnect();
