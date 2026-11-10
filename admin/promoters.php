<?php
/**
 * 优享财务系统 · 推广商管理（后台）
 *
 * 四个页签：
 *   推广商  审核入驻、调整佣金比例、启停用、查看推广页链接
 *   产品    查看 / 新增 / 修改各推广商的推广产品（产品金额与佣金比例决定结算金额）
 *   订单    推广商确认收款后的订单在此结算：平台佣金记入站方收入，结算金额归推广商
 *   设置    功能开关与默认佣金比例
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$tab = (string)($_GET['tab'] ?? 'promoters');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $back   = (string)($_POST['tab'] ?? $tab);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'promoter_status') {
        $res  = YxPromoter::setStatus((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''), (string)($_POST['remark'] ?? ''));
        $back = 'promoters';
    } elseif ($action === 'promoter_rate') {
        $rate = (float)($_POST['commission_rate'] ?? 0) / 100;
        $res  = YxPromoter::setRate((int)($_POST['id'] ?? 0), $rate);
        $back = 'promoters';
    } elseif ($action === 'product_save') {
        $res  = YxPromoter::saveProduct((int)($_POST['promoter_id'] ?? 0), $_POST, (int)($_POST['id'] ?? 0));
        $back = 'products';
    } elseif ($action === 'product_delete') {
        $res  = YxPromoter::deleteProduct((int)($_POST['promoter_id'] ?? 0), (int)($_POST['id'] ?? 0));
        $back = 'products';
    } elseif ($action === 'settle') {
        $res  = YxPromoter::settle((int)($_POST['id'] ?? 0), (string)($_POST['remark'] ?? ''));
        $back = 'orders';
    } elseif ($action === 'reject') {
        $res  = YxPromoter::reject((int)($_POST['id'] ?? 0), (string)($_POST['remark'] ?? ''));
        $back = 'orders';
    } elseif ($action === 'save_settings') {
        set_setting('promoter_enabled', !empty($_POST['promoter_enabled']) ? '1' : '0');
        $rate = max(0, min(99, (float)($_POST['promoter_default_rate'] ?? 10)));
        set_setting('promoter_default_rate', (string)round($rate, 2));
        $res  = ['ok' => true, 'msg' => '设置已保存'];
        $back = 'settings';
    }

    log_action('promoter.admin.' . $action, '', ['ok' => !empty($res['ok']), 'msg' => (string)($res['msg'] ?? '')]);
    flash((string)($res['msg'] ?? ''), !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/promoters.php?tab=' . urlencode($back)));
}

/* ---------------- 数据 ---------------- */

$tPromoter = YxDb::table('promoter');
$tProduct  = YxDb::table('promoter_product');
$tOrder    = YxDb::table('promoter_order');

$pendingPromoters  = (int)YxDb::value("SELECT COUNT(*) FROM {$tPromoter} WHERE status = 'pending'");
$pendingSettlements = (int)YxDb::value("SELECT COUNT(*) FROM {$tOrder} WHERE status = 'submitted'");

$promoters = YxDb::all(
    'SELECT p.*, c.username FROM ' . $tPromoter . ' p
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = p.customer_id
     ORDER BY p.id DESC LIMIT 200'
);
$promoterMap = [];
foreach ($promoters as $p) {
    $promoterMap[(int)$p['id']] = $p;
}

$pvPromoter = (int)($_GET['promoter_id'] ?? 0);
$products   = YxDb::all(
    'SELECT pp.*, p.name AS promoter_name FROM ' . $tProduct . ' pp
     LEFT JOIN ' . $tPromoter . ' p ON p.id = pp.promoter_id'
    . ($pvPromoter > 0 ? ' WHERE pp.promoter_id = ' . $pvPromoter : '')
    . ' ORDER BY pp.promoter_id DESC, pp.sort ASC, pp.id DESC LIMIT 300'
);

$oSql = 'SELECT o.*, p.name AS promoter_name FROM ' . $tOrder . ' o
     LEFT JOIN ' . $tPromoter . ' p ON p.id = o.promoter_id';
