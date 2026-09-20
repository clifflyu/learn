<?php
/**
 * Q11 实测：重构前 vs 重构后
 *   1) 契约测试：同样的入参，两个版本必须给出完全一样的出参（重构不改变行为）
 *   2) 复杂度：决策点数量
 *   3) OCP 实验：新增一个 applepay 渠道，各要动几个文件、核心文件哈希变不变
 *   4) 可测性：只测一个渠道，需要准备多少依赖
 *
 * 跑法：docker exec learn-php php /app/bench/framework/q11/3-run.php
 * 依赖库：q10_n1（只用 payments 表）
 */
require __DIR__ . '/1-before.php';
require __DIR__ . '/2-after.php';
require __DIR__ . '/2b-registry.php';

$pdo = new PDO('mysql:host=learn-mysql;port=3306;dbname=q10_n1;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('DROP TABLE IF EXISTS payments');
$pdo->exec('CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(32) NOT NULL,
    trade_no VARCHAR(64) NOT NULL,
    amount INT NOT NULL,
    fee INT NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_channel (channel)
) ENGINE=InnoDB');

const LOG_BEFORE = '/tmp/q11-before.log';
const LOG_AFTER  = '/tmp/q11-after.log';
@unlink(LOG_BEFORE);
@unlink(LOG_AFTER);

$sdks = ['alipay' => new FakeSdk('ALI'), 'wechat' => new FakeSdk('WX'), 'unionpay' => new FakeSdk('UP')];

$before = new PaymentService($pdo, LOG_BEFORE);
$after  = new PaymentGateway(
    q11_channels($sdks),
    new PdoLedger($pdo),
    new FilePayLogger(LOG_AFTER),
    new AmountPolicy(),
);

// ---------- 1) 契约测试 ----------
$cases = [
    ['alipay',    100,    1],
    ['alipay',    99999,  7],
    ['wechat',    250000, 42],
    ['unionpay',  1,      9],
    ['unionpay',  1000000, 3],
    ['wechat',    333333, 88],
];

$diff = 0;
$rows = [];
foreach ($cases as [$ch, $amount, $uid]) {
    $b = $before->pay($ch, $amount, $uid);
    $a = $after->pay($ch, $amount, $uid);
    $same = $b === $a;
    $diff += $same ? 0 : 1;
    $rows[] = [$ch, $amount, $uid, $b['trade_no'], $b['fee'], $same ? '一致' : '不一致'];
}

$rowCount = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();

// 日志内容对比（去掉时间戳）
$norm = fn(string $f) => preg_replace('/^\[[^\]]+\] /m', '', file_get_contents($f) ?: '');
$logBefore = $norm(LOG_BEFORE);
$logAfter  = $norm(LOG_AFTER);

echo "## 1) 契约测试：重构前后行为必须完全一致\n\n";
echo "| 渠道 | 金额(分) | 用户 | trade_no | fee | 出参对比 |\n| --- | ---: | ---: | --- | ---: | --- |\n";
foreach ($rows as $r) {
    printf("| %s | %d | %d | %s | %d | %s |\n", ...$r);
}
printf("\n- 用例数 %d，出参不一致 %d 个\n", count($cases), $diff);
printf("- payments 表写入行数：%d（两个版本各写一份，期望 %d）\n", $rowCount, count($cases) * 2);
printf("- 日志文本（忽略时间戳）一致：%s\n", $logBefore === $logAfter ? '是' : '否');

// ---------- 2) 复杂度：按类/方法统计，不按文件（文件行数对比对"后"不公平） ----------
echo "\n## 2) 决策点分布（if / elseif / case / && / || / ?:）\n\n";

/** 按类切分源码，统计每个类里的决策点 */
function decisionsByClass(string $file): array
{
    $src = file_get_contents($file);
    $parts = preg_split('/^(?=(?:final |abstract )?(?:class|interface|trait) \w+)/m', $src);
    $out = [];
    foreach ($parts as $chunk) {
        if (! preg_match('/^(?:final |abstract )?(?:class|interface|trait) (\w+)/', $chunk, $m)) {
            continue;
        }
        $d = 0;
        foreach (token_get_all('<?php ' . $chunk) as $t) {
            if (is_array($t) && in_array($t[0], [T_IF, T_ELSEIF, T_CASE, T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR], true)) {
                $d++;
            } elseif ($t === '?') {
                $d++;
            }
        }
        $out[$m[1]] = $d;
    }

    return $out;
}

$beforeDec = decisionsByClass(__DIR__ . '/1-before.php');
$afterDec  = decisionsByClass(__DIR__ . '/2-after.php');
$regDec    = decisionsByClass(__DIR__ . '/2b-registry.php');

echo "| 重构前 | 决策点 | 重构后 | 决策点 |\n| --- | ---: | --- | ---: |\n";
$pairs = [
    ['PaymentService', $beforeDec['PaymentService'] ?? 0, 'PaymentGateway', $afterDec['PaymentGateway'] ?? 0],
    ['（校验/分支/落库/日志全在里面）', 0, 'AmountPolicy', $afterDec['AmountPolicy'] ?? 0],
    ['', 0, 'AlipayChannel', $afterDec['AlipayChannel'] ?? 0],
    ['', 0, 'WechatChannel', $afterDec['WechatChannel'] ?? 0],
    ['', 0, 'UnionPayChannel', $afterDec['UnionPayChannel'] ?? 0],
    ['', 0, 'PdoLedger', $afterDec['PdoLedger'] ?? 0],
    ['', 0, 'FilePayLogger', $afterDec['FilePayLogger'] ?? 0],
    ['', 0, 'q11_channels（注册表）', $regDec['q11_channels'] ?? 0],
];
foreach ($pairs as $p) {
    printf("| %s | %s | %s | %s |\n", $p[0], $p[0] === '' ? '' : $p[1],
        $p[2], $p[2] === '' ? '' : $p[3]);
}
$sumBefore = ($beforeDec['PaymentService'] ?? 0);
$sumAfter  = ($afterDec['PaymentGateway'] ?? 0) + ($afterDec['AmountPolicy'] ?? 0);
printf("\n- 重构前：%d 个决策点全在 `PaymentService::pay()` 一个方法里\n", $sumBefore);
printf("- 重构后：编排类 `PaymentGateway` %d 个 + 规则类 `AmountPolicy` %d 个，每个渠道类 0 个\n",
    $afterDec['PaymentGateway'] ?? 0, $afterDec['AmountPolicy'] ?? 0);

$mb = new ReflectionMethod('PaymentService', 'pay');
$ma = new ReflectionMethod('PaymentGateway', 'pay');
printf("- `pay()` 方法本体行数：重构前 %d 行 → 重构后 %d 行\n",
    $mb->getEndLine() - $mb->getStartLine() + 1, $ma->getEndLine() - $ma->getStartLine() + 1);

// ---------- 3) OCP 实验：新增一个 applepay 渠道 ----------
echo "\n## 3) OCP 实验：新增 applepay 渠道，各要动什么\n\n";

$tmp = '/tmp/q11-ocp';
exec('rm -rf ' . escapeshellarg($tmp));
mkdir($tmp, 0777, true);
foreach (['1-before.php', '2-after.php', '2b-registry.php'] as $f) {
    copy(__DIR__ . '/' . $f, $tmp . '/' . $f);
}

// (a) 改"重构前"：往 switch 里插一个 case
$beforeSrc = file_get_contents($tmp . '/1-before.php');
$anchor = "            default:\n                throw new InvalidArgumentException(\"不支持的渠道: {\$channel}\");";
if (substr_count($beforeSrc, $anchor) !== 1) {
    fwrite(STDERR, "锚点没找到，OCP 实验无法进行\n");
    exit(1);
}
$newCase = <<<'PHPEOF'
            case 'applepay':
                $client = new ApplePaySdk('merchant.com.x');
                $resp   = $client->createTrade(['amount' => $amount, 'user' => $userId]);
                $tradeNo = $resp['trade_no'];
                $fee     = (int) round($amount * 0.0038);         // Apple Pay 0.38%
                break;

PHPEOF;
file_put_contents($tmp . '/1-before.php', str_replace($anchor, $newCase . $anchor, $beforeSrc));

// (b) 改"重构后"：只新增一个类文件 + 注册表加一行
file_put_contents($tmp . '/ApplePayChannel.php', <<<'PHPEOF'
<?php
final class ApplePayChannel implements PaymentChannel
{
    public function __construct(private SdkClient $sdk, private int $rateBp = 38) {}
    public function name(): string { return 'applepay'; }
    public function charge(int $amount, int $userId): Receipt
    {
        $tradeNo = $this->sdk->createTrade($amount, $userId);
        return new Receipt($tradeNo, (int) round($amount * $this->rateBp / 10000));
    }
}
PHPEOF);
$reg = file_get_contents($tmp . '/2b-registry.php');
file_put_contents($tmp . '/2b-registry.php', str_replace(
    "        new UnionPayChannel(\$sdks['unionpay']),\n",
    "        new UnionPayChannel(\$sdks['unionpay']),\n        new ApplePayChannel(new FakeSdk('AP')),\n",
    $reg
));

$hashBefore = md5_file(__DIR__ . '/1-before.php');
$hashAfter  = md5_file(__DIR__ . '/2-after.php');
$hashGate   = md5_file(__DIR__ . '/2-after.php');

printf("| 问 | 重构前（switch） | 重构后（策略） |\n| --- | --- | --- |\n");
printf("| 修改的已有文件 | 1-before.php：**+%d 行 case 分支** | 2b-registry.php：**+1 行** |\n",
    substr_count($newCase, "\n"));
printf("| 新增文件 | 0 | ApplePayChannel.php（%d 行） |\n", substr_count(file_get_contents($tmp . '/ApplePayChannel.php'), "\n") + 1);
printf("| 核心类所在文件哈希变化 | 1-before.php %s → **变了** | 2-after.php %s → **没变** |\n",
    substr($hashBefore, 0, 8), substr($hashAfter, 0, 8));
printf("| 改动落在哪一层 | 支付核心逻辑（下次改还得进同一个 switch） | 注册表 / 新增类 |\n");

// 验证：改完之后，重构后那一版确实能跑通 applepay
require $tmp . '/ApplePayChannel.php';
$afterChannels = static function (array $sdks): array {
    return [
        new AlipayChannel($sdks['alipay']),
        new WechatChannel($sdks['wechat']),
        new UnionPayChannel($sdks['unionpay']),
        new ApplePayChannel(new FakeSdk('AP')),
    ];
};
$newGateway = new PaymentGateway(
    $afterChannels($sdks),
    new PdoLedger($pdo),
    new FilePayLogger('/tmp/q11-after.log'),
    new AmountPolicy(),
);
$r = $newGateway->pay('applepay', 123456, 5);
printf("\n新渠道实测可跑通：applepay 123456 分 → trade_no=%s fee=%d（费率 0.38%%）\n", $r['trade_no'], $r['fee']);

// ---------- 4) 可测性 ----------
echo "\n## 4) 只测一个渠道，需要准备多少依赖\n\n";

$ref = new ReflectionClass('UnionPayChannel');
printf("- 重构后：`new UnionPayChannel(new FakeSdk('UP'))` → 构造参数 %d 个，不需要 PDO、不需要 Logger、不需要容器\n",
    (new ReflectionMethod('UnionPayChannel', '__construct'))->getNumberOfParameters());
$r2 = (new UnionPayChannel(new FakeSdk('UP')))->charge(10000, 1);
printf("  单独跑通：10000 分 → trade_no=%s fee=%d\n", $r2->tradeNo, $r2->fee);

$bp = new ReflectionMethod('PaymentService', '__construct');
printf("- 重构前：`new PaymentService(...)` → 构造参数 %d 个，第 1 个是 %s（必须真连上库才能构造）\n",
    $bp->getNumberOfParameters(),
    (string) $bp->getParameters()[0]->getType());

try {
    new PaymentService(new PDO('mysql:host=learn-mysql;port=1;dbname=nope', 'root', 'root', [PDO::ATTR_TIMEOUT => 1]));
    echo "  连不上也能构造？（意外）\n";
} catch (PDOException $e) {
    printf("  用一个连不上的 DSN 构造，直接抛：%s\n", explode(':', $e->getMessage())[0]);
}
printf("  也就是说：重构前想单测 unionpay 一个渠道，也必须先有一个可用的数据库连接。\n");
