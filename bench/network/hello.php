<?php
// Q38 实测用的最小 PHP 端点：只输出固定字符串，不含任何业务逻辑，
// 让 TTFB 的差异全部来自「静态文件 vs 走 FastCGI」这一条。
header('Content-Type: text/plain');
echo "hello from php-fpm\n";
