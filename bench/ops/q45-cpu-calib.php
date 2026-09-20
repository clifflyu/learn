<?php
/**
 * Q45 实测：校准 /proc/<pid>/stat 的 CPU 时间读数。
 *
 * 「(Δutime+Δstime) ÷ 墙钟时间」对单线程进程最大只能是 1.0（一个核）。
 * 如果算出来 > 1，要么进程是多线程的，要么这个环境的 jiffy 记账本身有偏差 ——
 * 在这台 WSL2 主机上实测确实会超，所以 CPU% 只能当「相对排序」用，不能当绝对值。
 *
 * 用法: docker exec learn-php php /app/bench/ops/q45-cpu-calib.php 6
 */

$seconds = max(1, (int) ($argv[1] ?? 6));

/** 取 /proc/self/stat 的 utime/stime（jiffies）；comm 里可能有空格，先砍到最后一个 ")" */
function cpu_jiffies(): array
{
    $stat = file_get_contents('/proc/self/stat');
    $rest = substr($stat, strrpos($stat, ')') + 2);
    $f    = explode(' ', $rest);
    return [(int) $f[11], (int) $f[12]];      // utime, stime（去掉前缀后从 0 起算）
}

$tick = (int) trim(shell_exec('getconf CLK_TCK')) ?: 100;

$t0 = microtime(true);
[$u0, $s0] = cpu_jiffies();

$deadline = microtime(true) + $seconds;
$acc = 0.0;
while (microtime(true) < $deadline) {
    for ($i = 0; $i < 200000; $i++) {
        $acc += sqrt($i) * 1.000001;
    }
}

$wall = microtime(true) - $t0;
[$u1, $s1] = cpu_jiffies();
$du = $u1 - $u0;
$ds = $s1 - $s0;

printf("CLK_TCK=%d  墙钟=%.3fs  Δutime=%d  Δstime=%d jiffies\n", $tick, $wall, $du, $ds);
printf("CPU 时间 = %.3fs  比值 (utime+stime)/wall = %.2f\n",
    ($du + $ds) / $tick, ($du + $ds) / $tick / $wall);
printf("→ 单线程纯计算进程的比值应该是 1.00。实测 %s\n",
    (($du + $ds) / $tick / $wall) > 1.1
        ? '偏高：这台 WSL2 主机的 jiffy 记账有偏差，CPU% 只能横向比大小'
        : '≈1，记账是准的');
