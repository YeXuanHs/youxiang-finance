<?php
/**
 * 优享财务系统 · 推广商推广页（前台）
 *
 * 访问方式：
 *   promoter.php                  推广商列表
 *   promoter.php?p={key}          某个推广商的产品列表与下单
 *   promoter.php?p={key}&sn={sn}  下单后的付款码 / 订单状态
 *
 * 用户付款直接扫码进入推广商自己的支付宝 / 微信账户；平台按佣金比例抽佣，
 * 推广商在用户中心确认收款后由平台结算（结算金额 = 金额 × (1 − 佣金比例)）。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$enabled = YxPromoter::enabled();
if (!$enabled) {
    $pageTitle = '推广合作';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><h2>推广合作</h2><p class="muted">推广合作暂未开放，欢迎稍后再来。</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$me  = YxAuth::customer();
$key = trim((string)($_GET['p'] ?? ''));
$sn  = trim((string)($_GET['sn'] ?? ''));

$methodName = function (string $m): string {
    return YxPromoter::payMethodName($m);
};

/* ---------------- 下单 ---------------- */

$order = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'order') {
        $pidKey = YxPromoter::idFromKey((string)($_POST['p'] ?? ''));
        $res = YxPromoter::createOrder(
            $pidKey,
            (int)($_POST['product_id'] ?? 0),
            $_POST,
            $me ? (int)$me['id'] : 0,
            $_FILES
        );
        if (!empty($res['ok'])) {
            flash('下单成功，请扫码付款');
            redirect(url('promoter.php?p=' . rawurlencode((string)($_POST['p'] ?? '')) . '&sn=' . urlencode((string)$res['sn'])));
        }
        flash((string)($res['msg'] ?? '下单失败'), 'error');
        redirect(url('promoter.php?p=' . rawurlencode((string)($_POST['p'] ?? ''))));
    }
    flash('未知操作', 'error');
    redirect(url('promoter.php'));
}

/* ---------------- 推广商列表 ---------------- */

if ($key === '') {
    $rows = YxDb::all(
        'SELECT p.*, (SELECT COUNT(*) FROM ' . YxDb::table('promoter_product')
        . " pp WHERE pp.promoter_id = p.id AND pp.status = 'active') AS product_count FROM "
        . YxDb::table('promoter') . " p WHERE p.status = 'active' ORDER BY p.id DESC LIMIT 60"
    );
    $pageTitle = '推广合作 · 官方推广商';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <div class="section-head">
            <h2>推广商</h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('user/promoter.php')) ?>">我要成为推广商 →</a>
        </div>
        <p class="muted">选择推广商进入其推广页挑选产品，付款直接转入推广商收款账户，平台提供订单与结算保障。</p>
        <?php if (!$rows): ?>
            <p class="muted mt-3">暂无在营推广商。</p>
        <?php else: ?>
        <div class="grid-3 mt-3">
            <?php foreach ($rows as $r): ?>
                <div class="card">
                    <h3><?= e((string)$r['name']) ?></h3>
                    <p class="muted small">在售产品 <?= (int)$r['product_count'] ?> 个</p>
                    <?php if ((string)$r['contact'] !== ''): ?>
                        <p class="muted small">联系：<?= e((string)$r['contact']) ?></p>
                    <?php endif; ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('promoter.php?p=' . YxPromoter::pageKey($r))) ?>">查看推广产品</a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------------- 单个推广商 ---------------- */

