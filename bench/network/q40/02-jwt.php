<?php
// Q40 JWT 实测：自己实现 HS256 的签/验，然后逐个打穿常见误解。
//
//   docker exec learn-php php /app/bench/network/q40/02-jwt.php
declare(strict_types=1);
require __DIR__ . '/02-jwt-lib.php';   // 函数实现抽到 lib 里，两个文件共用

$now = time();
$claims = [
    'sub'   => 'alice',
    'role'  => 'user',
    'iat'   => $now,
    'exp'   => $now + 3600,
];

echo "PHP: " . PHP_VERSION . "\n";
echo str_repeat('=', 88), "\n";

// ---------------------------------------------------------------- 1
echo "1. 签发一个正常的 JWT\n";
$jwt = jwt_sign($claims, Q40_SECRET);
echo "   {$jwt}\n";
echo "   总长度: " . strlen($jwt) . " 字节\n";
echo "   三段的长度: " . implode(' / ', array_map('strlen', explode('.', $jwt))) . "\n";
[$ok, $pl, $why] = jwt_verify_strict($jwt, Q40_SECRET);
echo "   严格验签: " . var_export($ok, true) . "  ({$why})\n";

// ---------------------------------------------------------------- 2
echo str_repeat('-', 88), "\n";
echo "2. payload 是 base64，不是加密 —— 不需要密钥就能读出来\n";
$mid = explode('.', $jwt)[1];
$pad = str_repeat('=', (4 - strlen($mid) % 4) % 4);
echo "   中间段原文     : {$mid}\n";
echo "   base64 解出来  : " . base64_decode(strtr($mid, '-_', '+/') . $pad) . "\n";
echo "   ↑ 所以 JWT 里绝不能放密码、手机号、身份证这类东西\n";

// ---------------------------------------------------------------- 3
echo str_repeat('-', 88), "\n";
echo "3. 改 payload 会被抓到吗？（密钥没泄漏的前提下）\n";
$forged_payload = b64u(json_encode(['sub' => 'alice', 'role' => 'admin', 'exp' => $now + 3600], JSON_UNESCAPED_SLASHES));
$h = explode('.', $jwt)[0];
$bad = "$h.$forged_payload." . explode('.', $jwt)[2];
[$ok, , $why] = jwt_verify_strict($bad, Q40_SECRET);
echo "   伪造的 token: {$bad}\n";
echo "   严格验签: " . var_export($ok, true) . "  ({$why})\n";
echo "   ↑ 签名对不上，篡改被抓。这是 JWT 唯一的、也是真实的保证：\n";
echo "     「内容没被改过」，不是「内容是对的」，更不是「这个 token 还有效」\n";

// ---------------------------------------------------------------- 4
echo str_repeat('-', 88), "\n";
echo "4. alg=none 攻击：把算法换成 none，签名留空\n";
$hdr_none = b64u(json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
$pl_admin = b64u(json_encode(['sub' => 'alice', 'role' => 'admin', 'exp' => $now + 3600], JSON_UNESCAPED_SLASHES));
$none_token = "$hdr_none.$pl_admin.";
echo "   伪造 token: {$none_token}\n";
[$ok, $payload, $why] = jwt_verify_naive($none_token, Q40_SECRET);
echo "   天真校验器（信 header 里的 alg）: " . var_export($ok, true) . "  ({$why}) => role=" . ($payload['role'] ?? '?') . "\n";
[$ok2, , $why2] = jwt_verify_strict($none_token, Q40_SECRET);
echo "   严格校验器（算法白名单）      : " . var_export($ok2, true) . "  ({$why2})\n";

// ---------------------------------------------------------------- 5
echo str_repeat('-', 88), "\n";
echo "5. 无法主动失效 —— JWT 最大的坑\n";
// 模拟一个「服务端只说一次、之后不再参与」的签发流程
$token = jwt_sign(['sub' => 'alice', 'jti' => bin2hex(random_bytes(8)), 'exp' => $now + 3600], Q40_SECRET);
echo "   签发: " . substr($token, 0, 48) . "...\n";
echo "   用户点了「退出登录」，服务端做了什么？\n";
echo "     - 如果服务端【不存任何状态】，它什么也做不了\n";
echo "     - 浏览器那边删掉 localStorage 里的 token，只是「客户端不再发」\n";
echo "     - 攻击者手里那份拷贝，照样能用到 exp 到期\n";
$stolen = $token;                       // 攻击者早就拷走了
echo "   攻击者用同一个 token 访问：\n";
[$ok, $pl, $why] = jwt_verify_strict($stolen, Q40_SECRET);
echo "     验签 " . var_export($ok, true) . "，payload sub={$pl['sub']}, exp-now=" . ($pl['exp'] - $now) . "s\n";
echo "     => 服务端没有任何理由拒绝它，因为[服务端根本没有它的记录]\n";
echo "\n   要想真的能吊销，只能把状态加回来，于是：\n";
echo "     - 维护黑名单（jti 列表）  -> 每请求查一次存储，退化成 session\n";
echo "     - 版本号 / 短过期 + 刷新令牌 -> 刷新令牌本身又必须是有状态的\n";

// ---------------------------------------------------------------- 6
echo str_repeat('-', 88), "\n";
echo "6. 和 Session 的体积对比\n";
$sid = bin2hex(random_bytes(16));
$a = "Cookie: PHPSESSID={$sid}";
$b = "Authorization: Bearer {$token}";
printf("   session 侧: %3d 字节  (%s)\n", strlen($a), $a);
printf("   JWT 侧    : %3d 字节  (%s)\n", strlen($b), $b);
printf("   差 %d 字节，每个请求都要带上；JWT 的 payload 越大差距越大\n", strlen($b) - strlen($a));
printf("   JWT 签名段固定 %d 字节，payload 段 %d 字节\n",
  strlen(explode('.', $token)[2]), strlen(explode('.', $token)[1]));
