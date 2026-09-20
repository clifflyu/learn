# 02 · 框架与架构（Q9–Q12）

> 配套 `../php-senior-interview-top50.md`。Laravel 为容器内实测安装的 **13.32.0**，脚本在 `../bench/framework/`。
> 这台机器是共用的（8 核，load average 长期 10~50），**所有耗时都取多轮最小值**，并且优先给**不受噪声影响的确定性指标**（查询次数、扫描行数、决策点个数、文件哈希）。

---

## Q9. Laravel 的请求生命周期是怎样的？从入口文件到响应输出。

### 结论

`public/index.php` → `$app->handleRequest()` → `HttpKernel::handle()` → **6 个 bootstrapper** → 全局中间件 Pipeline → 路由匹配 → 路由中间件 Pipeline → 控制器 → `prepareResponse`（**跑了两次**）→ `send()` → `terminate()`。

实测一次热请求 **105.7 ms**，其中**控制器只占 2.45 ms（2.3%）**，而 bootstrap 占 35.8 ms（34%）——**你要优化的那行业务代码，通常不在热点上**。

**Laravel 11+ 已经没有 `app/Http/Kernel.php`、没有 `RouteServiceProvider` 了。** 网上绝大多数生命周期图是 5.x~10.x 的，在 11+ 上是错的。

### 一、全链路（真实打点，不是照着源码画的）

```
public/index.php
  │  define('LARAVEL_START', microtime(true));           ← 热请求 0.003 ms 就走到这里
  ├─ 维护模式检查 storage/framework/maintenance.php
  ├─ require vendor/autoload.php                          ← Composer：冷 66.1 ms / 热 6.6 ms
  │
  ├─ $app = require bootstrap/app.php
  │     └─ Application::configure(basePath)->withRouting()->withMiddleware()
  │        ->withExceptions()->create()                   ← 返回 ApplicationBuilder 再 create()
  │           └─ new Application()  ← 容器诞生（Laravel 11+ 的入口在这里，不在 Kernel 里）
  │
  └─ $app->handleRequest(Request::capture())
        │
        │  ★ 注意：Request::capture() 此刻就创建了请求对象，但直到下面 instance() 才进容器
        │
        └─ [Illuminate\Foundation\Application]
              $kernel = $this->make(HttpKernelContract::class)   ← 从容器解析，没有 app/Http/Kernel.php
              │
              ├─ [Illuminate\Foundation\Http\Kernel] handle($request)
              │     └─ sendRequestThroughRouter($request)
              │           ├─ $this->app->instance('request', $request)   ← 请求对象此刻才进容器
              │           ├─ $this->bootstrap()          ← 6 个 bootstrapper，热请求 35.8 ms
              │           │    ① LoadEnvironmentVariables    5.4 ms  读 .env
              │           │    ② LoadConfiguration           9.3 ms  读 config/*.php
              │           │    ③ HandleExceptions            0.2 ms
              │           │    ④ RegisterFacades             0.7 ms  注册 AliasLoader
              │           │    ⑤ RegisterProviders           9.7 ms  bootstrap/providers.php
              │           │    ⑥ BootProviders               10.5 ms provider->boot()
              │           │
              │           └─ (new Pipeline($this->app))->send($request)
              │                 ->through($this->middleware)             ← 全局中间件 8 个，4.7 ms
              │                 ->then($this->dispatchToRouter())
              │                 │
              │                 │   ValidatePathEncoding → InvokeDeferredCallbacks → TrustProxies
              │                 │   → HandleCors → PreventRequestsDuringMaintenance → ValidatePostSize
              │                 │   → TrimStrings → ConvertEmptyStringsToNull
              │                 │        │
              │                 │        └─ [Router] dispatchToRoute($request)      2.3 ms 匹配
              │                 │              └─ runRoute()
              │                 │                    └─ prepareResponse(                  ← 第 2 次（外层）
              │                 │                         runRouteWithinStack()
              │                 │                           │
              │                 │                           └─ Pipeline->through(路由中间件 7 个)
              │                 │                                 EncryptCookies → AddQueuedCookiesToResponse
              │                 │                                 → StartSession → ShareErrorsFromSession
              │                 │                                 → PreventRequestForgery → SubstituteBindings
              │                 │                                 → App\Http\Middleware\Q9RouteMiddleware
              │                 │                                      │
              │                 │                                      └─ prepareResponse(   ← 第 1 次（内层）
              │                 │                                           $route->run()
              │                 │                                             └─ ★ 控制器执行（业务代码）
              │                 │
              │                 └─ （全局中间件的"后置"代码全部在响应组装完之后才跑）
              │
              ├─ $response->send()      ← 响应真正写给客户端
              └─ $kernel->terminate($request, $response)   ← 客户端已经拿到响应之后，还能再跑 1.65 ms
```

**三个容易记错的点，上图里都标了**：
`prepareResponse()` 出现两次（内层把闭包返回值转成 Response，外层再包一次，幂等）；
全局中间件的"出栈"部分在响应完全组装完之后才执行；
`send()` 和 `terminate()` 都在 `Application::handleRequest()` 内部，**`public/index.php` 一行都没写**。

### 二、实测（Laravel 13.32.0 / PHP 8.4.25）

装的是真实骨架：`composer create-project laravel/laravel` 到 `/tmp/lara`，然后往 7 个文件里插打点（`20-q9-patch.php`），起 `php -S` 发两次真实 HTTP 请求（`21-q9-trace.sh`）。
原始 trace 存在 `bench/framework/q9-lifecycle-trace.log`（126 行，完整可查）。

**分阶段耗时**（同一进程内第 1 次=冷启动、第 2 次=OPcache 已热）：

| 阶段 | 冷启动 ms | 热请求 ms | 热请求占比 |
| --- | ---: | ---: | ---: |
| ① Composer autoload | 66.1 | 6.6 | 6% |
| ② `bootstrap/app.php` → `Application` 实例 | 38.0 | 7.2 | 7% |
| ③ 解析 `HttpKernel`、进 `handle()`、绑 `request` | 144.2 | 11.3 | 11% |
| ④ `Kernel::bootstrap()`（6 个 bootstrapper） | 269.8 | 35.8 | **34%** |
| ⑤ 全局中间件 Pipeline（8 个） | 21.2 | 4.7 | 4% |
| ⑥ 路由匹配 + 收集路由中间件 | 16.0 | 2.3 | 2% |
| ⑦ 路由中间件 Pipeline（7 个）+ **控制器** | 118.2 | 16.7 | 16% |
| ⑧ 中间件出栈 + 两次 `prepareResponse` | 28.6 | 18.8 | 18% |
| ⑨ `send()` + `terminate()` | 5.3 | 2.0 | 2% |
| **合计** | **708.0** | **105.7** | 100% |

**6 个 bootstrapper 各自的耗时**（trace 里 `[11]` 打在每一项**开始之前**，所以相邻两条 `[11]` 的差就是前一项的耗时；最后一项用 `[15]` 收口）：

