<?php
/**
 * Q46 防刷实测：并发下的频控与一人一单
 *
 * 用法: docker exec learn-php php /app/bench/design/03-antibrush.php
 *
 * 六组对照
 *   A) 非原子「GET 判断 + SET」        —— 经典错误写法，并发下大量漏过
 *   B) SET NX EX 频控                  —— 同一用户 N 秒内只放行 1 次
 *   C) 计数器 INCR + 单独 EXPIRE       —— 计数本身没错，但 TTL 和计数不是一个原子操作
 *   D) Lua 原子 INCR + EXPIRE          —— 计数与 TTL 绑定，兜住 C 的坑
 *   E) ZSet 滑动窗口 vs 固定窗口       —— 固定窗口的临界突刺实测
 *   F) 一人一单：DB 唯一键兜底          —— 频控被绕过时最后的防线
 */

$redisHost = 'learn-redis';
$red = function () use ($redisHost) {
    $r = new Redis();
    $r->connect($redisHost, 6379, 2.0);
    $r->setOption(Redis::OPT_READ_TIMEOUT, 10.0);
    return $r;
};
$dsn = 'mysql:host=learn-mysql;port=3306;dbname=q46_seckill;charset=utf8mb4';
$pdoOpt = [
    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];
$db = fn() => new PDO($dsn, 'root', 'root', $pdoOpt);

$PFX  = 'q46:';               // 自己的 key 前缀
$FILE = '/tmp/q46-brush.tsv';

/** 把 N 个闭包并发跑起来（每个一个子进程），按行收回结果字符串 */
function fanout(int $n, callable $fn, string $file): array
{
    @unlink($file);
    $pids = []; $relays = [];
    for ($i = 0; $i < $n; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid  = pcntl_fork();
        if ($pid === -1) { fclose($pair[0]); fclose($pair[1]); break; }
        if ($pid === 0) {
            fclose($pair[1]);
            fread($pair[0], 1);                 // 等父进程放行
            fclose($pair[0]);
            try   { $res = (string)$fn($i); }
            catch (Throwable $e) { $res = 'ERR:' . substr($e->getMessage(), 0, 80); }
            file_put_contents($file, str_replace("\n", ' ', $res) . "\n", FILE_APPEND | LOCK_EX);
            exit(0);
        }
        fclose($pair[0]);
        $relays[] = $pair[1];
        $pids[]   = $pid;
    }
    foreach ($relays as $w) { fwrite($w, 'x'); fclose($w); }
    foreach ($pids as $pid) { pcntl_waitpid($pid, $st); }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    sort($lines);
    return $lines;
}

function tally(array $lines, string $pass, string $block): void
{
    $p = $b = $o = 0;
    foreach ($lines as $l) {
        if (str_contains($l, $pass))       $p++;
        elseif (str_contains($l, $block))  $b++;
        else                               $o++;
    }
    printf("  放行 %d / 拦截 %d%s\n", $p, $b, $o ? " / 其它 {$o}" : '');
}

/** Redis BUSY 容忍（本机 Redis 是共享的） */
function rc(callable $f, int $tries = 20)
{
    for ($i = 0; $i < $tries; $i++) {
        try { return $f(); } catch (RedisException $e) { usleep(500000); }
    }
    throw new RuntimeException('Redis 持续 busy');
}

$v = rc(fn() => $red()->info()['redis_version']);
echo "Redis {$v} / PHP ", PHP_VERSION, " / 同一用户并发 50\n";

// ============================================================
echo "\n", str_repeat('=', 72), "\n";
echo "A) 非原子 GET 判断 + SET（错误写法）\n";
echo str_repeat('=', 72), "\n";
// 每个请求：先 GET 看有没有被限，没有就 SET 上去。GET 和 SET 之间就是竞态窗口。
$keyA = "{$PFX}brush:A:user1";
rc(fn() => $red()->del($keyA));
$resA = fanout(50, function ($i) use ($red, $keyA) {
    $r = rc(fn() => $red());
    if ($r->exists($keyA)) return 'BLOCK';
    usleep(1000);                   // 模拟真实的业务处理耗时
    $r->setex($keyA, 5, '1');
    return 'PASS';
}, $FILE);
tally($resA, 'PASS', 'BLOCK');
echo "  预期：窗口内只该放行 1 个\n";

