<?php
/**
 * Q19 MVCC：读视图怎么建、为什么长事务会把 undo 撑爆
 *
 * 直接可观测的两个量：
 *   1) SHOW ENGINE INNODB STATUS 的 TRANSACTIONS 段里有 InnoDB 自己打印的 read view
 *      （"Trx read view will not see trx with id >= N, sees < M"）——这就是读视图本体
 *   2) History list length：还没被 purge 掉的旧版本数量。RR 下事务一开就读一次，
 *      读视图就固定了，之后所有更新产生的旧版本都不能回收 → 这个数会一直涨
 *
 * 用法: docker exec learn-php php /app/bench/mysql/14-mvcc.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
$conn = fn() => new PDO($dsn, 'root', 'root', $opt);
$q = fn(PDO $p, string $sql) => $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$setup = $conn();
echo "MySQL ", $setup->query('SELECT VERSION()')->fetchColumn(), "\n\n";

function status(): string {
    global $setup;
    return $setup->query("SHOW ENGINE INNODB STATUS")->fetch(PDO::FETCH_ASSOC)['Status'];
}
function historyLen(): int {
    preg_match('/History list length (\d+)/', status(), $m);
    return (int)($m[1] ?? -1);
}
function undoSize(): string {
    global $q, $setup;
    $out = [];
    foreach ($q($setup, "SELECT NAME, FILE_SIZE FROM information_schema.INNODB_TABLESPACES WHERE SPACE_TYPE='Undo'") as $r) {
        $out[] = sprintf('%s=%.1fMB', basename($r['NAME']), $r['FILE_SIZE'] / 1048576);
    }
    return implode(' ', $out);
}
// MySQL 8.4 的 INNODB STATUS 不再逐事务打印 "Trx read view will not see trx with id >= N"，
// 只打印一行汇总 "N read views open inside InnoDB"。所以这里读汇总值。
// 踩过的坑：网上 5.7/8.0 早期的博客里那段 Trx read view 文本在 8.4 已不存在，别再照着 grep。
function readViews(): array {
    preg_match('/(\d+) read views open inside InnoDB/', status(), $m);
    return isset($m[1]) ? ["open inside InnoDB = {$m[1]}"] : [];
}

$setup->exec("DROP TABLE IF EXISTS mv");
$setup->exec("CREATE TABLE mv (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB");
$setup->exec("INSERT INTO mv SELECT n, 0 FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3) t");
$setup->exec("DELETE FROM mv");
$setup->exec("SET SESSION cte_max_recursion_depth = 300000");
$setup->exec("INSERT INTO mv WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < 200000)
              SELECT n, 0 FROM seq");
echo "mv 表行数 = ", $setup->query("SELECT COUNT(*) FROM mv")->fetchColumn(), "\n";
printf("初始状态: History list length = %d, undo: %s\n\n", historyLen(), undoSize());

/**
 * 一轮实验：开着事务 A 的读视图，另一边反复 UPDATE 同一批行。
 * $holdView = true  → 事务 A 先读一次（RR：读视图从此固定）
 * $holdView = false → 事务 A 只 BEGIN 不读（RC：语句结束就没有读视图了）
 */
function mvccRound(string $label, bool $holdView): void {
    global $conn, $q, $setup;
    $a = $conn();
    $b = $conn();
    $a->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    $a->beginTransaction();
    if ($holdView) { $q($a, "SELECT v FROM mv WHERE id = 1"); }

    $base = historyLen();
    $t0 = microtime(true);
    $rounds = 3;
    for ($r = 0; $r < $rounds; $r++) {
        $b->beginTransaction();
        $b->exec("UPDATE mv SET v = v + 1");           // 20 万行，每轮产生 20 万个旧版本
        $b->commit();
        printf("  第 %d 轮 UPDATE 20 万行后: History list length = %-9d undo: %s\n",
            $r + 1, historyLen(), undoSize());
    }
    $after = historyLen();
    printf("  %s：", $label);
    printf("History list %d → %d（+%d），耗时 %.1fs，undo: %s\n", $base, $after, $after - $base,
        microtime(true) - $t0, undoSize());
    foreach (readViews() as $rv) { echo "  读视图: $rv\n"; }

    $a->commit();
    echo "  事务 A 提交后: History list length = ", historyLen(), "\n";
    $b->exec("UPDATE mv SET v = v + 1 WHERE id <= 1000");   // 触发 purge 线程干活
    usleep(800000);
    echo "  等 0.8s 并触发 purge 后: History list length = ", historyLen(), ", undo: ", undoSize(), "\n\n";
}

echo "========== 1) RR 事务读了 1 行就一直挂着 -> 读视图固定 -> 旧版本全部积压 ==========\n";
mvccRound("RR 读了 1 行", true);

echo "========== 2) 对照：同一个 RR 事务，但根本不读（没有读视图）==========\n";
mvccRound("RR 没读", false);

// 收尾：把 v 归零，别把 undo 一直留着
$setup->exec("UPDATE mv SET v = 0");
usleep(500000);
echo "收尾后: History list length = ", historyLen(), ", undo: ", undoSize(), "\n";

echo "\n========== 3) 当前所有活跃事务（trx_id 就是行的隐藏列 DB_TRX_ID）==========\n";
$b = $conn();
$b->beginTransaction();
$b->exec("UPDATE mv SET v = v + 1 WHERE id = 1");
foreach ($q($setup, "SELECT trx_id, trx_state, trx_isolation_level, trx_rows_modified, trx_started
                    FROM information_schema.INNODB_TRX") as $r) {
    printf("  trx_id=%s state=%s isolation=%s rows_modified=%s started=%s\n",
        $r['trx_id'], $r['trx_state'], $r['trx_isolation_level'], $r['trx_rows_modified'], $r['trx_started']);
}
$b->rollBack();

echo "\n========== 4) 长事务对 undo 的影响：截一段真实 undo 增长（%s）==========\n";
$a = $conn();
$a->beginTransaction();
$q($a, "SELECT v FROM mv WHERE id = 1");
$s0 = undoSize();
$b->beginTransaction();
for ($i = 0; $i < 5; $i++) { $b->exec("UPDATE mv SET v = v + 1"); }
$b->commit();
printf("  5 轮全表 UPDATE + 一个挂起的 RR 事务: undo %s → %s\n", $s0, undoSize());
$a->rollBack();