| bootstrapper | 冷启动 ms | 热请求 ms |
| --- | ---: | ---: |
| `LoadEnvironmentVariables` | 17.1 | 5.4 |
| `LoadConfiguration` | 64.2 | 9.3 |
| `HandleExceptions` | 2.0 | 0.2 |
| `RegisterFacades` | 5.8 | 0.7 |
| `RegisterProviders` | 95.9 | 9.7 |
| `BootProviders` | 84.7 | 10.5 |
| **合计** | **269.7** | **35.8** |

**`config:cache` 的效果**（`22-q9-cache.sh`，每个阶段独立跑 3 次取**逐项最小值**）：

| bootstrapper | 无缓存 ms | `config:cache` 后 ms | 变化 |
| --- | ---: | ---: | --- |
| `LoadEnvironmentVariables` | 2.023 | 0.243 | **8.3× 快** |
| `LoadConfiguration` | 6.642 | 0.590 | **11.3× 快** |
| `HandleExceptions` | 0.157 | 0.233 | — |
| `RegisterFacades` | 0.671 | 1.214 | **变慢 0.54 ms** |
| `RegisterProviders` | 5.213 | 5.941 | — |
| `BootProviders` | 5.174 | 5.398 | — |
| **bootstrap 合计** | **19.880** | **13.619** | **−31.5%** |

> **这个表的可信度靠的是"分布不重叠"，不是绝对值。** 两次独立运行里，`LoadConfiguration` 无缓存的 4 个样本是 11.970 / 13.876 / 7.802 / 6.642，`config:cache` 后的 4 个样本是 0.755 / 0.590 / 0.721 / 0.762 —— **最小值 6.642 仍大于对方最大值 0.762，8.7 倍，两个分布完全不重叠**，所以结论成立。但绝对数字在共享机器上会漂（同一份代码两次运行的最小值差 80%），**不要背数字，要背这个分离度**。

**`route:cache`**：实测 `php artisan route:cache` 输出 `Routes cached successfully.`，生成 `bootstrap/cache/routes-v7.php`（9,503 字节），之后清掉全部缓存、只做 `route:cache`，`GET /lifecycle` 仍返回 **HTTP 200**。

### 三、六个反直觉的点

**1. Laravel 11+ 没有 `app/Http/Kernel.php`，也没有 `RouteServiceProvider`。**
实测装完的骨架，`app/` 下只有 **3 个文件**：`Http/Controllers/Controller.php`、`Models/User.php`、`Providers/AppServiceProvider.php`——加上我为打点新增的路由中间件也才 4 个。全局中间件不在类属性里，而是在 `bootstrap/app.php` 的 `->withMiddleware(function (Middleware $middleware) {})` 闭包里配；服务提供者不在 `config/app.php` 的 `providers` 数组里，而在 `bootstrap/providers.php`。**面官如果按 5.x 的图问你 `$middlewareGroups` 和 `RouteServiceProvider::boot()`，你可以直接说这套在 11+ 已经拆了。**

**2. `prepareResponse()` 一次请求跑两次。**
trace 里 `[19b]` 出现在热请求的 **84.952 ms** 和 **103.111 ms** 两处，中间隔着整个路由中间件出栈过程。内层是 `Router::runRouteWithinStack()` 里 Pipeline 的终点 —— 把路由闭包的返回值（这里是字符串 `lifecycle ok`）转成 `Response` 对象；外层是 `Router::runRoute()` 把内层结果**再包一次**（已是 Response 则原样返回）。这也解释了为什么控制器里 `return 'string'` 和 `return response(...)` 都行。

**3. 一次热请求里控制器占 2.3%，bootstrap 占 34%。**
控制器（一个返回常量字符串的闭包）花了 **2.45 ms**；6 个 bootstrapper 花了 **35.8 ms**。冷启动更极端：708 ms 里控制器 1.6 ms（0.2%），autoload + bootstrap 占 374 ms。**"接口慢"先查框架固定开销和中间件，别先怀疑业务代码。**

**4. `send()` 之后还有 `terminate()`。**
热请求 103.947 ms 时响应已经写出去了（客户端此刻就能收到），`terminate()` 又跑了 **1.65 ms** 才结束。这就是 deferred callbacks、terminable 中间件、队列"请求后收尾"的落点。反过来说：**`terminate()` 里抛异常客户端已经收不到 500 了**，日志里会出现"请求成功但报了错"的诡异现象。

**5. 默认 `SESSION_DRIVER=database`，纯 API 请求也被拖上 session 存取。**
`StartSession` 进栈 **10.6 ms** + 出栈 **16.4 ms** = **27.0 ms，占热请求的 25.5%** —— 单个中间件里最贵的，比控制器贵 11 倍。出栈那 16.4 ms 就是 session 落盘（写 `sessions` 表）的那一段。而默认骨架的 `DB_CONNECTION` 是 `sqlite`，写的是 `database/database.sqlite` 文件。**这是 `composer create-project` 出来的骨架的默认值**（实测 `.env` 里 `SESSION_DRIVER=database`、`CACHE_STORE=database`，只有 `DB_CONNECTION=sqlite`），也就是说一个纯 API 请求（根本不需要 session）也会被 `web` 中间件组拖上这一次写盘。关掉的办法是不套 `web` 组，或者换 `array` / `cookie` 驱动。

**6. `route:cache` 现在能缓存闭包路由了。**
"用了 `Route::get('/x', function () {...})` 就不能 `route:cache`" 这条经验在 Laravel 13 上**已经过期**。实测带闭包路由依然缓存成功（靠 `laravel/serializable-closure` 序列化闭包），缓存后请求仍然 200。反过来说：**你现在没法再靠"route:cache 报错"来发现项目里的闭包路由了**，要主动 `grep` 或者靠规范。

### 四、实战结论

