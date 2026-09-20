<?php
// Q28 / Q29 / Q30 共用的连接与计时工具。只做连接和读数，不含业务逻辑。
// 这三个问题的实验都要「多个真实进程 + 回源次数统计」，所以单独放一个文件。

function rconn(): Redis {
    $r = new Redis();
    $r->connect('learn-redis', 6379, 2.0);
    $r->setOption(Redis::OPT_READ_TIMEOUT, 10.0);
    return $r;
}

function dbconn(bool $withDb = true): PDO {
    $dsn = 'mysql:host=learn-mysql;port=3306;charset=utf8mb4' . ($withDb ? ';dbname=q28_cache' : '');
    return new PDO($dsn, 'root', 'root', [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

// 本会话（= 这一条连接）自己执行了多少条 SELECT。
// 必须用 SESSION 而不是 GLOBAL：这台 MySQL 上还有别的会话在跑实验，
// 实测 GLOBAL 的 Com_select 空转噪声就有 1200~4300 q/s，任何 Δ 都被淹掉。
// SESSION 口径实测空转 200ms 漂移恰好为 0，100 次 prepared SELECT 就 +100。
function db_selects(PDO $pdo): int {
    return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
}

function ms(): int { return (int)(microtime(true) * 1000); }

// 绝对时刻调度：所有进程 sleep 到 base+offsetMs 再动作，做出确定的交错时序。
// 不用「先 A 再 sleep，再 B」那种相对写法 —— 进程启动、自动加载的耗时会漂。
function at(int $baseMs, int $offsetMs): void {
    $target = ($baseMs + $offsetMs) / 1000.0;
    while (($now = microtime(true)) < $target) {
        $d = $target - $now;
        if ($d > 0.003) usleep((int)($d * 1_000_000) - 1500);
        else            usleep(100);
    }
}

// fork 之前必须把父进程已经开好的连接置空（$pdo = null; $r = null;），fork 之后
// 重新 rconn()/dbconn()。
// 原因：子进程 exit() 时会析构从父进程继承来的连接对象，往同一个 socket 上发
// COM_QUIT —— MySQL/Redis 收到就把这条会话掐了，父进程 fork 之后再用这个句柄就是
// 「SQLSTATE[HY000] 2006 MySQL server has gone away」。
function fork_all(int $n, callable $fn): void {
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) { fwrite(STDERR, "fork 失败\n"); exit(1); }
        if ($pid === 0) {
            $rc = 0;
            try { $fn($i); } catch (Throwable $e) { fwrite(STDERR, "子进程 $i: {$e->getMessage()}\n"); $rc = 1; }
            exit($rc);                      // 子进程必须 exit，否则会继续跑父进程后面的代码
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) pcntl_waitpid($pid, $st);
}

// 只清自己的 key。这台 Redis 上可能还有别人的数据，不用 FLUSHALL。
function prefix_cleanup(Redis $r, string $pfx): int {
    $it = null; $n = 0;
    while (($keys = $r->scan($it, $pfx . '*', 1000)) !== false) {
        if ($keys) { $r->del($keys); $n += count($keys); }
        if ($it === 0) break;
    }
    return $n;
}
