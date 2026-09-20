<?php
/**
 * Q7 实测：手写一个最小 IoC 容器（Reflection 自动装配）+ 开销测量
 *
 * 用法: docker exec learn-php php /app/bench/php/q7-container.php
 */

error_reporting(E_ALL);

echo "PHP ", PHP_VERSION, "\n";
echo str_repeat('=', 70), "\n";

/* ================================================================== */
/* 被测对象：一条 4 层的依赖链                                          */
/* ================================================================== */

interface Logger { public function log(string $m): void; }

class FileLogger implements Logger
{
    public static int $built = 0;
    public function __construct(public string $path = '/tmp/app.log') { self::$built++; }
    public function log(string $m): void {}
}

class Connection
{
    public static int $built = 0;
    public function __construct(public string $dsn = 'sqlite::memory:', public ?Logger $logger = null) { self::$built++; }
}

class UserRepository
{
    public static int $built = 0;
    public function __construct(public Connection $conn) { self::$built++; }
}

class UserService
{
    public static int $built = 0;
    public function __construct(public UserRepository $repo, public Logger $logger) { self::$built++; }
}

class UserController
{
    public static int $built = 0;
    public function __construct(public UserService $service) { self::$built++; }
}

// 给「标量参数无默认值」的失败演示用
class NeedsScalar { public function __construct(public string $host) {} }
// 给「union type 装配不了」的失败演示用
class NeedsUnion { public function __construct(public FileLogger|UserService $dep) {} }
// 给「可变参数装配不了」的失败演示用
class NeedsVariadic
{
    public array $loggers;
    public function __construct(FileLogger ...$loggers) { $this->loggers = $loggers; }
}
// 给「依赖未绑定的接口」的失败演示用
interface Mailer {}
class MailService { public function __construct(public Mailer $mailer) {} }

/* ================================================================== */
/* 最小容器（约 70 行）                                                 */
/* ================================================================== */

class Container
{
    private array $bindings  = [];   // abstract => concrete
    private array $sharedIds = [];   // 需要共享的 concrete
    private array $shared    = [];   // 共享实例
    private array $plans     = [];   // 解析计划缓存：类名 => 构造参数描述
    private array $building  = [];   // 正在构造的类 → 环检测
    public bool $useCache    = true;
    public int  $reflectionCalls = 0;

    public function bind(string $abstract, string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, string $concrete): void
    {
        $this->bind($abstract, $concrete);
        $this->sharedIds[$concrete] = true;
    }

    public function get(string $id): object
    {
        $id = $this->bindings[$id] ?? $id;          // 接口 → 实现类

        if (!class_exists($id) && !interface_exists($id)) {   // 不查就丢给 ReflectionClass 会抛 ReflectionException
            throw new RuntimeException("无法自动装配: 类 $id 不存在");
        }
        if (interface_exists($id)) {                          // 走到这说明 bindings 没命中
            throw new RuntimeException("无法自动装配: 接口 $id 没有绑定实现");
        }
        if (isset($this->shared[$id])) {            // 共享实例直接返回
            return $this->shared[$id];
        }
        if (isset($this->building[$id])) {          // 环检测
            throw new RuntimeException(
                '循环依赖: ' . implode(' → ', array_keys($this->building)) . " → $id");
        }

        $this->building[$id] = true;
        try {
            $obj = $this->build($id);
        } finally {
            unset($this->building[$id]);
        }

        if (isset($this->sharedIds[$id])) { $this->shared[$id] = $obj; }
        return $obj;
    }

    private function build(string $class): object
    {
        $plan = $this->plan($class);

        $args = [];
        foreach ($plan as $p) {
            if ($p['type'] === null) {                       // 标量 / 无类型
                if ($p['hasDefault']) { $args[] = $p['default']; continue; }
                throw new RuntimeException(
                    "无法自动装配: $class::\${$p['name']} 是标量/无类型参数且没有默认值，容器不知道注入什么");
            }
            if (!class_exists($p['type']) && !interface_exists($p['type'])) {
                throw new RuntimeException("无法自动装配: $class::\${$p['name']} 的类型 {$p['type']} 不存在");
            }
            if (interface_exists($p['type']) && !isset($this->bindings[$p['type']])) {
                if ($p['hasDefault']) { $args[] = $p['default']; continue; }
                throw new RuntimeException(
                    "无法自动装配: $class::\${$p['name']} 依赖接口 {$p['type']}，容器里没有绑定实现，且没有默认值");
            }
            try {
                $args[] = $this->get($p['type']);            // 递归解析
            } catch (RuntimeException $e) {
                if ($p['hasDefault']) { $args[] = $p['default']; continue; }
                throw $e;
            }
        }
        return new $class(...$args);
    }

