<?php
/**
 * 优享财务系统 · 分站后台
 *
 * 模块：概览 / 下级分站 / 订单记录 / 我的客户 / 佣金明细 / 举报工单 / 分站设置
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

$subsite = YxAuth::requireSubsite();
$sid     = (int)$subsite['id'];

$MODULES = ['overview', 'children', 'orders', 'customers', 'commissions', 'reports', 'settings'];
$m = (string)($_GET['m'] ?? 'overview');
if (!in_array($m, $MODULES, true)) {
    $m = 'overview';
}

$payId = (int)($_GET['pay'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $to     = 'subsite/index.php?m=' . $m;
    $payTo  = 0;

    if ($action === 'child_create') {
        $res = YxSubsite::createChild([
            'parent_id' => $sid,
            'plan_id'   => (int)($_POST['plan_id'] ?? 0),
            'name'      => (string)($_POST['name'] ?? ''),
            'username'  => (string)($_POST['username'] ?? ''),
            'password'  => (string)($_POST['password'] ?? ''),
            'domain'    => (string)($_POST['domain'] ?? ''),
        ]);
        if (!empty($res['ok'])) {
            $to = 'subsite/index.php?m=children';
            if (!empty($res['order_id'])) {
                $payTo = (int)$res['order_id'];
            }
        }
        log_action('subsite.child_create', 'subsite#' . $sid, ['ok' => !empty($res['ok'])]);
    } elseif ($action === 'pay') {
        $oid   = (int)($_POST['id'] ?? 0);
        $cid   = (int)($subsite['customer_id'] ?? 0);
        $order = $cid > 0
            ? YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?', [$oid, $cid])
            : null;
        if (!$order) {
            $res = ['ok' => false, 'msg' => '订单不存在'];
        } elseif ($order['status'] !== 'unpaid') {
            $res = ['ok' => false, 'msg' => '订单状态不可支付'];
        } else {
            $bal = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
            if ($bal < (float)$order['total']) {
                $res = ['ok' => false, 'msg' => '余额不足（可用 ' . money($bal, true) . '），请先为分站付款账号充值'];
            } else {
                $res = YxBusiness::payOrder($oid, 'balance');
            }
        }
        log_action('subsite.pay', 'order#' . $oid, ['ok' => !empty($res['ok'])]);
    } elseif ($action === 'renew') {
        $res = YxSubsite::renew($sid, (int)($_POST['plan_id'] ?? 0), (int)($subsite['customer_id'] ?? 0));
        $to  = 'subsite/index.php?m=settings';
        if (!empty($res['order_id'])) {
            $payTo = (int)$res['order_id'];
        }
    } elseif ($action === 'profile') {
        $res = YxSubsite::updateProfile($sid, [
            'name'   => (string)($_POST['name'] ?? ''),
            'domain' => (string)($_POST['domain'] ?? ''),
            'remark' => (string)($_POST['remark'] ?? ''),
        ]);
    } elseif ($action === 'password') {
        $pwd  = (string)($_POST['password'] ?? '');
        $pwd2 = (string)($_POST['password2'] ?? '');
        if ($pwd !== $pwd2) {
            $res = ['ok' => false, 'msg' => '两次输入的密码不一致'];
        } else {
            $res = YxSubsite::setPassword($sid, $pwd);
        }
    } elseif ($action === 'report_create') {
        $res = YxTicketReport::create([
            'subsite_id' => $sid,
            'customer_id' => 0,
            'ticket_id'  => (int)($_POST['ticket_id'] ?? 0),
            'category'   => (string)($_POST['category'] ?? 'other'),
            'subject'    => (string)($_POST['subject'] ?? ''),
            'content'    => (string)($_POST['content'] ?? ''),
            'contact'    => (string)($_POST['contact'] ?? ''),
            'priority'   => (string)($_POST['priority'] ?? 'normal'),
        ]);
        $to = 'subsite/index.php?m=reports';
    } elseif ($action === 'report_reply') {
        $rid = (int)($_POST['id'] ?? 0);
        $row = YxTicketReport::find($rid);
        if (!$row || (int)$row['subsite_id'] !== $sid) {
            $res = ['ok' => false, 'msg' => '举报单不存在'];
        } else {
            $res = YxTicketReport::addReply($rid, 'subsite', $sid, (string)($_POST['content'] ?? ''));
        }
        $to = 'subsite/index.php?m=reports&id=' . $rid;
    }

    flash((string)($res['msg'] ?? ''), !empty($res['ok']) ? 'success' : 'error');
    redirect(url($to . ($payTo > 0 ? '&pay=' . $payTo : '')));
}

/* ------------------------------ 数据准备 ------------------------------ */

