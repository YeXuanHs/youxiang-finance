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
    $domain = normalize_domain((string)($_POST['domain'] ?? ''));
    $app    = YxDb::one('SELECT * FROM ' . YxDb::table('app') . " WHERE id = ? AND status = 'on'", [$appId]);

    if (!$app) {
        flash('应用不存在或已下架', 'error');
        redirect(url('store/index.php'));
    }
    if (YxEdition::rank() < YxEdition::rank($app['edition_min'])) {
        flash('该应用需要「' . YxEdition::name($app['edition_min']) . '」及以上版本', 'error');
        redirect(url('store/detail.php?id=' . $appId));
    }
    if ($domain === '') {
        flash('请填写要绑定的域名（授权码按域名签发）', 'error');
        redirect(url('store/detail.php?id=' . $appId));
    }

    // 应用设置（可选加价项）
    $specName = trim((string)($_POST['spec'] ?? ''));
    $q        = class_exists('YxServiceCenter') ? YxServiceCenter::appQuote($app, $specName) : ['amount' => (float)$app['price'], 'spec' => null];
    $price    = round_money($q['amount']);
    $specTxt  = $q['spec'] ? (string)$q['spec']['name'] : '';
    $name     = $app['name'] . ' @ ' . $domain . ($specTxt !== '' ? ' · ' . $specTxt : '');

    if ($price <= 0) {
        // 免费应用直接安装
        if (!YxEdition::can('store.buy')) {
            flash('当前版本不支持一键安装，请升级到「普惠版」及以上版本', 'error');
            redirect(url('store/detail.php?id=' . $appId));
        }
        $res = YxBusiness::installApp(['app_id' => $appId, 'customer_id' => $cid, 'domain' => $domain]);
        log_action('app.install_free', $app['key'], ['domain' => $domain, 'ok' => $res['ok']]);
        // 只有安装成功（权益已落库、本人即持有者）才回显授权码；失败时不要拼空码。
        $flashMsg = $res['msg'];
        if (!empty($res['ok']) && (string)($res['license_key'] ?? '') !== '') {
            $flashMsg .= '，授权码：' . $res['license_key'];
        }
        flash($flashMsg, $res['ok'] ? 'success' : 'error');
        redirect(url('user/licenses.php'));
    }

    $created = YxBusiness::createOrder([
        'customer_id' => $cid,
        'type'        => 'app',
        'app_id'      => $appId,
        'name'        => $domain,
        'amount'      => $price,
        'note'        => '应用购买：' . $app['name'] . ($specTxt !== '' ? ' · 设置：' . $specTxt : ''),
    ]);
    if (!$created['ok']) {
        flash($created['msg'], 'error');
        redirect(url('store/detail.php?id=' . $appId));
    }
    flash('订单已创建（' . $name . '），请完成支付');
    redirect(url('user/orders.php?pay=' . (int)$created['order_id']));
}

flash('未知的下单类型', 'error');
redirect(url('index.php'));
