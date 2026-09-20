<?php
/**
 * Q24 分库分表：什么时候必须分、分片键怎么选 —— 把「分了之后会变贵的东西」量出来
 *
 * 分片不是免费的。分片键命中的查询变快，非分片键的查询要打 N 个分片再归并，
 * 全局唯一 ID 不能再用自增，跨片 JOIN / 聚合 / 排序全部要改写。
 * 本脚本在一台实例上模拟 4 个分片（orders_0..orders_3 四张表），把这几笔账逐条量出来。
 *
 * 明确说明：这是「单机四表」模拟分片，量的是「SQL 条数、要合并的行数、单表规模」这些
 * 与网络无关的量。真·跨机器的网络 RTT / 部分失败 / 分布式事务代价无法在本环境测量，写「未实测」。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/19-sharding.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
$pdo = new PDO($dsn, 'root', 'root', $opt);
echo "MySQL ", $pdo->query('SELECT VERSION()')->fetchColumn(), "\n\n";

const SHARDS = 4;
const ROWS   = 600000;   // 单表 60 万；分片后每片 15 万 —— 够看出 DDL / 索引体积的规模差

// ============================================================
echo "========== 0) 先量「不分片」时，单表规模变大到底哪里变贵 ==========\n";
// ============================================================
$pdo->exec("DROP TABLE IF EXISTS one_big");
$pdo->exec("CREATE TABLE one_big (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  order_no CHAR(20) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_user (user_id), KEY idx_no (order_no)
) ENGINE=InnoDB");
$pdo->exec("SET SESSION cte_max_recursion_depth = 1200000");
$pdo->exec("INSERT INTO one_big (user_id, order_no, amount, created_at)
  WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < " . ROWS . ")
  SELECT n % 100000, CONCAT('NO', LPAD(n, 16, '0')), ROUND(RAND(n) * 1000, 2), NOW() - INTERVAL (n % 730) DAY FROM seq");
$pdo->query("ANALYZE TABLE one_big")->fetchAll();

function shardStat(PDO $pdo, string $t): array {
    $pdo->query("ANALYZE TABLE $t")->fetchAll();
    return $pdo->query("SELECT TABLE_ROWS r, DATA_LENGTH d, INDEX_LENGTH i FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA='learn' AND TABLE_NAME='$t'")->fetch(PDO::FETCH_ASSOC);
}
$s = shardStat($pdo, 'one_big');
printf("  单表 %d 行：聚簇 %.0f MB + 索引 %.0f MB = %.0f MB\n", $s['r'], $s['d'] / 1048576, $s['i'] / 1048576,
    ($s['d'] + $s['i']) / 1048576);

function timeit(callable $fn, int $n = 5): float {
    $best = INF;
    for ($i = 0; $i < $n; $i++) {
        $t0 = microtime(true);
        $fn();
        $best = min($best, (microtime(true) - $t0) * 1000);
    }
    return $best;
}

echo "\n  单表规模效应（每项取 5 次最小）：\n";
printf("    主键点查                 %7.3f ms\n", timeit(fn() => $pdo->query("SELECT * FROM one_big WHERE id = 777777")->fetchAll()));
printf("    user_id 二级索引点查      %7.3f ms（1 个 user 平均 10 单）\n",
    timeit(fn() => $pdo->query("SELECT * FROM one_big WHERE user_id = 777")->fetchAll()));
printf("    COUNT(*) 全表             %7.3f ms\n", timeit(fn() => $pdo->query("SELECT COUNT(*) FROM one_big")->fetchAll(), 3));
printf("    最近 7 天范围扫描         %7.3f ms\n",
    timeit(fn() => $pdo->query("SELECT COUNT(*) FROM one_big WHERE created_at > NOW() - INTERVAL 7 DAY")->fetchAll(), 3));

// ============================================================
echo "\n========== 1) 建 4 个分片，user_id 取模路由 ==========\n";
// ============================================================
for ($i = 0; $i < SHARDS; $i++) {
    $pdo->exec("DROP TABLE IF EXISTS orders_$i");
    $pdo->exec("CREATE TABLE orders_$i (
      id BIGINT UNSIGNED NOT NULL,          -- 分片后不能再靠单表 AUTO_INCREMENT，ID 由外部生成
      user_id INT UNSIGNED NOT NULL,
      order_no CHAR(20) NOT NULL,
      amount DECIMAL(10,2) NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id), KEY idx_user (user_id), KEY idx_no (order_no)
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO orders_$i (id, user_id, order_no, amount, created_at)
      WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n < " . (ROWS / SHARDS) . ")
      SELECT n * " . SHARDS . " + $i, (n * " . SHARDS . " + $i) % 100000,
             CONCAT('NO', LPAD(n * " . SHARDS . " + $i, 16, '0')), ROUND(RAND(n) * 1000, 2),
             NOW() - INTERVAL ((n * " . SHARDS . " + $i) % 730) DAY FROM seq");
}
$tot = 0;
for ($i = 0; $i < SHARDS; $i++) { $tot += (int)shardStat($pdo, "orders_$i")['r']; }
printf("  4 个分片共 %d 行，每片约 %.0f 万行（对比：不分片是 %d 万行一张表）\n",
    $tot, $tot / SHARDS / 10000, $s['r'] / 10000);

// ============================================================
echo "\n========== 2) 分片键命中：只打 1 个分片 ==========\n";
// ============================================================
$uid = 777;
$shard = $uid % SHARDS;
$t = timeit(function () use ($pdo, $shard, $uid) {
    $pdo->query("SELECT * FROM orders_$shard WHERE user_id = $uid")->fetchAll();
});
printf("  user_id=%d 落到 orders_%d：1 条 SQL，%7.3f ms\n", $uid, $shard, $t);

// ============================================================
echo "\n========== 3) 非分片键查询：必须打满 N 个分片再归并 ==========\n";
// ============================================================
$no = 'NO' . str_pad('777777', 16, '0', STR_PAD_LEFT);
$t = timeit(function () use ($pdo, $no) {
    for ($i = 0; $i < SHARDS; $i++) {
        $pdo->query("SELECT * FROM orders_$i WHERE order_no = '$no'")->fetchAll();
    }
});
printf("  按 order_no 精确查：要发 %d 条 SQL，%7.3f ms（4 片串行，真分片还得加 4 次网络 RTT）\n", SHARDS, $t);

// 跨片 COUNT
$t = timeit(function () use ($pdo) {
    $n = 0;
    for ($i = 0; $i < SHARDS; $i++) { $n += (int)$pdo->query("SELECT COUNT(*) FROM orders_$i")->fetchColumn(); }
});
printf("  跨片 COUNT(*)：%d 条 SQL 相加，%7.3f ms\n", SHARDS, $t);

// 跨片 ORDER BY ... LIMIT
$t = timeit(function () use ($pdo) {
    $all = [];
    for ($i = 0; $i < SHARDS; $i++) {
        foreach ($pdo->query("SELECT id, user_id, amount FROM orders_$i ORDER BY amount DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $all[] = $r;
        }
    }
    usort($all, fn($a, $b) => $b['amount'] <=> $a['amount']);
    $top = array_slice($all, 0, 10);
});
printf("  跨片「金额 TOP10」：%d 条 SQL 各取 TOP10，再在应用层归并 %d 行取前 10，%7.3f ms\n", SHARDS, SHARDS * 10, $t);

// ============================================================
echo "\n========== 4) 分片后最容易被忽略的一笔：分片键选错，热点打在一个片上 ==========\n";
// ============================================================
foreach (['user_id' => 'user_id % 4', 'created_at 的月份' => "MONTH(created_at) % 4"] as $k => $expr) {
    $cnt = [];
    for ($i = 0; $i < SHARDS; $i++) {
        $cnt[$i] = (int)$pdo->query("SELECT COUNT(*) FROM orders_$i WHERE $expr = $i")->fetchColumn();
    }
    $max = max($cnt); $min = min($cnt);
    printf("  按「%s」分片后各片行数: %s → 最大/最小 = %.2f\n", $k,
        implode('/', $cnt), $min ? $max / $min : INF);
}

// ============================================================
echo "\n========== 5) 全局唯一 ID：自增不能用了，主键用「随机 ID」的代价 ==========\n";
// ============================================================
/*
 * 【踩过的坑】第一版这里是「for 循环 5 万次单行 INSERT」，
 * 结果三个写法都测出 ~11 秒、比值 0.95x —— 因为 5 万次单行 INSERT 测的是
 * **5 万次网络往返**，插入本身的开销被完全淹没，比值毫无意义。
 * 改成每 1000 行一条多值 INSERT（共 50 条语句），才是「同样批量、不同主键分布」的对比。
 */
