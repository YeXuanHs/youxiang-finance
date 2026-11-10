<?php
/**
 * 优享财务系统 · 经营分析（企业版）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('report');

$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to   = (string)($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$summary = YxFinance::summary($from, $to);
$byCat   = YxFinance::incomeByCategory($from, $to);
$trend   = YxFinance::trend(30);
$maxTrend = 0.01;
foreach ($trend as $t) {
    $maxTrend = max($maxTrend, $t['income'], $t['expense']);
}

$orderStats = YxDb::one(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS amount
     FROM ' . YxDb::table('order') . "
     WHERE status = 'paid' AND paid_at >= ? AND paid_at <= ?",
    [$from . ' 00:00:00', $to . ' 23:59:59']
);
$orderCnt    = (int)($orderStats['cnt'] ?? 0);
$orderAmount = (float)($orderStats['amount'] ?? 0);

$newCustomers = (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE created_at >= ? AND created_at <= ?',
    [$from . ' 00:00:00', $to . ' 23:59:59']
);

$svcDist = YxDb::all('SELECT status, COUNT(*) AS cnt FROM ' . YxDb::table('service') . ' GROUP BY status');
$topProducts = YxDb::all(
    'SELECT p.name, COUNT(s.id) AS cnt, COALESCE(SUM(s.price),0) AS amount
     FROM ' . YxDb::table('service') . ' s
     JOIN ' . YxDb::table('product') . ' p ON p.id = s.product_id
     GROUP BY p.id, p.name ORDER BY amount DESC LIMIT 10'
);
$topCustomers = YxDb::all(
    'SELECT c.username, COUNT(o.id) AS cnt, COALESCE(SUM(o.total),0) AS amount
     FROM ' . YxDb::table('order') . ' o
     JOIN ' . YxDb::table('customer') . ' c ON c.id = o.customer_id
     WHERE o.status = \'paid\' AND o.paid_at >= ? AND o.paid_at <= ?
     GROUP BY c.id, c.username ORDER BY amount DESC LIMIT 10',
    [$from . ' 00:00:00', $to . ' 23:59:59']
);

$receivable = YxFinance::receivable();
$wallet     = YxFinance::walletTotals();

$pageTitle  = '经营分析';
$activeMenu = 'report';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="card">
    <form method="get" class="inline-form">
        <label>开始日期 <input type="date" name="from" value="<?= e($from) ?>"></label>
        <label>结束日期 <input type="date" name="to" value="<?= e($to) ?>"></label>
        <button class="btn btn-primary" type="submit">生成报表</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/report.php')) ?>">本月</a>
    </form>
</div>

<div class="grid-4">
    <div class="stat accent"><span>收入</span><strong><?= e(money($summary['income'], true)) ?></strong></div>
    <div class="stat"><span>支出</span><strong><?= e(money($summary['expense'], true)) ?></strong></div>
    <div class="stat"><span>净利</span><strong style="color:<?= $summary['profit'] >= 0 ? '#2f9e44' : '#e03131' ?>"><?= e(money($summary['profit'], true)) ?></strong></div>
    <div class="stat"><span>净利率</span><strong><?= $summary['income'] > 0 ? e(round($summary['profit'] / $summary['income'] * 100, 1)) . '%' : '-' ?></strong></div>
</div>

<div class="grid-4" style="margin-top:16px">
    <div class="stat"><span>已支付订单</span><strong><?= $orderCnt ?></strong><small>合计 <?= e(money($orderAmount, true)) ?></small></div>
    <div class="stat"><span>客单价</span><strong><?= $orderCnt > 0 ? e(money($orderAmount / $orderCnt, true)) : '-' ?></strong></div>
    <div class="stat"><span>新增客户</span><strong><?= $newCustomers ?></strong></div>
    <div class="stat"><span>应收未收</span><strong><?= e(money($receivable, true)) ?></strong><small>钱包余额 <?= e(money($wallet['balance'], true)) ?></small></div>
</div>

<div class="card" style="margin-top:16px">
    <h2>近 30 日收支趋势</h2>
    <div class="chart">
        <?php foreach ($trend as $t): ?>
            <div class="bar" title="<?= e($t['date']) ?> 收入 <?= e(money($t['income'])) ?> / 支出 <?= e(money($t['expense'])) ?>">
                <i style="height:<?= max(2, (int)round($t['income'] / $maxTrend * 100)) ?>%"></i>
                <i style="height:<?= max(2, (int)round($t['expense'] / $maxTrend * 100)) ?>%;background:linear-gradient(180deg,#ffc078,#e8a33d)"></i>
                <em><?= e(substr($t['label'], -2)) ?></em>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="legend">
        <span><i style="background:#1c7ed6"></i>收入</span>
        <span><i style="background:#e8a33d"></i>支出</span>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h2>收入构成</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>分类</th><th>笔数</th><th>金额</th><th>占比</th></tr></thead>
            <tbody>
            <?php foreach ($byCat as $row): ?>
                <tr>
                    <td><?= e($row['category']) ?></td>
                    <td><?= (int)$row['cnt'] ?></td>
                    <td><?= e(money($row['total'], true)) ?></td>
                    <td><?= $summary['income'] > 0 ? e(round((float)$row['total'] / $summary['income'] * 100, 1)) . '%' : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byCat): ?><tr><td colspan="4" class="empty">该区间无收入流水</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <h2>业务实例状态分布</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>状态</th><th>数量</th></tr></thead>
            <tbody>
            <?php foreach ($svcDist as $row): ?>
                <tr><td><?= e(YxBusiness::serviceStatusName($row['status'])) ?></td><td><?= (int)$row['cnt'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$svcDist): ?><tr><td colspan="2" class="empty">暂无业务</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h2>产品销量 Top 10</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>产品</th><th>售出</th><th>累计金额</th></tr></thead>
            <tbody>
            <?php foreach ($topProducts as $row): ?>
                <tr><td class="wrap"><?= e($row['name']) ?></td><td><?= (int)$row['cnt'] ?></td><td><?= e(money($row['amount'], true)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$topProducts): ?><tr><td colspan="3" class="empty">暂无数据</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <h2>客户消费 Top 10（区间内）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>客户</th><th>订单数</th><th>消费金额</th></tr></thead>
            <tbody>
            <?php foreach ($topCustomers as $row): ?>
                <tr><td><?= e($row['username']) ?></td><td><?= (int)$row['cnt'] ?></td><td><?= e(money($row['amount'], true)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$topCustomers): ?><tr><td colspan="3" class="empty">暂无数据</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
