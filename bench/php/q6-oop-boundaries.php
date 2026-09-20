<?php
/**
 * Q6 实测：抽象类 / 接口 / Trait 的能力边界
 *
 * 合法能力直接在本文档进程里声明并反射验证；
 * 非法写法会触发 Fatal error（不可 catch），所以每条都丢进一个 `php -r` 子进程，
 * 只把 PHP 自己吐出来的报错原文抓回来 —— 报错文案本身就是最硬的证据。
 *
 * 用法: docker exec learn-php php /app/bench/php/q6-oop-boundaries.php
 */

error_reporting(E_ALL);

echo "PHP ", PHP_VERSION, "\n";
echo str_repeat('=', 72), "\n";

/* ------------------------------------------------------------------ */
/* 0. 合法能力：真的声明，再用反射验证                                  */
/* ------------------------------------------------------------------ */

abstract class Abs
{
    public const  TYPED = 'php8.3+ 类型化常量';   // 常量
    protected int $prop = 1;                       // 实例属性（带类型）
    public static int $staticProp = 0;             // 静态属性
    public function __construct(public string $name = 'abs') {}  // 构造函数 + 属性提升
    public function body(): string { return 'has body'; }        // 带方法体的普通方法
    final public function sealed(): string { return 'final'; }   // final 方法
    abstract public function mustImpl(): string;                 // 抽象方法
    abstract public static function mustImplStatic(): string;    // 抽象静态方法
    private function helper(): string { return 'private'; }      // 私有方法
}

interface Iface
{
    public const  IVERSION = 1;                    // 常量
    public const  string ITYPED = 'php8.3+';       // 8.3 起接口常量也能带类型
    public function mustImpl(): string;            // 隐式抽象
    public static function staticDecl(): string;   // 静态方法声明（只能声明）
}

trait T
{
    public const  TC = 'trait const（8.2+）';       // 8.2 起 trait 可以有常量
    public int    $tp = 0;                          // 属性
    public static int $tsp = 0;                     // 静态属性
    public function hi(): string { return 'trait body'; }
    abstract public function fromTrait(): string;   // 8.0 起 trait 可以声明抽象方法
}

class Impl extends Abs implements Iface
{
    use T;
    public function mustImpl(): string { return 'impl'; }
    public static function mustImplStatic(): string { return 'static'; }
    public static function staticDecl(): string { return 'static decl'; }
    public function fromTrait(): string { return 'from trait'; }
}

$r = new ReflectionClass(Impl::class);
echo "[合法能力矩阵 · 反射验证]\n";
printf("  Impl 的父类            : %s\n", get_parent_class(Impl::class));
printf("  Impl 实现的接口        : %s\n", implode(',', $r->getInterfaceNames()));
printf("  Impl use 的 trait      : %s\n", implode(',', $r->getTraitNames()));
printf("  继承来的常量           : %s\n", implode(',', array_keys($r->getConstants())));
printf("  实例属性               : %s\n", implode(',', array_map(
    function ($p) { return $p->getName(); }, $r->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED))));
printf("  实例化并调用 trait 方法: %s\n", (new Impl())->hi());
printf("  trait 属性初值         : %s\n", var_export((new Impl())->tp, true));

// trait 方法的 Reflection 归属：声明类是谁？文件行号指向谁？
$m = new ReflectionMethod(Impl::class, 'hi');
printf("  trait 方法 getDeclaringClass: %s\n", $m->getDeclaringClass()->getName());
printf("  trait 方法 getFileName      : %s\n", basename($m->getFileName()));
printf("  trait 方法 getStartLine     : %s\n", $m->getStartLine());
printf("  class_uses(Impl)            : %s\n", implode(',', class_uses(Impl::class)));

// 抽象类的方法在子类里的“声明者”是抽象类
$m2 = new ReflectionMethod(Impl::class, 'body');
printf("  继承方法的 getDeclaringClass: %s\n", $m2->getDeclaringClass()->getName());

echo "\n";

/* ------------------------------------------------------------------ */
/* 1. 非法能力：子进程抓 PHP 原文报错                                   */
/* ------------------------------------------------------------------ */

/**
 * 在子进程里跑一段代码，抓 fatal/warning 原文
 */
function probe($label, $code)
{
    // 注意：php -r 的代码里不能再写 <?php 标签，否则直接 Parse error
    // 末尾统一追加一段标记，用来区分「没报错」和「没输出」
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r '
         . escapeshellarg($code . '; echo "|OK";') . ' 2>&1';
    $out = shell_exec($cmd);
    $out = trim((string) $out);
    $line = '';
    foreach (explode("\n", $out) as $l) {
        if (preg_match('/(Fatal error|Parse error|Warning|Deprecated)/', $l)) { $line = $l; break; }
    }
    if ($line === '') { $line = $out === '' ? '(无输出)' : trim($out); }
    // 去掉 "PHP Fatal error:  " 前缀与 " in /path on line N" 后缀，只留结论
    $line = preg_replace('/^PHP /', '', $line);
    $line = preg_replace('/ in \/.*? on line \d+$/', '', $line);
    $line = preg_replace('/\s+/', ' ', $line);
    printf("  %-34s %s\n", $label, $line);
}

