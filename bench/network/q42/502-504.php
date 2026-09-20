<?php
// Q42 用的后端端点，靠 query string 控制行为：
//   ?mode=ok      立刻返回
//   ?mode=slow    睡 N 秒（默认 10），用来触发 504
//   ?mode=exit    直接 exit，不给 nginx 任何响应体
//   ?mode=boom    fatal error
declare(strict_types=1);

$mode = $_GET['mode'] ?? 'ok';
$n    = (int)($_GET['n'] ?? 10);

switch ($mode) {
    case 'slow':
        sleep($n);
        echo "slept {$n}s\n";
        break;
    case 'exit':
        // 不经过 FPM 的正常收尾，直接断连
        exit;
    case 'boom':
        throw new RuntimeException('boom');
    default:
        echo "ok\n";
}
