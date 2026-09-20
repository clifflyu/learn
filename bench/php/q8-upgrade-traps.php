<?php
/**
 * Q8 实测：从 7.3 升到 8.4 会踩到的「弃用 / 移除」清单
 *
 * 题目的后半句是「升级要注意什么」，那就得知道每个坑从哪个版本开始报、报的是
 * Deprecated（还能跑）还是 Error（直接挂）。文档里的清单很长，这里只放**真跑出来**的。
 *
 * 每条都丢给 `php -r` 子进程：有些是编译期错误，catch 不住，会把整轮探测打断。
 * 父进程只负责分类：OK / Deprecated / Warning / Error(致命)。
 *
 * 用法:
 *   docker exec learn-php php /app/bench/php/q8-upgrade-traps.php
 *   bash /opt/learn/bench/php/q8-upgrade-traps.sh    # 六个版本一起跑并转成表
 */

printf("PHP|%s\n", PHP_VERSION);

/**
 * @param string $label 中文标签
 * @param string $code  不含 <?php 的代码
 */
function probe($label, $code)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r '
         . escapeshellarg($code . '; echo "|OK";') . ' 2>&1';
    $out = (string) shell_exec($cmd);

    if (strpos($out, '|OK') !== false && stripos($out, 'Deprecated') === false
        && stripos($out, 'Warning') === false && stripos($out, 'Notice') === false) {
        printf("%s|OK\n", $label);
        return;
    }

    // 找出最能说明问题的一行，并分类
    $kind = '?';
    $msg  = '';
    foreach (explode("\n", $out) as $l) {
        if ($l === '' || strpos($l, '|OK') !== false) { continue; }
        if (stripos($l, 'Deprecated') !== false && $kind === '?') { $kind = 'Deprecated'; }
        elseif (stripos($l, 'Fatal error') !== false) { $kind = 'Error'; }
        elseif (stripos($l, 'Uncaught') !== false && $kind !== 'Error') { $kind = 'Error'; }
        elseif (stripos($l, 'Warning') !== false && $kind === '?') { $kind = 'Warning'; }
        elseif (stripos($l, 'Notice') !== false && $kind === '?') { $kind = 'Notice'; }
        if ($msg === '') { $msg = $l; }
    }
    $msg = preg_replace('/^PHP /', '', $msg);
    $msg = preg_replace('/ in \/.*? on line \d+.*$/', '', $msg);
    $msg = preg_replace('/ in Command line code on line \d+.*$/', '', $msg);
    $msg = preg_replace('/\s+/', ' ', $msg);
    if (strlen($msg) > 60) { $msg = substr($msg, 0, 60) . '...'; }
    printf("%s|%s|%s\n", $label, $kind, $msg);
}

echo "\n== 升级会用到的坑 ==\n";

// 1. 动态属性（8.2 起弃用）
probe('给未声明属性赋值',
    'class C1 {} $o = new C1(); $o->x = 1; echo $o->x;');

// 1b. 加上 #[AllowDynamicProperties] 还报不报（属性单独占一行 —— 这一行在 7.3 上只是注释）
probe('  └ 属性写在类声明上一行',
    "class C3 {}\n#[AllowDynamicProperties]\nclass C3b {}\n\$o = new C3b(); \$o->x = 1; echo \$o->x;");

// 1c. 属性跟类声明写在同一行：7.3 上 `#` 是**行注释**，会把 class 声明一起吞掉
//     （第一版探测就是这么写的，7.3 上「没输出、没报错、退出码 0」，静默失效）
probe('  └ 属性与 class 写同一行',
    "class C4a { } #[AllowDynamicProperties] class C4b {}\nif (!class_exists('C4b')) { throw new Exception('class 声明被注释吞掉了'); }");

// 2. ${var} 插值（8.2 起弃用）
probe('字符串 "${foo}" 插值',
    '$foo = "bar"; $s = "x ${foo} y"; if ($s !== "x bar y") { throw new Exception("wrong"); }');

// 3. ${expr} / ${$name} 插值（8.2 起直接移除）
probe('字符串 "${$name}" 动态插值',
    '$name = "foo"; $foo = "bar"; $s = "x ${$name} y"; if ($s !== "x bar y") { throw new Exception("wrong"); }');

// 4. 内部函数传 null（8.1 起弃用）
probe('strlen(null)',
    'if (strlen(null) !== 0) { throw new Exception("wrong"); }');

// 5. 隐式可空参数（8.4 起弃用）
probe('参数 int $x = null',
    'function f5(int $x = null) { return $x; } if (f5() !== null) { throw new Exception("wrong"); }');

// 6. 浮点数组键隐式转 int（8.1 起弃用）
probe('数组键写成浮点 1.7',
    '$a = array(); $a[1.7] = 1; if (count($a) !== 1) { throw new Exception("wrong"); }');

// 7. assert 传字符串（8.0 起不再支持）
probe('assert("字符串")',
    'assert("1 == 1");');

// 8. utf8_encode / utf8_decode（8.2 起弃用）
probe('utf8_encode',
    'if (utf8_encode("a") !== "a") { throw new Exception("wrong"); }');

// 9. strftime（8.1 起弃用）
probe('strftime',
    'if (strlen(strftime("%Y")) !== 4) { throw new Exception("wrong"); }');

// 10. Serializable 接口（8.1 起弃用）
probe('实现 Serializable',
    'class C10 implements Serializable { public function serialize() { return ""; } public function unserialize($d) {} } echo "ok";');

// 11. 传引用给内部函数（8.0 起弃用）：必须是**非变量**才算踩坑，传变量一直合法
probe('end() 传表达式',            // 变量：一直合法
    '$a = array(1, 2); if (end($a) !== 2) { throw new Exception("wrong"); }');
probe('end(explode(...)) 非变量',  // 非变量：8.0 起弃用
    'if (end(explode(",", "a,b")) !== "b") { throw new Exception("wrong"); }');

// 12. mbstring 编码名（8.1 起弃用：用 UTF-8 而不是 utf8）
probe('mb_strlen("a", "utf8")',
    'if (mb_strlen("ab", "utf8") !== 2) { throw new Exception("wrong"); }');

// 13. 可选参数在必填参数之前（8.0 起弃用）
probe('可选参数写在必填前',
    'function f13($a = 1, $b) { return $a + $b; } if (f13(1, 2) !== 3) { throw new Exception("wrong"); }');

// 14. mcrypt（7.2 起移除，用 openssl 替代）—— 镜像是精简构建，先看扩展在不在
probe('mcrypt_encrypt',
    'if (function_exists("mcrypt_encrypt")) { echo "exists"; } else { throw new Exception("扩展不存在"); }');