$promoterId = YxPromoter::idFromKey($key);
$promoter   = $promoterId > 0 ? YxPromoter::byId($promoterId) : null;
if (!$promoter || (string)$promoter['status'] !== 'active') {
    $pageTitle = '推广页不可用';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><h2>推广页不可用</h2><p class="muted">该推广商不存在或已停用，请返回 <a href="' . e(url('promoter.php')) . '">推广商列表</a>。</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* 订单状态页（付款码 + 状态） */
if ($sn !== '') {
    $o = YxPromoter::orderBySn($sn);
    if (!$o || (int)$o['promoter_id'] !== (int)$promoter['id']) {
        $o = null;
    }
    $pageTitle = $o ? ('订单 ' . (string)$o['sn']) : '订单不存在';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <div class="section-head">
            <h2>订单详情</h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('promoter.php?p=' . rawurlencode($key))) ?>">返回推广页</a>
        </div>
        <?php if (!$o): ?>
            <p class="muted">订单不存在或不属于该推广商。</p>
        <?php else: ?>
            <table class="data mt-2">
                <tr><th>订单号</th><td class="mono"><?= e((string)$o['sn']) ?></td></tr>
                <tr><th>产品</th><td><?= e((string)$o['product_name']) ?></td></tr>
                <tr><th>订单金额</th><td><strong><?= e(money($o['amount'], true)) ?></strong></td></tr>
                <tr><th>收款方式</th><td><?= e($methodName((string)$o['pay_method'])) ?></td></tr>
                <tr><th>订单状态</th><td><?= e(YxPromoter::orderStatusName((string)$o['status'])) ?></td></tr>
                <tr><th>下单时间</th><td><?= e((string)$o['created_at']) ?></td></tr>
            </table>

            <?php if ((string)$o['status'] === 'pending'): ?>
                <div class="alert alert-warn mt-3">
                    请使用<?= e($methodName((string)$o['pay_method'])) ?>扫描下方收款码，向
                    <strong><?= e((string)($promoter[$o['pay_method'] . '_name'] ?: $promoter['name'])) ?></strong>
                    支付 <strong><?= e(money($o['amount'], true)) ?></strong>，付款后请保留凭证等待推广商确认。
                </div>
                <div style="text-align:center;margin:16px 0">
                    <img src="<?= e(yx_upload_url((string)$promoter[$o['pay_method'] . '_qr'])) ?>"
                         alt="<?= e($methodName((string)$o['pay_method'])) ?>收款码"
                         style="max-width:260px;width:100%;border-radius:12px;border:1px solid #e6ebf2;background:#fff;padding:10px">
                </div>
                <p class="muted small">付款时请在备注中填写订单号 <span class="mono"><?= e((string)$o['sn']) ?></span>，便于推广商核对到账。</p>
            <?php elseif ((string)$o['status'] === 'submitted'): ?>
                <div class="alert alert-ok mt-3">推广商已确认收款，等待平台结算。结算后本条订单状态将更新为「已结算」。</div>
            <?php elseif ((string)$o['status'] === 'settled'): ?>
                <div class="alert alert-ok mt-3">该订单已完成结算，感谢你的支持。</div>
            <?php else: ?>
                <div class="alert alert-error mt-3">该订单已被驳回：<?= e((string)($o['admin_remark'] ?: '请联系推广商核实')) ?></div>
            <?php endif; ?>
            <p class="muted small">请保存本页链接以便随时查看订单状态：<span class="mono"><?= e(url('promoter.php?p=' . $key . '&sn=' . (string)$o['sn'])) ?></span></p>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* 产品列表 + 下单表单 */
$products = YxPromoter::products((int)$promoter['id'], true);
$pageTitle = (string)$promoter['name'] . ' · 推广产品';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="section-head">
        <h2><?= e((string)$promoter['name']) ?></h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('promoter.php')) ?>">全部推广商</a>
    </div>
    <p class="muted">
        本页由推广商独立运营，订单款项直接转入推广商收款账户，平台负责订单留痕与结算监督。
        <?php if ((string)$promoter['contact'] !== ''): ?>联系方式：<?= e((string)$promoter['contact']) ?><?php endif; ?>
    </p>

    <?php if (!$products): ?>
        <p class="muted mt-3">该推广商暂无在售产品。</p>
    <?php else: ?>
        <div class="grid-2 mt-3">
        <?php foreach ($products as $p):
            $methods  = array_values(array_filter(explode(',', (string)$p['pay_methods'])));
            $only     = count($methods) === 1 ? $methods[0] : '';
            ?>
            <div class="card">
                <h3><?= e((string)$p['name']) ?></h3>
                <p class="muted small" style="font-size:22px;font-weight:700;color:#1f2733;margin:6px 0"><?= e(money($p['amount'], true)) ?></p>
                <?php if ((string)$p['remark'] !== ''): ?>
                    <p class="muted small"><?= e((string)$p['remark']) ?></p>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="order">
                    <input type="hidden" name="p" value="<?= e($key) ?>">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <div class="grid-2">
                        <label>您的称呼 <input type="text" name="buyer" maxlength="30" required value="<?= e((string)($me['realname'] ?? '')) ?>"></label>
                        <label>联系方式 <input type="text" name="contact" maxlength="60" required placeholder="手机 / 微信 / 邮箱" value="<?= e((string)($me['phone'] ?? $me['email'] ?? '')) ?>"></label>
                    </div>
                    <?php if ($methods): ?>
                        <label>收款方式
                            <select name="pay_method">
                                <?php foreach ($methods as $m): ?>
                                    <option value="<?= e($m) ?>" <?= $only === $m ? 'selected' : '' ?>><?= e($methodName($m)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <label>付款凭证（可选，jpg / png / webp，≤3MB）
                        <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">立即下单</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
