<?php
/**
 * 优享财务系统 · 我的账单
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
YxEdition::requireFeature('finance.invoice', url('user/index.php'));
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$statusF = (string)($_GET['status'] ?? '');
$STATUS  = ['unpaid' => '未支付', 'partial' => '部分支付', 'paid' => '已结清', 'void' => '已作废'];
$where   = ' WHERE customer_id = ?';
$bind    = [$cid];
if ($statusF !== '' && isset($STATUS[$statusF])) {
    $where .= ' AND status = ?';
    $bind[] = $statusF;
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('invoice') . $where, $bind);
$page  = paginate($total, 15);
$rows  = YxDb::all('SELECT * FROM ' . YxDb::table('invoice') . $where . ' ORDER BY id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'], $bind);

$payable = (float)YxDb::value('SELECT COALESCE(SUM(amount - paid_amount),0) FROM ' . YxDb::table('invoice') . " WHERE customer_id = ? AND status IN ('unpaid','partial')", [$cid]);
$paidAll = (float)YxDb::value('SELECT COALESCE(SUM(paid_amount),0) FROM ' . YxDb::table('invoice') . ' WHERE customer_id = ?', [$cid]);

$userTitle  = '我的账单';
$userActive = 'invoices';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="grid-3">
    <div class="stat accent"><span>待支付金额</span><strong><?= e(money($payable, true)) ?></strong></div>
    <div class="stat"><span>累计已支付</span><strong><?= e(money($paidAll, true)) ?></strong></div>
    <div class="stat"><span>账单总数</span><strong><?= $total ?></strong></div>
</div>

<div class="card mt-4">
    <div class="section-head">
        <h2>账单明细</h2>
        <form method="get" class="inline-form">
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
        <thead><tr><th>账单号</th><th>标题</th><th>金额</th><th>已收</th><th>到期日</th><th>状态</th><th>开具时间</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i): ?>
            <tr>
                <td class="mono small"><?= e($i['sn']) ?></td>
                <td class="wrap"><?= e($i['title']) ?></td>
                <td><?= e(money($i['amount'], true)) ?></td>
                <td><?= e(money($i['paid_amount'], true)) ?></td>
                <td>
                    <?php if ($i['status'] !== 'paid' && $i['due_date'] && $i['due_date'] < date('Y-m-d')): ?>
                        <span class="badge badge-bad"><?= e($i['due_date']) ?> 已逾期</span>
                    <?php else: ?>
                        <?= e($i['due_date'] ?: '-') ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $cls = ['paid' => 'badge-ok', 'unpaid' => 'badge-warn', 'partial' => 'badge-info', 'void' => 'badge-mute'][$i['status']] ?? 'badge-mute'; ?>
                    <span class="badge <?= $cls ?>"><?= e($STATUS[$i['status']] ?? $i['status']) ?></span>
                </td>
                <td class="small muted"><?= e($i['issued_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无账单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<div class="card">
    <h2>说明</h2>
    <ul class="tight muted">
        <li>业务开通、续费成功后会自动生成对应账单并标记已结清。</li>
        <li>如需开具企业发票，请通过工单提交开票信息。</li>
    </ul>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
