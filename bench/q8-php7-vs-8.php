<?php
/**
 * Q8 实测：PHP 7 / PHP 8 的行为差异
 * 刻意用 PHP 7.3 兼容语法（无箭头函数、无 match），以便同一份代码跑两个版本。
 *
 * 用法:
 *   docker run --rm --entrypoint php -v /opt/learn:/app \
 *     docker.m.daocloud.io/webdevops/php-nginx:7.3 /app/bench/q8-php7-vs-8.php
 *   docker exec learn-php php /app/bench/q8-php7-vs-8.php
 */

echo "PHP ", PHP_VERSION, "\n";
echo str_repeat('-', 58), "\n";

$cases = array(
    '0 == "a"'              => function () { return 0 == 'a'; },
    '100 == "100abc"'       => function () { return 100 == '100abc'; },
    'in_array(0, ["a","b"])' => function () { return in_array(0, array('a', 'b')); },
    '"0e1" == "0e2"'        => function () { return '0e1' == '0e2'; },
    'strlen(null)'          => function () { return @strlen(null); },
    '[] < [1] 比较'          => function () { return array() < array(1); },
    '1 < "2abc" 字符串偏移'   => function () { return @("abc"[1] == 'b'); },
);

foreach ($cases as $label => $fn) {
    $r = $fn();
    printf("%-24s => %s\n", $label, is_bool($r) ? ($r ? 'true' : 'false') : var_export($r, true));
}

echo "\n--- 语言特性探测 ---\n";
$features = array(
    'nullsafe 操作符 (?->)'  => version_compare(PHP_VERSION, '8.0', '>='),
    'match 表达式'           => version_compare(PHP_VERSION, '8.0', '>='),
    '命名参数'               => version_compare(PHP_VERSION, '8.0', '>='),
    '构造器属性提升'          => version_compare(PHP_VERSION, '8.0', '>='),
    'readonly 属性'          => version_compare(PHP_VERSION, '8.1', '>='),
    '枚举 enum'              => version_compare(PHP_VERSION, '8.1', '>='),
    '只读类 readonly class'  => version_compare(PHP_VERSION, '8.2', '>='),
    '类型化类常量'            => version_compare(PHP_VERSION, '8.3', '>='),
    '属性钩子 property hooks' => version_compare(PHP_VERSION, '8.4', '>='),
    '非对称可见性'            => version_compare(PHP_VERSION, '8.4', '>='),
);
foreach ($features as $name => $ok) {
    printf("  %-26s %s\n", $name, $ok ? '有' : '无');
}
