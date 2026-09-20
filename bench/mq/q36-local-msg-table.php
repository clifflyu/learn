<?php
/**
 * Q36 分布式事务：本地消息表 vs 「先写库再发 MQ」
 *
 * 两者都是 100 笔业务，其中 15 笔在「本地事务已提交」之后、消息发出去之前进程崩溃。
 *
 *   方案 A 先写库、再发 MQ（无补偿依据）→ 崩溃的那 15 笔永远补不回来
 *   方案 B 本地消息表（业务 + 消息记录同一事务 + 投递器补偿）→ 全部补发，最终一致
 *
 * 用法：docker exec learn-php php /app/bench/mq/q36-local-msg-table.php
 */
declare(strict_types=1);

const RABBIT = ['host' => 'q32-rabbit', 'port' => 5672, 'login' => 'guest', 'password' => 'guest', 'vhost' => '/'];
const DSN  = 'mysql:host=learn-mysql;port=3306;dbname=q37_idem;charset=utf8mb4';
const EX   = 'q36.ex';
const Q    = 'q36.downstream';
const BIZ  = 100;
const CRASH = 15;      // 前 15 笔在 commit 之后崩溃

function db(): PDO
{
    return new PDO(DSN, 'root', 'root', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function mq(): array
{
    $c = new AMQPConnection(RABBIT);
    $c->connect();
    $ch = new AMQPChannel($c);
    $q = new AMQPQueue($ch);
    $q->setName(Q);
    $q->setFlags(AMQP_DURABLE);
    $q->declareQueue();
    $e = new AMQPExchange($ch);
    $e->setName(EX);
    $e->setType(AMQP_EX_TYPE_DIRECT);
    $e->setFlags(AMQP_DURABLE);
    $e->declareExchange();
    $q->bind(EX, Q);
    return [$c, $ch, $q, $e];
}

/** 下游收件箱：模拟下游服务收到消息后写自己的库 */
function drainDownstream(PDO $pdo, AMQPQueue $q): int
{
    $n = 0;
    $guard = 0;
    while (($m = $q->get(AMQP_AUTOACK)) !== null && $m !== false) {
        $d = json_decode($m->getBody(), true);
        $st = $pdo->prepare('INSERT INTO t_downstream (biz_id, got_at) VALUES (?, NOW(3))');
        $st->execute([$d['biz_id']]);
        $n++;
        if (++$guard > 100000) {
            break;
        }
    }
    return $n;
}

$pdo = db();
[$c, $ch, $q, $e] = mq();

$pdo->exec('TRUNCATE t_biz_order');
$pdo->exec('TRUNCATE t_local_msg');
$pdo->exec('TRUNCATE t_downstream');
while (($m = $q->get(AMQP_AUTOACK)) !== null && $m !== false) {
}

// ============================================================ 方案 A
echo "=== 方案 A：先写本地库、再发 MQ（两件事不在一个事务里）===\n";
for ($i = 0; $i < BIZ; $i++) {
    $bizId = 'A-' . $i;
    // ① 本地事务：写业务表
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO t_biz_order (biz_id, amount, created_at) VALUES (?, 100.00, NOW(3))')->execute([$bizId]);
    $pdo->commit();
    // ② 发消息 —— 进程在这两步之间崩溃（实测里直接跳过）
    if ($i < CRASH) {
        continue;                      // 模拟 commit 之后、publish 之前崩溃
    }
    $e->publish(json_encode(['biz_id' => $bizId]), Q, AMQP_NOPARAM, ['delivery_mode' => 2]);
}
usleep(200000);
$aRecv = drainDownstream($pdo, $q);
$aBiz  = (int) $pdo->query("SELECT COUNT(*) FROM t_biz_order WHERE biz_id LIKE 'A-%'")->fetchColumn();
printf("  业务表落库 %d 笔，下游收到 %d 笔 → 不一致 %d 笔\n", $aBiz, $aRecv, $aBiz - $aRecv);
echo "  → 崩溃的 " . CRASH . " 笔本地已提交，但消息根本没发出去；数据库里没有任何「待发送」的痕迹，\n";
echo "     所以没有任何东西能驱动补偿，这 " . CRASH . " 笔永远不一致\n\n";

// ============================================================ 方案 B
echo "=== 方案 B：本地消息表（业务 + 消息记录同一本地事务）===\n";
$pdo->exec('TRUNCATE t_biz_order');
$pdo->exec('TRUNCATE t_local_msg');
$pdo->exec('TRUNCATE t_downstream');
while (($m = $q->get(AMQP_AUTOACK)) !== null && $m !== false) {
}
for ($i = 0; $i < BIZ; $i++) {
    $bizId = 'B-' . $i;
    // ① 一个本地事务同时写业务表和消息表 —— 要么都成功，要么都回滚
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO t_biz_order (biz_id, amount, created_at) VALUES (?, 100.00, NOW(3))')->execute([$bizId]);
    $pdo->prepare('INSERT INTO t_local_msg (biz_id, payload, status, retry, created_at) VALUES (?, ?, 0, 0, NOW(3))')
        ->execute([$bizId, json_encode(['biz_id' => $bizId])]);
    $pdo->commit();
    // ② 尽力立即投递（best effort）
    if ($i < CRASH) {
        continue;                      // 同样在 commit 之后崩溃
    }
    $e->publish(json_encode(['biz_id' => $bizId]), Q, AMQP_NOPARAM, ['delivery_mode' => 2]);
    $pdo->prepare('UPDATE t_local_msg SET status = 1, updated_at = NOW(3) WHERE biz_id = ?')->execute([$bizId]);
}
usleep(200000);
$bRecv1 = drainDownstream($pdo, $q);
$bBiz   = (int) $pdo->query("SELECT COUNT(*) FROM t_biz_order WHERE biz_id LIKE 'B-%'")->fetchColumn();
$unsent = (int) $pdo->query('SELECT COUNT(*) FROM t_local_msg WHERE status = 0')->fetchColumn();
printf("  崩溃后：业务表 %d 笔，下游已收到 %d 笔，消息表里 status=0（待投递）%d 笔\n", $bBiz, $bRecv1, $unsent);
echo "  → 关键差别：崩溃的那几笔在消息表里留下了 status=0 的记录，补偿有据可依\n\n";

// 投递器（定时任务 / 独立进程）：扫描 status=0 补发
echo "  --- 补偿投递器启动，扫描 status=0 的记录并补发 ---\n";
$rows = $pdo->query('SELECT biz_id, payload FROM t_local_msg WHERE status = 0 ORDER BY id')->fetchAll();
$sent = 0;
foreach ($rows as $row) {
    $e->publish($row['payload'], Q, AMQP_NOPARAM, ['delivery_mode' => 2]);
    $pdo->prepare('UPDATE t_local_msg SET status = 1, retry = retry + 1, updated_at = NOW(3) WHERE biz_id = ?')
        ->execute([$row['biz_id']]);
    $sent++;
}
usleep(200000);
$bRecv2 = drainDownstream($pdo, $q);
printf("  投递器补发 %d 笔，下游累计收到 %d 笔\n", $sent, $bRecv1 + $bRecv2);
$bBizFinal = (int) $pdo->query("SELECT COUNT(*) FROM t_biz_order WHERE biz_id LIKE 'B-%'")->fetchColumn();
$downTotal = (int) $pdo->query('SELECT COUNT(*) FROM t_downstream')->fetchColumn();
printf("  最终：业务表 %d 笔，下游 %d 笔 → 不一致 %d 笔\n", $bBizFinal, $downTotal, $bBizFinal - $downTotal);
printf("  剩下未投递 %d 笔\n\n",
    (int) $pdo->query('SELECT COUNT(*) FROM t_local_msg WHERE status = 0')->fetchColumn());

echo "=== 两种方案对比 ===\n";
printf("  %-28s 业务落库  下游收到  不一致\n", '方案');
printf("  %-28s %8d %9d %8d\n", 'A 先写库再发 MQ', $aBiz, $aRecv, $aBiz - $aRecv);
printf("  %-28s %8d %9d %8d\n", 'B 本地消息表 + 补偿投递', $bBizFinal, $downTotal, $bBizFinal - $downTotal);
echo "\n  注意：B 的「最终一致」是补偿换来的，代价是\n";
echo "    1) 每条业务消息多一次本地写 + 一次状态更新（消息表会持续增长）\n";
echo "    2) 崩溃后到补偿之间的窗口里，下游仍然看不到这笔业务（延迟不一致，不是实时一致）\n";
echo "    3) 补偿是「至少一次」的，下游必须自己幂等（见 Q37）\n";

$pdo->exec('TRUNCATE t_biz_order');
$pdo->exec('TRUNCATE t_local_msg');
$pdo->exec('TRUNCATE t_downstream');
$c->disconnect();
