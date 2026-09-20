<?php
/**
 * Q7 附：不带环检测的容器去解循环依赖，会发生什么
 *
 * 独立成文件是为了能在两个版本上跑（脚本自己抓不了「另一个 PHP 版本」的行为）：
 *   docker exec learn-php php /app/bench/php/q7-naive-recursion.php
 *   docker run --rm --entrypoint php -v /opt/learn:/app \
 *     docker.m.daocloud.io/webdevops/php-nginx:7.3 -d memory_limit=64M \
 *     /app/bench/php/q7-naive-recursion.php
 *
 * 会以 Fatal error 结束，这是预期的。
 */

echo "PHP ", PHP_VERSION, "  memory_limit=", ini_get('memory_limit'), "\n";
echo "栈上限相关 ini: zend.max_allowed_stack_size=", var_export(ini_get('zend.max_allowed_stack_size'), true), "\n";

class A { public function __construct(B $b) {} }
class B { public function __construct(A $a) {} }

$depth = 0;
register_shutdown_function(function () use (&$depth) {
    fwrite(STDERR, "崩之前递归到了第 $depth 层\n");
});

function naive($class)
{
    global $depth;
    $depth++;
    $rc = new ReflectionClass($class);
    $args = array();
    foreach ($rc->getConstructor()->getParameters() as $p) {
        $args[] = naive($p->getType()->getName());
    }
    return $rc->newInstanceArgs($args);
}

naive('A');
