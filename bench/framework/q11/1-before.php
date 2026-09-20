<?php
/**
 * Q11 重构【前】：一个 PaymentService 类干完所有事
 *   - 渠道逻辑用 switch 堆在一起          → 违反 OCP
 *   - 校验 / 支付 / 落库 / 记日志 / 计价 全在一个方法 → 违反 SRP
 *   - 直接 new AlipaySdk / new PDO        → 违反 DIP
 *   - 想测一个渠道，必须把 PDO、三个 SDK、日志路径全准备好 → 违反 ISP 的连带后果
 */

// ---------- 假装是三个第三方 SDK（为了能离线跑，行为确定） ----------
class AlipaySdk
{
    public function __construct(private string $appId, private string $secret) {}
    public function createTrade(array $p): array
    {
        return ['trade_no' => 'ALI' . strtoupper(substr(md5($p['user'] . $p['amount']), 0, 10))];
    }
}

class WechatSdk
{
    public function __construct(private string $mchId, private string $key) {}
    public function unifiedOrder(array $p): array
    {
        return ['prepay_id' => 'WX' . strtoupper(substr(md5($p['user'] . $p['amount']), 0, 10))];
    }
}

class UnionPaySdk
{
    public function __construct(private string $merId) {}
    public function consume(array $p): array
    {
        return ['query_id' => 'UP' . strtoupper(substr(md5($p['user'] . $p['amount']), 0, 10))];
    }
}

final class PaymentService
{
    public function __construct(
        private PDO $pdo,
        private string $logFile = '/tmp/q11-before.log',
    ) {}

    /**
     * 入参：渠道、金额（分）、用户
     * 出参：['trade_no' => ..., 'fee' => ..., 'channel' => ...]
     */
    public function pay(string $channel, int $amount, int $userId): array
    {
        // ① 参数校验：金额规则和 HTTP 层混在一起，谁也复用不了
        if ($amount <= 0) {
            throw new InvalidArgumentException('金额必须大于 0');
        }
        if ($amount > 1000000) {
            throw new InvalidArgumentException('单笔限额 1000000 分');
        }
        if ($channel === '' || $userId <= 0) {
            throw new InvalidArgumentException('参数不合法');
        }

        // ② 渠道分支：每加一个渠道都要回来改这个 switch
        switch ($channel) {
            case 'alipay':
                $client = new AlipaySdk('2021000000', 'secret');
                $resp   = $client->createTrade(['amount' => $amount, 'user' => $userId]);
                $tradeNo = $resp['trade_no'];
                $fee     = (int) round($amount * 0.006);          // 支付宝 0.6%
                break;

            case 'wechat':
                $client = new WechatSdk('1900000000', 'key');
                $resp   = $client->unifiedOrder(['amount' => $amount, 'user' => $userId]);
                $tradeNo = $resp['prepay_id'];
                $fee     = (int) round($amount * 0.006);          // 微信 0.6%
                break;

            case 'unionpay':
                $client = new UnionPaySdk('7772900581');
                $resp   = $client->consume(['amount' => $amount, 'user' => $userId]);
                $tradeNo = $resp['query_id'];
                $fee     = (int) round($amount * 0.0055);         // 银联 0.55%
                break;

            default:
                throw new InvalidArgumentException("不支持的渠道: {$channel}");
        }

        // ③ 落库：SQL 直接写死在业务方法里
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (channel, trade_no, amount, fee, user_id, created_at) VALUES (?,?,?,?,?,NOW())'
        );
        $stmt->execute([$channel, $tradeNo, $amount, $fee, $userId]);

        // ④ 记日志：日志格式的改动也要改这个文件
        file_put_contents(
            $this->logFile,
            sprintf("[%s] channel=%s trade_no=%s amount=%d fee=%d user=%d\n",
                date('Y-m-d H:i:s'), $channel, $tradeNo, $amount, $fee, $userId),
            FILE_APPEND
        );

        return ['trade_no' => $tradeNo, 'fee' => $fee, 'channel' => $channel, 'amount' => $amount];
    }
}
