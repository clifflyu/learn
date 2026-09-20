<?php
/**
 * Q10 N+1 查询问题实测
 * 库 q10_n1（5000 users / 25000 posts，每人 5 篇）
 * 跑法：docker exec learn-php php /app/bench/framework/11-q10-n1.php [index|noindex]
 *
 * 三个计数口径：
 *   - exec   ：execute()/query() 的次数 —— 等于 Laravel query log / DB::listen 的口径
 *   - stmt   ：exec + prepare()
 *   - Questions：MySQL 自己的 Statement 计数（见 12-q10-counter-calibration.php 的刻度）
 *   - Handler_read_*：真正扫描/读取的行数（见 13-q10-rows-metric.php：Innodb_rows_read
 *     在 SESSION 级别是被污染的，同一个 50 行工作量能报出 1236~4052，只有 Handler_* 可信）
 */

const HOST = 'learn-mysql';
const DB   = 'q10_n1';
const RUNS = 5;

$mode = $argv[1] ?? 'index';

$pdo = new PDO(sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4', HOST, DB), 'root', 'root', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// 把 user_id 上的索引按需摘掉/装上（只动 q10_n1）
$has = $pdo->query("SHOW INDEX FROM posts WHERE Key_name = 'idx_posts_user_id'")->fetchAll();
if ($mode === 'noindex' && $has) {
    $pdo->exec('ALTER TABLE posts DROP INDEX idx_posts_user_id');
} elseif ($mode === 'index' && !$has) {
    $pdo->exec('ALTER TABLE posts ADD INDEX idx_posts_user_id (user_id)');
}
$idx = $pdo->query("SHOW INDEX FROM posts WHERE Key_name = 'idx_posts_user_id'")->fetchAll() ? '有' : '无';

final class Meter
{
    public int $exec = 0;
    public int $stmt = 0;

    public function __construct(private PDO $pdo) {}

    public function prepare(string $sql): PDOStatement
    {
        $this->stmt++;
        return $this->pdo->prepare($sql);
    }

    public function query(string $sql): PDOStatement
    {
        $this->exec++;
        $this->stmt++;
        return $this->pdo->query($sql);
    }

    public function stat(string $name): int
    {
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_NUM)[1];
    }

    /**
     * [Questions, Handler_read_key, Handler_read_next, Handler_read_rnd_next]
     * read_key  = 走了几次索引查找
     * read_next = 顺着索引读了多少行
     * rnd_next  = 全表扫描扫过多少行
     */
    public function snapshot(): array
    {
        return [
            $this->stat('Questions'),
            $this->stat('Handler_read_key'),
            $this->stat('Handler_read_next'),
            $this->stat('Handler_read_rnd_next'),
        ];
    }
}

