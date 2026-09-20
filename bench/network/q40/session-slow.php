<?php
// Q40 session 并发实验用的端点：持有 session 1 秒，再输出。
//
//   ?handler=files|redis   用哪种 session 存储（在 session_start 之前 ini_set）
//   ?lock=0|1|2            仅 redis，两种加锁的开启方式（实测 6.3.0 只有第二种生效）
//                            0 = 不加锁
//                            1 = save_path 里写 &lock=1   （phpredis 5.3 之前的写法）
//                            2 = ini_set redis.session.locking_enabled=1（现在的写法）
//   ?mode=hold|early       hold = 持锁到脚本结束；early = 读完立刻 session_write_close()
//   ?hold=1.0              模拟业务耗时（秒）
declare(strict_types=1);

$handler = $_GET['handler'] ?? 'files';
$lock    = (int)($_GET['lock'] ?? 0);
$mode    = $_GET['mode'] ?? 'hold';
$hold    = (float)($_GET['hold'] ?? 1.0);

if ($handler === 'redis') {
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', 'tcp://learn-redis:6379?timeout=2' . ($lock === 1 ? '&lock=1' : ''));
    if ($lock === 2) {
        ini_set('redis.session.locking_enabled', '1');
    }
} else {
    ini_set('session.save_handler', 'files');
}

session_name('Q40SESS');
session_start();

$t_start = microtime(true);
$_SESSION['counter'] = ($_SESSION['counter'] ?? 0) + 1;
$_SESSION['handler'] = $handler;

if ($mode === 'early') {
    // 把 session 数据写回并【释放锁】，之后的 sleep 不再阻塞其他请求
    session_write_close();
}

usleep((int)($hold * 1_000_000));

echo json_encode([
    'handler'   => ini_get('session.save_handler'),
    'mode'      => $mode,
    'lock'      => $lock,
    // 报告【真正生效】的 INI，而不是我请求的参数
    'locking_enabled' => ini_get('redis.session.locking_enabled'),
    'counter'   => $_SESSION['counter'] ?? null,
    'sid'       => session_id(),
    'started_at'=> round($t_start, 4),
    'ended_at'  => round(microtime(true), 4),
], JSON_UNESCAPED_UNICODE), "\n";
