<?php
/**
 * 校准 MySQL 的 Questions 计数器：哪类语句计入、SHOW 自己算不算
 * 用法：docker exec learn-php php /app/bench/framework/12-q10-counter-calibration.php
 *
 * 目的：11-q10-n1.php 用 SHOW SESSION STATUS 当"查询次数"的裁判，
 *       必须先搞清楚这杆秤的刻度，否则测出来的次数是错的。
 */
$pdo = new PDO('mysql:host=learn-mysql;port=3306;dbname=q10_n1;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

function questions(PDO $pdo): int
{
    return (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
}

/**
 * 结构固定为：a = questions();  <被测动作>  ; b = questions();
 * 若模型为「b 的读数不含 b 自己这条 SHOW，且每条 SHOW 记 1」，
 * 则返回值应恒等于 1 + 动作里的语句数。
 */
function delta(PDO $pdo, callable $fn): int
{
    $a = questions($pdo);
    $fn();
    return questions($pdo) - $a;
}

$cases = [];

$cases['无动作（只剩 a 那条 SHOW）'] = [0, fn() => null];

foreach ([1, 3, 10] as $k) {
    $cases["{$k} 条 query()（COM_QUERY）"] = [$k, function () use ($pdo, $k) {
        for ($i = 0; $i < $k; $i++) {
            $pdo->query('SELECT 1')->fetchAll();
        }
    }];
}

$cases['1 次 prepare()（只 prepare 不 execute）'] = [0, function () use ($pdo) {
    $pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
}];

foreach ([1, 3, 10] as $k) {
    $cases["prepare + {$k} 次 execute()"] = [$k, function () use ($pdo, $k) {
        $s = $pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
        for ($i = 0; $i < $k; $i++) {
            $s->execute([$i]);
            $s->fetchAll();
        }
    }];
}

printf("PHP %s / MySQL %s\n\n", PHP_VERSION, $pdo->query('SELECT VERSION()')->fetch(PDO::FETCH_NUM)[0]);
printf("%-42s %8s %8s %8s\n", '被测动作', '净语句数', '实测增量', '增量-1');
printf("%s\n", str_repeat('-', 70));
foreach ($cases as $label => [$net, $fn]) {
    $d = delta($pdo, $fn);
    printf("%-42s %8d %8d %8d%s\n", $label, $net, $d, $d - 1, ($d - 1 === $net) ? '  ✓' : '  ✗');
}

echo "\n结论（刻度）：\n";
echo "  1) 上面每一行都是「a = SHOW Questions; 动作; b = SHOW Questions」的结构，\n";
echo "     差值恒为 1 + 净语句数 —— 即每次读计数器本身会带进 1 条 SHOW，测量时必须减掉\n";
echo "  2) COM_QUERY（query()）计 1，COM_STMT_EXECUTE（execute()）计 1\n";
echo "  3) COM_STMT_PREPARE（prepare()）**不计入 Questions** —— 这是最容易算错的一条，\n";
echo "     所以 11 脚本里额外用自计数的 exec / stmt 两列做交叉验证\n";
