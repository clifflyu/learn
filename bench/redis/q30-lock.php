<?php
// Q30 Redis 分布式锁：SET NX PX / 误删 / 过期 / 续期 / 可重入
//
// 用法:
//   docker exec learn-php php /app/bench/redis/q30-lock.php lost-update
//   docker exec learn-php php /app/bench/redis/q30-lock.php no-expire
//   docker exec learn-php php /app/bench/redis/q30-lock.php wrong-del
//   docker exec learn-php php /app/bench/redis/q30-lock.php lua-del
//   docker exec learn-php php /app/bench/redis/q30-lock.php watchdog
//   docker exec learn-php php /app/bench/redis/q30-lock.php reentrant
//
// 多个进程用「绝对时刻调度」at($base, $offsetMs) 对齐，而不是 sleep 相对时间 ——
// 进程启动、自动加载的耗时会漂，相对写法跑出来的时序每次都不一样。

require __DIR__ . '/_conn.php';

$P  = 'q30:';
$cmd = $argv[1] ?? '';

function lock_naive(Redis $r, string $k, string $tok, int $pxMs): bool {
    return (bool)$r->set($k, $tok, ['nx', 'px' => $pxMs]);
}
function unlock_naive(Redis $r, string $k): bool {          // 直接 DEL，不校验持有者
    return (bool)$r->del($k);
}
function unlock_lua(Redis $r, string $k, string $tok): bool {   // 校验 value 再删
    static $lua = "if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end";
    return (bool)$r->eval($lua, [$k, $tok], 1);
}
function fmt(Redis $r, string $k): string {
    $v = $r->get($k);
    $t = $r->pttl($k);
    return $v === false ? '(不存在)' : "$v (pttl={$t}ms)";
}

// ---------------------------------------------------------------- 1. 为什么需要锁
if ($cmd === 'lost-update') {
    $N = 20; $M = 100;
    $r = rconn();
    echo "===== 无锁 vs 加锁：$N 个进程 × $M 次「读-改-写」 =====";
    echo "\n（每次是 GET → usleep(1ms) 模拟业务计算 → SET，共 " . ($N * $M) . " 次自增）\n\n";

    foreach (['nolock', 'lock'] as $mode) {
        $r->del("{$P}cnt");
        $r->set("{$P}cnt", 0);
        $base = ms() + 1200;
        $t0 = microtime(true);
        $r = null;                                       // fork 前断开父进程自己的连接
        fork_all($N, function ($i) use ($base, $mode, $P, $M) {
            $r = rconn();
            at($base, 0);
            for ($j = 0; $j < $M; $j++) {
                if ($mode === 'lock') {
                    $tok = bin2hex(random_bytes(8));
                    while (!lock_naive($r, "{$P}lock:cnt", $tok, 5000)) usleep(200);   // 自旋等锁
                    $v = (int)$r->get("{$P}cnt");
                    usleep(1000);
                    $r->set("{$P}cnt", $v + 1);
                    unlock_lua($r, "{$P}lock:cnt", $tok);
                } else {
                    $v = (int)$r->get("{$P}cnt");
                    usleep(1000);
                    $r->set("{$P}cnt", $v + 1);
                }
            }
        });
        $dt = microtime(true) - $t0;
        $r = rconn();                                    // fork 后重连
        $got = (int)$r->get("{$P}cnt");
        printf("  %-8s : 期望 %d，实际 %d，丢 %d 次（%.1f%%），耗时 %.2fs\n",
               $mode === 'lock' ? 'SET NX PX' : '无锁', $N * $M, $got, $N * $M - $got,
               ($N * $M - $got) * 100 / ($N * $M), $dt);
    }
    $r->del("{$P}cnt", "{$P}lock:cnt");
    exit;
}