$plans        = YxSubsite::plans();
$parent       = (int)$subsite['parent_id'] > 0 ? YxSubsite::byId((int)$subsite['parent_id']) : null;
$children     = YxSubsite::children($sid);
$descendants  = YxSubsite::descendants($sid);
$custId       = (int)($subsite['customer_id'] ?? 0);
$custBalance  = $custId > 0 ? (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$custId]) : 0.0;
$orders       = YxSubsite::myOrders($sid);
$myCustomers  = YxSubsite::myCustomers($sid);
$commissions  = YxSubsite::commissions($sid);
$commissionSum = (float)YxDb::value(
    'SELECT COALESCE(SUM(amount),0) FROM ' . YxDb::table('subsite_commission') . ' WHERE beneficiary_id = ?',
    [$sid]
);
$reports = YxTicketReport::myReports(0, $sid, 100);

$pendingPay = [];
if ($custId > 0) {
    $pendingPay = YxDb::all(
        'SELECT * FROM ' . YxDb::table('order') . " WHERE customer_id = ? AND type = 'subsite' AND status = 'unpaid' ORDER BY id DESC",
        [$custId]
    );
}
$payOrder = null;
if ($payId > 0 && $custId > 0) {
    $payOrder = YxDb::one(
        'SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?',
        [$payId, $custId]
    );
}

$reportDetail = null;
$reportId     = (int)($_GET['id'] ?? 0);
if ($m === 'reports' && $reportId > 0) {
    $row = YxTicketReport::detail($reportId);
    if ($row && (int)$row['subsite_id'] === $sid) {
        $reportDetail = $row;
    }
}

$quota = YxSubsite::canCreateChild($subsite);
$childLimit = (int)$subsite['max_children'];
$childUsed  = YxSubsite::childCount($sid);

$titles = [
    'overview'    => '分站概览',
    'children'    => '下级分站',
    'orders'      => '订单记录',
    'customers'   => '我的客户',
    'commissions' => '佣金明细',
    'reports'     => '举报工单',
    'settings'    => '分站设置',
];

$subsiteTitle  = $titles[$m];
$subsiteActive = $m;
require dirname(__DIR__) . '/includes/subsite_header.php';
?>

<?php if ($payOrder && $payOrder['status'] === 'unpaid'): ?>
<div class="card">
    <h2>确认支付</h2>
    <table class="data">
        <tr><th>订单号</th><td class="mono"><?= e($payOrder['sn']) ?></td></tr>
        <tr><th>订单标题</th><td><?= e($payOrder['name'] ?: '-') ?></td></tr>
        <tr><th>应付金额</th><td><strong><?= e(money($payOrder['total'], true)) ?></strong></td></tr>
        <tr><th>分站付款账号余额</th><td><?= e(money($custBalance, true)) ?></td></tr>
    </table>
    <div class="form-actions mt-3">
        <?php if ($custBalance >= (float)$payOrder['total']): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="id" value="<?= (int)$payOrder['id'] ?>">
                <button class="btn btn-primary" type="submit">使用余额支付 <?= e(money($payOrder['total'], true)) ?></button>
            </form>
        <?php else: ?>
            <span class="muted">余额不足，请先为分站付款账号充值后再支付。</span>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('subsite/index.php?m=' . $m)) ?>">稍后再付</a>
    </div>
