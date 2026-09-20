<?php
// Q41 XSS 演示页（反射型 + 存储型），可切换「直接输出」和「转义输出」。
//
//   http://localhost:9311/bench/network/q41/xss-demo.php          默认：不安全
//   http://localhost:9311/bench/network/q41/xss-demo.php?safe=1   转义输出
//
// 两种 payload 都试：
//   ?q=<script>alert(document.cookie)</script>
//   ?q=<img src=x onerror="document.title='XSS'">
declare(strict_types=1);

$SAFE = isset($_GET['safe']) && $_GET['safe'] === '1';
$STORE = '/tmp/q41-comments.json';

function out(string $s, bool $safe): string
{
    // ENT_QUOTES 连单引号一起转，因为属性值常用单引号包；
    // ENT_SUBSTITUTE 让非法 UTF-8 字节变成 U+FFFD 而不是返回空串。
    return $safe ? htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $s;
}

// ---- 存储型：留言写进文件，下次访问原样吐出来 ----
$comments = is_file($STORE) ? (json_decode((string)file_get_contents($STORE), true) ?: []) : [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['comment'])) {
    $comments[] = (string)$_POST['comment'];
    file_put_contents($STORE, json_encode($comments, JSON_UNESCAPED_UNICODE));
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
if (isset($_GET['clear'])) {
    @unlink($STORE);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$q = (string)($_GET['q'] ?? '');

// 演示 HttpOnly 的作用：这个 cookie 在 JS 里读不到
setcookie('q41_session', 'SECRET-SESSION-ID-abc123', [
    'httponly' => true,
    'samesite' => 'Lax',
    'path'     => '/',
]);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="zh">
<head><meta charset="utf-8"><title>Q41 XSS 演示</title></head>
<body>
<h1>Q41 XSS 演示</h1>
<p>当前模式：<b><?= $SAFE ? '转义输出（安全）' : '原样输出（有漏洞）' ?></b>
   ｜ <a href="?safe=<?= $SAFE ? '0' : '1' ?>">切换</a>
   ｜ <a href="?clear=1&amp;safe=<?= $SAFE ? '1' : '0' ?>">清空留言</a></p>

<h2>1. 反射型</h2>
<form method="get">
  <input type="hidden" name="safe" value="<?= $SAFE ? '1' : '0' ?>">
  <input name="q" size="60" value="<?= out($q, true) ?>" placeholder="试试 &lt;script&gt;alert(1)&lt;/script&gt;">
  <button>提交</button>
</form>
<p>服务端回显（下面是原始 HTML 片段）：</p>
<div id="reflect">你搜索的是：<?= out($q, $SAFE) ?></div>
<pre><?= out('你搜索的是：' . $q, true) ?></pre>

<h2>2. 存储型</h2>
<form method="post">
  <input name="comment" size="60" placeholder="留言内容">
  <button>发表</button>
</form>
<ul>
<?php foreach ($comments as $c): ?>
  <li><?= out($c, $SAFE) ?></li>
<?php endforeach; ?>
</ul>

<h2>3. 这个页面能读到的 cookie</h2>
<pre>document.cookie = <span id="ck">（由 JS 填入）</span></pre>
<p>q41_session 设了 HttpOnly，所以 JS 读不到它 —— 但页面里被注入的脚本
   仍然可以发请求、改 DOM、偷其他内容。</p>

<script>
document.getElementById('ck').textContent = JSON.stringify(document.cookie);
</script>
</body>
</html>
