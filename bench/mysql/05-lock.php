<?php
/**
 * Q20 InnoDB 锁：行锁、锁等待、死锁
 *
 * 用法: docker exec learn-php php /app/bench/mysql/05-lock.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [
    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];

$conn = fn() => new PDO($dsn, 'root', 'root', $opt);

$setup = $conn();
$setup->exec("DROP TABLE IF EXISTS stock");
$setup->exec("CREATE TABLE stock (id INT PRIMARY KEY, qty INT NOT NULL) ENGINE=InnoDB");
$setup->exec("INSERT INTO stock VALUES (1, 100), (2, 100)");

echo "MySQL ", $setup->query('SELECT VERSION()')->fetchColumn(), "\n";

// ---------- 1. 行锁等待 ----------
echo "\n========== 1) 行锁：两个事务改同一行 ==========\n";
$a = $conn();
$b = $conn();
$b->exec("SET SESSION innodb_lock_wait_timeout = 3");

$a->beginTransaction();
$a->exec("UPDATE stock SET qty = qty - 1 WHERE id = 1");
echo "A 已锁住 id=1\n";

$t = microtime(true);
try {
    $b->exec("UPDATE stock SET qty = qty - 1 WHERE id = 1");
    echo "B 直接改成功（不该发生）\n";
} catch (PDOException $e) {
    printf("B 被阻塞 %.1f s 后失败: %s\n", microtime(true) - $t, substr($e->getMessage(), 0, 60));
}
$a->commit();

// 改不同行则不阻塞
$a->beginTransaction();
$a->exec("UPDATE stock SET qty = qty - 1 WHERE id = 1");
$t = microtime(true);
$b->exec("UPDATE stock SET qty = qty - 1 WHERE id = 2");
printf("A 锁 id=1 时，B 改 id=2 耗时 %.3f s（不同行不冲突）\n", microtime(true) - $t);
$a->commit();

// ---------- 2. 死锁 ----------
echo "\n========== 2) 死锁：相反顺序加锁（必须真并发，用 pcntl_fork）==========\n";

/*
 * 【踩过的坑】单进程顺序写是**制造不出死锁**的：
 *   $a->exec("UPDATE ... id=2");   // 阻塞住整个 PHP 进程
 *   $b->exec("UPDATE ... id=1");   // 这一行永远轮不到执行，环根本闭不上
 * 结果只会拿到 1205 Lock wait timeout exceeded，而不是 1213 Deadlock found。
 * 死锁的成立条件是「A 持有 X 等 Y，同时 B 持有 Y 等 X」——两个连接必须**同时**在等，
 * 所以必须真并发。这里用 pcntl_fork 开子进程（php-cli 自带 pcntl）。
 *
 * 另一个坑：fork 出来的子进程会继承父进程的 PDO 连接。父子共用一个 socket 会互相踩坏协议，
 * 所以两个分支都是 **fork 之后再各自 new PDO**，不共用连接。
 */

$dsnG = $dsn;
$optG = $opt;

$pid = pcntl_fork();
if ($pid === -1) {
    echo "  pcntl_fork 失败，跳过\n";
} elseif ($pid === 0) {
    // ===== 子进程：会话 B，先锁 id=2，再去要 id=1 =====
    $b = new PDO($dsnG, 'root', 'root', $optG);
    $b->exec("SET SESSION innodb_lock_wait_timeout = 10");
    $b->beginTransaction();
    $b->exec("UPDATE stock SET qty = qty - 1 WHERE id = 2");
    fwrite(STDOUT, "  [B] 已持有 id=2，等 0.4s 让 A 先拿到 id=1\n"); fflush(STDOUT);
    usleep(400000);
    try {
        $b->exec("UPDATE stock SET qty = qty - 1 WHERE id = 1");
        fwrite(STDOUT, "  [B] 拿到 id=1（没死锁？）\n");
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        fwrite(STDOUT, sprintf("  [B] 被回滚：SQLSTATE=%s errno=%s → %s\n",
            $e->getCode(), $code,
            $code == 1213 ? 'DEADLOCK（InnoDB 主动挑一个牺牲者回滚）' : substr($e->getMessage(), 0, 60)));
    }
    try { $b->rollBack(); } catch (Throwable $e) {}
    fflush(STDOUT);
    exit(0);
}

