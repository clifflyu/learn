<?php
// Q28 缓存穿透 / 击穿 / 雪崩 —— 全部用真实 MySQL 回源，回源次数用 MySQL SESSION 计数器统计
//
// 用法:
//   docker exec learn-php php /app/bench/redis/q28-cache.php setup
//   docker exec learn-php php /app/bench/redis/q28-cache.php penetration
//   docker exec learn-php php /app/bench/redis/q28-cache.php breakdown
//   docker exec learn-php php /app/bench/redis/q28-cache.php avalanche
//
// 回源次数怎么保证是真实的：读 MySQL 的 SHOW SESSION STATUS LIKE 'Com_select'，
// 这是服务端会话计数器，PHP 侧改不了。（不能用 GLOBAL：这台 MySQL 上还有别的会话在
// 跑，实测 GLOBAL 空转噪声 1200~4300 q/s，任何差值都被淹掉。）
// 子进程各自读自己的会话计数，再 INCRBY 汇总到 Redis —— 两边对不上就说明实验有问题。

require __DIR__ . '/_conn.php';

$PFX = 'q28:';
$cmd = $argv[1] ?? '';

const SLOW_MS = 50;   // 模拟「慢查询 / 复杂聚合」的回源耗时。真实 PK 查询太快，窗口撑不开。