| 现象 | 结论 / 动作 |
| --- | --- |
| 接口 P99 偏高，业务代码很简单 | 先量 bootstrap 和中间件，不是业务代码。用 `terminate` 前的耗时差定位 |
| 冷启动第一波请求特别慢 | autoload + bootstrap 374 ms 里大半是类加载，生产一定要开 OPcache + `composer dump-autoload -o` |
| 想压 bootstrap 时间 | `config:cache` 砍掉 `LoadConfiguration` + `LoadEnvironmentVariables`（实测 −31.5%），`route:cache` 再砍路由注册 |
| 配置改了不生效 | 上了 `config:cache` 之后 `env()` 在 `config/*.php` 之外**返回 null**，改 `.env` 必须重新 `config:cache` |
| API 项目请求莫名多了写库 | 看是不是默认的 `SESSION_DRIVER=database`；纯 API 别走 `web` 中间件组 |
| 想在响应后干活（写日志、打点） | 用 `terminate()` / terminable 中间件 / `defer()`，但**别在里面抛异常指望客户端能收到** |
| 升级到 Laravel 11+ | `app/Http/Kernel.php`、`RouteServiceProvider`、`app/Console/Kernel.php` 三件套已经拆解，中间件改到 `bootstrap/app.php` |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 入口文件为什么那么短？ | `handleRequest()` 一个方法把 `handle()` → `send()` → `terminate()` 全包了，11+ 故意把 index.php 压到 4 行 |
| 全局中间件和路由中间件的边界？ | 全局的在 `Kernel::$middleware`（11+ 在 `bootstrap/app.php`），包住整个 `dispatchToRoute`；路由的在 `runRouteWithinStack` 里，只包住控制器 |
| 为什么中间件是"洋葱"模型？ | `Pipeline::carry()` 递归地把 `$next` 往下传，前置代码进栈顺序执行、后置代码出栈逆序执行 |
| `Request::capture()` 在哪一步？ | 在 `index.php` 里就创建了，但 `instance('request', ...)` 到 `sendRequestThroughRouter` 才绑进容器，之前容器里没有 `request` |
| 服务提供者的 `register()` 和 `boot()` 有什么区别？ | `register()` 在 `RegisterProviders` 阶段按注册顺序全部跑完（只能绑容器），`boot()` 在 `BootProviders` 阶段等所有 provider 都注册完才跑（可以依赖别的 provider） |
| 门面（Facade）在哪一步生效？ | `RegisterFacades` 注册 `AliasLoader`，真正的类加载是**懒的**——第一次用 `Cache::get()` 时才触发 autoload |
| 一个请求里容器是新的吗？ | 是，PHP-FPM 下一个请求一个进程生命周期，`new Application()` 每请求一次；Octane/Swoole 下会常驻，所以要小心容器里的状态污染 |
| 为什么 `bootstrap/cache` 要可写？ | `config:cache` / `route:cache` / 编译后的 Blade 视图都写在那里，权限不对会直接 500 |

---

## Q10. 什么是 N+1 查询问题？怎么发现、怎么解决？

### 结论

N+1 = 拿主表 N 行 1 次查询，然后在循环里**每行再查一次**关联表，共 **N+1 次**。

**它的问题不是"查的行多"，而是"往返次数多"**——每一次都是一次网络 round-trip + 一次 SQL 解析 + 一次优化器。所以**即使预加载退化成了全表扫描，它依然快 28 倍**（下面第 1 条反直觉点，实测）。

实测（MySQL 8.4.11，N=1000）：N+1 = **184.6 ms / 1001 次查询**，`IN` 预加载 = **14.3 ms / 2 次查询**，**12.9 倍**。
差分法（ΔN = 4000）：N+1 每多一个用户 **+186.5 µs**，预加载 **+4.6 µs**，差 **40 倍**。

### 一、N+1 长什么样

```
【N+1】 取 5 个用户和他们的帖子
   PHP                                          MySQL
    │ ① SELECT id,name FROM users LIMIT 5  ────►  返回 5 行
    │ ◄─────────────────────────────────────────
    │ ② SELECT id,title FROM posts WHERE user_id=1 ─►  ┐
    │ ◄──────────────────────────────────────────────  │
    │ ③ ...user_id=2 ──────────────────────────────►   │  循环里
    │ ◄──────────────────────────────────────────────  │  又发了
    │ ④ ...user_id=3                                   │  N 次，
    │ ⑤ ...user_id=4                                   │  每次都
    │ ⑥ ...user_id=5                                   │  要等往返
    │                                                  ┘
    共 1 + 5 = 6 次查询，SQL 文本几乎一样，只是绑定值不同

【IN 预加载】 同样 5 个用户
    │ ① SELECT id,name FROM users LIMIT 5 ────────►  返回 5 行
    │ ② SELECT id,user_id,title FROM posts
    │      WHERE user_id IN (1,2,3,4,5) ──────────►  返回全部
    共 2 次查询，与 N 无关
```

### 二、实测（MySQL 8.4.11）

数据集：`users` 5000 行、`posts` 25000 行（每人 5 篇），`posts.user_id` 建索引。库名 `q10_n1`。
四种写法：`N+1` / `IN 预加载` / `IN 分块 500` / `JOIN 单查`，每个 N 跑 5 轮取最小和中位。
脚本：`10-q10-setup.sql`、`11-q10-n1.php`（含 `index`/`noindex` 两种模式）、`12-q10-counter-calibration.php`、`13-q10-rows-metric.php`、`30-q10-eloquent.php`。

**① 查询次数**（索引存在）

| 写法 | N=100 | N=1000 | N=5000 | 公式 |
| --- | ---: | ---: | ---: | --- |
| N+1 | 101 | 1,001 | 5,001 | 1 + N |
| `IN` 预加载 | 2 | 2 | 2 | 2 |
| `IN` 分块 500 | 2 | 3 | 11 | 1 + ⌈N/500⌉ |
| `JOIN` 单查 | 1 | 1 | 1 | 1 |

**② 扫描量**（`Handler_read_key` 索引查找 / `read_next` 索引读行 / `rnd_next` 全表扫描行）

| 写法 | 指标 | N=100 | N=1000 | N=5000 |
| --- | --- | ---: | ---: | ---: |
| N+1 | 索引查找 | 101 | 1,001 | 5,001 |
| N+1 | 索引读行 | 599 | 5,999 | 29,999 |
| `IN` 预加载 | 索引查找 | 101 | 1,001 | **2** |
| `IN` 预加载 | **全表扫描行** | 0 | 0 | **25,001** |
| `IN` 分块 500 | 全表扫描行 | 0 | 0 | 0 |
| `JOIN` 单查 | 全表扫描行 | 101 | 1,001 | 5,001 |

**③ 耗时**（最小 / 中位，ms）

| 写法 | N=100 | N=1000 | N=5000 |
| --- | ---: | ---: | ---: |
| **N+1** | 19.9 / 20.1 | **184.6 / 188.7** | **930.5 / 1111.2** |
| `IN` 预加载 | 2.1 / 2.9 | 14.3 / 14.8 | 32.6 / 33.3 |
| `IN` 分块 500 | 2.0 / 2.2 | 13.2 / 14.6 | 69.4 / 72.3 |
| `JOIN` 单查 | 1.3 / 1.4 | 10.1 / 11.4 | 64.8 / 65.2 |

**④ 差分法：N=1000 → N=5000（ΔN = 4000）**

| 写法 | Δ耗时 ms | **µs / 每用户** | Δ索引读行 | Δ全表扫描行 | Δ查询次数 |
| --- | ---: | ---: | ---: | ---: | ---: |
| N+1 | 745.9 | **186.5** | 24,000 | 0 | 4,000 |
| `IN` 预加载 | 18.3 | **4.6** | -1,000 | 25,001 | 0 |
| `IN` 分块 500 | 56.2 | 14.0 | 24,000 | 0 | 8 |
| `JOIN` 单查 | 54.7 | 13.7 | 24,000 | 4,000 | 0 |

