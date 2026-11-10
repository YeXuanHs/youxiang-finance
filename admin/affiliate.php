<?php
/**
 * 优享财务系统 · 推广计划（管理端）
 *
 * 两件事：
 *  1. 配置：佣金比例（%）、结款门槛（元）、推广计划开关；
 *  2. 佣金：按推广人汇总「已计提未结款」金额，支持一键结款（写支出流水并标记已结）。
 *
 * 结款只从站方支出侧登记流水，不增减客户钱包余额；已结款订单不会被重复结。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$res = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $rate = (float)($_POST['rate'] ?? 10);
        $rate = max(0, min(90, round($rate, 2)));
        $minPay = (float)($_POST['min_pay'] ?? 100);
        $minPay = max(0, round_money($minPay));
        $enabled = !empty($_POST['enabled']) ? '1' : '0';

        set_setting(YxAffiliate::SET_RATE, (string)$rate);
        set_setting(YxAffiliate::SET_MINPAY, (string)$minPay);
        set_setting(YxAffiliate::SET_ENABLED, $enabled);
        log_action('affiliate.config', 'setting', ['rate' => $rate, 'min_pay' => $minPay, 'enabled' => $enabled]);
        $res = ['ok' => true, 'msg' => '推广计划配置已保存'];
    } elseif ($action === 'settle') {
        $res = YxAffiliate::settle((int)($_POST['customer_id'] ?? 0), (int)$admin['id']);
    } else {
        $res = ['ok' => false, 'msg' => '未知操作'];
    }
}

$rate    = (float)setting(YxAffiliate::SET_RATE, 10);
$minPay  = YxAffiliate::minPay();
$enabled = YxAffiliate::enabled();

$tO = YxDb::table('order');
$tC = YxDb::table('customer');

// 按推广人汇总佣金（含待结 / 已结），仅列有佣金记录的推广人
$rows = YxDb::all(
    "SELECT o.publisher_id,
            c.username, c.user_no,
            COUNT(o.id) AS orders,
            COALESCE(SUM(o.total), 0) AS gmv,
            COALESCE(SUM(o.affiliate_commission), 0) AS total_commission,
            COALESCE(SUM(CASE WHEN o.affiliate_settled_at IS NULL THEN o.affiliate_commission ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN o.affiliate_settled_at IS NOT NULL THEN o.affiliate_commission ELSE 0 END), 0) AS settled,
            (SELECT COUNT(*) FROM {$tC} r WHERE r.publisher_id = o.publisher_id) AS referrals
       FROM {$tO} o
       LEFT JOIN {$tC} c ON c.id = o.publisher_id
      WHERE o.publisher_id > 0 AND o.affiliate_commission > 0
      GROUP BY o.publisher_id
      ORDER BY pending DESC, total_commission DESC"
);

$sumPending = 0.0;
$sumSettled = 0.0;
foreach ($rows as $r) {
    $sumPending += (float)$r['pending'];
    $sumSettled += (float)$r['settled'];
}

// 最近佣金明细
$recent = YxDb::all(
    "SELECT o.id, o.sn, o.name, o.total, o.paid_at, o.affiliate_rate, o.affiliate_commission,
            o.affiliate_settled_at, o.publisher_id, c.username
       FROM {$tO} o
       LEFT JOIN {$tC} c ON c.id = o.publisher_id
      WHERE o.publisher_id > 0 AND o.affiliate_commission > 0
      ORDER BY o.id DESC LIMIT 50"
);

$pageTitle  = '推广计划';
$activeMenu = 'affiliate';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<?php if ($res): ?>
    <div class="alert <?= $res['ok'] ? 'alert-ok' : 'alert-error' ?>"><?= e($res['msg']) ?></div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h2>推广计划配置</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <label>佣金比例（%）
                <input type="number" name="rate" step="0.01" min="0" max="90" value="<?= e((string)$rate) ?>" required>
            </label>
            <label>结款门槛（元）
                <input type="number" name="min_pay" step="0.01" min="0" value="<?= e((string)$minPay) ?>">
            </label>
            <label class="checkbox">
                <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                开放推广计划（用户中心「代理中心 → 推广计划」可见）
            </label>
            <p class="muted small">佣金按订单实付金额计提，比例上限 90%；自购订单不计佣金，已退款订单不重复计提。</p>
            <button class="btn btn-primary" type="submit">保存配置</button>
        </form>
    </div>

    <div class="card">
        <h2>汇总</h2>
        <table class="data">
            <tr><th>推广人数</th><td><b><?= count($rows) ?></b> 人</td></tr>
            <tr><th>有佣金订单</th><td><b><?= (int)array_sum(array_map(function ($r) { return (int)$r['orders']; }, $rows)) ?></b> 笔</td></tr>
            <tr><th>佣金总额</th><td><?= e(money($sumPending + $sumSettled, true)) ?></td></tr>
            <tr><th>待结款</th><td><b style="color:#d97706"><?= e(money($sumPending, true)) ?></b></td></tr>
            <tr><th>已结款</th><td><b style="color:#159957"><?= e(money($sumSettled, true)) ?></b></td></tr>
            <tr><th>当前比例</th><td><?= e(YxAffiliate::rateText()) ?></td></tr>
            <tr><th>计划状态</th><td><span class="badge <?= $enabled ? 'badge-ok' : 'badge-mute' ?>"><?= $enabled ? '已开放' : '已关闭' ?></span></td></tr>
        </table>
    </div>
</div>

<div class="card" style="margin-top:14px">
    <h2>推广人佣金（<?= count($rows) ?>）</h2>
    <?php if (!$rows): ?>
        <div class="empty">暂无佣金记录。客户通过推广链接带来注册并在本站消费后，此处会出现记录。</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr>
                <th>推广人</th><th>推广客户</th><th>成交订单</th><th>成交额</th>
                <th>佣金总额</th><th>待结款</th><th>已结款</th><th>操作</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <b><?= e($r['username'] ?: '（已删除）') ?></b>
                        <?php if ($r['user_no']): ?><br><span class="muted small mono">#<?= (int)$r['user_no'] ?></span><?php endif; ?>
                    </td>
                    <td class="small"><?= (int)$r['referrals'] ?> 人</td>
                    <td class="small"><?= (int)$r['orders'] ?> 笔</td>
                    <td class="small"><?= e(money($r['gmv'], true)) ?></td>
                    <td><b><?= e(money($r['total_commission'], true)) ?></b></td>
                    <td><b style="color:#d97706"><?= e(money($r['pending'], true)) ?></b></td>
                    <td style="color:#159957"><?= e(money($r['settled'], true)) ?></td>
                    <td>
                        <?php if ((float)$r['pending'] > 0): ?>
                            <form method="post" onsubmit="return confirm('确认给「<?= e($r['username'] ?: '该推广人') ?>」结款 <?= e(money($r['pending'], true)) ?>？此操作会登记支出流水且不可撤销。')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="settle">
                                <input type="hidden" name="customer_id" value="<?= (int)$r['publisher_id'] ?>">
                                <button class="btn btn-sm btn-primary" type="submit">结款</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small">无待结款</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:14px">
    <h2>最近佣金明细（最多 50 笔）</h2>
    <?php if (!$recent): ?>
        <div class="empty">暂无佣金明细</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr>
                <th>订单号</th><th>推广人</th><th>订单内容</th><th>实付</th>
                <th>比例</th><th>佣金</th><th>支付时间</th><th>状态</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent as $c): ?>
                <tr>
                    <td class="mono small"><?= e($c['sn']) ?></td>
                    <td class="small"><?= e($c['username'] ?: '（已删除）') ?></td>
                    <td class="wrap small"><?= e($c['name'] ?: '—') ?></td>
                    <td class="small"><?= e(money($c['total'], true)) ?></td>
                    <td class="small"><?= e(YxAffiliate::rateText((float)$c['affiliate_rate'])) ?></td>
                    <td><b><?= e(money($c['affiliate_commission'], true)) ?></b></td>
                    <td class="small"><?= e($c['paid_at'] ?: '—') ?></td>
                    <td><span class="badge <?= $c['affiliate_settled_at'] ? 'badge-ok' : 'badge-warn' ?>"><?= $c['affiliate_settled_at'] ? '已结算' : '待结算' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