function load_user(PDO $pdo, int $id, int $slowMs = 0) {
    if ($slowMs) usleep($slowMs * 1000);
    $st = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function count_keys(Redis $r, string $pat): int {
    $it = null; $n = 0;
    while (($keys = $r->scan($it, $pat, 1000)) !== false) { if ($keys) $n += count($keys); if ($it === 0) break; }
    return $n;
}
function sum_memory(Redis $r, string $pat): int {
    $it = null; $n = 0;
    while (($keys = $r->scan($it, $pat, 1000)) !== false) { if ($keys) foreach ($keys as $k) $n += (int)$r->rawCommand('MEMORY', 'USAGE', $k); if ($it === 0) break; }
    return $n;
}

// ---------------------------------------------------------------- setup
if ($cmd === 'setup') {
    $pdo = dbconn(false);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS q28_cache DEFAULT CHARACTER SET utf8mb4');
    $pdo = dbconn();
    $pdo->exec('DROP TABLE IF EXISTS users');
    $pdo->exec('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(64), city VARCHAR(32), KEY idx_city (city)) ENGINE=InnoDB');
    $st = $pdo->prepare('INSERT INTO users VALUES (?,?,?)');
    $pdo->beginTransaction();
    for ($i = 1; $i <= 1000; $i++) $st->execute([$i, "user$i", 'city' . ($i % 20)]);
    $pdo->commit();
    echo "q28_cache.users 就绪，共 " . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . " 行\n";
    exit;
}

// ---------------------------------------------------------------- 穿透
if ($cmd === 'penetration') {
    $r = rconn(); $pdo = dbconn();
    prefix_cleanup($r, $PFX);
    $M = 1000;

    echo "===== 穿透：{$M} 次查询，目标 id 全都不存在（表里只有 1000 行）=====\n\n";

    // 1. 攻击者固定打同一个不存在的 id
    $a = db_selects($pdo); $t0 = microtime(true);
    for ($i = 0; $i < $M; $i++) load_user($pdo, 999999);
    $d = db_selects($pdo) - $a; $dt = microtime(true) - $t0;
    printf("1) 固定 id=999999，无防护   : %4d 次请求 → 回源 %4d 次，%.3fs（%.0f q/s）\n", $M, $d, $dt, $M / $dt);

    // 2. 同样的攻击 + 空值缓存
    prefix_cleanup($r, $PFX);
    $a = db_selects($pdo); $t0 = microtime(true);
    for ($i = 0; $i < $M; $i++) {
        if ($r->get("{$PFX}nul:999999") === false) {
            $row = load_user($pdo, 999999);
            $r->setex("{$PFX}nul:999999", 60, $row === null ? '__NULL__' : json_encode($row));
        }
    }
    $d = db_selects($pdo) - $a; $dt = microtime(true) - $t0;
    printf("2) 固定 id=999999，空值缓存: %4d 次请求 → 回源 %4d 次，%.3fs\n", $M, $d, $dt);

    // 3. 攻击者每次换一个新 id
    prefix_cleanup($r, $PFX);
    $a = db_selects($pdo); $t0 = microtime(true);
    for ($i = 0; $i < $M; $i++) {
        $id = 900000 + $i;
        if ($r->get("{$PFX}nul:$id") === false) {
            $row = load_user($pdo, $id);
            $r->setex("{$PFX}nul:$id", 60, $row === null ? '__NULL__' : json_encode($row));
        }
    }
    $d = db_selects($pdo) - $a; $dt = microtime(true) - $t0;
    $nk = count_keys($r, "{$PFX}nul:*");
    $nm = sum_memory($r, "{$PFX}nul:*");
    printf("3) 每次换新 id，空值缓存   : %4d 次请求 → 回源 %4d 次，%.3fs\n", $M, $d, $dt);
    printf("   ↑ 空值缓存写了 %d 个 key，占 %d 字节。一个都没挡住，反而被人拿去写 Redis 了\n", $nk, $nm);

    // 4/5. 布隆过滤器
    echo "\n--- 布隆过滤器（Redis 位图 SETBIT 实现，1000 个真实 id，10000 bit / k=7）---\n";
    $bits = 10000; $k = 7;
    prefix_cleanup($r, $PFX);
    bf_build($r, "{$PFX}bf", range(1, 1000), $bits, $k);
    $bfmem = (int)$r->rawCommand('MEMORY', 'USAGE', "{$PFX}bf");

    $a = db_selects($pdo); $t0 = microtime(true);
    for ($i = 0; $i < $M; $i++) {
        if (bf_test($r, "{$PFX}bf", 999999, $bits, $k)) {
            if ($r->get("{$PFX}nul:999999") === false) {
                $row = load_user($pdo, 999999);
                $r->setex("{$PFX}nul:999999", 60, $row === null ? '__NULL__' : json_encode($row));
            }
        }
    }
    $d = db_selects($pdo) - $a; $dt = microtime(true) - $t0;
    printf("4) 固定 id + 布隆过滤器     : %4d 次请求 → 回源 %4d 次，%.3fs\n", $M, $d, $dt);

    $a = db_selects($pdo); $t0 = microtime(true);
    for ($i = 0; $i < $M; $i++) {
        $id = 900000 + $i;
        if (bf_test($r, "{$PFX}bf", $id, $bits, $k)) {
            if ($r->get("{$PFX}nul:$id") === false) {
                $row = load_user($pdo, $id);
                $r->setex("{$PFX}nul:$id", 60, $row === null ? '__NULL__' : json_encode($row));
            }
        }
    }
    $d = db_selects($pdo) - $a; $dt = microtime(true) - $t0;
    printf("5) 换新 id + 布隆过滤器     : %4d 次请求 → 回源 %4d 次，%.3fs\n", $M, $d, $dt);
    printf("   ↑ %d bit 的位图只占 %d 字节，对比空值缓存那 %d 字节\n", $bits, $bfmem, $nm);

    echo "\n--- 布隆过滤器误判率实测（n=1000 个真实 id，拿 20000 个不存在的 id 探）---\n";
    printf("  %-8s %-4s %-10s %-12s %s\n", '位图 bit', 'k', 'bit/元素', '实测误判率', 'MEMORY USAGE');
    $probe = range(2000000, 2019999);
    foreach ([[4000, 3], [8000, 6], [10000, 7], [20000, 14]] as [$m, $kk]) {
        $bk = "{$PFX}bfp:$m";
        bf_build($r, $bk, range(1, 1000), $m, $kk);
        printf("  %-8d %-4d %-10.1f %-12.3f%% %d B\n", $m, $kk, $m / 1000,
               bf_fp_rate($r, $bk, $probe, $m, $kk), (int)$r->rawCommand('MEMORY', 'USAGE', $bk));
        $r->del($bk);
    }
    echo "  注：每档只探了 20000 个样本，实测值有抖动，不是理论值\n";

    $r->del("{$PFX}bf");
    prefix_cleanup($r, $PFX);
    echo "\n清理完毕\n";
    exit;
}

// ---------------------------------------------------------------- 击穿
if ($cmd === 'breakdown') {
    $N = 50;
    $r = rconn(); $pdo = dbconn();
    prefix_cleanup($r, $PFX);

    foreach (['none', 'mutex'] as $mode) {
        $r->del("{$PFX}hot:1", "{$PFX}stat:dbselect");
        $base = ms() + 1500;
        $t0 = microtime(true);
        $pdo = null; $r = null;                          // fork 前断开父进程自己的连接
        fork_all($N, function ($i) use ($base, $mode, $PFX) {
            $r = rconn(); $pdo = dbconn();
            try {
                at($base, 0);                                   // N 个进程同一时刻起跑
                $ck = "{$PFX}hot:1";
                if ($r->get($ck) !== false) return;
                if ($mode === 'mutex') {
                    $lock = "{$PFX}lock:hot:1";
                    $tok  = bin2hex(random_bytes(8));
                    if ($r->set($lock, $tok, ['nx', 'px' => 2000])) {
                        $a = db_selects($pdo);
                        $row = load_user($pdo, 1, SLOW_MS);      // 只有抢到锁的这一个回源
                        $r->incrBy("{$PFX}stat:dbselect", db_selects($pdo) - $a);
                        $r->setex($ck, 60, json_encode($row));
                        $r->eval("if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('del',KEYS[1]) end", [$lock, $tok], 1);
                    } else {
                        for ($j = 0; $j < 60; $j++) { usleep(10000); if ($r->get($ck) !== false) break; }
                    }
                } else {
                    $a = db_selects($pdo);
                    $row = load_user($pdo, 1, SLOW_MS);          // N 个人一起回源
                    $r->incrBy("{$PFX}stat:dbselect", db_selects($pdo) - $a);
                    $r->setex($ck, 60, json_encode($row));
                }
            } finally {
                if (isset($a)) { /* 已在上面记过 */ }
            }
        });
        $dt = microtime(true) - $t0;
        $r = rconn(); $pdo = dbconn();                   // fork 后重连
        $d  = (int)$r->get("{$PFX}stat:dbselect");
        printf("%-9s: %d 个并发进程同时请求一个刚失效的热 key → 回源 %3d 次，全部跑完耗时 %.3fs\n",
               $mode === 'mutex' ? '互斥重建' : '无防护', $N, $d, $dt);
    }
    prefix_cleanup($r, $PFX);
    echo "\n清理完毕\n";
    exit;
}

// ---------------------------------------------------------------- 雪崩
if ($cmd === 'avalanche') {
    $K = 500; $RUN = 6000; $TTL = 3; $JITTER = 3; $W = 10; $BUCKET = 200;
    $NB = intdiv($RUN, $BUCKET) + 3;
    $r = rconn(); $pdo = dbconn();
    echo "===== 雪崩：$K 个缓存 key，{$W} 个并发客户端各读各的，观察 {$RUN}ms =====\n";
    echo "（每个客户端随机取 key 读，未命中就真实回源，每次回源 INCR 进对应的 {$BUCKET}ms 桶）\n";
    echo "（单进程客户端是瓶颈，测不出 DB 端的尖峰，所以这里用 {$W} 个进程）\n\n";

    foreach (['同一时刻失效' => 0, 'TTL 打散' => $JITTER] as $label => $jit) {
        prefix_cleanup($r, $PFX);
        $now = (int)$r->time()[0];
        $pipe = $r->pipeline();
        for ($i = 1; $i <= $K; $i++) {
            $pipe->setex("{$PFX}av:$i", 60, 'warm');
            $pipe->expireAt("{$PFX}av:$i", $now + $TTL + ($jit ? random_int(0, $jit) : 0));
        }
        $pipe->exec();
        for ($b = 0; $b < $NB; $b++) $r->del("{$PFX}avb:$b");

        $base = ms() + 1500;
        $pdo = null; $r = null;                     // fork 前断开父进程自己的连接
        fork_all($W, function ($w) use ($base, $K, $RUN, $TTL, $JITTER, $jit, $BUCKET, $PFX) {
            $r = rconn(); $pdo = dbconn();
            at($base, 0);
            $stop = ms() + $RUN;
            while (ms() < $stop) {
                $i = random_int(1, $K);
                if ($r->get("{$PFX}av:$i") !== false) continue;
                load_user($pdo, $i);                                    // 真实回源
                $r->incr("{$PFX}avb:" . intdiv(ms() - $base, $BUCKET));
                $r->setex("{$PFX}av:$i", 60, 'warm');
                $r->expireAt("{$PFX}av:$i", (int)$r->time()[0] + $TTL + ($jit ? random_int(0, $jit) : 0));
            }
        });

        $r = rconn(); $pdo = dbconn();               // fork 后重连
        $bk = [];
        for ($b = 0; $b < $NB; $b++) $bk[$b] = (int)$r->get("{$PFX}avb:$b");
        $peak = max($bk); $total = array_sum($bk);
        printf("%-14s TTL=%ds%-12s 总回源 %4d 次 / 峰值 %3d 次每 {$BUCKET}ms（≈%4d q/s）\n",
               $label, $TTL, $jit ? "+rand(0,{$jit})" : '', $total, $peak, $peak * (1000 / $BUCKET));
        // 打印前 15 个桶的形状，看尖峰有没有被摊平
        echo "    每 {$BUCKET}ms 的回源数（前 15 个桶）: " . implode(' ', array_slice($bk, 0, 15)) . "\n";
    }
    prefix_cleanup($r, $PFX);
    echo "\n清理完毕\n";
    exit;
}

// ---------------------------------------------------------------- bloom helpers
function bf_positions(string $item, int $bits, int $k): array {
    $h1 = crc32($item);
    $h2 = crc32($item . "\x00salt") | 1;
    $pos = [];
    for ($i = 0; $i < $k; $i++) $pos[] = (int)((($h1 + $i * $h2) % $bits + $bits) % $bits);
    return $pos;
}
function bf_build(Redis $r, string $key, array $items, int $bits, int $k): void {
    $r->del($key);
    $r->setBit($key, $bits - 1, 0);                 // 先把位图撑到目标长度
    $pipe = $r->pipeline();
    foreach ($items as $it) foreach (bf_positions((string)$it, $bits, $k) as $p) $pipe->setBit($key, $p, 1);
    $pipe->exec();
}
function bf_test(Redis $r, string $key, $item, int $bits, int $k): bool {
    foreach (bf_positions((string)$item, $bits, $k) as $p) if (!$r->getBit($key, $p)) return false;
    return true;
}
function bf_fp_rate(Redis $r, string $key, array $probe, int $bits, int $k): float {
    $pos = [];
    foreach ($probe as $it) $pos[] = bf_positions((string)$it, $bits, $k);
    $pipe = $r->pipeline();
    foreach ($pos as $ps) foreach ($ps as $p) $pipe->getBit($key, $p);
    $res = $pipe->exec();
    $fp = 0; $n = 0; $i = 0;
    foreach ($pos as $ps) {
        $all = true;
        foreach ($ps as $_) if (!$res[$i++]) $all = false;
        if ($all) $fp++;
        $n++;
    }
    return $n ? $fp * 100 / $n : 0.0;
}

fwrite(STDERR, "用法: q28-cache.php setup|penetration|breakdown|avalanche\n");
exit(1);
