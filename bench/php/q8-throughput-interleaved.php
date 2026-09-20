<?php
/**
 * Q8 附：7.3 vs 8.4 吞吐对比（交错轮转版）
 *
 * 两个版本没法在同一个进程里跑，宿主机上又一直有别的东西在抢 CPU（实测 loadavg ~7 / 8 核），
 * 顺序跑会出现「谁后跑谁吃亏/占便宜」的系统性偏差。
 * 所以：本脚本只负责打印一组 `耗时`，由外层 shell 在两个容器之间**交替**调用 5 轮，
 * 每行取 5 轮里的最小值 —— 抖动被均摊到两个版本上，比值才可信。
 *
 * 用法见 bench/php/q8-throughput-interleaved.sh
 */

ini_set('memory_limit', '512M');

/** 3 轮取最小 */
function best($fn, $rounds = 3)
{
    $times = array();
    for ($r = 0; $r < $rounds; $r++) {
        $t = microtime(true);
        $fn();
        $times[] = microtime(true) - $t;
    }
    sort($times);
    return $times[0];
}

$rows = array();

$rows['取模累加 2000 万次'] = best(function () {
    $s = 0;
    for ($i = 0; $i < 20000000; $i++) { $s += $i % 7; }
    return $s;
});

$rows['数组写入+遍历 100 万'] = best(function () {
    $a = array();
    for ($i = 0; $i < 1000000; $i++) { $a[] = $i; }
    $sum = 0;
    foreach ($a as $v) { $sum += $v; }
    return $sum;
});

$rows['字符串拼接 20 万次'] = best(function () {
    $s = '';
    for ($i = 0; $i < 200000; $i++) { $s .= 'x' . $i; }
    return $s;
});

$payload = array();
for ($i = 0; $i < 1000; $i++) {
    $payload['k' . $i] = array('id' => $i, 'name' => 'user' . $i, 'tags' => array('a', 'b'));
}
$json = json_encode($payload);

$rows['json_encode 1000键×200'] = best(function () use ($payload) {
    for ($i = 0; $i < 200; $i++) { $x = json_encode($payload); }
    return $x;
});

$rows['json_decode 1000键×200'] = best(function () use ($json) {
    for ($i = 0; $i < 200; $i++) { $x = json_decode($json, true); }
    return $x;
});

$rows['md5 20 万次'] = best(function () {
    $s = '';
    for ($i = 0; $i < 200000; $i++) { $s = md5('salt' . $i); }
    return $s;
});

// 只打印「行名|秒」，交给外层做跨版本聚合
foreach ($rows as $label => $sec) {
    printf("%s|%.4f\n", $label, $sec);
}