**⑤ 正确性校验**（同一 N 下四种写法的返回行数和内容校验和必须一模一样）

| N | N+1 | `IN` 预加载 | `IN` 分块 500 | `JOIN` 单查 |
| ---: | --- | --- | --- | --- |
| 100 | 500 行 / 7594 ✓ | 500 行 / 7594 ✓ | 500 行 / 7594 ✓ | 500 行 / 7594 ✓ |
| 1000 | 5000 行 / 76894 ✓ | 5000 行 / 76894 ✓ | 5000 行 / 76894 ✓ | 5000 行 / 76894 ✓ |
| 5000 | 25000 行 / 388894 ✓ | 25000 行 / 388894 ✓ | 25000 行 / 388894 ✓ | 25000 行 / 388894 ✓ |

**⑥ 真实 Eloquent（Laravel 13.32.0，`30-q10-eloquent.php`）**

| 写法 | 查询次数 | 耗时 ms |
| --- | ---: | ---: |
| `foreach (User::limit(1000)->get() as $u) { $u->posts; }` | **1,003** | 4,811.3 |
| `User::with('posts')->limit(1000)->get()` | **2** | 417.6 |
| `$u->posts()->count()` 循环取计数 | **1,001** | 4,177.8 |
| `User::withCount('posts')->limit(1000)->get()` | **2** | 41.9 |

`with()` 实际发出的 SQL（N=3）：

```sql
select * from `users` limit 3;
select * from `posts` where `posts`.`user_id` in (1, 2, 3);
```

### 三、六个反直觉的点

**1. 预加载也会退化成全表扫描——但它依然快 28 倍。**
N=5000 时，`IN 预加载` 的 `Handler_read_key` 只有 **2**（几乎没用索引），`rnd_next` 全表扫描 **25,001 行**（整个 posts 表）——因为 IN 列表里塞了 5000 个值，优化器认为挨个走索引回表还不如扫一遍。结果呢？**32.6 ms vs N+1 的 930.5 ms，仍然快 28.5 倍**。**省下来的是 5000 次 round-trip，不是行读取。** 这条彻底否定了"N+1 的问题是查得多"这个直觉。

**2. 没有索引时，N+1 是灾难级的。**
`posts.user_id` 索引摘掉后（`noindex` 模式）重跑：

| 写法 | N=1000 | N=5000 |
| --- | ---: | ---: |
| N+1 | 7,751.2 ms | **47,437.2 ms（47 秒）** |
| `IN` 预加载 | 20.4 ms | 74.1 ms |

N=5000 的 N+1 一共扫了 **125,005,000 行**（1.25 亿），差分法算出每多一个用户 **+9,921.5 µs**（有索引时是 186.5 µs，差 **53 倍**）。**同一个 N+1 代码，有没有索引能差 50 倍** —— 所以"上了预加载就完事"是错的，索引该建还得建。

**3. `JOIN` 单查并不总是最快的。**
N=100 / N=1000 时 JOIN 确实最快（1.3 / 10.1 ms），但 **N=5000 时 JOIN 64.8 ms 反而比 `IN` 预加载的 32.6 ms 慢一倍**。因为它要让 MySQL 做去重展开，而且结果集里用户的字段被重复了 5 倍（网络传输量变大）。N+1 的解法不是"一律 JOIN"。

**4. `Questions` 状态变量并不等于"SQL 语句数"。**
`12-q10-counter-calibration.php` 实测出的口径：`COM_QUERY` 计 **1**，`COM_STMT_EXECUTE` 计 **1**，**`COM_STMT_PREPARE` 计 0**；而且**读这个状态变量自己也会 +1**。我用"在与正测完全相同的代码路径上空载跑一遍"标定出常数偏移 = **4**，之后 `Questions − 4` 和自计数的 `exec` 在 **12 个格子里全部精确相等**。如果你直接拿 `Questions` 当语句数，PDO 的 prepare/execute 拆分会让你的报表全部错位。

**5. `Innodb_rows_read` 在 session 级别根本不能用。**
同一段 50 行的固定负载，重复测出 **1236 / 4052** 这种乱七八糟的值（`13-q10-rows-metric.php`）。它是全局累加计数器，在有多进程并发的实例上会被别人的读数污染，session 变量只是个 delta 快照。**要精确行数只能用 `Handler_read_key` / `Handler_read_next` / `Handler_read_rnd_next`** —— 这三个实测完全线性、可复现。

**6. 只取计数也是 N+1。**
`$u->posts()->count()` 在循环里 = **1001 次查询 / 4177.8 ms**；`withCount('posts')` = **2 次 / 41.9 ms**，**100 倍**。很多人以为"我没取关联对象，只取个数，不算 N+1"。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 循环里访问 `$model->relation` | 改成 `with('relation')` 预加载，先把 SQL 打出来看 |
| 循环里 `$model->relation()->count()` | 改成 `withCount('relation')` |
| 关联数据量可能很大（> 1000 主行） | `with(['posts' => fn($q) => $q->select('id','user_id','title')])` 限字段，必要时 `chunkById` |
| 预加载的 IN 列表可能超过几百 | **必须分块**（实测分块 500 在 N=5000 时 11 次查询 / 69.4 ms，仍远好于 N+1） |
| 需要"过滤 + 排序 + 分页"关联数据 | 用 `whereHas` / `withAggregate`，别在 PHP 里循环过滤 |
| 想在上线前拦住 | `Model::preventLazyLoading(! app()->isProduction())`，实测抛 `LazyLoadingViolationException: Attempted to lazy load [posts] on model [User] but lazy loading is disabled.` |
| 想找出正在发生的 N+1 | `DB::listen` 统计"同一 SQL 模板出现次数"，实测 50 个用户跑完：主查询 1 次、`where user_id = ?` **50 次**，一眼就看出来了 |
| 关联表没索引 | 先建索引再说，实测无索引时 N+1 慢 50 倍（差分 9921.5 vs 186.5 µs/用户） |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么 N+1 慢？ | 不是行多，是往返多。每次多一个 RTT + 解析 + 优化器开销，实测每用户 ~186 µs 全花在这上面 |
| 预加载一定更好吗？ | 不。IN 列表过长会退化成全表扫描（实测 N=5000 扫了 25,001 行），而且有绑定参数上限 / `max_allowed_packet` 风险，要分块 |
| `with()` 和 `load()` 的区别？ | `with()` 在查询时一并加载，主查询只有 1 条；`load()` 是事后对已有集合补一次 IN 查询，适合条件性加载 |
| `with()` 和 `withCount()` 能一起用吗？ | 能，但 `withCount` 会多一条聚合子查询，注意它和 `with` 的字段冲突 |
| `whereHas` 会不会 N+1？ | 不会，它编译成 `exists` 子查询；但 `whereHas` + `with` 都不加时，关联访问仍然是 N+1 |
| 懒加载拦截会影响生产吗？ | 会抛异常，所以只在非生产开。生产环境的替代品是 APM 的 SQL 聚合 / `DB::listen` 采样上报 |
| `JOIN` 能替代预加载吗？ | 一对一/多对一是等价且更省的；一对多会把主表字段重复展开（实测 N=5000 时 JOIN 比 IN 慢一倍），且分页会因行数膨胀算错 |
| 这些查询次数怎么测的？ | PDO 自己计数 + `SHOW SESSION STATUS LIKE 'Questions'` 交叉验证，`Handler_read_*` 量扫描量，三者互相对账 |

