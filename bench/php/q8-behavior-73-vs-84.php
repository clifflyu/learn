<?php
/**
 * Q8 实测：PHP 7.3 → 8.4 的行为差异（升级会炸的那些）
 *
 * 只用 7.3 也认得的语法，同一份代码跑三个版本：
 *   docker run --rm --entrypoint php -v /opt/learn:/app \
 *     docker.m.daocloud.io/webdevops/php-nginx:7.3 /app/bench/php/q8-behavior-73-vs-84.php
 *   docker run --rm --entrypoint php -v /opt/learn:/app php:8.2-cli /app/bench/php/q8-behavior-73-vs-84.php
 *   docker exec learn-php php /app/bench/php/q8-behavior-73-vs-84.php
 *
 * 错误不打印出来，用错误处理器收集，只输出「错误类别: 文案」一行，方便三个版本对齐。
 */

echo "PHP|", PHP_VERSION, "\n";

$errors = array();
set_error_handler(function ($no, $str) use (&$errors) {
    $names = array(
        E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_NOTICE => 'E_NOTICE',
        E_DEPRECATED => 'E_DEPRECATED', E_USER_WARNING => 'E_USER_WARNING',
    );
    $errors[] = (isset($names[$no]) ? $names[$no] : ('E_' . $no)) . ': ' . $str;
    return true;   // 吞掉，不打印
});

/** 跑一段代码，返回 "结果 | 错误" 一行 */
function t($label, $fn)
{
    global $errors;
    $errors = array();
    try {
        $r = $fn();
        $res = is_bool($r) ? ($r ? 'true' : 'false') : (is_null($r) ? 'null' : str_replace("\n", ' ', var_export($r, true)));
    } catch (Throwable $e) {
        $res = get_class($e) . '[' . $e->getMessage() . ']';
    }
    $err = implode(' / ', array_slice($errors, 0, 2));
    printf("%-28s| %-46s| %s\n", $label, $res, $err);
}

echo str_repeat('-', 130), "\n";

echo "== 算术与类型杂耍 ==\n";
t('1 / 0',              function () { return 1 / 0; });
t('1 % 0',              function () { return 1 % 0; });
t('intdiv(1, 0)',       function () { return intdiv(1, 0); });
t('0 / 0',              function () { return 0 / 0; });
t('"abc" + 1',          function () { return "abc" + 1; });
t('"5 apples" + 1',     function () { return "5 apples" + 1; });
t('"5" + 1',            function () { return "5" + 1; });
// 注意：不能在这里直接写 array() + 1 —— 7.3 下它是「编译期常量折叠」出来的 Fatal error，
// 整个文件连编译都过不去（连 PHP_VERSION_ID 那行都不会执行）。挪到文末的子进程探针里测。

echo "\n== 参数与类型检查（内部函数变严格）==\n";
t('strlen(null)',       function () { return strlen(null); });
t('strlen([])',         function () { return strlen(array()); });
t('strlen("a","b")',    function () { return strlen('a', 'b'); });
t('count(null)',        function () { return count(null); });
t('count(1)',           function () { return count(1); });
t('array_key_exists(o)', function () { $o = new stdClass(); $o->a = 1; return array_key_exists('a', $o); });
t('htmlspecialchars(null)', function () { return htmlspecialchars(null); });
t('(int)"1e3"',         function () { return (int) '1e3'; });

echo "\n== 数组键与类型转换 ==\n";
t('array[1.7] 键',      function () { $a = array(); $a[1.7] = 'x'; return array_keys($a); });
t('array[true] 键',     function () { $a = array(); $a[true] = 'x'; return array_keys($a); });
t('array[null] 键',     function () { $a = array(); $a[null] = 'x'; return array_keys($a); });

echo "\n== 字符串偏移 ==\n";
t('"abc"[-1]',          function () { return "abc"[-1]; });
t('"abc"[5]',           function () { return "abc"[5]; });
t('"abc"{0} (eval)',    function () { return eval('return "abc"{0};'); });

echo "\n== 未定义变量 / 数组键 ==\n";
t('未定义变量',          function () { return $undefinedVar; });
t('未定义数组键',        function () { $a = array(); return $a['nope']; });
t('未定义常量',          function () { return UNDEFINED_CONST; });
t('null 上取属性',       function () { $x = null; return $x->foo; });

echo "\n== 已移除的函数 / 语法 ==\n";
t('create_function',    function () { $f = create_function('$a', 'return $a;'); return $f(1); });
t('each()',             function () { $a = array(1, 2); return each($a); });
t('implode(数组, 分隔符)',  function () { return implode(array('a', 'b'), '-'); });
t('money_format',       function () { return function_exists('money_format') ? 'exists' : 'gone'; });
t('get_magic_quotes_gpc', function () { return get_magic_quotes_gpc(); });

