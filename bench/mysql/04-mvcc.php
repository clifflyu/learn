<?php
/**
 * Q18 隔离级别  Q19 MVCC（RC vs RR 的快照行为）
 * 用两个真实连接模拟两个并发事务。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/04-mvcc.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];

function conn(string $dsn, array $opt): PDO
{
    return new PDO($dsn, 'root', 'root', $opt);
}

function q(PDO $p, string $sql)
{
    return $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

echo "MySQL ", conn($dsn, $opt)->query('SELECT VERSION()')->fetchColumn(), "\n\n";

$setup = conn($dsn, $opt);
$setup->exec("DROP TABLE IF EXISTS acct");
$setup->exec("CREATE TABLE acct (id INT PRIMARY KEY, balance INT NOT NULL) ENGINE=InnoDB");
$setup->exec("INSERT INTO acct VALUES (1, 1000)");

foreach (['READ COMMITTED', 'REPEATABLE READ'] as $level) {
    echo "========== 隔离级别: $level ==========\n";

    $setup->exec("UPDATE acct SET balance = 1000 WHERE id = 1");

    $a = conn($dsn, $opt);      // 事务 A
    $b = conn($dsn, $opt);      // 事务 B

    $a->exec("SET SESSION TRANSACTION ISOLATION LEVEL $level");
    $b->exec("SET SESSION TRANSACTION ISOLATION LEVEL $level");

    $a->beginTransaction();
    $first = q($a, "SELECT balance FROM acct WHERE id = 1")[0]['balance'];
    printf("A 事务内第 1 次读: balance = %d\n", $first);

    // B 在 A 的事务期间修改并提交
    $b->beginTransaction();
    $b->exec("UPDATE acct SET balance = 2000 WHERE id = 1");
    $b->commit();
    echo "B 已把 balance 改成 2000 并提交\n";

    $second = q($a, "SELECT balance FROM acct WHERE id = 1")[0]['balance'];
    printf("A 事务内第 2 次读: balance = %d   → %s\n",
        $second,
        $second === $first ? '不可重复读：没发生（快照读）' : '发生了不可重复读');

    $a->commit();

    $after = q($a, "SELECT balance FROM acct WHERE id = 1")[0]['balance'];
    printf("A 提交后再读:     balance = %d\n\n", $after);
}

// 幻读：范围查询在 RR 下是否会出现新行
echo "========== 幻读测试（REPEATABLE-READ）==========\n";
$setup->exec("DELETE FROM acct");
$setup->exec("INSERT INTO acct VALUES (1, 100), (2, 200)");

$a = conn($dsn, $opt);
$b = conn($dsn, $opt);
$a->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$b->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");

$a->beginTransaction();
$n1 = q($a, "SELECT COUNT(*) c FROM acct WHERE id < 10")[0]['c'];

$b->beginTransaction();
$b->exec("INSERT INTO acct VALUES (3, 300)");
$b->commit();

$n2 = q($a, "SELECT COUNT(*) c FROM acct WHERE id < 10")[0]['c'];
printf("A 快照读第 1 次 count = %d，B 插入 id=3 后第 2 次 count = %d → %s\n",
    $n1, $n2, $n1 === $n2 ? '快照读看不到新行' : '出现幻读');

$cur = q($a, "SELECT COUNT(*) c FROM acct WHERE id < 10 FOR UPDATE")[0]['c'];
printf("A 用当前读 SELECT ... FOR UPDATE，count = %d → %s\n",
    $cur, $cur !== $n2 ? '当前读能看到新行（且被间隙锁挡住后续插入）' : '未看到');
$a->commit();

// 间隙锁：RR 下 B 想插入 id=5 会被挡
echo "\n========== 间隙锁（RR 下 FOR UPDATE 的范围锁）==========\n";
$a->beginTransaction();
$a->exec("SELECT * FROM acct WHERE id < 10 FOR UPDATE");
echo "A 持有 id<10 的间隙锁\n";
$b->exec("SET SESSION innodb_lock_wait_timeout = 2");
try {
    $b->exec("INSERT INTO acct VALUES (5, 500)");
    echo "B 插入 id=5 成功（未被间隙锁挡住）\n";
} catch (PDOException $e) {
    echo "B 插入 id=5 被挡: ", substr($e->getMessage(), 0, 80), "\n";
}
$a->commit();
