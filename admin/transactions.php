<?php
/**
 * 优享财务系统 · 收支流水
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('finance.cashflow');

$CATEGORIES = [
    'recharge'         => '客户充值',
    'order'            => '订单收款',
    'refund'           => '退款支出',
    'manual'           => '手工调整',
    'agent_commission' => '渠道佣金',
    'subsite_commission' => '分站佣金',
    'server_cost'      => '机房 / 服务器成本',
    'bandwidth'        => '带宽成本',
    'license_fee'      => '软件授权费',
    'salary'           => '人员成本',
    'other'            => '其他',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'expense') {
        $cat = isset($CATEGORIES[$_POST['category'] ?? '']) ? $_POST['category'] : 'other';
        $res = YxFinance::bookExpense(
            (float)($_POST['amount'] ?? 0),
            $cat,
            trim((string)($_POST['remark'] ?? '')),
            (string)$admin['username']
        );
    }
    log_action('transaction.' . $action, '', ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/transactions.php'));
}

$dirF   = (string)($_GET['direction'] ?? '');
$catF   = (string)($_GET['category'] ?? '');
$from   = (string)($_GET['from'] ?? date('Y-m-01'));
$to     = (string)($_GET['to'] ?? date('Y-m-d'));
$keyword = trim((string)($_GET['kw'] ?? ''));

$where = ' WHERE t.created_at >= ? AND t.created_at <= ?';
$bind  = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($dirF === 'income' || $dirF === 'expense') {
    $where .= ' AND t.direction = ?';
    $bind[] = $dirF;
}
if ($catF !== '' && isset($CATEGORIES[$catF])) {
    $where .= ' AND t.category = ?';
    $bind[] = $catF;
}
if ($keyword !== '') {
    $where .= ' AND (t.sn LIKE ? OR t.remark LIKE ? OR c.username LIKE ?)';
    $kw = '%' . $keyword . '%';
    array_push($bind, $kw, $kw, $kw);
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('transaction') . ' t LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = t.customer_id' . $where, $bind);
$page  = paginate($total, 20);
$rows  = YxDb::all(
    'SELECT t.*, c.username FROM ' . YxDb::table('transaction') . ' t
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = t.customer_id
     ' . $where . ' ORDER BY t.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$summary = YxFinance::summary($from, $to);

$pageTitle  = '收支流水';
$activeMenu = 'transactions';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-3">
    <div class="stat accent"><span>区间收入（站方营收）</span><strong><?= e(money($summary['income'], true)) ?></strong><small><?= e($from) ?> ~ <?= e($to) ?>，不含客户钱包流水</small></div>
    <div class="stat"><span>区间支出</span><strong><?= e(money($summary['expense'], true)) ?></strong></div>
    <div class="stat"><span>区间净利</span><strong style="color:<?= $summary['profit'] >= 0 ? '#2f9e44' : '#e03131' ?>"><?= e(money($summary['profit'], true)) ?></strong></div>
</div>

<div class="card" style="margin-top:16px">
    <details>
        <summary style="cursor:pointer;color:var(--primary)">＋ 登记支出（机房、带宽、人员等）</summary>
        <form method="post" class="inline-form" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="expense">
            <label class="grow">支出分类
                <select name="category">
                    <?php foreach (['server_cost', 'bandwidth', 'license_fee', 'salary', 'other'] as $k): ?>
                        <option value="<?= e($k) ?>"><?= e($CATEGORIES[$k]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>金额 <input type="number" step="0.01" min="0.01" name="amount" required></label>
            <label class="grow">备注 <input type="text" name="remark" placeholder="如：10 月香港机房续费"></label>
            <button class="btn btn-primary" type="submit">登记支出</button>
        </form>
    </details>
</div>

<div class="card">
    <div class="section-head">
        <h2>流水明细（共 <?= $total ?> 条）</h2>
        <form method="get" class="inline-form">
            <label>开始 <input type="date" name="from" value="<?= e($from) ?>"></label>
            <label>结束 <input type="date" name="to" value="<?= e($to) ?>"></label>
            <label>方向
                <select name="direction">
                    <option value="">全部</option>
                    <option value="income" <?= $dirF === 'income' ? 'selected' : '' ?>>收入</option>
                    <option value="expense" <?= $dirF === 'expense' ? 'selected' : '' ?>>支出</option>
                </select>
            </label>
            <label>分类
                <select name="category">
                    <option value="">全部</option>
                    <?php foreach ($CATEGORIES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $catF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>关键词 <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="流水号 / 备注 / 客户"></label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>流水号</th><th>时间</th><th>客户</th><th>方向</th><th>分类</th><th>金额</th><th>余额</th><th>方式</th><th>操作人</th><th>备注</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td class="mono small"><?= e($t['sn']) ?></td>
                <td class="small muted"><?= e($t['created_at']) ?></td>
                <td><?= e($t['username'] ?: '站方') ?></td>
                <td>
                    <span class="badge <?= $t['direction'] === 'income' ? 'badge-ok' : 'badge-bad' ?>">
                        <?= $t['direction'] === 'income' ? '收入' : '支出' ?>
                    </span>
                </td>
                <td><?= e($CATEGORIES[$t['category']] ?? $t['category']) ?></td>
                <td><strong><?= e(money($t['amount'], true)) ?></strong></td>
                <td><?= e(money($t['balance_after'], true)) ?></td>
                <td><?= e($t['method'] ?: '-') ?></td>
                <td><?= e($t['operator'] ?: '-') ?></td>
                <td class="wrap muted small"><?= e($t['remark'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">该区间暂无流水</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
