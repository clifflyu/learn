<?php
/**
 * Q45 实测：FPM worker 的 VmRSS 曲线。
 *
 * 三种模式：
 *   ?mode=peak&kb=40960  分配 40MB 再释放。RSS 会被顶到「历史峰值」然后不再下降，
 *                        后续请求复用这块内存 —— 这是「阶梯式上升后走平」，不是泄漏
 *   ?mode=leak&kb=4096   函数内 static 数组追加。用来验证「FPM 里 static 会不会跨请求存活」
 *   ?mode=clean          对照组
 *
 * 响应里带 pid、VmRSS、以及 static 数组的长度 —— static_n 恒为 1 就说明它每个请求都被重置。
 * 用法: curl 'http://localhost:9311/bench/ops/q45-leak-web.php?mode=peak&kb=40960'
 */

/** static 挂在 worker 进程上；如果跨请求存活，count 会一路涨上去 */
function leak_append(int $bytes): int
{
    static $cache = [];
    $cache[] = str_repeat('y', $bytes);
    return count($cache);
}

function rss(): int
{
    $s = @file_get_contents('/proc/self/status');
    return preg_match('/VmRSS:\s+(\d+) kB/', $s, $m) ? (int) $m[1] * 1024 : -1;
}

$mode = $_GET['mode'] ?? 'clean';
$kb   = max(1, (int) ($_GET['kb'] ?? 512));

$static_n = 0;
$peak_kb  = 0;

switch ($mode) {
    case 'peak':
        // 模拟一个「偶尔来一次」的重请求：查出一大批记录放进数组，处理完就释放。
        // 注意不要用一个巨大的字符串来模拟 —— 超过 ZEND_MM_CHUNK_SIZE(2MB) 的分配
        // 会走 mmap 直接还给内核，RSS 反而不会留下高水位。要的是「很多个小块」，
        // 它们走 Zend 的 chunk 分配器，chunk 一旦拿到就不会还给 OS。
        $rows = [];
        $chunk = 64 * 1024;                                  // 64KB 一块，走 chunk 分配器
        for ($i = 0; $i < intdiv($kb * 1024, $chunk); $i++) {
            $rows[] = str_repeat('z', $chunk);
        }
        $peak_kb = intdiv(memory_get_peak_usage(true), 1024);
        unset($rows);                      // 立刻释放
        break;

    case 'leak':
        $static_n = leak_append($kb * 1024);
        break;

    case 'clean':
    default:
        break;
}

header('Content-Type: text/plain; charset=utf-8');
printf("mode=%-5s pid=%-6d rss_kb=%-8d mem_kb=%-8d static_n=%d\n",
    $mode, getmypid(), intdiv(rss(), 1024), intdiv(memory_get_usage(true), 1024), $static_n);
