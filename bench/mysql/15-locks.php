<?php
/**
 * Q20 InnoDB 锁：S/X 兼容矩阵、间隙锁、锁等待现场
 *
 * performance_schema.data_locks / data_lock_waits 是 8.0 起真正能看到锁表的地方
 * （5.7 的 innodb_locks 只是「正在等待的锁」的一个不完整视图，8.0 已经删掉）。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/15-locks.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
$conn = fn() => new PDO($dsn, 'root', 'root', $opt);
$q = fn(PDO $p, string $sql) => $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$setup = $conn();
echo "MySQL ", $setup->query('SELECT VERSION()')->fetchColumn(), "\n";
echo "默认隔离级别: ", $setup->query('SELECT @@transaction_isolation')->fetchColumn(), "\n\n";

$setup->exec("DROP TABLE IF EXISTS lk");
$setup->exec("CREATE TABLE lk (id INT PRIMARY KEY, v INT NOT NULL, KEY idx_v (v)) ENGINE=InnoDB");
$setup->exec("INSERT INTO lk VALUES (1,10),(5,50),(10,100)");

function dumpLocks(PDO $setup, string $label): void {
    global $q;
    echo "  [$label] data_locks:\n";
    $rows = $q($setup, "SELECT ENGINE_TRANSACTION_ID trx, INDEX_NAME idx, LOCK_TYPE type, LOCK_MODE mode,
                               LOCK_STATUS st, LOCK_DATA data
                        FROM performance_schema.data_locks WHERE OBJECT_NAME='lk' ORDER BY trx, LOCK_TYPE");
    foreach ($rows as $r) {
        printf("    trx=%-6s %-8s %-8s %-22s %-8s %s\n",
            $r['trx'], $r['idx'] ?? '-', $r['type'], $r['mode'] ?? '-', $r['st'], $r['data'] ?? '-');
    }
    if (!$rows) { echo "    （无锁）\n"; }
}

/** 在 B 上跑一条语句，返回 [是否被挡住, 耗时] */
function blockedBy(PDO $b, string $sql, float $timeout): array {
    $b->exec("SET SESSION innodb_lock_wait_timeout = $timeout");
    $t0 = microtime(true);
    try { $b->query($sql)->fetchAll(); return [false, microtime(true) - $t0]; }
    catch (PDOException $e) { return [true, microtime(true) - $t0]; }
}

// ================= 1. S/X 兼容矩阵 =================
echo "========== 1) 共享锁(S) / 排他锁(X) 兼容矩阵（实测）==========\n";
echo "  约定：S=SELECT ... FOR SHARE，X=SELECT ... FOR UPDATE / UPDATE\n\n";

/** 等表上的锁全部释放（锁是异步释放的，直接跑下一条会被上一个 cell 的残留挡住） */
function waitUnlocked(PDO $setup, float $maxSec = 5.0): void {
    global $q;
    $t0 = microtime(true);
    while (microtime(true) - $t0 < $maxSec) {
        $n = (int)$q($setup, "SELECT COUNT(*) c FROM performance_schema.data_locks WHERE OBJECT_NAME='lk'")[0]['c'];
        if ($n === 0) { return; }
        usleep(50000);
    }
}

$a = $conn(); $b = $conn();
$matrix = [];
foreach (['S' => 'SELECT * FROM lk WHERE id = 1 FOR SHARE',
          'X' => 'SELECT * FROM lk WHERE id = 1 FOR UPDATE'] as $held => $holdSql) {
    foreach (['S' => 'SELECT * FROM lk WHERE id = 1 FOR SHARE',
              'X' => 'SELECT * FROM lk WHERE id = 1 FOR UPDATE'] as $want => $wantSql) {
        waitUnlocked($setup);
        $a->exec("SET SESSION innodb_lock_wait_timeout = 1");
        $a->beginTransaction();
        $a->query($holdSql)->fetchAll();   // SELECT 必须用 query()->fetchAll()，exec() 会留下未消费的结果集
        [$blocked, $sec] = blockedBy($b, $wantSql, 1);
        $matrix["$held/$want"] = $blocked ? '阻塞' : '兼容';
        try { $b->rollBack(); } catch (Throwable $e) {}
        try { $a->rollBack(); } catch (Throwable $e) {}
        printf("    A 持 %s，B 申请 %s → 耗时 %.2fs\n", $held, $want, $sec);
    }
}
waitUnlocked($setup);
printf("  已持有 \\ 想申请   %-10s %-10s\n", 'S', 'X');
foreach (['S', 'X'] as $held) {
    printf("  %-16s %-10s %-10s\n", $held, $matrix["$held/S"], $matrix["$held/X"]);
}

