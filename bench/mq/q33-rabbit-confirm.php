<?php
/**
 * Q33 生产端：publisher confirm 到底确认了什么？
 *
 * 场景：exchange 存在，但 routing key 不匹配任何队列。
 *   1) 裸 publish                        —— 生产者端毫无察觉
 *   2) confirm（批量等待）                —— 全部 ack，队列仍是 0
 *   3) confirm + mandatory + return      —— 才能在生产者端发现路由失败
 *   4) 对照组：rk 匹配时                  —— 真的进队列
 *   5) publish 到不存在的 exchange        —— 404，channel 挂掉
 *   6) confirm 逐条等待 vs 批量等待的吞吐差
 *
 * 用法：docker exec learn-php php /app/bench/mq/q33-rabbit-confirm.php
 */
declare(strict_types=1);

const RABBIT = ['host' => 'q32-rabbit', 'port' => 5672, 'login' => 'guest', 'password' => 'guest', 'vhost' => '/'];
const N      = 1000;
const EX     = 'q33.ex';
const Q      = 'q33.audit';
const RK_OK  = 'rk-ok';
const RK_MISS = 'rk-nobody-listens';

function conn(): AMQPConnection
{
    $c = new AMQPConnection(RABBIT);
    $c->connect();
    return $c;
}

/** 声明 exchange + 一个只绑定 RK_OK 的审计队列，并清空 */
function setup(AMQPConnection $c): void
{
    $ch = new AMQPChannel($c);
    $e = new AMQPExchange($ch);
    $e->setName(EX);
    $e->setType(AMQP_EX_TYPE_DIRECT);
    $e->setFlags(AMQP_DURABLE);
    $e->declareExchange();

    $q = new AMQPQueue($ch);
    $q->setName(Q);
    $q->setFlags(AMQP_DURABLE);
    $q->declareQueue();
    $q->bind(EX, RK_OK);

    // 坑：php-amqp 2.2.1dev 的 get() 在空队列上返回 NULL 而不是 false
    $guard = 0;
    while (($m = $q->get(AMQP_AUTOACK)) !== null && $m !== false) {
        if (++$guard > 100000) {
            break;
        }
    }
}


/** waitForConfirm 超时会抛 AMQPQueueException（不是返回 false），必须包住 */
function drainConfirms(AMQPChannel $ch, float $timeout = 0.05): void
{
    $deadline = microtime(true) + 30.0;
    while (microtime(true) < $deadline) {
        try {
            if (!$ch->waitForConfirm($timeout)) {
                return;
            }
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'timeout')) {
                return;
            }
            throw $e;
        }
    }
}


/**
 * confirm 回调里 multiple=true 表示「到 tag 为止的全部都确认了」，
 * 直接 +1 会少算。要按 deliveryTag 增量累计。
 */
function makeConfirmCounter(int &$acked, int &$nacked): array
{
    $state = ['last' => 0];
    $ack = function ($tag, $multiple, $queue = null) use (&$acked, &$state) {
        $acked += $multiple ? ($tag - $state['last']) : 1;
        $state['last'] = $tag;
        return true;
    };
    $nack = function ($tag, $multiple, $queue = null) use (&$nacked, &$state) {
        $nacked += $multiple ? ($tag - $state['last']) : 1;
        $state['last'] = $tag;
        return true;
    };
    return [$ack, $nack];
}

/** 等到「至少一条」confirm 回来就返回；超时抛异常，吞掉即可 */
function waitOne(AMQPChannel $ch, float $timeout = 2.0): void
{
    try {
        $ch->waitForConfirm($timeout);
    } catch (Throwable $e) {
        // 超时
    }
}

function depth(AMQPConnection $c): int
{
    $ch = new AMQPChannel($c);
    $q  = new AMQPQueue($ch);
    $q->setName(Q);
    $q->setFlags(AMQP_PASSIVE);
    return $q->declareQueue();
}

$c = conn();
setup($c);

// ------------------------------------------------------ 1) 裸 publish，rk 不匹配
echo "=== 1) 裸 publish（无 confirm、无 mandatory），rk=" . RK_MISS . " ===\n";
$ch = new AMQPChannel($c);
$e  = new AMQPExchange($ch);
$e->setName(EX);
$d0 = depth($c);
$t0 = microtime(true);
for ($i = 0; $i < N; $i++) {
    $e->publish("msg-{$i}", RK_MISS);
}
$dt = microtime(true) - $t0;
printf("  生产者：publish %d 条，无任何异常，耗时 %.3f s（%.0f 条/秒）\n", N, $dt, N / $dt);
printf("  审计队列深度：%d\n", depth($c));
echo "  → 生产者以为发送成功，broker 全部丢弃，两边都不知道\n\n";
$ch->close();

// ------------------------------------------------------ 2) confirm（批量等待）
echo "=== 2) publisher confirm（confirmSelect + setConfirmCallback，批量等待），rk=" . RK_MISS . " ===\n";
$ch = new AMQPChannel($c);
$ch->confirmSelect();
$acked = $nacked = 0;
$ch->setConfirmCallback(...makeConfirmCounter($acked, $nacked));
$e = new AMQPExchange($ch);
$e->setName(EX);
$d0 = depth($c);
$t0 = microtime(true);
for ($i = 0; $i < N; $i++) {
    $e->publish("msg-{$i}", RK_MISS);
}
drainConfirms($ch);
$dt = microtime(true) - $t0;
printf("  ack = %d，nack = %d，耗时 %.3f s（%.0f 条/秒）\n", $acked, $nacked, $dt, N / $dt);
printf("  审计队列深度增量：%d\n", depth($c) - $d0);
printf("  → %d 条 ack，队列里一条都没有。confirm 的 ack ≠ 消息进了队列\n\n", $acked);
$ch->close();

