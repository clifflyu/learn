<?php
// Q41 CSRF 演示：一个「银行」页面。
//
// 三种模式，用 ?mode= 切换：
//   mode=none    完全无防护 —— 跨站 POST 直接生效
//   mode=origin  校验 Origin/Referer
//   mode=token   校验 CSRF token（同步令牌模式）
//
// 配套的攻击者页面：csrf-evil.php
declare(strict_types=1);

$MODE = $_GET['mode'] ?? 'none';
session_start();

$BALANCE_FILE = '/tmp/q41-bank-balance.txt';
$LOG_FILE     = '/tmp/q41-bank-log.txt';

if (!is_file($BALANCE_FILE)) {
    file_put_contents($BALANCE_FILE, '10000');
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = 'alice';       // 演示用：自动登录
}

$msg = '';

// ------------------------------------------------------------ 转账处理
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['to'])) {
    $to     = (string)$_POST['to'];
    $amount = (int)($_POST['amount'] ?? 0);
    $origin = $_SERVER['HTTP_ORIGIN']  ?? '(无)';
    $refer  = $_SERVER['HTTP_REFERER'] ?? '(无)';
    $token  = (string)($_POST['csrf_token'] ?? '(无)');

    $allowed = true;
    $why = '无任何校验';

    if ($MODE === 'origin') {
        // 只认本站 Origin；没有 Origin 头时退回看 Referer 的 host
        $host = $_SERVER['HTTP_HOST'];
        $okO  = $origin !== '(无)' && parse_url($origin, PHP_URL_HOST) === explode(':', $host)[0];
        $okR  = $refer !== '(无)' && parse_url($refer, PHP_URL_HOST) === explode(':', $host)[0];
        $allowed = $okO || $okR;
        $why = $allowed ? 'Origin/Referer 属于本站' : 'Origin/Referer 不是本站 → 拒绝';
    } elseif ($MODE === 'token') {
        $allowed = hash_equals((string)$_SESSION['csrf_token'], $token);
        $why = $allowed ? 'token 匹配' : 'token 不匹配 → 拒绝';
    }

    $line = sprintf(
        "%s mode=%s to=%s amount=%d Origin=%s Referer=%s token=%s => %s（%s）\n",
        date('H:i:s'), $MODE, $to, $amount, $origin, $refer, substr($token, 0, 16),
        $allowed ? '已转账' : '已拒绝', $why
    );
    file_put_contents($LOG_FILE, $line, FILE_APPEND);

    if ($allowed) {
        $bal = (int)file_get_contents($BALANCE_FILE);
        file_put_contents($BALANCE_FILE, (string)($bal - $amount));
        $msg = "转账 {$amount} 给 {$to} 成功（{$why}）";
    } else {
        $msg = "转账被拒绝：{$why}";
    }
}

if (isset($_GET['reset'])) {
    file_put_contents($BALANCE_FILE, '10000');
    @unlink($LOG_FILE);
    header('Location: ?mode=' . urlencode($MODE));
    exit;
}

$balance = (int)file_get_contents($BALANCE_FILE);
$log     = is_file($LOG_FILE) ? file_get_contents($LOG_FILE) : '';

// flash 只显示一次
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="zh">
<head><meta charset="utf-8"><title>Q41 CSRF 演示 · 银行</title></head>
<body>
<h1>Q41 银行（受攻击目标）</h1>
<p>模式：<b><?= htmlspecialchars($MODE) ?></b> ｜ 切换：
  <a href="?mode=none">无防护</a> ·
  <a href="?mode=origin">Origin 校验</a> ·
  <a href="?mode=token">CSRF token</a>
  ｜ <a href="?reset=1&amp;mode=<?= urlencode($MODE) ?>">重置余额</a></p>

<p>当前登录用户：<b><?= htmlspecialchars((string)$_SESSION['user']) ?></b>
   ｜ 余额：<b id="balance"><?= $balance ?></b></p>

<?php if ($msg !== ''): ?><p style="color:#b00"><b><?= htmlspecialchars($msg) ?></b></p><?php endif; ?>

<h2>转账</h2>
<form method="post" action="?mode=<?= htmlspecialchars($MODE) ?>">
  <input name="to" value="attacker" size="12">
  <input name="amount" value="9999" size="8">
  <?php if ($MODE === 'token'): ?>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
  <?php endif; ?>
  <button>转账</button>
</form>

<h2>这个页面的会话 cookie</h2>
<pre>Set-Cookie: PHPSESSID=...; HttpOnly; SameSite=<?= htmlspecialchars(ini_get('session.cookie_samesite') ?: '(未设置)') ?></pre>
<pre>session.cookie_samesite = <?= htmlspecialchars(var_export(ini_get('session.cookie_samesite'), true)) ?></pre>

<h2>攻击者页面</h2>
<p><a href="csrf-evil.php?mode=<?= htmlspecialchars($MODE) ?>">打开 csrf-evil.php</a>（模拟受害者访问攻击者的站点）</p>

<h2>服务端收到的 POST 流水</h2>
<pre><?= htmlspecialchars($log) ?></pre>
</body>
</html>
