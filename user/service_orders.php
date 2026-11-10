<?php
/**
 * 优享财务系统 · 我的服务（服务中心购买记录）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$rows = YxDb::all(
    'SELECT * FROM ' . YxDb::table('service_order') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 200',
    [$cid]
);

$pageTitle = '我的服务';
$userActive = 'service_orders';
$userTitle  = '我的服务';
$customer = $customer;
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2>服务购买记录（共 <?= count($rows) ?> 条）</h2>
        <a class="btn btn-primary" href="<?= e(url('service/index.php')) ?>">浏览服务中心</a>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>服务单号</th><th>服务项目</th><th>规格</th><th>金额</th><th>计费</th><th>状态</th><th>到期日</th><th>下单时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
            $status  = (string)$r['status'];
            $orderId = (int)$r['order_id'];
            $payLink = null;
            if ($orderId > 0) {
                $od = YxDb::one('SELECT status FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]);
                if ($od && in_array((string)$od['status'], ['unpaid', 'awaiting'], true)) {
                    $payLink = url('user/orders.php?pay=' . $orderId);
                }
            }
            ?>
            <tr>
                <td class="mono"><?= e($r['sn']) ?></td>
                <td><?= e($r['name']) ?></td>
                <td><?= e($r['spec_text'] ?: '—') ?></td>
                <td><?= e(money($r['amount'], true)) ?></td>
                <td><?= e(YxServiceCenter::billName((string)$r['bill_type'])) ?></td>
                <td><span class="badge <?= e(YxServiceCenter::statusBadge($status)) ?>"><?= e(YxServiceCenter::statusName($status)) ?></span></td>
                <td><?= e($r['expire_at'] ?: '—') ?></td>
                <td class="small muted"><?= e($r['created_at']) ?></td>
                <td>
                    <?php if ($payLink): ?>
                        <a class="btn btn-sm btn-primary" href="<?= e($payLink) ?>">去支付</a>
                    <?php elseif ((int)$r['service_id'] > 0): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('user/services.php')) ?>">查看业务</a>
                    <?php else: ?>
                        <span class="muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="empty">还没有购买过服务，<a href="<?= e(url('service/index.php')) ?>">去看看</a></td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
