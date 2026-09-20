<?php
/**
 * Q8 附：常量折叠（constant folding）导致的「整个文件编译不过」
 *
 * `array() + 1` 两边都是常量表达式，PHP 7.x 的编译器在编译期就把它折叠求值，
 * 于是 Fatal error 发生在**编译阶段**：文件里连第一行 echo 都不会执行。
 * PHP 8.4 不再折叠，改成运行时抛 TypeError，所以输出 A / B 之后才报错。
 *
 * 用法:
 *   docker exec learn-php php /app/bench/php/q8-const-fold.php
 *   docker run --rm --entrypoint php -v /opt/learn:/app \
 *     docker.m.daocloud.io/webdevops/php-nginx:7.3 /app/bench/php/q8-const-fold.php
 */

echo "A: 这行能打出来，说明文件编译过了\n";

function f()
{
    return array() + 1;   // 没有变量参与 → 常量折叠的候选
}

echo "B: 函数定义完了，还没调用它\n";

f();