// ---------------------------------------------------------------- 2. 忘了过期时间
if ($cmd === 'no-expire') {
    $r = rconn();
    $r->del("{$P}lock:noexp");
    echo "===== SETNX 之后进程死了（没走到 EXPIRE）=====\n\n";
    echo "1) 客户端 A: SETNX {$P}lock:noexp tokA → " . var_export((bool)$r->setnx("{$P}lock:noexp", 'tokA'), true) . "\n";
    echo "   A 本来打算下一步 EXPIRE 30，但在这一步之前进程被 kill / 网络断了\n";
    echo "   现在这个 key 的 pttl = " . $r->pttl("{$P}lock:noexp") . "  (-1 = 永不过期)\n\n";
    $r2 = rconn();     // 另一个客户端，另一个连接
    echo "2) 客户端 B 尝试加锁 20 次，每次间隔 100ms：\n";
    $ok = 0;
    for ($i = 0; $i < 20; $i++) { if ($r2->set("{$P}lock:noexp", 'tokB', ['nx', 'px' => 30000])) $ok++; usleep(100000); }
    printf("   成功 %d / 20 次，key 的 pttl 依然是 %d —— 这把锁永远不会自己开\n", $ok, $r2->pttl("{$P}lock:noexp"));
    echo "\n3) 对比：把 SETNX+EXPIRE 合成一条 SET NX PX，进程死了锁最多卡 PX 那么久\n";
    $r->del("{$P}lock:atomic");
    $r->set("{$P}lock:atomic", 'tokA', ['nx', 'px' => 1500]);
    $t1 = $r->pttl("{$P}lock:atomic");
    usleep(1600 * 1000);
    $t2 = $r->pttl("{$P}lock:atomic");
    printf("   写入后 pttl=%dms，1.6 秒后 pttl=%d（-2 = 已自动释放，别人现在能拿到）\n", $t1, $t2);
    echo "   → " . fmt($r, "{$P}lock:atomic") . "\n";
    $r->del("{$P}lock:noexp", "{$P}lock:atomic");
    exit;
}

// ---------------------------------------------------------------- 3/4. 误删别人的锁
foreach (['wrong-del' => false, 'lua-del' => true] as $sub => $useLua) {
    if ($cmd !== $sub) continue;
    echo $useLua
        ? "===== 释放时用 Lua 校验 value（正确做法）=====\n\n"
        : "===== 释放时直接 DEL（误删别人的锁）=====\n\n";
    $r = rconn();
    $r->del("{$P}lock:mutex");
    $base = ms() + 1500;
    $r = null;                                       // fork 前断开父进程自己的连接
    fork_all(3, function ($who) use ($base, $P, $useLua) {
        //  $who 0=A(持锁者, 干活超时)  1=B(第二个)  2=C(第三个)
        $r = rconn();
        if ($who === 0) {
            at($base, 0);
            $tokA = 'tokA';
            $got  = $r->set("{$P}lock:mutex", $tokA, ['nx', 'px' => 800]);
            at($base, 30);
            echo "  A  t=  30ms  SET lock tokA NX PX 800 → " . ($got ? '成功' : '失败') . "，然后开始干活（实际要 1500ms）\n";
            at($base, 1500);
            if ($useLua) { $d = (int)$r->eval("if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end", ["{$P}lock:mutex", $tokA], 1); }
            else         { $d = unlock_naive($r, "{$P}lock:mutex") ? 1 : 0; }
            echo "  A  t=1500ms  收工释放 → " . ($d ? 'DEL 执行了' : '发现自己已经不是持有者，什么都没删') . "\n";
        }
        if ($who === 1) {
            $tokB = 'tokB';
            at($base, 100);
            $got1 = $r->set("{$P}lock:mutex", $tokB, ['nx', 'px' => 3000]);
            echo "  B  t= 100ms  SET lock tokB NX PX 3000 → " . ($got1 ? '成功' : '失败（A 还持着）') . "\n";
            at($base, 900);
            $got2 = $r->set("{$P}lock:mutex", $tokB, ['nx', 'px' => 3000]);
            echo "  B  t= 900ms  SET lock tokB NX PX 3000 → " . ($got2 ? '成功（A 的锁 800ms 已过期）' : '失败') . "\n";
            at($base, 2000);
            $still = $r->get("{$P}lock:mutex");
            echo "  B  t=2000ms  我以为自己还持着锁，GET 回来的是 " . ($still === false ? '(空)' : $still)
               . " → " . ($still === $tokB ? '确实还是我' : '已经被别人拿走了，但我不知道') . "\n";
        }
        if ($who === 2) {
            at($base, 1600);
            $got = $r->set("{$P}lock:mutex", 'tokC', ['nx', 'px' => 3000]);
            echo "  C  t=1600ms  SET lock tokC NX PX 3000 → " . ($got ? '成功（此刻 B 还以为自己持有）' : '失败（锁还在 B 手里，互斥成立）') . "\n";
            at($base, 2100);
            echo "  C  t=2100ms  锁的当前持有者 = " . fmt($r, "{$P}lock:mutex") . "\n";
        }
    });

    // 父进程收尾：看最终有几个进程自认为在临界区
    $r = rconn();                                    // fork 后重连
    $holder = $r->get("{$P}lock:mutex");
    echo "\n  最终 key 的持有者 = " . ($holder === false ? '(空)' : $holder) . "\n";
    echo $useLua
        ? "  → A 的 DEL 被 Lua 挡掉了，B 的锁没被误删，C 加锁失败。互斥从头到尾成立。\n"
        : "  → A 在 1500ms 那次 DEL 删掉的是 B 的锁，C 随即拿到锁。B 和 C 同时认为自己在临界区。\n";
    $r->del("{$P}lock:mutex");
    echo "\n";
    exit;
}

