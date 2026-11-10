<?php
/**
 * 优享财务系统 · 代理中心（概览 / 自助下单 / 我的订单 / 我的业务）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$agent = YxAuth::requireAgent();
$aid   = (int)$agent['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $payId  = 0;

    if ($action === 'order') {
        $pid     = (int)($_POST['product_id'] ?? 0);
        $cycle   = (string)($_POST['cycle'] ?? 'month');
        $product = YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ? AND status = 1', [$pid]);
        if (!$product) {
            $res = ['ok' => false, 'msg' => '产品不存在或已下架'];
        } elseif (YxEdition::rank() < YxEdition::rank($product['edition_min'])) {
            $res = ['ok' => false, 'msg' => '该产品需要「' . YxEdition::name($product['edition_min']) . '」及以上版本'];
        } else {
            $r = YxAgent::placeOrder($agent, $product, $cycle);
            if (!empty($r['ok'])) {
                $payId = (int)$r['order_id'];
            }
            $res = $r;
        }
    } elseif ($action === 'subsite_create') {
        if (!YxEdition::can('subsite')) {
            $res = ['ok' => false, 'msg' => '当前版本不支持分站功能'];
        } else {
            $res = YxSubsite::createChild([
                'parent_id'   => 0,
                'plan_id'     => (int)($_POST['plan_id'] ?? 0),
                'name'        => (string)($_POST['name'] ?? ''),
                'username'    => (string)($_POST['username'] ?? ''),
                'password'    => (string)($_POST['password'] ?? ''),
                'domain'      => (string)($_POST['domain'] ?? ''),
                'customer_id' => YxAgent::ensureCustomer($agent),
                'agent_id'    => $aid,
                'remark'      => '代理开通：' . $agent['name'],
            ]);
            if (!empty($res['ok']) && !empty($res['order_id'])) {
                $payId = (int)$res['order_id'];
            }
        }
    } elseif ($action === 'subsite_renew') {
        $sid  = (int)($_POST['id'] ?? 0);
        $row  = $sid > 0 ? YxSubsite::byId($sid) : null;
        if (!YxEdition::can('subsite')) {
            $res = ['ok' => false, 'msg' => '当前版本不支持分站功能'];
        } elseif (!$row || (int)$row['agent_id'] !== $aid) {
            $res = ['ok' => false, 'msg' => '分站不存在或不属于本代理'];
        } else {
            $res = YxSubsite::renew($sid, 0, YxAgent::ensureCustomer($agent));
            if (!empty($res['ok']) && !empty($res['order_id'])) {
                $payId = (int)$res['order_id'];
            }
        }
    } elseif ($action === 'pay') {
        $oid   = (int)($_POST['id'] ?? 0);
        // 归属校验：订单须属于本代理（agent_id），兼容历史订单（按代理客户账号匹配）
        $order = $oid > 0 ? YxDb::one(
            'SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND (agent_id = ? OR customer_id = ?)',
            [$oid, $aid, (int)$agent['customer_id']]
        ) : null;
        $cid   = $order ? (int)$order['customer_id'] : 0;
        $bal   = $cid > 0 ? (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]) : 0;
        if (!$order) {
            $res = ['ok' => false, 'msg' => '订单不存在'];
        } elseif ($order['status'] !== 'unpaid') {
            $res = ['ok' => false, 'msg' => '订单状态不可支付'];
        } elseif ($bal < (float)$order['total']) {
            $res = ['ok' => false, 'msg' => '余额不足，请先充值或使用在线支付'];
        } else {
            $res = YxBusiness::payOrder((int)$order['id'], 'balance');
        }
    }

    log_action('agent.' . $action, 'agent#' . $aid, ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('agent/index.php' . ($payId > 0 ? '?pay=' . $payId : '')));
}

$cid      = (int)$agent['customer_id'];
$balance  = $cid > 0 ? (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]) : 0.0;
$custName = $cid > 0 ? (string)YxDb::value('SELECT username FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]) : '';
$products = YxDb::all('SELECT * FROM ' . YxDb::table('product') . ' WHERE status = 1 ORDER BY sort ASC, id DESC LIMIT 100');
$orders   = $cid > 0 ? YxAgent::myOrders($cid) : [];
$services = $cid > 0 ? YxAgent::myServices($cid) : [];
$payId    = (int)($_GET['pay'] ?? 0);
$payOrder = ($cid > 0 && $payId > 0)
    ? YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?', [$payId, $cid])
    : null;

$subsiteOn   = YxEdition::can('subsite');
$mySubsites  = $subsiteOn
    ? YxDb::all(
        'SELECT s.*, p.name AS plan_name FROM ' . YxDb::table('subsite') . ' s
         LEFT JOIN ' . YxDb::table('subsite_plan') . ' p ON p.id = s.plan_id
         WHERE s.agent_id = ? ORDER BY s.id DESC LIMIT 100',
        [$aid]
    )
    : [];
$subsitePlans = $subsiteOn ? YxSubsite::plans() : [];
$subsiteBal   = 0.0;
foreach ($mySubsites as $s) {
    $subsiteBal += (float)$s['balance'];
}
$pendingSubsitePay = $cid > 0
    ? YxDb::all(
        'SELECT * FROM ' . YxDb::table('order') . " WHERE customer_id = ? AND type = 'subsite' AND status = 'unpaid' ORDER BY id DESC LIMIT 20",
        [$cid]
    )
    : [];

$agentTitle  = '代理概览';
$agentActive = 'dashboard';
require dirname(__DIR__) . '/includes/agent_header.php';
?>

<div class="grid-4">
    <div class="stat accent"><span>代理折扣</span><strong><?= e(rtrim(rtrim(number_format(YxAgent::discount(), 2, '.', ''), '0'), '.')) ?>%</strong><small>下单自动按此折扣</small></div>
    <div class="stat"><span>账号余额</span><strong><?= e(money($balance, true)) ?></strong><small><?= $custName !== '' ? e('客户号 ' . $custName) : '首次下单自动建号' ?></small></div>
    <div class="stat"><span>我的业务</span><strong><?= count($services) ?></strong></div>
    <div class="stat"><span>待支付订单</span><strong><?= count(array_filter($orders, fn($o) => $o['status'] === 'unpaid')) ?></strong></div>
</div>

<?php if ($payOrder && $payOrder['status'] === 'unpaid'): ?>
<div class="card mt-4">
    <h2>确认支付</h2>
    <table class="data">
        <tr><th>订单号</th><td class="mono"><?= e($payOrder['sn']) ?></td></tr>
        <tr><th>订单标题</th><td><?= e($payOrder['name'] ?: '-') ?></td></tr>
        <tr><th>应付金额</th><td><strong><?= e(money($payOrder['total'], true)) ?></strong></td></tr>
        <tr><th>账号余额</th><td><?= e(money($balance, true)) ?></td></tr>
    </table>
    <div class="form-actions mt-3">
        <?php if ($balance >= (float)$payOrder['total']): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="id" value="<?= (int)$payOrder['id'] ?>">
                <button class="btn btn-primary" type="submit">使用余额支付 <?= e(money($payOrder['total'], true)) ?></button>
            </form>
        <?php else: ?>
            <span class="muted">余额不足，请先为代理账号充值（客户号：<?= e($custName) ?>）后重试。</span>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('agent/index.php')) ?>">稍后再付</a>
    </div>
    <?php if (YxPayment::configured()): ?>
        <div class="mt-3"><?= YxPayment::renderCheckout($payOrder, 'alipay', '代理下单', url('agent/index.php?pay=' . (int)$payOrder['id'])) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" id="subsites">
    <div class="section-head">
        <h2>我的分站（<?= count($mySubsites) ?>）</h2>
        <span class="muted small">累计佣金 <?= e(money($subsiteBal, true)) ?></span>
    </div>
    <?php if (!$subsiteOn): ?>
        <div class="alert alert-warn">当前版本未开放分站功能。</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>分站</th><th>账号</th><th>层级</th><th>套餐</th><th>到期</th><th>佣金余额</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($mySubsites as $s): ?>
            <tr>
                <td><?= (int)$s['id'] ?></td>
                <td class="wrap"><?= e($s['name']) ?><br><span class="muted small mono"><?= e($s['domain'] ?: '-') ?></span></td>
                <td class="mono small"><?= e($s['username']) ?></td>
                <td>L<?= (int)$s['level'] ?></td>
                <td><?= e($s['plan_name'] ?: '-') ?></td>
                <td class="small"><?= e($s['expire_at'] ?: '-') ?></td>
                <td><?= e(money($s['balance'], true)) ?></td>
                <td><span class="badge <?= YxSubsite::isUsable($s) ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxSubsite::statusName((int)$s['status'])) ?></span></td>
                <td>
                    <?php if ((int)$s['status'] !== YxSubsite::STATUS_ACTIVE && $subsitePlans): ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="subsite_renew">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">续费开通</button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('subsite/login.php')) ?>">后台入口</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$mySubsites): ?><tr><td colspan="9" class="empty">还没有分站，可在下方开通</td></tr><?php endif; ?>
        </tbody>
    </table></div>

    <?php if (!$subsitePlans): ?>
        <div class="empty mt-3">暂无可开通的分站套餐，请联系平台管理员配置</div>
    <?php else: ?>
    <h2 class="mt-4">开通分站</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="subsite_create">
        <div class="grid-3">
            <label>分站套餐
                <select name="plan_id" required>
                    <?php foreach ($subsitePlans as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> · <?= e(money($p['price'], true)) ?> / <?= (int)$p['duration_days'] ?>天 · 佣金 <?= e(rtrim(rtrim(number_format((float)$p['commission_rate'], 2, '.', ''), '0'), '.')) ?>%</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>分站名称 <input type="text" name="name" required maxlength="120" placeholder="如：华南一站"></label>
            <label>登录账号 <input type="text" name="username" required maxlength="50" placeholder="3-50 位字母/数字/_-@."></label>
        </div>
        <div class="grid-3">
            <label>登录密码 <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
            <label>绑定域名（可选） <input type="text" name="domain" maxlength="120" placeholder="sub.example.com"></label>
            <label>&nbsp;<button class="btn btn-primary btn-block" type="submit">创建分站</button></label>
        </div>
        <p class="muted small mt-2">付费套餐会生成开通订单，从本代理账号余额（客户号 <?= e($custName !== '' ? $custName : '首次开通时自动创建') ?>）中扣除；0 元套餐创建后立即开通。</p>
    </form>
    <?php endif; ?>

    <?php if ($pendingSubsitePay): ?>
    <h2 class="mt-4">待支付的开通 / 续费订单（<?= count($pendingSubsitePay) ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($pendingSubsitePay as $o): ?>
            <tr>
                <td class="mono small"><?= e($o['sn']) ?></td>
                <td class="wrap"><?= e($o['name'] ?: '-') ?></td>
                <td><?= e(money($o['total'], true)) ?></td>
                <td class="small muted"><?= e($o['created_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="<?= e(url('agent/index.php?pay=' . (int)$o['id'] . '#subsites')) ?>">去支付</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card" id="products">
    <h2>产品下单（代理价）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>产品</th><th>周期</th><th>原价</th><th>代理价</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td class="wrap"><?= e($p['name']) ?><?php if (!empty($p['auto_setup'])): ?> <span class="badge badge-info">自动开通</span><?php else: ?> <span class="badge badge-warn">人工开通</span><?php endif; ?></td>
                <td>
                    <form method="post" class="inline-form" id="of<?= (int)$p['id'] ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="order">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <select name="cycle">
                            <?php foreach (YxBusiness::CYCLES as $ck => $cv): ?>
                                <option value="<?= e($ck) ?>" <?= $ck === $p['cycle'] ? 'selected' : '' ?>><?= e($cv['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><?= e(money(YxBusiness::cyclePrice($p, $p['cycle']), true)) ?></td>
                <td><strong><?= e(money(YxAgent::priceFor($p, $p['cycle']), true)) ?></strong></td>
                <td>
                    <button class="btn btn-sm btn-primary" type="submit" form="of<?= (int)$p['id'] ?>">下单</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="5" class="empty">暂无可售产品</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <p class="muted small mt-2">代理价 = 产品周期价 × (1 − 代理折扣%)；「人工开通」的产品下单后由管理员开通。</p>
</div>

<div class="card" id="orders">
    <h2>我的订单（<?= count($orders) ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td class="mono small"><?= e($o['sn']) ?></td>
                <td class="wrap"><?= e($o['name'] ?: '-') ?></td>
                <td><?= e(money($o['total'], true)) ?></td>
                <td><span class="badge <?= $o['status'] === 'paid' ? 'badge-ok' : ($o['status'] === 'unpaid' ? 'badge-warn' : 'badge-mute') ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span></td>
                <td class="small muted"><?= e($o['created_at']) ?></td>
                <td><?php if ($o['status'] === 'unpaid'): ?><a class="btn btn-sm btn-primary" href="<?= e(url('agent/index.php?pay=' . (int)$o['id'])) ?>">去支付</a><?php else: ?><span class="muted small">-</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="6" class="empty">暂无订单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <h2>我的业务（<?= count($services) ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>业务</th><th>产品</th><th>IP</th><th>到期日</th><th>状态</th></tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
            <tr>
                <td class="wrap"><?= e($s['name']) ?><br><span class="muted small mono"><?= e($s['sn']) ?></span></td>
                <td><?= e($s['product_name'] ?: '-') ?></td>
                <td class="mono small"><?= e($s['ip'] ?: '-') ?></td>
                <td><?= e($s['next_due_date'] ?: '-') ?></td>
                <td><span class="badge <?= ['active' => 'badge-ok', 'pending' => 'badge-warn', 'suspended' => 'badge-warn', 'terminated' => 'badge-bad', 'expired' => 'badge-bad'][$s['status']] ?? 'badge-mute' ?>"><?= e(YxBusiness::serviceStatusName($s['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$services): ?><tr><td colspan="5" class="empty">暂无业务</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/agent_footer.php'; ?>