// ===== 父进程：会话 A，先锁 id=1，再去要 id=2 =====
$a = new PDO($dsnG, 'root', 'root', $optG);
$a->exec("SET SESSION innodb_lock_wait_timeout = 10");
$a->beginTransaction();
$a->exec("UPDATE stock SET qty = qty - 1 WHERE id = 1");
echo "  [A] 已持有 id=1，等 0.4s 让 B 先拿到 id=2\n";
usleep(400000);
try {
    $a->exec("UPDATE stock SET qty = qty - 1 WHERE id = 2");
    echo "  [A] 拿到 id=2（没死锁？）\n";
} catch (PDOException $e) {
    $code = $e->errorInfo[1] ?? 0;
    printf("  [A] 被回滚：SQLSTATE=%s errno=%s → %s\n", $e->getCode(), $code,
        $code == 1213 ? 'DEADLOCK（InnoDB 主动挑一个牺牲者回滚）' : substr($e->getMessage(), 0, 60));
}
try { $a->rollBack(); } catch (Throwable $e) {}
pcntl_waitpid($pid, $st);

/*
 * 【踩过的坑】第三个坑：子进程 exit 时，PHP 会析构它**从父进程继承来的**那个 $setup 连接，
 * 析构会往 socket 上发一个 COM_QUIT —— 服务端就把这个 session 关了。
 * 于是父进程手里那个 $setup 变成一具尸体，下一句 query 直接报
 *   SQLSTATE[HY000] 2006 MySQL server has gone away
 * 解决：waitpid 之后重建 $setup。（同理，所以两个分支都是在 fork **之后**才 new PDO。）
 */
$setup = $conn();

/*
 * 【踩过的坑】MySQL 8.4 **没有**全局死锁计数器。
 * SHOW GLOBAL STATUS LIKE '%deadlock%' 实测返回 0 行（没有 Innodb_deadlocks 这个变量）。
 * 所以死锁次数只能靠：
 *   (a) innodb_print_all_deadlocks=ON + 错误日志里数 "LATEST DETECTED DEADLOCK" 的行数；
 *   (b) 用 Innodb_row_lock_waits 看锁等待**趋势**（注意它把死锁和普通超时算在一起，不区分）。
 */
$rows = $setup->query("SHOW GLOBAL STATUS LIKE '%deadlock%'")->fetchAll(PDO::FETCH_ASSOC);
printf("  SHOW GLOBAL STATUS LIKE '%%deadlock%%' 返回 %d 行 → 8.4 没有全局死锁计数器\n", count($rows));
$w = $setup->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->fetch(PDO::FETCH_ASSOC);
printf("  Innodb_row_lock_waits 累计 = %s（含死锁和普通锁等待超时，不区分）\n", $w['Value'] ?? '?');

// ---------- 3. 死锁现场 ----------
echo "\n========== 3) SHOW ENGINE INNODB STATUS 里的死锁现场 ==========\n";
$st = $setup->query("SHOW ENGINE INNODB STATUS")->fetch(PDO::FETCH_ASSOC);
$status = $st['Status'] ?? '';
// 【踩过的坑】别用 (?=\n---|\z) 当结束锚点：LATEST DETECTED DEADLOCK 紧后面就是一行 '-----'，
// 惰性匹配会在这里立刻收尾，只截到 2 行标题。改成截到下一个段头 TRANSACTIONS 为止。
if (preg_match('/LATEST DETECTED DEADLOCK.*?(?=\nTRANSACTIONS|\z)/s', $status, $m)) {
    $block = $m[0];
    // 只打印关键几段，避免刷屏
    foreach (explode("\n", $block) as $line) {
        if (preg_match('/^(LATEST DETECTED DEADLOCK|\*\*\* \(|TRANSACTION|WAITING FOR|HOLDS THE LOCK|RECORD LOCKS|TABLE LOCK|WE ROLL BACK|MySQL thread id)/', trim($line))) {
            echo "  ", rtrim($line), "\n";
        }
    }
} else {
    echo "  （未找到死锁记录，可调大 innodb_print_all_deadlocks 后重试）\n";
}

// ---------- 4. 锁相关计数 ----------
echo "\n========== 4) 锁等待计数 ==========\n";
foreach ($setup->query("SHOW GLOBAL STATUS WHERE Variable_name IN
    ('Innodb_row_lock_waits','Innodb_row_lock_time_avg','Innodb_row_lock_current_waits','Innodb_deadlocks')") as $r) {
    printf("  %-32s %s\n", $r['Variable_name'], $r['Value']);
}
