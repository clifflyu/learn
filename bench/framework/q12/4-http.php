<?php
/**
 * Q12 分层：HTTP / Delivery 层 + 三种典型越界写法
 *   - OrderController：只做「取参 → 调 Service → 组装响应」，没有 SQL、没有业务规则
 *   - BadOrderController1：跳过 Service，Controller 里直接写 SQL + 复制一遍折扣规则（越界 A）
 *   - RequestBoundService：Service 里读 HTTP 请求、返回 HTTP Response（越界 B）
 */

final class Request
{
    public function __construct(private array $input = [], private array $headers = []) {}

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[$key] ?? $default;
    }
}

final class JsonResponse
{
    public function __construct(public int $status, public array $body) {}

    public function send(): void
    {
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// ---------- 正确写法 ----------
final class OrderController
{
    public function __construct(private PlaceOrderService $service) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $result = $this->service->place([
                'user_id' => (int) $request->input('user_id'),
                'amount'  => (int) $request->input('amount'),
                'vip'     => (bool) $request->input('vip', false),
                'sku'     => (string) $request->input('sku', 'SKU-1'),
                'qty'     => (int) $request->input('qty', 1),
            ]);

            return new JsonResponse(200, $result);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(422, ['error' => $e->getMessage()]);
        }
    }
}

// ---------- 越界 A：Controller 里自己写 SQL、自己复制一份折扣规则 ----------
final class BadOrderController
{
    public function __construct(private PDO $pdo) {}

    public function store(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id');
        $amount = (int) $request->input('amount');

        // 折扣规则被复制了一遍，而且抄漏了 VIP 那一档
        $discount = $amount >= 10000 ? (int) round($amount * 1000 / 10000) : 0;
        $total = $amount - $discount;

        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (id, user_id, amount, discount, total, status, created_at)
             VALUES ((SELECT * FROM (SELECT COALESCE(MAX(id),0)+1 FROM orders) t), ?,?,?,?,?,NOW())'
        );
        $stmt->execute([$userId, $amount, $discount, $total, 'placed']);

        return new JsonResponse(200, ['order_id' => (int) $this->pdo->lastInsertId(), 'total' => $total]);
    }
}

// ---------- 越界 B：Service 认识 HTTP ----------
final class RequestBoundService
{
    public function __construct(private OrderRepository $orders, private PricingPolicy $pricing) {}

    /** 从超全局变量里取用户，返回一个 HTTP Response —— 队列、CLI、定时任务全都用不了 */
    public function placeFromGlobals(array $input): JsonResponse
    {
        $vip = (bool) ($_SERVER['HTTP_X_VIP'] ?? false);          // 只有 HTTP 请求里才有这个键
        $order = new Order((int) $input['user_id'], (int) $input['amount'], $vip);
        $quote = $this->pricing->quote($order);

        return new JsonResponse(200, ['total' => $quote->total, 'vip' => $vip]);
    }
}
