<?php
/**
 * Q8 实测：PHP 8 各特性的「起始版本」矩阵
 *
 * 为什么不用文档里的版本号：题目问的是「升级要注意什么」，那就必须知道
 * 每一项到底从哪个版本开始有 —— 靠记忆写 8.1/8.2/8.3 容易错，而且不可复核。
 * 这里把每个特性都做成一次探测，在 7.3 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 六个版本上各跑一遍，
 * 谁 OK 谁不 OK 由 PHP 自己回答。
 *
 * 本文件自身必须是 7.3 兼容语法（不能用箭头函数、match、命名参数等），
 * 否则在 7.3 上连第一行都执行不到。
 *
 * 两条用血换来的经验（第一版就是踩了这两个坑）：
 *  1) 探测必须丢给**子进程**跑，不能在本进程 eval()。
 *     有些写法是编译期错误（E_COMPILE_ERROR）而不是 ParseError，catch 不住，
 *     一整轮探测会当场中断，后面全部变成「没数据」。实测踩到的两个：
 *       PHP 8.0 : eval 'new K6() 当参数默认值'   → Fatal error: Constant expression contains invalid operations
 *       PHP 8.2 : eval '$s{0}'                  → Fatal error: Array and string offset access syntax with curly braces is no longer supported
 *  2) 探测结果里不能出现 `|`，下游是按 `|` 切列做矩阵的
 *     （标签里写了 `(A&B)|null`，结果那一列直接被切碎，8.4 明明支持却显示 ERR）。
 *
 * 用法:
 *   docker exec learn-php php /app/bench/php/q8-feature-matrix.php
 *   bash /opt/learn/bench/php/q8-feature-matrix.sh     # 六个版本一起跑并转成矩阵
 */

printf("PHP|%s\n", PHP_VERSION);

/**
 * 语法探测：丢给 `php -r` 子进程，能跑完并打印 |OK 就是支持。
 * 只把「报错原文的头几个词」带回来，不然一屏都是堆栈。
 */
function syn($label, $code)
{
    // php -r 的代码里不能有 <?php 标签；末尾补一个标记，用来区分「没报错」和「没输出」
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r '
         . escapeshellarg($code . '; echo "|OK";') . ' 2>&1';
    $out = (string) shell_exec($cmd);

    if (strpos($out, '|OK') !== false) {
        printf("%s|OK\n", $label);
        return;
    }
    $line = '';
    foreach (explode("\n", $out) as $l) {
        if (preg_match('/(Fatal error|Parse error|CompileError|Compile error|TypeError|Error|Exception|Deprecated|Warning)/', $l)) {
            $line = $l;
            break;
        }
    }
    if ($line === '') { $line = trim($out) === '' ? '(无输出)' : trim($out); }
    $line = preg_replace('/^PHP /', '', $line);
    $line = preg_replace('/ in \/.*? on line \d+.*$/', '', $line);
    $line = preg_replace('/\s+/', ' ', $line);
    if (strlen($line) > 46) { $line = substr($line, 0, 46) . '...'; }
    printf("%s|%s\n", $label, $line);
}

/** API 探测：函数/类/接口/常量是否存在。这类探测在本进程做就够安全 */
function api($label, $kind, $name)
{
    switch ($kind) {
        case 'fn':    $ok = function_exists($name); break;
        case 'class': $ok = class_exists($name); break;
        case 'iface': $ok = interface_exists($name); break;
        case 'const': $ok = defined($name); break;
        default:      $ok = false;
    }
    printf("%s|%s\n", $label, $ok ? 'OK' : 'missing');
}

echo "\n== 语法特性 ==\n";

