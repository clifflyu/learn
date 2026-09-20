<?php
/**
 * Q9 打点：往 Laravel（/tmp/lara）的入口文件、Kernel、Pipeline、Router 里插入 trace 调用，
 * 把真实的调用顺序打到 /tmp/q9-trace.log。
 *
 * 跑法：docker exec learn-php php /app/bench/framework/20-q9-patch.php
 * 幂等：已经打过的补丁会跳过；锚点找不到会报错退出（不静默失败）。
 */

$root = '/tmp/lara';
if (! is_dir($root)) {
    fwrite(STDERR, "找不到 {$root}，先 composer create-project laravel/laravel /tmp/lara\n");
    exit(1);
}

const TRACE_FN = <<<'PHP'
$GLOBALS['Q9_T0'] = microtime(true);
function q9trace(string $msg): void {
    file_put_contents('/tmp/q9-trace.log', sprintf("%8.3f ms  %s\n", (microtime(true) - $GLOBALS['Q9_T0']) * 1000, $msg), FILE_APPEND);
}

PHP;

/** [文件, 锚点, 替换, 说明] */
$patches = [

    // ---------- 入口文件 ----------
    ['public/index.php',
        "define('LARAVEL_START', microtime(true));\n\n// Determine if the application is in maintenance mode...\n",
        "define('LARAVEL_START', microtime(true));\n\n// ===== Q9 打点 =====\n" . TRACE_FN
        . "q9trace('[01] public/index.php：define(LARAVEL_START)，请求从这里开始');\n\n"
        . "// Determine if the application is in maintenance mode...\n",
        'index.php 顶部 + 定义 trace 函数'],

    ['public/index.php',
        "require __DIR__.'/../vendor/autoload.php';\n",
        "q9trace('[02] require vendor/autoload.php（Composer）');\n"
        . "require __DIR__.'/../vendor/autoload.php';\n"
        . "q9trace('[03] autoload 注册完成');\n",
        'require autoload'],

    ['public/index.php',
        "\$app = require_once __DIR__.'/../bootstrap/app.php';\n\n\$app->handleRequest(Request::capture());\n",
        "\$app = require_once __DIR__.'/../bootstrap/app.php';\n"
        . "q9trace('[06] bootstrap/app.php 执行完，拿到 Application 实例');\n\n"
        . "q9trace('[07] \$app->handleRequest(Request::capture())');\n"
        . "\$app->handleRequest(Request::capture());\n"
        . "q9trace('[23] handleRequest() 返回，index.php 结束');\n",
        'handleRequest 调用'],

    // ---------- bootstrap/app.php ----------
    // 注意：这个文件 artisan 也会 require，那时 q9trace() 还不存在（它定义在 public/index.php），
    // 所以这里补一个空实现，否则 artisan 直接 Fatal error。
    ['bootstrap/app.php',
        "return Application::configure(basePath: dirname(__DIR__))\n",
        "if (! function_exists('q9trace')) {\n    function q9trace(string \$msg): void {}\n}\n"
        . "q9trace('[04] bootstrap/app.php：Application::configure() 开始组装');\n\n"
        . "return Application::configure(basePath: dirname(__DIR__))\n",
        'Application::configure 开始'],

    // ---------- Application ----------
    ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
        "    public function handleRequest(Request \$request)\n    {\n        \$kernel = \$this->make(HttpKernelContract::class);\n\n        \$response = \$kernel->handle(\$request)->send();\n\n        \$kernel->terminate(\$request, \$response);\n    }",
        "    public function handleRequest(Request \$request)\n    {\n"
        . "        \$kernel = \$this->make(HttpKernelContract::class);\n"
        . "        q9trace('[07b] Application::handleRequest()：容器解析出 ' . get_class(\$kernel));\n\n"
        . "        \$response = \$kernel->handle(\$request);\n"
        . "        q9trace('[20] Kernel::handle() 返回 ' . get_class(\$response) . ' status=' . \$response->getStatusCode() . '，准备 send()');\n"
        . "        \$response->send();\n"
        . "        q9trace('[21] \$response->send()：响应已写出');\n\n"
        . "        \$kernel->terminate(\$request, \$response);\n"
        . "        q9trace('[22] Kernel::terminate() 完成');\n"
        . "    }",
        'Application::handleRequest'],

    ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
        "        foreach (\$bootstrappers as \$bootstrapper) {\n            \$this['events']->dispatch('bootstrapping: '.\$bootstrapper, [\$this]);\n\n            \$this->make(\$bootstrapper)->bootstrap(\$this);\n\n            \$this['events']->dispatch('bootstrapped: '.\$bootstrapper, [\$this]);\n        }",
        "        foreach (\$bootstrappers as \$bootstrapper) {\n"
        . "            q9trace('[11] ├─ bootstrapper: ' . \$bootstrapper);\n"
        . "            \$this['events']->dispatch('bootstrapping: '.\$bootstrapper, [\$this]);\n\n"
        . "            \$this->make(\$bootstrapper)->bootstrap(\$this);\n\n"
        . "            \$this['events']->dispatch('bootstrapped: '.\$bootstrapper, [\$this]);\n        }",
        'Application::bootstrapWith 逐个启动项'],

    ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
        "    public function __construct(\$basePath = null)\n    {\n",
        "    public function __construct(\$basePath = null)\n    {\n"
        . "        q9trace('[05] new Application()：容器诞生');\n",
        'Application::__construct'],

    // ---------- HTTP Kernel ----------
    ['vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php',
        "        \$this->requestStartedAt = Carbon::now();\n",
        "        q9trace('[08] Illuminate\\Foundation\\Http\\Kernel::handle()：请求进入 Kernel');\n"
        . "        \$this->requestStartedAt = Carbon::now();\n",
        'Kernel::handle'],

    ['vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php',
        "        \$this->app->instance('request', \$request);\n\n        Request::clearResolvedInstance();\n\n        \$this->bootstrap();\n\n        return (new Pipeline(\$this->app))\n            ->send(\$request)\n            ->through(\$this->app->shouldSkipMiddleware() ? [] : \$this->middleware)\n            ->then(\$this->dispatchToRouter());",
        "        q9trace('[09] Kernel::sendRequestThroughRouter()：把 request 实例绑进容器');\n"
        . "        \$this->app->instance('request', \$request);\n\n        Request::clearResolvedInstance();\n\n"
        . "        q9trace('[10] Kernel::bootstrap() 开始');\n"
        . "        \$this->bootstrap();\n"
        . "        q9trace('[15] bootstrap 全部完成');\n\n"
        . "        \$middleware = \$this->app->shouldSkipMiddleware() ? [] : \$this->middleware;\n"
        . "        q9trace('[16] 全局中间件 Pipeline（' . count(\$middleware) . ' 个）：'\n"
        . "            . implode(' → ', array_map(fn(\$m) => is_string(\$m) ? class_basename(\$m) : get_class(\$m), \$middleware)));\n\n"
        . "        return (new Pipeline(\$this->app))\n            ->send(\$request)\n"
        . "            ->through(\$middleware)\n            ->then(\$this->dispatchToRouter());",
        'Kernel::sendRequestThroughRouter'],

    ['vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php',
        "    public function terminate(\$request, \$response)\n    {\n        \$this->app['events']->dispatch(new Terminating);\n",
        "    public function terminate(\$request, \$response)\n    {\n"
        . "        q9trace('[22a] Kernel::terminate()：跑 terminable 中间件');\n"
        . "        \$this->app['events']->dispatch(new Terminating);\n",
        'Kernel::terminate'],

    // ---------- Router ----------
    ['vendor/laravel/framework/src/Illuminate/Routing/Router.php',
        "    public function dispatchToRoute(Request \$request)\n    {\n        return \$this->runRoute(\$request, \$this->findRoute(\$request));\n    }",
        "    public function dispatchToRoute(Request \$request)\n    {\n"
        . "        q9trace('[17] Router::dispatchToRoute()：开始匹配 ' . \$request->getMethod() . ' ' . \$request->getPathInfo());\n"
        . "        \$route = \$this->findRoute(\$request);\n"
        . "        q9trace('[18] 命中路由 ' . implode('|', \$route->methods()) . ' ' . \$route->uri() . '  →  ' . \$route->getActionName());\n\n"
        . "        return \$this->runRoute(\$request, \$route);\n    }",
        'Router::dispatchToRoute'],

    ['vendor/laravel/framework/src/Illuminate/Routing/Router.php',
        "        \$middleware = \$shouldSkipMiddleware ? [] : \$this->gatherRouteMiddleware(\$route);\n\n        return (new Pipeline(\$this->container))",
        "        \$middleware = \$shouldSkipMiddleware ? [] : \$this->gatherRouteMiddleware(\$route);\n"
        . "        q9trace('[19] 路由中间件 Pipeline（' . count(\$middleware) . ' 个）：'\n"
        . "            . implode(' → ', array_map(fn(\$m) => is_string(\$m) ? class_basename(\$m) : get_class(\$m), \$middleware)));\n\n"
        . "        return (new Pipeline(\$this->container))",
        'Router::runRouteWithinStack'],

    ['vendor/laravel/framework/src/Illuminate/Routing/Router.php',
        "    public function prepareResponse(\$request, \$response)\n    {\n",
        "    public function prepareResponse(\$request, \$response)\n    {\n"
        . "        q9trace('[19b] Router::prepareResponse()：路由返回值 → Response 对象');\n",
        'Router::prepareResponse'],

    // ---------- Pipeline：中间件进出顺序 ----------
    ['vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php',
        "                    \$carry = method_exists(\$pipe, \$this->method)\n                        ? \$pipe->{\$this->method}(...\$parameters)\n                        : \$pipe(...\$parameters);\n\n                    return \$this->handleCarry(\$carry);",
        "                    \$q9name = is_object(\$pipe) ? get_class(\$pipe) : (is_string(\$pipe) ? \$pipe : 'Closure');\n"
        . "                    q9trace('       ▶ 进入 ' . \$q9name);\n"
        . "                    \$carry = method_exists(\$pipe, \$this->method)\n"
        . "                        ? \$pipe->{\$this->method}(...\$parameters)\n"
        . "                        : \$pipe(...\$parameters);\n"
        . "                    q9trace('       ◀ 离开 ' . \$q9name);\n\n"
        . "                    return \$this->handleCarry(\$carry);",
        'Pipeline::carry 记录中间件进出'],
];

