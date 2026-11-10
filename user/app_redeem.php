<?php
/**
 * 优享财务系统 · 应用/资源 获取入口
 *
 * 依据 app.bill_type 处理：
 *   points      → 扣积分立即授予（资源=下载权益；应用=安装授权）
 *   once / year → 生成订单（type=app）并跳转支付，支付成功后交付
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('store/index.php'));
}
csrf_check();

$appId  = (int)($_POST['app_id'] ?? 0);
$domain = normalize_domain((string)($_POST['domain'] ?? ''));
$app    = YxDb::one('SELECT * FROM ' . YxDb::table('app') . " WHERE id = ? AND status = 'on'", [$appId]);

if (!$app) {
    flash('应用不存在或已下架', 'error');
    redirect(url('store/index.php'));
}
$back       = url('store/detail.php?id=' . $appId);
$isResource = (string)($app['author_type'] ?? '') === 'thirdparty';
$bill       = YxBusiness::billType($app);

/* 资源文件不在本机时一律不下单：前台已收敛入口，这里兜底直接 POST 的情况，
   否则会生成「付了钱却无文件可下载」的订单 */
if ($isResource && !YxStore::packageReady($app)) {
    flash('该资源暂未托管安装包，暂不可获取；请到应用详情页查看原始地址', 'error');
    redirect($back);
}

if (YxEdition::rank() < YxEdition::rank($app['edition_min'])) {
    flash('该应用需要「' . YxEdition::name($app['edition_min']) . '」及以上版本', 'error');
    redirect($back);
}
if (!$isResource && !YxEdition::can('store.buy')) {
    flash('购买付费应用需要「普惠版」及以上版本', 'error');
    redirect($back);
}
if (YxBusiness::entitlement($appId, $cid)) {
    flash('您已拥有该应用/资源');
    redirect($back);
}

/* 积分兑换 */
if ($bill === 'points') {
    $need = (int)($app['points_price'] ?? 0);
    if ($need <= 0) {
        flash('该商品未配置积分价格', 'error');
        redirect($back);
    }
    if (!$isResource && $domain === '') {
        flash('请填写要绑定的域名', 'error');
        redirect($back);
    }
    $dec = YxDb::exec('UPDATE ' . YxDb::table('customer') . ' SET points = points - ? WHERE id = ? AND points >= ?', [$need, $cid, $need]);
    if (!$dec) {
        flash('积分不足，需 ' . $need . ' 积分', 'error');
        redirect($back);
    }
    if ($isResource) {
        YxBusiness::grantResource($app, $cid, 0);
    } else {
        YxBusiness::installApp(['app_id' => $appId, 'customer_id' => $cid, 'domain' => $domain]);
    }
    log_action('app.redeem_points', 'app#' . $appId, ['customer' => $cid, 'points' => $need]);
    flash('兑换成功，已开通/解锁');
    redirect($back);
}

/* 一次性 / 按年：生成订单并支付 */
if ($bill === 'once' || $bill === 'year') {
    // 应用设置（可选加价项）+ 授权版本报价，金额与详情页展示口径一致
    $specName    = trim((string)($_POST['spec'] ?? ''));
    $editionWant = trim((string)($_POST['edition'] ?? ''));
    $pricing     = YxStore::priceOf($app, $editionWant !== '' ? $editionWant : null, $specName);
    if ($editionWant !== '' && !$pricing['quote']) {
        flash('所选授权版本不在该商品的报价范围内', 'error');
        redirect($back);
    }
    $amount   = round_money($pricing['amount']);
    $specTxt  = $pricing['spec'] ? (string)$pricing['spec']['name'] : '';
    $editionTxt = $pricing['quote'] ? (string)($pricing['quote']['label'] ?? '') : '';
    $orderBill  = !empty($pricing['quote']) ? (string)$pricing['quote']['bill_type'] : $bill;
    if ($amount <= 0) {
        flash('该商品未配置价格', 'error');
        redirect($back);
    }
    if (!$isResource && $domain === '') {
        flash('请填写要绑定的域名', 'error');
        redirect($back);
    }
    $created = YxBusiness::createOrder([
        'customer_id' => $cid,
        'type'        => 'app',
        'app_id'      => $appId,
        'name'        => $isResource ? $app['name'] : $domain,
        'amount'      => $amount,
        'note'        => '应用购买：' . $app['name'] . '（' . YxBusiness::billName($orderBill) . '）'
            . ($editionTxt !== '' ? ' · 授权版本：' . $editionTxt : '')
            . ($specTxt !== '' ? ' · 设置：' . $specTxt : ''),
    ]);
    if (empty($created['ok'])) {
        flash($created['msg'], 'error');
        redirect($back);
    }
    log_action('app.create_order', 'app#' . $appId, ['order' => $created['order_id'], 'bill' => $bill, 'edition' => $editionWant, 'spec' => $specTxt]);
    flash('订单已创建，请完成支付');
    redirect(url('user/orders.php?pay=' . (int)$created['order_id']));
}

flash('该商品为免费，请直接在详情页获取', 'error');
redirect($back);
