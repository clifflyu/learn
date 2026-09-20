<?php
// Q26 常用数据结构的内存开销与编码切换点
//
// 用法: docker exec learn-php php /app/bench/redis/q26-datastruct.php
//
// 为什么用 MEMORY USAGE 逐 key 求和，而不是 INFO used_memory 求差：
//   这台 Redis 上还有别的会话在写，used_memory 的差会被别人污染。
//   MEMORY USAGE <key> SAMPLES 0 是精确的按 key 统计，只算自己的 key，天然隔离。
// 仍然用差分法：在 N 和 2N 两档各测一次，差值 ÷ 规模差 = 边际开销，
//   把 db 字典本身的常数项和分配器噪声抵掉。

require __DIR__ . '/_conn.php';

$PFX = 'q26:';
$N1 = 100000; $N2 = 200000; $DN = $N2 - $N1;
$r = rconn();

function total_mem(Redis $r, string $pfx): int {
    $it = null; $sum = 0; $keys = [];
    while (($ks = $r->scan($it, $pfx . '*', 2000)) !== false) {
        if ($ks) foreach ($ks as $k) $keys[] = $k;
        if ($it === 0) break;
    }
    foreach (array_chunk($keys, 5000) as $chunk) {
        $r->multi(Redis::PIPELINE);
        foreach ($chunk as $k) $r->rawCommand('MEMORY', 'USAGE', $k, 'SAMPLES', '0');
        foreach ($r->exec() as $v) $sum += (int)$v;
    }
    return $sum;
}
function wipe(Redis $r, string $pfx): void { prefix_cleanup($r, $pfx); }

// 用 --pipe 的等价物：pipeline 批量灌
function pipe(Redis $r, callable $gen, int $n): void {
    $buf = [];
    $flush = function () use ($r, &$buf) {
        if (!$buf) return;
        $r->multi(Redis::PIPELINE);
        foreach ($buf as $c) $r->rawCommand(...$c);
        $r->exec();
        $buf = [];
    };
    for ($i = 0; $i < $n; $i++) { $buf[] = $gen($i); if (count($buf) >= 5000) $flush(); }
    $flush();
}

echo "===== 版本 =====\n";
foreach (explode("\n", (string)$r->rawCommand('INFO', 'server')) as $l)
    if (preg_match('/^(redis_version|os|arch_bits):/', $l)) echo "  " . trim($l) . "\n";

// ---------------------------------------------------------------- 边际内存
echo "\n===== 每元素边际内存（N={$N1} 与 {$N2} 两档差分）=====\n\n";
printf("  %-34s %12s %12s %10s\n", '数据结构', "N={$N1}", "N={$N2}", 'B/元素');

$shapes = [
    'string: N 个 key，键 16B 值 7B'        => ['s_str',  function ($r, $p, $n) { pipe($r, fn($i) => ['SET', "{$p}string:" . sprintf('%07d', $i), 'v' . sprintf('%06d', $i)], $n); },
                                              fn($r, $p) => total_mem($r, "{$p}string:")],
    'hash: 1 个 key，N 个字段(名 8B 值 7B)'           => ['s_hash', function ($r, $p, $n) { pipe($r, fn($i) => ['HSET', "{$p}hash", sprintf('f%07d', $i), 'v' . sprintf('%06d', $i)], $n); },
                                              fn($r, $p) => (int)$r->rawCommand('MEMORY', 'USAGE', "{$p}hash", 'SAMPLES', '0')],
    'list: 1 个 key，N 个元素(值 7B)'           => ['s_list', function ($r, $p, $n) { pipe($r, fn($i) => ['RPUSH', "{$p}list", 'v' . sprintf('%06d', $i)], $n); },
                                              fn($r, $p) => (int)$r->rawCommand('MEMORY', 'USAGE', "{$p}list", 'SAMPLES', '0')],
    'set: 1 个 key，N 个成员(值 7B)'      => ['s_set',  function ($r, $p, $n) { pipe($r, fn($i) => ['SADD', "{$p}set", 'v' . sprintf('%06d', $i)], $n); },
                                              fn($r, $p) => (int)$r->rawCommand('MEMORY', 'USAGE', "{$p}set", 'SAMPLES', '0')],
    'zset: 1 个 key，N 个成员(值 7B)'           => ['s_zset', function ($r, $p, $n) { pipe($r, fn($i) => ['ZADD', "{$p}zset", $i, 'v' . sprintf('%06d', $i)], $n); },
                                              fn($r, $p) => (int)$r->rawCommand('MEMORY', 'USAGE', "{$p}zset", 'SAMPLES', '0')],
];

