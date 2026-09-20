<?php
/**
 * Q45 实测：从「哪个进程」到「哪一行代码」——用 excimer 采样 profiler。
 *
 * 光知道「php-fpm 的某个 worker 在烧 CPU」还不够，要指到函数、行号才能改代码。
 * excimer 是采样型 profiler：每 1ms 抓一次调用栈，开销约 1%，生产上可以短暂开着跑。
 * 同类工具：php-spx、xhprof、Swoole 自带的 tick profiler。
 * 没有扩展时可以用 pcntl 信号自己撸一个，见 q45-poor-mans-profiler.php。
 *
 * 用法: docker exec learn-php php /app/bench/ops/q45-excimer.php
 */

if (!extension_loaded('excimer')) {
    exit("excimer 未安装，这个脚本跑不了\n");
}

// ── 三种「看起来都很正常」的热点代码，混在一起跑 ───────────────

/** 每行都做一次正则：O(n) 次 preg_match */
function hot_regex(int $rows): int
{
    $hit = 0;
    for ($i = 0; $i < $rows; $i++) {
        if (preg_match('/^order-(\d+)-([a-z]+)$/', "order-$i-abc", $m)) {
            $hit += (int) $m[1];
        }
    }
    return $hit;
}

/** 循环里排序：每次都对整个数组排一遍，纯浪费 */
function hot_sort(int $rows, array $data): float
{
    $sum = 0.0;
    for ($i = 0; $i < $rows; $i++) {
        $copy = $data;
        rsort($copy);
        $sum += $copy[0];
    }
    return $sum;
}

/** json 编解码大结构：序列化开销和结构大小成正比 */
function hot_json(int $rows, array $payload): int
{
    $n = 0;
    for ($i = 0; $i < $rows; $i++) {
        $n += strlen(json_encode($payload));
        json_decode(json_encode($payload), true);
    }
    return $n;
}

/** 对照组：几乎不耗时的函数，看它会不会出现在采样里 */
function cold_helper(): int
{
    return strlen('x');
}

$payload = ['items' => []];
for ($i = 0; $i < 800; $i++) {
    $payload['items'][] = ['id' => $i, 'name' => "item-$i", 'tags' => ['a', 'b', 'c']];
}
$data = range(1, 3000);

$profiler = new ExcimerProfiler();
$profiler->setPeriod(0.001);            // 1 ms 一次采样
$profiler->setEventType(EXCIMER_REAL);  // 墙上时间；EXCIMER_CPU 则只统计 CPU 时间
$profiler->start();

$t0  = microtime(true);
$acc = hot_regex(400000);
$acc += (int) hot_sort(400, $data);
$acc += hot_json(300, $payload);
$acc += cold_helper();
$wall = microtime(true) - $t0;

$profiler->stop();
$log = $profiler->getLog();

printf("总耗时 = %.3f s，采样周期 1 ms，实际抓到 %d 个样本\n\n",
    $wall, $log->getEventCount());

// ── 1. 按函数聚合：谁是热点（self = 自己占用的样本） ──────────
$agg = $log->aggregateByFunction();   // 键是函数名，usort 会把键冲掉，必须用 uasort
uasort($agg, fn($a, $b) => $b['self'] <=> $a['self']);
$total = $log->getEventCount() ?: 1;

printf("%-14s %-26s %7s %7s %10s\n", '函数', '定义位置', 'self', '占比', 'inclusive');
foreach ($agg as $fn => $row) {
    printf("%-14s %-26s %7d %6.1f%% %10d\n",
        $fn,
        basename($row['file']) . ':' . $row['line'],
        $row['self'],
        $row['self'] / $total * 100,
        $row['inclusive']);
}

// ── 2. 按调用栈聚合：热路径长什么样 ───────────────────────────
// formatCollapsed() 输出 "帧;帧;帧 样本数"，是火焰图的输入格式
$stacks = [];
foreach (explode("\n", trim($log->formatCollapsed())) as $line) {
    $sp = strrpos($line, ' ');
    $stacks[substr($line, 0, $sp)] = (int) substr($line, $sp + 1);
}
arsort($stacks);
echo "\n最热的 4 条调用栈（栈底 → 栈顶）：\n";
$i = 0;
foreach ($stacks as $stack => $n) {
    printf("  #%d  %5d 样本 (%4.1f%%)  %s\n", ++$i, $n, $n / $total * 100,
        str_replace(';', ' → ', $stack));
    if ($i >= 4) {
        break;
    }
}

// ── 3. 栈顶那 1ms 具体停在哪一行 ──────────────────────────────
// 采样型 profiler 的每一帧都带 file:line，这就是「改哪一行」的答案
$hits = [];
foreach ($log as $entry) {
    foreach ($entry->getTrace() as $frame) {
        if (isset($frame['function']) && $frame['function'] !== 'hot_regex') {
            continue;
        }
        if (isset($frame['function'])) {
            $hits[basename($frame['file']) . ':' . $frame['line']] =
                ($hits[basename($frame['file']) . ':' . $frame['line']] ?? 0) + 1;
        }
    }
}
arsort($hits);
echo "\nhot_regex 里被采样到的行号（样本最多的那几行就是要改的地方）：\n";
$i = 0;
foreach ($hits as $where => $n) {
    printf("  %-30s %5d 样本\n", $where, $n);
    if (++$i >= 3) {
        break;
    }
}
