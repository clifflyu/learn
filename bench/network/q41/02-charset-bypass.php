<?php
// Q41 附加实验：为什么「转义函数」不是可靠的防护 —— GBK 宽字节绕过。
//
// addslashes() / mysqli_real_escape_string() 的做法是「在危险字符前面加 \」。
// 但转义发生在【客户端】，而 SQL 的解析发生在【服务端】，中间隔着字符集。
// 当连接字符集是 GBK 这类双字节编码时，0xBF5C 会被服务端当成【一个】汉字，
// 于是 \%27 里的 0x5C 被吃掉，单引号 0x27 就裸奔出来了。
//
//   docker exec learn-php php /app/bench/network/q41/02-charset-bypass.php
declare(strict_types=1);
require __DIR__ . '/db.php';

echo "PHP      : " . PHP_VERSION . "\n";
echo "MySQL    : " . db_vuln()->query('SELECT VERSION()')->fetchColumn() . "\n\n";

// 攻击者提交的原始字节：0xBF 0x27  —— 0xBF27 在 GBK 里不是合法汉字，
// 转义后变成 0xBF 0x5C 0x27，而 0xBF5C 恰好是合法 GBK 汉字「縗」。
$payload = "\xbf\x27 OR 1=1 -- ";

echo "payload 原始字节 : " . bin2hex($payload) . "\n";
echo "addslashes 之后  : " . bin2hex(addslashes($payload)) . "\n";
echo "  —— 注意中间多出来的 5c：0xBF5C 会被 GBK 解析成一个汉字，\n";
echo "     紧跟其后的 0x27 就恢复成「真正的单引号」。\n\n";

// ---- 连接字符集 = gbk，并且用 addslashes 转义 ----
$db = new PDO(Q41_DSN, Q41_USER, Q41_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("SET NAMES gbk");
echo "连接字符集: " . $db->query('SELECT @@character_set_client')->fetchColumn() . "\n";

$u = addslashes($payload);
$sql = "SELECT id, username, role FROM users WHERE username = '$u'";
echo "SQL: {$sql}\n";
echo "SQL 十六进制: " . bin2hex($sql) . "\n";
try {
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo "结果: " . count($rows) . " 行 " . json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
    echo count($rows) > 1 ? "  ⇒ 转义被绕过，注入成功\n" : "  ⇒ 没绕过\n";
} catch (Throwable $e) {
    echo "报错（说明没绕过）: " . $e->getMessage() . "\n";
}

// ---- 同样 payload，走服务端预处理 ----
echo "\n同一个 payload 走 PDO 预处理（EMULATE_PREPARES=false）：\n";
$sdb = db_safe();
$st  = $sdb->prepare('SELECT id, username, role FROM users WHERE username = ?');
$st->execute([$payload]);
echo "结果: " . count($st->fetchAll()) . " 行  ⇒ 字节原样当值比较，语法不受影响\n";

// ---- 对照组：字符集正确时 addslashes 确实挡住了 ----
echo "\n对照组：同一个 addslashes 写法，但连接字符集是 utf8mb4：\n";
$db2 = new PDO(Q41_DSN, Q41_USER, Q41_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db2->exec("SET NAMES utf8mb4");
$u2  = addslashes($payload);
$sql2 = "SELECT id, username, role FROM users WHERE username = '$u2'";
try {
    $rows = $db2->query($sql2)->fetchAll(PDO::FETCH_ASSOC);
    echo "结果: " . count($rows) . " 行  ⇒ utf8mb4 下 0xBF 是非法字节，绕不过去\n";
} catch (Throwable $e) {
    echo "报错（说明没绕过）: " . $e->getMessage() . "\n";
}
