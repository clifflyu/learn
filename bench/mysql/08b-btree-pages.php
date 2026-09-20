<?php
/**
 * Q13 补测：每个档位「聚簇索引到底有几页」——用实测页数说话，不用估算公式
 *
 * 踩过的坑：information_schema.TABLES 的 DATA_LENGTH / TABLE_ROWS 是采样估算，
 * 同一个 200000 行的表，两次读出来能差 3 倍（一次算出 72 B/行，一次 23.7 B/行），
 * 拿它反推「每叶页多少行」不可靠。INNODB_TABLESTATS 给的是**页数**（CLUST_INDEX_SIZE），
 * 这个量直接和 B+Tree 的层数挂钩，稳定得多。
 *
 * 只做插入 + 读统计，不做页计数测量（那部分在 08-btree-height.php）。
 * 用法: docker exec learn-php php /app/bench/mysql/08b-btree-pages.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$pdo = new PDO($dsn, 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);
$pdo->exec("SET SESSION information_schema_stats_expiry = 0");
echo "MySQL ", $pdo->query('SELECT VERSION()')->fetchColumn(), "\n";
printf("innodb_page_size = %s B\n\n", $pdo->query("SELECT @@innodb_page_size")->fetchColumn());

$pdo->exec("DROP TABLE IF EXISTS bt2");
$pdo->exec("CREATE TABLE bt2 (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(32) NOT NULL, age TINYINT UNSIGNED NOT NULL,
  city VARCHAR(32) NOT NULL, email VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB");
$pdo->exec("SET SESSION cte_max_recursion_depth = 1200000");

printf("%-10s %-10s %-12s %-14s %-14s %s\n",
    'COUNT(*)', '聚簇索引页', '行/页', '二级索引页', '每页 16384B', '推算层数');
$prev = 0;
foreach ([320, 50000, 200000, 500000, 1000000] as $target) {
    if ($target > $prev) {
        $pdo->exec("INSERT INTO bt2 (name, age, city, email, created_at)
            WITH RECURSIVE seq(n) AS (SELECT " . ($prev + 1) . " UNION ALL SELECT n+1 FROM seq WHERE n < $target)
            SELECT CONCAT('user', n), 18 + (n % 50),
                   ELT(1 + (FLOOR(n / 50) % 5), '北京','上海','广州','深圳','杭州'),
                   CONCAT('u', n, '@example.com'), NOW() - INTERVAL (n % 365) DAY
            FROM seq");
        $prev = $target;
    }
    $pdo->query("ANALYZE TABLE bt2")->fetchAll();
    $real = (int)$pdo->query("SELECT COUNT(*) FROM bt2")->fetchColumn();
    $ts = $pdo->query("SELECT NUM_ROWS, CLUST_INDEX_SIZE, OTHER_INDEX_SIZE
                       FROM information_schema.INNODB_TABLESTATS WHERE NAME = 'learn/bt2'")
              ->fetch(PDO::FETCH_ASSOC);
    $clust = (int)$ts['CLUST_INDEX_SIZE'];
    printf("%-10d %-12d %-12.1f %-14d %-14s %s\n",
        $real, $clust, $clust ? $real / $clust : 0, (int)$ts['OTHER_INDEX_SIZE'],
        $clust * 16384 / 1048576 . ' MB',
        $clust <= 1 ? '1（根即叶）' : ($clust <= 1260 ? '2' : '3+'));
}

echo "\n  层数判据：非叶层每条记录 = PK 4 B + 子页号 4 B + 记录头 ≈ 13~18 B\n";
printf("            → 根页扇出 ≈ %d ~ %d 个叶页\n", intval(16384 / 18), intval(16384 / 13));
echo "            → 叶页数 ≤ 扇出 时树高 2；超过就变 3。所以跳变点看「聚簇索引页数」那一列\n";