$oStatus = (string)($_GET['status'] ?? '');
$oBind = [];
if (in_array($oStatus, ['pending', 'submitted', 'settled', 'rejected'], true)) {
    $oSql .= ' WHERE o.status = ?';
    $oBind[] = $oStatus;
}
$oTotal = (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . $tOrder . ' o' . ($oBind ? ' WHERE o.status = ?' : ''),
    $oBind
);
$page = paginate($oTotal, 30);
$orders = YxDb::all($oSql . ' ORDER BY o.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'], $oBind);

$sumStat = YxDb::one(
    "SELECT COALESCE(SUM(CASE WHEN status = 'settled' THEN amount ELSE 0 END), 0) AS amount,
            COALESCE(SUM(CASE WHEN status = 'settled' THEN commission_amount ELSE 0 END), 0) AS commission,
            COALESCE(SUM(CASE WHEN status = 'settled' THEN settle_amount ELSE 0 END), 0) AS settle
     FROM {$tOrder}"
);

$editProduct = null;
if ($tab === 'products' && (int)($_GET['edit'] ?? 0) > 0) {
    $editProduct = YxDb::one('SELECT * FROM ' . $tProduct . ' WHERE id = ?', [(int)$_GET['edit']]);
}

$promoterBadge = function (string $s): string {
    $map = ['pending' => 'badge-warn', 'active' => 'badge-ok', 'disabled' => 'badge-off'];
    return $map[$s] ?? 'badge-mute';
};
$orderBadge = function (string $s): string {
    $map = ['pending' => 'badge-warn', 'submitted' => 'badge-info', 'settled' => 'badge-ok', 'rejected' => 'badge-off'];
    return $map[$s] ?? 'badge-mute';
};

$pageTitle  = '推广商';
$activeMenu = 'promoters';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="stat accent"><span>待审核推广商</span><strong><?= $pendingPromoters ?></strong></div>
    <div class="stat"><span>待结算订单</span><strong><?= $pendingSettlements ?></strong></div>
    <div class="stat"><span>累计成交额</span><strong><?= e(money($sumStat['amount'] ?? 0, true)) ?></strong></div>
    <div class="stat"><span>累计平台佣金</span><strong><?= e(money($sumStat['commission'] ?? 0, true)) ?></strong></div>
</div>

<div class="cat-bar mt-4">
    <a class="<?= $tab === 'promoters' ? 'active' : '' ?>" href="<?= e(url('admin/promoters.php?tab=promoters')) ?>">推广商（<?= $pendingPromoters ?> 待审）</a>
    <a class="<?= $tab === 'products' ? 'active' : '' ?>" href="<?= e(url('admin/promoters.php?tab=products')) ?>">推广产品</a>
    <a class="<?= $tab === 'orders' ? 'active' : '' ?>" href="<?= e(url('admin/promoters.php?tab=orders')) ?>">订单结算（<?= $pendingSettlements ?> 待结）</a>
    <a class="<?= $tab === 'settings' ? 'active' : '' ?>" href="<?= e(url('admin/promoters.php?tab=settings')) ?>">设置</a>
</div>

