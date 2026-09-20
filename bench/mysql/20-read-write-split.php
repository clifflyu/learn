<?php
/**
 * Q23 附：读写分离的「写后立刻读从库」到底有多容易读到旧数据？
 *
 * 用法: docker exec learn-php php /app/bench/mysql/20-read-write-split.php [轮数]
 *
 * 为什么要用 PHP 而不是 shell + docker exec：
 *   每轮要「写主库 → 读从库 → 轮询到可见」，用 docker exec 每次都要起进程 + 建连接（~30ms），
 *   而同一台宿主机上的主从陈旧窗口只有零点几毫秒，窗口会被进程启动开销完全淹没。
 *   这里用两条常驻 PDO 连接（主、从各一条），才能量到真实的陈旧窗口。
 */

$rounds = (int)($argv[1] ?? 500);
$dsnM = 'mysql:host=learn-mysql;port=3306;dbname=repl;charset=utf8mb4';
$dsnS = 'mysql:host=q23-slave;port=3306;dbname=repl;charset=utf8mb4';
$opt  = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

$M = new PDO($dsnM, 'root', 'root', $opt);
$S = new PDO($dsnS, 'root', 'root', $opt);

// id 用 INT 就够：这里只要「每轮一个独一无二、且肯定没出现过的 id」
$M->exec("DROP TABLE IF EXISTS rw");
$M->exec("CREATE TABLE rw (id INT PRIMARY KEY, v INT, ts DATETIME(6)) ENGINE=InnoDB");
$M->exec("TRUNCATE TABLE rw");
// 等从库把 TRUNCATE 也回放掉，否则下面的「从库读不到」可能只是还没清空
$S->query("SELECT 1")->fetchAll();
$t0 = microtime(true);
while (true) {
    $mp = (int)$M->query("SHOW BINARY LOG STATUS")->fetch(PDO::FETCH_ASSOC)['Position'];
    $sp = (int)($S->query("SHOW REPLICA STATUS")->fetch(PDO::FETCH_ASSOC)['Exec_Source_Log_Pos'] ?? 0);
    if ($sp >= $mp) { break; }
    if (microtime(true) - $t0 > 30) { break; }
    usleep(200);
}

// 【踩过的坑】原来写的是 (int)(microtime(true)*1000) << 20 —— 约 1.9e18，
//   塞进 INT 列直接报 1264 Out of range。id 只要唯一即可，不用真雪花。
$base = 800000000 + (time() % 100000) * 10000;
$ins  = $M->prepare("INSERT INTO rw (id, v, ts) VALUES (?, ?, NOW(6))");
$sel  = $S->prepare("SELECT v FROM rw WHERE id = ?");
$selM = $M->prepare("SELECT v FROM rw WHERE id = ?");

$miss = 0; $waits = [];
$tAll = microtime(true);
for ($i = 0; $i < $rounds; $i++) {
    $id = $base + $i;
    $ins->execute([$id, $i]);                 // 写主库（autocommit）
    $t1 = microtime(true);
    $sel->execute([$id]);
    if ($sel->fetchColumn() === false) {      // 从库还看不到 = 陈旧读
        $miss++;
        while (true) {                        // 「等位点」方案的代价：轮询到可见为止
            $sel->execute([$id]);
            if ($sel->fetchColumn() !== false) { break; }
            if (microtime(true) - $t1 > 5) { break; }
            usleep(100);
        }
        $waits[] = (microtime(true) - $t1) * 1000;
    }
}
$elapsed = microtime(true) - $tAll;

printf("轮数 = %d（每轮：写主库 → 立刻按主键读从库）\n", $rounds);
printf("读到旧数据（从库查不到这一行）的次数 = %d / %d = %.1f%%\n", $miss, $rounds, $miss / $rounds * 100);
if ($waits) {
    sort($waits);
    printf("「轮询等到可见」耗时：min %.2fms  p50 %.2fms  p90 %.2fms  max %.2fms  平均 %.2fms\n",
        $waits[0], $waits[(int)(count($waits) * 0.5)], $waits[(int)(count($waits) * 0.9)],
        end($waits), array_sum($waits) / count($waits));
}
printf("整轮总耗时 %.2fs（平均 %.2fms/轮）\n", $elapsed, $elapsed / $rounds * 1000);

// 主从单行点查延迟对比：读写分离真正的收益不在「单条查询快多少」
$t = microtime(true);
for ($i = 0; $i < 2000; $i++) { $selM->execute([$base + $i % $rounds]); $selM->fetchColumn(); }
$msM = (microtime(true) - $t) * 1000;
$t = microtime(true);
for ($i = 0; $i < 2000; $i++) { $sel->execute([$base + $i % $rounds]); $sel->fetchColumn(); }
$msS = (microtime(true) - $t) * 1000;
printf("同一条主键点查 ×2000：主库 %.0fms（%.3fms/次）  从库 %.0fms（%.3fms/次）\n",
    $msM, $msM / 2000, $msS, $msS / 2000);
