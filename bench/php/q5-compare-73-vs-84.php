<?php
/**
 * Q5 实测：== 与 === 的行为，PHP 7.3 vs PHP 8.4 差分
 *
 * 刻意用 PHP 7.3 兼容语法（不用箭头函数 / match / nullsafe / 类型声明），
 * 这样同一份代码能在两个版本上跑出可直接 diff 的输出。
 * 输出格式固定为 `标签|结果`，方便在宿主机上 join 成对照表。
 *
 * 用法:
 *   docker run --rm --entrypoint php -v /opt/learn:/app \
 *     docker.m.daocloud.io/webdevops/php-nginx:7.3 /app/bench/php/q5-compare-73-vs-84.php
 *   docker exec learn-php php /app/bench/php/q5-compare-73-vs-84.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "PHP|", PHP_VERSION, "\n";

/* ---------- 1. 松散比较 vs 严格比较 ---------- */

$cases = array(
    '0 == "a"'              => function () { return 0 == 'a'; },
    '0 === "a"'             => function () { return 0 === 'a'; },
    '100 == "100abc"'       => function () { return 100 == '100abc'; },
    '100 == "1e2"'          => function () { return 100 == '1e2'; },
    '"1" == "01"'           => function () { return '1' == '01'; },
    '"10" == "1e1"'         => function () { return '10' == '1e1'; },
    '"abc" == 0'            => function () { return 'abc' == 0; },
    '"1 " == 1 (尾空格)'     => function () { return '1 ' == 1; },
    '" 1" == 1 (前空格)'     => function () { return ' 1' == 1; },
    '"1_0" == 10'           => function () { return '1_0' == 10; },
    '0 == ""'               => function () { return 0 == ''; },
    'null == false'         => function () { return null == false; },
    'null == ""'            => function () { return null == ''; },
    '"0" == false'          => function () { return '0' == false; },
    '[] == false'           => function () { return array() == false; },
    '[] == null'            => function () { return array() == null; },
    '"" == false'           => function () { return '' == false; },
    '1 == true'             => function () { return 1 == true; },
    '100 == 100.0'          => function () { return 100 == 100.0; },
    '(0.1+0.2) == 0.3'      => function () { return (0.1 + 0.2) == 0.3; },
    '"0e1" == "0e2"'        => function () { return '0e1' == '0e2'; },
    'INF == "INF"'          => function () { return INF == 'INF'; },
    'NAN == NAN'            => function () { return NAN == NAN; },
);

foreach ($cases as $label => $fn) {
    $r = $fn();
    echo $label, '|', $r ? 'true' : 'false', "\n";
}

/* ---------- 2. 松散比较的传染面：受 == 影响的函数 ---------- */

echo "--- 传染面 ---\n";

// in_array / array_search 默认松散
$hay = array('a', 'b', '1', '01');
echo 'in_array(0, ["a","b","1","01"])|', in_array(0, $hay) ? 'true' : 'false', "\n";
echo 'array_search(0, ["a","b"])|', var_export(array_search(0, array('a', 'b')), true), "\n";

// switch 用的是 ==
$hit = 'no';
switch (0) {
    case 'a': $hit = 'matched "a"'; break;
    case 'b': $hit = 'matched "b"'; break;
    default:  $hit = 'default';
}
echo 'switch(0) case "a"|', $hit, "\n";

// array_unique 默认 SORT_STRING
echo 'array_unique(["1","01",1,true])|', json_encode(array_values(array_unique(array('1', '01', 1, true)))), "\n";
echo 'array_unique(SORT_REGULAR)|', json_encode(array_values(array_unique(array('1', '01', 1, true), SORT_REGULAR))), "\n";

// sort 的默认行为：数字与字符串混合
$mixed = array(10, '9', 'abc', 2, '2');
sort($mixed);
echo 'sort([10,"9","abc",2,"2"])|', json_encode($mixed), "\n";

// strpos 返回值用 == 判断
$pos = strpos('abc', 'a');
echo 'strpos("abc","a") == false|', ($pos == false) ? 'true' : 'false', "\n";
echo 'strpos("abc","a") === false|', ($pos === false) ? 'true' : 'false', "\n";

/* ---------- 3. 魔法哈希 ---------- */

echo "--- 魔法哈希 ---\n";
echo 'md5 形态 "0e..." == "0e..."|', ('0e12345' == '0e67890') ? 'true' : 'false', "\n";
echo 'hash_equals 同串|', hash_equals('0e12345', '0e67890') ? 'true' : 'false', "\n";
echo 'real md5 前后缀|', (md5('240610708') == md5('QNKCDZO')) ? 'true' : 'false', "\n";
echo 'md5("240610708")|', md5('240610708'), "\n";
echo 'md5("QNKCDZO")|', md5('QNKCDZO'), "\n";
echo 'sha1 碰撞对|', (sha1('aaroZmOk') == sha1('aaK1STfY')) ? 'true' : 'false', "\n";

/* ---------- 4. 严格比较的边界 ---------- */

echo "--- === 边界 ---\n";
echo '1 === 1.0|', (1 === 1.0) ? 'true' : 'false', "\n";
echo '0 === -0.0|', (0 === -0.0) ? 'true' : 'false', "\n";
echo '0.0 === -0.0|', (0.0 === -0.0) ? 'true' : 'false', "\n";
echo 'NAN === NAN|', (NAN === NAN) ? 'true' : 'false', "\n";
echo '[] === []|', (array() === array()) ? 'true' : 'false', "\n";
echo '[1,2] === [2=>1,1=>2]|', (array(1, 2) === array(2 => 1, 1 => 2)) ? 'true' : 'false', "\n";

// 对象：== 比较属性，=== 比较是不是同一个实例
class P { public $v; public function __construct($v) { $this->v = $v; } }
$p1 = new P(1); $p2 = new P(1); $p3 = $p1;
echo '两个同值对象 ==|', ($p1 == $p2) ? 'true' : 'false', "\n";
echo '两个同值对象 ===|', ($p1 === $p2) ? 'true' : 'false', "\n";
echo '同一实例 ===|', ($p1 === $p3) ? 'true' : 'false', "\n";