$pdo->exec("SET SESSION information_schema_stats_expiry = 0");
foreach (['id_auto', 'id_snow', 'id_rand'] as $t) { $pdo->exec("DROP TABLE IF EXISTS $t"); }
$pdo->exec("CREATE TABLE id_auto (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v INT) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE id_snow (id BIGINT UNSIGNED PRIMARY KEY, v INT) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE id_rand (id BIGINT UNSIGNED PRIMARY KEY, v INT) ENGINE=InnoDB");

$M = 50000; $BATCH = 1000; $ROUNDS = 3;

$batchInsert = function (string $table, callable $idOf) use ($pdo, $M, $BATCH): void {
    $pdo->beginTransaction();
    for ($s = 0; $s < $M; $s += $BATCH) {
        $vals = [];
        for ($i = $s; $i < $s + $BATCH; $i++) {
            $id = $idOf($i);
            $vals[] = $id === null ? "($i)" : "($id,$i)";
        }
        $cols = $idOf(0) === null ? '(v)' : '(id,v)';
        $pdo->exec("INSERT INTO $table $cols VALUES " . implode(',', $vals));
    }
    $pdo->commit();
};

$seqId  = null;                                     // 自增：由 MySQL 分配
$base   = (int)(microtime(true) * 1000) << 20;
$snowId = fn(int $i) => $base + ($i << 8) + random_int(0, 255);
$randId = fn(int $i) => random_int(1, PHP_INT_MAX >> 1);

