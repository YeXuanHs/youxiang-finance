<?php
/**
 * 优享财务系统 · 用户中心 · 推广商中心
 *
 * 推广商在此完成：入驻申请（上传支付宝 / 微信收款码）→ 维护推广产品 → 确认收款 → 查看结算。
 * 用户付款直接进入推广商自己的收款码，平台按佣金比例抽佣，结算金额 = 产品金额 × (1 − 佣金比例)。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

$promoter = YxPromoter::profile($cid);
$pid      = $promoter ? (int)$promoter['id'] : 0;
$tab      = (string)($_GET['tab'] ?? 'overview');

/* ---------------- 提交处理 ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $back   = 'overview';

    if ($action === 'apply') {
        $res  = YxPromoter::apply($cid, $_POST, $_FILES);
        $back = 'overview';
    } elseif ($action === 'save_product') {
        $back = 'products';
        if ($pid <= 0) {
            $res = ['ok' => false, 'msg' => '请先提交推广商资料'];
        } else {
            $res = YxPromoter::saveProduct($pid, $_POST, (int)($_POST['id'] ?? 0));
        }
    } elseif ($action === 'delete_product') {
        $back = 'products';
        $res  = $pid > 0
            ? YxPromoter::deleteProduct($pid, (int)($_POST['id'] ?? 0))
            : ['ok' => false, 'msg' => '请先提交推广商资料'];
    } elseif ($action === 'confirm_paid') {
        $back = 'orders';
        $res  = $pid > 0
            ? YxPromoter::confirmPaid((int)($_POST['id'] ?? 0), $pid, (string)($_POST['trade_no'] ?? ''))
            : ['ok' => false, 'msg' => '请先提交推广商资料'];
    } elseif ($action === 'reject_order') {
        $back = 'orders';
        $res  = $pid > 0
            ? YxPromoter::rejectOrder((int)($_POST['id'] ?? 0), $pid, (string)($_POST['remark'] ?? ''))
            : ['ok' => false, 'msg' => '请先提交推广商资料'];
    }

    log_action('user.promoter.' . $action, 'customer#' . $cid, ['ok' => !empty($res['ok']), 'msg' => (string)($res['msg'] ?? '')]);
    flash((string)($res['msg'] ?? ''), !empty($res['ok']) ? 'success' : 'error');
    redirect(url('user/promoter.php?tab=' . urlencode($back)));
}

/* ---------------- 展示数据 ---------------- */

$enabled  = YxPromoter::enabled();
$products = $pid > 0 ? YxPromoter::products($pid, false) : [];
$stats    = $pid > 0 ? YxPromoter::stats($pid) : null;
$editId   = (int)($_GET['edit'] ?? 0);
$editing  = $editId > 0 ? YxPromoter::productOf($editId, $pid) : null;

$orderTotal = $pid > 0 ? YxPromoter::orderCount($pid) : 0;
$page       = paginate($orderTotal, 20);
$orders     = $pid > 0 ? YxPromoter::orders($pid, '', $page['per_page'], $page['offset']) : [];

$statusBadge = function (string $s): string {
    $map = ['pending' => 'badge-warn', 'active' => 'badge-ok', 'disabled' => 'badge-off'];
    return $map[$s] ?? 'badge';
};
$orderBadge = function (string $s): string {
    $map = ['pending' => 'badge-warn', 'submitted' => 'badge-info', 'settled' => 'badge-ok', 'rejected' => 'badge-off'];
    return $map[$s] ?? 'badge';
};

$userTitle  = '推广商中心';
$userActive = 'promoter';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if (!$enabled): ?>
    <div class="alert alert-warn">推广商功能暂未开放，如需合作请联系平台客服。</div>
<?php endif; ?>

<?php if ($promoter): ?>
<div class="grid-4">
    <div class="stat accent"><span>推广商状态</span><strong><?= e(YxPromoter::statusName((string)$promoter['status'])) ?></strong></div>
    <div class="stat"><span>累计订单</span><strong><?= (int)$stats['orders'] ?></strong></div>
    <div class="stat"><span>累计成交额</span><strong><?= e(money($stats['amount'], true)) ?></strong></div>
    <div class="stat"><span>累计结款</span><strong><?= e(money($stats['settle'], true)) ?></strong></div>
</div>
<?php endif; ?>

<div class="cat-bar mt-4">
    <a class="<?= $tab === 'overview' ? 'active' : '' ?>" href="<?= e(url('user/promoter.php?tab=overview')) ?>">入驻资料</a>
    <a class="<?= $tab === 'products' ? 'active' : '' ?>" href="<?= e(url('user/promoter.php?tab=products')) ?>">推广产品</a>
    <a class="<?= $tab === 'orders' ? 'active' : '' ?>" href="<?= e(url('user/promoter.php?tab=orders')) ?>">订单与收款</a>
</div>