    /** 解析构造函数签名，结果缓存 */
    private function plan(string $class): array
    {
        if ($this->useCache && isset($this->plans[$class])) {
            return $this->plans[$class];
        }
        $this->reflectionCalls++;
        $ctor = (new ReflectionClass($class))->getConstructor();
        $plan = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $p) {
                $t = $p->getType();
                $plan[] = [
                    'name'       => $p->getName(),
                    'type'       => ($t instanceof ReflectionNamedType && !$t->isBuiltin()) ? $t->getName() : null,
                    'hasDefault' => $p->isDefaultValueAvailable(),
                    'default'    => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
                ];
            }
        }
        if ($this->useCache) { $this->plans[$class] = $plan; }
        return $plan;
    }
}

/* ================================================================== */
/* 1. 自动装配一条 4 层依赖链，全程没有一处 new                          */
/* ================================================================== */

echo "[1] 自动装配\n";
$c = new Container();
$c->singleton(Logger::class, FileLogger::class);   // 接口 → 实现，且共享
$c->bind(Connection::class, Connection::class);

$ctrl = $c->get(UserController::class);
printf("  get(UserController) → %s\n", get_class($ctrl));
printf("  链路: UserController → %s → %s → %s → %s\n",
    get_class($ctrl->service),
    get_class($ctrl->service->repo),
    get_class($ctrl->service->repo->conn),
    get_class($ctrl->service->repo->conn->logger));
printf("  接口自动换成了绑定的实现: %s\n", get_class($ctrl->service->logger));

echo "\n[1b] 装配不了的时候报什么错\n";
foreach ([
    '标量参数无默认值' => [NeedsScalar::class, 'NeedsScalar'],
    '依赖未绑定的接口' => [MailService::class, 'MailService'],
    'union type 参数'  => [NeedsUnion::class, 'NeedsUnion'],
    '类型不存在'       => ['NoSuchClass', 'NoSuchClass'],
] as $label => $spec) {
    $cc = new Container();
    $cc->bind(Connection::class, Connection::class);
    try {
        $cc->get($spec[0]);
        printf("  %-18s (没报错?!)\n", $label);
    } catch (RuntimeException $e) {
        printf("  %-18s %s\n", $label, $e->getMessage());
    }
}
// 可变参数：容器不报错，但只塞进去 1 个实例
$cv = new Container();
$cv->bind(Logger::class, FileLogger::class);
$variadic = $cv->get(NeedsVariadic::class);
printf("  %-18s 容器不报错，但只注入了 %d 个实例（可变参数被当普通参数处理）\n",
    '可变参数', count($variadic->loggers));

/* ================================================================== */
/* 2. 共享实例 vs 每次新造                                              */
/* ================================================================== */

echo "\n[2] singleton vs 每次新造\n";
UserService::$built = 0;
$c->get(UserController::class);
$c->get(UserController::class);
printf("  共享 Logger 的容器 get 两次: UserService 造了 %d 次 (UserController 未注册共享，每次都造)\n",
    UserService::$built);

$transient = new Container();
$transient->bind(Logger::class, FileLogger::class);   // 接口仍要绑实现，只是不共享
UserService::$built = FileLogger::$built = 0;
$t1 = $transient->get(UserController::class);
$t2 = $transient->get(UserController::class);
printf("  全非共享容器 get 两次: UserService 造了 %d 次, FileLogger 造了 %d 次, 两个 controller 同一实例? %s\n",
    UserService::$built, FileLogger::$built, $t1 === $t2 ? 'yes' : 'no');

/* ================================================================== */
/* 3. 循环依赖                                                          */
/* ================================================================== */

echo "\n[3] 循环依赖\n";
class A { public function __construct(public B $b) {} }
class B { public function __construct(public A $a) {} }
try {
    (new Container())->get(A::class);
} catch (RuntimeException $e) {
    echo "  带检测的容器: ", $e->getMessage(), "\n";
}