// ================= 2. 行锁等待的现场 =================
echo "\n========== 2) 行锁等待的真实现场 ==========\n";
$a = $conn(); $b = $conn();
$a->beginTransaction();
$a->query("UPDATE lk SET v = v + 1 WHERE id = 5")->fetchAll();
$a->query("SELECT 1")->fetchAll();
echo "  A 持有 id=5 的排他锁（尚未提交）\n";
dumpLocks($setup, 'A 持锁后');

// B 在另一个连接里发起等待，用后台进程跑，主线程去看锁等待表
$pid = null;
$b->exec("SET SESSION innodb_lock_wait_timeout = 8");
$sql = "UPDATE lk SET v = v + 1 WHERE id = 5";
$proc = proc_open("php -r '" . str_replace("'", "'\\''",
    '$p=new PDO("mysql:host=learn-mysql;dbname=learn;charset=utf8mb4","root","root");
     $p->exec("SET SESSION innodb_lock_wait_timeout=8");
     try{$p->exec("UPDATE lk SET v=v+1 WHERE id=5");}catch(Throwable $e){}') . "'",
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(1200000);
echo "  B 正在等 id=5 的锁，此刻 data_lock_waits：\n";
foreach ($q($setup, "SELECT w.REQUESTING_ENGINE_TRANSACTION_ID req, w.BLOCKING_ENGINE_TRANSACTION_ID blk,
                            r.LOCK_MODE req_mode, r.LOCK_DATA req_data, r.LOCK_STATUS st
                     FROM performance_schema.data_lock_waits w
                     JOIN performance_schema.data_locks r
                       ON r.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID") as $r) {
    printf("    等待者 trx=%s 被阻塞者 trx=%s  mode=%s data=%s status=%s\n",
        $r['req'], $r['blk'], $r['req_mode'], $r['req_data'], $r['st']);
}
foreach ($q($setup, "SELECT trx_id, trx_state, trx_rows_locked, trx_rows_modified, trx_started
                     FROM information_schema.INNODB_TRX") as $r) {
    printf("    INNODB_TRX: trx_id=%s state=%s rows_locked=%s rows_modified=%s\n",
        $r['trx_id'], $r['trx_state'], $r['trx_rows_locked'], $r['trx_rows_modified']);
}
$a->commit();
proc_close($proc);

// ================= 3. 间隙锁（RR）=================
echo "\n========== 3) RR 下的间隙锁：锁的是「区间」，不是「行」==========\n";
foreach (['REPEATABLE READ', 'READ COMMITTED'] as $lvl) {
    $setup->exec("DELETE FROM lk"); $setup->exec("INSERT INTO lk VALUES (1,10),(5,50),(10,100)");
    $a = $conn(); $b = $conn();
    $a->exec("SET SESSION TRANSACTION ISOLATION LEVEL $lvl");
    $b->exec("SET SESSION TRANSACTION ISOLATION LEVEL $lvl");
    echo "  --- 隔离级别 $lvl ---\n";
    $a->beginTransaction();
    $q($a, "SELECT * FROM lk WHERE id < 5 FOR UPDATE");   // 表里 id<5 只有 1 行
    dumpLocks($setup, "SELECT * FROM lk WHERE id < 5 FOR UPDATE（只命中 1 行）");
    [$blk, $sec] = blockedBy($b, "INSERT INTO lk VALUES (3, 30)", 1);   // 插入到「空隙」里
    printf("    B 插入 id=3（不存在的行，落在空隙中）: %s，耗时 %.2fs\n", $blk ? '被挡' : '成功', $sec);
    try { $b->rollBack(); } catch (Throwable $e) {}
    $a->rollBack();
}

// ================= 4. 不加锁的读 vs 加锁的读 =================
echo "\n========== 4) 快照读 vs 当前读：同样一句 SELECT 的锁行为完全不同 ==========\n";
$setup->exec("DELETE FROM lk"); $setup->exec("INSERT INTO lk VALUES (1,10),(5,50),(10,100)");
$a = $conn();
$a->beginTransaction();
$q($a, "SELECT * FROM lk WHERE id = 5");
dumpLocks($setup, "普通 SELECT（快照读，MVCC）");
$a->rollBack();
$a->beginTransaction();
$q($a, "SELECT * FROM lk WHERE id = 5 FOR UPDATE");
dumpLocks($setup, "SELECT ... FOR UPDATE（当前读）");
$a->rollBack();

// ================= 5. 锁相关计数 =================
echo "\n========== 5) 全局锁指标 ==========\n";
foreach ($q($setup, "SHOW GLOBAL STATUS WHERE Variable_name IN
    ('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_time_avg',
     'Innodb_row_lock_time_max','Innodb_row_lock_current_waits','Innodb_deadlocks')") as $r) {
    printf("  %-34s %s\n", $r['Variable_name'], $r['Value']);
}