---

## Q11. 什么是 SOLID？结合一个真实的场景说明。

### 结论

SOLID 不是五个要背的名词，是**五个约束"改动成本"的规则**。它唯一可检验的形式是：

> **加一个新需求，要改几个**已有**文件、动几行**已有**代码。**

实测：把一个支付渠道的 `switch` 重构成策略模式后，新增 `applepay` 渠道从「**改核心类 +6 行 case**」变成「**新增 1 个文件 + 注册表 +1 行**」；核心类文件的 MD5 **从"变了"变成"没变"**——这才是"对扩展开放、对修改关闭"的字面验证。

### 一、五个原则，用一段真实的坏代码串起来

重构前的 `1-before.php`（105 行）：一个 `PaymentService::pay()` 方法 56 行，7 个决策点，四种职责：

```php
final class PaymentService
{
    public function __construct(private PDO $pdo) {}   // ← DIP 违背：直接依赖具体 PDO

    public function pay(int $channel, int $amount, int $userId): array
    {
        // ① 校验：不同渠道有不同最小/最大金额        ← 职责 1（风控规则）
        if ($channel === self::ALIPAY && $amount < 1)      throw new InvalidArgumentException('...');
        if ($channel === self::WECHAT && $amount < 100)    throw new InvalidArgumentException('...');
        if ($amount > 1000000)                             throw new InvalidArgumentException('...');

        // ② 选渠道 + 算费率                          ← 职责 2（商务规则）
        switch ($channel) {
            case self::ALIPAY:   $sdk = new AlipaySdk();   $fee = (int) round($amount * 0.006); break;
            case self::WECHAT:   $sdk = new WechatSdk();   $fee = (int) round($amount * 0.006); break;
            case self::UNIONPAY: $sdk = new UnionPaySdk(); $fee = (int) round($amount * 0.0055); break;
            default: throw new InvalidArgumentException('未知渠道');
        }

        $tradeNo = $sdk->createTrade($amount, $userId);   // ← 第三方 SDK 直接 new

        // ③ 落库                                     ← 职责 3（存储）
        $stmt = $this->pdo->prepare('INSERT INTO payments (...) VALUES (...)');
        $stmt->execute([...]);

        // ④ 写日志                                   ← 职责 4（可观测性）
        file_put_contents('/tmp/pay.log', sprintf("[%s] %s %d\n", date('c'), $tradeNo, $amount), FILE_APPEND);

        return ['trade_no' => $tradeNo, 'fee' => $fee];
    }
}
```

**五个原则在这段代码里逐一对应：**

| 原则 | 违背点 | 后果 |
| --- | --- | --- |
| **SRP** 单一职责 | `pay()` 同时承担校验、选渠道算费、落库、写日志 | 4 个不同的**变更原因**（风控改规则 / 商务加渠道 / DBA 换存储 / 运维改日志格式）都逼你打开这同一个方法 |
| **OCP** 开闭 | 加渠道要改 `switch` ——**修改**核心类，而不是**扩展** | 每加一个渠道都碰一次核心支付逻辑，回归范围不断变大 |
| **LSP** 里氏替换 | `AlipaySdk` / `WechatSdk` / `UnionPaySdk` 三个类没有共同接口，只是"看起来能互相替换" | 想统一调用只能靠 `switch` 硬编码，没有编译期/运行期契约保证 |
| **ISP** 接口隔离 | 一旦为了"统一"抽一个 `SdkClient` 大接口，把只有支付宝有的 `refund()` 塞进去 | 微信/银联就得写空实现的 `refund()`，或者抛 `BadMethodCallException` |
| **DIP** 依赖倒置 | `new PDO(...)`、`new AlipaySdk()`、`file_put_contents(...)` 全是具体实现 | 想单测一个 `unionpay` 渠道，**必须先有一个能连上的数据库**（实测：连不上直接 `SQLSTATE[HY000] [2002] Connection refused`） |

重构后的 `2-after.php`（183 行，7 个类 + 1 个注册表函数）：

```
PaymentGateway（编排，18 行）        ← 0 个决策点
  ├─ PaymentChannel 接口 ──┬─ AlipayChannel    (0 决策点)
  │                        ├─ WechatChannel    (0)
  │                        └─ UnionPayChannel  (0)
  ├─ SdkClient 接口 ───────┴─ AlipaySdk / WechatSdk / UnionPaySdk / FakeSdk
  ├─ AmountPolicy（费率与限额规则）   ← 3 个决策点，**规则集中在这一个类**
  ├─ Ledger 接口 ─── PdoLedger
  └─ PayLogger 接口 ── FilePayLogger
```

### 二、实测（PHP 8.4.25）

脚本：`bench/framework/q11/`（`1-before.php` / `2-after.php` / `2b-registry.php` / `3-run.php`）。

**① 契约测试：重构前后行为必须完全一致**（6 个用例覆盖 3 个渠道 × 边界金额）

| 渠道 | 金额(分) | 用户 | trade_no | fee | 出参对比 |
| --- | ---: | ---: | --- | ---: | --- |
| alipay | 100 | 1 | ALI1E6E0A04D2 | 1 | 一致 |
| alipay | 99999 | 7 | ALI575DAFBE2B | 600 | 一致 |
| wechat | 250000 | 42 | WX6F710C8C7E | 1500 | 一致 |
| unionpay | 1 | 9 | UP54229ABFCF | 0 | 一致 |
| unionpay | 1000000 | 3 | UP0DEB331F84 | 5500 | 一致 |
| wechat | 333333 | 88 | WX18104CA0AD | 2000 | 一致 |

用例 6 个，出参不一致 **0** 个；`payments` 表写入 **12** 行（两版各写一份，期望 12）；日志文本（忽略时间戳）一致。

**② 决策点分布**（统计 `if` / `elseif` / `case` / `&&` / `||` / `?:`，用 `token_get_all` 按类边界切分）

| 重构前 | 决策点 | 重构后 | 决策点 |
| --- | ---: | --- | ---: |
| `PaymentService`（校验/分支/落库/日志全在里面） | **7** | `PaymentGateway` | **0** |
| | | `AmountPolicy` | **3** |
| | | `AlipayChannel` / `WechatChannel` / `UnionPayChannel` | 0 / 0 / 0 |
| | | `PdoLedger` / `FilePayLogger` | 0 / 0 |
| | | `q11_channels()`（注册表） | 0 |

