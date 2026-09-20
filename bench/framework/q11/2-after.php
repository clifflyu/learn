<?php
/**
 * Q11 重构【后】：同样的业务，按 SOLID 拆开
 *   - 一个渠道一个类，新增渠道不动 PaymentGateway  → OCP
 *   - 校验/计价/落库/日志各归各家                    → SRP
 *   - 依赖接口（SdkClient / Ledger / Logger），不 new 具体实现 → DIP
 *   - 每个渠道类只暴露 charge()，测试时只依赖一个 SDK 接口 → ISP + 可测
 *
 * 与 1-before.php 完全等价的输出，见 3-run.php 的契约测试。
 */

// ---------- 抽象 ----------
interface PaymentChannel
{
    public function name(): string;

    /** @return Receipt */
    public function charge(int $amount, int $userId): Receipt;
}

final class Receipt
{
    public function __construct(
        public readonly string $tradeNo,
        public readonly int $fee,
    ) {}
}

/** 渠道 SDK 的抽象：真实项目里由容器注入具体实现 */
interface SdkClient
{
    public function createTrade(int $amount, int $userId): string;   // 返回渠道流水号
}

interface Ledger
{
    public function record(string $channel, string $tradeNo, int $amount, int $fee, int $userId): void;
}

interface PayLogger
{
    public function log(string $channel, string $tradeNo, int $amount, int $fee, int $userId): void;
}

/** 金额规则单独一处：HTTP、CLI、队列、定时任务都复用同一份 */
final class AmountPolicy
{
    public function __construct(private int $maxAmount = 1000000) {}

    public function assertValid(int $amount, int $userId): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('金额必须大于 0');
        }
        if ($amount > $this->maxAmount) {
            throw new InvalidArgumentException("单笔限额 {$this->maxAmount} 分");
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('参数不合法');
        }
    }
}

// ---------- 每个渠道一个类，各自持有自己的费率 ----------
final class AlipayChannel implements PaymentChannel
{
    public function __construct(private SdkClient $sdk, private int $rateBp = 60) {}   // 60 基点 = 0.6%

    public function name(): string { return 'alipay'; }

    public function charge(int $amount, int $userId): Receipt
    {
        $tradeNo = $this->sdk->createTrade($amount, $userId);

        return new Receipt($tradeNo, (int) round($amount * $this->rateBp / 10000));
    }
}

final class WechatChannel implements PaymentChannel
{
    public function __construct(private SdkClient $sdk, private int $rateBp = 60) {}

    public function name(): string { return 'wechat'; }

    public function charge(int $amount, int $userId): Receipt
    {
        $tradeNo = $this->sdk->createTrade($amount, $userId);

        return new Receipt($tradeNo, (int) round($amount * $this->rateBp / 10000));
    }
}

final class UnionPayChannel implements PaymentChannel
{
    public function __construct(private SdkClient $sdk, private int $rateBp = 55) {}

    public function name(): string { return 'unionpay'; }

    public function charge(int $amount, int $userId): Receipt
    {
        $tradeNo = $this->sdk->createTrade($amount, $userId);

        return new Receipt($tradeNo, (int) round($amount * $this->rateBp / 10000));
    }
}

// ---------- 具体实现（替换掉原来的 new PDO / file_put_contents） ----------
final class PdoLedger implements Ledger
{
    public function __construct(private PDO $pdo) {}

    public function record(string $channel, string $tradeNo, int $amount, int $fee, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (channel, trade_no, amount, fee, user_id, created_at) VALUES (?,?,?,?,?,NOW())'
        );
        $stmt->execute([$channel, $tradeNo, $amount, $fee, $userId]);
    }
}

final class FilePayLogger implements PayLogger
{
    public function __construct(private string $logFile) {}

    public function log(string $channel, string $tradeNo, int $amount, int $fee, int $userId): void
    {
        file_put_contents(
            $this->logFile,
            sprintf("[%s] channel=%s trade_no=%s amount=%d fee=%d user=%d\n",
                date('Y-m-d H:i:s'), $channel, $tradeNo, $amount, $fee, $userId),
            FILE_APPEND
        );
    }
}

// ---------- 编排：只依赖抽象，新增渠道不用改这个类 ----------
final class PaymentGateway
{
    /** @var array<string, PaymentChannel> */
    private array $channels;

    /** @param PaymentChannel[] $channels */
    public function __construct(
        array $channels,
        private Ledger $ledger,
        private PayLogger $logger,
        private AmountPolicy $policy,
    ) {
        foreach ($channels as $c) {
            $this->channels[$c->name()] = $c;
        }
    }

    public function pay(string $channel, int $amount, int $userId): array
    {
        $this->policy->assertValid($amount, $userId);

        $impl = $this->channels[$channel] ?? throw new InvalidArgumentException("不支持的渠道: {$channel}");

        $receipt = $impl->charge($amount, $userId);

        $this->ledger->record($channel, $receipt->tradeNo, $amount, $receipt->fee, $userId);
        $this->logger->log($channel, $receipt->tradeNo, $amount, $receipt->fee, $userId);

        return [
            'trade_no' => $receipt->tradeNo,
            'fee'      => $receipt->fee,
            'channel'  => $channel,
            'amount'   => $amount,
        ];
    }
}

// ---------- 假的 SDK 实现：和 1-before.php 里的 SDK 行为一致 ----------
final class FakeSdk implements SdkClient
{
    public function __construct(private string $prefix) {}

    public function createTrade(int $amount, int $userId): string
    {
        return $this->prefix . strtoupper(substr(md5($userId . $amount), 0, 10));
    }
}
