<?php
/**
 * Q12 分层：Domain 层
 *   - Order：领域对象，只有数据和自身不变量，不认识 PDO / Request / Response
 *   - OrderRepository：仓储接口。注意它是「领域层定义的接口」，实现放在 infra
 *   - PricingPolicy：业务规则。规则放这里，三个入口（HTTP / CLI / 队列）才能共用一份
 */

final class Order
{
    public function __construct(
        public readonly int $userId,
        public readonly int $amount,        // 单位：分
        public readonly bool $vip = false,
        public readonly int $id = 0,
    ) {}
}

/** 带折扣的报价结果 */
final class Quote
{
    public function __construct(
        public readonly int $amount,
        public readonly int $discount,
        public readonly int $total,
    ) {}
}

final class PricingPolicy
{
    public function __construct(
        private int $fullAmountThreshold = 10000,   // 满 100 元
        private int $fullDiscountBp = 1000,         // 满减 10%（基点）
        private int $vipDiscountBp = 500,           // VIP 再 5%
    ) {}

    public function quote(Order $order): Quote
    {
        if ($order->amount <= 0) {
            throw new InvalidArgumentException('订单金额必须大于 0');
        }

        $discount = 0;
        if ($order->amount >= $this->fullAmountThreshold) {
            $discount += (int) round($order->amount * $this->fullDiscountBp / 10000);
        }
        if ($order->vip) {
            $discount += (int) round($order->amount * $this->vipDiscountBp / 10000);
        }

        return new Quote($order->amount, $discount, $order->amount - $discount);
    }
}

/**
 * 仓储接口属于 Domain 层：业务要什么，接口就写什么；怎么存是 infra 的事。
 * 注意接口里没有任何 SQL、没有 Eloquent、没有「事务」这种存储概念。
 */
interface OrderRepository
{
    public function nextId(): int;

    public function save(Order $order, Quote $quote, string $status): void;

    public function addItem(int $orderId, string $sku, int $qty, int $price): void;

    public function findById(int $orderId): ?array;

    public function countByStatus(string $status): int;
}
