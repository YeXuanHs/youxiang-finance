<?php
/**
 * 优享财务系统 · 账单列表
 *
 * 与「账单总览」(user/invoices.php) 的分工：
 *   账单总览 —— 汇总数字 + 账单清单（金额视角）
 *   账单列表 —— 逐笔账单的付款进度、逾期天数与支付记录（收款视角）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
YxEdition::requireFeature('finance.invoice', url('user/index.php'));
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$STATUS = ['unpaid' => '未支付', 'partial' => '部分支付', 'paid' => '已结清', 'void' => '已作废'];
$TYPES  = ['service' => '业务账单', 'renew' => '续费账单', 'manual' => '手工账单', 'order' => '订单账单'];

$statusF = (string)($_GET['status'] ?? '');
$typeF   = (string)($_GET['type'] ?? '');
$kw      = trim((string)($_GET['q'] ?? ''));

$where = ' WHERE customer_id = ?';
$bind  = [$cid];
if ($statusF !== '' && isset($STATUS[$statusF])) {
    $where .= ' AND status = ?';
    $bind[] = $statusF;
}
if ($typeF !== '' && isset($TYPES[$typeF])) {
    $where .= ' AND type = ?';
    $bind[] = $typeF;
}
if ($kw !== '') {
    $where .= ' AND (sn LIKE ? OR title LIKE ?)';
    $bind[] = '%' . $kw . '%';
    $bind[] = '%' . $kw . '%';
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('invoice') . $where, $bind);
$page  = paginate($total, 20);
$rows  = YxDb::all(
    'SELECT * FROM ' . YxDb::table('invoice') . $where . ' ORDER BY id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

// 支付记录按账单所属订单聚合，便于在列表里直接看到「用什么通道付的」
$orderIds = [];
foreach ($rows as $r) {
    if ((int)$r['order_id'] > 0) {
        $orderIds[(int)$r['order_id']] = true;
    }
}
$payMap = [];
if ($orderIds) {
    $ids  = array_keys($orderIds);
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $pays = YxDb::all(
        'SELECT order_id, gateway, trade_no, amount, status, paid_at FROM ' . YxDb::table('payment')
        . ' WHERE order_id IN (' . $in . ') ORDER BY id DESC',
        $ids
    );
    foreach ($pays as $p) {
        $oid = (int)$p['order_id'];
        if (!isset($payMap[$oid])) {
            $payMap[$oid] = $p;
        }
    }
}

$sum = YxDb::one(
    'SELECT COALESCE(SUM(amount),0) AS a, COALESCE(SUM(paid_amount),0) AS p, COUNT(*) AS c'
    . ' FROM ' . YxDb::table('invoice') . ' WHERE customer_id = ?',
    [$cid]
);

$userTitle  = '账单列表';
$userActive = 'invoice_list';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="grid-3">
    <div class="stat"><span>账单笔数</span><strong><?= (int)($sum['c'] ?? 0) ?></strong></div>
    <div class="stat"><span>账单总额</span><strong><?= e(money($sum['a'] ?? 0, true)) ?></strong></div>
    <div class="stat accent"><span>未结清金额</span><strong><?= e(money(max(0, (float)($sum['a'] ?? 0) - (float)($sum['p'] ?? 0)), true)) ?></strong></div>
</div>

<div class="card mt-4">
    <div class="section-head">
        <h2>全部账单<em><?= $total ?> 条</em></h2>
        <form method="get" class="inline-form">
            <label>关键词
                <input type="text" name="q" value="<?= e($kw) ?>" placeholder="账单号 / 标题">
            </label>
            <label>类型
                <select name="type">
                    <option value="">全部</option>
                    <?php foreach ($TYPES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $typeF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <?php foreach ($STATUS as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">筛选</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead>
            <tr>
                <th>账单号</th><th>标题</th><th>类型</th><th>应付</th><th>已付</th><th>进度</th>
                <th>到期日</th><th>状态</th><th>支付通道</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
            $amount = (float)$r['amount'];
            $paid   = (float)$r['paid_amount'];
            $pct    = $amount > 0 ? min(100, (int)round($paid / $amount * 100)) : ($r['status'] === 'paid' ? 100 : 0);
            $overdue = $r['status'] !== 'paid' && $r['status'] !== 'void' && $r['due_date'] && $r['due_date'] < date('Y-m-d');
            $pay = $payMap[(int)$r['order_id']] ?? null;
            $cls = ['paid' => 'badge-ok', 'unpaid' => 'badge-warn', 'partial' => 'badge-info', 'void' => 'badge-mute'][$r['status']] ?? 'badge-mute';
            ?>
            <tr>
                <td class="mono small"><?= e($r['sn']) ?></td>
                <td class="wrap"><?= e($r['title'] ?: '-') ?></td>
                <td class="small"><?= e($TYPES[$r['type']] ?? $r['type']) ?></td>
                <td><?= e(money($amount, true)) ?></td>
                <td><?= e(money($paid, true)) ?></td>
                <td class="small" style="min-width:110px">
                    <span class="yx-bar"><i style="width:<?= $pct ?>%"></i></span>
                    <span class="muted"><?= $pct ?>%</span>
                </td>
                <td>
                    <?php if ($overdue): ?>
                        <?php $days = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$r['due_date'])) / 86400); ?>
                        <span class="badge badge-bad">逾期 <?= $days ?> 天</span>
                    <?php else: ?>
                        <span class="small"><?= e($r['due_date'] ?: '-') ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $cls ?>"><?= e($STATUS[$r['status']] ?? $r['status']) ?></span></td>
                <td class="small muted">
                    <?php if ($pay): ?>
                        <?= e($pay['gateway'] ?: '-') ?><?= $pay['trade_no'] ? '<br><span class="mono">' . e($pay['trade_no']) . '</span>' : '' ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (in_array($r['status'], ['unpaid', 'partial'], true)): ?>
                        <a class="btn btn-sm btn-primary" href="<?= e(url('user/recharge.php')) ?>">充值支付</a>
                    <?php else: ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('user/invoice_apply.php?invoice_id=' . (int)$r['id'])) ?>">申请发票</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">没有符合条件的账单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<div class="card mt-4">
    <h2>说明</h2>
    <ul class="tight muted">
        <li>「已付」为账单累计收到的金额，部分支付时账单状态为「部分支付」，剩余金额可继续支付。</li>
        <li>账单目前由财务在后台确认收款，如需结清未支付账单，请先充值余额或提交工单联系财务。</li>
        <li>账单逾期不影响业务正常使用，但可能影响新购与续费，请及时结清。</li>
        <li>已结清的账单可申请发票，点击行末「申请发票」提交开票信息。</li>
    </ul>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
