<?php
/**
 * Q46 秒杀防超卖：7 种库存扣减方案的真实并发实测
 *
 * 用法: docker exec learn-php php /app/bench/design/02-seckill.php [并发数] [库存] [轮数]
 *   默认: 100 并发 / 100 库存 / 每股 1 轮
 *
 * 设计要点
 *  - 每个并发请求 = 一个 pcntl_fork 出来的独立进程 + 独立 PDO/Redis 连接，
 *    尽量贴近「PHP-FPM 多 worker 各跑各的」的真实形态。
 *  - fork 前必须销毁父进程里的 PDO / Redis 对象：子进程退出时会析构这些连接，
 *    往共享的 socket 上写 COM_QUIT，会把父进程的连接一起搞死。这是 pcntl + PDO 的经典坑。
 *  - 并发放行闸门：**每个子进程一对独立的 socket**，子进程阻塞读 1 字节，父进程 fork 完
 *    全部子进程后逐个写入放行。踩过的坑：一开始让所有子进程阻塞读同一对 socket，PHP 的
 *    stream 层会做缓冲，第一个醒来的子进程把 N 字节全吞进自己的 buffer，其余的全卡到
 *    default_socket_timeout（60 s）——实测 makespan 直接飙到 60 s。每个子进程独占一对才准。
 *    子进程上报绝对发车时刻，父进程换算成相对放行点的偏移，派发窗口一眼可见。
 *  - orders 表的行数 = 卖出去多少，是唯一事实来源；stock 字段算错了它不会骗人。
 */

$concurrency = (int)($argv[1] ?? 100);
$initialQty  = (int)($argv[2] ?? 100);
$rounds      = (int)($argv[3] ?? 1);

$dsn  = 'mysql:host=learn-mysql;port=3306;dbname=q46_seckill;charset=utf8mb4';
$pdoOpt = [
    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];
$db = fn() => new PDO($dsn, 'root', 'root', $pdoOpt);

$redisHost = 'learn-redis';
$red = function () use ($redisHost) {
    $r = new Redis();
    $r->connect($redisHost, 6379, 2.0);
    $r->setOption(Redis::OPT_READ_TIMEOUT, 5.0);
    return $r;
};

$RESULT_FILE = '/tmp/q46-results.tsv';
$STOCK_KEY   = 'q46:stock:1';
$QUEUE_KEY   = 'q46:order:queue';
$KEY_PREFIX  = 'q46:';

// ============================================================
// 7 种方案。每个闭包收到 (PDO $db, Redis $r, int $uid)，返回 true=抢到 / false=没抢到
// $uid 每个请求唯一，满足 orders.uk_goods_user
// ============================================================

$variants = [];

// 1) 无防护-A：先 SELECT 判空，再「相对扣减」
//    判空在并发下完全无效——所有请求都在写入发生前读到了 stock>0
$variants['1 无防护A SELECT判空+相对扣减'] = [
    'kind' => 'db',
    'fn' => function ($db, $r, $uid) {
        $st = (int)$db->query('SELECT stock FROM stock WHERE goods_id = 1')->fetchColumn();
        if ($st <= 0) return false;
        $db->exec('UPDATE stock SET stock = stock - 1 WHERE goods_id = 1');
        $db->exec("INSERT INTO orders (goods_id, user_id, status, created_at) VALUES (1, {$uid}, 0, NOW(3))");
        return true;
    },
];

// 2) 无防护-B：先 SELECT 判空，再把「PHP 里算好的新值」写回去 —— 经典的丢失更新
//    100 个请求全都写 stock=99，看最后能卖出去多少
$variants['2 无防护B 丢失更新(PHP算值)'] = [
    'kind' => 'db',
    'fn' => function ($db, $r, $uid) {
        $st = (int)$db->query('SELECT stock FROM stock WHERE goods_id = 1')->fetchColumn();
        if ($st <= 0) return false;
        $new = $st - 1;
        $db->exec("UPDATE stock SET stock = {$new} WHERE goods_id = 1");
        $db->exec("INSERT INTO orders (goods_id, user_id, status, created_at) VALUES (1, {$uid}, 0, NOW(3))");
        return true;
    },
];

