<?php
/**
 * Q6 附：抽象类 / 接口 / Trait 的能力与报错文案，PHP 7.3 vs PHP 8.4 差分
 *
 * 只放 7.3 也认得的语法，同一份代码两个版本都能跑。
 * 每条都在子进程里跑，抓 PHP 原文报错。
 *
 * 用法:
 *   docker run --rm --entrypoint php -v /opt/learn:/app \
 *     docker.m.daocloud.io/webdevops/php-nginx:7.3 /app/bench/php/q6-oop-73-vs-84.php
 *   docker exec learn-php php /app/bench/php/q6-oop-73-vs-84.php
 */

error_reporting(E_ALL);

function probe($label, $code)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r '
         . escapeshellarg($code . '; echo "|OK";') . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
    $line = '';
    foreach (explode("\n", $out) as $l) {
        if (preg_match('/(Fatal error|Parse error|Warning|Deprecated)/', $l)) { $line = $l; break; }
    }
    if ($line === '') { $line = $out === '' ? '(无输出)' : $out; }
    $line = preg_replace('/^PHP /', '', $line);
    $line = preg_replace('/ in \/.*? on line \d+$/', '', $line);
    $line = preg_replace('/ in Command line code:?\d*$/', '', $line);
    $line = preg_replace('/\s+/', ' ', $line);
    printf("%-30s| %s\n", $label, $line);
}

echo "PHP|", PHP_VERSION, "\n";
echo "特性探测\n";
probe('接口属性',         'interface I { public $x; }');
probe('接口静态属性',     'interface I { public static $x; }');
probe('接口方法带方法体', 'interface I { public function f() { return 1; } }');
probe('接口方法 protected', 'interface I { protected function f(); }');
probe('抽象类 private abstract', 'abstract class A { abstract private function f(); }');
probe('new 接口',         'interface I { public function f(); } new I();');
probe('new trait',        'trait T { public function f() {} } new T();');
probe('new 抽象类',       'abstract class A { abstract public function f(); } new A();');
probe('继承两个类',       'class A {} class B {} class C extends A, B {}');
probe('接口多继承',       'interface I1 {} interface I2 {} interface I3 extends I1, I2 {}');
probe('实现类覆盖接口常量', 'interface I { const V = 1; } class C implements I { const V = 2; } echo C::V;');

echo "trait 能力\n";
probe('trait 常量',       'trait T { const C = 1; } class C { use T; } echo C::C;');
probe('trait 抽象方法',   'trait T { abstract public function f(); } class C { use T; public function f() {} } echo "ok";');
probe('trait 静态方法',   'trait T { public static function f() { return "s"; } } class C { use T; } echo C::f();');
probe('trait 属性',       'trait T { public $x = 7; } class C { use T; } echo (new C)->x;');
probe('trait 构造函数',   'trait T { public function __construct() { echo "ctor"; } } class C { use T; } new C;');
probe('trait 常量同名同值', 'trait T1 { const C = 1; } trait T2 { const C = 1; } class C { use T1, T2; } echo "ok";');

echo "trait 冲突报错\n";
probe('方法同名冲突',     'trait T1 { public function f() { return 1; } } trait T2 { public function f() { return 2; } } class C { use T1, T2; }');
probe('属性同名同定义',   'trait T1 { public $x = 1; } trait T2 { public $x = 1; } class C { use T1, T2; } echo "ok";');
probe('属性同名不同默认值', 'trait T1 { public $x = 1; } trait T2 { public $x = 2; } class C { use T1, T2; }');
probe('属性同名不同可见性', 'trait T1 { public $x = 1; } trait T2 { protected $x = 1; } class C { use T1, T2; }');
probe('类自身方法覆盖 trait', 'trait T { public function f() { return 1; } } class C { use T; public function f() { return 2; } } echo (new C)->f();');
probe('抽象方法未实现',   'trait T { abstract public function f(); } class C { use T; }');
probe('insteadof 解决冲突', 'trait T1 { public function f() { return "T1"; } } trait T2 { public function f() { return "T2"; } } class C { use T1, T2 { T1::f insteadof T2; } } echo (new C)->f();');
probe('as 改可见性',      'trait T { public function f() { return 1; } } class C { use T { f as protected g; } } echo "ok";');
