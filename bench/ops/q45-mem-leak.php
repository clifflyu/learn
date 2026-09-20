<?php
/**
 * Q45 实测：内存持续增长 —— 五种成因/对照，每种跑一个独立进程。
 *
 * 为什么一个 case 一个进程：Zend MM 从 OS 拿到的 chunk 不会还回去。
 * 同一个进程里跑完「泄漏 174MB」再跑别的，释放的内存会留在分配器的空闲链表里
 * 被后面的 case 复用，VmRSS 曲线会被前一个 case 污染，看什么都是平的。
 *
 * 用法: docker exec learn-php php /app/bench/ops/q45-mem-leak.php <case> [轮数]
 *       case 见下面的 $cases；driver 是 q45-mem-leak.sh
 */

$case   = $argv[1] ?? 'cycles_gc_off';
$rounds = (int) ($argv[2] ?? 10);
$per    = 20000;          // 每轮迭代次数

function rss(): int
{
    $s = @file_get_contents('/proc/self/status');
    return preg_match('/VmRSS:\s+(\d+) kB/', $s, $m) ? (int) $m[1] * 1024 : -1;
}

/** 成因一：循环引用 + gc_disable()。引用计数归不了零，GC 又被关了 */
function leak_cycles(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->peer = $b;
        $b->peer = $a;
        unset($a, $b);          // 各自 refcount 停在 1
    }
}

/** 成因二：全局数组只增不减（本地缓存没上限、把请求数据往全局里塞） */
function leak_global(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $GLOBALS['q45_cache'][] = ['id' => $i, 'blob' => str_repeat('x', 64)];
    }
}

/**
 * 成因三：函数里的 static 变量。
 * FPM 下 worker 是长驻的，static 数组跨请求存活 —— 这是最容易漏掉的一种，
 * 因为代码看起来「每次请求都是新的」。
 */
function leak_static(int $n): void
{
    static $cache = [];
    for ($i = 0; $i < $n; $i++) {
        $cache[] = str_repeat('y', 64);
    }
}

/** 对照一：分配一大块再释放。RSS 会上去，但之后是平的 —— 这不是泄漏 */
function control_bigalloc(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $big = str_repeat('z', 1024 * 1024);
        $len = strlen($big);
        unset($big);
    }
}

/** 对照二：循环引用但 GC 开着，内存是平的 */
function control_gc_on(int $n): void
{
    leak_cycles($n);
}

$cases = [
    'cycles_gc_off'   => ['fn' => 'leak_cycles',      'gc' => false, 'desc' => '循环引用 + gc_disable'],
    'static_array'    => ['fn' => 'leak_static',      'gc' => true,  'desc' => '函数内 static 数组（跨请求存活）'],
    'global_array'    => ['fn' => 'leak_global',      'gc' => true,  'desc' => '全局数组只增不减'],
    'gc_on'           => ['fn' => 'control_gc_on',    'gc' => true,  'desc' => '对照：同样的循环引用，GC 开着'],
    'bigalloc_freed'  => ['fn' => 'control_bigalloc', 'gc' => true,  'desc' => '对照：分配 1MB 后释放'],
];

if (!isset($cases[$case])) {
    exit("unknown case: $case\n可用: " . implode(', ', array_keys($cases)) . "\n");
}
$c = $cases[$case];

$c['gc'] ? gc_enable() : gc_disable();

// 基线取进程启动后的第一秒（此时 OPcache、autoload 都已经加载完）
$baseMem = memory_get_usage(true);
$baseRss = rss();

printf("=== %s（gc_%s）迭代 %d 次 × %d 轮 ===\n",
    $c['desc'], $c['gc'] ? 'enable' : 'disable', $per, $rounds);
printf("%6s %16s %16s %16s %16s\n", '轮', 'memory_usage', 'Δmemory', 'VmRSS', 'ΔVmRSS');

$fn = $c['fn'];
for ($r = 1; $r <= $rounds; $r++) {
    $fn($per);
    $m = memory_get_usage(true);
    $s = rss();
    printf("%6d %16s %16s %16s %16s\n", $r,
        number_format($m), number_format($m - $baseMem),
        number_format($s), number_format($s - $baseRss));
}

// 收尾：显式回收一次，看 VmRSS 会不会降 —— 这一步是「真泄漏」和「分配器不还内存」的分界
gc_collect_cycles();
$st = gc_status();
printf("\n手动 gc_collect_cycles() 后: VmRSS = %s（比基线高 %s），"
    . "GC 跑了 %d 轮、回收 %d 个根\n",
    number_format(rss()), number_format(rss() - $baseRss),
    $st['runs'], $st['collected']);
echo "→ VmRSS 不降不代表还有泄漏：Zend MM 的 chunk 不会还给 OS，释放的内存留在空闲链表里。\n";
