<?php
/**
 * Q13 为什么用 B+Tree：直接量出「树高」
 *
 * 原理：主键点查要读几个页 = B+Tree 的层数。
 * Innodb_buffer_pool_read_requests 是全局计数器，FLUSH STATUS 清不掉，
 * 且同实例上其他库的活动会污染它（污染只会让读数偏大、不会偏小），
 * 所以：反复开测量窗口 → 只采「窗口内空转漂移 ≈ 0」的干净样本 → 取最小值。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/07-btree.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$pdo = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo "MySQL ", $pdo->query('SELECT VERSION()')->fetchColumn(), "\n\n";

function pages(): int {
    global $pdo;
    return (int)$pdo->query("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read_requests'")
                    ->fetch(PDO::FETCH_ASSOC)['Value'];
}

function handler(string $name): int {
    global $pdo;
    return (int)$pdo->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_ASSOC)['Value'];
}

/**
 * 差分法 + 脏窗口剔除。
 * 一个「窗口」= 连续跑 n 次 fn，取计数器增量；窗口结束后再空转一小段测漂移，
 * 漂移超过 delta 的 2% 就判定该窗口被别的会话污染，丢弃。
 * 返回干净样本的最小值（污染只增不减，最小值最接近真值）。
 */
function perQuery(callable $fn, int $n, string $label, int $want = 3, int $maxTry = 40): void {
    $fn();
    $clean = [];
    for ($t = 0; $t < $maxTry && count($clean) < $want; $t++) {
        $t0 = microtime(true);
        $a = pages();
        for ($i = 0; $i < $n; $i++) { $fn(); }
        $b = pages();
        $dt = microtime(true) - $t0;
        $a2 = pages(); usleep(200000); $drift = pages() - $a2;
        $delta = $b - $a;
        if ($drift <= max(2, $delta * 0.02)) {
            $clean[] = [$delta / $n, $dt / $n * 1000, $delta];
        }
    }
    if (!$clean) { printf("  %-40s  全部窗口都被污染，放弃\n", $label); return; }
    $per = array_column($clean, 0); sort($per);
    $ms  = array_column($clean, 1); sort($ms);
    printf("  %-40s %9.3f 页/次  %8.4f ms/次   (干净窗口 %d 个, 原始页数 %s)\n",
        $label, $per[0], $ms[0], count($clean), implode('/', array_column($clean, 2)));
}

$stmt = fn(string $sql) => function () use ($sql, $pdo) {
    $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
};

/** Handler_* 是会话级计数器，FLUSH STATUS 可清零，不受其他会话影响 */
function handlerCount(string $sql, string $label): void {
    global $pdo;
    $pdo->query("FLUSH STATUS");
    $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    printf("  %-40s read_key=%-6d read_next=%-8d read_rnd_next=%-8d rows_read=%d\n", $label,
        handler('Handler_read_key'), handler('Handler_read_next'),
        handler('Handler_read_rnd_next'), 0);
}

echo "========== 1) 树高 = 一次点查读几页 ==========\n";

$pdo->exec("DROP TABLE IF EXISTS tiny");
$pdo->exec("CREATE TABLE tiny (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO tiny (v) SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5");
for ($i = 0; $i < 6; $i++) { $pdo->exec("INSERT INTO tiny (v) SELECT v FROM tiny"); }
echo "  tiny 表行数: ", $pdo->query("SELECT COUNT(*) FROM tiny")->fetchColumn(), "\n";
perQuery($stmt("SELECT v FROM tiny WHERE id = 20"), 2000, "tiny（320 行，索引只有根页）");
perQuery($stmt("SELECT name FROM users WHERE id = 12345"), 2000, "users（50 万行）主键点查");
perQuery($stmt("SELECT name FROM users WHERE email = 'u12345@example.com'"), 2000, "users 二级索引 idx_email + 回表");

echo "\n========== 2) 全表扫描 vs 索引扫描 ==========\n";
perQuery($stmt("SELECT SUM(LENGTH(name)) FROM users"), 1, "聚簇索引全覆盖（全表扫描）", 3, 60);
perQuery($stmt("SELECT COUNT(*) FROM users WHERE city='北京' AND age=30"), 500, "idx_city_age 覆盖扫描 2000 行");

echo "\n========== 3) 范围扫描：叶页顺着链表走 ==========\n";
foreach ([100, 1000, 10000] as $rows) {
    $hi = 100000 + $rows;
    perQuery($stmt("SELECT name FROM users WHERE id BETWEEN 100000 AND $hi"), 200, "范围扫 $rows 行", 2, 60);
}

echo "\n========== 4) 会话级 Handler 计数器（不受其他会话干扰）==========\n";
handlerCount("SELECT name FROM users WHERE id = 12345", "主键点查");
handlerCount("SELECT name FROM users WHERE id BETWEEN 100000 AND 100999", "主键范围扫 1000 行");
handlerCount("SELECT COUNT(*) FROM users WHERE city='北京' AND age=30", "idx_city_age 覆盖扫 2000 行");
handlerCount("SELECT SUM(LENGTH(name)) FROM users WHERE city='北京' AND age=30", "idx_city_age + 回表 2000 行");
handlerCount("SELECT SUM(LENGTH(name)) FROM users", "全表扫描 50 万行");
handlerCount("SELECT SUM(LENGTH(name)) FROM users WHERE name='user1'", "无索引列查询（全表扫）");

echo "\n========== 5) 实测页数 vs 算术推导 ==========\n";
$s = $pdo->query("SELECT TABLE_ROWS r, AVG_ROW_LENGTH a, DATA_LENGTH d, INDEX_LENGTH i
                  FROM information_schema.TABLES WHERE TABLE_SCHEMA='learn' AND TABLE_NAME='users'")->fetch(PDO::FETCH_ASSOC);
$ts = $pdo->query("SELECT NUM_ROWS, CLUST_INDEX_SIZE, OTHER_INDEX_SIZE FROM information_schema.INNODB_TABLESTATS
                   WHERE NAME='learn/users'")->fetch(PDO::FETCH_ASSOC);
printf("  TABLE_ROWS=%d  AVG_ROW_LENGTH=%d  DATA_LENGTH=%d  INDEX_LENGTH=%d\n",
    $s['r'], $s['a'], $s['d'], $s['i']);
printf("  INNODB_TABLESTATS: NUM_ROWS=%d  聚簇索引=%d 页  二级索引合计=%d 页\n",
    $ts['NUM_ROWS'], $ts['CLUST_INDEX_SIZE'], $ts['OTHER_INDEX_SIZE']);
printf("  16384 B/页 ÷ %d B/行 = %.1f 行/页  →  50 万行 = %.0f 个叶页（实测聚簇索引 %d 页）\n",
    $s['a'], 16384 / $s['a'], $s['r'] / (16384 / $s['a']), $ts['CLUST_INDEX_SIZE']);