<?php if ($tab === 'products'): ?>
    <div class="card">
        <div class="section-head">
            <h2>推广产品</h2>
            <?php if ($pid > 0): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('user/promoter.php?tab=products')) ?>">清空表单</a>
            <?php endif; ?>
        </div>
        <?php if (!$promoter): ?>
            <p class="muted">请先在「入驻资料」中提交推广商资料并上传收款码。</p>
        <?php elseif ((string)$promoter['status'] === 'pending'): ?>
            <div class="alert alert-warn">您的入驻申请正在审核中，审核通过后产品才会展示给用户。</div>
        <?php endif; ?>

        <?php if ($pid > 0): ?>
        <form method="post" class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>产品名称 <input type="text" name="name" maxlength="80" required value="<?= e((string)($editing['name'] ?? '')) ?>"></label>
                <label>产品金额（元） <input type="number" name="amount" min="0.01" step="0.01" required value="<?= e((string)($editing['amount'] ?? '')) ?>"></label>
                <label>佣金比例（%）
                    <input type="number" name="commission_rate" min="0" max="99" step="0.01"
                           value="<?= e($editing ? (string)round((float)$editing['commission_rate'] * 100, 2) : '') ?>"
                           placeholder="留空则用默认 <?= e(YxPromoter::rateText((float)$promoter['commission_rate'])) ?>">
                </label>
                <label>排序（越小越靠前） <input type="number" name="sort" value="<?= (int)($editing['sort'] ?? 0) ?>"></label>
            </div>
            <div class="grid-2">
                <label class="checkbox"><input type="checkbox" name="pay_alipay" value="1" <?= (!$editing || strpos((string)$editing['pay_methods'], 'alipay') !== false) ? 'checked' : '' ?><?= empty($promoter['alipay_qr']) ? ' disabled' : '' ?>> 支持支付宝收款<?= empty($promoter['alipay_qr']) ? '（未上传收款码）' : '' ?></label>
                <label class="checkbox"><input type="checkbox" name="pay_wechat" value="1" <?= (!$editing || strpos((string)$editing['pay_methods'], 'wechat') !== false) ? 'checked' : '' ?><?= empty($promoter['wechat_qr']) ? ' disabled' : '' ?>> 支持微信收款<?= empty($promoter['wechat_qr']) ? '（未上传收款码）' : '' ?></label>
            </div>
            <div class="grid-2">
                <label>状态
                    <select name="status">
                        <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>上架</option>
                        <option value="offline" <?= ($editing['status'] ?? '') === 'offline' ? 'selected' : '' ?>>下架</option>
                    </select>
                </label>
                <label>备注 <input type="text" name="remark" maxlength="120" value="<?= e((string)($editing['remark'] ?? '')) ?>"></label>
            </div>
            <p class="muted small">
                平台佣金比例不低于 <?= e(YxPromoter::rateText((float)$promoter['commission_rate'])) ?>，
                结算金额 = 产品金额 × (1 − 佣金比例)，由平台在用户付款后结算给推广商。
            </p>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '添加产品' ?></button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($products): ?>
    <div class="card">
        <h2>产品列表</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>产品名称</th><th>产品金额</th><th>佣金比例</th><th>结算金额</th><th>收款方式</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e((string)$p['name']) ?></td>
                    <td><?= e(money($p['amount'], true)) ?></td>
                    <td><?= e(YxPromoter::rateText((float)$p['commission_rate'])) ?></td>
                    <td><?= e(money($p['settle_amount'], true)) ?></td>
                    <td><?= e(implode(' / ', array_map(function ($m) { return YxPromoter::payMethodName($m); }, array_filter(explode(',', (string)$p['pay_methods']))))) ?></td>
                    <td><span class="badge <?= $p['status'] === 'active' ? 'badge-ok' : 'badge-off' ?>"><?= $p['status'] === 'active' ? '上架' : '下架' ?></span></td>
                    <td class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('user/promoter.php?tab=products&edit=' . (int)$p['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确定删除该产品？已有订单的产品将改为下架。')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'orders'): ?>
    <div class="card">
        <div class="section-head">
            <h2>订单与收款</h2>
            <?php if ($promoter): ?>
                <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= e(url('promoter.php?p=' . YxPromoter::pageKey($promoter))) ?>">打开我的推广页 →</a>
            <?php endif; ?>
        </div>
        <p class="muted">用户在你的推广页下单后直接扫码付款给你；收到款项后点「确认收款」，平台随后按佣金比例结算，结算金额为你应得部分。</p>
        <?php if (!$orders): ?>
            <p class="muted mt-3">暂无订单。</p>
        <?php else: ?>
        <div class="table-wrap mt-3"><table class="data">
            <thead><tr><th>订单号</th><th>产品</th><th>买家</th><th>订单金额</th><th>平台佣金</th><th>你应得</th><th>收款方式</th><th>状态</th><th>下单时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="mono"><?= e((string)$o['sn']) ?></td>
                    <td><?= e((string)$o['product_name']) ?></td>
                    <td><?= e((string)$o['buyer']) ?><br><small class="muted"><?= e((string)$o['contact']) ?></small></td>
                    <td><?= e(money($o['amount'], true)) ?></td>
                    <td><?= e(money($o['commission_amount'], true)) ?></td>
                    <td><?= e(money($o['settle_amount'], true)) ?></td>
                    <td><?= e(YxPromoter::payMethodName((string)$o['pay_method'])) ?></td>
                    <td><span class="badge <?= $orderBadge((string)$o['status']) ?>"><?= e(YxPromoter::orderStatusName((string)$o['status'])) ?></span>
                        <?php if ((string)$o['status'] === 'rejected' && (string)$o['admin_remark'] !== ''): ?>
                            <br><small class="muted"><?= e((string)$o['admin_remark']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string)$o['created_at']) ?></td>
                    <td class="row-actions">
                        <?php if ((string)$o['status'] === 'pending'): ?>
                            <form method="post" onsubmit="return confirm('确认已收到该笔款项？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="confirm_paid">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <input type="text" name="trade_no" placeholder="收款流水号（选填）" maxlength="60">
                                <button class="btn btn-sm btn-primary" type="submit">确认收款</button>
                            </form>
                            <form method="post" onsubmit="return confirm('确认驳回该订单？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject_order">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <input type="text" name="remark" placeholder="驳回原因" maxlength="120">
                                <button class="btn btn-sm btn-danger" type="submit">驳回</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pager($page) ?>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="card">
        <div class="section-head">
            <h2>入驻资料</h2>
            <?php if ($promoter): ?>
                <span class="badge <?= $statusBadge((string)$promoter['status']) ?>"><?= e(YxPromoter::statusName((string)$promoter['status'])) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($promoter): ?>
            <table class="data mt-2">
                <tr><th>推广人</th><td><?= e((string)$promoter['name']) ?></td></tr>
                <tr><th>平台佣金比例</th><td><?= e(YxPromoter::rateText((float)$promoter['commission_rate'])) ?>（结算金额 = 订单金额 × <?= e(rtrim(rtrim(number_format((1 - (float)$promoter['commission_rate']) * 100, 2, '.', ''), '0'), '.')) ?>%）</td></tr>
                <tr><th>推广页</th><td class="mono"><a target="_blank" rel="noopener" href="<?= e(url('promoter.php?p=' . YxPromoter::pageKey($promoter))) ?>"><?= e(url('promoter.php?p=' . YxPromoter::pageKey($promoter))) ?></a></td></tr>
                <?php if ((string)$promoter['status'] === 'disabled' && (string)$promoter['remark'] !== ''): ?>
                    <tr><th>停用原因</th><td class="warn"><?= e((string)$promoter['remark']) ?></td></tr>
                <?php endif; ?>
            </table>
        <?php endif; ?>

        <?php if (!$enabled && !$promoter): ?>
            <p class="muted">功能未开放，暂不能提交入驻。</p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <div class="grid-2">
                <label>推广人名称 <input type="text" name="name" maxlength="40" required value="<?= e((string)($promoter['name'] ?? ($customer['realname'] ?: $customer['username']))) ?>"></label>
                <label>手机号 <input type="text" name="phone" maxlength="20" value="<?= e((string)($promoter['phone'] ?? ($customer['phone'] ?? ''))) ?>"></label>
                <label>联系方式（微信 / QQ） <input type="text" name="contact" maxlength="60" value="<?= e((string)($promoter['contact'] ?? '')) ?>"></label>
            </div>

            <div class="grid-2">
                <label>支付宝收款码（jpg / png / webp，≤3MB）
                    <input type="file" name="alipay_qr" accept="image/jpeg,image/png,image/webp">
                </label>
                <label>支付宝收款人 <input type="text" name="alipay_name" maxlength="40" value="<?= e((string)($promoter['alipay_name'] ?? '')) ?>"></label>
                <?php if (!empty($promoter['alipay_qr'])): ?>
                    <div class="muted small">当前支付宝收款码：<br>
                        <img src="<?= e(yx_upload_url((string)$promoter['alipay_qr'])) ?>" alt="支付宝收款码" style="max-width:150px;border-radius:8px;border:1px solid #e6ebf2;margin-top:6px">
                    </div>
                <?php endif; ?>
                <label>微信收款码（jpg / png / webp，≤3MB）
                    <input type="file" name="wechat_qr" accept="image/jpeg,image/png,image/webp">
                </label>
                <label>微信收款人 <input type="text" name="wechat_name" maxlength="40" value="<?= e((string)($promoter['wechat_name'] ?? '')) ?>"></label>
                <?php if (!empty($promoter['wechat_qr'])): ?>
                    <div class="muted small">当前微信收款码：<br>
                        <img src="<?= e(yx_upload_url((string)$promoter['wechat_qr'])) ?>" alt="微信收款码" style="max-width:150px;border-radius:8px;border:1px solid #e6ebf2;margin-top:6px">
                    </div>
                <?php endif; ?>
            </div>
            <p class="muted small">用户通过你的推广页下单后，直接扫码付款到你的支付宝 / 微信账户；平台按佣金比例抽取服务费，剩余部分为你的结算金额。</p>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $promoter ? '保存资料' : '提交入驻申请' ?></button>
            </div>
        </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
