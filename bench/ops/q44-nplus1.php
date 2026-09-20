<?php
/**
 * Q44 实测③：代码问题 —— N+1 把一条慢 SQL 放大成了「接口超时」。
 *
 * 业务场景：订单列表接口，要给出每个客户近 110 天「待付款」订单的金额，
 * 外加该客户一共下过多少单。客户列表 30 条。
 *
 *   ?mode=n1    每条客户查一次库（N+1）——而且查的正是那条全表扫描的慢 SQL
 *   ?mode=join  一条 GROUP BY 搞定（JOIN 改写）
 *   ?mode=in    两条查询：主表 IN 批量 + 应用层聚合（不依赖 JOIN，分库分表下更常用）
 *
 * 同时输出本次请求真正执行了多少条 SQL —— 这是 N+1 最直接的证据，
 * 比时间更好用：时间受缓存影响，查询条数不受。
 *
 * 用法: curl 'http://localhost:9311/bench/ops/q44-nplus1.php?mode=n1'
 */

$mode     = $_GET['mode'] ?? 'n1';
$customer = min(300, max(1, (int) ($_GET['n'] ?? 30)));

$pdo = new PDO('mysql:host=learn-mysql;port=3306;dbname=q44_slow;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/** 会话级 SELECT 计数：N+1 的量化证据 */
function selects(PDO $pdo): int
{
    $r = $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM);
    return (int) $r[1];
}

$t0    = microtime(true);
$start = selects($pdo);

$ids = $pdo->query("SELECT id, name FROM customers ORDER BY id LIMIT $customer")->fetchAll(PDO::FETCH_ASSOC);

$result = [];
switch ($mode) {
    case 'n1':
        // 循环里发 SQL：30 个客户 = 30 次全表扫描
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amt FROM orders
              WHERE customer_id = ? AND status = 0 AND created_at >= NOW() - INTERVAL 110 DAY'
        );
        foreach ($ids as $c) {
            $st->execute([$c['id']]);
            $result[$c['id']] = $st->fetch(PDO::FETCH_ASSOC);
        }
        break;

    case 'join':
        // 一条 SQL 出全部：范围扫描一次，然后在应用层对齐
        $sql = 'SELECT o.customer_id, COUNT(*) AS cnt, COALESCE(SUM(o.amount), 0) AS amt
                  FROM orders o
                 WHERE o.customer_id IN (' . implode(',', array_column($ids, 'id')) . ')
                   AND o.status = 0 AND o.created_at >= NOW() - INTERVAL 110 DAY
              GROUP BY o.customer_id';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $result[$r['customer_id']] = $r;
        }
        break;

    case 'in':
    default:
        // 分片/微服务下不能 JOIN 时的写法：一次 IN 批量捞回来，应用层聚合
        $sql = 'SELECT customer_id, amount FROM orders
                 WHERE customer_id IN (' . implode(',', array_column($ids, 'id')) . ')
                   AND status = 0 AND created_at >= NOW() - INTERVAL 110 DAY';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['customer_id'];
            $result[$k]['cnt'] = ($result[$k]['cnt'] ?? 0) + 1;
            $result[$k]['amt'] = ($result[$k]['amt'] ?? 0) + (float) $r['amount'];
        }
        break;
}

$ms      = (microtime(true) - $t0) * 1000;
$queries = selects($pdo) - $start;

header('Content-Type: text/plain; charset=utf-8');
printf("mode           = %s\n", $mode);
printf("客户数         = %d\n", count($ids));
printf("本次请求 SQL 数 = %d   ← N+1 的硬证据\n", $queries);
printf("耗时           = %.1f ms\n", $ms);
printf("结果条数       = %d\n", count($result));
