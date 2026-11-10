<?php
/**
 * 优享财务系统 · 支付异步回调（易支付 / 聚合码 / 码支付通用协议）
 *
 * 网关回调后：验签 → 定位订单 → 金额核对 → 完成交付（充值入账 / 业务开通 / 应用安装）。
 * 成功必须输出 success 字样，否则网关会重复通知。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$params  = YxPayment::readNotifyParams();
$orderSn = trim((string)($params['out_trade_no'] ?? ''));

/** 应答：纯文本 success，网关只认这个 */
$ack = static function (): void {
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'success';
    exit;
};

/** 拒收：记录原因并返回 fail，便于网关重试与后台排查 */
$reject = static function (int $code, string $msg, string $sn, array $params) use ($ack): void {
    log_action('pay.notify_reject', $sn, [
        'msg'   => $msg,
        'http'  => $code,
        'type'  => (string)($params['type'] ?? ''),
        'param' => mb_substr((string)json_encode($params, JSON_UNESCAPED_UNICODE), 0, 1500),
    ]);
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'fail';
    exit;
};

$verify = YxPayment::verifyNotify($params);
if (!$verify['ok']) {
    $reject(403, $verify['msg'], $orderSn, $params);
}

// 非成功状态（等待付款 / 已关闭 / 已退款）直接应答，避免网关重复推送
if (!YxPayment::isTradeSuccess($params)) {
    log_action('pay.notify_ignored', $orderSn, ['trade_status' => (string)($params['trade_status'] ?? '')]);
    $ack();
}

if ($orderSn === '') {
    $reject(400, '缺少 out_trade_no', '', $params);
}

$order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE sn = ?', [$orderSn]);
if (!$order) {
    $reject(404, '订单不存在', $orderSn, $params);
}

// 已支付：重复通知，直接应答 success（交付逻辑本身也是幂等的）
if ($order['status'] === 'paid') {
    $ack();
}

// 金额校验：防止篡改
$paidMoney = round_money($params['money'] ?? 0);
if ($paidMoney > 0 && abs($paidMoney - (float)$order['total']) > 0.01) {
    YxFinance::recordPayment([
        'order_id'    => (int)$order['id'],
        'order_sn'    => $orderSn,
        'customer_id' => (int)$order['customer_id'],
        'gateway'     => (string)($params['type'] ?? 'gateway'),
        'trade_no'    => (string)($params['trade_no'] ?? ''),
        'amount'      => $paidMoney,
        'status'      => 'mismatch',
        'raw'         => $params,
    ]);
    $reject(400, '金额不匹配', $orderSn, $params);
}

$res = YxBusiness::payOrderExternal(
    (int)$order['id'],
    (string)($params['type'] ?? 'gateway'),
    (string)($params['trade_no'] ?? ''),
);

log_action('pay.notify', $orderSn, ['ok' => !empty($res['ok']), 'msg' => $res['msg'] ?? '']);

if (empty($res['ok'])) {
    // 交付失败（如状态已非待支付）：返回 fail 让网关重试，后台可见原因
    if (!empty($res['repeat'])) {
        $ack();
    }
    $reject(500, (string)($res['msg'] ?? '交付失败'), $orderSn, $params);
}

$ack();
