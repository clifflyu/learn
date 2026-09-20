<?php
/**
 * Q22 深分页：LIMIT 400000, 20 到底慢在哪一步，三种改写各能救回多少
 *
 * 关键：慢的不是「返回 20 行」，是「先按顺序取出 400020 行、丢掉前 400000 行」。
 * 所以用会话级 Handler 计数把这一步直接量出来（Handler 计数是会话内的，不受同实例其他库干扰）：
 *   Handler_read_next    = 顺着索引读下一行的次数
 *   Handler_read_rnd_next= 顺着聚簇索引全表扫的下一行次数
 *   Handler_read_key     = 用索引定位的次数
 *
 * 用法: docker exec learn-php php /app/bench/mysql/18-pagination.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
$pdo = new PDO($dsn, 'root', 'root', $opt);
echo "MySQL ", $pdo->query('SELECT VERSION()')->fetchColumn(), "\n\n";

$pdo->exec("DROP TABLE IF EXISTS pg");
// pad VARCHAR(255)：让「每行」和「索引项」差得足够大，延迟关联的收益才看得见
$pdo->exec("CREATE TABLE pg (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  pad VARCHAR(255) NOT NULL,
  KEY idx_created (created_at)
) ENGINE=InnoDB");
$pdo->exec("SET SESSION cte_max_recursion_depth = 600000");
$N = 500000;
$pdo->exec("INSERT INTO pg (user_id, created_at, pad)
  WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < $N)
  SELECT n, NOW() - INTERVAL (n % 365) DAY, REPEAT('x', 200) FROM seq");
$pdo->query("ANALYZE TABLE pg")->fetchAll();
$rows = $pdo->query("SELECT COUNT(*) FROM pg")->fetchColumn();
$sz = $pdo->query("SELECT DATA_LENGTH d, INDEX_LENGTH i FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA='learn' AND TABLE_NAME='pg'")->fetch(PDO::FETCH_ASSOC);
printf("pg 表 %s 行：聚簇索引 %.0f MB，二级索引 idx_created %.0f MB\n\n",
    $rows, $sz['d'] / 1048576, $sz['i'] / 1048576);

/** 跑一条 SQL，返回 [耗时ms, 返回行数, Handler 增量] */
function run(PDO $pdo, string $sql): array {
    $pdo->query("FLUSH STATUS")->fetchAll();
    $t0 = microtime(true);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $ms = (microtime(true) - $t0) * 1000;
    $h = [];
    foreach ($pdo->query("SHOW SESSION STATUS")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (str_starts_with($r['Variable_name'], 'Handler_read_')) { $h[$r['Variable_name']] = (int)$r['Value']; }
    }
    return [$ms, count($rows), $h];
}

function line(string $label, array $r): void {
    $h = $r[2];
    printf("  %-52s %8.2f ms  返回%3d 行  read_key=%-7d read_next=%-8d read_rnd_next=%d\n",
        $label, $r[0], $r[1], $h['Handler_read_key'] ?? 0, $h['Handler_read_next'] ?? 0, $h['Handler_read_rnd_next'] ?? 0);
}

echo "========== 1) 深分页的代价随 offset 线性增长 ==========\n";
foreach ([0, 1000, 100000, 400000, 499000] as $off) {
    line("SELECT * FROM pg ORDER BY id LIMIT $off, 20",
        run($pdo, "SELECT * FROM pg ORDER BY id LIMIT $off, 20"));
}

echo "\n========== 2) 改写一：延迟关联（先只取主键，再回表取 20 行）==========\n";
foreach ([100000, 400000] as $off) {
    line("原版  LIMIT $off, 20",
        run($pdo, "SELECT * FROM pg ORDER BY id LIMIT $off, 20"));
    line("延迟关联 SELECT p.* FROM pg p JOIN (SELECT id FROM pg ORDER BY id LIMIT $off,20) t ON p.id=t.id",
        run($pdo, "SELECT p.* FROM pg p JOIN (SELECT id FROM pg ORDER BY id LIMIT $off, 20) t ON p.id = t.id"));
}

echo "\n========== 3) 改写二：游标/书签（记住上一页最后一行的 id，不带 OFFSET）==========\n";
$last = 400000;
line("WHERE id > $last ORDER BY id LIMIT 20",
    run($pdo, "SELECT * FROM pg WHERE id > $last ORDER BY id LIMIT 20"));

echo "\n========== 4) 改写三：ORDER BY 一个「有索引但你不用」的列，看它退化得多快 ==========\n";
foreach ([0, 400000] as $off) {
    line("SELECT * FROM pg ORDER BY created_at LIMIT $off, 20",
        run($pdo, "SELECT * FROM pg ORDER BY created_at LIMIT $off, 20"));
}

echo "\n========== 5) 为什么只能线性扫：EXPLAIN 里根本没有「跳过 N 行」这个能力 ==========\n";
$pdo->query("EXPLAIN SELECT * FROM pg ORDER BY id LIMIT 400000, 20")->fetchAll();
$e = $pdo->query("EXPLAIN ANALYZE SELECT * FROM pg ORDER BY id LIMIT 400000, 20")->fetchAll(PDO::FETCH_NUM);
echo "  " . str_replace("\n", "\n  ", $e[0][0]), "\n";
$e = $pdo->query("EXPLAIN ANALYZE SELECT p.* FROM pg p JOIN (SELECT id FROM pg ORDER BY id LIMIT 400000, 20) t ON p.id = t.id")->fetchAll(PDO::FETCH_NUM);
echo "\n  延迟关联版：\n  " . str_replace("\n", "\n  ", $e[0][0]), "\n";