echo "[非法能力 · PHP 原文报错]\n";

echo "-- 实例化 --\n";
probe('new 抽象类',     'abstract class A { abstract public function f(); } new A();');
probe('new 接口',       'interface I { public function f(); } new I();');
probe('new trait',      'trait T { public function f() {} } new T();');

echo "-- 属性 --\n";
probe('接口声明实例属性', 'interface I { public $x; }');
probe('接口声明静态属性', 'interface I { public static $x; }');
probe('抽象类声明 private abstract', 'abstract class A { abstract private function f(); }');

echo "-- 方法体 --\n";
probe('接口方法带方法体', 'interface I { public function f() { return 1; } }');
probe('接口静态方法带方法体', 'interface I { public static function f() { return 1; } }');
probe('接口方法 protected', 'interface I { protected function f(); }');

echo "-- 继承 --\n";
probe('继承两个类',      'class A {} class B {} class C extends A, B {}');
probe('接口继承接口(多继承)', 'interface I1 {} interface I2 {} interface I3 extends I1, I2 {}');
probe('类实现两个接口',  'interface I1 { public function a(); } interface I2 { public function b(); } class C implements I1, I2 { public function a() {} public function b() {} } echo "ok";');

echo "-- 接口常量 --\n";
probe('实现类覆盖接口常量', 'interface I { const V = 1; } class C implements I { const V = 2; } echo C::V;');
probe('子接口覆盖父接口常量', 'interface I1 { const V = 1; } interface I2 extends I1 { const V = 2; } echo I2::V;');
probe('常量和父类常量同名', 'class A { const V = 1; } interface I { const V = 2; } class C extends A implements I {}');
probe('两个接口常量同名', 'interface I1 { const V = 1; } interface I2 { const V = 2; } class C implements I1, I2 {} echo "ok";');
probe('trait 常量与接口常量同名', 'interface I { const V = 1; } trait T { const V = 2; } class C implements I { use T; } echo C::V;');
probe('  同上但值反过来(验证谁赢)', 'interface I { const V = 9; } trait T { const V = 2; } class C implements I { use T; } echo C::V;');
probe('trait 常量与父类常量同名', 'class A { const V = 1; } trait T { const V = 2; } class C extends A { use T; } echo C::V;');
probe('trait 常量与自身常量同名同值', 'trait T { const V = 2; } class C { use T; const V = 2; } echo C::V;');
probe('trait 常量与自身常量同名不同值', 'trait T { const V = 2; } class C { use T; const V = 3; } echo C::V;');

echo "-- trait 冲突 --\n";
probe('trait 方法同名冲突',
    'trait T1 { public function f() { return 1; } } trait T2 { public function f() { return 2; } } class C { use T1, T2; }');
probe('trait 属性同名同定义',
    'trait T1 { public $x = 1; } trait T2 { public $x = 1; } class C { use T1, T2; } echo "ok";');
probe('trait 属性同名不同默认值',
    'trait T1 { public $x = 1; } trait T2 { public $x = 2; } class C { use T1, T2; }');
probe('trait 属性同名不同可见性',
    'trait T1 { public $x = 1; } trait T2 { protected $x = 1; } class C { use T1, T2; }');
probe('trait 属性同名不同类型',
    'trait T1 { public int $x = 1; } trait T2 { public string $x = "1"; } class C { use T1, T2; }');
probe('trait 常量同名同值',
    'trait T1 { const C = 1; } trait T2 { const C = 1; } class C { use T1, T2; } echo "ok";');
probe('trait 常量同名不同值',
    'trait T1 { const C = 1; } trait T2 { const C = 2; } class C { use T1, T2; }');
probe('trait 常量 vs 类自身常量同名',
    'trait T1 { const C = 1; } class C { use T1; const C = 2; } echo "ok";');
probe('trait 抽象方法未实现',
    'trait T { abstract public function f(); } class C { use T; }');
probe('trait 方法 vs 类自身方法同名',
    'trait T { public function f() { return 1; } } class C { use T; public function f() { return 2; } } echo (new C)->f();');
probe('trait 静态方法同名冲突',
    'trait T1 { public static function f() {} } trait T2 { public static function f() {} } class C { use T1, T2; }');

