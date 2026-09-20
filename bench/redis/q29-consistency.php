<?php
// Q29 缓存与数据库一致性：先删缓存 vs 先更新库 —— 把竞态窗口量出来
//
// 用法:
//   docker exec learn-php php /app/bench/redis/q29-consistency.php setup
//   docker exec learn-php php /app/bench/redis/q29-consistency.php window
//   docker exec learn-php php /app/bench/redis/q29-consistency.php race del-first
//   docker exec learn-php php /app/bench/redis/q29-consistency.php race del-first-double
//   docker exec learn-php php /app/bench/redis/q29-consistency.php race update-first
//   docker exec learn-php php /app/bench/redis/q29-consistency.php stress del-first
//   docker exec learn-php php /app/bench/redis/q29-consistency.php stress update-first
//
// 核心问题不是「谁先谁后」，而是「写出脏数据需要一个多宽的窗口」。窗口宽度可以量。

require __DIR__ . '/_conn.php';

$P = 'q29:';
$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';

function item_get(PDO $pdo): int {
    return (int)$pdo->query('SELECT v FROM item WHERE id=1')->fetchColumn();
}
function item_set(PDO $pdo, int $v): int {
    return $pdo->prepare('UPDATE item SET v=? WHERE id=1')->execute([$v]);
}
function cache_key(): string { return 'q29:item:1'; }

// ---------------------------------------------------------------- setup
if ($cmd === 'setup') {
    $pdo = dbconn(false);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS q28_cache DEFAULT CHARACTER SET utf8mb4');
    $pdo = dbconn();
    $pdo->exec('DROP TABLE IF EXISTS item');
    $pdo->exec('CREATE TABLE item (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO item VALUES (1, 0)');
    // 一次业务更新常常不止一行：再准备一张 2000 行的表，city 上故意不加索引
    $pdo->exec('DROP TABLE IF EXISTS item_rows');
    $pdo->exec('CREATE TABLE item_rows (id INT PRIMARY KEY AUTO_INCREMENT, city VARCHAR(16), v INT) ENGINE=InnoDB');
    $st = $pdo->prepare('INSERT INTO item_rows (city, v) VALUES (?,?)');
    $pdo->beginTransaction();
    for ($i = 0; $i < 2000; $i++) $st->execute(['c' . ($i % 10), 0]);
    $pdo->commit();
    $n = $pdo->query("SELECT COUNT(*) FROM item_rows WHERE city='c3'")->fetchColumn();
    echo "q28_cache.item 就绪 (id=1 v=0)；item_rows 就绪 (2000 行，city='c3' 的有 {$n} 行)\n";
    exit;
}

// ---------------------------------------------------------------- 窗口宽度
if ($cmd === 'window') {
    $r = rconn(); $pdo = dbconn();
    $pdo->exec('UPDATE item SET v=0 WHERE id=1');

    // 读侧回填一个完整来回要多久：GET(miss) → SELECT → SET
    $n = 1000; $s = [];
    for ($i = 0; $i < $n; $i++) {
        $r->del(cache_key());
        $t = microtime(true);
        $r->get(cache_key());
        item_get($pdo);
        $r->setex(cache_key(), 30, '0');
        $s[] = (microtime(true) - $t) * 1000;
    }
    sort($s);
    printf("读侧一次完整回填 (GET miss + SELECT + SET) : p50=%.3fms  p99=%.3fms\n", $s[intdiv($n, 2)], $s[(int)($n * 0.99)]);

    // 写侧：单行 UPDATE（走主键）
    $n = 1000; $s = [];
    for ($i = 0; $i < $n; $i++) { $t = microtime(true); item_set($pdo, $i); $s[] = (microtime(true) - $t) * 1000; }
    sort($s);
    printf("写侧单行 UPDATE (主键)                     : p50=%.3fms  p99=%.3fms\n", $s[intdiv($n, 2)], $s[(int)($n * 0.99)]);

    // 写侧：一次业务更新往往不止一行 —— 200 行、city 上无索引、全表扫
    $n = 100; $s = [];
    for ($i = 0; $i < $n; $i++) {
        $t = microtime(true);
        $pdo->exec("UPDATE item_rows SET v=v+1 WHERE city='c3'");
        $s[] = (microtime(true) - $t) * 1000;
    }
    sort($s);
    printf("写侧 200 行 UPDATE (全表扫, 无可用索引)    : p50=%.3fms  p99=%.3fms\n", $s[intdiv($n, 2)], $s[(int)($n * 0.99)]);

    // 写侧：一个事务里改 3 张表（真实业务的样子）
    $n = 100; $s = [];
    for ($i = 0; $i < $n; $i++) {
        $t = microtime(true);
        $pdo->beginTransaction();
        item_set($pdo, $i);
        $pdo->exec("UPDATE item_rows SET v=v+1 WHERE city='c1'");
        $pdo->exec("UPDATE item_rows SET v=v+1 WHERE city='c2'");
        $pdo->commit();
        $s[] = (microtime(true) - $t) * 1000;
    }
    sort($s);
    printf("写侧 3 条 UPDATE 一个事务                  : p50=%.3fms  p99=%.3fms\n", $s[intdiv($n, 2)], $s[(int)($n * 0.99)]);

    // Redis DEL 一个来回
    $n = 1000; $s = [];
    for ($i = 0; $i < $n; $i++) { $t = microtime(true); $r->del(cache_key()); $s[] = (microtime(true) - $t) * 1000; }
    sort($s);
    printf("写侧 DEL 缓存一个来回                      : p50=%.3fms  p99=%.3fms\n", $s[intdiv($n, 2)], $s[(int)($n * 0.99)]);

    $r->del(cache_key());
    echo "\n窗口宽度 = 上面这几个数的组合：\n";
    echo "  先删缓存   : DEL 之后、UPDATE 提交之前，任何一次读回填进去的都是旧值 → 窗口 = 整个 UPDATE 耗时\n";
    echo "  先更新库再删: 读必须「读到旧值」且「回填落在 DEL 之后」→ 窗口 = 回填耗时 − (UPDATE+DEL) 耗时，正常是负数\n";
    exit;
}

