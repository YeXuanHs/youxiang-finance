<?php
/**
 * 优享财务系统 · 服务中心 · 服务详情与下单
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

$id   = (int)($_GET['id'] ?? 0);
$item = $id > 0 ? YxServiceCenter::find($id) : null;
if (!$item || (int)$item['status'] !== 1) {
    flash('服务不存在或已下架', 'error');
    redirect(url('service/index.php'));
}

$customer = YxAuth::customer();
$cid      = $customer ? (int)$customer['id'] : 0;
$specs    = YxServiceCenter::specs($item);
$features = YxServiceCenter::features($item);
$bill     = (string)$item['bill_type'];
$isQuote  = $bill === 'quote';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$customer) {
        flash('请先登录后再下单', 'error');
        redirect(url('user/login.php?redirect=' . urlencode('service/detail.php?id=' . $id)));
    }
    $specName = trim((string)($_POST['spec'] ?? ''));
    $contact  = trim((string)($_POST['contact'] ?? ''));
    $remark   = trim((string)($_POST['remark'] ?? ''));

    if ($contact === '') {
        $contact = (string)($customer['phone'] ?? '');
    }

    $res = YxServiceCenter::createOrder($item, $cid, $specName, null, [
        'contact' => $contact,
        'remark'  => $remark,
    ]);

    if (empty($res['ok'])) {
        flash($res['msg'], 'error');
        redirect(url('service/detail.php?id=' . $id));
    }
    log_action('service.order', (string)$item['name'], ['item' => $id, 'spec' => $specName]);

    if (!empty($res['unpaid']) && !empty($res['order_id'])) {
        flash('下单成功，请完成支付以安排服务');
        redirect(url('user/orders.php?pay=' . (int)$res['order_id']));
    }
    flash($res['msg']);
    redirect(url('user/service_orders.php'));
}

// 展示用价格区间
$priceFrom = YxServiceCenter::quote($item, $specs[0]['name'] ?? null)['amount'];
$priceTo   = $priceFrom;
foreach ($specs as $s) {
    $v = round_money((float)$s['price']);
    if ($v > 0 && $v > $priceTo) {
        $priceTo = $v;
    }
}

$pageTitle = $item['name'] . ' - 服务中心 - ' . setting('site_name', config('app.name'));
$pageFull  = true;
require dirname(__DIR__) . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/service.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/service.css') ?>">

<div class="yx-svc">
<div class="yx-svc-wrap">

    <nav class="yx-svc-crumb">
        <a href="<?= e(url('service/index.php')) ?>">服务中心</a>
        <span>/</span>
        <a href="<?= e(url('service/index.php?group=' . urlencode((string)$item['group_key']))) ?>"><?= e(YxServiceCenter::groupName((string)$item['group_key'])) ?></a>
        <span>/</span>
        <b><?= e($item['name']) ?></b>
    </nav>

    <div class="yx-svc-detail">
        <div class="yx-svc-detail-main">
            <div class="yx-svc-detail-head">
                <span class="yx-svc-ico is-lg"><?= e($item['icon'] ?: YxServiceCenter::groupIcon((string)$item['group_key'])) ?></span>
                <div>
                    <h1><?= e($item['name']) ?>
                        <?php if (!empty($item['badge'])): ?><span class="yx-svc-badge"><?= e($item['badge']) ?></span><?php endif; ?>
                    </h1>
                    <?php if (!empty($item['subtitle'])): ?><p class="yx-svc-sub"><?= e($item['subtitle']) ?></p><?php endif; ?>
                    <div class="yx-svc-detail-meta">
                        <span><?= e(YxServiceCenter::groupName((string)$item['group_key'])) ?></span>
                        <span><?= e(YxServiceCenter::billName($bill)) ?></span>
                        <?php if ((int)$item['sales'] > 0): ?><span>已服务 <?= (int)$item['sales'] ?> 次</span><?php endif; ?>
                        <?php if ((int)$item['stock'] > 0): ?><span>剩余 <?= (int)$item['stock'] ?> 份</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($item['description'])): ?>
                <div class="yx-svc-block">
                    <h2>服务说明</h2>
                    <p class="yx-svc-desc"><?= nl2br(e($item['description'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($features): ?>
                <div class="yx-svc-block">
                    <h2>包含内容</h2>
                    <ul class="yx-svc-feats is-lg">
                        <?php foreach ($features as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="yx-svc-block">
                <h2>交付方式</h2>
                <p class="yx-svc-desc">付款成功后，工程师将在 1 个工作日内与你联系，按约定时间远程或上门实施。服务过程中如需现场配合，我们会提前沟通。</p>
            </div>
        </div>

        <aside class="yx-svc-detail-side">
            <form method="post" class="yx-svc-buybox">
                <?= csrf_field() ?>
                <div class="yx-svc-buybox-price">
                    <?php if ($isQuote): ?>
                        <strong>面议</strong>
                        <small>提交需求后由工程师评估报价</small>
                    <?php else: ?>
                        <strong><?= e(YxServiceCenter::priceLabel($item, true)) ?></strong>
                        <?php if (count($specs) > 1 && $priceTo > $priceFrom): ?>
                            <small>¥<?= e(money($priceFrom)) ?> ~ ¥<?= e(money($priceTo)) ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($specs): ?>
                    <label class="yx-svc-field">选择规格
                        <select name="spec" id="svcSpec">
                            <?php foreach ($specs as $i => $s): ?>
                                <option value="<?= e($s['name']) ?>" data-price="<?= e(money(round_money((float)$s['price']))) ?>">
                                    <?= e($s['name']) ?><?= (float)$s['price'] > 0 ? '（¥' . e(money(round_money((float)$s['price']))) . '）' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <label class="yx-svc-field">联系方式
                    <input type="text" name="contact" value="<?= e($customer['phone'] ?? '') ?>" placeholder="手机 / 微信 / QQ" required>
                </label>

                <label class="yx-svc-field">需求说明
                    <textarea name="remark" rows="3" placeholder="简述网站环境、期望时间等，便于工程师准备"></textarea>
                </label>

                <?php if ($customer): ?>
                    <button class="yx-svc-submit" type="submit"><?= $isQuote ? '提交需求' : '立即购买' ?></button>
                    <p class="yx-svc-tip">下单后可在「我的服务」中查看进度</p>
                <?php else: ?>
                    <a class="yx-svc-submit is-link" href="<?= e(url('user/login.php?redirect=' . urlencode('service/detail.php?id=' . $id))) ?>">登录后购买</a>
                    <p class="yx-svc-tip">还没有账号？<a href="<?= e(url('user/register.php')) ?>">免费注册</a></p>
                <?php endif; ?>
            </form>
        </aside>
    </div>
</div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
