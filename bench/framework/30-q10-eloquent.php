<?php
/**
 * Q10 的 ORM 侧：用真实 Eloquent（Laravel 13.32.0 的 illuminate/database）复现 N+1，
 * 并演示三种"发现"手段和两种"解决"手段。
 *
 * 跑法：docker exec learn-php php /app/bench/framework/30-q10-eloquent.php [n]
 * 依赖：/tmp/lara/vendor（composer create-project 装的 Laravel 骨架）
 */
require '/tmp/lara/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;

$n = (int) ($argv[1] ?? 1000);

$capsule = new Capsule;
$capsule->addConnection([
    'driver'   => 'mysql',
    'host'     => 'learn-mysql',
    'port'     => 3306,
    'database' => 'q10_n1',
    'username' => 'root',
    'password' => 'root',
    'charset'  => 'utf8mb4',
]);
$capsule->setEventDispatcher(new Illuminate\Events\Dispatcher(new Illuminate\Container\Container));
$capsule->setAsGlobal();
$capsule->bootEloquent();

class User extends Model
{
    protected $table = 'users';
    public $timestamps = false;

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

class Post extends Model
{
    protected $table = 'posts';
    public $timestamps = false;
}

$conn = Capsule::connection();
$conn->enableQueryLog();

function countQueries(): int
{
    $n = count(Capsule::connection()->getQueryLog());
    Capsule::connection()->flushQueryLog();

    return $n;
}

printf("Laravel framework: %s\n", Illuminate\Foundation\Application::VERSION);
printf("PHP %s / MySQL %s / N=%d\n", PHP_VERSION, $conn->selectOne('SELECT VERSION() AS v')->v, $n);
$hasIdx = (bool) $conn->select("SHOW INDEX FROM posts WHERE Key_name = 'idx_posts_user_id'");
printf("posts.user_id 索引：%s（11-q10-n1.php noindex 模式会把它摘掉，跑本脚本前先跑一次 index 模式）\n\n",
    $hasIdx ? '有' : '**无** ← 数字会失真');

$rows = 0;
$sum  = 0;

// ---------- 懒加载（N+1） ----------
$t0 = hrtime(true);
foreach (User::limit($n)->get() as $u) {
    foreach ($u->posts as $p) {   // 每次循环触发一条 SELECT
        $rows++;
        $sum += strlen($p->title);
    }
}
$lazyMs = (hrtime(true) - $t0) / 1e6;
$lazyQ = countQueries();

// ---------- 预加载（with） ----------
$t0 = hrtime(true);
$x = 0;
foreach (User::with('posts')->limit($n)->get() as $u) {
    foreach ($u->posts as $p) {
        $x++;
    }
}
$eagerAllMs = (hrtime(true) - $t0) / 1e6;
$eagerAllQ = countQueries();

// 只看一条 SQL 的形态：with 打开时 Laravel 实际发了什么
$conn->enableQueryLog();
User::with('posts')->limit(3)->get();
$sqls = array_map(fn($l) => $l['query'], $conn->getQueryLog());
$conn->flushQueryLog();

echo "## Eloquent 懒加载 vs 预加载\n\n";
printf("| 写法 | 查询次数 | 耗时 ms | 说明 |\n| --- | ---: | ---: | --- |\n");
printf("| `foreach (User::limit(N)->get() as \$u) { \$u->posts; }` | **%d** | %.1f | 1 + N |\n", $lazyQ, $lazyMs);
printf("| `User::with('posts')->limit(N)->get()` | **%d** | %.1f | 2 |\n", $eagerAllQ, $eagerAllMs);
printf("\nN=%d，行数校验：懒加载 %d 行，预加载 %d 行，%s\n", $n, $rows, $x, $rows === $x ? '一致' : '不一致！');

echo "\n## `with()` 打开后 Laravel 实际发出的 SQL（N=3）\n\n```sql\n";
foreach ($sqls as $s) {
    echo $s . ";\n";
}
echo "```\n";

// ---------- 发现手段 1：DB::listen ----------
echo "\n## 发现手段 1：`DB::listen` 打点\n\n```php\n";
$seen = [];
$conn->listen(function ($query) use (&$seen) {
    $sql = preg_replace('/\s+/', ' ', $query->sql);
    $seen[$sql] = ($seen[$sql] ?? 0) + 1;
});
User::limit(50)->get()->each(fn($u) => $u->posts);
$conn->flushQueryLog();
echo "// 50 个用户跑完后，同一个 SQL 模板出现了几次：\n";
foreach ($seen as $sql => $c) {
    printf("// %5d 次  %s\n", $c, mb_strimwidth($sql, 0, 70, '...'));
}
echo "```\n";

// ---------- 发现手段 2：preventLazyLoading ----------
echo "\n## 发现手段 2：`Model::preventLazyLoading()`（非生产环境强制拦截）\n\n```text\n";
Model::preventLazyLoading(true);
try {
    foreach (User::limit(5)->get() as $u) {
        $u->posts;   // 触发
    }
    echo "没有抛异常（意外）\n";
} catch (LazyLoadingViolationException $e) {
    echo "LazyLoadingViolationException: " . $e->getMessage() . "\n";
}
Model::preventLazyLoading(false);
echo "```\n";

// ---------- 解决手段：withCount / 只取需要的列 ----------
echo "\n## 解决手段：`withCount` 与「只要计数也要走 N+1」的对比\n\n";
$conn->enableQueryLog();
$t0 = hrtime(true);
$users = User::withCount('posts')->limit($n)->get();
$cntMs = (hrtime(true) - $t0) / 1e6;
printf("`User::withCount('posts')->limit(%d)->get()`：%d 次查询，%.1f ms，第一个用户 posts_count=%d\n",
    $n, countQueries(), $cntMs, $users->first()->posts_count);

$conn->enableQueryLog();
$t0 = hrtime(true);
$users = User::limit($n)->get();
$c = 0;
foreach ($users as $u) {
    $c += $u->posts()->count();   // 即使只要计数，也还是 N+1
}
$badMs = (hrtime(true) - $t0) / 1e6;
printf("`\$u->posts()->count()` 循环里取计数：%d 次查询，%.1f ms\n", countQueries(), $badMs);