$naive = <<<'PHP'
class A { public function __construct(B $b) {} }
class B { public function __construct(A $a) {} }
$depth = 0;
register_shutdown_function(function () use (&$depth) { fwrite(STDERR, "  崩之前递归到了第 $depth 层\n"); });
function naive($class) {
    global $depth;
    $depth++;
    $rc = new ReflectionClass($class);
    $args = array();
    foreach ($rc->getConstructor()->getParameters() as $p) { $args[] = naive($p->getType()->getName()); }
    return $rc->newInstanceArgs($args);
}
naive('A');
PHP;
$out = trim((string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d memory_limit=64M -r ' . escapeshellarg($naive) . ' 2>&1'));
$out = preg_replace('/ in \/.*? on line \d+/', '', $out);
echo "  不带检测的容器（memory_limit=64M）:\n";
foreach (explode("\n", $out) as $l) {
    $l = trim($l);
    if ($l === '' || preg_match('/^#\d+ /', $l)) { continue; }   // 两万多层栈帧，丢掉
    echo '    ', $l, "\n";
}

/* ================================================================== */
/* 4. 开销：Reflection 现算 vs 缓存解析计划 vs 直接 new                 */
/* ================================================================== */

echo "\n[4] 开销测量 (N = 100000 次解析 UserController，5 轮轮转交错，各配置取最小值)\n";

$N = 100000;
$ROUNDS = 5;

// 四组配置轮流跑。宿主机上还有别的容器在抢 CPU，顺序跑会让某组独吞一段抖动，
// 交错跑则抖动均摊到每一组，比值才可信。
$cNoCache = new Container();
$cNoCache->useCache = false;
$cNoCache->bind(Logger::class, FileLogger::class);
$cNoCache->bind(Connection::class, Connection::class);

$cCache = new Container();
$cCache->bind(Logger::class, FileLogger::class);
$cCache->bind(Connection::class, Connection::class);

$cSingle = new Container();
$cSingle->singleton(Logger::class, FileLogger::class);
$cSingle->singleton(Connection::class, Connection::class);
$cSingle->singleton(UserRepository::class, UserRepository::class);
$cSingle->singleton(UserService::class, UserService::class);
$cSingle->singleton(UserController::class, UserController::class);
$cSingle->get(UserController::class);   // 预热：建图只发生一次

$configs = array(
    '直接 new（人工装配）' => function () {
        $x = new UserController(new UserService(new UserRepository(new Connection('dsn', new FileLogger())), new FileLogger()));
    },
    '容器，不缓存 Reflection' => function () use ($cNoCache) { $cNoCache->get(UserController::class); },
    '容器，缓存解析计划' => function () use ($cCache) { $cCache->get(UserController::class); },
    '容器，全注册 singleton' => function () use ($cSingle) { $cSingle->get(UserController::class); },
    '纯 ReflectionClass 一次' => function () {
        $rc = new ReflectionClass(UserController::class);
        foreach ($rc->getConstructor()->getParameters() as $p) { $p->getType(); }
    },
);

$times = array_fill_keys(array_keys($configs), []);
for ($r = 0; $r < $ROUNDS; $r++) {
    foreach ($configs as $label => $resolve) {
        $t = hrtime(true);
        for ($i = 0; $i < $N; $i++) { $resolve(); }
        $times[$label][] = (hrtime(true) - $t) / 1e6;
    }
}

$rows = [];
foreach ($times as $label => $list) {
    sort($list);
    $notes = '';
    if ($label === '容器，不缓存 Reflection') { $notes = 'ReflectionClass 构造 ' . $cNoCache->reflectionCalls . ' 次'; }
    if ($label === '容器，缓存解析计划')      { $notes = 'ReflectionClass 构造 ' . $cCache->reflectionCalls . ' 次'; }
    $rows[] = array($label, $list[0], $list[intdiv($ROUNDS, 2)], $notes);
}
$direct = $rows[0][1];

foreach ($rows as $r) {
    printf("  %-26s 最小 %8.2f ms  中位 %8.2f ms  (%6.3f µs/次)  %s\n",
        $r[0], $r[1], $r[2], $r[1] * 1000 / $N, $r[3]);
}
printf("  → 容器 vs 直接 new: 不缓存 %.1f 倍，缓存计划 %.1f 倍，全 singleton %.2f 倍\n",
    $rows[1][1] / $direct, $rows[2][1] / $direct, $rows[3][1] / $direct);

echo "\n完成\n";
