<?php
/**
 * 优享财务系统 · 管理端仪表盘
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$monthFrom = date('Y-m-01');
$monthTo   = date('Y-m-t');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recheck') {
    csrf_check();
    YxLicense::flushCache();
    $r = YxLicense::query(current_domain(), true);
    YxLicense::log(current_domain(), $r['status'], $r['source'], '后台手动复核');
    log_action('license.recheck', current_domain());
    flash('授权复核完成：' . $r['message'] . '（来源：' . $r['source'] . '）', $r['authorized'] ? 'success' : 'warn');
    redirect(url('admin/index.php'));
}

$stats = [
    'customers'   => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer')),
    'new_30'      => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE created_at >= ?', [date('Y-m-d 00:00:00', strtotime('-30 day'))]),
    'services'    => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . " WHERE status = 'active'"),
    'services_all'=> (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service')),
    'unpaid_ord'  => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . " WHERE status = 'unpaid'"),
    'tickets'     => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ticket') . " WHERE status IN ('open','replied')"),
];

$summary   = YxFinance::summary($monthFrom, $monthTo);
$wallet    = YxFinance::walletTotals();
$receivable = YxFinance::receivable();
$trend     = YxFinance::trend(7);
$maxTrend  = 0.01;
foreach ($trend as $t) {
    $maxTrend = max($maxTrend, $t['income'], $t['expense']);
}

$recentOrders = YxDb::all(
    'SELECT o.*, c.username FROM ' . YxDb::table('order') . ' o
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = o.customer_id
     ORDER BY o.id DESC LIMIT 8'
);
$expiring = YxBusiness::expiringServices(7);
$license  = YxLicense::check();
$badge    = YxLicense::badge();

$pageTitle  = '仪表盘';
$activeMenu = 'dashboard';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="stat accent">
        <span>本月收入</span>
        <strong><?= e(money($summary['income'], true)) ?></strong>
        <small>支出 <?= e(money($summary['expense'], true)) ?> · 净利 <?= e(money($summary['profit'], true)) ?></small>
    </div>
    <div class="stat">
        <span>客户总数</span>
        <strong><?= $stats['customers'] ?></strong>
        <small>近 30 天新增 <?= $stats['new_30'] ?></small>
    </div>
    <div class="stat">
        <span>正常业务实例</span>
        <strong><?= $stats['services'] ?></strong>
        <small>累计 <?= $stats['services_all'] ?> 个</small>
    </div>
    <div class="stat">
        <span>待收款</span>
        <strong><?= e(money($receivable, true)) ?></strong>
        <small>待支付订单 <?= $stats['unpaid_ord'] ?> 笔</small>
    </div>
</div>

<div class="grid-4" style="margin-top:16px">
    <div class="stat">
        <span>客户钱包余额</span>
        <strong><?= e(money($wallet['balance'], true)) ?></strong>
        <small>授信额度 <?= e(money($wallet['credit'], true)) ?></small>
    </div>
    <div class="stat">
        <span>待处理工单</span>
        <strong><?= $stats['tickets'] ?></strong>
        <small><?= YxEdition::can('ticket') ? '<a href="' . e(url('admin/tickets.php')) . '">前往处理</a>' : '当前版本未启用工单' ?></small>
    </div>
    <div class="stat">
        <span>当前版本</span>
        <strong style="color:<?= e(YxEdition::info()['color']) ?>"><?= e(YxEdition::name()) ?></strong>
        <small><a href="<?= e(url('admin/edition.php')) ?>">版本与模块</a></small>
    </div>
    <div class="stat">
        <span>授权状态</span>
        <strong style="color:<?= $badge['class'] === 'ok' ? '#2f9e44' : '#e03131' ?>">
            <?= $badge['class'] === 'ok' ? '正版' : '未授权' ?>
        </strong>
        <small><?= e($badge['text']) ?></small>
    </div>
</div>

<div class="grid-2" style="margin-top:16px">
    <div class="card">
        <h2>近 7 日收支趋势</h2>
        <div class="chart">
            <?php foreach ($trend as $t): ?>
                <div class="bar" title="<?= e($t['date']) ?> 收入 <?= e(money($t['income'])) ?> / 支出 <?= e(money($t['expense'])) ?>">
                    <i style="height:<?= max(3, (int)round($t['income'] / $maxTrend * 100)) ?>%"></i>
                    <i style="height:<?= max(3, (int)round($t['expense'] / $maxTrend * 100)) ?>%;background:linear-gradient(180deg,#ffc078,#e8a33d)"></i>
                    <em><?= e($t['label']) ?></em>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="legend">
            <span><i style="background:#1c7ed6"></i>收入</span>
            <span><i style="background:#e8a33d"></i>支出</span>
        </div>
    </div>

    <div class="card">
        <h2>授权校验</h2>
        <table class="data">
            <tr><th>当前域名</th><td class="mono"><?= e($license['domain']) ?></td></tr>
            <tr><th>校验结论</th><td>
                <span class="badge badge-<?= $badge['class'] === 'ok' ? 'ok' : ($badge['class'] === 'warn' ? 'warn' : 'bad') ?>">
                    <?= e($badge['text']) ?>
                </span>
            </td></tr>
            <tr><th>结果来源</th><td><?= e($license['source']) ?>（remote=官方接口 / local=本地授权表 / cache=缓存）</td></tr>
            <tr><th>授权版本</th><td><?= e(YxEdition::name($license['authorized'] ? $license['edition'] : 'basic')) ?></td></tr>
            <tr><th>到期时间</th><td><?= e($license['expire_at'] ?: '无期限') ?></td></tr>
            <tr><th>校验时间</th><td><?= e($license['checked_at']) ?></td></tr>
        </table>
        <form method="post" class="form-actions" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="recheck">
            <button class="btn btn-primary" type="submit">重新校验授权</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/licenses.php')) ?>">授权中心</a>
        </form>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="section-head"><h2>最近订单</h2><a href="<?= e(url('admin/orders.php')) ?>">全部订单 →</a></div>
        <?php if (!$recentOrders): ?>
            <div class="empty">暂无订单</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>单号</th><th>客户</th><th>金额</th><th>状态</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach ($recentOrders as $o): ?>
                <tr>
                    <td class="mono"><?= e($o['sn']) ?></td>
                    <td><?= e($o['username'] ?: '游客') ?></td>
                    <td><?= e(money($o['total'], true)) ?></td>
                    <td><span class="badge <?= $o['status'] === 'paid' ? 'badge-ok' : ($o['status'] === 'unpaid' ? 'badge-warn' : 'badge-mute') ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span></td>
                    <td class="small muted"><?= e($o['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="section-head"><h2>7 天内到期业务</h2><a href="<?= e(url('admin/services.php')) ?>">业务实例 →</a></div>
        <?php if (!$expiring): ?>
            <div class="empty">暂无即将到期的业务</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>业务</th><th>客户</th><th>到期日</th><th>状态</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($expiring, 0, 8) as $s): ?>
                <tr>
                    <td class="wrap"><?= e($s['name']) ?></td>
                    <td><?= e($s['username'] ?: '-') ?></td>
                    <td><?= e($s['next_due_date']) ?></td>
                    <td><span class="badge <?= $s['status'] === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxBusiness::serviceStatusName($s['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