echo "-- trait 不是类型 --\n";
probe('instanceof trait', 'trait T { public function f() {} } class C { use T; } var_dump((new C) instanceof T);');
probe('trait 当类型声明', 'trait T { public function f() {} } class C { use T; } function g(T $x) { return 1; } echo g(new C);');

echo "\n";

/* ------------------------------------------------------------------ */
/* 2. 冲突解决：insteadof / as                                          */
/* ------------------------------------------------------------------ */

echo "[冲突解决 insteadof / as]\n";

trait T1 { public function hello(): string { return 'T1::hello'; } public function only1(): string { return 'only1'; } }
trait T2 { public function hello(): string { return 'T2::hello'; } }

class Resolved
{
    use T1, T2 {
        T1::hello insteadof T2;   // 冲突：显式选 T1
        T2::hello as hello2;      // 别名：把 T2 的实现留下，换个名字
        T1::only1 as protected hidden;  // as 还能改可见性
    }
}
$o = new Resolved();
printf("  use T1,T2 { T1::hello insteadof T2; T2::hello as hello2; }\n");
printf("    ->hello()      = %s\n", $o->hello());
printf("    ->hello2()     = %s\n", $o->hello2());
printf("    ->hidden()     protected, 外部调用 → ");
$m3 = new ReflectionMethod(Resolved::class, 'hidden');
printf("%s\n", $m3->isProtected() ? 'isProtected()=true' : 'isProtected()=false');

// 别名不解决冲突：只 as 不 insteadof 仍然 fatal
probe('只 as 不 insteadof',
    'trait T1 { public function f() {} } trait T2 { public function f() {} } class C { use T1, T2 { T1::f as f1; } }');

// 单 trait 也能 as 改名（不需要冲突）
trait TS { public function a(): string { return 'a'; } }
class Single { use TS { a as b; } }
printf("  单个 trait 也能改名：a()=%s  b()=%s\n", (new Single())->a(), (new Single())->b());

echo "\n";

/* ------------------------------------------------------------------ */
/* 3. PHP 8.4 新增：接口 / 抽象类声明「带 hook 的属性」                  */
/* ------------------------------------------------------------------ */

echo "[PHP 8.4 新能力：接口能否声明属性（property hooks）]\n";

probe('8.4 接口声明带 hook 属性',
    'interface I { public string $name { get; } } echo "接口可以有属性了";');
probe('8.4 接口声明裸属性（无 hook）',
    'interface I { public string $name; } echo "ok";');
probe('8.4 抽象类声明抽象属性',
    'abstract class A { abstract public string $name { get; } } echo "ok";');

if (PHP_VERSION_ID >= 80400) {
    // 实现类用「普通属性」满足接口的 get hook 要求
    eval('interface HasName { public string $name { get; } }
          class PlainUser implements HasName { public string $name; }
          class HookedUser implements HasName { public string $name { get => strtoupper($this->name); } }');
    $u = new PlainUser();
    $u->name = 'tom';
    printf("  接口声明 hook 属性 + 实现类用普通属性满足: PlainUser->name = %s\n", $u->name);
    $h = new HookedUser();
    printf("  HookedUser 读取未初始化属性 → ");
    try { echo $h->name; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(); }
    echo "\n";
}

echo "\n";

/* ------------------------------------------------------------------ */
/* 4. 一个类能同时吃下多少：组合能力上限                                */
/* ------------------------------------------------------------------ */

echo "[组合能力：一个类能装多少东西]\n";

interface Ia { const A = 'a'; public function fa(); }
interface Ib { const B = 'b'; public function fb(); }
interface Ic { const C = 'c'; public function fc(); }
trait Ta { public function ta() { return 'ta'; } }
trait Tb { public function tb() { return 'tb'; } }
trait Tc { public function tc() { return 'tc'; } }

abstract class Base { abstract public function fb(); }

class Combiner extends Base implements Ia, Ib, Ic
{
    use Ta, Tb, Tc;
    public function fa() { return 'fa'; }
    public function fb() { return 'fb'; }
    public function fc() { return 'fc'; }
}
$rc = new ReflectionClass(Combiner::class);
printf("  1 个父类 + %d 个接口 + %d 个 trait + 自己的方法 = %d 个方法\n",
    count($rc->getInterfaceNames()), count($rc->getTraitNames()), count($rc->getMethods()));
printf("  其中常量 %d 个：%s\n", count($rc->getConstants()), implode(',', $rc->getConstants()));
printf("  method_exists('Combiner','ta') = %s\n", method_exists('Combiner', 'ta') ? 'true' : 'false');
printf("  抽象类里能塞抽象方法让子类实现：fb 由 %s 实现\n",
    (new ReflectionMethod(Combiner::class, 'fb'))->getDeclaringClass()->getName());
