<?php
// Q41 攻击驱动：对「拼接 SQL」和「PDO 预处理」两套实现跑同一批 payload，
// 打印实际执行的 SQL 和返回结果。
//
// 前置：先灌库（最后一个 payload 会真的把 users 表 DROP 掉，每次跑完都要重灌）
//   docker exec -i learn-mysql mysql -uroot -proot --default-character-set=utf8mb4 \
//     < bench/network/q41/00-setup.sql
//   docker exec learn-php php /app/bench/network/q41/01-injection.php
declare(strict_types=1);
require __DIR__ . '/db.php';

// ---------------------------------------------------------------- 两种实现
/** 有漏洞：用户输入直接拼进 SQL 字符串 */
function login_vuln(PDO $db, string $u, string $p): array
{
    $sql = "SELECT id, username, role FROM users WHERE username = '$u' AND password = '$p'";
    return [$sql, $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)];
}

/** 安全：占位符 + 服务端预处理，输入永远只是「值」，不参与语法解析 */
function login_safe(PDO $db, string $u, string $p): array
{
    $sql = 'SELECT id, username, role FROM users WHERE username = ? AND password = ?';
    $st  = $db->prepare($sql);
    $st->execute([$u, $p]);
    return [$sql, $st->fetchAll(PDO::FETCH_ASSOC)];
}

// ---------------------------------------------------------------- payload
// 注意最后那个空格：MySQL 的 '--' 注释必须跟一个空白才是注释，
// 网上抄的 payload 少了它就会报语法错。
$PAYLOADS = [
    '正常登录'            => ['alice', 'alice123'],
    '密码错（对照组）'    => ['alice', 'wrong'],
    '经典万能密码'        => ['admin', "' OR '1'='1' -- "],
    '用户名位注入'        => ["' OR 1=1 -- ", 'x'],
    '注释掉后半句'        => ["admin'-- ", 'whatever'],
    'UNION 拖信用卡表'    => ["' UNION SELECT id, card_no, bank FROM credit_cards -- ", 'x'],
    'UNION 拖密码哈希'    => ["' UNION SELECT id, username, password FROM users -- ", 'x'],
    '堆叠查询（多语句）'  => ["'; DROP TABLE users; -- ", 'x'],
];

$vdb = db_vuln();
$sdb = db_safe();

foreach ($PAYLOADS as $name => [$u, $p]) {
    echo str_repeat('=', 96), "\n";
    echo "payload: {$name}\n";
    echo "  输入 username = " . var_export($u, true) . "\n";
    echo "  输入 password = " . var_export($p, true) . "\n";

    // ---- 有漏洞的版本 ----
    try {
        [$sql, $rows] = login_vuln($vdb, $u, $p);
        echo "  [拼接] 实际执行的 SQL:\n    {$sql}\n";
        echo "  [拼接] 返回 " . count($rows) . " 行: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "  [拼接] 报错: " . $e->getMessage() . "\n";
    }

    // ---- 安全版本 ----
    try {
        [$sql, $rows] = login_safe($sdb, $u, $p);
        echo "  [预处理] 实际执行的 SQL:\n    {$sql}\n";
        echo "  [预处理] 返回 " . count($rows) . " 行: " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "  [预处理] 报错: " . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------- 表还在不在
echo str_repeat('=', 96), "\n";
echo "堆叠查询之后，users 表还在吗？\n";
try {
    $n = $vdb->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "  users 表仍在，行数 = {$n}\n";
} catch (Throwable $e) {
    echo "  users 表没了: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------- 版本信息
echo str_repeat('=', 96), "\n";
echo "环境\n";
echo "  PHP          : " . PHP_VERSION . "\n";
echo "  MySQL        : " . $vdb->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "  PDO 默认 EMULATE_PREPARES = " . var_export(
    (new PDO(Q41_DSN, Q41_USER, Q41_PASS))->getAttribute(PDO::ATTR_EMULATE_PREPARES),
    true
) . "  （mine: db_vuln 用默认，db_safe 关掉）\n";
