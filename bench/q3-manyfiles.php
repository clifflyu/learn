<?php
/**
 * Q3 实测：OPcache 在「一次请求加载大量文件」场景下的收益
 * 模拟一个中型框架：500 个类文件 × 60 个方法（单文件约 485 行，合计 24 万行）。
 *
 * 用法:
 *   # 关 OPcache
 *   php -d opcache.enable_cli=0 q3-manyfiles.php
 *   # 开 OPcache（连跑两次，第二次是热缓存）
 *   php -d opcache.enable_cli=1 -d opcache.memory_consumption=512 \
 *       -d opcache.max_accelerated_files=10000 q3-manyfiles.php
 */

$dir = sys_get_temp_dir() . '/q3gen';
require __DIR__ . '/_fixtures.php';
ensure_fixtures($dir);

$st = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$stat = $st['opcache_statistics'] ?? [];
$before = $stat['num_cached_scripts'] ?? 0;

$t = microtime(true);
for ($i = 1; $i <= 500; $i++) {
    require "$dir/c$i.php";
}
$el = microtime(true) - $t;

$st = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$stat = $st['opcache_statistics'] ?? [];
$mem = $st['memory_usage'] ?? [];

printf(
    "耗时 %.3f s   opcache=%s   缓存脚本 %d→%d   oom重启=%d   缓存占用 %.1f MB\n",
    $el,
    $st && ($st['opcache_enabled'] ?? false) ? 'on' : 'off',
    $before,
    $stat['num_cached_scripts'] ?? -1,
    $stat['oom_restarts'] ?? -1,
    ($mem['used_memory'] ?? 0) / 1048576
);

// 确认类真的可用
$o = new C500();
$r = $o->m59(1, 'x');
printf("校验: C500->m59(1,'x') = %s\n", json_encode($r));
