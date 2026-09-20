<?php
/**
 * Q3 实测用的代码 fixtures：生成 500 个类文件，每个类 60 个方法，
 * 体量接近一个真实框架的单个源文件（约 130 行）。
 */

function ensure_fixtures(string $dir, int $files = 500, int $methods = 60): void
{
    if (is_dir($dir) && count(glob("$dir/c*.php")) === $files) {
        return;
    }
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $body = '';
    for ($m = 0; $m < $methods; $m++) {
        $body .= "    /** 方法 {$m}：返回结构化数据 */\n"
              . "    public function m$m(int \$x, string \$s): array\n"
              . "    {\n"
              . "        \$r = ['x' => \$x + $m, 's' => \$s, 'm' => $m];\n"
              . "        if (\$x % 2 === 0) { \$r['even'] = true; }\n"
              . "        return \$r;\n"
              . "    }\n\n";
    }

    for ($i = 1; $i <= $files; $i++) {
        file_put_contents("$dir/c$i.php", "<?php\n\nclass C$i\n{\n$body}\n");
    }
}
