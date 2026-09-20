<?php
/**
 * Q19 追问：SHOW ENGINE INNODB STATUS 里的 "History list length" 到底在数什么？
 *
 * 背景：14-mvcc.php 里 3 个事务各 UPDATE 20 万行，History list length 只涨了 12，
 * 如果它是「未 purge 的 undo 记录数」，应该涨 60 万才对。所以必须实测它的计量单位，
 * 否则答案里写「长事务积压 60 万个旧版本」就是编数字。
 *
 * 做法：全程挂一个 RR 事务并先读一次（读视图固定 → purge 被挡住 → 只增不减），
 * 然后分别用不同形态的写去冲，看这个数怎么动。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/14b-history-unit.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
$conn = fn() => new PDO($dsn, 'root', 'root', $opt);
$q = fn(PDO $p, string $sql) => $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$setup = $conn();
echo "MySQL ", $setup->query('SELECT VERSION()')->fetchColumn(), "\n\n";

function hlen(PDO $p): int {
    preg_match('/History list length (\d+)/', $p->query("SHOW ENGINE INNODB STATUS")->fetch(PDO::FETCH_ASSOC)['Status'], $m);
    return (int)($m[1] ?? -1);
}

$setup->exec("DROP TABLE IF EXISTS hu");
$setup->exec("CREATE TABLE hu (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB");
$setup->exec("SET SESSION cte_max_recursion_depth = 300000");
$setup->exec("INSERT INTO hu WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < 200000)
              SELECT n, 0 FROM seq");
echo "hu 表行数 = ", $setup->query("SELECT COUNT(*) FROM hu")->fetchColumn(), "\n\n";

// 挂一个 RR 事务并读一次，把读视图钉住，purge 从此不能回收任何新版本
$hold = $conn();
$hold->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$hold->beginTransaction();
$q($hold, "SELECT v FROM hu WHERE id = 1");
echo "已挂起一个 RR 事务并读过一次（读视图固定，purge 被挡住）\n";
$base = hlen($setup);
printf("基线 History list length = %d\n\n", $base);

$b = $conn();

function step(PDO $setup, PDO $b, string $label, float $base, callable $fn): float {
    $t0 = microtime(true);
    $fn($b);
    $now = hlen($setup);
    printf("  %-46s History list %6.0f → %-6d (+%.0f)  %.1fs\n",
        $label, $base, $now, $now - $base, microtime(true) - $t0);
    return (float)$now;
}

echo "========== 同一个读视图下，不同形态的写各让这个数涨多少 ==========\n";

// A. 1 个事务改 2000 行
$cur = step($setup, $b, "1 个事务 UPDATE 2000 行", (float)$base, function (PDO $b) {
    $b->beginTransaction(); $b->exec("UPDATE hu SET v = v + 1 WHERE id <= 2000"); $b->commit();
});

// B. 2000 个事务各改 1 行
$cur = step($setup, $b, "2000 个事务各 UPDATE 1 行", $cur, function (PDO $b) {
    for ($i = 1; $i <= 2000; $i++) { $b->exec("UPDATE hu SET v = v + 1 WHERE id = $i"); }
});

// C. 1 个事务改 200000 行
$cur = step($setup, $b, "1 个事务 UPDATE 200000 行", $cur, function (PDO $b) {
    $b->beginTransaction(); $b->exec("UPDATE hu SET v = v + 1"); $b->commit();
});

// D. 同一个事务里把同一行改 2000 次
$cur = step($setup, $b, "1 个事务把同一行 UPDATE 2000 次", $cur, function (PDO $b) {
    $b->beginTransaction();
    for ($i = 0; $i < 2000; $i++) { $b->exec("UPDATE hu SET v = v + 1 WHERE id = 1"); }
    $b->commit();
});

// E. 再改 200000 行一次，验证是不是线性
$cur = step($setup, $b, "再 1 个事务 UPDATE 200000 行", $cur, function (PDO $b) {
    $b->beginTransaction(); $b->exec("UPDATE hu SET v = v + 1"); $b->commit();
});

echo "\n  → 结论：这个数计的是「undo 记录/事务」还是「undo 页」，见上面的增量对比\n";
printf("  当前 History list length = %d（%s）\n", hlen($setup),
    hlen($setup) > 0 ? "有挂起读视图，purge 不动" : "已被 purge");

// 视角二：undo 表空间文件大小（这个才是「磁盘上真的胀了」）
echo "\n========== undo 表空间实际占用 ==========\n";
foreach ($q($setup, "SELECT NAME, FILE_SIZE, SPACE_TYPE FROM information_schema.INNODB_TABLESPACES
                     WHERE SPACE_TYPE='Undo'") as $r) {
    printf("  %-30s %.0f MB\n", $r['NAME'], $r['FILE_SIZE'] / 1048576);
}

echo "\n========== 释放读视图，看 purge 追不追得上（每 0.5s 采一次，最多 20s）==========\n";
$hold->rollBack();
$t0 = microtime(true);
for ($i = 0; $i < 40; $i++) {
    printf("  +%.1fs  History list length = %d\n", microtime(true) - $t0, hlen($setup));
    usleep(500000);
}
