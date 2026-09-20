<?php
/**
 * Q44 实测：一个「没有扩展也能定位到行」的慢请求。
 *
 * 调用栈故意做成 Controller → Service → Repository → Query 四层，
 * 每层都有循环，好让 FPM 自带的 slowlog 打出来的栈能看出「卡在哪一层、哪一行」。
 *
 * 用法: curl 'http://localhost:9311/bench/ops/q44-slowreq.php?rows=1500'
 *       rows 每加 1 大约多 0.94ms（实测，真实计算不是 usleep —— sleep 的调用栈
 *       只有一行 usleep，演示不出「哪一行代码」）
 */

/** 第四层：模拟「一条查询本该走索引却走了全表扫」的开销 */
function query_layer(int $rows): float
{
    $t = 0.0;
    for ($i = 0; $i < $rows; $i++) {
        // 用真实 CPU 工作代替 sleep：排序一个不断变大的数组
        $data = range(1, 4000);
        rsort($data);
        $t += $data[0];
    }
    return $t;
}

/** 第三层：仓储，N+1 的循环就在这里 */
function repository_layer(int $n): float
{
    $sum = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sum += query_layer(1);      // ← 第 36 行：循环里发查询
    }
    return $sum;
}

/** 第二层：业务逻辑 */
function service_layer(int $n): float
{
    $total = repository_layer($n);
    return $total * 2;
}

/** 第一层：控制器 */
function controller_layer(int $n): float
{
    return service_layer($n) + 1;
}

$rows = max(1, (int) ($_GET['rows'] ?? 40));
$t0   = microtime(true);
$sum  = controller_layer($rows);
$ms   = (microtime(true) - $t0) * 1000;

header('Content-Type: text/plain; charset=utf-8');
printf("pid=%d rows=%d 耗时=%.0fms\n", getmypid(), $rows, $ms);
