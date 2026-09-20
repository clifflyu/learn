<?php
/**
 * Q12 分层：Application / Service 层
 *   - 只做「编排 + 事务边界」：取号 → 报价 → 落库 → 明细
 *   - 入参出参全是领域对象/标量，不认识 Request / Response / Session
 *   - 事务边界在 Service：因为「一次下单」这件事的原子性属于业务，不属于 HTTP
 *
 * 换成 InMemoryOrderRepository 也能跑（见 5-run.php 的 0 查询测试），
 * 这就是「Service 只依赖接口」的价值。
 */

final class PlaceOrderService
{
    public function __construct(
        private OrderRepository $orders,
        private PricingPolicy $pricing,
    ) {}

    /**
     * @param array{user_id:int, amount:int, vip?:bool, sku:string, qty:int} $input
     * @return array{order_id:int, total:int, discount:int, status:string}
     */
    public function place(array $input): array
    {
        $order = new Order(
            userId: (int) $input['user_id'],
            amount: (int) $input['amount'],
            vip: (bool) ($input['vip'] ?? false),
        );

        $quote = $this->pricing->quote($order);

        $order = new Order($order->userId, $order->amount, $order->vip, $this->orders->nextId());

        // 事务边界：只有「订单头 + 订单明细」一起成功才算下单成功
        $inTx = $this->orders instanceof PdoOrderRepository;
        if ($inTx) {
            $this->orders->beginTransaction();
        }

        try {
            $this->orders->save($order, $quote, 'placed');
            $this->orders->addItem($order->id, (string) $input['sku'], (int) $input['qty'], $quote->total);

            if ($inTx) {
                $this->orders->commit();
            }
        } catch (Throwable $e) {
            if ($inTx) {
                $this->orders->rollBack();
            }
            throw $e;
        }

        return [
            'order_id' => $order->id,
            'total'    => $quote->total,
            'discount' => $quote->discount,
            'status'   => 'placed',
        ];
    }
}

/**
 * 反例 E：把事务边界交给调用方（「事务写在 Controller 里」的典型形态）。
 * 编排和 PlaceOrderService 一模一样，唯一区别是这里不自己开事务。
 * HTTP 路径由 Controller 开事务时看着没问题，队列 / CLI 路径就会漏（见 5-run.php）。
 */
final class OutsideTxOrderService
{
    public function __construct(
        private OrderRepository $orders,
        private PricingPolicy $pricing,
    ) {}

    public function place(array $input): array
    {
        $order = new Order(
            userId: (int) $input['user_id'],
            amount: (int) $input['amount'],
            vip: (bool) ($input['vip'] ?? false),
        );
        $quote = $this->pricing->quote($order);
        $order = new Order($order->userId, $order->amount, $order->vip, $this->orders->nextId());

        $this->orders->save($order, $quote, 'placed');
        $this->orders->addItem($order->id, (string) $input['sku'], (int) $input['qty'], $quote->total);

        return ['order_id' => $order->id, 'total' => $quote->total, 'discount' => $quote->discount, 'status' => 'placed'];
    }
}

/**
 * 反例 C：业务规则写进仓储 —— 换一个实现规则就没了。
 * 这里「VIP 用户必须满 5000 分才能下单」是业务规则，却被塞进了 MySQL 实现里。
 */
final class RuleInRepositoryRepository implements OrderRepository
{
    private InMemoryOrderRepository $inner;

    public function __construct()
    {
        $this->inner = new InMemoryOrderRepository();
    }

    public function nextId(): int { return $this->inner->nextId(); }

    public function save(Order $order, Quote $quote, string $status): void
    {
        if ($order->vip && $order->amount < 5000) {
            throw new DomainException('VIP 起送金额不足');   // ← 业务规则跑到了仓储里
        }
        $this->inner->save($order, $quote, $status);
    }

    public function addItem(int $orderId, string $sku, int $qty, int $price): void
    {
        $this->inner->addItem($orderId, $sku, $qty, $price);
    }

    public function findById(int $orderId): ?array { return $this->inner->findById($orderId); }

    public function countByStatus(string $status): int { return $this->inner->countByStatus($status); }
}
