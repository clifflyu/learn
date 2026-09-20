<?php
/**
 * Q37 补充：Redis SETNX 令牌的三个失败模式
 *
 * 场景 A：SETNX 拿到令牌后业务失败，令牌不释放 → 客户端重试全被当重复挡掉 → 业务永久丢失
 * 场景 B：令牌丢失（maxmemory 淘汰 / failover / TTL 到期）→ 同一个请求再次落到「写」分支 → 重复
 * 场景 C：幂等记录表 + 状态机（processing → success / failed 可重试）→ 既不丢也不重
 *
 * 用法：docker exec learn-php php /app/bench/mq/q37-token.php
 */
declare(strict_types=1);

const DSN = 'mysql:host=learn-mysql;port=3306;dbname=q37_idem;charset=utf8mb4';
const RPREFIX = 'q37:tok:';

$pdo = new PDO(DSN, 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$r = new Redis();
$r->connect('learn-redis', 6379, 2.0);

$POINTS = 100;   // 每笔业务给用户加 100 积分

function resetState(PDO $pdo, Redis $r): void
{
    $pdo->exec('TRUNCATE t_account');
    $pdo->exec('INSERT INTO t_account (uid, points, updated_at) VALUES (1001, 0, NOW(3))');
    $pdo->exec('TRUNCATE t_idem_record');
    foreach ($r->keys(RPREFIX . '*') as $k) {
        $r->del($k);
    }
}

/**
 * 一次「客户端调用」，内部带 3 次重试（SDK 超时的标准行为）。
 * $firstAttemptFails 模拟第一次执行业务时 DB 抖动。
 */
function callWithRetry(PDO $pdo, Redis $r, string $mode, string $otn, int $points, bool $firstAttemptFails): array
{
    $stat = ['attempts' => 0, 'biz_runs' => 0, 'rejected' => 0, 'succeeded' => 0, 'lost' => 0];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $stat['attempts']++;
        $key = RPREFIX . $otn;

        if ($mode === 'token-only') {
            $ok = $r->set($key, '1', ['nx', 'ex' => 60]);
            if (!$ok) {
                $stat['rejected']++;
                continue;                       // 被当成重复请求，直接返回
            }
            if ($attempt === 1 && $firstAttemptFails) {
                // 业务失败：令牌不释放（真实代码里这里往往是异常直接抛出）
                continue;
            }
            $pdo->prepare('UPDATE t_account SET points = points + ?, updated_at = NOW(3) WHERE uid = 1001')
                ->execute([$points]);
            $stat['biz_runs']++;
            $stat['succeeded']++;
            continue;
        }

        if ($mode === 'state-machine') {
            // 幂等记录表：主键就是幂等键
            try {
                $pdo->prepare('INSERT INTO t_idem_record (idem_key,state,biz_id,result_body,created_at,updated_at)
                               VALUES (?,0,NULL,NULL,NOW(3),NOW(3))')
                    ->execute([$otn]);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                // 已存在：看状态决定是「返回上次结果」还是「允许重试」
                $row = $pdo->prepare('SELECT state, result_body FROM t_idem_record WHERE idem_key = ?');
                $row->execute([$otn]);
                $rec = $row->fetch();
                if ((int) $rec['state'] === 1) {
                    $stat['rejected']++;
                    $stat['succeeded']++;       // 重复请求返回上次结果
                    continue;
                }
                if ((int) $rec['state'] === 2) {
                    // failed：重置为 processing 后允许重跑
                    $pdo->prepare('UPDATE t_idem_record SET state = 0, updated_at = NOW(3) WHERE idem_key = ?')
                        ->execute([$otn]);
                } else {
                    $stat['rejected']++;        // 还在 processing，让客户端稍后重试
                    continue;
                }
            }

            try {
                $pdo->beginTransaction();
                if ($attempt === 1 && $firstAttemptFails) {
                    throw new RuntimeException('模拟 DB 抖动');
                }
                $pdo->prepare('UPDATE t_account SET points = points + ?, updated_at = NOW(3) WHERE uid = 1001')
                    ->execute([$points]);
                $pdo->prepare('UPDATE t_idem_record SET state = 1, result_body = ?, updated_at = NOW(3) WHERE idem_key = ?')
                    ->execute(['{"ok":true}', $otn]);
                $pdo->commit();
                $stat['biz_runs']++;
                $stat['succeeded']++;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $pdo->prepare('UPDATE t_idem_record SET state = 2, updated_at = NOW(3) WHERE idem_key = ?')
                    ->execute([$otn]);
            }
            continue;
        }
    }

    if ($stat['succeeded'] === 0) {
        $stat['lost'] = 1;
    }
    return $stat;
}

function report(string $title, array $stat, PDO $pdo, int $points): void
{
    $bal = (int) $pdo->query('SELECT points FROM t_account WHERE uid = 1001')->fetchColumn();
    echo "  {$title}\n";
    printf("    客户端调用 3 次重试 → 业务真正执行 %d 次，被幂等挡回 %d 次\n", $stat['biz_runs'], $stat['rejected']);
    printf("    用户积分余额 = %d（期望 %d）  差 = %+d\n", $bal, $points, $bal - $points);
    printf("    业务是否丢失：%s\n", $stat['lost'] ? '是 —— 重试全被挡掉，这笔业务永远没执行' : '否');
}

// ================================================================ 场景 A
echo "=== 场景 A：Redis SETNX 裸用，业务失败后令牌不释放 ===\n";
resetState($pdo, $r);
$stat = callWithRetry($pdo, $r, 'token-only', 'OTN-A-1', $POINTS, true);
report('SETNX 令牌 + 首次业务失败', $stat, $pdo, $POINTS);

// ================================================================ 场景 B
echo "\n=== 场景 B：令牌丢失后同一请求再次到达 ===\n";
resetState($pdo, $r);
$s1 = callWithRetry($pdo, $r, 'token-only', 'OTN-B-1', $POINTS, false);
printf("  第一次回调执行完毕：业务执行 %d 次，积分 = %d\n",
    $s1['biz_runs'], (int) $pdo->query('SELECT points FROM t_account WHERE uid = 1001')->fetchColumn());
// 模拟：maxmemory 淘汰、主从切换丢写、或 TTL 设得太短
$r->del(RPREFIX . 'OTN-B-1');
printf("  [模拟] 令牌 key 被淘汰/丢失（DEL %sOTN-B-1）\n", RPREFIX);
$s2 = callWithRetry($pdo, $r, 'token-only', 'OTN-B-1', $POINTS, false);
$bal = (int) $pdo->query('SELECT points FROM t_account WHERE uid = 1001')->fetchColumn();
printf("  第二次同样的回调到达：业务又执行 %d 次，积分 = %d（期望 %d，多入账 %+d）\n",
    $s2['biz_runs'], $bal, $POINTS, $bal - $POINTS);

// ================================================================ 场景 C
echo "\n=== 场景 C：幂等记录表 + 状态机（同一笔请求重试 3 次） ===\n";
resetState($pdo, $r);
$stat = callWithRetry($pdo, $r, 'state-machine', 'OTN-C-1', $POINTS, true);
report('幂等记录表 + 首次业务失败', $stat, $pdo, $POINTS);
$rec = $pdo->query("SELECT idem_key,state,result_body FROM t_idem_record")->fetchAll();
echo "    幂等记录表最终状态：\n";
foreach ($rec as $x) {
    printf("      idem_key=%s state=%d result=%s\n", $x['idem_key'], $x['state'], $x['result_body'] ?? 'NULL');
}

// 再打一次同样的请求（重复回调）
echo "\n  同一笔请求第 4 次到达（业务已成功）：\n";
$s3 = callWithRetry($pdo, $r, 'state-machine', 'OTN-C-1', $POINTS, false);
$bal = (int) $pdo->query('SELECT points FROM t_account WHERE uid = 1001')->fetchColumn();
printf("    业务执行 %d 次，被挡回 %d 次，积分 = %d（期望 %d）\n", $s3['biz_runs'], $s3['rejected'], $bal, $POINTS);

foreach ($r->keys(RPREFIX . '*') as $k) {
    $r->del($k);
}