// ---------------------------------------------------------------- 5. 续期 watchdog
if ($cmd === 'watchdog') {
    $r = rconn();
    $TTL = 800; $WORK = 4200; $A_OFF = 0; $B_OFF = 150; $B_DEADLINE = 5200;
    echo "===== 锁 TTL={$TTL}ms，但持锁者要干 {$WORK}ms 的活 =====\n";
    echo "（A 在 t=0 加锁；B 从 t={$B_OFF}ms 起每 20ms 试一次，直到 t={$B_DEADLINE}ms）\n\n";
    foreach (['norenew' => '不续期', 'watchdog' => '看门狗自动续期'] as $mode => $label) {
        prefix_cleanup($r, $P);            // 清掉上一轮的结果 key
        $base = ms() + 1200;
        $r = null;                                   // fork 前断开父进程自己的连接
        fork_all(2, function ($who) use ($base, $mode, $P, $TTL, $WORK, $A_OFF, $B_OFF, $B_DEADLINE) {
            $r = rconn();
            if ($who === 0) {                                    // A：持锁干活
                $tok = 'tokA';
                at($base, $A_OFF);
                if (!$r->set("{$P}lock:wd", $tok, ['nx', 'px' => $TTL])) { $r->set("{$P}wd:a_result", 'SETNX 失败（B 先抢到了，测试设计有问题）'); return; }
                $lostAt = null;
                while (ms() < $base + $WORK) {
                    if ($mode === 'watchdog') {
                        // 续期也必须校验持有者，否则会续到别人的锁上
                        $r->eval("if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('pexpire',KEYS[1],ARGV[2]) else return 0 end",
                                 ["{$P}lock:wd", $tok, $TTL], 1);
                    }
                    if ($lostAt === null && $r->get("{$P}lock:wd") !== $tok) $lostAt = ms() - $base;
                    usleep(50000);
                }
                $r->set("{$P}wd:a_result", $lostAt === null ? "全程持有，{$WORK}ms 内一刻都没丢" : "在 t={$lostAt}ms 发现锁已经不在自己手里");
                $r->eval("if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end", ["{$P}lock:wd", $tok], 1);
                $r->set("{$P}wd:a_release", ms() - $base);
            } else {                                             // B：一直被挡在门外
                at($base, $B_OFF);
                while (ms() - $base < $B_DEADLINE) {
                    if ($r->set("{$P}lock:wd", 'tokB', ['nx', 'px' => 3200])) {
                        $r->set("{$P}wd:b_got", ms() - $base);
                        $r->del("{$P}lock:wd");
                        return;
                    }
                    usleep(20000);
                }
                $r->set("{$P}wd:b_got", -1);
            }
        });
        $r = rconn();                                // fork 后重连
        $aLost = $r->get("{$P}wd:a_result");
        $aRel  = (int)$r->get("{$P}wd:a_release");
        $bGot  = (int)$r->get("{$P}wd:b_got");
        echo "--- {$label} ---\n";
        printf("  A（持锁者）: %s\n", $aLost === false ? '(无结果)' : $aLost);
        printf("  A 释放锁于  : t=%dms\n", $aRel);
        if ($bGot === -1) printf("  B（等待者）: %dms 内一次都没抢到\n", $B_DEADLINE);
        else printf("  B（等待者）: t=%dms 抢到锁%s\n", $bGot, $bGot < $aRel ? "  ← 早于 A 释放，两人同时在临界区！" : "  ← 晚于 A 释放，互斥成立");
        echo "\n";
    }
    prefix_cleanup($r, $P);
    exit;
}

