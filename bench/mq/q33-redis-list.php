<?php
/**
 * Q33 消费端（Redis 版）：List 当队列时，消费者崩溃会丢消息
 *
 *   场景 A  BLPOP 直接消费           —— 取走即删除，崩溃 = 永久丢失
 *   场景 B  BRPOPLPUSH 可靠队列       —— 先挪到 processing list，处理成功再删，崩溃可恢复
 *   场景 C  LREM 的代价随 processing list 长度增长（O(N)，不是 O(1)）
 *
 * 用法：docker exec learn-php php /app/bench/mq/q33-redis-list.php
 */
declare(strict_types=1);

const REDIS_HOST = 'learn-redis';
const REDIS_PORT = 6379;
const P = 'q33r:';          // key 前缀，跑完清理

$r = new Redis();
$r->connect(REDIS_HOST, REDIS_PORT, 2.0);
$r->select(0);

function cleanup(Redis $r): void
{
    foreach ($r->keys(P . '*') as $k) {
        $r->del($k);
    }
}

const TOTAL = 1000;   // 业务总量
const CRASH_AT = 500; // 消费者处理到第 500 条时崩溃

cleanup($r);

// ============================================================ 场景 A
echo "=== 场景 A：BLPOP 直接消费，消费者崩溃 ===\n";
$Q    = P . 'queueA';
$DONE = P . 'doneA';

// ---- A1：逐条消费（取一条处理一条），崩在最后一条的处理中途 ----
for ($i = 0; $i < TOTAL; $i++) {
    $r->lPush($Q, "order:{$i}");
}
printf("  A1 逐条消费：投递 %d 条，队列长度 = %d\n", TOTAL, $r->lLen($Q));
$processed = 0;
for ($i = 0; $i < CRASH_AT; $i++) {
    $msg = $r->blPop([$Q], 1);
    if ($msg === null || $msg === false) {
        break;
    }
    if ($i === CRASH_AT - 1) {
        break;                       // 取出来了，没处理完就崩
    }
    $r->incr($DONE);
    $processed++;
}
printf("     取走 %d 条，处理完成 %d 条，队列剩 %d 条 → 丢失 %d 条\n",
    CRASH_AT, $processed, $r->lLen($Q), CRASH_AT - $processed);

// ---- A2：批量取（LRANGE + LTRIM，常见的「一次捞一批」写法），崩在批处理中途 ----
$r->del($Q, $DONE);
$DONE_BATCH = P . 'doneAbatch';
$r->del($DONE_BATCH);
for ($i = 0; $i < TOTAL; $i++) {
    $r->lPush($Q, "order:{$i}");
}
printf("  A2 批量取：投递 %d 条，队列长度 = %d\n", TOTAL, $r->lLen($Q));
$batch = 500;
$lost  = 0;
for ($round = 0; $round < 2; $round++) {
    // 一批捞出来：LRANGE 读 + LTRIM 截断，用 MULTI 保证原子
    $r->multi();
    $r->lRange($Q, 0, $batch - 1);
    $r->lTrim($Q, $batch, -1);
    $res  = $r->exec();
    $msgs = $res[0] ?? [];
    if ($round === 1) {
        // 这一批拿到手，处理了 3 条就崩了
        foreach (array_slice($msgs, 0, 3) as $m) {
            $r->incr($DONE_BATCH);
        }
        $lost = count($msgs) - 3;
        break;
    }
    foreach ($msgs as $m) {
        $r->incr($DONE_BATCH);
    }
}
printf("     第一批处理完；第二批 %d 条拿到手，只处理了 3 条就崩溃\n", $batch);
printf("     队列剩 %d 条 → 丢失 %d 条（已在内存里，Redis 里也没有了）\n", $r->lLen($Q), $lost);
echo "  → List 的 BLPOP/LTRIM 是「取走即删除」，broker 里没有 unacked 状态，没有重投机会\n";
echo "  → 批量越大，单次崩溃丢得越多：A1 丢 1 条，A2 丢 " . $lost . " 条\n\n";

// ============================================================ 场景 B
echo "=== 场景 B：BRPOPLPUSH 可靠队列，同样的崩溃点 ===\n";
$Q2  = P . 'queueB';
$BK  = P . 'processingB';
$DONE2 = P . 'doneB';
for ($i = 0; $i < TOTAL; $i++) {
    $r->lPush($Q2, "order:{$i}");
}
printf("  投递 %d 条，队列长度 = %d\n", TOTAL, $r->lLen($Q2));

// --- 第一轮消费：崩在同样的位置 ---
$processed = 0;
for ($i = 0; $i < CRASH_AT; $i++) {
    // 原子地把消息从 Q2 挪到 BK，两边都不丢
    $msg = $r->brPopLPush($Q2, $BK, 1);
    if ($msg === null || $msg === false) {
        break;
    }
    if ($i === CRASH_AT - 1) {
        break;                       // 同样：拿在手里没处理完就崩
    }
    $r->incr($DONE2);
    $r->lRem($BK, $msg, 1);          // 处理成功才从备份 list 删掉
    $processed++;
}
printf("  崩溃瞬间：主队列 %d 条，processing list %d 条，已完成 %d 条\n",
    $r->lLen($Q2), $r->lLen($BK), $processed);

// --- 恢复程序：把 processing list 里的消息搬回主队列 ---
$recovered = 0;
while (($m = $r->rPopLPush($BK, $Q2)) !== null && $m !== false) {
    $recovered++;
}
printf("  恢复程序把 %d 条从 processing list 搬回主队列\n", $recovered);

// --- 第二轮消费：把剩下的全部处理完 ---
while (($msg = $r->brPopLPush($Q2, $BK, 1)) !== null && $msg !== false) {
    $r->incr($DONE2);
    $r->lRem($BK, $msg, 1);
}
printf("  最终处理完成 = %d / %d，丢失 = %d 条\n",
    (int) $r->get($DONE2), TOTAL, TOTAL - (int) $r->get($DONE2));
printf("  主队列 %d 条，processing list %d 条\n", $r->lLen($Q2), $r->lLen($BK));
echo "  → 代价：每条消息多一次 Redis 往返 + processing list 的内存；换来崩溃后的可恢复\n\n";

// ============================================================ 场景 C
echo "=== 场景 C：LREM 的代价随 processing list 长度增长 ===\n";
echo "  （BRPOPLPUSH 把消息塞到 processing list 的头部，LREM 从头部扫描；\n";
echo "    这里把目标放在尾部，测最坏情况）\n";
$LK = P . 'lremcost';
foreach ([100, 1000, 10000, 100000] as $n) {
    $r->del($LK);
    $vals = [];
    for ($i = 0; $i < $n; $i++) {
        $vals[] = "x:{$i}";
    }
    $r->rPush($LK, ...$vals);        // 目标 x:0 在头部，x:{n-1} 在尾部
    $lat = [];
    $reps = 30;
    for ($i = 0; $i < $reps; $i++) {
        $t0 = microtime(true);
        $r->lRem($LK, "x:" . ($n - 1), 1);   // 尾部元素：要扫过整个 list
        $lat[] = (microtime(true) - $t0) * 1e6;
        $r->rPush($LK, "x:" . ($n - 1));     // 放回去
    }
    sort($lat);
    printf("  list 长度 %-7d → LREM 尾部元素 p50 = %.1f us\n", $n, $lat[(int) (0.5 * ($reps - 1))]);
}
$r->del($LK);

cleanup($r);
$r->close();
