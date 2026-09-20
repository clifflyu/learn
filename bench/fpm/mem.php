<?php
/**
 * Q4 实测：单个 FPM worker 的真实内存占用
 * 这是估算 pm.max_children 的分母——注意用的是 VmRSS，不是 memory_get_usage。
 *
 * 用法:
 *   curl 'http://localhost:9311/bench/fpm/mem.php'                  # 空载
 *   curl 'http://localhost:9311/bench/fpm/mem.php?load=framework'   # 加载 500 个类文件
 */

header('Content-Type: text/plain; charset=utf-8');

function rss(): int
{
    $s = @file_get_contents('/proc/self/status');
    return preg_match('/VmRSS:\s+(\d+) kB/', $s, $m) ? (int) $m[1] * 1024 : -1;
}

/** PSS = 私有内存 + 共享内存按共享进程数均摊。RSS 会把 opcache 的共享段重复计入每个 worker。 */
function rollup(string $key): int
{
    $s = @file_get_contents('/proc/self/smaps_rollup');
    return $s && preg_match('/^' . $key . ':\s+(\d+) kB/m', $s, $m) ? (int) $m[1] * 1024 : -1;
}

$load = $_GET['load'] ?? '';

if ($load === 'framework') {
    require __DIR__ . '/../_fixtures.php';
    ensure_fixtures(__DIR__ . '/../_gen');
    for ($i = 1; $i <= 500; $i++) {
        require __DIR__ . "/../_gen/c$i.php";
    }
    $obj = new C500();
    $obj->m59(1, 'x');
}

printf("pid                         = %d\n", getmypid());
printf("memory_get_usage(true)      = %s B   ← 从 Zend 分配器视角\n", number_format(memory_get_usage(true)));
printf("memory_get_peak_usage(true) = %s B\n", number_format(memory_get_peak_usage(true)));
printf("VmRSS                       = %s B   ← 含共享内存，会重复计算\n", number_format(rss()));
printf("Pss                         = %s B   ← 共享内存已按进程数均摊，估算用这个\n", number_format(rollup('Pss')));
printf("Private_Dirty               = %s B   ← 每 worker 独占的部分\n", number_format(rollup('Private_Dirty')));
printf("Shared_Clean                = %s B   ← 主要是 opcache 的共享段\n", number_format(rollup('Shared_Clean')));
