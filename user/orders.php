<?php
/**
 * 优享财务系统 · 我的订单
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'pay') {
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?', [$id, $cid]);
        if (!$order) {
            $res = ['ok' => false, 'msg' => '订单不存在'];
        } elseif ($order['status'] !== 'unpaid') {
            $res = ['ok' => false, 'msg' => '订单当前状态为「' . YxBusiness::orderStatusName($order['status']) . '」'];
        } elseif ($order['type'] === 'recharge') {
            $res = ['ok' => false, 'msg' => '充值订单请在充值页面完成支付'];
        } elseif ((float)$customer['balance'] < (float)$order['total']) {
            $res = ['ok' => false, 'msg' => '余额不足，请先充值（需 ' . money($order['total'], true) . '）'];
        } else {
            $res = YxBusiness::payOrder($id, 'balance');
        }
    } elseif ($action === 'transfer') {
        // 银联转账：用户声明已付款，订单转入待确认，由管理员核对到账
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?', [$id, $cid]);
        if (!$order) {
            $res = ['ok' => false, 'msg' => '订单不存在'];
        } elseif (!yx_offline_available()) {
            $res = ['ok' => false, 'msg' => '银联转账通道未开启，请选择其他付款方式'];
        } elseif ($order['type'] === 'recharge') {
            $res = ['ok' => false, 'msg' => '充值订单请到充值页面提交'];
        } else {
            $res = YxBusiness::markAwaiting($id, (string)$customer['username']);
        }
    } elseif ($action === 'cancel') {
        $n = YxDb::exec(
            'UPDATE ' . YxDb::table('order') . " SET status = 'cancelled' WHERE id = ? AND customer_id = ? AND status IN ('unpaid','awaiting')",
            [$id, $cid]
        );
        $res = $n ? ['ok' => true, 'msg' => '订单已取消'] : ['ok' => false, 'msg' => '仅待支付 / 待确认转账的订单可取消'];
    }

    $res = is_array($res) ? $res : ['ok' => false, 'msg' => '操作失败'];
    log_action('order.front_' . $action, 'order#' . $id, ['ok' => !empty($res['ok'])]);
    flash((string)($res['msg'] ?? '操作失败'), !empty($res['ok']) ? 'success' : 'error');
    redirect(url('user/orders.php'));
}

$payId = (int)($_GET['pay'] ?? 0);
$payOrder = $payId > 0
    ? YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?', [$payId, $cid])
    : null;

$statusF = (string)($_GET['status'] ?? '');
$where   = ' WHERE customer_id = ?';
$bind    = [$cid];
if ($statusF !== '' && isset(YxBusiness::ORDER_STATUS[$statusF])) {
    $where .= ' AND status = ?';
    $bind[] = $statusF;
}
$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . $where, $bind);
$page  = paginate($total, 15);
$rows  = YxDb::all('SELECT * FROM ' . YxDb::table('order') . $where . ' ORDER BY id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'], $bind);

// 已支付的正版订单：一次查出凭证号，列表里直接给「购买证明」入口
$certMap = YxCertificate::mapByOrders(array_column($rows, 'id'));

// 刷新余额（支付后）
$balance = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);

$userTitle  = '我的订单';
$userActive = 'orders';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if ($payOrder && $payOrder['status'] === 'awaiting'): ?>
<div class="card">
    <div class="section-head">
        <h2>等待确认转账</h2>
        <span class="badge badge-info">待确认转账</span>
    </div>
    <p class="muted">
        订单 <code><?= e($payOrder['sn']) ?></code> 的转账声明已提交，管理员核对到账后将即时开通。
        如长期未确认，请联系客服并提供订单号。
    </p>
    <div class="form-actions mt-3">
        <a class="btn btn-ghost" href="<?= e(url('user/orders.php')) ?>">返回订单列表</a>
        <form method="post" onsubmit="return confirm('确认取消该订单？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" value="<?= (int)$payOrder['id'] ?>">
            <button class="btn btn-mute" type="submit">取消订单</button>
        </form>
    </div>
</div>
<?php elseif ($payOrder && $payOrder['status'] === 'unpaid'): ?>
<?php
$orderTotal = (float)$payOrder['total'];
$canScan    = YxPayment::configured() && $payOrder['type'] !== 'recharge';
$canOffline = yx_offline_available() && $payOrder['type'] !== 'recharge';
$hasBalance = $balance >= $orderTotal;
?>
<div class="card">
    <h2>确认支付</h2>
    <table class="data kv">
        <tr><th>订单号</th><td class="mono"><?= e($payOrder['sn']) ?></td></tr>
        <tr><th>订单标题</th><td><?= e($payOrder['name'] ?: '-') ?></td></tr>
        <tr><th>应付金额</th><td><strong class="pay-total"><?= e(money($orderTotal, true)) ?></strong></td></tr>
        <tr><th>账户余额</th><td><?= e(money($balance, true)) ?></td></tr>
    </table>

    <?php if ($payOrder['type'] === 'recharge'): ?>
        <p class="muted mt-3">这是充值订单，请到 <a href="<?= e(url('user/recharge.php')) ?>">充值页面</a> 完成支付。</p>
    <?php else: ?>
        <?php $methods = ($hasBalance ? 1 : 0) + ($canScan ? 1 : 0) + ($canOffline ? 1 : 0); ?>
        <?php if ($methods > 1): ?>
            <div class="pay-tabs" data-pay-tabs>
                <button type="button" class="pay-tab<?= $hasBalance ? ' active' : '' ?>" data-pay-tab="balance">余额支付</button>
                <?php if ($canScan): ?>
                    <button type="button" class="pay-tab<?= !$hasBalance ? ' active' : '' ?>" data-pay-tab="scan">扫码支付</button>
                <?php endif; ?>
                <?php if ($canOffline): ?>
                    <button type="button" class="pay-tab<?= (!$hasBalance && !$canScan) ? ' active' : '' ?>" data-pay-tab="transfer">银联卡转账</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($hasBalance): ?>
            <div class="pay-panel<?= $methods > 1 ? ' active' : '' ?>" data-pay-panel="balance">
                <form method="post" class="form-actions mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="id" value="<?= (int)$payOrder['id'] ?>">
                    <button class="btn btn-primary" type="submit">使用余额支付 <?= e(money($orderTotal, true)) ?></button>
                    <a class="btn btn-ghost" href="<?= e(url('user/orders.php')) ?>">稍后再付</a>
                </form>
            </div>
        <?php else: ?>
            <p class="alert alert-warn mt-3">余额不足，还差 <?= e(money($orderTotal - $balance, true)) ?>。
                <?php if ($canOffline || $canScan): ?>可改用下方其他方式付款。<?php else: ?>请先充值后再支付。<?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($canScan): ?>
            <div class="pay-panel<?= !$hasBalance ? ' active' : '' ?>" data-pay-panel="scan">
                <div class="pay-scan-head">
                    <span class="pay-scan-title">微信 / 支付宝扫码支付</span>
                </div>
                <?php
                // 微信走收银台跳转（网关 mapi 不返回微信二维码），支付宝在页面内出码
                $scanType = (string)($_GET['type'] ?? 'alipay');
                if (!in_array($scanType, ['wxpay', 'alipay'], true)) {
                    $scanType = 'alipay';
                }
                ?>
                <div class="pay-methods mb-3">
                    <?php foreach (['alipay' => '支付宝', 'wxpay' => '微信支付'] as $t => $lbl): ?>
                        <?php if ($t === $scanType): ?>
                            <span class="btn btn-primary btn-sm"><?= e($lbl) ?></span>
                        <?php else: ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('user/orders.php?pay=' . (int)$payOrder['id'] . '&type=' . $t)) ?>">改用<?= e($lbl) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?= YxPayment::renderCheckout(
                    $payOrder,
                    $scanType,
                    (string)($payOrder['name'] ?: '订单支付'),
                    url('user/orders.php?pay=' . (int)$payOrder['id'])
                ) ?>
            </div>
        <?php endif; ?>

        <?php if ($canOffline): ?>
            <div class="pay-panel<?= (!$hasBalance && !$canScan) ? ' active' : '' ?>" data-pay-panel="transfer">
                <p class="muted small mt-3">请按以下信息通过手机银行 / 网银 / 柜面转账，<strong>转账备注务必填写订单号</strong>：</p>
                <?= yx_offline_render($payOrder) ?>
                <form method="post" class="form-actions mt-3" onsubmit="return confirm('确认已按上述信息完成转账？提交后需等待管理员核对到账。')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="transfer">
                    <input type="hidden" name="id" value="<?= (int)$payOrder['id'] ?>">
                    <button class="btn btn-primary" type="submit">我已完成转账</button>
                    <a class="btn btn-ghost" href="<?= e(url('user/orders.php')) ?>">稍后再付</a>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$hasBalance && !$canScan && !$canOffline): ?>
            <div class="form-actions mt-3">
                <a class="btn btn-primary" href="<?= e(url('user/recharge.php')) ?>">去充值</a>
                <a class="btn btn-ghost" href="<?= e(url('user/orders.php')) ?>">返回</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="section-head">
        <h2>订单列表（共 <?= $total ?> 笔）</h2>
        <form method="get" class="inline-form">
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <?php foreach (YxBusiness::ORDER_STATUS as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">筛选</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>单号</th><th>类型</th><th>标题</th><th>金额</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o): ?>
            <tr>
                <td class="mono small"><?= e($o['sn']) ?></td>
                <td><?= e(['new' => '新购', 'renew' => '续费', 'recharge' => '充值', 'app' => '应用', 'deposit' => '保证金', 'upstream' => '自动对接'][$o['type']] ?? $o['type']) ?></td>
                <td class="wrap"><?= e($o['name'] ?: '-') ?></td>
                <td><?= e(money($o['total'], true)) ?></td>
                <td><span class="badge <?= e(YxBusiness::orderBadgeClass((string)$o['status'])) ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span></td>
                <td class="small muted"><?= e($o['created_at']) ?></td>
                <td>
                    <div class="row-actions">
                        <?php if ($o['status'] === 'unpaid'): ?>
                            <?php if ($o['type'] === 'recharge'): ?>
                                <a class="btn btn-sm btn-primary" href="<?= e(url('user/recharge.php')) ?>">去充值</a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-primary" href="<?= e(url('user/orders.php?pay=' . (int)$o['id'])) ?>">去支付</a>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('确认取消该订单？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <button class="btn btn-sm btn-mute" type="submit">取消</button>
                            </form>
                        <?php elseif ($o['status'] === 'awaiting'): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('user/orders.php?pay=' . (int)$o['id'])) ?>">查看转账信息</a>
                        <?php else: ?>
                            <?php if (isset($certMap[(int)$o['id']])): ?>
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('user/certificate.php?order=' . (int)$o['id'])) ?>">购买证明</a>
                            <?php else: ?>
                                <span class="muted small">-</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无订单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