// ============================================================
echo "\n", str_repeat('=', 72), "\n";
echo "B) SET NX EX（一条命令完成「判断+占位+过期」）\n";
echo str_repeat('=', 72), "\n";
$keyB = "{$PFX}brush:B:user1";
rc(fn() => $red()->del($keyB));
$resB = fanout(50, function ($i) use ($red, $keyB) {
    $r  = rc(fn() => $red());
    $ok = $r->set($keyB, '1', ['nx', 'ex' => 5]);
    if (!$ok) return 'BLOCK';
    usleep(1000);
    return 'PASS';
}, $FILE);
tally($resB, 'PASS', 'BLOCK');

// 不同用户之间不能互相误伤
$resB2 = fanout(50, function ($i) use ($red, $PFX) {
    $r = rc(fn() => $red());
    $k = "{$PFX}brush:B:user" . ($i + 100);
    return $r->set($k, '1', ['nx', 'ex' => 5]) ? 'PASS' : 'BLOCK';
}, $FILE);
echo "  50 个不同用户各发 1 次：";
tally($resB2, 'PASS', 'BLOCK');
foreach (rc(fn() => $red()->keys("{$PFX}brush:B:user1*")) as $k) { rc(fn() => $red()->del($k)); }

// ============================================================
// C/D) 计数器限流：10 秒窗口最多 5 次
// ============================================================
$LIMIT = 5;
echo "\n", str_repeat('=', 72), "\n";
echo "C) INCR + 单独 EXPIRE（计数原子，TTL 不原子）\n";
echo str_repeat('=', 72), "\n";
$keyC = "{$PFX}brush:C:user1";
rc(fn() => $red()->del($keyC));
$resC = fanout(50, function ($i) use ($red, $keyC, $LIMIT) {
    $r = rc(fn() => $red());
    $n = $r->incr($keyC);
    if ($n === 1) {
        // 只有第一次设 TTL。如果这里进程崩了 / 被 kill，key 就永远不过期 —— 用户被永久封禁
        $r->expire($keyC, 10);
    }
    return $n <= $LIMIT ? 'PASS' : 'BLOCK';
}, $FILE);
tally($resC, 'PASS', 'BLOCK');
$ttlC = rc(fn() => $red()->ttl($keyC));
echo "  计数 = ", rc(fn() => $red()->get($keyC)), "，剩余 TTL = {$ttlC} s\n";
// 演示坑：把计数写成 7 但不设 TTL，模拟「INCR 之后进程被杀」
rc(fn() => $red()->set($keyC, '7'));
echo "  模拟「INCR 之后、EXPIRE 之前进程被杀」：计数 = 7，TTL = ",
     rc(fn() => $red()->ttl($keyC)), " s（-1 = 永不过期 → 这个用户被永久限流）\n";

echo "\n", str_repeat('=', 72), "\n";
echo "D) Lua 原子 INCR + EXPIRE\n";
echo str_repeat('=', 72), "\n";
$LUA_LIMIT = <<<'LUA'
local n = redis.call('INCR', KEYS[1])
if n == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
return n
LUA;
$keyD = "{$PFX}brush:D:user1";
rc(fn() => $red()->del($keyD));
$resD = fanout(50, function ($i) use ($red, $keyD, $LIMIT, $LUA_LIMIT) {
    $r = rc(fn() => $red());
    $n = (int)$r->eval($LUA_LIMIT, [$keyD, 10], 1);
    return $n <= $LIMIT ? 'PASS' : 'BLOCK';
}, $FILE);
tally($resD, 'PASS', 'BLOCK');
echo "  计数 = ", rc(fn() => $red()->get($keyD)), "，剩余 TTL = ",
     rc(fn() => $red()->ttl($keyD)), " s（计数和 TTL 一定同时存在）\n";

// ============================================================
// E) 固定窗口的临界突刺 vs 滑动窗口（窗口 2 s，限额 5）
//    固定窗口的真实实现是「按 wall-clock 分桶」：key = rate:user:<时间戳/窗口>。
//    于是窗口边界一到就换 key，计数从 0 开始 —— 这就是突刺的根源。
//    踩过的坑：一开始用「预置 key 值=0 + EXPIRE」来模拟固定窗口，结果第一批的 INCR
//    返回 1，命中 `if n==1 then EXPIRE` 分支把 TTL 续上了，窗口永远不滚动，
//    测出来的结果跟预期正好相反。分桶 key 才是对固定窗口的忠实还原。
//    另外两批必须用父进程给的**绝对时刻**发车：fork + 放行有几十到几百 ms 抖动，
//    相对 usleep 会把窗口边界吃掉。
// ============================================================
$W = 2;                                  // 窗口 2 s
$tKey = microtime(true);
$boundary = (floor($tKey / $W) + 1) * $W;            // 下一个 wall-clock 窗口边界
if ($boundary - $tKey < 0.4) { $boundary += $W; }    // 留够第一批的准备时间
$t1 = $boundary - 0.10;                  // 批1：窗口末尾
$t2 = $boundary + 0.10;                  // 批2：下一个窗口开头

