<?php
// Q43 场景实测：同样的请求压力，五种限流算法分别放行多少。
//
//   docker exec learn-php php /app/bench/network/q43/01-scenarios.php
//
// 场景一：窗口边界处打两波突发 —— 固定窗口的经典漏洞
// 场景二：空闲一段时间后一次打出 20 个 —— 突发容忍度
//
// 所有算法统一：limit = 10 次 / 1000 ms（漏桶的 cap 也取 10）。
declare(strict_types=1);
require __DIR__ . '/ratelimit.php';

const LIMIT     = 10;
const WINDOW_MS = 1000;

function now_ms(): float { return microtime(true) * 1000; }

function sleep_until(float $t_ms): void
{
    $d = $t_ms - now_ms();
    if ($d > 0) {
        usleep((int)round($d * 1000));
    }
}

$R = RateLimiter::conn();

/** 每种算法一个独立实例，各自用自己的 key，互不干扰 */
function limiters(Redis $r): array
{
    return [
        new FixedWindow(LIMIT, WINDOW_MS, $r),
        new SlidingWindowLog(LIMIT, WINDOW_MS, $r),
        new SlidingWindowCounter(LIMIT, WINDOW_MS, $r),
        new TokenBucket((float)LIMIT, LIMIT, $r),
        new LeakyBucket((float)LIMIT, LIMIT, $r),
    ];
}

echo "PHP: " . PHP_VERSION . "\n";
echo "Redis: " . $R->info('server')['redis_version'] . "\n";
echo "统一参数: limit = " . LIMIT . " 次 / " . WINDOW_MS . " ms\n";
echo str_repeat('=', 92), "\n";

// ===========================================================================
// 场景一：窗口边界处的两波突发
//
//   固定窗口的窗口是【绝对时间】对齐的（floor(now/1000)），
//   所以在边界前 150ms 打 10 个、边界后 150ms 再打 10 个 —— 全程只隔 300ms，
//   但它们落在两个不同的窗口里，固定窗口会【全部放行】。
// ===========================================================================
echo "场景一：窗口边界处的两波突发（边界前 150ms 打 10 个，边界后 150ms 再打 10 个）\n";
echo str_repeat('-', 92), "\n";

$rows = [];
foreach (limiters($R) as $l) {
    $key = 's1';
    $l->reset($key);

    // 等到下一个整秒边界附近再开打，边界由绝对时间决定
    $boundary = (floor(now_ms() / WINDOW_MS) + 1) * WINDOW_MS;
    $t1 = $boundary - 150;
    $t2 = $boundary + 150;

    sleep_until($t1);
    $b1_ok = $b1_no = 0;
    $t1_real = now_ms();
    for ($i = 0; $i < LIMIT; $i++) {
        $r = $l->allow($key, (int)now_ms());
        $r['allow'] ? $b1_ok++ : $b1_no++;
    }
    $t1_end = now_ms();

    sleep_until($t2);
    $b2_ok = $b2_no = 0;
    $t2_real = now_ms();
    $sample = null;
    for ($i = 0; $i < LIMIT; $i++) {
        $r = $l->allow($key, (int)now_ms());
        if ($i === 0) $sample = $r['info'];
        $r['allow'] ? $b2_ok++ : $b2_no++;
    }
    $t2_end = now_ms();

    $rows[] = [
        'algo'   => $l->name(),
        'b1'     => $b1_ok,
        'b2'     => $b2_ok,
        'total'  => $b1_ok + $b2_ok,
        'span'   => $t2_end - $t1_real,
        'note'   => $sample ?? '',
    ];
}

printf("  %-16s %-12s %-12s %-10s %-12s %s\n",
    '算法', '第一波通过', '第二波通过', '合计通过', '全部打完耗时', '第二波第一次的判定');
foreach ($rows as $row) {
    printf("  %-16s %-12s %-12s %-10s %8.1f ms   %s\n",
        $row['algo'], $row['b1'] . ' / ' . LIMIT, $row['b2'] . ' / ' . LIMIT,
        $row['total'] . ' / ' . (LIMIT * 2), $row['span'], $row['note']);
}
echo "\n  ⇒ 固定窗口放行了 20 个，而它承诺的是「每 1000ms 最多 10 个」。\n";

// ===========================================================================
// 场景二：空闲 2 秒后一次打出 20 个
//
//   桶容量决定了「攒了多久之后能一口气放多少」。
//   令牌桶这里额外测一个 cap=20 的版本，用来证明「突发能力是一个旋钮」。
// ===========================================================================
echo "\n";
echo "场景二：静默 2 秒后，一次连打 20 个（每个算法都先清空状态）\n";
echo str_repeat('-', 92), "\n";

$all = limiters($R);
$all[] = new TokenBucket((float)LIMIT, LIMIT * 2, $R);   // cap 翻倍的令牌桶

$rows2 = [];
foreach ($all as $l) {
    $key = 's2';
    $l->reset($key);
    sleep_until(now_ms() + 2000);          // 空闲 2 秒：按 rate=10/s 算，任何算法都该攒够 20 个额度
    $ok = $no = 0;
    $t0 = now_ms();
    $first_reject_at = null;
    for ($i = 0; $i < 20; $i++) {
        $r = $l->allow($key, (int)now_ms());
        if ($r['allow']) {
            $ok++;
        } else {
            $no++;
            $first_reject_at ??= now_ms() - $t0;
        }
    }
    $rows2[] = [
        'algo' => $l->name() . '  (' . $l->spec() . ')',
        'ok' => $ok, 'no' => $no, 'first_reject' => $first_reject_at,
    ];
}

printf("  %-40s %-10s %-10s %s\n", '算法（参数）', '通过', '拒绝', '第几个开始被拒');
foreach ($rows2 as $row) {
    printf("  %-40s %-10s %-10s %s\n", $row['algo'], $row['ok'] . ' / 20', $row['no'] . ' / 20',
        $row['first_reject'] === null ? '没有拒绝' : sprintf('第 %d 个（%.0f ms 处）', $row['ok'] + 1, $row['first_reject']));
}
echo "\n  ⇒ 「空闲积攒的额度能不能一次性兑现」= 桶容量，这是各算法最大的行为差异。\n";

// ===========================================================================
// 场景三：漏桶的「摊平」效果
//   漏桶不靠拒绝，靠排队：10 个请求瞬间到达，出口会被拉成 10 * (1000/rate) ms。
// ===========================================================================
echo "\n";
echo "场景三：漏桶的排队效果（rate = 5 个/秒，桶容量 20，瞬间来 10 个）\n";
echo str_repeat('-', 92), "\n";

$lb = new LeakyBucket(5.0, 20, $R);
$lb->reset('s3');
$t0 = now_ms();
$departs = [];
for ($i = 0; $i < 10; $i++) {
    $r = $lb->allow('s3', (int)now_ms());
    $departs[] = now_ms() + $r['retry_after_ms'];
}
printf("  %-6s %-14s %s\n", '序号', '计划发出时刻', '相对第一个的间隔');
foreach ($departs as $i => $d) {
    printf("  #%-5d %8.1f ms     %6.1f ms\n", $i + 1, $d - $t0,
        $i === 0 ? 0.0 : $d - $departs[0]);
}
printf("  10 个请求的到达跨度: %.1f ms，计划发出跨度: %.1f ms\n",
    $departs[0] - $t0, end($departs) - $departs[0]);
echo "  ⇒ 出口被拉成严格等距的 200ms 一个，这就是「漏桶=匀速」，也是它和令牌桶的本质区别。\n";