$applied = 0;
$skipped = 0;

foreach ($patches as [$rel, $find, $replace, $desc]) {
    $path = $root . '/' . $rel;
    if (! is_file($path)) {
        fwrite(STDERR, "✗ 文件不存在：{$path}\n");
        exit(1);
    }
    $src = file_get_contents($path);

    // 幂等判断按「补丁」而不是按「文件」：锚点被替换掉就说明这一处已经打过了。
    // （按文件判断会误伤同一个文件里的后续补丁）
    if (substr_count($src, $find) === 0 && str_contains($replace, 'q9trace(')) {
        printf("· 已打过，跳过：%s\n", $desc);
        $skipped++;
        continue;
    }

    $n = substr_count($src, $find);
    if ($n !== 1) {
        fwrite(STDERR, sprintf("✗ 锚点在 %s 里出现 %d 次（要求 1 次）：%s\n", $rel, $n, $desc));
        exit(1);
    }

    file_put_contents($path, str_replace($find, $replace, $src));
    printf("✓ %s  ← %s\n", $desc, $rel);
    $applied++;
}

// ---------- 路由中间件 + 路由 ----------
$mwDir = $root . '/app/Http/Middleware';
@mkdir($mwDir, 0777, true);
file_put_contents($mwDir . '/Q9RouteMiddleware.php', <<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Q9RouteMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        q9trace('       [route middleware] Q9RouteMiddleware 前置');
        $response = $next($request);
        q9trace('       [route middleware] Q9RouteMiddleware 后置');

        return $response;
    }
}
PHP);
printf("✓ 写入路由中间件 app/Http/Middleware/Q9RouteMiddleware.php\n");

$web = $root . '/routes/web.php';
if (! str_contains(file_get_contents($web), '/lifecycle')) {
    file_put_contents($web, file_get_contents($web) . <<<'PHP'

// ===== Q9 打点用路由 =====
Route::get('/lifecycle', function (\Illuminate\Http\Request $request) {
    q9trace('       ★★ 控制器（路由闭包）执行 —— 业务代码在这里');

    return response('lifecycle ok', 200)->header('X-Q9', 'traced');
})->middleware([\App\Http\Middleware\Q9RouteMiddleware::class]);
PHP);
    printf("✓ routes/web.php 追加 /lifecycle 路由\n");
} else {
    printf("· /lifecycle 路由已存在\n");
}

printf("\n补丁 %d 处，跳过 %d 处\n", $applied, $skipped);
