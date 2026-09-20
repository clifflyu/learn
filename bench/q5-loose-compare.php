<?php
/**
 * Q5 实测：== 与 === 的经典坑
 *
 * 用法: docker exec learn-php php /app/bench/q5-loose-compare.php
 */

echo "PHP ", PHP_VERSION, "\n\n";

$cases = [
    '0 == "a"'            => fn() => 0 == 'a',
    '"1" == "01"'         => fn() => '1' == '01',
    '"10" == "1e1"'       => fn() => '10' == '1e1',
    '100 == "1e2"'        => fn() => 100 == '1e2',
    '100 == "100abc"'     => fn() => 100 == '100abc',
    '0 == ""'             => fn() => 0 == '',
    'null == false'       => fn() => null == false,
    'null == ""'          => fn() => null == '',
    '"0" == false'        => fn() => '0' == false,
    '[] == false'         => fn() => [] == false,
    '[] == null'          => fn() => [] == null,
    '"abc" == 0'          => fn() => 'abc' == 0,
    '(0.1+0.2) == 0.3'    => fn() => (0.1 + 0.2) == 0.3,
];

printf("%-24s %-8s %s\n", '表达式', '结果', '说明');
printf("%s\n", str_repeat('-', 66));
foreach ($cases as $expr => $fn) {
    $r = $fn();
    printf("%-24s %-8s %s\n", $expr, $r ? 'true' : 'false', '');
}

echo "\n=== 真实事故形态 ===\n";

// 1. strpos 返回 0（找到了，位置 0）与 false（没找到）松散比较相等
$pos = strpos('abc', 'a');
printf("strpos('abc','a')      = %s (int)   松散 == false → %s\n",
    var_export($pos, true), $pos == false ? 'true ← 误判为没找到' : 'false');

// 2. in_array 不传 strict
printf("in_array(0, ['a','b'])              → %s\n",
    in_array(0, ['a', 'b']) ? 'true ← 误命中' : 'false');
printf("in_array(0, ['a','b'], true)        → %s\n",
    in_array(0, ['a', 'b'], true) ? 'true' : 'false');

// 3. 魔法哈希：两个「科学计数法样」的字符串会被当数字比，都等于 0 → 恒等
//    这是 == 仍未被 PHP 8 修掉的坑，密码/签名比较绝不能用 ==
printf("'0e12345' == '0e67890'              → %s  ← 魔法哈希，PHP 8 仍未修\n",
    '0e12345' == '0e67890' ? 'true' : 'false');
printf("hash_equals('0e12345','0e67890')    → %s  ← 正确姿势\n",
    hash_equals('0e12345', '0e67890') ? 'true' : 'false');

// 4. 排序 / 去重
$ids = ['1', '01', 1, true];
printf("array_unique(['1','01',1,true])     → %s\n", json_encode(array_values(array_unique($ids))));
printf("array_unique(..., SORT_REGULAR) 后  → %s\n",
    json_encode(array_values(array_unique(['1', '01', 1, true], SORT_REGULAR))));

// 5. 正确姿势
echo "\n=== 正确姿势 ===\n";
printf("strpos 用 !== false : %s\n", strpos('abc', 'a') !== false ? 'true' : 'false');
printf("比较字符串统一转 string: %s\n", (string) 1 === (string) '1' ? 'true' : 'false');