<?php if ($tab === 'products'): ?>
    <div class="card">
        <div class="section-head">
            <h2><?= $editProduct ? '编辑推广产品' : '新增推广产品' ?></h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/promoters.php?tab=products' . ($pvPromoter > 0 ? '&promoter_id=' . $pvPromoter : ''))) ?>">清空表单</a>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="product_save">
            <input type="hidden" name="tab" value="products">
            <input type="hidden" name="id" value="<?= (int)($editProduct['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>推广商
                    <select name="promoter_id" required>
                        <option value="">请选择推广商</option>
                        <?php foreach ($promoters as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)($editProduct['promoter_id'] ?? $pvPromoter) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e((string)$p['name']) ?>（<?= e(YxPromoter::statusName((string)$p['status'])) ?>，佣金 <?= e(YxPromoter::rateText((float)$p['commission_rate'])) ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>产品名称 <input type="text" name="name" maxlength="80" required value="<?= e((string)($editProduct['name'] ?? '')) ?>"></label>
                <label>产品金额（元） <input type="number" name="amount" min="0.01" step="0.01" required value="<?= e((string)($editProduct['amount'] ?? '')) ?>"></label>
                <label>佣金比例（%）
                    <input type="number" name="commission_rate" min="0" max="99" step="0.01"
                           value="<?= e($editProduct ? (string)round((float)$editProduct['commission_rate'] * 100, 2) : '') ?>"
                           placeholder="留空则跟随推广商当前比例">
                </label>
                <label>排序 <input type="number" name="sort" value="<?= (int)($editProduct['sort'] ?? 0) ?>"></label>
                <label>状态
                    <select name="status">
                        <option value="active" <?= ($editProduct['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>上架</option>
                        <option value="offline" <?= ($editProduct['status'] ?? '') === 'offline' ? 'selected' : '' ?>>下架</option>
                    </select>
                </label>
            </div>
            <div class="grid-2">
                <label class="checkbox"><input type="checkbox" name="pay_alipay" value="1" <?= (!$editProduct || strpos((string)$editProduct['pay_methods'], 'alipay') !== false) ? 'checked' : '' ?>> 支持支付宝收款</label>
                <label class="checkbox"><input type="checkbox" name="pay_wechat" value="1" <?= (!$editProduct || strpos((string)$editProduct['pay_methods'], 'wechat') !== false) ? 'checked' : '' ?>> 支持微信收款</label>
            </div>
            <label>备注 <input type="text" name="remark" maxlength="120" value="<?= e((string)($editProduct['remark'] ?? '')) ?>"></label>
            <p class="muted small">结算金额 = 产品金额 ×（1 − 佣金比例）；佣金比例不得低于推广商默认比例，未上传对应收款方式时该方式会被忽略。</p>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $editProduct ? '保存修改' : '添加产品' ?></button></div>
        </form>
    </div>

    <div class="card">
        <div class="section-head">
            <h2>推广产品（<?= count($products) ?>）</h2>
            <form method="get" class="inline-form">
                <input type="hidden" name="tab" value="products">
                <label>推广商
                    <select name="promoter_id">
                        <option value="0">全部</option>
                        <?php foreach ($promoters as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $pvPromoter === (int)$p['id'] ? 'selected' : '' ?>><?= e((string)$p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit">筛选</button>
            </form>
        </div>
        <?php if (!$products): ?>
            <p class="muted">暂无推广产品。</p>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>推广商</th><th>产品名称</th><th>产品金额</th><th>佣金比例</th><th>结算金额</th><th>收款方式</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e((string)$p['promoter_name']) ?></td>
                    <td><?= e((string)$p['name']) ?></td>
                    <td><?= e(money($p['amount'], true)) ?></td>
                    <td><?= e(YxPromoter::rateText((float)$p['commission_rate'])) ?></td>
                    <td><?= e(money($p['settle_amount'], true)) ?></td>
                    <td><?= e(implode(' / ', array_map(function ($m) { return YxPromoter::payMethodName($m); }, array_filter(explode(',', (string)$p['pay_methods']))))) ?></td>
                    <td><span class="badge <?= $p['status'] === 'active' ? 'badge-ok' : 'badge-off' ?>"><?= $p['status'] === 'active' ? '上架' : '下架' ?></span></td>
                    <td class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/promoters.php?tab=products&edit=' . (int)$p['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确定删除该产品？已有订单的产品将改为下架。')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="product_delete">
                            <input type="hidden" name="tab" value="products">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="promoter_id" value="<?= (int)$p['promoter_id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'orders'): ?>
    <div class="card">
        <div class="section-head">
            <h2>推广订单</h2>
            <form method="get" class="inline-form">
                <input type="hidden" name="tab" value="orders">
                <label>状态
                    <select name="status">
                        <option value="">全部</option>
                        <?php foreach (['pending', 'submitted', 'settled', 'rejected'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $oStatus === $s ? 'selected' : '' ?>><?= e(YxPromoter::orderStatusName($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit">筛选</button>
            </form>
        </div>
        <p class="muted">结算后平台佣金（<strong><?= e(money($sumStat['commission'] ?? 0, true)) ?></strong>）记入站方收入，推广商结款
            <strong><?= e(money($sumStat['settle'] ?? 0, true)) ?></strong> 不进入客户余额，由平台线下 / 线上另行支付给推广商。</p>
        <?php if (!$orders): ?>
            <p class="muted mt-3">暂无订单。</p>
        <?php else: ?>
        <div class="table-wrap mt-3"><table class="data">
            <thead><tr><th>订单号</th><th>推广商</th><th>产品</th><th>买家</th><th>订单金额</th><th>佣金比例</th><th>平台佣金</th><th>推广商应得</th><th>收款</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="mono"><?= e((string)$o['sn']) ?></td>
                    <td><?= e((string)$o['promoter_name']) ?></td>
                    <td><?= e((string)$o['product_name']) ?></td>
                    <td><?= e((string)$o['buyer']) ?><br><small class="muted"><?= e((string)$o['contact']) ?></small></td>
                    <td><?= e(money($o['amount'], true)) ?></td>
                    <td><?= e(YxPromoter::rateText((float)$o['commission_rate'])) ?></td>
                    <td><?= e(money($o['commission_amount'], true)) ?></td>
                    <td><?= e(money($o['settle_amount'], true)) ?></td>
                    <td><?= e(YxPromoter::payMethodName((string)$o['pay_method'])) ?>
                        <?php if ((string)$o['proof_image'] !== ''): ?>
                            <br><a class="small" target="_blank" rel="noopener" href="<?= e(yx_upload_url((string)$o['proof_image'])) ?>">查看凭证</a>
                        <?php endif; ?>
                        <?php if ((string)$o['trade_no'] !== ''): ?>
                            <br><small class="muted mono"><?= e((string)$o['trade_no']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $orderBadge((string)$o['status']) ?>"><?= e(YxPromoter::orderStatusName((string)$o['status'])) ?></span>
                        <?php if ((string)$o['admin_remark'] !== ''): ?><br><small class="muted"><?= e((string)$o['admin_remark']) ?></small><?php endif; ?>
                    </td>
                    <td><?= e((string)$o['created_at']) ?>
                        <?php if ((string)$o['settled_at'] !== ''): ?><br><small class="muted">结算 <?= e((string)$o['settled_at']) ?></small><?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <?php if ((string)$o['status'] === 'submitted'): ?>
                            <form method="post" onsubmit="return confirm('确认结算该订单？将按佣金比例登记平台佣金收入。')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="settle">
                                <input type="hidden" name="tab" value="orders">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <input type="text" name="remark" placeholder="结算备注（选填）" maxlength="120">
                                <button class="btn btn-sm btn-primary" type="submit">结算</button>
                            </form>
                            <form method="post" onsubmit="return confirm('确认驳回该订单？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="tab" value="orders">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <input type="text" name="remark" placeholder="驳回原因" maxlength="120">
                                <button class="btn btn-sm btn-danger" type="submit">驳回</button>
                            </form>
                        <?php elseif ((string)$o['status'] === 'pending'): ?>
                            <form method="post" onsubmit="return confirm('确认驳回该订单？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="tab" value="orders">
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

<?php elseif ($tab === 'settings'): ?>
    <div class="card" style="max-width:720px">
        <h2>推广商设置</h2>
        <form method="post" class="mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="tab" value="settings">
            <label class="checkbox"><input type="checkbox" name="promoter_enabled" value="1" <?= YxPromoter::enabled() ? 'checked' : '' ?>> 开启推广商功能（关闭后前台推广页与入驻申请均不可用）</label>
            <label>新入驻默认佣金比例（%）
                <input type="number" name="promoter_default_rate" min="0" max="99" step="0.01" value="<?= e((string)setting('promoter_default_rate', '10')) ?>">
            </label>
            <p class="muted small">佣金比例即平台抽成：结算金额 = 订单金额 ×（1 − 佣金比例）。调整已有推广商比例请到「推广商」页签单独设置。</p>
            <div class="form-actions"><button class="btn btn-primary" type="submit">保存设置</button></div>
        </form>
    </div>

<?php else: ?>
    <div class="card">
        <div class="section-head">
            <h2>推广商（<?= count($promoters) ?>）</h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('promoter.php')) ?>" target="_blank" rel="noopener">前台推广页 →</a>
        </div>
        <?php if (!$promoters): ?>
            <p class="muted">暂无推广商入驻记录。</p>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>推广人</th><th>账号</th><th>联系方式</th><th>收款码</th><th>佣金比例</th><th>业绩</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($promoters as $p): ?>
                <tr>
                    <td><?= e((string)$p['name']) ?><br><small class="muted mono">#<?= (int)$p['id'] ?></small></td>
                    <td><?= e((string)($p['username'] ?? '-')) ?></td>
                    <td><?= e((string)($p['phone'] ?: '-')) ?><br><small class="muted"><?= e((string)($p['contact'] ?: '')) ?></small></td>
                    <td>
                        <?php if ((string)$p['alipay_qr'] !== ''): ?><a target="_blank" rel="noopener" href="<?= e(yx_upload_url((string)$p['alipay_qr'])) ?>">支付宝</a><?php endif; ?>
                        <?php if ((string)$p['wechat_qr'] !== ''): ?> <a target="_blank" rel="noopener" href="<?= e(yx_upload_url((string)$p['wechat_qr'])) ?>">微信</a><?php endif; ?>
                        <?php if ((string)$p['alipay_qr'] === '' && (string)$p['wechat_qr'] === ''): ?><span class="muted">未上传</span><?php endif; ?>
                    </td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="promoter_rate">
                            <input type="hidden" name="tab" value="promoters">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="number" name="commission_rate" min="0" max="99" step="0.01"
                                   value="<?= e((string)round((float)$p['commission_rate'] * 100, 2)) ?>" style="width:80px">
                            <button class="btn btn-sm btn-ghost" type="submit">%</button>
                        </form>
                    </td>
                    <td>
                        <?= (int)$p['order_count'] ?> 单<br>
                        <small class="muted">成交 <?= e(money($p['order_amount'], true)) ?><br>
                        佣金 <?= e(money($p['commission_total'], true)) ?><br>
                        结款 <?= e(money($p['settle_amount'], true)) ?></small>
                    </td>
                    <td><span class="badge <?= $promoterBadge((string)$p['status']) ?>"><?= e(YxPromoter::statusName((string)$p['status'])) ?></span>
                        <?php if ((string)$p['remark'] !== ''): ?><br><small class="muted"><?= e((string)$p['remark']) ?></small><?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <?php if ((string)$p['status'] !== 'active'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="promoter_status">
                                <input type="hidden" name="tab" value="promoters">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="status" value="active">
                                <button class="btn btn-sm btn-primary" type="submit">通过并启用</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((string)$p['status'] !== 'disabled'): ?>
                            <form method="post" onsubmit="return confirm('确定停用该推广商？其推广页将立即不可访问。')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="promoter_status">
                                <input type="hidden" name="tab" value="promoters">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="status" value="disabled">
                                <input type="text" name="remark" placeholder="停用原因" maxlength="120">
                                <button class="btn btn-sm btn-danger" type="submit">停用</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/promoters.php?tab=products&promoter_id=' . (int)$p['id'])) ?>">产品</a>
                        <?php if ((string)$p['status'] === 'active'): ?>
                            <a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="<?= e(url('promoter.php?p=' . YxPromoter::pageKey($p))) ?>">推广页</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