// ---------------------------------------------------------------- 确定性竞态
if ($cmd === 'race') {
    $r = rconn(); $pdo = dbconn();
    $order = $arg ?: 'del-first';
    $r->del(cache_key());
    $pdo->exec('UPDATE item SET v=0 WHERE id=1');
    $r->del(cache_key());

    echo "===== 确定性交错：顺序 = {$order} =====\n";
    echo "（用绝对时刻调度对齐两个真实进程，不是「先跑 A 再 sleep 再跑 B」）\n\n";
    $base = ms() + 1500;
    $pdo = null; $r = null;                          // fork 前断开父进程自己的连接
    fork_all(2, function ($who) use ($base, $order, $P) {
        $r = rconn(); $pdo = dbconn();
        if ($who === 0) {                                  // 写请求
            at($base, 0);
            if ($order === 'del-first') {
                $r->del(cache_key());
                echo "  W  t=   0ms  DEL 缓存\n";
                at($base, 300);
                $t = microtime(true); item_set($pdo, 100);
                printf("  W  t= 300ms  UPDATE item SET v=100  (耗时 %.2fms)\n", (microtime(true) - $t) * 1000);
            } elseif ($order === 'del-first-double') {
                $r->del(cache_key());
                echo "  W  t=   0ms  DEL 缓存（第一次）\n";
                at($base, 300);
                item_set($pdo, 100);
                echo "  W  t= 300ms  UPDATE item SET v=100\n";
                at($base, 900);
                $r->del(cache_key());
                echo "  W  t= 900ms  DEL 缓存（第二次，延迟 600ms）\n";
            } else {                                       // update-first
                at($base, 100);
                item_set($pdo, 100);
                echo "  W  t= 100ms  UPDATE item SET v=100\n";
                at($base, 110);
                $r->del(cache_key());
                echo "  W  t= 110ms  DEL 缓存\n";
            }
        } else {                                           // 读请求
            if ($order === 'update-first') {
                at($base, 0);
                $c = $r->get(cache_key());
                echo "  R  t=   0ms  GET 缓存 → " . ($c === false ? 'miss' : $c) . "\n";
                at($base, 5);
                $v = item_get($pdo);
                echo "  R  t=   5ms  SELECT → v={$v}\n";
                at($base, 250);
                $r->setex(cache_key(), 30, (string)$v);
                echo "  R  t= 250ms  SET 缓存 = {$v}（模拟回填慢：序列化 / GC / 网络抖动）\n";
            } else {
                at($base, 20);
                $c = $r->get(cache_key());
                echo "  R  t=  20ms  GET 缓存 → " . ($c === false ? 'miss（刚被 W 删掉）' : $c) . "\n";
                at($base, 25);
                $v = item_get($pdo);
                echo "  R  t=  25ms  SELECT → v={$v}\n";
                at($base, 230);
                $r->setex(cache_key(), 30, (string)$v);
                echo "  R  t= 230ms  SET 缓存 = {$v}\n";
            }
        }
    });

    at($base, 1400);
    $r = rconn(); $pdo = dbconn();                   // fork 后重连
    $db = item_get($pdo);
    $cv = $r->get(cache_key());
    printf("\n  终局：DB.v=%d   cache=%s\n", $db, $cv === false ? '(空)' : $cv);
    if ($cv !== false && (int)$cv !== $db) echo "  → 脏数据：缓存里是旧值，而且它不会自己好\n";
    elseif ($cv === false)                 echo "  → 缓存是空的，下一次读会从库里取到新值（没有脏数据）\n";
    else                                   echo "  → 一致\n";

    // 再读一次，看缓存能不能自己恢复
    $v2 = $r->get(cache_key());
    if ($v2 === false) { $v2 = item_get($pdo); $r->setex(cache_key(), 30, (string)$v2); }
    printf("  紧接着一次正常读：cache=%d  DB=%d  %s\n", (int)$v2, $db, (int)$v2 === $db ? '一致' : '仍然脏');

    $r->del(cache_key());
    $pdo->exec('UPDATE item SET v=0 WHERE id=1');
    echo "\n";
    exit;
}

