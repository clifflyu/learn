<?php
/**
 * Q4 实测：占住一个 worker 指定毫秒数，用来制造并发、打满进程池。
 *
 * 用法: curl 'http://localhost:9311/bench/fpm/slow.php?ms=2000'
 */

$ms = max(0, (int) ($_GET['ms'] ?? 1000));

usleep($ms * 1000);

header('Content-Type: text/plain; charset=utf-8');
printf("pid=%d  held %d ms\n", getmypid(), $ms);