// 3) 悲观锁：SELECT ... FOR UPDATE 把行锁住，查和改在同一个事务里
$variants['3 悲观锁 SELECT FOR UPDATE'] = [
    'kind' => 'db',
    'fn' => function ($db, $r, $uid) {
        $db->beginTransaction();
        $st = (int)$db->query('SELECT stock FROM stock WHERE goods_id = 1 FOR UPDATE')->fetchColumn();
        if ($st <= 0) { $db->rollBack(); return false; }
        $db->exec('UPDATE stock SET stock = stock - 1 WHERE goods_id = 1');
        $db->exec("INSERT INTO orders (goods_id, user_id, status, created_at) VALUES (1, {$uid}, 0, NOW(3))");
        $db->commit();
        return true;
    },
];

// 4) 乐观锁：UPDATE ... WHERE stock > 0，用影响行数判断
//    单条语句自带原子性，不需要显式事务
$variants['4 乐观锁 WHERE stock>0'] = [
    'kind' => 'db',
    'fn' => function ($db, $r, $uid) {
        $n = $db->exec('UPDATE stock SET stock = stock - 1 WHERE goods_id = 1 AND stock > 0');
        if ($n === 0) return false;
        $db->exec("INSERT INTO orders (goods_id, user_id, status, created_at) VALUES (1, {$uid}, 0, NOW(3))");
        return true;
    },
];

// 5) Redis DECR：DECR 的返回值就是「扣减后的值」，天然一人一个，不会重复
$variants['5 Redis DECR 判定'] = [
    'kind' => 'redis',
    'fn' => function ($db, $r, $uid) {
        $left = $r->decr($GLOBALS['STOCK_KEY']);
        return $left >= 0;
    },
];

// 6) Redis Lua：原子「判空 + 扣减」，卖完就不再动 key
$LUA = <<<'LUA'
local s = tonumber(redis.call('GET', KEYS[1]) or '-1')
if s <= 0 then return 0 end
redis.call('DECR', KEYS[1])
return 1
LUA;
$variants['6 Redis Lua 判定'] = [
    'kind' => 'redis',
    'fn' => function ($db, $r, $uid) use ($LUA) {
        return $r->eval($LUA, [$GLOBALS['STOCK_KEY']], 1) == 1;
    },
];

// 7) Redis Lua 预扣 + 落到 Redis 队列（请求路径完全不碰 MySQL）
//    真正上生产的形态：同步部分只做 Redis 判定 + 入队，异步消费者再慢慢写库
$variants['7 Redis预扣+异步入队'] = [
    'kind' => 'redis',
    'fn' => function ($db, $r, $uid) use ($LUA) {
        if ($r->eval($LUA, [$GLOBALS['STOCK_KEY']], 1) != 1) return false;
        $r->lPush($GLOBALS['QUEUE_KEY'], (string)$uid);
        return true;
    },
];

// ============================================================
// 子进程执行体
// ============================================================
function childRun(string $kind, callable $fn, $relay, int $uid, string $file): void
{
    global $db, $red;
    // 栅栏：阻塞在自己的 socket 上等父进程放行。**不能自旋**——200 个子进程 usleep
    // 自旋会把 8 核打满，实测调度延迟飙到 2 秒级，并发就不再是并发了。
    fread($relay, 1);
    fclose($relay);
    $t0 = microtime(true);

    $ok = false; $err = '';
    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            $conn = $kind === 'db' ? $db() : $red();
            $ok   = (bool)$fn($conn, $conn, $uid);
            break;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // 本机 Redis 是共享的：别的长 Lua 脚本会把 redis-server 卡成 BUSY，
            // 这跟被测方案无关，等待后重试，最多 10 次（约 6 s）
            if ($kind === 'redis' && str_contains($msg, 'BUSY') && $attempt <= 10) {
                usleep(600000);
                continue;
            }
            $err = str_replace(["\n", "\t", '"'], ' ', substr($msg, 0, 120));
            break;
        }
    }
    $dt = microtime(true) - $t0;

    // 一行一条结果；single write < PIPE_BUF，O_APPEND 原子
    file_put_contents(
        $file,
        sprintf("%s\t%d\t%.6f\t%.6f\t%s\n", $kind, $ok ? 1 : 0, $t0, $dt, $err),  // t0 为绝对时刻，父进程再换算偏移
        FILE_APPEND | LOCK_EX
    );
    exit(0);
}

