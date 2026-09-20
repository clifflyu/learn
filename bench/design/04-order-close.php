<?php
/**
 * Q47 订单超时自动关闭：三种触发方案 + 防重复关闭
 *
 * 用法: docker exec learn-php php /app/bench/design/04-order-close.php
 *
 * 第一部分：什么时候触发关闭
 *   A) 定时轮询扫表                 —— 精度 = 轮询间隔，代价 = 每秒固定扫表
 *   B) Redis 过期键 + keyspace 通知 —— 精度受 Redis 过期策略影响，且 pub/sub 不持久
 *   C) 延迟队列（ZSet）             —— 精度 = 轮询间隔，但只扫「到期的那几个」
 *
 * 第二部分：怎么保证不重复关闭（4 个 worker 抢同一批订单）
 *   D1) 无保护：SELECT 出来直接 UPDATE，status 不守卫
 *   D2) 状态机：UPDATE ... WHERE id=? AND status=0，看影响行数
 *   D3) 唯一约束：close_log.uk_order 兜底，重复的 INSERT 直接 1062
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=q46_seckill;charset=utf8mb4';
$pdoOpt = [
    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];
$db = fn() => new PDO($dsn, 'root', 'root', $pdoOpt);

$redisHost = 'learn-redis';
$red = function () use ($redisHost) {
    $r = new Redis();
    $r->connect($redisHost, 6379, 2.0);
    $r->setOption(Redis::OPT_READ_TIMEOUT, 10.0);
    return $r;
};
$GLOBALS['busy_retry'] = 0;
function rc(callable $f, int $tries = 200) {
    // 本机 Redis 是共享的：别的长 Lua 脚本会把 redis-server 卡成 BUSY。
    // 退避必须短（50 ms），而且要把重试次数报出来 —— 否则重试等待会被算进被测延迟里，
    // 我第一版用 500 ms 退避，ZSet 队列的关闭延迟直接从 ~90 ms 被抬到 1440 ms。
    for ($i = 0; $i < $tries; $i++) {
        try { return $f(); }
        catch (RedisException $e) { $GLOBALS['busy_retry']++; usleep(50000); }
    }
    throw new RuntimeException('Redis 持续 busy');
}

$PFX       = 'q47:';
$N_ORDERS  = 100;
$EXPIRE_MS = 1000;                     // 下单后 1 秒到期
$EXPIRE_US = $EXPIRE_MS * 1000;        // MySQL 没有 MILLISECOND 单位，统一换算成微秒
$F = '/tmp/q47-out.tsv';

/** 起 N 个并发 worker，每个跑 $fn($idx)，结果按行收回 */
function workers(int $n, callable $fn, string $file): array
{
    @unlink($file);
    $pids = []; $relays = [];
    for ($i = 0; $i < $n; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid  = pcntl_fork();
        if ($pid === -1) { fclose($pair[0]); fclose($pair[1]); break; }
        if ($pid === 0) {
            fclose($pair[1]);
            fread($pair[0], 1);
            fclose($pair[0]);
            try { $fn($i); } catch (Throwable $e) { file_put_contents($file, "ERR\t" . substr($e->getMessage(), 0, 100) . "\n", FILE_APPEND); }
            exit(0);
        }
        fclose($pair[0]);
        $relays[] = $pair[1];
        $pids[]   = $pid;
    }
    foreach ($relays as $w) { fwrite($w, 'x'); fclose($w); }
    foreach ($pids as $p) { pcntl_waitpid($p, $st); }
    return @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
}

function resetOrders(PDO $p, int $n, int $expireMs, string $status = '0'): void
{
    $expireUs = $expireMs * 1000;         // MySQL 没有 MILLISECOND 单位，统一换算成微秒
    $p->exec('TRUNCATE TABLE close_attempt');
    $p->exec('TRUNCATE TABLE close_log');
    $p->exec('TRUNCATE TABLE orders');
    $p->exec("INSERT INTO orders (goods_id, user_id, status, created_at, expire_at)
              WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < {$n})
              SELECT 1, n, {$status}, NOW(3), NOW(3) + INTERVAL {$expireUs} MICROSECOND FROM seq");
}