`pay()` 方法本体行数：**56 行 → 18 行**。

**③ OCP 实验：新增 `applepay` 渠道，各要动什么**

| 问 | 重构前（`switch`） | 重构后（策略 + 注册表） |
| --- | --- | --- |
| 修改的已有文件 | `1-before.php`：**+6 行 case 分支** | `2b-registry.php`：**+1 行** |
| 新增文件 | 0 | `ApplePayChannel.php`（11 行） |
| **核心类文件 MD5** | `f72f6188…` → **变了** | `db9c71dd…` → **没变** |
| 改动落在哪一层 | 支付核心逻辑（下次加还得进同一个 `switch`） | 注册表 / 新增类 |

新渠道实测可跑通：`applepay 123456 分 → trade_no=AP54B41981BE fee=469`（费率 0.38%）。

**④ 只测一个渠道，需要准备多少依赖**

- 重构后：`new UnionPayChannel(new FakeSdk('UP'))` —— 构造参数 2 个，**不需要 PDO、不需要 Logger、不需要容器**。单独跑通：`10000 分 → trade_no=UP2E07B8C9C2 fee=55`。
- 重构前：`new PaymentService(...)` —— 构造参数也是 2 个，但第 1 个是 `PDO`（**必须真连上库才能构造**）。用一个连不上的 DSN 构造，直接抛：`SQLSTATE[HY000] [2002] Connection refused`。

**也就是说：重构前想单测 `unionpay` 一个渠道，也必须先有一个可用的数据库连接。**

### 三、五个反直觉的点

**1. "职责"的判据是"变更原因"，不是"功能多少"。**
拆 `PaymentService` 不是因为"它干的事多"，是因为它有**四个不同的变更原因**：风控改限额、商务加渠道、存储换实现、运维改日志格式。**因为几件事凑在一起而拆，是过度设计；因为几种变更理由而拆，才是 SRP。**

**2. 重构后代码总行数变多了（105 → 183），但决策点从 7 个降到 3 个。**
"重构 = 代码变少"是错的。真正的指标是**决策点的集中度**：重构前 7 个判断散在一个方法里、每个都影响支付主干；重构后 3 个全在 `AmountPolicy` 一个类里，渠道类全是 0。**如果你用"文件行数"衡量重构，你会得出完全相反的结论。**

**3. OCP 可以用哈希验证，不用靠"感觉"。**
"对扩展开放、对修改关闭"听起来很虚，但它是**可证伪**的：加功能前把核心类文件打 MD5，加完之后哈希没变，才算真的做到了。实测 `2-after.php` 的哈希在新增 `applepay` 后**完全没变**，而 `1-before.php` 变了。**面试时能说出这个验证方式，比背定义强十倍。**

**4. LSP 和 ISP 在真实重构里往往是同一个动作的两面。**
抽 `SdkClient` 接口时，`AlipaySdk` 独有的 `refund()` 是试金石：为了"统一"把它塞进接口，微信/银联就得写空实现——那是 **ISP** 违背；而如果让 `AlipayChannel` 在 `SdkClient` 位置上"能替换"但行为不一致（比如退款返回 `false` 而不是抛异常），那就是 **LSP** 违背。**抽接口的时候顺手问一句"哪些方法不是所有实现都需要的"，就是在同时守这两条。**

**5. DIP 的收益不是"解耦"这个词，是"能不能单测"。**
"解耦"太抽象，量不出来。**能测出来的是：构造一个对象需不需要外部依赖。** 实测重构前 `new PaymentService(new PDO('mysql:host=不存在的机器;...'))` 直接 `Connection refused`；重构后 `new UnionPayChannel(new FakeSdk('UP'))` 就地跑通。**能不能在没有数据库的机器上跑单测，就是 DIP 做没做对的验收标准。**

### 四、实战结论

| 症状 | 违背的原则 | 最小改法 |
| --- | --- | --- |
| 一个方法 50+ 行、`switch` 里 5 个 `case` | OCP + SRP | 抽接口 + 每个 `case` 一个类 + 一个注册表/容器绑定 |
| 加个新类型要改 3 个文件、动核心类 | OCP | 把"新增"从"改已有"里挪出去：注册表 / 标签接口 / 事件 |
| 单测必须起数据库、起 Redis、起容器 | DIP | 依赖接口而不是具体类，测试传内存实现 / Fake |
| 为了"统一"在接口里塞了只有 1 个实现需要的方法 | ISP | 拆小接口，或者用能力接口（`Refundable`）按需实现 |
| 子类重写的方法抛 `NotSupportedException` | LSP | 说明父类抽象错了，别硬塞，往上抽或拆开 |
| 一个类改一次需求就要动一次，且每次都要重测全部 | SRP | 按"变更原因"拆，不是按"代码长度"拆 |

**注意反向的坑**：SOLID 不是"接口越多越好"。**如果同一类需求只会有一个实现、且永远不会变，抽接口就是纯成本。** 判断标准还是那句：**你被"改动"折磨过吗？** 没被折磨过的地方不要提前抽象——上面那个 `PayLogger` 接口之所以值得抽，是因为测试里立刻就要塞一个不写磁盘的实现。

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| SOLID 是五个独立原则吗？ | 不是。SRP 是"按变更原因分"，OCP 是"分完之后能扩展不修改"，DIP 是"分的方式靠依赖接口"，LSP/ISP 是"接口分得对不对的验收标准" |
| 什么时候不该用 SOLID？ | 需求只有一种可能、且短期不会变；原型 / 脚本；抽象成本高于维护收益时。**过度抽象比不抽象更贵** |
| 策略模式和工厂模式的关系？ | 加渠道这件事上两者配合：策略解决"行为不同"，注册表/工厂解决"谁来选"。单用策略还得 `switch` 选实例，等于没解决 OCP |
| 除了策略模式还有别的 OCP 做法吗？ | 注册表 + 标签接口、事件/监听器、容器标签（`tagged()`）、插件机制。核心都是"新增 = 加文件 + 加一行注册" |
| 怎么衡量重构没有改变行为？ | 契约测试（本例 6 个用例 0 不一致 + 落库 12 行 + 日志文本一致）。**没有测试的重构只是在赌** |
| Laravel 里怎么落地 DIP？ | 构造函数注入 + 在 `AppServiceProvider::register()` 里 `bind(Interface::class, Impl::class)`；单测用 `$this->instance()` 换 Fake |
| 抽象类还是接口？ | 有共享实现用抽象类（模板方法），只有契约用接口（策略、端口）。PHP 单继承，接口更灵活 |
| 这跟"贫血模型/充血模型"有关系吗？ | 有关系。把规则全塞进 Service 会让领域对象贫血；本例的 `AmountPolicy` 就是把规则留在领域层的做法 |

---

## Q12. 项目怎么分层？Controller / Service / Repository 的职责与边界在哪？

### 结论

