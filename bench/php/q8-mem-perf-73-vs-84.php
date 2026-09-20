<?php
/**
 * Q8 实测：PHP 7.3 vs 8.4 的内存与吞吐差异
 *
 * 7.3 兼容语法。内存用 memory_get_usage() 的差值；时间跑 5 轮取最小值（机器上有其他容器，噪声大）。
 *
 * 用法:
 *   docker run --rm --entrypoint php -v /opt/learn:/app \
 *     docker.m.daocloud.io/webdevops/php-nginx:7.3 /app/bench/php/q8-mem-perf-73-vs-84.php
 *   docker exec learn-php php /app/bench/php/q8-mem-perf-73-vs-84.php
 */

ini_set('memory_limit', '512M');

printf("PHP|%s|Zend %s\n", PHP_VERSION, zend_version());

/* ---------------- 1. 内存 ---------------- */

function memOf($fn)
{
    gc_collect_cycles();
    $before = memory_get_usage();
    $keep = $fn();
    $after = memory_get_usage();
    $bytes = $after - $before;
    unset($keep);
    return $bytes;
}

echo "\n== 内存（N = 1000000）==\n";
$N = 1000000;

$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[$i] = $i; }
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '顺序整数键数组', $bytes, $bytes / $N);

$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[$i * 2] = $i; }   // 有间隔 → 7.3/8.4 都会退化成哈希
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '有间隔整数键数组', $bytes, $bytes / $N);

$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a['k' . $i] = $i; }
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '短字符串键数组', $bytes, $bytes / $N);

$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[] = $i; }
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '追加写入数组', $bytes, $bytes / $N);

// 注意：不能写 $a[] = 'abcdef...'（字面量是 interned 的，100 万个元素共用一份 zend_string，
// 测出来只有 16.8 B/元素，会得出「字符串不要钱」的假结论）。要造各不相同的字符串。
$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[] = md5((string) $i); }  // 32 字符、互不相同
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '32 字符字符串数组(互不相同)', $bytes, $bytes / $N);

$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[] = 'abcdefghijklmnopqrstuvwxyz012345'; }  // 字面量
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '同上但用字面量(共享串)', $bytes, $bytes / $N);

// 意外发现：同样造 32 字符串，sprintf 的每串开销是 md5/str_repeat 的 4 倍
$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[] = sprintf('%032d', $i); }
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '同上但用 sprintf 造', $bytes, $bytes / $N);

$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[] = array('id' => $i, 'name' => 'x'); }
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '小数组（2 元素）数组', $bytes, $bytes / $N);

/* 差分法复核：同样形态测两档规模，差值 ÷ 规模差 = 每元素真实开销（抵消常数项与分配器噪声）
   上面「互不相同字符串」单点测出来的 336 B/元素看着偏高，用差分确认一下。 */
echo "\n-- 差分法复核 --\n";
foreach (array(
    '顺序整数键' => function ($n) { $a = array(); for ($i = 0; $i < $n; $i++) { $a[] = $i; } return $a; },
    '互不相同 32 字符串' => function ($n) { $a = array(); for ($i = 0; $i < $n; $i++) { $a[] = md5((string) $i); } return $a; },
    '有间隔整数键' => function ($n) { $a = array(); for ($i = 0; $i < $n; $i++) { $a[$i * 2] = $i; } return $a; },
) as $label => $maker) {
    $lo = 500000; $hi = 1000000;
    $mLo = memOf(function () use ($maker, $lo) { return $maker($lo); });
    $mHi = memOf(function () use ($maker, $hi) { return $maker($hi); });
    printf("%-30s| %10d → %10d | %5.1f B/元素\n", $label, $mLo, $mHi, ($mHi - $mLo) / ($hi - $lo));
}

class Point73 { public $x = 0; public $y = 0; public $name = 'p'; }

$bytes = memOf(function () use ($N) {
    $a = array();
    for ($i = 0; $i < $N; $i++) { $a[] = new Point73(); }
    return $a;
});
printf("%-30s| %10d B | %5.1f B/元素\n", '对象（3 属性）数组', $bytes, $bytes / $N);

/* ---------------- 2. 吞吐 ---------------- */

function best($fn, $rounds = 5)
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

echo "\n== 吞吐（5 轮取最小，秒）==\n";

$t = best(function () {
    $s = 0;
    for ($i = 0; $i < 20000000; $i++) { $s += $i % 7; }
    return $s;
});
printf("%-30s| %8.4f s\n", '2000 万次整数取模累加', $t);

$t = best(function () {
    $a = array();
    for ($i = 0; $i < 1000000; $i++) { $a[] = $i; }
    $sum = 0;
    foreach ($a as $v) { $sum += $v; }
    return $sum;
});
printf("%-30s| %8.4f s\n", '100 万次数组写入+遍历', $t);

$t = best(function () {
    $s = '';
    for ($i = 0; $i < 200000; $i++) { $s .= 'x' . $i; }
    return $s;
});
printf("%-30s| %8.4f s\n", '20 万次字符串拼接', $t);

$payload = array();
for ($i = 0; $i < 1000; $i++) { $payload['k' . $i] = array('id' => $i, 'name' => 'user' . $i, 'tags' => array('a', 'b')); }
$json = json_encode($payload);
$t = best(function () use ($payload) {
    for ($i = 0; $i < 200; $i++) { $x = json_encode($payload); }
    return $x;
});
printf("%-30s| %8.4f s\n", 'json_encode 1000 键 × 200 次', $t);

$t = best(function () use ($json) {
    for ($i = 0; $i < 200; $i++) { $x = json_decode($json, true); }
    return $x;
});
printf("%-30s| %8.4f s\n", 'json_decode 同上 × 200 次', $t);

$t = best(function () {
    $s = '';
    for ($i = 0; $i < 200000; $i++) { $s = md5('salt' . $i); }
    return $s;
});
printf("%-30s| %8.4f s\n", 'md5 × 20 万次', $t);

/* ---------------- 3. OPcache / JIT ---------------- */

echo "\n== OPcache / JIT 能力 ==\n";
printf("%-30s| %s\n", 'opcache_get_status 存在', function_exists('opcache_get_status') ? 'yes' : 'no');
if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    printf("%-30s| %s\n", 'CLI 下 opcache 是否启用', !empty($st['opcache_enabled']) ? 'yes' : 'no');
    printf("%-30s| %s\n", 'status 里有 jit 字段', isset($st['jit']) ? 'yes' : 'no');
    if (isset($st['jit'])) {
        printf("%-30s| %s\n", 'JIT on', !empty($st['jit']['on']) ? 'yes' : 'no');
        printf("%-30s| %s\n", 'JIT buffer_size', isset($st['jit']['buffer_size']) ? $st['jit']['buffer_size'] : '-');
    }
}
$ini = array('opcache.jit', 'opcache.jit_buffer_size', 'opcache.enable', 'zend.max_allowed_stack_size');
foreach ($ini as $k) {
    printf("%-30s| %s\n", $k, var_export(ini_get($k), true));
}