// ------------------------------------------------------ 3) confirm + mandatory + return
echo "=== 3) confirm + mandatory + return callback，rk=" . RK_MISS . " ===\n";
$ch = new AMQPChannel($c);
$ch->confirmSelect();
$returned = 0;
$first    = null;
$ch->setConfirmCallback(fn($t, $m, $q = null) => true, fn($t, $m, $q = null) => true);
$ch->setReturnCallback(function ($code, $text, $ex, $rk, $props, $body) use (&$returned, &$first) {
    $returned++;
    $first ??= sprintf('code=%d text=%s exchange=%s rk=%s', $code, $text, $ex, $rk);
});
$e = new AMQPExchange($ch);
$e->setName(EX);
$t0 = microtime(true);
$tSent = microtime(true);
for ($i = 0; $i < N; $i++) {
    $e->publish("msg-{$i}", RK_MISS, AMQP_MANDATORY);
}
drainConfirms($ch);
$dt = microtime(true) - $t0;
printf("  returned = %d，耗时 %.3f s（%.0f 条/秒）\n", $returned, $dt, N / $dt);
printf("  首个 basic.return：%s\n", $first ?? '(无)');
printf("  审计队列深度增量：%d\n", depth($c) - $d0);
echo "  → 只有 mandatory + return callback 才能在生产者端发现「路由不到队列」\n\n";
$ch->close();

// ------------------------------------------------------ 4) 对照组：rk 匹配
echo "=== 4) 对照组：rk=" . RK_OK . "（有队列绑定） ===\n";
$ch = new AMQPChannel($c);
$ch->confirmSelect();
$acked = $returned = 0;
$nacked = 0;
$ch->setConfirmCallback(...makeConfirmCounter($acked, $nacked));
$ch->setReturnCallback(function () use (&$returned) { $returned++; });
$e = new AMQPExchange($ch);
$e->setName(EX);
$d0 = depth($c);
$t0 = microtime(true);
for ($i = 0; $i < N; $i++) {
    $e->publish("msg-{$i}", RK_OK, AMQP_MANDATORY);
}
drainConfirms($ch);
$dt = microtime(true) - $t0;
printf("  ack = %d，returned = %d，审计队列深度增量 = %d，耗时 %.3f s（%.0f 条/秒）\n",
    $acked, $returned, depth($c) - $d0, $dt, N / $dt);
echo "  → rk 匹配时 mandatory 不会触发 return，消息真的进了队列\n\n";
$ch->close();

// ------------------------------------------------------ 5) exchange 不存在
echo "=== 5) publish 到不存在的 exchange ===\n";
$ch = new AMQPChannel($c);
$e = new AMQPExchange($ch);
$e->setName('q33.does.not.exist');
$t0 = microtime(true);
$threw = null;
try {
    $e->publish('x', 'rk');
} catch (Throwable $t) {
    $threw = $t;
}
printf("  publish 本身：%s\n", $threw ? '抛 ' . get_class($threw) : '没抛异常，看起来成功了');
// 错误是异步的：broker 的 channel.close 要等下一次读写才被处理
try {
    $ch->close();
    echo "  channel->close()：没抛异常\n";
} catch (Throwable $t) {
    printf("  channel->close() 才抛出：%s —— %s（延迟 %.4f s 暴露）\n",
        get_class($t), $t->getMessage(), microtime(true) - $t0);
}
echo "  → 异步 publish 的错误是「延迟暴露」的：publish 返回成功不代表 broker 接受了\n";
// 404 之后这条 connection 上的 channel 全部失效，必须重连
$c = conn();
echo "  （已重建连接）\n\n";

// ------------------------------------------------------ 6) 批量大小对 confirm 吞吐的影响
echo "=== 6) confirm 批量大小对吞吐的影响（每档 2000 条，rk=" . RK_OK . "）===\n";
echo "  说明：php-amqp 2.2.1dev 下 setConfirmCallback + 逐条 waitForConfirm 会退化\n";
echo "        （每次 waitForConfirm 阻塞满超时，100 条 90 秒没跑完），这里只扫批量。\n";
$TOTAL = 2000;
foreach ([1, 10, 100, 1000, 2000] as $batch) {
    $ch = new AMQPChannel($c);
    $ch->confirmSelect();
    $acked = $nacked6 = 0;
    $ch->setConfirmCallback(...makeConfirmCounter($acked, $nacked6));
    $e = new AMQPExchange($ch);
    $e->setName(EX);
    $d0 = depth($c);
    $t0 = microtime(true);
    $sent = 0;
    while ($sent < $TOTAL) {
        $n = min($batch, $TOTAL - $sent);
        for ($i = 0; $i < $n; $i++) {
            $e->publish("b{$batch}-" . ($sent + $i), RK_OK);
        }
        drainConfirms($ch);
        $sent += $n;
    }
    $dt = microtime(true) - $t0;
    printf("  批量=%-5d  %.3f s  %6.0f 条/秒  ack=%d  队列增量=%d\n",
        $batch, $dt, $TOTAL / $dt, $acked, depth($c) - $d0);
    $ch->close();
}

$c->disconnect();
