<?php
/**
 * 查清楚哪个"行数"计数器在 SESSION 级别可信
 * 背景：11 脚本里 Innodb_rows_read 的数字忽大忽小（34,695 / 21,430 / 30,000），
 *       要么计数器是全局的（会被别的会话污染），要么它统计的不是我以为的东西。
 * 做法：跑一个行数完全已知的工作负载，看哪些计数器给出正确的确定值。
 */
$pdo = new PDO('mysql:host=learn-mysql;port=3306;dbname=q10_n1;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

const VARS = [
    'Innodb_rows_read',
    'Handler_read_key',
    'Handler_read_next',
    'Handler_read_rnd_next',
    'Handler_read_rnd',
    'Handler_read_first',
    'Com_select',
    'Select_scan',
];

function sstat(PDO $pdo, string $name): int
{
    return (int) $pdo->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_NUM)[1];
}

function snap(PDO $pdo): array
{
    return array_map(fn($v) => sstat($pdo, $v), VARS);
}

/** 已知工作量：$k 次「按 user_id 取 5 行」 */
function workload(PDO $pdo, int $k): void
{
    $s = $pdo->prepare('SELECT id, title FROM posts WHERE user_id = ?');
    for ($i = 0; $i < $k; $i++) {
        $s->execute([$i + 1]);
        $s->fetchAll();
    }
}

printf("PHP %s / MySQL %s\n\n", PHP_VERSION, $pdo->query('SELECT VERSION()')->fetch(PDO::FETCH_NUM)[0]);
echo "工作量：按 user_id 查 5 行，重复 k 次；预期命中 5k 行（索引 idx_posts_user_id）\n";
echo "预期：Handler_read_key = k（每次走一次索引查找），Handler_read_next = 5k（索引顺序读）\n\n";

// 三次重复，看哪个计数器的增量稳定
$hdr = sprintf("%-22s %8s %8s %8s   %s", '计数器', 'k=1', 'k=10', 'k=100', '是否稳定');
echo $hdr . "\n" . str_repeat('-', 72) . "\n";

$res = [];
foreach ([1, 10, 100] as $k) {
    $before = snap($pdo);
    workload($pdo, $k);
    $after = snap($pdo);
    foreach (VARS as $i => $name) {
        $res[$name][$k] = $after[$i] - $before[$i];
    }
}

foreach (VARS as $name) {
    $v = $res[$name];
    $stable = ($v[10] === 10 * $v[1] && $v[100] === 100 * $v[1]) ? '✔ 线性，可信' : '✘ 不线性';
    printf("%-22s %8d %8d %8d   %s\n", $name, $v[1], $v[10], $v[100], $stable);
}

echo "\n重复同一工作量 5 次（k=10），看每个计数器增量的波动：\n\n";
printf("%-22s %s\n", '计数器', '5 次增量');
foreach (VARS as $name) {
    $deltas = [];
    for ($r = 0; $r < 5; $r++) {
        $b = sstat($pdo, $name);
        workload($pdo, 10);
        $deltas[] = sstat($pdo, $name) - $b;
    }
    printf("%-22s %s%s\n", $name, implode(', ', $deltas),
        count(array_unique($deltas)) === 1 ? '   ✔ 稳定' : '   ✘ 抖动（会被其他会话污染）');
}
