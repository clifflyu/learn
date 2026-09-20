<?php
/**
 * Q44 实测②：被排查的那个「接口」。
 *
 * 它做了三件事，分别代表三类耗时来源：
 *   1. 自身逻辑（纯 PHP 计算）
 *   2. 查一次 MySQL
 *   3. 调一次下游 HTTP 接口（可选）
 * 每段的耗时都记下来，回写在响应头和响应体里 —— 这就是「分段计时」，
 * 排查第一步要的就是它，因为从客户端只能看到一个总时间。
 *
 * 用法:
 *   curl 'http://localhost:9311/bench/ops/q44-api.php'                    # 只有本机和 DB
 *   curl 'http://localhost:9311/bench/ops/q44-api.php?down=800'           # 下游慢 800ms
 *   curl -sD - -o /dev/null 'http://localhost:9311/bench/ops/q44-api.php?down=800'
 */

$down = max(0, (int) ($_GET['down'] ?? 0));
$t    = [];
$t0   = microtime(true);

// ── 1. 自身逻辑：假装算了点东西 ──────────────────────────────
usleep(3000);
$sum = 0;
for ($i = 0; $i < 20000; $i++) {
    $sum += $i % 7;
}
$t['self'] = (microtime(true) - $t0) * 1000;

// ── 2. MySQL：一次走索引的点查，正常应该 < 5ms ────────────────
$s = microtime(true);
try {
    $pdo = new PDO('mysql:host=learn-mysql;port=3306;dbname=q44_slow;charset=utf8mb4', 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $st = $pdo->prepare('SELECT id, amount FROM orders WHERE id = ?');
    $st->execute([1000]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $row = ['err' => $e->getMessage()];
}
$t['db'] = (microtime(true) - $s) * 1000;

// ── 3. 下游 HTTP：慢的就是它 ──────────────────────────────────
$t['downstream'] = 0.0;
$t['http_code']  = 0;
if ($down > 0) {
    $s = microtime(true);
    $ch = curl_init('http://localhost/bench/ops/q44-downstream.php?ms=' . $down);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        // 下游慢，连接本身不慢；把连接超时和读取超时分开设，健康检查才有意义
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    $t['http_code']  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $t['downstream'] = (microtime(true) - $s) * 1000;
}

$t['total'] = (microtime(true) - $t0) * 1000;

// 分段计时回写响应头：生产上更常见的做法是写进 trace / access log，
// 这里放在头里，方便用 curl -D- 一眼看到
header('X-Time-Self-Ms: '       . round($t['self'], 1));
header('X-Time-DB-Ms: '         . round($t['db'], 1));
header('X-Time-Downstream-Ms: ' . round($t['downstream'], 1));
header('X-Time-Total-Ms: '      . round($t['total'], 1));

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'pid'        => getmypid(),
    'db_row'     => $row,
    'timing_ms'  => array_map(fn($v) => round($v, 1), $t),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
