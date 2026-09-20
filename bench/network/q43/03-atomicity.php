<?php
// Q43 为什么限流必须用 Lua：把「读-判断-写」拆成三条 Redis 命令会漏。
//
//   docker exec learn-php php /app/bench/network/q43/03-atomicity.php
//
// 同样的 8 个并发进程、同样的限值（50 次/秒），跑两个版本：
//   A 非原子：GET 当前值 → 判断 → INCR      （PHP 里最常见的写法）
//   B 原子  ：一段 Lua 脚本里完成全部判断
// 只要读和写之间有哪怕一点间隙，所有 worker 就会读到同一个旧值，一起放行。
declare(strict_types=1);

const LIMIT    = 50;
const DURATION = 1000;    // ms
const WORKERS  = 8;

function now_ms(): float { return microtime(true) * 1000; }

$R = new Redis();
$R->connect('learn-redis', 6379, 1.0);
echo "PHP: " . PHP_VERSION . "   Redis: " . $R->info('server')['redis_version'] . "\n";
printf("限值 %d 次 / 1000 ms，%d 个并发进程猛打 %d ms\n", LIMIT, WORKERS, DURATION);
echo str_repeat('=', 78), "\n";

/** A. 非原子：三条独立命令，中间还夹着一次网络往返 */
$naive = <<<'PHP'
$n = (int)$r->get($k);
// 现实中这里可能是一次 DB 查询、一次日志、甚至只是 Redis 的另一条命令
if ($n < $limit) {
    $r->incr($k);              // ← 别的进程在这之前也读到了同一个 $n
    allow();
} else {
    deny();
}
PHP;

$lua_ver = <<<'LUA'
local n = tonumber(redis.call('GET', KEYS[1]) or 0)
if n < tonumber(ARGV[1]) then
  redis.call('INCR', KEYS[1])
  return 1
end
return 0
LUA;

// 注意：窗口必须【远长于】压测时长，否则计数器会在测试中途翻页，
// 多出来的放行数就变成「换了新窗口」而不是「并发漏了」—— 第一版就踩了这个坑。
function run_case(Redis $R, string $mode, string $lua, int $extra_us = 0): array
{
    $key = "q43:atomic:$mode";
    $R->del($key);
    $t0 = now_ms() + 150;
    $pids = [];
    for ($i = 0; $i < WORKERS; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $r = new Redis();
            $r->connect('learn-redis', 6379, 1.0);
            $ok = 0; $no = 0;
            while (true) {
                $now = now_ms();
                if ($now - $t0 >= DURATION) break;
                if ($now < $t0) { usleep(500); continue; }
                if ($mode === 'naive') {
                    // ↓↓↓ 非原子：读、判断、写是三次独立的往返
                    $n = (int)$r->get($key);
                    if ($n < LIMIT) {
                        if ($extra_us > 0) usleep($extra_us);   // 临界区里的其他活儿
                        $r->incr($key);                         // ← 此刻别人也读到了同一个 $n
                        $ok++;
                    } else {
                        $no++;
                    }
                } else {
                    $res = $r->eval($lua, [$key, LIMIT], 1);
                    $res ? $ok++ : $no++;
                }
            }
            file_put_contents("/tmp/q43-atomic-$mode-$i", "$ok $no");
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);

    $ok = $no = 0;
    for ($i = 0; $i < WORKERS; $i++) {
        [$a, $b] = array_map('intval', explode(' ', (string)file_get_contents("/tmp/q43-atomic-$mode-$i")));
        $ok += $a; $no += $b;
        @unlink("/tmp/q43-atomic-$mode-$i");
    }
    return ['ok' => $ok, 'no' => $no, 'final' => (int)$R->get($key)];
}

$rows = [];
$rows['A 非原子 (GET→判断→INCR)']        = run_case($R, 'naive', $lua_ver);
$rows['B 非原子 + 临界区多花 200us']     = run_case($R, 'naive', $lua_ver, 200);
$rows['C 原子 (整段 Lua)'            ]   = run_case($R, 'lua',   $lua_ver);

printf("\n  %-32s %-8s %-8s %-14s %s\n", '版本', '放行', '拒绝', '计数器最终值', '放行 / 限值');
foreach ($rows as $name => $a) {
    printf("  %-32s %-8s %-8s %-14s %.2fx%s\n",
        $name, $a['ok'], $a['no'], $a['final'], $a['ok'] / LIMIT,
        $a['ok'] > LIMIT ? '   ← 漏了' : '   (精确)');
}
echo "\n  ⇒ 非原子版本放行的比限值多。原因不是「Redis 慢」，而是「读」和「写」之间\n";
echo "     有真实的时间窗口，并发进程在这段时间里全都读到同一个旧值，然后各加各的。\n";
echo "     临界区里越忙（查库、鉴权、写日志），窗口越大，漏得越多。\n";
echo "     判定和状态更新必须在同一个原子操作里完成 —— 这就是 Lua 的意义。\n";

$R->del("q43:atomic:naive", "q43:atomic:lua");