</div>
<?php endif; ?>

<?php if ($m === 'overview'): ?>
<div class="grid-4">
    <div class="stat accent"><span>佣金余额</span><strong><?= e(money($subsite['balance'], true)) ?></strong><small>累计佣金 <?= e(money($commissionSum, true)) ?></small></div>
    <div class="stat"><span>下级分站</span><strong><?= $childUsed ?></strong><small><?= $childLimit > 0 ? '套餐上限 ' . $childLimit . ' 个' : '不限个数' ?></small></div>
    <div class="stat"><span>下级总层级</span><strong><?= count($descendants) ?></strong><small>含所有间接下级</small></div>
    <div class="stat"><span>付款账号余额</span><strong><?= e(money($custBalance, true)) ?></strong><small><?= $custId > 0 ? '客户号 #' . $custId : '首次开通下级时自动创建' ?></small></div>
</div>

<div class="card mt-4">
    <h2>分站信息</h2>
    <table class="data">
        <tr><th>分站名称</th><td><?= e($subsite['name']) ?></td><th>登录账号</th><td class="mono"><?= e($subsite['username']) ?></td></tr>
        <tr><th>层级</th><td>L<?= (int)$subsite['level'] ?></td><th>上级分站</th><td><?= $parent ? e($parent['name']) : '（顶级分站）' ?></td></tr>
        <tr><th>套餐</th><td><?= e(YxSubsite::plan((int)$subsite['plan_id'])['name'] ?? '-') ?></td><th>佣金比例</th><td><?= e(rtrim(rtrim(number_format((float)$subsite['commission_rate'], 2, '.', ''), '0'), '.')) ?>%</td></tr>
        <tr><th>到期日</th><td><?= e($subsite['expire_at'] ?: '未开通') ?></td><th>状态</th><td><span class="badge <?= YxSubsite::isUsable($subsite) ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxSubsite::statusName((int)$subsite['status'])) ?></span></td></tr>
        <tr><th>绑定域名</th><td colspan="3" class="mono"><?= e($subsite['domain'] ?: '-') ?></td></tr>
    </table>
    <?php if (!$quota['ok']): ?>
        <div class="alert alert-warn mt-3"><?= e($quota['msg']) ?></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>最近订单</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>状态</th><th>时间</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($orders, 0, 8) as $o): ?>
            <tr>
                <td class="mono small"><?= e($o['sn']) ?></td>
                <td class="wrap"><?= e($o['name'] ?: '-') ?></td>
                <td><?= e(money($o['total'], true)) ?></td>
                <td><span class="badge <?= $o['status'] === 'paid' ? 'badge-ok' : ($o['status'] === 'unpaid' ? 'badge-warn' : 'badge-mute') ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span></td>
                <td class="small muted"><?= e($o['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="5" class="empty">暂无订单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php elseif ($m === 'children'): ?>
<div class="card">
    <div class="section-head">
        <h2>下级分站（<?= count($children) ?>）</h2>
        <span class="muted small"><?= $childLimit > 0 ? '套餐上限 ' . $childLimit . ' 个，已用 ' . $childUsed . ' 个' : '当前套餐不限下级个数' ?></span>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>分站</th><th>账号</th><th>层级</th><th>套餐</th><th>到期</th><th>佣金</th><th>状态</th></tr></thead>
        <tbody>
        <?php foreach ($children as $c): ?>
            <tr>
                <td><?= (int)$c['id'] ?></td>
                <td class="wrap"><?= e($c['name']) ?><br><span class="muted small mono"><?= e($c['domain'] ?: '-') ?></span></td>
                <td class="mono small"><?= e($c['username']) ?></td>
                <td>L<?= (int)$c['level'] ?></td>
                <td><?= e($c['plan_name'] ?: '-') ?></td>
                <td><?= e($c['expire_at'] ?: '-') ?></td>
                <td><?= e(money($c['balance'], true)) ?></td>
                <td><span class="badge <?= YxSubsite::isUsable($c) ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxSubsite::statusName((int)$c['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$children): ?><tr><td colspan="8" class="empty">还没有下级分站，可在下方创建</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <h2>发展下级分站</h2>
    <?php if (!$quota['ok']): ?>
        <div class="alert alert-warn"><?= e($quota['msg']) ?></div>
    <?php endif; ?>
    <?php if (!$plans): ?>
        <div class="empty">暂无可用的分站套餐，请联系平台管理员配置</div>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="child_create">
        <div class="grid-3">
            <label>分站套餐
                <select name="plan_id" required>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">
                            <?= e($p['name']) ?> · <?= e(money($p['price'], true)) ?> / <?= (int)$p['duration_days'] ?>天 · 佣金 <?= e(rtrim(rtrim(number_format((float)$p['commission_rate'], 2, '.', ''), '0'), '.')) ?>%
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>分站名称 <input type="text" name="name" required maxlength="120" placeholder="如：华东一站"></label>
            <label>登录账号 <input type="text" name="username" required maxlength="50" placeholder="3-50 位字母/数字/_-@."></label>
        </div>
        <div class="grid-3">
            <label>登录密码 <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
            <label>绑定域名（可选） <input type="text" name="domain" maxlength="120" placeholder="sub.example.com"></label>
            <label>&nbsp;<button class="btn btn-primary btn-block" type="submit">创建并下单</button></label>
        </div>
        <p class="muted small mt-2">付费套餐会生成开通订单，从本分站的付款账号余额中扣除；0 元套餐创建后立即开通。</p>
    </form>
    <?php endif; ?>
</div>

<?php if ($pendingPay): ?>
<div class="card">
    <h2>待支付的开通订单（<?= count($pendingPay) ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($pendingPay as $o): ?>
            <tr>
                <td class="mono small"><?= e($o['sn']) ?></td>
                <td class="wrap"><?= e($o['name'] ?: '-') ?></td>
                <td><?= e(money($o['total'], true)) ?></td>
                <td class="small muted"><?= e($o['created_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="<?= e(url('subsite/index.php?m=children&pay=' . (int)$o['id'])) ?>">去支付</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php elseif ($m === 'orders'): ?>
<div class="card">
    <h2>订单记录（<?= count($orders) ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>状态</th><th>支付方式</th><th>创建时间</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td class="mono small"><?= e($o['sn']) ?></td>
                <td class="wrap"><?= e($o['name'] ?: '-') ?></td>
                <td><?= e(money($o['total'], true)) ?></td>
                <td><span class="badge <?= $o['status'] === 'paid' ? 'badge-ok' : ($o['status'] === 'unpaid' ? 'badge-warn' : 'badge-mute') ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span></td>
                <td class="small"><?= e($o['pay_method'] ?: '-') ?></td>
                <td class="small muted"><?= e($o['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="6" class="empty">暂无订单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php elseif ($m === 'customers'): ?>
<div class="card">
    <h2>我的客户（<?= count($myCustomers) ?>）</h2>
    <p class="muted small">归属本分站的客户账号；其下单产生的佣金按比例结算给本分站及上级分站。</p>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>账号</th><th>邮箱</th><th>手机</th><th>余额</th><th>状态</th><th>注册时间</th></tr></thead>
        <tbody>
        <?php foreach ($myCustomers as $c): ?>
            <tr>
                <td><?= (int)$c['id'] ?></td>
                <td class="mono small"><?= e($c['username']) ?></td>
                <td class="small"><?= e($c['email'] ?: '-') ?></td>
                <td class="small"><?= e($c['phone'] ?: '-') ?></td>
                <td><?= e(money($c['balance'], true)) ?></td>
                <td><span class="badge <?= (int)$c['status'] === 1 ? 'badge-ok' : 'badge-mute' ?>"><?= (int)$c['status'] === 1 ? '正常' : '冻结' ?></span></td>
                <td class="small muted"><?= e($c['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$myCustomers): ?><tr><td colspan="7" class="empty">暂无归属客户</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php elseif ($m === 'commissions'): ?>
<div class="card">
    <h2>佣金明细（累计 <?= e(money($commissionSum, true)) ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>时间</th><th>订单号</th><th>来源分站</th><th>层级</th><th>比例</th><th>基数</th><th>佣金</th></tr></thead>
        <tbody>
        <?php foreach ($commissions as $c): ?>
            <tr>
                <td class="small muted"><?= e($c['created_at']) ?></td>
                <td class="mono small"><?= e($c['order_sn'] ?: ('#' . (int)$c['order_id'])) ?></td>
                <td><?= e((int)$c['from_id'] > 0 ? ('#' . (int)$c['from_id']) : '平台直客') ?></td>
                <td><?= (int)$c['level'] > 0 ? '第 ' . (int)$c['level'] . ' 级上级' : '本级客户订单' ?></td>
                <td><?= e(rtrim(rtrim(number_format((float)$c['rate'], 2, '.', ''), '0'), '.')) ?>%</td>
                <td><?= e(money($c['base_amount'], true)) ?></td>
                <td><strong><?= e(money($c['amount'], true)) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$commissions): ?><tr><td colspan="7" class="empty">暂无佣金记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php elseif ($m === 'reports'): ?>
<?php if ($reportDetail): ?>
<div class="card">
    <div class="section-head">
        <h2><?= e($reportDetail['sn']) ?> <?= e($reportDetail['subject']) ?></h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('subsite/index.php?m=reports')) ?>">返回列表</a>
    </div>
    <table class="data">
        <tr><th>分类</th><td><?= e(YxTicketReport::categoryName((string)$reportDetail['category'])) ?></td><th>优先级</th><td><span class="badge <?= e(YxTicketReport::priorityBadge((string)$reportDetail['priority'])) ?>"><?= e(YxTicketReport::priorityName((string)$reportDetail['priority'])) ?></span></td></tr>
        <tr><th>状态</th><td><span class="badge <?= e(YxTicketReport::statusBadge((string)$reportDetail['status'])) ?>"><?= e(YxTicketReport::statusName((string)$reportDetail['status'])) ?></span></td><th>联系方式</th><td><?= e($reportDetail['contact'] ?: '-') ?></td></tr>
        <tr><th>提交时间</th><td><?= e($reportDetail['created_at']) ?></td><th>处理时间</th><td><?= e($reportDetail['handled_at'] ?: '-') ?></td></tr>
        <tr><th>举报说明</th><td colspan="3" style="white-space:pre-wrap"><?= e($reportDetail['content']) ?></td></tr>
        <?php if ($reportDetail['handle_note']): ?><tr><th>处理结论</th><td colspan="3"><?= e($reportDetail['handle_note']) ?></td></tr><?php endif; ?>
    </table>

    <h2 class="mt-4">沟通记录</h2>
    <?php if (!$reportDetail['replies']): ?>
        <div class="empty">暂无回复</div>
    <?php else: ?>
        <?php foreach ($reportDetail['replies'] as $r): $isAdmin = $r['author_type'] === 'admin'; ?>
            <div style="border-left:3px solid <?= $isAdmin ? '#1c7ed6' : '#c9d3e3' ?>;padding:0 0 10px 12px;margin-bottom:14px">
                <div class="small muted"><strong><?= $isAdmin ? '平台客服' : '分站' ?></strong> · <?= e($r['created_at']) ?></div>
                <div style="white-space:pre-wrap"><?= e($r['content']) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (YxTicketReport::isOpen((string)$reportDetail['status'])): ?>
        <form method="post" class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="report_reply">
            <input type="hidden" name="id" value="<?= (int)$reportDetail['id'] ?>">
            <label>补充说明 <textarea name="content" required placeholder="补充证据或追问处理进度"></textarea></label>
            <button class="btn btn-primary" type="submit">提交回复</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>提交举报</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="report_create">
        <div class="grid-3">
            <label>举报类型
                <select name="category">
                    <?php foreach (YxTicketReport::CATEGORIES as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>紧急程度
                <select name="priority">
                    <?php foreach (YxTicketReport::PRIORITIES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>联系方式 <input type="text" name="contact" maxlength="120" value="<?= e($subsite['username']) ?>"></label>
        </div>
        <label>举报标题 <input type="text" name="subject" required maxlength="200"></label>
        <label>举报说明 <textarea name="content" rows="4" required placeholder="请描述违规事实、涉及订单或客户"></textarea></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">提交举报</button></div>
    </form>
</div>

<div class="card">
    <h2>我的举报（<?= count($reports) ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>编号</th><th>标题</th><th>类型</th><th>优先级</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
            <tr>
                <td class="mono small"><?= e($r['sn']) ?></td>
                <td class="wrap"><?= e($r['subject']) ?></td>
                <td class="small"><?= e(YxTicketReport::categoryName((string)$r['category'])) ?></td>
                <td><span class="badge <?= e(YxTicketReport::priorityBadge((string)$r['priority'])) ?>"><?= e(YxTicketReport::priorityName((string)$r['priority'])) ?></span></td>
                <td><span class="badge <?= e(YxTicketReport::statusBadge((string)$r['status'])) ?>"><?= e(YxTicketReport::statusName((string)$r['status'])) ?></span></td>
                <td class="small muted"><?= e($r['created_at']) ?></td>
                <td><a class="btn btn-sm btn-ghost" href="<?= e(url('subsite/index.php?m=reports&id=' . (int)$r['id'])) ?>">查看</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$reports): ?><tr><td colspan="7" class="empty">暂无举报记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php else: ?>
<div class="card">
    <h2>分站资料</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <div class="grid-2">
            <label>分站名称 <input type="text" name="name" value="<?= e($subsite['name']) ?>" required maxlength="120"></label>
            <label>绑定域名 <input type="text" name="domain" value="<?= e($subsite['domain']) ?>" maxlength="120" placeholder="sub.example.com"></label>
        </div>
        <label>备注 <input type="text" name="remark" value="<?= e($subsite['remark']) ?>" maxlength="255"></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存资料</button></div>
    </form>
</div>

<div class="card">
    <h2>修改登录密码</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="grid-2">
            <label>新密码 <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
            <label>确认新密码 <input type="password" name="password2" required minlength="6" autocomplete="new-password"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">更新密码</button></div>
    </form>
</div>

<div class="card">
    <h2>套餐与续费</h2>
    <table class="data">
        <tr><th>当前套餐</th><td><?= e(YxSubsite::plan((int)$subsite['plan_id'])['name'] ?? '-') ?></td><th>到期日</th><td><?= e($subsite['expire_at'] ?: '未开通') ?></td></tr>
        <tr><th>佣金比例</th><td><?= e(rtrim(rtrim(number_format((float)$subsite['commission_rate'], 2, '.', ''), '0'), '.')) ?>%</td><th>下级上限</th><td><?= $childLimit > 0 ? $childLimit . ' 个' : '不限' ?></td></tr>
    </table>
    <?php if (!$plans): ?>
        <div class="empty mt-3">暂无可购套餐</div>
    <?php else: ?>
    <form method="post" class="inline-form mt-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="renew">
        <label>续费 / 升级套餐
            <select name="plan_id">
                <?php foreach ($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$subsite['plan_id'] ? 'selected' : '' ?>>
                        <?= e($p['name']) ?> · <?= e(money($p['price'], true)) ?> / <?= (int)$p['duration_days'] ?>天
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary" type="submit">生成续费订单</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/subsite_footer.php'; ?>
