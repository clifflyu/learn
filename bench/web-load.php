<?php
/**
 * Q3 实测（Web 路径）：一次请求加载 500 个类文件
 * 通过 nginx + php-fpm 访问，才是 OPcache 的真实工作场景。
 *
 * 用法: curl 'http://localhost:9311/bench/web-load.php'
 */

header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__ . '/_gen';
require __DIR__ . '/_fixtures.php';
ensure_fixtures($dir);

$t = microtime(true);
for ($i = 1; $i <= 500; $i++) {
    require "$dir/c$i.php";
}
$el = microtime(true) - $t;

$st = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;

printf("加载 500 文件 %.4f s   opcache=%s   cached_scripts=%d   本次脚本命中=%s\n",
    $el,
    $st && ($st['opcache_enabled'] ?? false) ? 'on' : 'off',
    $st['opcache_statistics']['num_cached_scripts'] ?? -1,
    isset($st['scripts'][__FILE__]) ? 'yes' : 'no'
);