echo "\n", str_repeat('=', 72), "\n";
printf("E1) 固定窗口（按 %d s 分桶）：边界落在两批中间 → 两批都放行\n", $W);
echo str_repeat('=', 72), "\n";
$keyE = "{$PFX}brush:E1:user1";
rc(function () use ($red, $PFX) { foreach ($red()->keys("{$PFX}brush:E1:*") as $k) $red()->del($k); return 1; });
$resE = fanout(10, function ($i) use ($red, $keyE, $LUA_LIMIT, $t1, $t2, $W) {
    $r = rc(fn() => $red());
    $target = $i < 5 ? $t1 : $t2;
    while (microtime(true) < $target) usleep(1000);
    $slot = intdiv((int)microtime(true), $W);         // 请求时刻落在哪个桶
    $n = (int)$r->eval($LUA_LIMIT, ["{$keyE}:{$slot}", $W], 1);
    return $n <= 5 ? 'PASS' : 'BLOCK';
}, $FILE);
tally($resE, 'PASS', 'BLOCK');
printf("  限额 5/%ds，两批相隔 0.2 s，合计放行了上面这么多\n", $W);

$keyE2 = "{$PFX}brush:E2:user1";
rc(fn() => $red()->del($keyE2));
$LUA_SLIDE = <<<'LUA'
local now    = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local limit  = tonumber(ARGV[3])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
local n = redis.call('ZCARD', KEYS[1])
if n >= limit then return 0 end
redis.call('ZADD', KEYS[1], now, ARGV[4])
redis.call('PEXPIRE', KEYS[1], window)
return 1
LUA;
echo "\nE2) 滑动窗口（ZSet）：同样的时刻发车，第二批被拦住\n";
echo str_repeat('=', 72), "\n";
$resE2 = fanout(10, function ($i) use ($red, $keyE2, $LUA_SLIDE, $t1, $t2, $W) {
    $r = rc(fn() => $red());
    $target = $i < 5 ? $t1 : $t2;
    while (microtime(true) < $target) usleep(1000);
    [$us, $sec] = explode(' ', microtime());
    $now = (int)$sec * 1000 + (int)round($us / 1000);
    return $r->eval($LUA_SLIDE, [$keyE2, $now, $W * 1000, 5, $i], 1) ? 'PASS' : 'BLOCK';
}, $FILE);
tally($resE2, 'PASS', 'BLOCK');
printf("  批1 的 5 条记录在第二批到达时仍在 %d s 窗口内，所以一个都过不去\n", $W);

// ============================================================
// F) 一人一单：DB 唯一键兜底
// ============================================================
echo "\n", str_repeat('=', 72), "\n";
echo "F) 一人一单：50 个并发请求抢同一用户同一商品（q46_seckill.orders）\n";
echo str_repeat('=', 72), "\n";
$p = $db();
$p->exec('TRUNCATE TABLE orders');
$p->exec('UPDATE stock SET stock = 100 WHERE goods_id = 1');
$p = null;

$resF = fanout(50, function ($i) use ($db) {
    $p = $db();
    try {
        $p->exec("INSERT INTO orders (goods_id, user_id, status, created_at) VALUES (1, 777, 0, NOW(3))");
        return 'PASS';
    } catch (PDOException $e) {
        // 1062 = Duplicate entry
        return $e->errorInfo[1] === 1062 ? 'DUP' : 'ERR:' . $e->errorInfo[1];
    }
}, $FILE);
tally($resF, 'PASS', 'DUP');
$p = $db();
printf("  orders 表里 user_id=777 的行数 = %d\n",
    $p->query('SELECT COUNT(*) FROM orders WHERE user_id = 777')->fetchColumn());
$p = null;

// 清理
rc(function () use ($red, $PFX) {
    $r = $red();
    foreach ($r->keys("{$PFX}brush:*") as $k) { $r->del($k); }
    return 1;
});
echo "\n完成（q46:brush:* 已清理）。\n";