function resetState(PDO $p, Redis $r, int $qty): void
{
    $p->exec('TRUNCATE TABLE orders');
    $p->exec("UPDATE stock SET stock = {$qty}, sold = 0 WHERE goods_id = 1");
    $r->del($GLOBALS['STOCK_KEY']);
    $r->set($GLOBALS['STOCK_KEY'], (string)$qty);
    $r->del($GLOBALS['QUEUE_KEY']);
}

function pct(array $xs, float $q): float
{
    if (!$xs) return 0.0;
    sort($xs);
    return $xs[(int)floor($q * (count($xs) - 1))];
}

// ============================================================
// 跑一轮
// ============================================================
function runOnce(string $name, array $spec, int $concurrency, int $qty, int $round): array
{
    global $db, $red, $RESULT_FILE;

    // --- 复位（用临时连接，之后立刻销毁，绝不能带进 fork）---
    $p = $db(); $r = $red();
    resetState($p, $r, $qty);
    $p = null; $r = null;                 // 显式析构，避免子进程退出时误关父连接
    @unlink($RESULT_FILE);

    // 放行闸门：每个子进程独占一对 Unix socket
    $tFork = microtime(true);
    $pids = []; $relays = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);  // [read, write]
        $pid = pcntl_fork();
        if ($pid === -1) { fwrite(STDERR, "fork 失败于第 {$i} 个\n"); fclose($pair[0]); fclose($pair[1]); break; }
        if ($pid === 0) {
            fclose($pair[1]);
            // 子进程：uid 用 (轮次,序号) 保证唯一
            childRun($spec['kind'], $spec['fn'], $pair[0], $round * 1000000 + $i + 1, $RESULT_FILE);
        }
        fclose($pair[0]);           // 父进程只留写端
        $relays[] = $pair[1];
        $pids[]   = $pid;
    }
    $forkCost = microtime(true) - $tFork;

    // 全员就位，逐个放行
    foreach ($relays as $w) { fwrite($w, 'x'); fclose($w); }
    $startAt = microtime(true);

    // 父进程等全部子进程结束
    foreach ($pids as $pid) { pcntl_waitpid($pid, $st); }
    $tEnd = microtime(true);

    // --- 汇总（此时才重新开连接）---
    $p = $db();
    $r = $red();
    for ($t = 0; $t < 20; $t++) {          // 同样躲 BUSY
        try { $r->ping(); break; } catch (RedisException $e) { usleep(500000); }
    }

    $ok = 0; $fail = 0; $lat = []; $off = []; $errs = []; $makespan = 0.0;
    foreach (file($RESULT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$k, $o, $offset, $dt, $e] = array_pad(explode("\t", $line), 5, '');
        $o === '1' ? $ok++ : $fail++;
        $lat[] = (float)$dt;
        $off[] = (float)$offset - $startAt;              // 相对放行时刻的派发偏移
        $makespan = max($makespan, (float)$offset - $startAt + (float)$dt);
        if ($e !== '') { $errs[$e] = ($errs[$e] ?? 0) + 1; }
    }

    $finalStock = (int)$p->query('SELECT stock FROM stock WHERE goods_id = 1')->fetchColumn();
    $orders     = (int)$p->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    try {
        $redisLeft = $r->exists($GLOBALS['STOCK_KEY']) ? (int)$r->get($GLOBALS['STOCK_KEY']) : null;
    } catch (RedisException $e) {
        $redisLeft = null;               // 被别的脚本卡住，读不到就不报
    }

    // 异步队列方案：请求全部结束后，用 1 个消费者慢慢落库
    $drained = 0;
    if ($spec['kind'] === 'redis' && str_contains($name, '异步入队')) {
        $tDrain = microtime(true);
        while (true) {
            try {
                $uid = $r->rPop($GLOBALS['QUEUE_KEY']);
            } catch (RedisException $e) {
                usleep(500000);                 // BUSY，等对端脚本跑完
                continue;
            }
            if ($uid === null || $uid === false) break;
            $p->exec("INSERT IGNORE INTO orders (goods_id, user_id, status, created_at)
                      VALUES (1, " . (int)$uid . ", 0, NOW(3))");
            $drained++;
        }
        $drainCost = microtime(true) - $tDrain;
        $orders = (int)$p->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    } else {
        $drainCost = 0.0;
    }

    $p = null; $r = null;

    return [
        'name' => $name, 'ok' => $ok, 'fail' => $fail,
        'final' => $finalStock, 'orders' => $orders,
        'oversell' => $orders - $qty,
        // 账实差 = 剩余库存 −（总量 − 订单数）。正确方案恒为 0；
        // 丢失更新时库存字段没被减够，剩下的都是「还能被再卖一次」的幽灵库存
        'diff' => $finalStock - ($qty - $orders),
        'makespan' => $makespan, 'qps' => $makespan > 0 ? $ok / $makespan : 0,
        'p50' => pct($lat, 0.50) * 1000, 'p99' => pct($lat, 0.99) * 1000,
        'jitter' => $off ? (max($off) - min($off)) * 1000 : 0,
        'fork' => $forkCost * 1000, 'redisLeft' => $redisLeft, 'concurrency' => $concurrency,
        'drained' => $drained, 'drainCost' => $drainCost,
        'errs' => $errs,
        'wall' => $tEnd - $startAt,
    ];
}

