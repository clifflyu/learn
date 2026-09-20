<?php
// Q31 「删一个大 Key」到底花多久 —— 按数据结构拆开量
//
// 用法: docker exec learn-php php /app/bench/redis/q31-delcost.php [元素数]
//
// 两条线互相印证：
//   客户端线 —— 持久连接上包住 DEL，含一次网络往返（先量一个「DEL 不存在的 key」
//               当基线把 RTT 减掉）。这个方法的问题是 redis-cli 那种 fork+exec 的
//               4~5ms 启动开销会盖住一切，所以必须用持久连接。
//   服务端线 —— SLOWLOG。临时把 slowlog-log-slower-than 设成 0 记录所有命令，
//               然后按「命令名 + key 名」精确取回那一条的 usec。phpredis 会把
//               SLOWLOG GET 解析成嵌套数组，比在 shell 里 grep 靠谱得多。
//
// 核心结论先放这：删除耗时跟「元素个数」几乎无关，跟「内部节点个数」强相关。
// quicklist 把元素打包进 listpack，一次 zfree 释放一大片；hashtable / skiplist
// 是每个元素一个节点，只能一个个 free。

require __DIR__ . '/_conn.php';

$P = 'q31:';
$N = (int)($argv[1] ?? 500000);

function pipe_cmds(Redis $r, array $cmds): void {
    $r->multi(Redis::PIPELINE);
    foreach ($cmds as $c) $r->rawCommand(...$c);
    $r->exec();
}
function build(Redis $r, string $key, string $type, int $n): void {
    $r->del($key);
    $chunk = [];
    for ($i = 0; $i < $n; $i++) {
        $chunk[] = match ($type) {
            'list' => ['RPUSH', $key, "v$i"],
            'hash' => ['HSET',  $key, "f$i", "v$i"],
            'set'  => ['SADD',  $key, "v$i"],
            'zset' => ['ZADD',  $key, (string)$i, "v$i"],
        };
        if (count($chunk) >= 5000) { pipe_cmds($r, $chunk); $chunk = []; }
    }
    if ($chunk) pipe_cmds($r, $chunk);
}
// 从 SLOWLOG 里精确取回「命令名 + 参数」都匹配的那一条的 usec
function slow_usec(Redis $r, string $cmd, string $arg): ?int {
    foreach ((array)$r->rawCommand('SLOWLOG', 'GET', '200') as $e) {
        if (!is_array($e) || !isset($e[2], $e[3])) continue;
        if (strcasecmp((string)$e[3][0], $cmd) === 0 && (string)($e[3][1] ?? '') === $arg) return (int)$e[2];
    }
    return null;
}
function usec_str(?int $u): string { return $u === null ? '?' : number_format($u); }

$r = rconn();
prefix_cleanup($r, $P);
$oldThr = $r->rawCommand('CONFIG', 'GET', 'slowlog-log-slower-than');
$r->rawCommand('CONFIG', 'SET', 'slowlog-log-slower-than', '0');
$r->rawCommand('CONFIG', 'SET', 'slowlog-max-len', '1000');

echo "===== 删大 Key 的成本（Redis 7.4.11，每个 key {$N} 个元素）=====\n\n";

// ---- 客户端基线：DEL 一个不存在的 key = 纯网络往返 + 一次空查找
$base = [];
for ($i = 0; $i < 200; $i++) {
    $t = microtime(true); $r->del("{$P}nope"); $base[] = (microtime(true) - $t) * 1000;
}
sort($base);
$rtt = $base[100];
printf("  客户端基线：DEL 一个不存在的 key，p50 = %.3f ms（≈ 一次网络往返，下面要把它减掉）\n\n", $rtt);

