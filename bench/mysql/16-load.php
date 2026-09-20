<?php
/**
 * Q23 主从延迟实验的压测端：在主库上制造「许多互不冲突的小事务」。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/16-load.php <并发连接数> <每连接事务数> [表名]
 *
 * 为什么不用 shell 里 for + docker exec 发 INSERT：
 *   每个 docker exec 都要起一个进程 + 建一次 TCP 连接，2000 条要几十秒，
 *   压出来的「延迟」其实是进程启动开销，不是数据库负载（第一版就踩了这个坑）。
 *   用 PHP 常驻连接发小事务，才是真的在测数据库。
 */

$conns = (int)($argv[1] ?? 20);
$txns  = (int)($argv[2] ?? 100);
$table = $argv[3] ?? 'small';

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=repl;charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

// 建表（幂等）
$setup = new PDO($dsn, 'root', 'root', $opt);
$setup->exec("CREATE TABLE IF NOT EXISTS $table (
  id INT AUTO_INCREMENT PRIMARY KEY, v INT, pad VARCHAR(80)) ENGINE=InnoDB");
$setup->exec("TRUNCATE TABLE $table");
$setup = null;

$pids = [];
for ($c = 0; $c < $conns; $c++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $pdo = new PDO($dsn, 'root', 'root', $opt);
        $pad = str_repeat('x', 60);
        $st  = $pdo->prepare("INSERT INTO $table (v, pad) VALUES (?, ?)");
        for ($i = 0; $i < $txns; $i++) {
            $pdo->beginTransaction();
            $st->execute([$c, $pad]);
            $pdo->commit();
        }
        exit(0);
    }
    $pids[] = $pid;
}

$t0 = microtime(true);
foreach ($pids as $p) { pcntl_waitpid($p, $st); }
// 这里不能用 fwrite 之外的输出影响父 shell，只报一行统计
fwrite(STDERR, sprintf("  [load] %d 连接 × %d 事务 = %d 个小事务，耗时 %.1fs\n",
    $conns, $txns, $conns * $txns, microtime(true) - $t0));