/** 跑一个变体，返回 [校验和, 行数, exec, stmt, Questions 差值, 读行数, ms] */
function run(string $variant, PDO $pdo, Meter $m, int $n): array
{
    $rows = 0;
    $sum  = 0;
    $m->exec = $m->stmt = 0;
    $before = $m->snapshot();
    $t0 = hrtime(true);

    switch ($variant) {
        case 'N+1':
            // 1 次查用户 + N 次查各自的帖子（Eloquent 懒加载的真实形态）
            $users = $m->query("SELECT id, name FROM users ORDER BY id LIMIT $n")->fetchAll();
            $stmt  = $m->prepare('SELECT id, title FROM posts WHERE user_id = ?');
            foreach ($users as $u) {
                $stmt->execute([$u['id']]);
                $m->exec++;
                $m->stmt++;
                foreach ($stmt->fetchAll() as $p) {
                    $rows++;
                    $sum += strlen($p['title']);
                }
            }
            break;

        case 'IN 预加载':
            // 1 次查用户 + 1 次 IN 把帖子全捞回来，在 PHP 里按 user_id 分组
            $users = $m->query("SELECT id, name FROM users ORDER BY id LIMIT $n")->fetchAll();
            $ids   = array_column($users, 'id');
            $ph    = implode(',', array_fill(0, count($ids), '?'));
            $stmt  = $m->prepare("SELECT id, user_id, title FROM posts WHERE user_id IN ($ph)");
            $stmt->execute($ids);
            $m->exec++;
            $m->stmt++;
            $grouped = [];
            foreach ($stmt->fetchAll() as $p) {
                $grouped[$p['user_id']][] = $p;
            }
            foreach ($users as $u) {
                foreach ($grouped[$u['id']] ?? [] as $p) {
                    $rows++;
                    $sum += strlen($p['title']);
                }
            }
            break;

        case 'IN 分块 500':
            // 预加载，但每 500 个 id 一批（避免超长 IN）
            $users = $m->query("SELECT id, name FROM users ORDER BY id LIMIT $n")->fetchAll();
            $grouped = [];
            foreach (array_chunk(array_column($users, 'id'), 500) as $chunk) {
                $ph   = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $m->prepare("SELECT id, user_id, title FROM posts WHERE user_id IN ($ph)");
                $stmt->execute($chunk);
                $m->exec++;
                $m->stmt++;
                foreach ($stmt->fetchAll() as $p) {
                    $grouped[$p['user_id']][] = $p;
                }
            }
            foreach ($users as $u) {
                foreach ($grouped[$u['id']] ?? [] as $p) {
                    $rows++;
                    $sum += strlen($p['title']);
                }
            }
            break;

        case 'JOIN 单查':
            $stmt = $m->prepare(
                "SELECT p.id, p.title FROM (SELECT id FROM users ORDER BY id LIMIT $n) u
                 JOIN posts p ON p.user_id = u.id"
            );
            $stmt->execute();
            $m->exec++;
            $m->stmt++;
            foreach ($stmt->fetchAll() as $p) {
                $rows++;
                $sum += strlen($p['title']);
            }
            break;
    }

    $elapsed = (hrtime(true) - $t0) / 1e6; // ms
    $after   = $m->snapshot();

    return [
        'sum' => $sum, 'rows' => $rows,
        'exec' => $m->exec, 'stmt' => $m->stmt,
        'qdelta' => $after[0] - $before[0],
        'readkey' => $after[1] - $before[1],
        'readnext' => $after[2] - $before[2],
        'rndnext' => $after[3] - $before[3],
        'ms' => $elapsed,
    ];
}

// ---------- 校准 Questions 的常数偏移 ----------
// 空载走一遍 run() 的 snapshot 结构：增量只来自 "after" 那次 snapshot 里的 2 条 SHOW
$m = new Meter($pdo);
$m->snapshot();
$before0 = $m->snapshot();
$after0  = $m->snapshot();
$Q_OFFSET = $after0[0] - $before0[0];

echo "# Q10 N+1 实测\n\n";
echo '- PHP ' . PHP_VERSION . ' / MySQL ' . $pdo->query('SELECT VERSION()')->fetch(PDO::FETCH_NUM)[0] . "\n";
echo "- 数据集：users=5000，posts=25000（每人 5 篇），posts.user_id 索引：**{$idx}**，每次取最小/中位共 " . RUNS . " 轮\n";
echo "- 计数口径见 `12-q10-counter-calibration.php`：COM_QUERY / COM_STMT_EXECUTE 各计 1，"
   . "COM_STMT_PREPARE 计 0；在与正测完全相同的代码路径上空载跑一遍得到常数偏移 = {$Q_OFFSET}，"
   . "所以 'Questions − '{$Q_OFFSET} 应当正好等于自计数的 exec\n\n";

// 预热 buffer pool
run('N+1', $pdo, $m, 200);
run('IN 预加载', $pdo, $m, 200);

$variants = ['N+1', 'IN 预加载', 'IN 分块 500', 'JOIN 单查'];
$scales   = [100, 1000, 5000];

$time = [];
$meta = [];
foreach ($variants as $v) {
    foreach ($scales as $n) {
        $ts = [];
        $last = null;
        for ($i = 0; $i < RUNS; $i++) {
            $last = run($v, $pdo, $m, $n);
            $ts[] = $last['ms'];
        }
        sort($ts);
        $time[$v][$n] = [$ts[0], $ts[intdiv(count($ts), 2)]];
        $meta[$v][$n] = $last;
    }
}

// ---------- 一致性检查 ----------
foreach ($scales as $n) {
    $ref = $meta['N+1'][$n]['sum']; // 同一 N 下以 N+1 的结果为基准
    foreach ($variants as $v) {
        if ($meta[$v][$n]['sum'] !== $ref) {
            echo "!! 结果校验和不一致：{$v} @N={$n} 得 {$meta[$v][$n]['sum']}，期望 {$ref}\n\n";
        }
    }
}