// ---------------------------------------------------------------- 6. 可重入
if ($cmd === 'reentrant') {
    $r = rconn();
    $r->del("{$P}lock:re");
    $ACQ = "local k=KEYS[1] local tok=ARGV[1] local ttl=tonumber(ARGV[2])
            if redis.call('exists',k)==0 then redis.call('hincrby',k,tok,1) redis.call('pexpire',k,ttl) return 1 end
            if redis.call('hexists',k,tok)==1 then redis.call('hincrby',k,tok,1) redis.call('pexpire',k,ttl) return 1 end
            return 0";
    $REL = "local k=KEYS[1] local tok=ARGV[1]
            if redis.call('hexists',k,tok)==0 then return 0 end
            local c=redis.call('hincrby',k,tok,-1)
            if c<=0 then redis.call('del',k) end
            return 1";

    echo "===== 可重入锁（hash 存持有者+重入次数）=====\n\n";
    echo "A 加锁 1 次 : " . $r->eval($ACQ, ["{$P}lock:re", 'tokA', 5000], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "\n";
    echo "A 再加 1 次 : " . $r->eval($ACQ, ["{$P}lock:re", 'tokA', 5000], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "\n";
    echo "B 加锁      : " . $r->eval($ACQ, ["{$P}lock:re", 'tokB', 5000], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "  ← 0 = 被挡住\n";
    echo "A 释放 1 次 : " . $r->eval($REL, ["{$P}lock:re", 'tokA'], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "  ← 还在，计数减到 1\n";
    echo "B 加锁      : " . $r->eval($ACQ, ["{$P}lock:re", 'tokB', 5000], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "  ← 依然 0，A 的计数没归零\n";
    echo "B 直接释放  : " . $r->eval($REL, ["{$P}lock:re", 'tokB'], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "  ← 0 = 不是自己的锁删不掉\n";
    echo "A 释放 2 次 : " . $r->eval($REL, ["{$P}lock:re", 'tokA'], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "  ← 计数归零，锁被删\n";
    echo "B 加锁      : " . $r->eval($ACQ, ["{$P}lock:re", 'tokB', 5000], 1)
       . "   hgetall=" . json_encode($r->hGetAll("{$P}lock:re")) . "  ← 现在才轮到 B\n";
    $r->del("{$P}lock:re");
    exit;
}

fwrite(STDERR, "用法: q30-lock.php lost-update|no-expire|wrong-del|lua-del|watchdog|reentrant\n");
exit(1);
