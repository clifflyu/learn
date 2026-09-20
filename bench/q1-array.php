<?php
/**
 * Q1 实测脚本：PHP 数组的每元素内存开销
 *
 * 原理：数组容量 nTableSize 取 2 的幂，扩容时正好翻倍，不受取整干扰。
 * 所以在两个相邻的 2 的幂规模上各测一次，差值 ÷ 元素数差 = 精确的每槽位开销。
 * 每次测量都在独立子进程里做，避免分配器状态互相污染。
 *
 * 用法: php q1-bench.php
 */

$N1 = 1 << 18;   // 262144
$N2 = 1 << 19;   // 524288

if (($argv[1] ?? '') === '--one') {
    measure($argv[2], (int) $argv[3]);
    exit;
}

function measure(string $type, int $N): void
{
    $base = memory_get_usage();
    switch ($type) {
        case 'packed':                       // 递增整数键 → packed
            $a = [];
            for ($i = 0; $i < $N; $i++) $a[$i] = $i;
            break;
        case 'sparse':                       // 稀疏整数键 → 退化为哈希表
            $a = [];
            for ($i = 0; $i < $N; $i++) $a[$i * 2] = $i;
            break;
        case 'str':                          // 短字符串键（2~7 字符）
            $a = [];
            for ($i = 0; $i < $N; $i++) $a['k' . $i] = $i;
            break;
        case 'longstr':                      // 长字符串键（21 字符）
            $a = [];
            $pad = str_repeat('k', 20);
            for ($i = 0; $i < $N; $i++) $a[$pad . $i] = $i;
            break;
        case 'spl':                          // SplFixedArray 装同样的值
            $a = new SplFixedArray($N);
            for ($i = 0; $i < $N; $i++) $a[$i] = $i;
            break;
        case 'spl_empty':                    // SplFixedArray 空槽（对照）
            $a = new SplFixedArray($N);
            break;
    }
    echo memory_get_usage() - $base, "\n";
}

function child(string $type, int $N): int
{
    return (int) shell_exec(sprintf('%s %s --one %s %d', PHP_BINARY, __FILE__, $type, $N));
}

$types = [
    'packed'    => 'packed（递增整数键）',
    'sparse'    => '稀疏整数键',
    'str'       => '短字符串键（2~7 字符）',
    'longstr'   => '长字符串键（21~26 字符）',
    'spl'       => 'SplFixedArray',
];

printf("PHP %s\n\n", PHP_VERSION);
printf("%-26s %11s %11s %10s\n", '形态', 'N=2^18', 'N=2^19', 'B/元素');
printf("%s\n", str_repeat('-', 62));
foreach ($types as $t => $label) {
    $m1 = child($t, $N1);
    $m2 = child($t, $N2);
    printf("%-26s %11s %11s %10.1f\n",
        $label, number_format($m1), number_format($m2), ($m2 - $m1) / ($N2 - $N1));
}

// 对照：SplFixedArray 空槽的纯指针开销
$s1 = child('spl_empty', $N1);
$s2 = child('spl_empty', $N2);
printf("%-26s %11s %11s %10.1f\n",
    'SplFixedArray（空槽）', number_format($s1), number_format($s2), ($s2 - $s1) / ($N2 - $N1));

// 用第三档规模交叉验证：差值应完全一致，证明测量不受分配器噪声影响
echo "\n--- 换一档规模交叉验证（N=2^19 → 2^20）---\n";
foreach (['packed', 'sparse'] as $t) {
    $m2 = child($t, $N2);
    $m3 = child($t, 1 << 20);
    printf("%-26s %10.1f B/元素\n", $types[$t], ($m3 - $m2) / ((1 << 20) - $N2));
}

// unset 之后内存还不还？
echo "\n--- unset 后内存是否归还 ---\n";
$N = 1 << 20;
$b = memory_get_usage();
$a = [];
for ($i = 0; $i < $N; $i++) $a[$i] = $i;
$full = memory_get_usage() - $b;
for ($i = 0; $i < $N; $i++) unset($a[$i]);
$after = memory_get_usage() - $b;
printf("1M 元素分配: %s B\n", number_format($full));
printf("全部 unset 后仍占: %s B  (%.1f B/元素残留)\n", number_format($after), $after / $N);

// 对照：直接销毁整个数组
unset($a);
printf("unset 整个数组后: %s B\n", number_format(memory_get_usage() - $b));

// ---------- packed 什么时候退化成哈希表 ----------
// 规则（实测）：键按「连续递增」写入才保持 packed；有间隔或乱序就退化。
// 起始键是 0 还是 1 无所谓。这决定了「16 B/元素」能维持多久，是这题最实用的一环。
$N = 100000;

echo "\n--- packed 退化测试（N=10 万）---\n";

function probe(string $label, callable $build): void
{
    global $N;
    $b = memory_get_usage();
    $a = $build();
    printf("%-28s %6.1f B/元素\n", $label, (memory_get_usage() - $b) / $N);
}

probe('键 0..N-1 顺序', fn() => array_combine(range(0, $N - 1), range(0, $N - 1)));
probe('键 1..N 顺序', fn() => array_combine(range(1, $N), range(1, $N)));
probe('有间隔 0,2,4,…', fn() => array_combine(range(0, 2 * ($N - 1), 2), range(0, $N - 1)));
probe('倒序插入', function () use ($N) {
    $a = [];
    for ($i = $N - 1; $i >= 0; $i--) $a[$i] = $i;
    return $a;
});
probe('随机顺序插入', function () use ($N) {
    $a = [];
    $k = range(0, $N - 1);
    shuffle($k);
    foreach ($k as $i) $a[$i] = $i;
    return $a;
});
probe('顺序 + 插入一个字符串键', function () use ($N) {
    $a = range(0, $N - 1);
    $a['str_key'] = 1;
    return $a;
});
probe('顺序 + 挖洞后继续追加', function () use ($N) {
    $a = range(0, $N - 1);
    unset($a[5], $a[1000]);
    $a[] = 1;
    return $a;
});