syn('箭头函数 fn()',      '$f = fn($x) => $x * 2; if ($f(2) !== 4) throw new Exception("wrong");');
syn('nullsafe ?->',       '$o = null; if ($o?->foo() !== null) throw new Exception("wrong");');
syn('match 表达式',       '$r = match (2) { 1 => "one", 2 => "two", default => "other" }; if ($r !== "two") throw new Exception("wrong");');
syn('命名参数',           'function f1($a, $b) { return $a - $b; } if (f1(b: 1, a: 5) !== 4) throw new Exception("wrong");');
syn('构造器属性提升',     'class K2 { public function __construct(public int $x = 7) {} } if ((new K2())->x !== 7) throw new Exception("wrong");');
syn('readonly 属性',      'class K3 { public readonly int $x; public function __construct() { $this->x = 3; } } if ((new K3())->x !== 3) throw new Exception("wrong");');
syn('枚举 enum',          'enum E4 { case A; } if (E4::A->name !== "A") throw new Exception("wrong");');
// 注意：不能只写 `function f5(): never {...}`。7.3 会把 never 当成一个**类名**，
// 照样解析通过（假阳性）。所以再加一步反射检查：真·never 是内置类型，假·never 是类类型。
syn('never 返回类型',     'function f5(): never { throw new RuntimeException("x"); } $t = (new ReflectionFunction("f5"))->getReturnType(); if (!$t->isBuiltin()) throw new Exception("never 被当成了类名");');
syn('new 出现在参数默认值', 'class K6 {} class K7 { public function __construct(K6 $k = new K6()) {} } if (!(new K7()) instanceof K7) throw new Exception("wrong");');
syn('纯交集类型 A和B',    'class K8 {} class K9 {} function f10(K8&K9 $x) { return 1; }');
syn('一等公民可调用 strlen(...)', '$f = strlen(...); if ($f("abcd") !== 4) throw new Exception("wrong");');
syn('trait 里定义常量',   'trait T11 { const C = 11; } class K12 { use T11; } if (K12::C !== 11) throw new Exception("wrong");');
syn('readonly class',     'readonly class K13 { public function __construct(public int $x) {} } if ((new K13(1))->x !== 1) throw new Exception("wrong");');
// 同样防假阳性：真支持时可以传 null/false，假支持（被当类名）会 TypeError
syn('独立 null 类型',     'function f14(null $x) { return $x; } if (f14(null) !== null) throw new Exception("wrong");');
syn('独立 false true 类型', 'function f15(false $x): true { return true; } if (f15(false) !== true) throw new Exception("wrong");');
syn('DNF 类型 (A和B)或null', 'class K16 {} class K17 {} function f18((K16&K17)|null $x) { return 1; } if (f18(null) !== 1) throw new Exception("wrong");');
syn('类型化类常量',       'class K19 { const string S = "s"; } if (K19::S !== "s") throw new Exception("wrong");');
syn('属性钩子 property hook', 'class K20 { public int $x { get => 42; } } if ((new K20())->x !== 42) throw new Exception("wrong");');
syn('非对称可见性 private(set)', 'class K21 { public private(set) int $x = 1; public function set(int $v) { $this->x = $v; } } $o = new K21(); $o->set(5); if ($o->x !== 5) throw new Exception("wrong");');
syn('new 不带括号调用方法', 'class K22 { public function m() { return "m"; } } if (new K22()->m() !== "m") throw new Exception("wrong");');
syn('字符串偏移用花括号', '$s = "abc"; if ($s{0} !== "a") throw new Exception("wrong");');

echo "\n== 函数 / 类 / 接口 ==\n";

api('str_contains',           'fn',    'str_contains');
api('str_starts_with',        'fn',    'str_starts_with');
api('get_debug_type',         'fn',    'get_debug_type');
api('fdiv',                   'fn',    'fdiv');
api('preg_last_error_msg',    'fn',    'preg_last_error_msg');
api('接口 Stringable',        'iface', 'Stringable');
api('array_is_list',          'fn',    'array_is_list');
api('enum_exists',            'fn',    'enum_exists');
api('类 Random\\Randomizer',  'class', 'Random\\Randomizer');
api('属性 AllowDynamicProperties', 'class', 'AllowDynamicProperties');
api('类 SensitiveParameter',  'class', 'SensitiveParameter');
api('json_validate',          'fn',    'json_validate');
api('mb_str_pad',             'fn',    'mb_str_pad');
api('类 Override（属性）',    'class', 'Override');
api('数组函数 array_find',    'fn',    'array_find');
api('数组函数 array_any',     'fn',    'array_any');
api('数组函数 array_all',     'fn',    'array_all');
api('类 Deprecated（属性）',  'class', 'Deprecated');
api('mb_ucfirst',             'fn',    'mb_ucfirst');
api('常量 E_STRICT',          'const', 'E_STRICT');

echo "\n== 已移除 / 已弃用 ==\n";

api('create_function',        'fn',    'create_function');
api('each',                   'fn',    'each');
api('get_magic_quotes_gpc',   'fn',    'get_magic_quotes_gpc');
api('money_format',           'fn',    'money_format');
api('mcrypt_encrypt',         'fn',    'mcrypt_encrypt');
api('image2wbmp',             'fn',    'image2wbmp');
