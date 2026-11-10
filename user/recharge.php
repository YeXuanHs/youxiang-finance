<?php
/**
 * 优享财务系统 · 余额充值
 *
 * 支付通道通过后台设置接入（易支付/聚合码通用协议）；未配置通道时退回线下汇款流程。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$gatewayUrl = trim((string)setting('pay_gateway_url', ''));
$gatewayOn  = YxPayment::configured();

$pending = null;
$submitForm = '';
$amount = (float)($_POST['amount'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amount = round_money($_POST['amount'] ?? 0);
    if ($amount < 1) {
        flash('单次充值金额不能低于 1.00 元', 'error');
        redirect(url('user/recharge.php'));
    }
    if ($amount > 50000) {
        flash('单次充值金额过大，请联系客服', 'error');
        redirect(url('user/recharge.php'));
    }

    $created = YxBusiness::createOrder([
        'customer_id' => $cid,
        'type'        => 'recharge',
        'name'        => '余额充值 ' . money($amount, true),
        'amount'      => $amount,
        'note'        => '用户自助充值',
    ]);
    if (!$created['ok']) {
        flash($created['msg'], 'error');
        redirect(url('user/recharge.php'));
    }
    $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$created['order_id']]);
    log_action('recharge.create', $order['sn'], ['amount' => $amount]);

    if ($gatewayOn) {
        $payType = (string)($_POST['pay_type'] ?? 'alipay');
        if (!in_array($payType, ['wxpay', 'alipay'], true)) {
            $payType = 'alipay';
        }
        $submitForm = YxPayment::renderCheckout($order, $payType, '余额充值', url('user/recharge.php?sn=' . urlencode((string)$order['sn'])));
    } else {
        $pending = $order;
        flash('充值订单已生成，请按下方指引完成付款，到账后将自动入账');
    }
}

$sn = (string)($_GET['sn'] ?? '');
if ($sn !== '') {
    $o = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE sn = ? AND customer_id = ?', [$sn, $cid]);
    if ($o && $o['status'] === 'paid') {
        flash('充值已到账，当前余额 ' . money((float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]), true));
        redirect(url('user/recharge.php'));
    } elseif ($o) {
        // 未支付订单：留在当前页展示待支付面板（跳转会丢失 $pending）
        $pending = $o;
    } else {
        flash('充值订单不存在', 'error');
        redirect(url('user/recharge.php'));
    }
}

$logs = YxDb::all(
    'SELECT * FROM ' . YxDb::table('transaction') . " WHERE customer_id = ? AND category IN ('recharge','refund') ORDER BY id DESC LIMIT 15",
    [$cid]
);

$userTitle  = '余额充值';
$userActive = 'recharge';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if ($submitForm !== ''): ?>
    <?php
    // 同一订单可在微信 / 支付宝之间切换（微信走收银台跳转，支付宝页面内出码）
    $curType = (string)($_POST['pay_type'] ?? 'alipay');
    if (!in_array($curType, ['wxpay', 'alipay'], true)) {
        $curType = 'alipay';
    }
    ?>
    <div class="card" id="yxPayCard">
        <h2>在线支付（<?= e(YxPayment::typeName($curType)) ?>）</h2>
        <div class="pay-methods mb-3">
            <?php foreach (['wxpay' => '微信支付', 'alipay' => '支付宝'] as $t => $lbl): ?>
                <?php if ($t === $curType): ?>
                    <span class="btn btn-primary btn-sm"><?= e($lbl) ?></span>
                <?php else: ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="amount" value="<?= e(number_format((float)$amount, 2, '.', '')) ?>">
                        <input type="hidden" name="pay_type" value="<?= e($t) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">改用<?= e($lbl) ?></button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?= $submitForm ?>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h2>充值金额</h2>
        <?php if (!$gatewayOn): ?>
            <div class="alert alert-warn">
                在线支付通道尚未配置，提交后将进入线下汇款流程，由客服人工入账。
            </div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label>充值金额（元）
                <input type="number" step="0.01" min="1" name="amount" value="<?= e($amount > 0 ? number_format($amount, 2, '.', '') : '100.00') ?>" required>
            </label>
            <?php if ($gatewayOn): ?>
                <label>支付方式
                    <span class="pay-type-radios">
                        <label class="inline"><input type="radio" name="pay_type" value="wxpay" checked> 微信支付</label>
                        <label class="inline"><input type="radio" name="pay_type" value="alipay"> 支付宝</label>
                    </span>
                </label>
            <?php endif; ?>
            <div class="form-actions mb-4">
                <?php foreach ([50, 100, 200, 500, 1000] as $quick): ?>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="document.querySelector('input[name=amount]').value='<?= $quick ?>.00'">
                        <?= $quick ?> 元
                    </button>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary btn-block" type="submit">提交充值</button>
        </form>
    </div>

    <div class="card">
        <h2>当前余额</h2>
        <div class="stat accent">
            <span>可用余额</span>
            <strong><?= e(money($customer['balance'], true)) ?></strong>
            <small>授信额度 <?= e(money($customer['credit'], true)) ?></small>
        </div>

        <?php if ($pending): ?>
            <h2 class="mt-5">待支付充值单</h2>
            <table class="data">
                <tr><th>订单号</th><td class="mono"><?= e($pending['sn']) ?></td></tr>
                <tr><th>金额</th><td><strong><?= e(money($pending['total'], true)) ?></strong></td></tr>
                <tr><th>状态</th><td><span class="badge badge-warn">待支付</span></td></tr>
            </table>
            <p class="muted small mt-2">
                线下汇款请务必备注订单号 <code><?= e($pending['sn']) ?></code>，客服核对后将人工入账。
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>充值 / 退款记录</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>流水号</th><th>时间</th><th>类型</th><th>金额</th><th>余额</th><th>备注</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $t): ?>
            <tr>
                <td class="mono small"><?= e($t['sn']) ?></td>
                <td class="small muted"><?= e($t['created_at']) ?></td>
                <td><?= $t['category'] === 'recharge' ? '充值' : '退款' ?></td>
                <td><strong><?= e(money($t['amount'], true)) ?></strong></td>
                <td><?= e(money($t['balance_after'], true)) ?></td>
                <td class="wrap muted small"><?= e($t['remark'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6" class="empty">暂无记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
