<?php
// Q40 JWT 的最小实现，供 02-jwt.php（CLI 实验）和 jwt-vs-session.php（HTTP 演示）共用。
// 故意不引第三方库：就是要看清 HS256 到底做了什么。
declare(strict_types=1);

const Q40_SECRET = 'q40-demo-secret';

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64u_decode(string $s): string
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** 签发：HS256 */
function jwt_sign(array $payload, string $secret, string $alg = 'HS256'): string
{
    $h = b64u(json_encode(['alg' => $alg, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $p = b64u(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64u(hash_hmac('sha256', "$h.$p", $secret, true));
    return "$h.$p.$sig";
}

/** 天真校验：信 header 里的 alg —— 这是最经典的 JWT 漏洞 */
function jwt_verify_naive(string $jwt, string $secret): array
{
    [$h, $p, $s] = explode('.', $jwt) + ['', '', ''];
    $header = json_decode(b64u_decode($h), true);
    $alg = $header['alg'] ?? 'HS256';
    if ($alg === 'none') {
        return [true, json_decode(b64u_decode($p), true), 'alg=none，直接放行'];
    }
    $expect = b64u(hash_hmac('sha256', "$h.$p", $secret, true));
    return [hash_equals($expect, $s), json_decode(b64u_decode($p), true), "按 $alg 验签"];
}

/** 正确校验：算法写死在代码里，header 里的 alg 只用来比对，不用来决策 */
function jwt_verify_strict(string $jwt, string $secret): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return [false, null, '不是三段式'];
    }
    [$h, $p, $s] = $parts;
    $header = json_decode(b64u_decode($h), true) ?: [];
    if (($header['alg'] ?? '') !== 'HS256') {   // ← 白名单，不是「读什么用什么」
        return [false, null, 'alg 不在白名单'];
    }
    $expect = b64u(hash_hmac('sha256', "$h.$p", $secret, true));
    return [hash_equals($expect, $s), json_decode(b64u_decode($p), true), '严格验签'];
}