分层的唯一判据是**依赖方向**：`Controller → Service → Repository → DB`，**箭头单向**，而且 Service 依赖的是 Repository 的**接口**，不是实现。

三句话记住三条边界：

- **Controller** 里不该出现 SQL，也不该出现业务规则；
- **Service** 里不该出现 `$_SERVER` / `Request` / `Response`——它必须能在 **HTTP、CLI、队列**三种入口下跑；
- **Repository** 里不该出现业务规则——它只管"怎么存取"，不管"能不能存"。

另外那条最容易吵的：**事务边界属于 Service。**

实测越界的代价（同一个 Service、同一笔 VIP 订单 10000 分）：Controller 里抄了一份折扣规则漏了 VIP，HTTP 入口**多收 500 分（5.00 元）**；Service 里读 `$_SERVER` 在队列里**不报错、静默算错价**；业务规则写进 Repository 后**单测说"成功"、线上说"拒绝"**；事务写在 Controller 时**队列路径留下 1 行脏数据**。

### 一、分层骨架

```
        ┌────────────────────────────────────────────────────┐
        │  Delivery / HTTP 层                                 │
        │  OrderController                                    │
        │    · 取参、类型转换、校验格式                        │
        │    · 调 Service                                     │
        │    · 把结果组装成 Response                          │
        │  ✗ 不写 SQL  ✗ 不写业务规则  ✗ 不开事务             │
        └───────────────────────┬────────────────────────────┘
                                │ 只依赖 Service 的入参/出参（数组或 DTO）
                                ▼
        ┌────────────────────────────────────────────────────┐
        │  Application / Service 层                           │
        │  PlaceOrderService                                  │
        │    · 编排用例：取策略 → 算价 → 建订单 → 存 → 返回   │
        │    · ★ 事务边界在这一层（beginTransaction/commit）  │
        │    · 抛领域异常（DomainException），不抛 HTTP 异常  │
        │  ✗ 不认识 $_SERVER  ✗ 不认识 Request/Response       │
        └───────────────────────┬────────────────────────────┘
                                │ 依赖 OrderRepository **接口**
                                ▼
        ┌────────────────────────────────────────────────────┐
        │  Domain 层                                          │
        │  Order / Quote（值对象）  PricingPolicy（规则）      │
        │  OrderRepository（接口，只有 findById/save）         │
        │  ✗ 接口里不该出现 beginTransaction / SQL / PDO      │
        └───────────────────────▲────────────────────────────┘
                                │ 实现（依赖箭头反过来指向内层）
        ┌───────────────────────┴────────────────────────────┐
        │  Infrastructure 层                                  │
        │  PdoOrderRepository（+ beginTransaction/commit/…）  │
        │  InMemoryOrderRepository（测试用）                   │
        │  ✗ 不写业务规则  ✗ 不抛领域异常                     │
        └────────────────────────────────────────────────────┘
```

对应的代码骨架（`1-domain.php` / `2-infra.php` / `3-application.php` / `4-http.php`）：

```php
// ---------- domain：值对象 + 规则 + 接口 ----------
final class Order { public function __construct(
    public readonly int $userId, public readonly int $amount, public readonly bool $vip) {} }

final class PricingPolicy {                       // 规则住在领域层
    public function quote(Order $o): Quote {
        $discount = $o->amount >= 10000 ? (int) round($o->amount * 0.10) : 0;
        if ($o->vip) { $discount += (int) round($o->amount * 0.05); }
        return new Quote($o->amount, $discount, $o->amount - $discount);
    }
}

interface OrderRepository {                       // 接口在领域层，没有 SQL、没有事务概念
    public function save(Order $o, array $items, Quote $q): int;
    public function findById(int $id): array;
}

// ---------- application：编排 + 事务边界 ----------
final class PlaceOrderService {
    public function __construct(private OrderRepository $orders, private PricingPolicy $pricing) {}

    public function place(array $input): array {
        $order = new Order((int) $input['user_id'], (int) $input['amount'], (bool) $input['vip']);
        if ($order->amount <= 0) { throw new InvalidArgumentException('金额必须为正'); }
        $quote = $this->pricing->quote($order);          // 规则来自领域层，这里不重算

        $this->orders->beginTransaction();               // ★ 事务边界在 Service
        try {
            $id = $this->orders->save($order, [[ 'sku' => $input['sku'],
                                                 'qty' => (int) $input['qty'] ]], $quote);
            $this->orders->commit();
        } catch (Throwable $e) { $this->orders->rollBack(); throw $e; }

        return ['order_id' => $id, 'total' => $quote->total, 'status' => 'placed'];
    }
}

// ---------- delivery：只做取参 / 调 Service / 组装响应 ----------
final class OrderController {
    public function __construct(private PlaceOrderService $service) {}

    public function store(Request $request): JsonResponse {
        try {
            return new JsonResponse(200, $this->service->place([
                'user_id' => (int) $request->input('user_id'),
                'amount'  => (int) $request->input('amount'),
                'vip'     => (bool) $request->input('vip', false),
                'sku'     => (string) $request->input('sku', 'SKU-1'),
                'qty'     => (int) $request->input('qty', 1),
            ]));
        } catch (InvalidArgumentException $e) {          // 入参错误 → 422
            return new JsonResponse(422, ['error' => $e->getMessage()]);
        }
        // 领域异常 → 404/409，由异常处理器统一映射；这里不写业务 if
    }
}
```

### 二、实测（PHP 8.4.25 / MySQL 8.4.11）

脚本 `bench/framework/q12/5-run.php`。场景：VIP 用户下单 10000 分，满减 10% + VIP 再减 5%，**正确总价 8500**。

**A) Controller 里复制了业务规则 → 两个入口两个价**

| 入口 | 代码路径 | total |
| --- | --- | ---: |
| CLI / 队列 | `PlaceOrderService::place()`（规则来自 `PricingPolicy`） | **8500** |
| HTTP | `BadOrderController::store()`（抄了一份规则，**漏了 VIP 分支**） | **9000** |

同一笔订单，HTTP 入口**多收了 500 分（5.00 元）**。

**B) Service 里读 `$_SERVER` / 返回 `Response` → 队列里跑不了**

```text
CLI（或队列消费者）调用 placeFromGlobals()：
  ① 不报错，静默算错价：total=9000，正确值 8500（VIP 折扣丢了）
  ② 调用方按数组取数：Error: Cannot use object of type JsonResponse as array
  返回类型是 JsonResponse —— 队列消费者拿到的不是数据而是一个 HTTP 响应对象
```

**C) 业务规则写进 Repository 实现 → 单测与线上行为不一致**

| 谁在跑 | 用的仓储实现 | VIP 1000 分下单的结果 |
| --- | --- | --- |
| 单元测试 | `InMemoryOrderRepository` | **成功** |
| 线上 | `RuleInRepositoryRepository`（规则被塞进 `save()`） | **拒绝：VIP 起送金额不足** |

