<?php
// Q40 「退出登录」在 Session 和 JWT 下分别意味着什么 —— 可跑的 HTTP 演示。
//
//   ?act=login-session   建会话
//   ?act=me-session      带 cookie 访问受保护资源
//   ?act=logout-session  session_destroy()
//   ?act=login-jwt       签发 JWT，返回 token（不落任何服务端状态）
//   ?act=me-jwt&token=…  用 Bearer token 访问受保护资源
//   ?act=logout-jwt      「退出登录」：客户端丢掉 token，服务端什么也不做
declare(strict_types=1);
require __DIR__ . '/02-jwt-lib.php';

session_name('Q40SESS');
session_start();
header('Content-Type: application/json; charset=utf-8');

$act   = $_GET['act'] ?? '';
$token = $_GET['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$token = preg_replace('/^Bearer\s+/i', '', (string)$token);
$out   = ['act' => $act];

switch ($act) {
    case 'login-session':
        $_SESSION['user'] = 'alice';
        $out += ['result' => '登录成功', '服务端状态' => 'sess_' . session_id() . ' 已写入存储'];
        break;

    case 'me-session':
        $u = $_SESSION['user'] ?? null;
        $out += $u
            ? ['result' => '200 已认证', 'user' => $u, '依据' => '服务端存储里还有这个 session']
            : ['result' => '401 未认证', '依据' => '服务端存储里已经没有这个 session 了'];
        break;

    case 'logout-session':
        session_destroy();
        $out += ['result' => '已退出', '服务端做了什么' => '把 session 文件/记录删掉了'];
        break;

    case 'login-jwt':
        $t = jwt_sign(['sub' => 'alice', 'iat' => time(), 'exp' => time() + 3600], Q40_SECRET);
        $out += [
            'result'  => '签发成功',
            'token'   => $t,
            '服务端做了什么' => '什么都没存。token 自带全部信息',
        ];
        break;

    case 'me-jwt':
        [$ok, $payload, $why] = jwt_verify_strict($token, Q40_SECRET);
        if (!$ok) {
            $out += ['result' => '401 验签失败', 'why' => $why];
        } elseif (($payload['exp'] ?? 0) < time()) {
            $out += ['result' => '401 已过期'];
        } else {
            $out += [
                'result' => '200 已认证',
                'user'   => $payload['sub'],
                '依据'   => '签名有效且没到期。服务端不知道这个 token 是否「被登出」过',
            ];
        }
        break;

    case 'logout-jwt':
        $out += [
            'result' => '浏览器那边把 token 删了',
            '服务端做了什么' => '什么都没做。刚才那个 token 现在拿去用，依然 200',
        ];
        break;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
