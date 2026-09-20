<?php
/**
 * Q44 实测②：用一段 sleep 模拟「下游接口变慢」。
 *
 * 用法: curl 'http://localhost:9311/bench/ops/q44-downstream.php?ms=800'
 *
 * 真实场景里它就是支付网关 / 风控 / 用户中心 / 第三方 API，
 * 慢的原因你控制不了，但你要能证明「慢的是它，不是我」。
 */

$ms = max(0, (int) ($_GET['ms'] ?? 800));

usleep($ms * 1000);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['downstream' => 'ok', 'slept_ms' => $ms], JSON_UNESCAPED_UNICODE), "\n";