**D) 事务边界写错层 → 队列路径留下半成品数据**

同一段订单编排代码、同一个非法入参（`qty = 0`，被 `CHECK (qty > 0)` 拦下）：

| 事务开在哪 | 调用方 | 明细插入失败后的报错 | orders 残留行数 |
| --- | --- | --- | ---: |
| Controller | HTTP 请求 | `PDOException(3819) Check constraint 'chk_qty' is violated.` | 0 |
| Controller | 队列消费（**没人开事务**） | 同上 | **1** |
| **Service（正确）** | 队列消费 | 同上 | **0** |

**E) 边界收益：Service 只依赖接口 → 没有数据库也能测**

- 用 `InMemoryOrderRepository` 跑 2 笔下单：**0.039 ms**，`orders` 表新增 **0** 行（全程没碰 MySQL）
- 断言拿到的数据：订单 1 `total=18000`，订单 2 `total=1900`（2000 分未达满减线，VIP 减 5%）
- 换成 `PdoOrderRepository`，**Service 一行不改**（A 段里同一份 Service 已在 MySQL 上跑通）

### 三、五个反直觉的点

**1. 事务边界属于 Service，既不属于 Controller 也不属于 Repository。**
这是分层题里最容易吵的一条。实测最能说明问题：**同一个 Service、同一个非法入参**，事务开在 Controller 时，HTTP 路径残留 0 行（因为 Controller 会 rollback），**队列路径残留 1 行脏数据**——因为队列消费者根本不知道要开事务。**"HTTP 路径看起来正常"是最大的陷阱**，它掩盖了那行脏数据。放 Service 里，三条路径都是 0。

**2. 越界最危险的形式不是报错，是静默算错。**
Service 里写 `$vip = (bool) ($_SERVER['HTTP_X_VIP'] ?? false);`，在 CLI/队列下 `$_SERVER['HTTP_X_VIP']` 不存在，但 `?? false` 把它**优雅地降级成"不是 VIP"**了——不抛异常、不进日志，只是**每一笔 VIP 订单都多收 5%**。实测 `total=9000`，正确值 8500。**带默认值的越界比直接崩掉危险得多。**

**3. "业务规则写进 Repository"的代价不是难维护，是单测会骗你。**
同一个 Service、同一个入参，`InMemoryOrderRepository` 说**成功**、`RuleInRepositoryRepository` 说**拒绝**。规则跟着**实现**走而不是跟着**业务**走，于是**测试环境和生产环境跑的根本不是同一套规则**。更糟的是：你的单测全绿。

**4. Controller 里复制的规则，差距不会自己收敛，只会越来越大。**
抄的那份漏了 VIP 分支，一开始只差 500 分。但两份规则从此各自演化：风控改起送线、商务改折扣率，改的人只会改他看得见的那份。**分层的意义不是"现在少 500 分"，而是"把'只有一份规则'这件事变成结构性保证"。**

**5. 接口定义在领域层，实现放在基础设施层——依赖箭头指向内层。**
`OrderRepository` 接口里出现 `beginTransaction()` / `commit()` / `rollBack()` 是可以的（`PdoOrderRepository` 提供），但接口里出现 `PDO` 类型、出现 SQL、出现 `LIMIT` 就不行。**判断标准：如果明天换成 MongoDB / 内存实现，这个接口要不要改？** 要改，说明实现细节漏进了领域层。本例的 `InMemoryOrderRepository` 实现了同一个接口且**没有事务概念**（无操作），Service 却能照常跑通——这就是边界干净的证据。

### 四、实战结论

| 症状 | 越界位置 | 最小改法 |
| --- | --- | --- |
| 两个入口算出两个价 | Controller 复制了规则 | 规则收回 `PricingPolicy`，Controller 只做类型转换 |
| 队列/定时任务跑不通、报 `Request` 相关错 | Service 依赖 HTTP | Service 入参改成普通数组 / DTO，出参返回数据不返回 `Response` |
| 单测绿、线上红（或反过来） | 规则写在 Repository 里 | 规则上移到领域层，Repository 只做存取 |
| 队列消费后出现半成品数据 | 事务开在 Controller | 事务边界移进 Service，三条入口统一 |
| Controller 200 行、Service 是空壳 | Service 层被跳过 | Controller 瘦到只做"取参 → 调 Service → 组装响应" |
| Service 里到处 `new PDO` / `new Redis` | 依赖具体实现 | 构造函数注入接口，容器绑定实现 |
| Repository 里有 `if ($amount < 10000)` | 业务规则下沉 | 规则上移；Repository 只回答"怎么存" |
| Service 之间互相调用成环 | 编排跨了用例 | 抽出更上层的用例服务，或改用事件解耦 |

**别过度分层**：只有一个"取一条记录直接返回"的接口，不需要 Service + Repository + DTO 三件套。**判据还是变更原因**——规则会不会变、存储会不会换、入口会不会多。三个都不会，一个 Controller 写完最省。

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| Controller 到底该有多薄？ | 薄到"取参、类型转换、调 Service、组装响应"，除此之外的 `if` 都要问一句：这是格式校验（留）还是业务规则（走） |
| 事务为什么不能放 Repository？ | Repository 的职责是"存取一条/一批"，它不知道一个用例涉及几笔存取；跨仓储的事务（本例 `orders` + `order_items`）它管不了 |
| 那跨仓储的事务怎么办？ | 归 Service 编排；真要跨库就用最终一致（Saga / 本地消息表），别硬上分布式事务 |
| Service 之间能互相调用吗？ | 能，但要单向。出现环就说明用例边界画错了，抽上层服务或改事件 |
| DTO 和数组怎么选？ | 入口固定、字段少用数组够；跨层传递、字段多变、要类型安全就上 DTO。**别为了"规范"给三个字段包一个类** |
| Repository 该返回数组还是模型对象？ | 领域层只依赖接口时返回领域对象/数组；直接用 Eloquent 模型会让上层耦合 ORM（`$model->save()` 就是隐藏的持久化） |
| Laravel 里 Repository 有必要吗？ | Eloquent 本身就是 Active Record + Repository 混合体。**规则简单就别套**；需要换存储、需要给领域层隔离 ORM 时才值得 |
| 领域异常怎么变成 HTTP 状态码？ | 在 `bootstrap/app.php` 的 `->withExceptions()` 里统一映射（`DomainException` → 409，`InvalidArgumentException` → 422），**别在 Controller 里逐个 catch** |
| 分层和微服务的边界是一回事吗？ | 同源。分层是"进程内的依赖方向"，微服务是"进程间的依赖方向"，都是让依赖指向稳定的一端 |
| 怎么防止后来的人破坏分层？ | 架构测试（PHPArkitect / Deptrac）写进 CI：禁止 `App\Http` 引用 `PDO`、禁止 `App\Domain` 引用 `Illuminate\Http`（**未实测**，本环境没装这两个工具） |