// ---------------------------------------------------------------- 并发压力下的脏数据率
if ($cmd === 'stress') {
    $order = $arg ?: 'del-first';
    $NREAD = 20; $RUN = 4000; $RDELAY = 0;
    $r = rconn(); $pdo = dbconn();
    prefix_cleanup($r, $P);
    $pdo->exec('UPDATE item SET v=0 WHERE id=1');
    $r->del(cache_key());

    echo "===== 并发压力：1 个写 + {$NREAD} 个读，跑 {$RUN}ms，顺序 = {$order} =====\n";
    echo "脏数据判定（写侧）：写请求做完 UPDATE+DEL 之后 2ms 再 GET 缓存，\n";
    echo "  缓存里非空且 < 刚写入的版本号 → 有个读请求把旧值回填进去了，且这次 DEL 没能清掉它。\n";
    echo "  这是下界：回填发生在检查之后的那些没算进来。\n\n";

    $base = ms() + 1500;
    $r = null; $pdo = null;                          // fork 前断开父进程自己的连接
    fork_all($NREAD + 1, function ($who) use ($base, $order, $NREAD, $RUN, $RDELAY, $P) {
        $r = rconn(); $pdo = dbconn();
        if ($who === 0) {                                       // 写
            at($base, 0);
            $writes = 0; $dirty = 0; $stop = ms() + $RUN;
            $v = 0;
            while (ms() < $stop) {
                $v++;
                if ($order === 'del-first') {
                    $r->del(cache_key());
                    item_set($pdo, $v);
                } else {
                    item_set($pdo, $v);
                    $r->del(cache_key());
                }
                usleep(2000);                                   // 留出在读请求回填的落地时间
                $cv = $r->get(cache_key());
                if ($cv !== false && (int)$cv < $v) $dirty++;
                $writes++;
            }
            $r->set("{$P}stress:writes", $writes);
            $r->set("{$P}stress:dirty", $dirty);
        } else {                                                // 读
            at($base, 0);
            $reads = 0; $stop = ms() + $RUN;
            while (ms() < $stop) {
                $c = $r->get(cache_key());
                if ($c === false) {
                    $val = item_get($pdo);
                    if ($RDELAY) usleep($RDELAY);
                    $r->setex(cache_key(), 30, (string)$val);
                }
                $reads++;
            }
            $r->incrBy("{$P}stress:reads", $reads);
        }
    });

    $r = rconn(); $pdo = dbconn();                   // fork 后重连
    $w = (int)$r->get("{$P}stress:writes");
    $d = (int)$r->get("{$P}stress:dirty");
    $rd = (int)$r->get("{$P}stress:reads");
    printf("写请求 %d 次，读请求 %d 次\n", $w, $rd);
    printf("写完之后缓存里留着旧值的次数：%d  (%.2f%% of writes)\n", $d, $w ? $d * 100 / $w : 0);
    prefix_cleanup($r, $P);
    $pdo->exec('UPDATE item SET v=0 WHERE id=1');
    exit;
}

fwrite(STDERR, "用法: q29-consistency.php setup|window|race <order>|stress <order>\n");
exit(1);