echo "## 1) 语句数（exec=执行次数 / stmt=exec+prepare / Questions 原始差 −{$Q_OFFSET}）\n\n";
echo "| 写法 | 口径 | N=100 | N=1000 | N=5000 | 公式 |\n| --- | --- | ---: | ---: | ---: | --- |\n";
$formula = ['N+1' => '1+N 次 exec + 1 次 prepare', 'IN 预加载' => '2 次 exec + 2 次 prepare', 'IN 分块 500' => '1 + ⌈N/500⌉ 次 exec + 同样多次 prepare', 'JOIN 单查' => '1 次 exec + 1 次 prepare'];
foreach ($variants as $v) {
    $e = $s = $q = [];
    foreach ($scales as $n) {
        $d = $meta[$v][$n];
        $e[] = $d['exec'];
        $s[] = $d['stmt'];
        $q[] = $d['qdelta'] - $Q_OFFSET;
    }
    printf("| %s | **exec** | %s | %s |\n", $v, implode(' | ', $e), $formula[$v]);
    printf("| %s | stmt | %s | |\n", $v, implode(' | ', $s));
    printf("| %s | Questions | %s | |\n", $v, implode(' | ', $q));
}

echo "\n## 2) 扫描量（Handler_read_key 索引查找次数 / read_next 索引读行 / rnd_next 全表扫描行）\n\n";
echo "| 写法 | 指标 | N=100 | N=1000 | N=5000 |\n| --- | --- | ---: | ---: | ---: |\n";
foreach ($variants as $v) {
    foreach (['readkey' => '索引查找', 'readnext' => '索引读行', 'rndnext' => '全表扫描行'] as $k => $label) {
        $c = [];
        foreach ($scales as $n) {
            $c[] = number_format($meta[$v][$n][$k]);
        }
        printf("| %s | %s | %s |\n", $v, $label, implode(' | ', $c));
    }
}

echo "\n## 3) 耗时 ms（最小 / 中位）\n\n";
echo "| 写法 | N=100 | N=1000 | N=5000 |\n| --- | ---: | ---: | ---: |\n";
foreach ($variants as $v) {
    $c = [];
    foreach ($scales as $n) {
        [$min, $med] = $time[$v][$n];
        $c[] = sprintf('%.1f / %.1f', $min, $med);
    }
    printf("| %s | %s |\n", $v, implode(' | ', $c));
}

echo "\n## 4) 差分法：N=1000 → N=5000（ΔN=4000）\n\n";
echo "| 写法 | Δ耗时 ms | µs/用户 | Δ索引查找 | Δ索引读行 | Δ全表扫描行 | Δexec |\n| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";
foreach ($variants as $v) {
    $a = $meta[$v][1000];
    $b = $meta[$v][5000];
    $d = $time[$v][5000][0] - $time[$v][1000][0];
    printf("| %s | %.1f | %.1f | %s | %s | %s | %d |\n",
        $v, $d, $d * 1000 / 4000,
        number_format($b['readkey'] - $a['readkey']),
        number_format($b['readnext'] - $a['readnext']),
        number_format($b['rndnext'] - $a['rndnext']),
        $b['exec'] - $a['exec']);
}

echo "\n## 5) 结果一致性（同一 N 下 4 种写法返回的数据必须一模一样）\n\n";
foreach ($scales as $n) {
    $ref = $meta['N+1'][$n]['sum'];
    $cells = [];
    foreach ($variants as $v) {
        $d = $meta[$v][$n];
        $cells[] = sprintf('%s %d 行/%d %s', $v, $d['rows'], $d['sum'], $d['sum'] === $ref ? '✓' : '✗');
    }
    printf("- N=%d：%s\n", $n, implode('，', $cells));
}

echo "\n## 6) 真实发出去的 SQL\n\n```sql\n";
echo "-- 所有写法的第 1 条（1 次）\nSELECT id, name FROM users ORDER BY id LIMIT 5000;\n";
echo "-- N+1 的第 2 条：反复执行 5000 次，每次一个网络往返\nSELECT id, title FROM posts WHERE user_id = ?;   -- ? = 1,2,3,...,5000\n";
echo "-- 预加载的第 2 条：只 1 次\nSELECT id, user_id, title FROM posts WHERE user_id IN (1,2,3,...,5000);\n";
echo "-- JOIN：连用户带帖子一次拿完\nSELECT p.id, p.title FROM (SELECT id FROM users ORDER BY id LIMIT 5000) u JOIN posts p ON p.user_id = u.id;\n```\n";
