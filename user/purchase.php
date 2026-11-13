<?php
/**
 * 优享财务系统 · 下单（产品购买 / 应用购买）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('index.php#products'));
}
csrf_check();

$kind = (string)($_POST['kind'] ?? 'product');

if ($kind === 'product') {
    $pid   = (int)($_POST['product_id'] ?? 0);
    $cycle = (string)($_POST['cycle'] ?? 'month');
    $product = YxDb::one('SELECT * FROM ' . YxDb::table('product') . " WHERE id = ? AND status = 1", [$pid]);

    if (!$product) {
        flash('产品不存在或已下架', 'error');
        redirect(url('index.php#products'));
    }
    if (!isset(YxBusiness::CYCLES[$cycle])) {
        $cycle = YxBusiness::defaultCycle($product);
    }
    // 产品可能只开放部分周期（如上游按年计费的商品仅年付），越权周期一律打回
    if (!YxBusiness::cycleAllowed($product, $cycle)) {
        flash('该产品不支持「' . YxBusiness::cycleName($cycle) . '」，可选：'
            . implode(' / ', array_map(static function ($k) {
                return YxBusiness::cycleName($k);
            }, YxBusiness::allowedCycles($product))), 'error');
        redirect(url('products.php?id=' . $pid));
    }
    if (YxEdition::rank() < YxEdition::rank($product['edition_min'])) {
        flash('该产品需要「' . YxEdition::name($product['edition_min']) . '」及以上版本', 'error');
        redirect(url('index.php#products'));
    }
    if ((int)$product['stock'] === 0) {
        flash('该产品库存不足，请稍后再试', 'error');
        redirect(url('index.php#products'));
    }

    $price = YxBusiness::cyclePrice($product, $cycle) + (float)$product['setup_fee'];
    $created = YxBusiness::createOrder([
        'customer_id' => $cid,
        'type'        => 'new',
        'product_id'  => $pid,
        'name'        => $product['name'] . '（' . YxBusiness::cycleName($cycle) . '）',
        'amount'      => $price,
        'note'        => '用户自助下单',
    ]);

    if (!$created['ok']) {
        flash($created['msg'], 'error');
        redirect(url('index.php#products'));
    }
    log_action('order.create_front', 'order#' . $created['order_id'], ['product' => $pid, 'cycle' => $cycle]);
    flash('下单成功，请完成支付以开通业务');
    redirect(url('user/orders.php?pay=' . (int)$created['order_id']));
}

if ($kind === 'app') {
    $appId  = (int)($_POST['app_id'] ?? 0);
    $app    = YxDb::one('SELECT * FROM ' . YxDb::table('app') . " WHERE id = ? AND status = 'on'", [$appId]);
    $back   = url('store/detail.php?id=' . $appId);

    if (!$app) {
        flash('应用不存在或已下架', 'error');
        redirect(url('store/index.php'));
    }

    // 与 user/app_redeem.php 共用同一结算入口，免费 / 积分 / 按年 / 升级规则不分叉
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
    redirect((string)($app['author_type'] ?? '') === 'thirdparty' ? $back : url('user/licenses.php'));
}

flash('未知的下单类型', 'error');
redirect(url('index.php'));
