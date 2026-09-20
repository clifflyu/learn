<?php
/**
 * Q35 消息积压 + 顺序性
 *
 *   1) 灌 10 万条进 Redis List：内存占用、生产吞吐、encoding 变化
 *   2) 多消费者（4 进程）消费：耗时 + 乱序率
 *   3) 单消费者消费：耗时 + 乱序率
 *   4) 生产速率 vs 消费速率 → 积压是怎么涨起来的
 *
 * 用法：docker exec learn-php php /app/bench/mq/q35-backlog.php
 */
declare(strict_types=1);

const RH = 'learn-redis';
const RP = 6379;
const P  = 'q35:';
const N  = 100000;

function rds(): Redis
{
    $r = new Redis();
    $r->connect(RH, RP, 3.0);
    return $r;
}

function cleanup(Redis $r): void
{
    foreach ($r->keys(P . '*') as $k) {
        $r->del($k);
    }
}

$r = rds();
cleanup($r);

// ============================================================ 1) 灌 10 万条
echo "=== 1) 灌 " . N . " 条进 Redis List ===\n";
$Q = P . 'queue';

$mem0 = (int) $r->info('memory')['used_memory'];
$t0 = microtime(true);
$chunk = [];
for ($i = 0; $i < N; $i++) {
    $chunk[] = json_encode(['seq' => $i, 'order_no' => 'ORD' . $i, 'amount' => 99.5]);
    if (count($chunk) === 500) {              // pipeline 批 500 条
        $r->multi(Redis::PIPELINE);
        foreach ($chunk as $c) {
            $r->rPush($Q, $c);
        }
        $r->exec();
        $chunk = [];
    }
}
if ($chunk) {
    $r->multi(Redis::PIPELINE);
    foreach ($chunk as $c) {
        $r->rPush($Q, $c);
    }
    $r->exec();
}
$dt  = microtime(true) - $t0;
$mem1 = (int) $r->info('memory')['used_memory'];
$len  = $r->lLen($Q);

printf("  队列长度 = %d\n", $len);
$prodRate = N / $dt;
printf("  生产耗时 = %.3f s（%.0f 条/秒，pipeline 批 500）\n", $dt, $prodRate);
printf("  used_memory 增量 = %d B（%.1f MB）\n", $mem1 - $mem0, ($mem1 - $mem0) / 1048576);
printf("  单条均摊 = %.1f B（含 List 节点指针 / quicklist 结构开销）\n", ($mem1 - $mem0) / $len);
printf("  样本 JSON 长度 = %d B → 结构开销占 %.0f%%\n",
    strlen(json_encode(['seq' => 0, 'order_no' => 'ORD0', 'amount' => 99.5])),
    100 * (1 - strlen(json_encode(['seq' => 0, 'order_no' => 'ORD0', 'amount' => 99.5])) / (($mem1 - $mem0) / $len)));
printf("  OBJECT ENCODING = %s\n", $r->rawCommand('object', 'encoding', $Q));
printf("  MEMORY USAGE key = %s B\n", $r->rawCommand('memory', 'usage', $Q));

// 逐条 push 的吞吐对照（用另一个 key，避免污染）
$Q2 = P . 'queue-single';
$t0 = microtime(true);
for ($i = 0; $i < 20000; $i++) {
    $r->rPush($Q2, 'x');
}
$dt2 = microtime(true) - $t0;
printf("  对照：逐条 RPUSH 20000 条耗时 %.3f s（%.0f 条/秒）\n\n", $dt2, 20000 / $dt2);
$r->del($Q2);

// ============================================================ 2) 4 消费者
echo "=== 2) 4 个消费者并发消费 " . N . " 条：耗时 + 乱序率 ===\n";
$ORDER = P . 'order-multi';
$r->del($ORDER);
$t0 = microtime(true);
$pids = [];
for ($w = 0; $w < 4; $w++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $rc = rds();
        // 注意：phpredis 的 lPop 在 key 为空时返回 false，不是 null
        while (($msg = $rc->lPop($Q)) !== false && $msg !== null) {
            $d = json_decode($msg, true);
            $rc->rPush($ORDER, (string) $d['seq']);   // 记录「实际处理完成顺序」
        }
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $st);
}
$dt = microtime(true) - $t0;
printf("  4 消费者耗时 = %.3f s（%.0f 条/秒）\n", $dt, N / $dt);
printf("  Redis info：connected_clients 已回落，队列剩余 %d\n", $r->lLen($Q));

$seqs = array_map('intval', $r->lRange($ORDER, 0, -1));
$bad  = 0;
$badAt = [];
for ($i = 1; $i < count($seqs); $i++) {
    if ($seqs[$i] < $seqs[$i - 1]) {
        $bad++;
        if (count($badAt) < 5) {
            $badAt[] = sprintf('#%d: %d→%d', $i, $seqs[$i - 1], $seqs[$i]);
        }
    }
}
printf("  记录到的处理顺序 %d 条，相邻逆序对 = %d → 乱序率 %.2f%%\n",
    count($seqs), $bad, 100 * $bad / max(1, count($seqs) - 1));
printf("  前 20 条的处理顺序：%s\n", implode(',', array_slice($seqs, 0, 20)));
printf("  前 5 个逆序位置：%s\n", implode('  ', $badAt));
printf("  ↑ 多消费者抢同一个 List，谁先抢到谁先处理，顺序完全不可控\n\n");

// ============================================================ 3) 单消费者
echo "=== 3) 单消费者消费 " . N . " 条：耗时 + 乱序率 ===\n";
$r->del($Q, $ORDER);
$chunk = [];
for ($i = 0; $i < N; $i++) {
    $chunk[] = json_encode(['seq' => $i, 'order_no' => 'ORD' . $i, 'amount' => 99.5]);
    if (count($chunk) === 500) {
        $r->multi(Redis::PIPELINE);
        foreach ($chunk as $c) {
            $r->rPush($Q, $c);
        }
        $r->exec();
        $chunk = [];
    }
}
$t0 = microtime(true);
while (($msg = $r->lPop($Q)) !== false && $msg !== null) {
    $d = json_decode($msg, true);
    $r->rPush($ORDER, (string) $d['seq']);
}
$dt = microtime(true) - $t0;
$consumeRate = N / $dt;
$seqs = array_map('intval', $r->lRange($ORDER, 0, -1));
$bad = 0;
for ($i = 1; $i < count($seqs); $i++) {
    if ($seqs[$i] < $seqs[$i - 1]) {
        $bad++;
    }
}
printf("  单消费者耗时 = %.3f s（%.0f 条/秒）\n", $dt, $consumeRate);
printf("  相邻逆序对 = %d → 乱序率 %.2f%%（严格 FIFO）\n\n", $bad, 100 * $bad / max(1, count($seqs) - 1));

// ============================================================ 4) 速率对比
echo "=== 4) 生产速率 vs 消费速率 ===\n";
printf("  生产（pipeline 批 500）： %8.0f 条/秒\n", $prodRate);
printf("  消费（单消费者逐条）：   %8.0f 条/秒\n", $consumeRate);
printf("  消费/生产 = %.3f\n", $consumeRate / $prodRate);
echo "  消费端慢的原因：生产走 pipeline 一次往返发 500 条，消费是「取一条-处理一条」的同步往返。\n";
echo "  两者的比值就是积压增长的速度：比值 < 1 时积压持续上涨，只能靠加消费者或批量化消费追平。\n";

cleanup($r);
$r->close();
