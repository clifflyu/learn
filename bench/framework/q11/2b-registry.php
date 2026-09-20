<?php
/**
 * Q11：渠道注册表 —— 整个"新增一个支付渠道"里唯一需要改动的已有文件。
 * PaymentGateway（2-after.php）本身不需要动，这是 OCP 的落点。
 *
 * 说明：注册表仍然是"改一行"。要做到零改动，得靠容器的 tag / 目录扫描自动发现，
 * 那部分本仓库没实测，答案里按"未实测"标注。
 */
function q11_channels(array $sdks): array
{
    return [
        new AlipayChannel($sdks['alipay']),
        new WechatChannel($sdks['wechat']),
        new UnionPayChannel($sdks['unionpay']),
    ];
}
