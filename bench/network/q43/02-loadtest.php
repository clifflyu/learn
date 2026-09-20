<?php
// Q43 并发压测：8 个进程用【完全相同的请求节奏】打五种限流器，对比通过曲线。
//
//   docker exec learn-php php /app/bench/network/q43/02-loadtest.php
//
// 压力设定：目标 400 次/秒（限值的 2 倍），持续 3 秒，按 100ms 分桶统计放行数。
//   - 每个 worker 自己按 50 次/秒 的节奏发请求（usleep 定速），8 个 worker 合计 400/s
//   - worker 用 pcntl_fork 出来，共用同一个开始时刻，分桶对齐误差 < 1 个桶
//   - fork 之后子进程必须【重新连接 Redis】，不能共用父进程的 socket
declare(strict_types=1);
require __DIR__ . '/ratelimit.php';

const DURATION_MS  = 3000;
const BUCKET_MS    = 100;
const NBUCKETS     = DURATION_MS / BUCKET_MS;
const WORKERS      = 8;
const OFFER_PER_S  = 400;                        // 总目标压力
const LIMIT        = 200;                        // 限流阈值：200 次/秒

function now_ms(): float { return microtime(true) * 1000; }
function atomic_inc(string $file, int $n): void
{
    $fh = fopen($file, 'c+');
    flock($fh, LOCK_EX);
    $cur = (int)stream_get_contents($fh);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)($cur + $n));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}
function read_inc(string $file): int
{
    return is_file($file) ? (int)file_get_contents($file) : 0;
}

$R = RateLimiter::conn();
echo "PHP: " . PHP_VERSION . "   Redis: " . $R->info('server')['redis_version'] . "\n";
printf("压力: %d worker x %d 次/秒 = %d 次/秒，持续 %d ms；限流阈值 %d 次/秒\n",
    WORKERS, intdiv(OFFER_PER_S, WORKERS), OFFER_PER_S, DURATION_MS, LIMIT);
printf("分桶: 每 %d ms 一个桶，共 %d 个桶\n", BUCKET_MS, NBUCKETS);
echo str_repeat('=', 108), "\n";

/** @return array<string, callable(Redis):RateLimiter> */
function factories(): array
{
    return [
        'fixed_window'    => fn(Redis $r) => new FixedWindow(LIMIT, 1000, $r),
        'sliding_log'     => fn(Redis $r) => new SlidingWindowLog(LIMIT, 1000, $r),
        'sliding_counter' => fn(Redis $r) => new SlidingWindowCounter(LIMIT, 1000, $r),
        'token_bucket'    => fn(Redis $r) => new TokenBucket((float)LIMIT, LIMIT, $r),
        'leaky_bucket'    => fn(Redis $r) => new LeakyBucket((float)LIMIT, LIMIT, $r),
    ];
}

$results = [];
foreach (factories() as $algo => $factory) {
    // 清空本算法上一轮留下的 key
    $factory($R)->reset('load');

    $files = [];
    for ($i = 0; $i < WORKERS; $i++) {
        $f = "/tmp/q43-bucket-{$algo}-{$i}.json";
        @unlink($f);
        $files[] = $f;
    }
    @unlink('/tmp/q43-attempts');
    file_put_contents('/tmp/q43-attempts', '0');

    $t0 = now_ms() + 150;                 // 给 fork 留出时间，所有 worker 对齐到这个时刻开始
    $pids = [];
    for ($i = 0; $i < WORKERS; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "fork 失败\n");
            exit(1);
        }
        if ($pid === 0) {
            // ---- 子进程 ----
            $r = RateLimiter::conn();          // 必须重连，不能共用父进程的 socket
            $lim = $factory($r);
            $buckets = array_fill(0, NBUCKETS + 1, 0);
            $interval = 1000 / (OFFER_PER_S / WORKERS);   // 每次请求间隔（【毫秒】，和 now_ms 同单位）
            $next = $t0;
            while (true) {
                $now = now_ms();
                if ($now - $t0 >= DURATION_MS) break;
                if ($now < $t0) { $next = $t0; usleep(500); continue; }  // 等到统一起跑线
                $res = $lim->allow('load', (int)$now);
                atomic_inc('/tmp/q43-attempts', 1);
                $b = intdiv((int)($now - $t0), BUCKET_MS);
                if ($b <= NBUCKETS && $res['allow']) $buckets[$b]++;
                $next += $interval;
                if ($next < now_ms()) $next = now_ms();      // 落后了就重新对齐，避免越睡越晚
                $d = $next - now_ms();
                if ($d > 0) usleep((int)round($d * 1000));   // ms -> us
            }
            file_put_contents($files[$i], json_encode($buckets));
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);

    $merged = array_fill(0, NBUCKETS, 0);
    foreach ($files as $f) {
        $b = json_decode((string)@file_get_contents($f), true) ?: [];
        foreach ($merged as $k => $_) $merged[$k] += $b[$k] ?? 0;
    }
    $results[$algo] = ['buckets' => $merged, 'attempts' => read_inc('/tmp/q43-attempts')];
}

// ---------------------------------------------------------------------------
// 输出：每个算法的放行曲线 + 汇总
// ---------------------------------------------------------------------------
echo "\n放行曲线（每格 = 100ms 内【通过】的请求数，一行 30 格 = 3 秒）\n";
echo str_repeat('-', 108), "\n";
foreach ($results as $algo => $d) {
    printf("  %-16s ", $algo);
    foreach ($d['buckets'] as $n) {
        printf('%3d', $n);
    }
    printf("   峰值 %d\n", max($d['buckets']));
}
echo "\n";
echo "  " . str_repeat(' ', 16);
foreach (range(0, NBUCKETS - 1) as $i) {
    printf('%3s', $i % 10 === 0 ? 's' : '·');
}
echo "   (s = 整秒边界)\n";

echo "\n汇总\n";
echo str_repeat('-', 108), "\n";
printf("  %-16s %-12s %-12s %-12s %-14s %s\n",
    '算法', '实际压力', '通过', '拒绝', '实际放行速率', '曲线形态');
foreach ($results as $algo => $d) {
    $pass = array_sum($d['buckets']);
    $att  = $d['attempts'];
    $zero = count(array_filter($d['buckets'], fn($n) => $n === 0));
    printf("  %-16s %-12s %-12s %-12s %-14s 峰值/均值 = %.2f\n",
        $algo,
        sprintf('%d 次/秒', (int)round($att / (DURATION_MS / 1000))),
        sprintf('%d', $pass),
        sprintf('%d', $att - $pass),
        sprintf('%.0f 次/秒', $pass / (DURATION_MS / 1000)),
        max($d['buckets']) / max(0.001, $pass / NBUCKETS));
}
echo "\n  注：漏桶是排队算法，这里的「通过」= 收进桶里（还没轮到发出），所以它前期几乎全收，\n";
echo "      直到桶装满(cap=200)才开始拒绝 —— 拒绝不代表压力消失，只代表积压到了上限。\n";

foreach (glob('/tmp/q43-bucket-*.json') as $f) @unlink($f);
@unlink('/tmp/q43-attempts');
