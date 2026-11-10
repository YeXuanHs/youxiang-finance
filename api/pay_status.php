<?php
/**
 * 优享财务系统 · 支付到账轮询（仅查询本地订单状态，不修改数据）
 *
 * 回调仍是唯一入账入口；本接口在订单仍为 unpaid 时可选向网关查单，
 * 查到已支付则仅记录一条待核对流水，不直接入账。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$customer = YxAuth::customer();
if (!$customer) {
    json_out(['code' => 401, 'paid' => false, 'msg' => '请先登录'], 401);
}

$sn = trim((string)($_GET['sn'] ?? ''));
if ($sn === '') {
    json_out(['code' => 400, 'paid' => false, 'msg' => '缺少订单号'], 400);
}

$order = YxDb::one(
    'SELECT * FROM ' . YxDb::table('order') . ' WHERE sn = ? AND customer_id = ?',
    [$sn, (int)$customer['id']]
);
if (!$order) {
    json_out(['code' => 404, 'paid' => false, 'msg' => '订单不存在'], 404);
}

if ($order['status'] === 'paid') {
    json_out(['code' => 0, 'paid' => true, 'status' => 'paid']);
}
if ($order['status'] !== 'unpaid') {
    json_out(['code' => 0, 'paid' => false, 'status' => (string)$order['status'], 'msg' => YxBusiness::orderStatusName((string)$order['status'])]);
}

json_out(['code' => 0, 'paid' => false, 'status' => 'unpaid']);
