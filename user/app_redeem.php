<?php
/**
 * 优享财务系统 · 应用/资源 获取入口
 *
 * 计费方式与授权版本均由 YxStore::checkout() 统一裁决：
 *   free        → 直接签发授权（应用）或下载权益（资源）
 *   points      → 扣积分后授予
 *   once / year → 生成带版本快照的订单（type=app）并跳转支付
 *
 * 页面本身不再按 app.bill_type 判断，避免「基础价按年、所选版本一次性」
 * 这类组合走进错误的计费分支。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('store/index.php'));
}
csrf_check();

$appId = (int)($_POST['app_id'] ?? 0);
$app   = YxDb::one('SELECT * FROM ' . YxDb::table('app') . " WHERE id = ? AND status = 'on'", [$appId]);

if (!$app) {
    flash('应用不存在或已下架', 'error');
    redirect(url('store/index.php'));
}
$back       = url('store/detail.php?id=' . $appId);
$isResource = (string)($app['author_type'] ?? '') === 'thirdparty';

$res = YxStore::checkout($app, $cid, [
    'domain'  => (string)($_POST['domain'] ?? ''),
    'edition' => (string)($_POST['edition'] ?? ''),
    'spec'    => (string)($_POST['spec'] ?? ''),
]);

if (empty($res['ok'])) {
    flash((string)($res['msg'] ?? '操作失败'), 'error');
    redirect($back);
}

if (!empty($res['order_id'])) {
    flash((string)$res['msg']);
    redirect(url('user/orders.php?pay=' . (int)$res['order_id']));
}

$msg = (string)$res['msg'];
if ((string)($res['license_key'] ?? '') !== '') {
    $msg .= '，授权码：' . $res['license_key'];
}
flash($msg);
redirect($isResource ? $back : url('user/licenses.php'));
