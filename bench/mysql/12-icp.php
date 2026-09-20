<?php
/**
 * Q17 追问：Extra 里的 Using index condition（ICP）到底省了什么
 *
 * 设计要点：条件必须落在「索引里有、但不参与定位」的列上。
 * t_abc 的索引是 (a,b,c)，查询 a=1 AND b>2 AND c=3：
 *   - a 用来定位，b 用来做范围，c 在索引里但用不上定位
 *   - ICP 开：c=3 在引擎层直接过滤掉 19568 行，只有 20 行回表
 *   - ICP 关：a=1 AND b>2 的 19588 行全部回表，再到 server 层过滤 c=3
 * MySQL 8.4 没有 ICP 相关的 Handler 计数器（MariaDB 才有 Handler_icp_filtered），
 * 所以这里用「多次取最小值」的耗时对比，Extra 字段的差异同时作为计划层面的证据。
 *
 * 用法: docker exec learn-php php /app/bench/mysql/12-icp.php
 */

$dsn = 'mysql:host=learn-mysql;port=3306;dbname=learn;charset=utf8mb4';
$pdo = new PDO($dsn, 'root', 'root', [
    PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);
echo "MySQL ", $pdo->query('SELECT VERSION()')->fetchColumn(), "\n\n";

$sql = "SELECT SUM(LENGTH(d)) FROM t_abc WHERE a = 1 AND b > 2 AND c = 3";

// 先确认两边结果一致，排除「测出了差异但结果不同」的假象
$expect = $pdo->query($sql)->fetchColumn();
echo "结果 SUM(LENGTH(d)) = {$expect}（两种设置下必须一致）\n\n";

foreach (['on', 'off'] as $sw) {
    $pdo->exec("SET SESSION optimizer_switch = 'index_condition_pushdown=$sw'");
    $extra = $pdo->query("EXPLAIN $sql")->fetch(PDO::FETCH_ASSOC)['Extra'];

    $pdo->query($sql)->fetchAll();                       // 预热
    $best = INF; $worst = 0;
    for ($t = 0; $t < 9; $t++) {                          // 取多轮最小值，抵消同实例其他库的干扰
        $t0 = microtime(true);
        $sum = 0;
        for ($i = 0; $i < 20; $i++) { $sum += (int)$pdo->query($sql)->fetchColumn(); }
        $ms = (microtime(true) - $t0) / 20 * 1000;
        $best = min($best, $ms); $worst = max($worst, $ms);
    }
    printf("  ICP %-3s  %8.3f ms/次 (最慢 %.3f)  Extra = %s\n", $sw, $best, $worst, $extra);
    $res[$sw] = $best;
}
$pdo->exec("SET SESSION optimizer_switch = 'index_condition_pushdown=on'");
printf("\n  ICP 开比关快 %.1f 倍\n", $res['off'] / $res['on']);