printf("  %-6s %-11s %12s %15s %15s %14s\n", '类型', '编码', '内存占用', '客户端看到(ms)', '服务端 usec', 'usec/元素');
$rows = [];
foreach (['list', 'hash', 'set', 'zset'] as $type) {
    $key = "{$P}dc:$type";
    build($r, $key, $type, $N);
    $mem = (int)$r->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', '0');
    $enc = $r->object('encoding', $key);

    $r->rawCommand('SLOWLOG', 'RESET');
    $t = microtime(true); $r->del($key); $cli = (microtime(true) - $t) * 1000;
    $usec = slow_usec($r, 'DEL', $key);

    printf("  %-6s %-11s %12s %15.3f %15s %14.3f\n",
           $type, $enc, number_format($mem), $cli, usec_str($usec), $usec !== null ? $usec / $N : 0);
    $rows[$type] = [$mem, $cli - $rtt, $usec, $enc];
}

echo "\n  ↑ list 的元素打包在 listpack 里（编码 quicklist），一次 free 放掉一大片；\n";
echo "    hash/set/zset 每个元素一个节点，只能逐个释放 —— 元素数一样，删除成本差两个数量级。\n";

// ---- 删除耗时随元素数怎么涨
echo "\n===== 同一类型（list）元素数翻倍时，删除耗时怎么涨 =====\n\n";
printf("  %-12s %-12s %14s %15s %14s\n", '元素数', '编码', '内存占用', '服务端 usec', 'usec/元素');
foreach ([125000, 250000, 500000, 1000000, 2000000] as $n) {
    $key = "{$P}dc:scale";
    build($r, $key, 'list', $n);
    $mem = (int)$r->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', '0');
    $enc = $r->object('encoding', $key);
    $r->rawCommand('SLOWLOG', 'RESET');
    $r->del($key);
    $usec = slow_usec($r, 'DEL', $key);
    printf("  %-12s %-12s %14s %15s %14.3f\n", number_format($n), $enc, number_format($mem),
           usec_str($usec), $usec !== null ? $usec / $n : 0);
}

// ---- DEL vs UNLINK：lazyfree 到底把什么挪走了
echo "\n===== DEL vs UNLINK（hash {$N} 个字段，释放成本高的那种）=====\n\n";
printf("  %-8s %14s %15s %20s\n", '命令', '客户端看到', '服务端 usec', 'lazyfree_pending');
foreach (['DEL', 'UNLINK'] as $cmd) {
    $key = "{$P}dc:lu";
    build($r, $key, 'hash', $N);
    $r->rawCommand('SLOWLOG', 'RESET');
    $t = microtime(true);
    $r->rawCommand($cmd, $key);
    $cli = (microtime(true) - $t) * 1000;
    $usec  = slow_usec($r, $cmd, $key);
    $pend  = null;
    foreach (explode("\n", (string)$r->rawCommand('INFO', 'memory')) as $l)
        if (str_starts_with($l, 'lazyfree_pending_objects:')) $pend = trim(explode(':', $l)[1]);
    printf("  %-8s %11.3f ms %15s %20s\n", $cmd, $cli, usec_str($usec), $pend);
    for ($i = 0; $i < 50; $i++) {                                      // 等后台线程放完
        $p = null;
        foreach (explode("\n", (string)$r->rawCommand('INFO', 'memory')) as $l)
            if (str_starts_with($l, 'lazyfree_pending_objects:')) $p = trim(explode(':', $l)[1]);
        if ($p === '0') break;
        usleep(100000);
    }
}
echo "\n  ↑ DEL 的服务端 usec 就是主线程实打实被占住的时间；UNLINK 只花几微秒把对象\n";
echo "    挂到后台队列（lazyfree_pending_objects 立刻变 1），释放交给 bio 线程。\n";
echo "    注意 UNLINK 不是「没有代价」：后台线程释放时会和主线程抢分配器，延迟是摊开来的。\n";

$r->rawCommand('CONFIG', 'SET', 'slowlog-log-slower-than',
               is_array($oldThr) ? (string)($oldThr[1] ?? '10000') : '10000');
$r->rawCommand('CONFIG', 'SET', 'slowlog-max-len', '128');
prefix_cleanup($r, $P);
echo "\n清理完毕\n";
