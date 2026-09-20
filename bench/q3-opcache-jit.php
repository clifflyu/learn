<?php
/**
 * Q3 实测：OPcache 与 JIT 的收益
 *
 * 两个负载：
 *   cpu  —— 纯计算密集（JIT 的主场）
 *   web  —— 模拟一次 Web 请求：少量函数调用 + 数组/字符串操作 + 一次「I/O」
 *
 * 用法: php q3-opcache-jit.php <cpu|web>
 */

$mode = $argv[1] ?? 'cpu';

$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$jit = $status['jit'] ?? null;
printf("opcache=%s  jit=%s  jit_buffer=%s\n",
    $status ? (($status['opcache_enabled'] ?? false) ? 'on' : 'off') : 'n/a',
    $jit ? (($jit['on'] ?? false) ? 'on' : 'off') : 'n/a',
    $jit ? ($jit['buffer_size'] ?? 0) : 0
);

if ($mode === 'cpu') {
    $t = microtime(true);
    $sum = 0;
    for ($i = 0; $i < 20000000; $i++) {
        $sum += ($i * 31) % 7;
    }
    printf("cpu  耗时 %.3f s  (sum=%d)\n", microtime(true) - $t, $sum);
    exit;
}

// 模拟 Web 请求：重复 2000 次「一次请求」的典型动作
$t = microtime(true);
$acc = 0;
for ($req = 0; $req < 2000; $req++) {
    $data = [];
    for ($i = 0; $i < 50; $i++) {
        $data['k' . $i] = $i * 2;
    }
    $json = json_encode($data);                 // 序列化
    $back = json_decode($json, true);           // 反序列化
    $acc += array_sum($back) + strlen($json);   // 聚合
}
printf("web  耗时 %.3f s  (acc=%d)\n", microtime(true) - $t, $acc);
