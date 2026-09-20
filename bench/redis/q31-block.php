<?php
// Q31 「删一个大 Key 会把别人堵多久」—— 客户端视角的延迟尖峰
//
// 用法: docker exec learn-php php /app/bench/redis/q31-block.php [元素数]
//
// 为什么不用 shell 里 `date +%s%N; redis-cli DEL; date`：
//   redis-cli 每次都要 fork+exec+连接，量出来恒等于 4~5ms 的进程启动开销，
//   服务端真正花了多久完全被盖住。这里换成：
//     · 操作耗时     → 由操作进程自己的持久连接测（不含进程启动）
//     · 服务端耗时   → 由 q31-delcost.php 读 SLOWLOG
//     · 旁人的延迟尖峰 → 一个独立进程用持久连接做紧循环 PING，按 20ms 分桶记最大值
//   三条线互相印证，不是同一个数换个说法。
//
// victim 用 hash 而不是 list：同样的元素数，quicklist 把元素打包进 listpack，
// 删 500k 元素的 list 只要 72 微秒，根本堵不住人；hashtable 每元素一个节点，
// 删 500k 字段的 hash 要 145 毫秒。要演示「大 Key 卡死实例」，得用后者。

require __DIR__ . '/_conn.php';

$P      = 'q31:';
$N      = (int)($argv[1] ?? 500000);
$BUCKET = 20;      // ms
$RUN    = 1600;    // 探测窗口总长
$ACT    = 700;     // 在第 700ms 时发起 DEL / UNLINK

function pipe_cmds(Redis $r, array $cmds): void {
    $r->multi(Redis::PIPELINE);
    foreach ($cmds as $c) $r->rawCommand(...$c);
    $r->exec();
}
function build_hash(Redis $r, string $key, int $n): void {
    $r->del($key);
    $chunk = [];
    for ($i = 0; $i < $n; $i++) {
        $chunk[] = ['HSET', $key, "f$i", "v$i"];
        if (count($chunk) >= 5000) { pipe_cmds($r, $chunk); $chunk = []; }
    }
    if ($chunk) pipe_cmds($r, $chunk);
}

$r = rconn();
prefix_cleanup($r, $P);

echo "===== 删大 Key 时，别的客户端被堵多久（Redis 7.4.11）=====\n";
echo "victim 是一个 {$N} 字段的 hash（hashtable，每字段一个节点）；\n";
echo "探测进程在 {$BUCKET}ms 一档上记最坏 PING 往返。探针和操作者都是持久连接，都不含进程启动开销。\n";

foreach (['DEL', 'UNLINK'] as $op) {
    build_hash($r, "{$P}victim", $N);
    $sz = (int)$r->rawCommand('MEMORY', 'USAGE', "{$P}victim", 'SAMPLES', '0');
    $r->del("{$P}blk:done", "{$P}blk:op");

    $base = ms() + 800;
    $r = null;                                        // fork 前断开父进程自己的连接
    fork_all(2, function ($who) use ($base, $P, $N, $BUCKET, $RUN, $ACT, $op) {
        $r = rconn();
        if ($who === 0) {                             // 探针：紧循环 PING
            at($base, 0);
            $max = [];
            while (true) {
                $e = ms() - $base;
                if ($e >= $RUN) break;
                $t = microtime(true);
                $r->ping();
                $d = (microtime(true) - $t) * 1000;
                $b = intdiv($e, $BUCKET);
                if (!isset($max[$b]) || $d > $max[$b]) $max[$b] = $d;
            }
            $r->multi(Redis::PIPELINE);
            foreach ($max as $b => $v) $r->rawCommand('SET', "{$P}blk:$b", sprintf('%.3f', $v));
            $r->exec();
            $r->set("{$P}blk:done", 1);
        } else {                                      // 操作者
            at($base, $ACT);
            $t = microtime(true);
            $r->rawCommand($op, "{$P}victim");
            $r->set("{$P}blk:op", sprintf('%.3f', (microtime(true) - $t) * 1000));
        }
    });
    $r = rconn();                                     // fork 后重连

    $opMs = (float)$r->get("{$P}blk:op");
    $nb = intdiv($RUN, $BUCKET) + 1;
    $prof = [];
    for ($b = 0; $b < $nb; $b++) $prof[$b] = (float)($r->get("{$P}blk:$b") ?: 0);
    $peak = max($prof);
    $peakAt = array_search($peak, $prof, true) * $BUCKET;

    printf("\n--- %s ---\n", $op);
    printf("  victim 占用 %s B；%s 命令自身耗时 %.2f ms\n", number_format($sz), $op, $opMs);
    printf("  旁人最坏一次 PING 往返 %.2f ms，出现在 t≈%dms\n", $peak, $peakAt);
    echo "  每 {$BUCKET}ms 的最坏 PING 延迟（ms）：\n";
    foreach ($prof as $b => $v) {
        $bar = str_repeat('#', (int)min(60, round($v * 3)));
        printf("    t=%4dms %7.2f %s\n", $b * $BUCKET, $v, $bar);
    }
    $r->del("{$P}blk:op", "{$P}blk:done");
    for ($b = 0; $b < $nb; $b++) $r->del("{$P}blk:$b");
}

prefix_cleanup($r, $P);
echo "\n清理完毕\n";
