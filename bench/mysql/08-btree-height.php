<?php
/**
 * Q13 核心实测：一次主键点查读几页 = B+Tree 的层数
 *
 * Innodb_buffer_pool_read_requests 计的是「逻辑页访问次数」（含命中缓存的页），
 * 所以主键点查的页数就是树高。做法：造一串行数递增、行宽相同的表，
 * 看页数是否随「叶页数超过根页扇出」而 1→2→3 跳变。
 *
 * 该计数器是全局的，同实例上别的库在跑会污染它。两个反污染手段：
 *   1) 污染是「每个时间窗口一个常数」，窗口内查询次数 n 越大，摊到每次就越小 → n 取 2000
 *   2) 污染只增不减 → 跑多个窗口取最小值，并用「SELECT 1」（真值 0 页）做污染基线
 *
 * 用法: docker exec learn-php php /app/bench/mysql/08-btree-height.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$pdo = new PDO($dsn, 'root', 'root', [
    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);
$pdo->exec("SET SESSION information_schema_stats_expiry = 0");  // 否则 TABLE_ROWS/DATA_LENGTH 是 24h 的缓存值，五个档位会读到同一份（踩过）
echo "MySQL ", $pdo->query('SELECT VERSION()')->fetchColumn(), "\n";
printf("innodb_page_size=%s  innodb_adaptive_hash_index=%s（AHI 关闭，排除哈希索引干扰）\n\n",
    $pdo->query("SELECT @@innodb_page_size")->fetchColumn(),
    $pdo->query("SELECT @@innodb_adaptive_hash_index")->fetchColumn());

function pages(): int {
    global $pdo;
    return (int)$pdo->query("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read_requests'")
                    ->fetch(PDO::FETCH_ASSOC)['Value'];
}

/** 跑 windows 个窗口，每窗口 n 次；返回 [最小页/次, 达到最小的窗口数, 窗口数] */
function perQuery(callable $fn, int $n, int $windows = 60): array {
    $fn();                                              // 预热
    $res = [];
    for ($t = 0; $t < $windows; $t++) {
        $a = pages();
        for ($i = 0; $i < $n; $i++) { $fn(); }
        $res[] = (pages() - $a) / $n;
    }
    $min = min($res);
    return [$min, count(array_filter($res, fn($v) => abs($v - $min) < 1e-9)), $windows];
}

$zero = fn() => function () use ($pdo) { $pdo->query("SELECT 1")->fetchAll(PDO::FETCH_ASSOC); };
$q    = fn(string $sql) => function () use ($sql, $pdo) { $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); };

[$bmin, $bcnt, $bw] = perQuery($zero(), 2000, 40);
printf("污染基线（SELECT 1，真值 0 页）: %.4f 页/次（%d/%d 个窗口为 0，其余窗口被同实例其他库污染）\n\n",
    $bmin, $bcnt, $bw);

$pdo->exec("DROP TABLE IF EXISTS bt");
$pdo->exec("CREATE TABLE bt (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(32) NOT NULL, age TINYINT UNSIGNED NOT NULL,
  city VARCHAR(32) NOT NULL, email VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB");
$pdo->exec("SET SESSION cte_max_recursion_depth = 1200000");

printf("%-10s %-10s %-12s %-20s %-14s %s\n",
    '行数', '每叶页行数', '叶页数(算)', '点查页/次 min', '干净窗口', '推算层数');
$prev = 0;
foreach ([320, 50000, 200000, 500000, 1000000] as $target) {
    if ($target > $prev) {
        $pdo->exec("INSERT INTO bt (name, age, city, email, created_at)
            WITH RECURSIVE seq(n) AS (SELECT " . ($prev + 1) . " UNION ALL SELECT n+1 FROM seq WHERE n < $target)
            SELECT CONCAT('user', n), 18 + (n % 50),
                   ELT(1 + (FLOOR(n / 50) % 5), '北京','上海','广州','深圳','杭州'),
                   CONCAT('u', n, '@example.com'), NOW() - INTERVAL (n % 365) DAY
            FROM seq");
        $prev = $target;
    }
    $info = $pdo->query("SELECT TABLE_ROWS r, DATA_LENGTH d FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA='learn' AND TABLE_NAME='bt'")->fetch(PDO::FETCH_ASSOC);
    $rowBytes = $info['r'] ? $info['d'] / $info['r'] : 0;   // 实测平均行宽（AVG_ROW_LENGTH 会滞后，直接用 DATA_LENGTH/TABLE_ROWS）
    $perPage  = $rowBytes ? 16384 / $rowBytes : 0;           // 每叶页能装多少行
    $leaves   = $perPage ? $info['r'] / $perPage : 0;
    $mid = intdiv((int)$info['r'], 2) + 1;
    [$mn, $cnt, $w] = perQuery($q("SELECT name FROM bt WHERE id = $mid"), 2000, 60);
    printf("%-10s %-12.1f %-14.0f %-20s %-14s %s\n", $target, $perPage, $leaves,
        sprintf('%.3f', $mn), "$cnt/$w",
        $mn <= 1 ? '1' : ($mn <= 2 ? '2（根即叶）' : ($mn <= 3 ? '3' : '?')));
}

echo "\n========== 扇出推算 ==========\n";
$info = $pdo->query("SELECT TABLE_ROWS r, DATA_LENGTH d FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA='learn' AND TABLE_NAME='bt'")->fetch(PDO::FETCH_ASSOC);
$rowBytes = $info['d'] / $info['r'];
$perPage  = 16384 / $rowBytes;
printf("  末档 %d 行，平均行宽 %.1f B → 每叶页 %.0f 行，叶页数 ≈ %.0f\n",
    $info['r'], $rowBytes, $perPage, $info['r'] / $perPage);
printf("  非叶层每条记录 = PK 4 B + 子页号 4 B + 记录头 ≈ 13~18 B → 根页扇出 ≈ %d~%d\n",
    intval(16384 / 18), intval(16384 / 13));
printf("  叶页数超过扇出就要再加一层 → 跳变点在 %.0f ~ %.0f 行之间（实测落在 20 万 ~ 50 万之间）\n",
    16384 / 18 * $perPage, 16384 / 13 * $perPage);
