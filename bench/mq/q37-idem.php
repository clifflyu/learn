<?php
/**
 * Q37 幂等：四种防护方案的并发竞态实测
 *
 * 用法：docker exec learn-php php /app/bench/mq/q37-idem.php <scenario> [workers] [rounds]
 *   scenario: noguard | uniq | upsert | redis | naive
 *
 * 原理：所有 worker 先做完「前置检查」，再用 Redis 计数器做屏障（spin-wait）对齐，
 *       然后同时执行写操作 —— 把 SELECT-then-INSERT 的竞态窗口拉到最大。
 *       没有屏障的话，进程调度会让多数请求串行通过，测不出重复。
 */
declare(strict_types=1);

const MYSQL_DSN  = 'mysql:host=learn-mysql;port=3306;dbname=q37_idem;charset=utf8mb4';
const MYSQL_USER = 'root';
const MYSQL_PASS = 'root';
const REDIS_HOST = 'learn-redis';
const REDIS_PORT = 6379;
const RPREFIX    = 'q37:';

$TABLE = [
    'noguard' => 't_noguard',
    'uniq'    => 't_uniq',
    'upsert'  => 't_upsert',
    'redis'   => 't_redis',
    'naive'   => 't_redis',   // 复用同一张表，演示错误的 Redis 用法
];

function db(): PDO
{
    return new PDO(MYSQL_DSN, MYSQL_USER, MYSQL_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function rds(): Redis
{
    $r = new Redis();
    $r->connect(REDIS_HOST, REDIS_PORT, 2.0);
    return $r;
}

/** 屏障：所有 worker 都到了才一起放行 */
function barrier(Redis $r, string $key, int $workers): void
{
    $r->incr($key);
    $deadline = microtime(true) + 10.0;
    while ((int) $r->get($key) < $workers) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "barrier timeout\n");
            break;
        }
        usleep(200);
    }
}

// ---------------------------------------------------------------- worker

function worker(string $scenario, int $wid, int $workers, int $rounds, string $run): void
{
    $pdo = db();
    $r   = rds();

    for ($round = 0; $round < $rounds; $round++) {
        $otn    = "OTN-{$run}-{$round}";
        $amount = 99.00;

        // ---- 前置检查（幂等实现里最常见的「先查后写」） ----
        if ($scenario === 'noguard') {
            $st = $pdo->prepare('SELECT id FROM t_noguard WHERE out_trade_no = ?');
            $st->execute([$otn]);
            $exists = $st->fetchColumn();
        } elseif ($scenario === 'uniq') {
            $st = $pdo->prepare('SELECT id FROM t_uniq WHERE out_trade_no = ?');
            $st->execute([$otn]);
            $exists = $st->fetchColumn();
        } else {
            $exists = false;
        }

        barrier($r, RPREFIX . "barrier:{$run}:{$round}", $workers);

        $outcome = 'unknown';
        $t0      = microtime(true);
        try {
            switch ($scenario) {
                case 'noguard':
                    if ($exists === false) {
                        $st = $pdo->prepare(
                            'INSERT INTO t_noguard (out_trade_no,user_id,amount,status,created_at) VALUES (?,?,?,1,NOW(3))'
                        );
                        $st->execute([$otn, 1000 + $wid, $amount]);
                        $outcome = 'inserted';
                    } else {
                        $outcome = 'skipped';
                    }
                    break;

                case 'uniq':
                    if ($exists === false) {
                        $st = $pdo->prepare(
                            'INSERT INTO t_uniq (out_trade_no,user_id,amount,status,created_at) VALUES (?,?,?,1,NOW(3))'
                        );
                        $st->execute([$otn, 1000 + $wid, $amount]);
                        $outcome = 'inserted';
                    } else {
                        $outcome = 'skipped';
                    }
                    break;

                case 'upsert':
                    // 不做前置查询，一条 SQL 同时承担「插入」和「冲突检测」
                    $st = $pdo->prepare(
                        'INSERT INTO t_upsert (out_trade_no,user_id,amount,status,pay_count,created_at)
                         VALUES (?,?,?,1,0,NOW(3))
                         ON DUPLICATE KEY UPDATE updated_at = NOW(3)'
                    );
                    $st->execute([$otn, 1000 + $wid, $amount]);
                    // MySQL: 1=新插入  2=冲突且字段有变化  0=冲突但无变化
                    $outcome = 'rowcount=' . $st->rowCount();
                    break;

                case 'redis':
                    // 原子 SET NX EX —— 正确用法
                    $ok = $r->set(RPREFIX . "tok:{$otn}", (string) $wid, ['nx', 'ex' => 60]);
                    if ($ok) {
                        $st = $pdo->prepare(
                            'INSERT INTO t_redis (out_trade_no,user_id,amount,status,created_at) VALUES (?,?,?,1,NOW(3))'
                        );
                        $st->execute([$otn, 1000 + $wid, $amount]);
                        $outcome = 'inserted';
                    } else {
                        $outcome = 'rejected';
                    }
                    break;

                case 'naive':
                    // EXISTS + SET —— 错误用法，两步不原子
                    if (!$r->exists(RPREFIX . "naive:{$otn}")) {
                        usleep(1500);                       // 放大窗口，模拟网络/IO 延迟
                        $r->set(RPREFIX . "naive:{$otn}", '1', 60);
                        $st = $pdo->prepare(
                            'INSERT INTO t_redis (out_trade_no,user_id,amount,status,created_at) VALUES (?,?,?,1,NOW(3))'
                        );
                        $st->execute([$otn, 1000 + $wid, $amount]);
                        $outcome = 'inserted';
                    } else {
                        $outcome = 'rejected';
                    }
                    break;
            }
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            $outcome = $code === 1062 ? 'dup1062' : "err{$code}";
        } catch (Throwable $e) {
            $outcome = 'err:' . $e->getMessage();
        }
        $dt = microtime(true) - $t0;

        $r->lPush(RPREFIX . "result:{$run}", json_encode(
            ['w' => $wid, 'round' => $round, 'outcome' => $outcome, 'ms' => round($dt * 1000, 3)],
            JSON_UNESCAPED_UNICODE
        ));
    }
}