// ============================================================
// 主流程
// ============================================================
$ver = (new PDO($dsn, 'root', 'root', $pdoOpt))->query('SELECT VERSION()')->fetchColumn();
echo "MySQL {$ver} / PHP ", PHP_VERSION, "\n";
echo "库存 {$initialQty} 件，并发 {$concurrency}，每股 {$rounds} 轮\n";

$header = sprintf(
    "%-34s %6s %6s %8s %7s %7s %7s %9s %8s %8s %9s %s",
    '方案', '成功', '失败', '最终库存', '订单数', '超卖', '账实差', '耗时(s)', 'p50(ms)', 'p99(ms)', 'QPS', '派发抖动(ms)'
);

foreach ($variants as $name => $spec) {
    echo "\n", str_repeat('=', 130), "\n{$name}\n", str_repeat('=', 130), "\n";
    echo $header, "\n", str_repeat('-', 130), "\n";
    for ($rd = 1; $rd <= $rounds; $rd++) {
        $x = runOnce($name, $spec, $concurrency, $initialQty, $rd);
        printf(
            "%-34s %6d %6d %8d %7d %7d %7d %9.3f %8.2f %8.2f %9.0f %8.2f\n",
            "第 {$rd} 轮", $x['ok'], $x['fail'], $x['final'], $x['orders'],
            $x['oversell'], $x['diff'], $x['makespan'], $x['p50'], $x['p99'], $x['qps'], $x['jitter']
        );
        printf("%-34s fork %d 个子进程耗时 %.0f ms\n", '', $x['concurrency'], $x['fork']);
        if ($x['redisLeft'] !== null) printf("%-34s Redis 剩余 = %d\n", '', $x['redisLeft']);
        if ($x['drained'])            printf("%-34s 异步落库 %d 单，耗时 %.3f s\n", '', $x['drained'], $x['drainCost']);
        foreach ($x['errs'] as $e => $c) printf("%-34s [err×%d] %s\n", '', $c, $e);
    }
}

echo "\n完成。\n";
