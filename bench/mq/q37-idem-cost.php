<?php
/**
 * Q37 补充：四种幂等防护的「单次开销」对比（单进程串行，2000 次/档）
 * 并发竞态测的是正确性，这里测的是代价。
 *
 * 用法：docker exec learn-php php /app/bench/mq/q37-idem-cost.php
 */
declare(strict_types=1);

const DSN = 'mysql:host=learn-mysql;port=3306;dbname=q37_idem;charset=utf8mb4';
const RPREFIX = 'q37:cost:';
const N = 2000;

$pdo = new PDO(DSN, 'root', 'root', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$r = new Redis();
$r->connect('learn-redis', 6379, 2.0);
foreach ($r->keys(RPREFIX . '*') as $k) {
    $r->del($k);
}
foreach (['t_noguard', 't_uniq', 't_upsert', 't_redis'] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t}");
}

$run = (string) time();
$results = [];

/** 每档跑 N 次，每次都换一个 out_trade_no（模拟正常流量，不是重复流量） */
function bench(string $name, callable $fn): void
{
    global $results, $pdo;
    // 预热 200 次
    for ($i = 0; $i < 200; $i++) {
        $fn("WARM-{$i}");
    }
    $lat = [];
    $t0 = microtime(true);
    for ($i = 0; $i < N; $i++) {
        $a = microtime(true);
        $fn("B-{$name}-{$i}");
        $lat[] = (microtime(true) - $a) * 1e6;
    }
    $total = microtime(true) - $t0;
    sort($lat);
    $results[$name] = [
        'qps'   => N / $total,
        'us_op' => $total / N * 1e6,
        'p50'   => $lat[(int) (0.50 * (N - 1))],
        'p99'   => $lat[(int) (0.99 * (N - 1))],
    ];
    printf("  %-34s %10.0f ops/s  %8.1f us/op  p50=%7.1f p99=%7.1f us\n",
        $name, $results[$name]['qps'], $results[$name]['us_op'], $results[$name]['p50'], $results[$name]['p99']);
}

$stPlain = $pdo->prepare('INSERT INTO t_noguard (out_trade_no,user_id,amount,status,created_at) VALUES (?,1,1.00,1,NOW(3))');
$sel     = $pdo->prepare('SELECT id FROM t_noguard WHERE out_trade_no = ?');
$stUniq  = $pdo->prepare('INSERT INTO t_uniq (out_trade_no,user_id,amount,status,created_at) VALUES (?,1,1.00,1,NOW(3))');
$stUps   = $pdo->prepare('INSERT INTO t_upsert (out_trade_no,user_id,amount,status,pay_count,created_at)
                          VALUES (?,1,1.00,1,0,NOW(3)) ON DUPLICATE KEY UPDATE updated_at = NOW(3)');
$stRedis = $pdo->prepare('INSERT INTO t_redis (out_trade_no,user_id,amount,status,created_at) VALUES (?,1,1.00,1,NOW(3))');

echo "=== Q37 幂等方案开销对比（单进程串行，每档 " . N . " 次，MySQL 8.4.11 / Redis 7.4.11 / PHP 8.4.25） ===\n\n";

bench('A 裸 INSERT（无防护基线）', function ($otn) use ($stPlain) {
    $stPlain->execute([$otn]);
});

bench('B SELECT 先查后写（无约束）', function ($otn) use ($sel, $stPlain) {
    $sel->execute([$otn]);
    if ($sel->fetchColumn() === false) {
        $stPlain->execute([$otn]);
    }
});

bench('C INSERT + 唯一索引捕获 1062', function ($otn) use ($stUniq) {
    try {
        $stUniq->execute([$otn]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
    }
});

bench('D INSERT ... ON DUPLICATE KEY UPDATE', function ($otn) use ($stUps) {
    $stUps->execute([$otn]);
});

bench('E Redis SETNX + INSERT', function ($otn) use ($r, $stRedis) {
    if ($r->set(RPREFIX . $otn, '1', ['nx', 'ex' => 3600])) {
        $stRedis->execute([$otn]);
    }
});

bench('F Redis SETNX 纯拦截（不落库）', function ($otn) use ($r) {
    $r->set(RPREFIX . $otn, '1', ['nx', 'ex' => 3600]);
});

echo "\n--- 相对基线倍数（按 us/op） ---\n";
$base = $results['A 裸 INSERT（无防护基线）']['us_op'];
foreach ($results as $k => $v) {
    printf("  %-34s %.2fx\n", $k, $v['us_op'] / $base);
}

foreach ($r->keys(RPREFIX . '*') as $k) {
    $r->del($k);
}
