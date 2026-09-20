<?php
/**
 * Q18 四种隔离级别 × 三种异常的完整矩阵
 *
 * 每种异常单独造：脏读（读到未提交）、不可重复读（同行值变了）、幻读（范围行数变了）。
 * 两个真实连接：A 只读、B 只写，全用快照读（普通 SELECT）。
 *
 * 有个坑必须先处理：SERIALIZABLE 下普通 SELECT 会隐式加共享锁，
 * B 的写会直接被挡到超时，于是「读不到新值」并不是隔离级别的功劳，而是写根本没进去。
 * 所以每个子测试都要单独记录「B 的写有没有被挡」。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/13-isolation.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [
    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];
$conn = fn() => new PDO($dsn, 'root', 'root', $opt);
$q = fn(PDO $p, string $sql) => $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/** 执行一条写语句，返回 [是否成功, 耗时]。被锁挡住会等到 innodb_lock_wait_timeout 后抛 1205。 */
function tryWrite(PDO $b, string $sql): array {
    $t0 = microtime(true);
    try { $b->exec($sql); return [true, microtime(true) - $t0]; }
    catch (PDOException $e) { return [false, microtime(true) - $t0]; }
}

$setup = $conn();
echo "MySQL ", $setup->query('SELECT VERSION()')->fetchColumn(), "\n";
echo "默认隔离级别: ", $setup->query('SELECT @@transaction_isolation')->fetchColumn(), "\n\n";

$setup->exec("DROP TABLE IF EXISTS iso");
$setup->exec("CREATE TABLE iso (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB");

$levels = ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];
$rows = [];

foreach ($levels as $lvl) {
    $a = $conn(); $b = $conn();
    $a->exec("SET SESSION TRANSACTION ISOLATION LEVEL $lvl");
    $b->exec("SET SESSION TRANSACTION ISOLATION LEVEL $lvl");
    $b->exec("SET SESSION innodb_lock_wait_timeout = 2");   // 别真等 50 秒

    // ---------- 1. 脏读：B 改了但不提交 ----------
    $setup->exec("DELETE FROM iso"); $setup->exec("INSERT INTO iso VALUES (1, 100)");
    $a->beginTransaction();
    $v0 = (int)$q($a, "SELECT v FROM iso WHERE id = 1")[0]['v'];
    $b->beginTransaction();
    [$okD, $secD] = tryWrite($b, "UPDATE iso SET v = 200 WHERE id = 1");
    $v1 = (int)$q($a, "SELECT v FROM iso WHERE id = 1")[0]['v'];
    $dirty = $okD && $v1 !== $v0;
    try { $b->rollBack(); } catch (Throwable $e) {}
    try { $a->commit(); } catch (Throwable $e) {}

    // ---------- 2. 不可重复读：B 改了并提交 ----------
    $setup->exec("UPDATE iso SET v = 100 WHERE id = 1");
    $a->beginTransaction();
    $v2 = (int)$q($a, "SELECT v FROM iso WHERE id = 1")[0]['v'];
    $b->beginTransaction();
    [$okN, $secN] = tryWrite($b, "UPDATE iso SET v = 300 WHERE id = 1");
    if ($okN) { $b->commit(); } else { try { $b->rollBack(); } catch (Throwable $e) {} }
    $v3 = (int)$q($a, "SELECT v FROM iso WHERE id = 1")[0]['v'];
    $nonrepeat = $okN && $v3 !== $v2;
    try { $a->commit(); } catch (Throwable $e) {}

    // ---------- 3. 幻读：B 插入新行并提交 ----------
    $setup->exec("DELETE FROM iso"); $setup->exec("INSERT INTO iso VALUES (1, 1), (2, 2)");
    $a->beginTransaction();
    $c1 = (int)$q($a, "SELECT COUNT(*) c FROM iso WHERE id < 10")[0]['c'];
    $b->beginTransaction();
    [$okP, $secP] = tryWrite($b, "INSERT INTO iso VALUES (3, 3)");
    if ($okP) { $b->commit(); } else { try { $b->rollBack(); } catch (Throwable $e) {} }
    $c2 = (int)$q($a, "SELECT COUNT(*) c FROM iso WHERE id < 10")[0]['c'];
    $phantom = $okP && $c1 !== $c2;
    try { $a->commit(); } catch (Throwable $e) {}

    $blocked = !$okD || !$okN || !$okP;
    $rows[] = [$lvl, $dirty, $nonrepeat, $phantom, $blocked,
               sprintf('%.2fs/%.2fs/%.2fs', $secD, $secN, $secP),
               $okN ? "$v2 → $v3" : "写被挡，$v2 → $v3",
               $okP ? "$c1 → $c2" : "写被挡，$c1 → $c2"];
}

printf("%-18s %-6s %-12s %-6s %-10s %-20s %-16s %s\n",
    '隔离级别', '脏读', '不可重复读', '幻读', '写被挡', '三次写的耗时', '同行两次读', '范围两次 count');
foreach ($rows as $r) {
    printf("%-18s %-6s %-12s %-6s %-10s %-20s %-16s %s\n",
        $r[0], $r[1] ? '有' : '无', $r[2] ? '有' : '无', $r[3] ? '有' : '无',
        $r[4] ? '是' : '否', $r[5], $r[6], $r[7]);
}

// ---------- 补充：SERIALIZABLE 下普通 SELECT 到底加了什么锁 ----------
echo "\n========== SERIALIZABLE 的普通 SELECT 加了什么锁（performance_schema.data_locks）==========\n";
$setup->exec("DELETE FROM iso"); $setup->exec("INSERT INTO iso VALUES (1, 1), (2, 2)");
$a = $conn();
$a->exec("SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE");
$a->beginTransaction();
$q($a, "SELECT * FROM iso WHERE id = 1");
foreach ($q($setup, "SELECT OBJECT_NAME, INDEX_NAME, LOCK_TYPE, LOCK_MODE, LOCK_STATUS, LOCK_DATA
                    FROM performance_schema.data_locks WHERE OBJECT_NAME = 'iso'") as $r) {
    printf("  %-12s %-8s %-10s %-24s %-8s %s\n",
        $r['OBJECT_NAME'], $r['INDEX_NAME'], $r['LOCK_TYPE'], $r['LOCK_MODE'], $r['LOCK_STATUS'], $r['LOCK_DATA']);
}
$a->rollBack();

// ---------- 补充：RR 下同一个 SELECT 会不会加锁（对照）----------
echo "\n对照：REPEATABLE READ 下同样的普通 SELECT\n";
$a = $conn();
$a->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$a->beginTransaction();
$q($a, "SELECT * FROM iso WHERE id = 1");
$found = $q($setup, "SELECT COUNT(*) c FROM performance_schema.data_locks WHERE OBJECT_NAME = 'iso'")[0]['c'];
echo "  data_locks 里 iso 的锁数量: $found\n";
$a->rollBack();

// ---------- 补充：显式加锁读才会加锁 ----------
echo "\n对照：RR 下 SELECT ... FOR UPDATE\n";
$a->beginTransaction();
$q($a, "SELECT * FROM iso WHERE id = 1 FOR UPDATE");
foreach ($q($setup, "SELECT INDEX_NAME, LOCK_TYPE, LOCK_MODE, LOCK_DATA
                    FROM performance_schema.data_locks WHERE OBJECT_NAME = 'iso'") as $r) {
    printf("  %-8s %-10s %-24s %s\n", $r['INDEX_NAME'], $r['LOCK_TYPE'], $r['LOCK_MODE'], $r['LOCK_DATA']);
}
$a->rollBack();