/** 关闭延迟（毫秒）的分布，直接让 MySQL 算，避免 PHP 与 MySQL 时钟口径不一致 */
function delays(PDO $p, string $table = 'close_attempt'): array
{
    $sql = "SELECT
              COUNT(*)                                     AS cnt,
              ROUND(AVG(TIMESTAMPDIFF(MICROSECOND, o.expire_at, a.created_at))/1000, 1) AS avg_ms,
              ROUND(MIN(TIMESTAMPDIFF(MICROSECOND, o.expire_at, a.created_at))/1000, 1) AS min_ms,
              ROUND(MAX(TIMESTAMPDIFF(MICROSECOND, o.expire_at, a.created_at))/1000, 1) AS max_ms
            FROM {$table} a JOIN orders o ON o.id = a.order_id";
    return $p->query($sql)->fetch(PDO::FETCH_ASSOC);
}

$p = $db();
$ver = $p->query('SELECT VERSION()')->fetchColumn();
echo "MySQL {$ver} / PHP ", PHP_VERSION, " / {$N_ORDERS} 张订单，下单后 {$EXPIRE_MS} ms 到期\n";
$p = null;

// ============================================================
// A) 定时轮询扫表
//    到期时刻取 1.5 s，故意让它落在两次轮询之间，这样「关闭延迟 ≈ 轮询间隔」才看得出来。
//    第一版把到期写成 INTERVAL 1000 MICROSECOND（=1 ms，MySQL 没有 MILLISECOND 单位），
//    结果第一次轮询就把订单全关了，延迟只有 21 ms，什么都没测出来。
// ============================================================
foreach ([1000, 100] as $intervalMs) {
    $expireA = 1500;
    echo "\n", str_repeat('=', 78), "\n";
    echo "A) 定时轮询扫表，间隔 {$intervalMs} ms（订单下单后 {$expireA} ms 到期）\n";
    echo str_repeat('=', 78), "\n";

    $p = $db();
    resetOrders($p, $N_ORDERS, $expireA);
    $p->exec("FLUSH STATUS");
    $before = (int)$p->query("SHOW SESSION STATUS LIKE 'Handler_read_next'")->fetch(PDO::FETCH_ASSOC)['Value'];

    $tEnd = microtime(true) + 4.0;
    $polls = 0; $closed = 0;
    while (microtime(true) < $tEnd) {
        $polls++;
        $ids = $p->query("SELECT id FROM orders WHERE status=0 AND expire_at <= NOW(3) ORDER BY expire_at LIMIT 500")
                  ->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in = implode(',', $ids);
            $p->exec("UPDATE orders SET status=2, closed_at=NOW(3) WHERE id IN ({$in}) AND status=0");
            $p->exec("INSERT INTO close_attempt (order_id, source, worker, created_at)
                      SELECT id, 'poll', 'w1', NOW(3) FROM orders WHERE id IN ({$in})");
            $closed += count($ids);
        }
        usleep($intervalMs * 1000);
    }
    $after = (int)$p->query("SHOW SESSION STATUS LIKE 'Handler_read_next'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $d = delays($p);
    printf("  轮询 %d 次，关闭 %d 张\n", $polls, $closed);
    printf("  关闭延迟 ms：平均 %.1f / 最小 %.1f / 最大 %.1f\n", $d['avg_ms'], $d['min_ms'], $d['max_ms']);
    printf("  Handler_read_next：%d → %d，平均每次轮询扫 %.0f 行（索引 idx_status_expire）\n",
        $before, $after, $polls ? ($after - $before) / $polls : 0);
    $p = null;
}

// ============================================================
// B) Redis 过期键 + keyspace notification
// ============================================================
echo "\n", str_repeat('=', 78), "\n";
echo "B) Redis 过期键 + keyspace notification\n";
echo str_repeat('=', 78), "\n";
$oldNotify = rc(fn() => $red()->config('GET', 'notify-keyspace-events')['notify-keyspace-events']);
rc(fn() => $red()->config('SET', 'notify-keyspace-events', 'Ex'));
echo "  notify-keyspace-events: '{$oldNotify}' → 'Ex'（跑完恢复）\n";

$subFile = '/tmp/q47-sub.tsv';
@unlink($subFile);
$subPid = pcntl_fork();
if ($subPid === 0) {
    $r = new Redis();
    $r->connect($redisHost, 6379, 5.0);
    $r->setOption(Redis::OPT_READ_TIMEOUT, 30.0);
    $r->subscribe(['__keyevent@0__:expired'], function ($redis, $chan, $key) use ($subFile) {
        if (str_starts_with($key, 'q47:expire:')) {
            file_put_contents($subFile, $key . "\t" . (int)round(microtime(true) * 1000) . "\n", FILE_APPEND);
        }
    });
    exit(0);
}
usleep(600000);                                     // 等订阅建立
$expireAt = [];
for ($i = 1; $i <= $N_ORDERS; $i++) {
    $k = "{$PFX}expire:order:{$i}";
    rc(fn() => $red()->set($k, '1', ['px' => $EXPIRE_MS]));
    $expireAt[$i] = microtime(true) + $EXPIRE_MS / 1000;   // 记录理论到期时刻
}
$t0 = microtime(true);
echo "  已写入 {$N_ORDERS} 个 " . $EXPIRE_MS . " ms 过期的 key，开始等待通知…\n";
usleep(4200000);                                    // 等到期 + 观察窗口
posix_kill($subPid, SIGTERM);
pcntl_waitpid($subPid, $st);

$got = [];
foreach (@file($subFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    [$k, $ms] = explode("\t", $line);
    $id = (int)substr($k, strrpos($k, ':') + 1);
    if (isset($expireAt[$id])) $got[] = $ms / 1000 - $expireAt[$id];
}
sort($got);
$cnt = count($got);
if ($cnt) {
    printf("  收到通知 %d / %d 个\n", $cnt, $N_ORDERS);
    printf("  通知延迟 ms：最小 %.1f / 中位 %.1f / 最大 %.1f\n",
        $got[0] * 1000, $got[(int)floor(($cnt - 1) / 2)] * 1000, $got[$cnt - 1] * 1000);
} else {
    echo "  没收到任何通知（检查 notify-keyspace-events）\n";
}
rc(function () use ($red, $PFX) {
    $r = $red();
    foreach ($r->keys("{$PFX}expire:*") as $k) $r->del($k);
    return 1;
});
rc(fn() => $red()->config('SET', 'notify-keyspace-events', $oldNotify));
echo "  notify-keyspace-events 已恢复为 '{$oldNotify}'\n";

// ============================================================
// C) 延迟队列（ZSet）
// ============================================================
echo "\n", str_repeat('=', 78), "\n";
echo "C) 延迟队列：ZADD 到期时间戳，worker 只捞「到点的那几个」\n";
echo str_repeat('=', 78), "\n";
$p = $db();
resetOrders($p, $N_ORDERS, $EXPIRE_MS);
// 下单：把每个订单的到期毫秒时间戳写进 ZSet
// 【踩过的坑】score 一定不能用 `UNIX_TIMESTAMP(expire_at)*1000 + MICROSECOND(expire_at)/1000`：
// UNIX_TIMESTAMP() 作用在 DATETIME(3) 上**已经带小数秒**（返回 1789883542.878 这种），
// 再加一遍 MICROSECOND()/1000 就把小数算了两次，score 比真实到期时刻最多晚 999 ms。
// 症状是「关闭延迟稳定比轮询间隔大几百 ms」，而且每次跑的数值都不一样（取决于 NOW(3) 的小数部分），
// 极难怀疑到是 SQL 表达式的锅。*1000 之后 ROUND 一次就够。
$p->exec("UPDATE orders SET expire_at = NOW(3) + INTERVAL {$EXPIRE_US} MICROSECOND");
$rows = $p->query("SELECT id, ROUND(UNIX_TIMESTAMP(expire_at) * 1000) AS score FROM orders")
          ->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    rc(fn() => $red()->zAdd("{$PFX}delay", (float)$row['score'], (string)$row['id']));
}
$p = null;
printf("  ZSet 里压入 %d 个订单\n", count($rows));

// 先标定一次「空轮询间隔」：本机 Redis 是和别的任务共享的，别人跑长 Lua 脚本时
// 单次 round trip 会从 ~1 ms 涨到几百 ms，队列本身没问题但关闭延迟会被抬起来。
// 把这个数打出来，读的人才知道该赖机制还是赖环境。
$calib = (function () use ($red, $PFX) {
    $z = $red();
    $z->del("{$PFX}calib");
    rc(fn() => $z->zAdd("{$PFX}calib", 1, 'x'));
    $t = microtime(true);
    for ($i = 0; $i < 200; $i++) {
        rc(fn() => $z->zRangeByScore("{$PFX}calib", '-inf', '1', ['limit' => [0, 100]]));
        usleep(10000);
    }
    rc(fn() => $z->del("{$PFX}calib"));
    return (microtime(true) - $t) / 200 * 1000;
})();
printf("  【标定】空轮询一轮（ZRANGEBYSCORE + usleep(10ms)）实测 %.1f ms\n", $calib);

$runZSetWorker = function (int $idx) use ($db, $red, $PFX, $N_ORDERS) {
    $r = $db();
    $z = $red();                                       // 循环里复用同一条连接
    $tEnd = microtime(true) + 10.0;
    while (microtime(true) < $tEnd) {
        $now = (int)round(microtime(true) * 1000);
        $due = rc(fn() => $z->zRangeByScore("{$PFX}delay", '-inf', (string)$now, ['limit' => [0, 100]]));
        if (!$due) { usleep(10000); continue; }        // 没到点的就睡 10 ms
        foreach ($due as $oid) {
            // ZREM 的返回值就是「抢到没抢到」：同一成员只有一个 worker 能删掉它
            $mine = rc(fn() => $z->zRem("{$PFX}delay", (string)$oid));
            if (!$mine) continue;                      // 别人先抢走了
            $n = $r->exec("UPDATE orders SET status=2, closed_at=NOW(3) WHERE id=" . (int)$oid . " AND status=0");
            if ($n === 1) {
                $r->exec("INSERT INTO close_attempt (order_id, source, worker, created_at)
                          VALUES (" . (int)$oid . ", 'zset', 'w{$idx}', NOW(3))");
            }
        }
    }
};

$EXPIRE_ZSET_US = 4000000;        // ZSet 方案给 4 s 余量：压 100 个 ZADD + fork 都要时间。
                                  // 注意「关闭延迟」是拿 close_attempt.created_at 减 orders.expire_at 算的，
                                  // 只看时间戳不看挂钟，所以准备耗时**不会**被算进延迟里——
                                  // 之前怀疑是准备时间污染，是错的方向，真凶是上面 score 表达式的小数秒双算。
foreach ([1, 4] as $nWorkers) {
    $p = $db();
    resetOrders($p, $N_ORDERS, $EXPIRE_ZSET_US / 1000);
    $p->exec("UPDATE orders SET expire_at = NOW(3) + INTERVAL {$EXPIRE_ZSET_US} MICROSECOND");
    $rows = $p->query("SELECT id, ROUND(UNIX_TIMESTAMP(expire_at) * 1000) AS score FROM orders")
              ->fetchAll(PDO::FETCH_ASSOC);
    rc(fn() => $red()->del("{$PFX}delay"));
    $tPush = microtime(true);
    foreach ($rows as $row) { rc(fn() => $red()->zAdd("{$PFX}delay", (float)$row['score'], (string)$row['id'])); }
    $pushCost = microtime(true) - $tPush;
    $p = null;

    workers($nWorkers, $runZSetWorker, $F);
    $p = $db();
    $d = delays($p);
    printf("  【%d 个 worker】关闭 %s 张，ZSet 剩余 %d，Redis BUSY 重试 %d 次，压 ZSet 耗时 %.0f ms\n", $nWorkers,
        $d['cnt'], rc(fn() => $red()->zCard("{$PFX}delay")), $GLOBALS['busy_retry'], $pushCost * 1000);
    printf("                关闭延迟 ms：平均 %.1f / 最小 %.1f / 最大 %.1f\n",
        $d['avg_ms'], $d['min_ms'], $d['max_ms']);
    $p = null;
}

// ============================================================
// D) 不重复关闭：4 个 worker 抢同一批已到期的订单
// ============================================================
echo "\n", str_repeat('=', 78), "\n";
echo "D) 不重复关闭：4 个 worker 同时处理 {$N_ORDERS} 张已到期的订单\n";
echo str_repeat('=', 78), "\n";

$variants = [
    'D1 无保护（status 不守卫）' => function (PDO $p, int $id, int $w) {
        // 直接改，谁都能改，改几次都行
        $p->exec("UPDATE orders SET status=2, closed_at=NOW(3) WHERE id={$id}");
        $p->exec("INSERT INTO close_attempt (order_id, source, worker, created_at)
                  VALUES ({$id}, 'none', 'w{$w}', NOW(3))");
        return true;
    },
    'D2 状态机（WHERE status=0）' => function (PDO $p, int $id, int $w) {
        $n = $p->exec("UPDATE orders SET status=2, closed_at=NOW(3) WHERE id={$id} AND status=0");
        if ($n !== 1) return false;                    // 影响行数 0 = 别人已经关过了
        $p->exec("INSERT INTO close_attempt (order_id, source, worker, created_at)
                  VALUES ({$id}, 'fsm', 'w{$w}', NOW(3))");
        return true;
    },
    'D3 唯一约束（close_log.uk_order）' => function (PDO $p, int $id, int $w) {
        try {
            $p->exec("INSERT INTO close_log (order_id, source, worker, created_at)
                      VALUES ({$id}, 'uniq', 'w{$w}', NOW(3))");
        } catch (PDOException $e) {
            if ($e->errorInfo[1] === 1062) return false;   // Duplicate entry，别人关过了
            throw $e;
        }
        $p->exec("UPDATE orders SET status=2, closed_at=NOW(3) WHERE id={$id}");
        $p->exec("INSERT INTO close_attempt (order_id, source, worker, created_at)
                  VALUES ({$id}, 'uniq', 'w{$w}', NOW(3))");
        return true;
    },
];

foreach ($variants as $name => $closeOne) {
    $limit = $name[1] === '2' ? '' : '';               // 三个方案都捞同样多的候选
    $p = $db();
    resetOrders($p, $N_ORDERS, -1000);                 // expire_at 在过去 = 全部已到期
    $p->exec("FLUSH STATUS");
    $pBefore = $p->query('SELECT COUNT(*) FROM orders WHERE status = 2')->fetchColumn();
    $p = null;

    $t0 = microtime(true);
    $lines = workers(4, function (int $w) use ($db, $closeOne, $N_ORDERS, $F) {
        $p = $db();
        // 每个 worker 都把候选全捞出来 —— 4 个 worker 看到的是同一批 status=0 的订单
        $ids = $p->query("SELECT id FROM orders WHERE status=0 AND expire_at <= NOW(3) ORDER BY id LIMIT {$N_ORDERS}")
                  ->fetchAll(PDO::FETCH_COLUMN);
        $ok = 0; $rej = 0;
        foreach ($ids as $id) {
            $closeOne($p, (int)$id, $w) ? $ok++ : $rej++;
            usleep(200);                               // 让 4 个 worker 真正交错
        }
        file_put_contents($F, "STAT\t{$ok}\t{$rej}\n", FILE_APPEND);
    }, $F);
    $cost = microtime(true) - $t0;
    $okSum = 0; $rejSum = 0;
    foreach ($lines as $l) {
        $c = explode("\t", $l);
        if ($c[0] === 'STAT') { $okSum += (int)$c[1]; $rejSum += (int)$c[2]; }
    }

    $p = $db();
    $attempts  = $p->query('SELECT COUNT(*) FROM close_attempt')->fetchColumn();
    $closedRow = $p->query('SELECT COUNT(*) FROM orders WHERE status = 2')->fetchColumn();
    $dup       = $p->query('SELECT COUNT(*) FROM (SELECT order_id FROM close_attempt GROUP BY order_id HAVING COUNT(*) > 1) t')->fetchColumn();
    $maxPer    = $p->query('SELECT COALESCE(MAX(c),0) FROM (SELECT COUNT(*) c FROM close_attempt GROUP BY order_id) t')->fetchColumn();
    printf("  %-32s 生效关闭 %3d / 被幂等拦下 %3d / 实际关闭动作 %3d 次 / 重复关闭的订单 %3d 张 / 单张最多关 %d 次  (%.2f s)\n",
        $name, $okSum, $rejSum, $attempts, $dup, $maxPer, $cost);
    $p = null;
}

// 清理
rc(function () use ($red, $PFX) {
    $r = $red();
    foreach ($r->keys("{$PFX}*") as $k) $r->del($k);
    return 1;
});
echo "\n完成（q47:* 已清理）。\n";
