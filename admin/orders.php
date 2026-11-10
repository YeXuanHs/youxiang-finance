<?php
/**
 * 优享财务系统 · 订单管理
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'pay') {
        $method = (string)($_POST['method'] ?? 'balance');
        // 余额支付会先扣减客户余额；线下/网关收款仅完成交付
        $res = $method === 'balance'
            ? YxBusiness::payOrder($id, 'balance')
            : YxBusiness::payOrderExternal($id, $method);
    } elseif ($action === 'confirm_transfer') {
        // 银联转账确认收款：不扣余额，标记为线下转账并完成交付
        $res = YxBusiness::payOrderExternal($id, 'unionpay_transfer', trim((string)($_POST['trade_no'] ?? '')));
    } elseif ($action === 'cancel') {
        // 待支付 / 待确认转账都可取消（转账声明未核对前允许撤回）
        $n = YxDb::exec(
            'UPDATE ' . YxDb::table('order') . " SET status = 'cancelled' WHERE id = ? AND status IN ('unpaid','awaiting')",
            [$id]
        );
        $res = $n ? ['ok' => true, 'msg' => '订单已取消'] : ['ok' => false, 'msg' => '仅待支付或待确认转账的订单可取消'];
    } elseif ($action === 'refund') {
        // 退款细节（状态抢占、充值订单冲回余额）统一在 YxBusiness::refundOrder 内处理
        $res = YxBusiness::refundOrder($id, (string)$admin['username']);
    } elseif ($action === 'create') {
        $cid  = (int)($_POST['customer_id'] ?? 0);
        $pid  = (int)($_POST['product_id'] ?? 0);
        $type = isset(['new' => 1, 'renew' => 1, 'recharge' => 1][$_POST['type'] ?? '']) ? $_POST['type'] : 'new';
        if ($cid <= 0) {
            $res = ['ok' => false, 'msg' => '请选择客户'];
        } else {
            $amount = (float)($_POST['amount'] ?? 0);
            $name   = trim((string)($_POST['name'] ?? ''));
            if ($pid > 0 && $amount <= 0) {
                $prod = YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ?', [$pid]);
                $amount = (float)($prod['price'] ?? 0);
                $name   = $name ?: (string)($prod['name'] ?? '');
            }
            $res = YxBusiness::createOrder([
                'customer_id' => $cid,
                'type'        => $type,
                'product_id'  => $pid,
                'service_id'  => (int)($_POST['service_id'] ?? 0),
                'name'        => $name,
                'amount'      => $amount,
                'note'        => '后台手工创建',
            ]);
        }
    }

    // $res 在个别分支可能为 null/非数组，统一兜底后再取值，避免 Undefined 警告
    $res    = is_array($res) ? $res : ['ok' => false, 'msg' => '操作失败'];
    log_action('order.' . $action, 'order#' . $id, ['ok' => !empty($res['ok'])]);
    flash((string)($res['msg'] ?? ($res['ok'] ?? false ? '操作成功' : '操作失败')), !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/orders.php'));
}

$statusF = (string)($_GET['status'] ?? '');
$typeF   = (string)($_GET['type'] ?? '');
$keyword = trim((string)($_GET['kw'] ?? ''));
$where   = ' WHERE 1=1';
$bind    = [];
if ($statusF !== '' && isset(YxBusiness::ORDER_STATUS[$statusF])) {
    $where .= ' AND o.status = ?';
    $bind[] = $statusF;
}
if ($typeF !== '') {
    $where .= ' AND o.type = ?';
    $bind[] = $typeF;
}
if ($keyword !== '') {
    $where .= ' AND (o.sn LIKE ? OR o.name LIKE ? OR c.username LIKE ?)';
    $kw = '%' . $keyword . '%';
    array_push($bind, $kw, $kw, $kw);
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . ' o LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = o.customer_id' . $where, $bind);
$page  = paginate($total, 15);
$rows  = YxDb::all(
    'SELECT o.*, c.username, c.balance FROM ' . YxDb::table('order') . ' o
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = o.customer_id
     ' . $where . ' ORDER BY o.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$customers = YxDb::all('SELECT id, username, balance FROM ' . YxDb::table('customer') . ' ORDER BY id DESC LIMIT 200');

$pageTitle  = '订单管理';
$activeMenu = 'orders';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="card">
    <details>
        <summary style="cursor:pointer;color:var(--primary)">＋ 手工创建订单</summary>
        <form method="post" class="inline-form" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="grow">客户
                <select name="customer_id" required>
                    <option value="">请选择客户</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['username']) ?> · 余额 <?= e(money($c['balance'], true)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>类型
                <select name="type">
                    <option value="new">新购</option>
                    <option value="renew">续费</option>
                    <option value="recharge">充值</option>
                </select>
            </label>
            <label class="grow">产品（可空） <input type="text" name="name" placeholder="订单标题"></label>
            <label>金额 <input type="number" step="0.01" name="amount" value="0.00"></label>
            <button class="btn btn-primary" type="submit">创建订单</button>
        </form>
    </details>
</div>

<div class="card">
    <div class="section-head">
        <h2>订单列表（共 <?= $total ?> 笔）</h2>
        <form method="get" class="inline-form">
            <label>关键词 <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="单号 / 标题 / 客户"></label>
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <?php foreach (YxBusiness::ORDER_STATUS as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>类型
                <select name="type">
                    <option value="">全部</option>
                    <?php foreach (['new' => '新购', 'renew' => '续费', 'recharge' => '充值', 'app' => '应用', 'deposit' => '保证金', 'upstream' => '自动对接'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $typeF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>单号</th><th>客户</th><th>类型</th><th>标题</th><th>金额</th><th>实付</th><th>支付方式</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o): ?>
            <tr>
                <td class="mono small"><?= e($o['sn']) ?></td>
                <td><?= e($o['username'] ?: '-') ?></td>
                <td><?= e(['new' => '新购', 'renew' => '续费', 'recharge' => '充值', 'app' => '应用', 'deposit' => '保证金', 'upstream' => '自动对接'][$o['type']] ?? $o['type']) ?></td>
                <td class="wrap"><?= e($o['name'] ?: '-') ?></td>
                <td><?= e(money($o['amount'], true)) ?></td>
                <td><strong><?= e(money($o['total'], true)) ?></strong></td>
                <td><?= e($o['pay_method'] ?: '-') ?></td>
                <td>
                    <span class="badge <?= e(YxBusiness::orderBadgeClass((string)$o['status'])) ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span>
                </td>
                <td class="small muted"><?= e($o['created_at']) ?></td>
                <td>
                    <div class="row-actions">
                        <?php if ($o['status'] === 'awaiting'): ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('确认已收到该笔银联转账？确认后立即交付，不可撤销。')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="confirm_transfer">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <input type="text" name="trade_no" placeholder="银行流水号(可空)" style="width:130px;padding:5px 8px">
                                <button class="btn btn-sm btn-ok" type="submit">确认收款</button>
                            </form>
                            <form method="post" class="row-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <button class="btn btn-sm btn-mute" type="submit">取消</button>
                            </form>
                        <?php elseif ($o['status'] === 'unpaid'): ?>
                            <form method="post" class="row-actions" onsubmit="return confirm('确认从客户余额扣款并标记支付？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="pay">
                                <input type="hidden" name="method" value="balance">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <button class="btn btn-sm btn-ok" type="submit">余额支付</button>
                            </form>
                            <form method="post" class="row-actions" onsubmit="return confirm('确认标记为已收款（不扣余额）？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="pay">
                                <input type="hidden" name="method" value="manual">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit">线下收款</button>
                            </form>
                            <form method="post" class="row-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <button class="btn btn-sm btn-mute" type="submit">取消</button>
                            </form>
                        <?php elseif ($o['status'] === 'paid'): ?>
                            <form method="post" class="row-actions" onsubmit="return confirm('确认退款？将把金额退回客户余额。')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="refund">
                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">退款</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small">-</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">暂无订单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