foreach ($shapes as $label => [$k, $fill, $measure]) {
    wipe($r, $PFX);
    $m1 = null; $m2 = null;
    foreach ([$N1, $N2] as $n) {
        if ($n === $N2) { /* 在 N1 基础上追加到 N2 */ }
        $fill($r, $PFX, $n);
        $m = $measure($r, $PFX);
        if ($n === $N1) $m1 = $m; else $m2 = $m;
    }
    printf("  %-34s %12s %12s %10.1f\n", $label, number_format($m1), number_format($m2), ($m2 - $m1) / $DN);
}
wipe($r, $PFX);

// ---------------------------------------------------------------- 同一批数据的三种存法
echo "\n===== 同一批数据（10 万个 id → 6 字节值）的三种存法，N={$N1} =====\n\n";
$N = $N1;

wipe($r, $PFX);
pipe($r, fn($i) => ['SET', "{$PFX}s:" . sprintf('%07d', $i), 'v' . sprintf('%06d', $i)], $N);
$m_strings = total_mem($r, "{$PFX}s:");
echo "  1) 10 万个独立 string key        : " . str_pad(number_format($m_strings) . " B", 14) . " → " . sprintf("%.1f B/条", $m_strings / $N) . "\n";

wipe($r, $PFX);
pipe($r, fn($i) => ['HSET', "{$PFX}onehash", sprintf('f%07d', $i), 'v' . sprintf('%06d', $i)], $N);
$m_hash1 = (int)$r->rawCommand('MEMORY', 'USAGE', "{$PFX}onehash", 'SAMPLES', '0');
echo "  2) 1 个 hash，10 万个字段        : " . str_pad(number_format($m_hash1) . " B", 14) . " → " . sprintf("%.1f B/条", $m_hash1 / $N) . "\n";

wipe($r, $PFX);
define('SHARD', 1000);
pipe($r, fn($i) => ['HSET', "{$PFX}sh:" . intdiv($i, SHARD), sprintf('f%07d', $i), 'v' . sprintf('%06d', $i)], $N);
$m_hashN = total_mem($r, "{$PFX}sh:");
echo "  3) " . ($N / SHARD) . " 个 hash 分片（每片 " . SHARD . " 字段）: " . str_pad(number_format($m_hashN) . " B", 14) . " → " . sprintf("%.1f B/条", $m_hashN / $N) . "\n";

printf("\n  独立 string key 是分片 hash 的 %.2f 倍，是不分片 hash 的 %.2f 倍\n",
       $m_strings / $m_hashN, $m_strings / $m_hash1);
echo "  注：1 个超大 hash 最省内存，但它同时是最典型的大 Key —— 省内存和「别造大 Key」是有冲突的\n";
wipe($r, $PFX);

// ---------------------------------------------------------------- 编码切换点
echo "\n===== 编码切换点（实测，逐个加元素直到 OBJECT ENCODING 变脸）=====\n\n";
$cfg = [];
foreach (['hash-max-listpack-entries', 'hash-max-listpack-value', 'set-max-intset-entries',
          'set-max-listpack-entries', 'zset-max-listpack-entries', 'zset-max-listpack-value',
          'list-max-listpack-size'] as $c) $cfg[$c] = $r->config('GET', $c)[$c] ?? '?';
foreach ($cfg as $c => $v) printf("  %-30s = %s\n", $c, $v);

function flip(Redis $r, string $key, callable $add, int $max = 3000): array {
    $r->del($key);
    $prev = null;
    for ($i = 1; $i <= $max; $i++) {
        $add($i);
        $e = $r->object('encoding', $key);
        if ($prev !== null && $e !== $prev) return [$i, $prev, $e];
        $prev = $e;
    }
    return [-1, $prev, $prev];
}

echo "\n  --- hash（值 1 字节，只加字段）---\n";
[$i, $a, $b] = flip($r, "{$PFX}f_hash", fn($n) => $r->hSet("{$PFX}f_hash", "f$n", 'v'));
printf("    第 %d 个字段时：%s → %s\n", $i, $a, $b);