$t = [];
foreach (['id_auto' => $seqId, 'id_snow' => $snowId, 'id_rand' => $randId] as $tbl => $gen) {
    $best = INF;
    for ($r = 0; $r < $ROUNDS; $r++) {
        $pdo->exec("TRUNCATE TABLE $tbl");
        $t0 = microtime(true);
        $batchInsert($tbl, $gen ?? fn(int $i) => null);
        $best = min($best, (microtime(true) - $t0) * 1000);
    }
    $t[$tbl] = $best;
}

printf("  每批 %d 行、共 %d 行、取 %d 轮最小值：\n", $BATCH, $M, $ROUNDS);
printf("    %-8s 自增（严格递增）        %8.0f ms   基准\n", 'id_auto', $t['id_auto']);
printf("    %-8s 雪花（趋势递增，局部乱序）%8.0f ms   %.2fx\n", 'id_snow', $t['id_snow'], $t['id_snow'] / $t['id_auto']);
printf("    %-8s 随机（完全无序）        %8.0f ms   %.2fx\n", 'id_rand', $t['id_rand'], $t['id_rand'] / $t['id_auto']);
foreach (['id_auto', 'id_snow', 'id_rand'] as $tbl) {
    $r = $pdo->query("ANALYZE TABLE $tbl")->fetchAll();
    $r = $pdo->query("SELECT DATA_LENGTH d FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA='learn' AND TABLE_NAME='$tbl'")->fetch(PDO::FETCH_ASSOC);
    printf("    %-8s 聚簇索引占 %5.2f MB（%d 行）\n", $tbl, $r['d'] / 1048576, $M);
}

// ============================================================
echo "\n========== 6) DDL：分片最硬的理由 —— 表越大，改表越停不下来 ==========\n";
// ============================================================
$tShard = timeit(function () use ($pdo) { $pdo->query("ALTER TABLE orders_0 ADD INDEX idx_created (created_at), ALGORITHM=INPLACE, LOCK=NONE")->fetchAll(); }, 1);
$tBig   = timeit(function () use ($pdo) { $pdo->query("ALTER TABLE one_big ADD INDEX idx_created (created_at), ALGORITHM=INPLACE, LOCK=NONE")->fetchAll(); }, 1);
printf("  %d 万行的分片 orders_0 加索引：  %7.0f ms\n", (int)(ROWS / SHARDS / 10000), $tShard);
printf("  %d 万行的单表 one_big 加索引： %7.0f ms  (%.1fx)\n", (int)(ROWS / 10000), $tBig, $tBig / max(1.0, $tShard));
$s2 = shardStat($pdo, 'one_big');
printf("  加完索引后 one_big 索引占 %.0f MB（原 %.0f MB），这部分内存/IO 是净增的\n",
    $s2['i'] / 1048576, $s['i'] / 1048576);

echo "\n  未实测：真·跨机器分片的网络 RTT、部分失败重试、跨片分布式事务、扩容时 rehash 的搬迁量\n";
echo "          —— 本环境只有一台 MySQL 实例，这些量不出来，不编数字。\n";
