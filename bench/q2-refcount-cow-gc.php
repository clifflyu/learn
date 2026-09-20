<?php
/**
 * Q2 实测：引用计数 / 写时复制（COW）/ 垃圾回收（GC）
 *
 * 用法: docker exec learn-php php /app/bench/q2-refcount-cow-gc.php
 */

echo "PHP ", PHP_VERSION, "\n";

// ==================== 一、引用计数 ====================
echo "\n=========== 一、引用计数 ===========\n";
echo "（debug_zval_dump 的显示值比真实 refcount 大 1，看变化量即可）\n\n";

$arr = [1, 2, 3];
echo "数组，1 个变量:\n  ";
debug_zval_dump($arr);
$b = $arr;
echo "赋值给 \$b 后:\n  ";
debug_zval_dump($arr);
unset($b);
echo "unset \$b 后:\n  ";
debug_zval_dump($arr);
unset($arr);

$o = new stdClass();
echo "\n对象，1 个变量:\n  ";
debug_zval_dump($o);
$p = $o;
echo "赋值给 \$p 后:\n  ";
debug_zval_dump($o);
unset($p, $o);

// ==================== 二、写时复制 ====================
echo "\n=========== 二、写时复制（N = 100 万整数）===========\n";

$N = 1 << 20;

function readOnly(array $x): int
{
    return count($x);
}

function writesInside(array $x): array
{
    // 必须在函数内部测：复制出来的那份在函数返回时就释放了
    $m = memory_get_usage();
    $x[0] = 0;                  // 函数内一写，立刻复制一整份
    $copy = memory_get_usage() - $m;
    $x[0] = 0;                  // 再写不产生新复制
    return ['copy' => $copy, 'again' => memory_get_usage() - $m - $copy];
}

$m = memory_get_usage();
$a = range(1, $N);
printf("range 分配            : %10s B\n", number_format(memory_get_usage() - $m));
echo "\n（以下都只显示增量）\n";

$m = memory_get_usage();
$b = $a;
printf("  \$b = \$a               %10s B   ← 只加引用计数\n", number_format(memory_get_usage() - $m));

$m = memory_get_usage();
count($a);
printf("  count(\$a)             %10s B\n", number_format(memory_get_usage() - $m));

$m = memory_get_usage();
foreach ($a as $v) { /* 只读 */ }
printf("  foreach 按值遍历       %10s B\n", number_format(memory_get_usage() - $m));

$m = memory_get_usage();
readOnly($a);
printf("  只读传参进函数         %10s B   ← 不复制\n", number_format(memory_get_usage() - $m));

$r = writesInside($a);
printf("  函数内写参数           %10s B   ← 复制一整份（函数内测得）\n", number_format($r['copy']));
printf("  函数内再写一次         %10s B   ← 已经分离过，不再复制\n", number_format($r['again']));

$m = memory_get_usage();
$b[0] = 999;
printf("  \$b[0] = 999            %10s B   ← 复制一整份\n", number_format(memory_get_usage() - $m));

$m = memory_get_usage();
unset($b);
printf("  unset \$b               %10s B\n", number_format(memory_get_usage() - $m));

// 对象不走 COW
$m = memory_get_usage();
$obj = new stdClass();
$obj->data = range(1, $N);
$obj2 = $obj;
$before = memory_get_usage();
$obj2->tag = 'x';               // 改的是同一个对象，不复制
printf("  对象属性赋值           %10s B   ← 对象按句柄传递，不复制\n", number_format(memory_get_usage() - $before));

unset($obj, $obj2, $a);

// ==================== 三、垃圾回收 ====================
echo "\n=========== 三、垃圾回收（10 万次循环引用）===========\n";

class Node
{
    public ?Node $next = null;
}

function churn(int $n): array
{
    $before = gc_status();
    for ($i = 0; $i < $n; $i++) {
        $x = new Node();
        $y = new Node();
        $x->next = $y;
        $y->next = $x;              // 循环引用：引用计数永远归不了零
        unset($x, $y);
    }
    $after = gc_status();
    return [
        'runs'      => $after['runs'] - $before['runs'],
        'collected' => $after['collected'] - $before['collected'],
    ];
}

gc_enable();
$m = memory_get_usage();
$r = churn(100000);
printf("GC 开 : 触发 %2d 次，回收 %s 个根，净增内存 %s B\n",
    $r['runs'], number_format($r['collected']), number_format(memory_get_usage() - $m));

gc_collect_cycles();
gc_disable();
$m = memory_get_usage();
$r = churn(100000);
printf("GC 关 : 触发 %2d 次，回收 %s 个根，净增内存 %s B   ← 泄漏\n",
    $r['runs'], number_format($r['collected']), number_format(memory_get_usage() - $m));

gc_enable();
gc_collect_cycles();

// 单次收集的耗时：先关掉 GC 攒一堆垃圾，再手动收
gc_disable();
for ($i = 0; $i < 200000; $i++) {
    $x = new Node();
    $y = new Node();
    $x->next = $y;
    $y->next = $x;
    unset($x, $y);
}
$m = memory_get_usage();
$t = microtime(true);
$n = gc_collect_cycles();
$el = microtime(true) - $t;
printf("\n攒下 20 万对循环引用后手动回收：%d 个根，耗时 %.4f s，释放 %s B\n",
    $n, $el, number_format($m - memory_get_usage()));
gc_enable();

// ==================== 四、gc_status 字段 ====================
echo "\n=========== 四、gc_status() 全部字段 ===========\n";
foreach (gc_status() as $k => $v) {
    printf("  %-18s = %s\n", $k, is_scalar($v) ? $v : json_encode($v));
}

// ==================== 五、非循环引用不归 GC 管 ====================
echo "\n=========== 五、GC 只处理循环引用 ===========\n";
gc_collect_cycles();
$m = memory_get_usage();
for ($i = 0; $i < 100000; $i++) {
    $s = new Node();            // 无循环，refcount 归零即释放
    unset($s);
}
printf("无循环引用的 10 万次创建/销毁，净增内存 %s B\n", number_format(memory_get_usage() - $m));
