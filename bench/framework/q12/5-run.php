<?php
/**
 * Q12 实测：分层边界值不值得守，用数字说话
 *   A) 越界代价：Controller 里复制一份业务规则 → 同一笔订单，HTTP 与 CLI 两个入口算出两个价
 *   B) 越界代价：Service 认识 HTTP → 队列 / CLI 调不动（真实报错）
 *   C) 越界代价：业务规则落进仓储实现 → 单测（内存实现）通过、线上（真存储）拒绝
 *   D) 越界代价：事务边界写在 Controller → HTTP 路径正常，队列路径留下半成品数据
 *   E) 边界收益：Service 只依赖接口 → 不连数据库也能测（0 次查询）
 *
 * 跑法：docker exec learn-php php /app/bench/framework/q12/5-run.php
 */
require __DIR__ . '/1-domain.php';
require __DIR__ . '/2-infra.php';
require __DIR__ . '/3-application.php';
require __DIR__ . '/4-http.php';

$pdo = new PDO('mysql:host=learn-mysql;port=3306;dbname=q10_n1;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('DROP TABLE IF EXISTS order_items');
$pdo->exec('DROP TABLE IF EXISTS orders');
$pdo->exec('CREATE TABLE orders (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount INT NOT NULL,
    discount INT NOT NULL,
    total INT NOT NULL,
    status VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_status (status)
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    sku VARCHAR(32) NOT NULL,
    qty INT NOT NULL,
    price INT NOT NULL,
    CONSTRAINT chk_qty CHECK (qty > 0)
) ENGINE=InnoDB');

$repo    = new PdoOrderRepository($pdo);
$policy  = new PricingPolicy();
$service = new PlaceOrderService($repo, $policy);

$reset = function () use ($pdo) {
    $pdo->exec('DELETE FROM order_items');
    $pdo->exec('DELETE FROM orders');
};
$countOrders = fn() => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();

/** 同一笔订单：VIP 用户，金额 10000 分 */
$input = ['user_id' => 7, 'amount' => 10000, 'vip' => true, 'sku' => 'SKU-A', 'qty' => 1];

echo "# Q12 分层边界实测\n\n";
echo "- PHP " . PHP_VERSION . " / MySQL " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "- 场景：VIP 用户下单 10000 分（满减 10% + VIP 5%，正确总价 8500）\n\n";

// ---------- A) 规则被复制成两份 ----------
$reset();
$cli = $service->place($input);                       // CLI / 队列 / 定时任务走 Service
$http = (new BadOrderController($pdo))->store(new Request($input));   // HTTP 走 Controller 里的复制品

printf("## A) Controller 里复制业务规则 → 两个入口两个价\n\n");
printf("| 入口 | 代码路径 | total |\n| --- | --- | ---: |\n");
printf("| CLI / 队列 | `PlaceOrderService::place()`（规则来自 PricingPolicy） | %d |\n", $cli['total']);
printf("| HTTP | `BadOrderController::store()`（抄了一份规则，漏了 VIP） | %d |\n", $http->body['total']);
$gap = $http->body['total'] - $cli['total'];
printf("\n同一笔订单，HTTP 入口多收了 **%d 分（%.2f 元）**；两份规则各自演化下去只会越差越多。\n\n",
    $gap, $gap / 100);

// ---------- B) Service 认识 HTTP ----------
echo "## B) Service 里读 \$_SERVER / 返回 Response → 队列里跑不了\n\n```text\n";
$rbs = new RequestBoundService($repo, $policy);
printf("CLI（或队列消费者）调用 placeFromGlobals()：\n");

// ① 取用户身份：HTTP 头在 CLI 下不存在，但 ?? false 让它静默降级
$resp = $rbs->placeFromGlobals($input);
printf("  ① 不报错，静默算错价：total=%d，正确值 %d（VIP 折扣丢了）\n", $resp->body['total'], $cli['total']);

// ② 返回 Response：调用方要的是数据，拿到的却是个对象
try {
    $total = $resp['total'];
    printf("  ② \$resp['total'] = %s（意外没报错）\n", var_export($total, true));
} catch (Throwable $e) {
    printf("  ② 调用方按数组取数：%s: %s\n", get_class($e), $e->getMessage());
}
printf("  返回类型是 %s —— 队列消费者拿到的不是数据而是一个 HTTP 响应对象\n", JsonResponse::class);
echo "```\n\n";

// ---------- C) 业务规则落进仓储 ----------
$reset();
$ruleRepo = new RuleInRepositoryRepository();
$ruleSvc  = new PlaceOrderService($ruleRepo, $policy);
$vipSmall = ['user_id' => 7, 'amount' => 1000, 'vip' => true, 'sku' => 'SKU-A', 'qty' => 1];

$memRepo = new InMemoryOrderRepository();
$memSvc  = new PlaceOrderService($memRepo, $policy);

$prod = '成功';
try { $ruleSvc->place($vipSmall); } catch (DomainException $e) { $prod = '拒绝：' . $e->getMessage(); }
$test = '成功';
try { $memSvc->place($vipSmall); } catch (DomainException $e) { $test = '拒绝：' . $e->getMessage(); }

echo "## C) 业务规则写进仓储实现 → 单测与线上行为不一致\n\n";
echo "| 谁在跑 | 用的仓储实现 | VIP 1000 分下单的结果 |\n| --- | --- | --- |\n";
printf("| 单元测试 | `InMemoryOrderRepository` | %s |\n", $test);
printf("| 线上 | `RuleInRepositoryRepository`（规则被塞进 save()） | %s |\n", $prod);
printf("\n同一个 Service、同一个入参，结果不同 —— 因为规则跟着实现走，而不是跟着业务走。\n\n");

// ---------- D) 事务边界写在 Controller ----------
echo "## D) 事务边界写错层 → 队列路径留下半成品数据\n\n";
$reset();
$outside = new OutsideTxOrderService($repo, $policy);
$badQty  = ['user_id' => 7, 'amount' => 10000, 'vip' => true, 'sku' => 'SKU-A', 'qty' => 0];

// D-1：HTTP 路径：Controller 开了事务（看起来没问题）
$pdo->beginTransaction();
$httpErr = '-';
try {
    $outside->place($badQty);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $httpErr = 'PDOException(' . $e->errorInfo[1] . ') ' . (explode(':', $e->getMessage())[2] ?? '');
}
$afterHttp = $countOrders();

// D-2：队列路径：同一个 Service，没人开事务
$reset();
$queueErr = '-';
try {
    $outside->place($badQty);
} catch (Throwable $e) {
    $queueErr = 'PDOException(' . $e->errorInfo[1] . ') ' . (explode(':', $e->getMessage())[2] ?? '');
}
$afterQueue = $countOrders();

// D-3：事务放回 Service（正确做法）
$reset();
$txErr = '-';
try {
    $service->place($badQty);
} catch (Throwable $e) {
    $txErr = 'PDOException(' . $e->errorInfo[1] . ') ' . (explode(':', $e->getMessage())[2] ?? '');
}
$afterService = $countOrders();

echo "| 事务开在哪 | 调用方 | 明细插入失败后的报错 | orders 残留行数 |\n| --- | --- | --- | ---: |\n";
printf("| Controller | HTTP 请求 | %s | %d |\n", $httpErr, $afterHttp);
printf("| Controller | 队列消费（没人开事务） | %s | **%d** |\n", $queueErr, $afterQueue);
printf("| Service（正确） | 队列消费 | %s | **%d** |\n", $txErr, $afterService);
printf("\n同一段订单编排代码，事务写在 Controller 时：HTTP 路径残留 0 行，队列路径残留 %d 行脏数据。\n\n", $afterQueue);

// ---------- E) 边界收益：不连库也能测 Service ----------
echo "## E) 边界收益：Service 只依赖接口 → 没有数据库也能测\n\n";
$memRepo2 = new InMemoryOrderRepository();
$svc = new PlaceOrderService($memRepo2, new PricingPolicy());

$t0 = hrtime(true);
$r1 = $svc->place(['user_id' => 1, 'amount' => 20000, 'vip' => false, 'sku' => 'S', 'qty' => 2]);
$r2 = $svc->place(['user_id' => 2, 'amount' => 2000,  'vip' => true,  'sku' => 'S', 'qty' => 1]);
$ms = (hrtime(true) - $t0) / 1e6;

printf("- 用 `InMemoryOrderRepository` 跑 2 笔下单：%.3f ms，orders 表新增 0 行（全程没碰 MySQL）\n", $ms);
printf("- 断言拿到的数据：订单 1 total=%d，订单 2 total=%d（2000 分未达满减线，VIP 减 5%%）\n",
    $memRepo2->findById($r1['order_id'])['total'], $memRepo2->findById($r2['order_id'])['total']);
printf("- 换成 `PdoOrderRepository`，Service 一行不改：%s\n", $cli['status'] === 'placed' ? '（见上面 A 段，同一份 Service 已在 MySQL 上跑通）' : '（异常）');