echo "  --- hash（只放 1 个字段，把值加长）---\n";
$r->del("{$PFX}f_hashv");
$prev = null;
for ($len = 1; $len <= 200; $len++) {
    $r->del("{$PFX}f_hashv"); $r->hSet("{$PFX}f_hashv", 'f', str_repeat('x', $len));
    $e = $r->object('encoding', "{$PFX}f_hashv");
    if ($prev !== null && $e !== $prev) { printf("    值长度 %d 时：%s → %s（阈值 %s，注意是「大于」才切换）\n", $len, $prev, $e, $cfg['hash-max-listpack-value']); break; }
    $prev = $e;
}

echo "  --- set（纯整数成员）---\n";
[$i, $a, $b] = flip($r, "{$PFX}f_intset", fn($n) => $r->sAdd("{$PFX}f_intset", (string)$n));
printf("    第 %d 个整数成员时：%s → %s\n", $i, $a, $b);

echo "  --- set（字符串成员）---\n";
[$i, $a, $b] = flip($r, "{$PFX}f_set", fn($n) => $r->sAdd("{$PFX}f_set", "m$n"));
printf("    第 %d 个字符串成员时：%s → %s\n", $i, $a, $b);

echo "  --- zset（成员 1 字节）---\n";
[$i, $a, $b] = flip($r, "{$PFX}f_zset", fn($n) => $r->zAdd("{$PFX}f_zset", $n, "m$n"));
printf("    第 %d 个成员时：%s → %s\n", $i, $a, $b);

echo "  --- zset（1 个成员，把成员名加长）---\n";
$r->del("{$PFX}f_zsetv");
$prev = null;
for ($len = 1; $len <= 200; $len++) {
    $r->del("{$PFX}f_zsetv"); $r->zAdd("{$PFX}f_zsetv", 1, str_repeat('x', $len));
    $e = $r->object('encoding', "{$PFX}f_zsetv");
    if ($prev !== null && $e !== $prev) { printf("    成员长度 %d 时：%s → %s\n", $len, $prev, $e); break; }
    $prev = $e;
}

echo "  --- list（元素 10 字节，看多少条撑爆一个 listpack）---\n";
$r->del("{$PFX}f_list");
$prev = null;
for ($n = 1; $n <= 2000; $n++) {
    $r->rPush("{$PFX}f_list", str_pad('x10', 10));
    $e = $r->object('encoding', "{$PFX}f_list");
    if ($prev !== null && $e !== $prev) { printf("    第 %d 个元素时：%s → %s\n", $n, $prev, $e); break; }
    $prev = $e;
}
$r->del("{$PFX}f_list");
$r->rPush("{$PFX}f_list", ...array_fill(0, 20000, str_pad('x10', 10)));
printf("    2 万个 10 字节元素：encoding=%s（list 永远叫 quicklist，元素被切成多个 listpack）\n", $r->object('encoding', "{$PFX}f_list"));
$r->del("{$PFX}f_list");

// ---------------------------------------------------------------- 分片粒度 vs 内存
echo "\n===== 同一个 " . number_format($N) . " 条数据集，只改 hash 分片粒度 =====\n\n";
printf("  %-12s %-14s %-12s %-14s %s\n", '分片数', '每片字段数', '编码', '总内存', 'B/条');
foreach ([1, 128, 400, 512, 513, 1000, 100000] as $per) {
    wipe($r, $PFX);
    $nsh = intdiv($N, $per);
    pipe($r, fn($i) => ['HSET', "{$PFX}sh2:" . intdiv($i, $per), sprintf('f%07d', $i), 'v' . sprintf('%06d', $i)], $N);
    $mem = total_mem($r, "{$PFX}sh2:");
    printf("  %-12s %-14s %-12s %-14s %.1f\n", number_format($nsh), number_format($per),
           $r->object('encoding', "{$PFX}sh2:0"), number_format($mem), $mem / $N);
}
echo "\n  ↑ 临界点就在每片 512 个字段：512 及以下还是 listpack（连续内存、无字典），\n";
echo "    513 开始转 hashtable，B/条 直接跳上去，一路涨到和「独立 string key」持平。\n";
echo "    所以「用 hash 省内存」只在 hash 小的时候成立；大 hash 既费内存，又是大 Key。\n";

prefix_cleanup($r, $PFX);
echo "\n清理完毕\n";
