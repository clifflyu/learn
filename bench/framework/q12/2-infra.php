<?php
/**
 * Q12 分层：Infrastructure 层
 *   - PdoOrderRepository：真存储
 *   - InMemoryOrderRepository：测试替身
 *   - 两个类都不含业务规则：VIP 折扣、金额校验一律不放这里
 *     （放进来就会出现「换个实现业务就变了」，见 5-run.php 的反例 D）
 */

final class PdoOrderRepository implements OrderRepository
{
    public function __construct(private PDO $pdo) {}

    public function nextId(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM orders')->fetchColumn();
    }

    public function save(Order $order, Quote $quote, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (id, user_id, amount, discount, total, status, created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([$order->id, $order->userId, $quote->amount, $quote->discount, $quote->total, $status]);
    }

    public function addItem(int $orderId, string $sku, int $qty, int $price): void
    {
        // order_items.qty 上有 CHECK (qty > 0)，qty=0 会直接报 3819
        $stmt = $this->pdo->prepare('INSERT INTO order_items (order_id, sku, qty, price) VALUES (?,?,?,?)');
        $stmt->execute([$orderId, $sku, $qty, $price]);
    }

    public function findById(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE status = ?');
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollBack(): void { $this->pdo->rollBack(); }
}

final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<int, array> */
    public array $orders = [];
    /** @var array<int, array> */
    public array $items = [];

    public function nextId(): int
    {
        return $this->orders ? max(array_keys($this->orders)) + 1 : 1;
    }

    public function save(Order $order, Quote $quote, string $status): void
    {
        $this->orders[$order->id] = [
            'id' => $order->id, 'user_id' => $order->userId, 'amount' => $quote->amount,
            'discount' => $quote->discount, 'total' => $quote->total, 'status' => $status,
        ];
    }

    public function addItem(int $orderId, string $sku, int $qty, int $price): void
    {
        // 故意不管 qty > 0：这是 CHECK 约束该管的事，仓储不该替数据库做业务判断
        $this->items[] = compact('orderId', 'sku', 'qty', 'price');
    }

    public function findById(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    public function countByStatus(string $status): int
    {
        return count(array_filter($this->orders, fn($o) => $o['status'] === $status));
    }
}