echo "\n== 排序稳定性 ==\n";
t('sort 稳定性',        function () {
    // 按第 2 个字段排序，键相同的那几组看相对顺序有没有被打乱
    $rows = array(
        array('a', 1), array('b', 2), array('c', 1), array('d', 2), array('e', 1),
        array('f', 2), array('g', 1), array('h', 2), array('i', 1), array('j', 2),
        array('k', 1), array('l', 2), array('m', 1), array('n', 2), array('o', 1),
        array('p', 2), array('q', 1), array('r', 2), array('s', 1), array('t', 2),
    );
    usort($rows, function ($x, $y) { return $x[1] - $y[1]; });
    $first = '';
    foreach ($rows as $row) { $first .= $row[0]; }
    return $first;
});

echo "\n== 新函数（8.0 才有）==\n";
t('str_contains',       function () { return function_exists('str_contains') ? str_contains('abc', 'b') : 'missing'; });
t('str_starts_with',    function () { return function_exists('str_starts_with') ? str_starts_with('abc', 'a') : 'missing'; });
t('array_is_list',      function () { return function_exists('array_is_list') ? array_is_list(array(1, 2)) : 'missing'; });
t('enum_exists',        function () { return function_exists('enum_exists') ? 'yes' : 'no'; });

echo "\n== 语法能力探测（eval 探测，能过就是支持）==\n";
$syntax = array(
    '箭头函数 fn()'        => 'return (fn($x) => $x + 1)(1);',
    'nullsafe ?->'         => '$a = null; return $a?->foo;',
    'match 表达式'         => 'return match(1) { 1 => "one", default => "other" };',
    '命名参数'             => 'return strlen(string: "abc");',
    '构造器属性提升'       => 'class C1 { public function __construct(public int $x) {} } return (new C1(1))->x;',
    'readonly 属性'        => 'class C2 { public readonly int $x; public function __construct() { $this->x = 1; } } return (new C2)->x;',
    '枚举 enum'            => 'enum E1: string { case A = "a"; } return E1::A->value;',
    '类型化类常量'         => 'class C3 { public const string S = "s"; } return C3::S;',
    '属性钩子 property hook' => 'class C4 { public int $x = 1 { get => $this->x * 2; } } return (new C4)->x;',
    'new 不带括号调用'     => 'class C5 { public function f() { return 1; } } return new C5()->f();',
    'readonly class'       => 'readonly class C6 { public function __construct(public int $x) {} } return (new C6(1))->x;',
    '非对称可见性 private(set)' => 'class C7 { public function __construct(private(set) int $x = 1) {} public function get() { return $this->x; } } return (new C7)->get();',
);
foreach ($syntax as $name => $code) {
    $errors = array();
    try {
        $r = eval($code);
        printf("%-28s| %s\n", $name, '支持 → ' . var_export($r, true));
    } catch (Throwable $e) {
        printf("%-28s| %s\n", $name, '不支持 → ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* 不可捕获的 Fatal error：只能丢子进程                                  */
/* ------------------------------------------------------------------ */

echo "\n== 不可 catch 的 Fatal（子进程探针）==\n";

function probe($label, $code)
{
    // 用变量绕开编译期常量折叠，否则整个文件都编译不过
    $out = trim((string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r '
        . escapeshellarg($code . '; echo "|OK";') . ' 2>&1'));
    $line = '';
    foreach (explode("\n", $out) as $l) {
        if (preg_match('/(Fatal error|Parse error|Warning|Deprecated)/', $l)) { $line = $l; break; }
    }
    if ($line === '') { $line = $out === '' ? '(无输出)' : $out; }
    $line = preg_replace('/^PHP /', '', $line);
    $line = preg_replace('/ in \/.*? on line \d+$/', '', $line);
    $line = preg_replace('/ in Command line code:?\d*$/', '', $line);
    printf("%-28s| %s\n", $label, preg_replace('/\s+/', ' ', $line));
}

probe('隐式可空参数',     'function f(string $s = null) { return $s; } echo f();');
probe('E_STRICT 常量',    '$e = E_STRICT; echo $e;');
probe('数组 + 整数',      '$a = array(); return $a + 1;');
probe('对象 + 整数',      '$o = new stdClass(); return $o + 1;');
probe('对象当数组用',     '$o = new stdClass(); return $o["k"];');
probe('字符串 + 数组',    '$s = "a"; $a = array(); return $s + $a;');
probe('函数重复声明',     'function f() {} function f() {}');
probe('类重复声明',       'class C {} class C {}');