// ---------------------------------------------------------------- main

$scenario = $argv[1] ?? 'noguard';
$workers  = (int) ($argv[2] ?? 16);
$rounds   = (int) ($argv[3] ?? 8);

if (!isset($TABLE[$scenario])) {
    fwrite(STDERR, "unknown scenario: {$scenario}\n");
    exit(1);
}
$table = $TABLE[$scenario];

$run = date('His') . '-' . substr((string) getmypid(), -4);
$r   = rds();

// 清理本 run 的 key，清空目标表
foreach ($r->keys(RPREFIX . '*') as $k) {
    if (str_contains($k, $run) === false) {
        continue;
    }
    $r->del($k);
}
$r->del(RPREFIX . "result:{$run}");
$pdo = db();
$pdo->exec("TRUNCATE TABLE {$table}");
// 关键：fork 前必须断开。子进程退出时会对继承来的 socket 发 COM_QUIT，
// 把父进程的连接一起杀掉（MySQL server has gone away）。
$pdo = null;

echo "=== Q37 幂等并发实测 | 方案={$scenario} 表={$table} 并发进程={$workers} 轮次={$rounds} 总请求=" . ($workers * $rounds) . " ===\n";

$t0 = microtime(true);
$pids = [];
for ($i = 0; $i < $workers; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");
        exit(1);
    }
    if ($pid === 0) {
        // 清掉子进程继承的父连接，避免 fork 后共用 socket
        worker($scenario, $i, $workers, $rounds, $run);
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}
$wall = microtime(true) - $t0;

$pdo = db();   // fork 之后再建父进程自己的连接

// ---- 汇总 ----
$raw = $r->lRange(RPREFIX . "result:{$run}", 0, -1);
$byOutcome = [];
$lat = [];
foreach ($raw as $j) {
    $d = json_decode($j, true);
    $byOutcome[$d['outcome']] = ($byOutcome[$d['outcome']] ?? 0) + 1;
    $lat[] = $d['ms'];
}
ksort($byOutcome);
sort($lat);
$p = fn(float $q) => $lat ? $lat[(int) floor($q * (count($lat) - 1))] : 0;

$totalReq = $workers * $rounds;
$rows  = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
$distinct = (int) $pdo->query("SELECT COUNT(DISTINCT out_trade_no) FROM {$table}")->fetchColumn();
$badOtns  = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT out_trade_no FROM {$table} GROUP BY out_trade_no HAVING COUNT(*) > 1) x")->fetchColumn();

echo "\n--- 客户端视角（{$totalReq} 次请求的返回） ---\n";
foreach ($byOutcome as $k => $v) {
    printf("  %-14s %6d\n", $k, $v);
}
printf("  合计            %6d\n", array_sum($byOutcome));

echo "\n--- 数据库视角 ---\n";
printf("  业务请求数（去重后应有）   %d\n", $rounds);
printf("  实际落库行数               %d\n", $rows);
printf("  被写重复的 out_trade_no 数 %d\n", $badOtns);
printf("  重复行数（脏数据）         %d\n", max(0, $rows - $rounds));

echo "\n--- 耗时 ---\n";
printf("  总墙钟 %.3f s   单请求 p50=%.3f ms  p95=%.3f ms  max=%.3f ms\n",
    $wall, $p(0.50), $p(0.95), $p(0.99));

// 清理
foreach ($r->keys(RPREFIX . '*') as $k) {
    if (str_contains($k, $run)) {
        $r->del($k);
    }
}
