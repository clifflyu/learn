<?php
/**
 * Q45 实测：一个把 CPU 跑满的 PHP 脚本。
 *
 * 生产上它不是这样明摆着的——通常藏在一个「循环里做正则 / 序列化 / 加解密 /
 * 大数组排序」的接口里。这里用它来演示从「机器 CPU 高」一路定位到「哪一行代码」。
 *
 * 用法:
 *   docker exec learn-php php /app/bench/ops/q45-cpu-burn.php 30     # CLI 跑 30 秒
 *   curl 'http://localhost:9311/bench/ops/q45-cpu-burn.php?seconds=30'   # 走 FPM 跑
 *
 * 注意：CLI 和 FPM 两种跑法的定位路径不一样（见 q45-cpu-locate.sh）。
 */

$seconds = (int) ($argv[1] ?? $_GET['seconds'] ?? 10);
$seconds = max(1, min(120, $seconds));

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    // 别让 web 端的输出缓冲挡住 pid，方便一边跑一边抓它
    ob_implicit_flush(true);
}

printf("pid=%d  sapi=%s  cpu_burn for %d s\n", getmypid(), PHP_SAPI, $seconds);

$deadline = microtime(true) + $seconds;
$acc      = 0.0;
$rounds   = 0;

// 纯计算，没有 IO、没有 sleep：进程状态会一直是 R，utime 一直涨
while (microtime(true) < $deadline) {
    for ($i = 0; $i < 200000; $i++) {
        $acc += sqrt($i) * 1.000001;
    }
    $rounds++;
}

printf("rounds=%d  acc=%.2f\n", $rounds, $acc);
