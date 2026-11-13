<?php
/**
 * 优享财务系统 · 用户管理后台（/panel/）
 * 独立入口：概览 / 业务 / 订单 / 账单 / 充值 / 工单 / 签到 / 代理自助开通 / 授权版本购买 / 服务中心 / 漏洞提交 / 资料
 * 不含：应用商店、开发者中心、我的授权
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
if (!YxAuth::customer()) {
    redirect(url('panel/login.php'));
}
$customer = YxAuth::customer();
$cid      = (int)$customer['id'];

$MENU = ['overview', 'services', 'orders', 'invoices', 'recharge', 'tickets', 'checkin', 'agent', 'edition', 'support', 'vuln', 'profile'];
$m    = (string)($_GET['m'] ?? 'overview');
if (!in_array($m, $MENU, true)) {
    $m = 'overview';
}
$DEPARTMENTS = ['tech' => '技术支持', 'billing' => '财务计费', 'sales' => '售前咨询', 'abuse' => '投诉与违规'];
$PRIORITIES  = ['low' => '低', 'normal' => '中', 'high' => '高', 'urgent' => '紧急'];
$SEVERITY    = ['low' => '低', 'mid' => '中', 'high' => '高', 'critical' => '严重'];

function panel_edition(string $k): array
{
    $k   = in_array($k, ['basic', 'pro', 'enterprise'], true) ? $k : 'basic';
    $def = ['basic' => [10, 0], 'pro' => [1600, 365], 'enterprise' => [3600, 365]][$k];
    return ['key' => $k, 'name' => YxEdition::name($k), 'price' => (float)setting('edition_price_' . $k, $def[0]), 'days' => (int)setting('edition_days_' . $k, $def[1])];
}

/* ------------------------------ 表单处理 ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do     = (string)($_POST['do'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $back   = $m;
    $payId  = 0;

    if ($do === 'ticket_create') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $dept    = isset($DEPARTMENTS[$_POST['department'] ?? '']) ? $_POST['department'] : 'tech';
        $prio    = isset($PRIORITIES[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal';
        if ($subject === '' || $content === '') {
            $res = ['ok' => false, 'msg' => '请填写工单主题与描述'];
        } else {
            $tid = YxBusiness::createTicket(['customer_id' => $cid, 'department' => $dept, 'subject' => $subject, 'priority' => $prio]);
            YxBusiness::replyTicket($tid, 'customer', $cid, $content);
            $res = ['ok' => true, 'msg' => '工单已提交 #' . $tid];
        }
        $back = 'tickets';
    } elseif ($do === 'ticket_reply') {
        $tid = (int)($_POST['id'] ?? 0);
        $tk  = YxDb::one('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE id = ? AND customer_id = ?', [$tid, $cid]);
        $res = (!$tk) ? ['ok' => false, 'msg' => '工单不存在'] : YxBusiness::replyTicket($tid, 'customer', $cid, trim((string)($_POST['content'] ?? '')));
        $back = 'tickets';
    } elseif ($do === 'ticket_close') {
        $tid = (int)($_POST['id'] ?? 0);
        $tk  = YxDb::one('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE id = ? AND customer_id = ?', [$tid, $cid]);
        if ($tk && $tk['status'] !== 'closed') {
            $res = YxBusiness::closeTicket($tid);
        } else {
            $res = ['ok' => false, 'msg' => '工单不存在或已关闭'];
        }
        $back = 'tickets';
    } elseif ($do === 'checkin') {
        $res  = YxCheckin::checkin($cid);
        $back = 'checkin';
    } elseif ($do === 'profile_save') {
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $company = trim((string)($_POST['company'] ?? ''));
        $patch = ['email' => $email, 'phone' => $phone, 'company' => $company];
        $pwd = (string)($_POST['password'] ?? '');
        if ($pwd !== '') {
            if (strlen($pwd) < 8) {
                $res = ['ok' => false, 'msg' => '密码至少 8 位'];
                flash($res['msg'], 'error');
                redirect(url('panel/index.php?m=profile'));
            }
            $patch['password'] = YxAuth::hash($pwd);
        }
        YxDb::update('customer', $patch, 'id = :id', ['id' => $cid]);
        $res  = ['ok' => true, 'msg' => '资料已保存'];
        $back = 'profile';
    } elseif ($do === 'recharge_create') {
        $amount = round_money($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            $res = ['ok' => false, 'msg' => '请输入充值金额'];
        } else {
            $o = YxBusiness::createOrder(['customer_id' => $cid, 'type' => 'recharge', 'name' => '账户充值', 'amount' => $amount, 'note' => '用户后台充值']);
            if (empty($o['ok'])) {
                $res = $o;
            } else {
                $res   = ['ok' => true, 'msg' => '充值订单已创建'];
                $payId = (int)$o['order_id'];
            }
        }
        $back = 'recharge';
    } elseif ($do === 'pay') {
        $oid   = (int)($_POST['id'] ?? 0);
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?', [$oid, $cid]);
        $bal   = (float)$customer['balance'];
        if (!$order) {
            $res = ['ok' => false, 'msg' => '订单不存在'];
        } elseif ($order['status'] !== 'unpaid') {
            $res = ['ok' => false, 'msg' => '订单状态不可支付'];
        } elseif ($order['type'] === 'recharge') {
            $res = ['ok' => false, 'msg' => '充值订单请使用在线支付'];
        } elseif ($bal < (float)$order['total']) {
            $res = ['ok' => false, 'msg' => '余额不足，请先充值或在线支付'];
        } else {
            $res = YxBusiness::payOrder($oid, 'balance');
        }
        $back = in_array($_POST['from'] ?? '', $MENU, true) ? $_POST['from'] : 'orders';
    } elseif ($do === 'agent_order') {
        $agent = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE customer_id = ? LIMIT 1', [$cid]);
        $pid   = (int)($_POST['product_id'] ?? 0);
        $cycle = (string)($_POST['cycle'] ?? 'month');
        $prod  = $pid ? YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ? AND status = 1', [$pid]) : null;
        if (!$agent) {
            $res = ['ok' => false, 'msg' => '当前账号未关联代理，请联系管理员'];
        } elseif (!$prod) {
            $res = ['ok' => false, 'msg' => '产品不存在或已下架'];
        } else {
            $r = YxAgent::placeOrder($agent, $prod, $cycle);
            if (!empty($r['ok'])) {
                $payId = (int)$r['order_id'];
            }
            $res = $r;
        }
        $back = 'agent';
    } elseif ($do === 'edition_buy') {
        $ed     = panel_edition((string)($_POST['edition'] ?? 'basic'));
        $domain = normalize_domain((string)($_POST['domain'] ?? ''));
        if ($domain === '') {
            $res = ['ok' => false, 'msg' => '请填写要绑定的域名'];
        } elseif ($ed['price'] <= 0) {
            $res = ['ok' => false, 'msg' => '该版本价格未配置'];
        } else {
            $o = YxBusiness::createOrder([
                'customer_id' => $cid, 'type' => 'edition',
                'name'        => '授权：' . $ed['name'] . ' @ ' . $domain,
                'amount'      => $ed['price'],
                'note'        => json_encode(['edition' => $ed['key'], 'domain' => $domain, 'days' => $ed['days']], JSON_UNESCAPED_UNICODE),
            ]);
            if (empty($o['ok'])) {
                $res = $o;
            } else {
                $res   = ['ok' => true, 'msg' => '订单已创建，请完成支付'];
                $payId = (int)$o['order_id'];
            }
        }
        $back = 'edition';
    } elseif ($do === 'vuln_submit') {
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        if ($title === '' || $desc === '') {
            $res = ['ok' => false, 'msg' => '请填写漏洞标题与详细说明'];
        } else {
            // 报案 / 涉警类诉求：仍登记，但标记「仅人工办理」，不进入自助受理与自动答复
            $dispute = YxDispute::inspect($title, $desc);
            YxDb::insert('vuln_report', [
                'sn' => make_sn('VUL', 'vuln_report'), 'customer_id' => $cid,
                'reporter' => $customer['username'], 'contact' => trim((string)($_POST['contact'] ?? $customer['email'])),
                'title' => $title,
                'asset_type' => in_array($_POST['asset_type'] ?? '', ['web', 'server', 'app', 'other'], true) ? $_POST['asset_type'] : 'web',
                'target' => trim((string)($_POST['target'] ?? '')),
                'severity' => in_array($_POST['severity'] ?? '', ['low', 'mid', 'high', 'critical'], true) ? $_POST['severity'] : 'mid',
                'description' => $desc, 'poc' => trim((string)($_POST['poc'] ?? '')),
                'manual_only' => $dispute['hit'] ? 1 : 0,
                'manual_note' => $dispute['hit'] ? mb_substr('命中：' . implode('、', $dispute['keywords']), 0, 255) : null,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $res  = ['ok' => true, 'msg' => $dispute['hit'] ? '已登记，编号已生成。该诉求属报案 / 涉警类，仅人工办理，客服将通过工单与您联系' : '漏洞已提交，感谢反馈'];
            $back = 'vuln';
        }
    }

    log_action('panel.' . $do, 'customer#' . $cid, ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('panel/index.php?m=' . urlencode($back) . ($payId > 0 ? '&pay=' . $payId : '')));
}

/* ------------------------------ 数据 ------------------------------ */
$balance  = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
$payId    = (int)($_GET['pay'] ?? 0);
$payOrder = $payId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ? AND customer_id = ?', [$payId, $cid]) : null;
$agent    = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE customer_id = ? LIMIT 1', [$cid]);

$titles = ['overview' => '概览', 'services' => '我的业务', 'orders' => '我的订单', 'invoices' => '我的账单', 'recharge' => '余额充值', 'tickets' => '工单支持', 'checkin' => '每日签到', 'agent' => '代理自助开通', 'edition' => '授权版本购买', 'support' => '服务中心', 'vuln' => '漏洞提交', 'profile' => '资料设置'];
$panelTitle  = $titles[$m];
$panelActive = $m;
require dirname(__DIR__) . '/includes/panel_header.php';

/* 支付卡片（充值走网关；其它可余额）*/
if ($payOrder && $payOrder['status'] === 'unpaid'):
?>
<div class="card">
    <h2>确认支付</h2>
    <table class="data">
        <tr><th>订单号</th><td class="mono"><?= e($payOrder['sn']) ?></td><th>金额</th><td><strong><?= e(money($payOrder['total'], true)) ?></strong></td></tr>
        <tr><th>标题</th><td colspan="3"><?= e($payOrder['name'] ?: '-') ?></td></tr>
    </table>
    <div class="form-actions mt-3">
        <?php if ($payOrder['type'] !== 'recharge' && $balance >= (float)$payOrder['total']): ?>
            <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="do" value="pay">
                <input type="hidden" name="id" value="<?= (int)$payOrder['id'] ?>">
                <input type="hidden" name="from" value="<?= e($m) ?>">
                <button class="btn btn-primary" type="submit">余额支付 <?= e(money($payOrder['total'], true)) ?></button>
            </form>
        <?php endif; ?>
        <?php if (YxPayment::configured()): ?>
            <?= YxPayment::renderCheckout($payOrder, 'alipay', '用户后台支付', url('panel/index.php?m=' . $m . '&pay=' . (int)$payOrder['id'])) ?>
        <?php elseif ($payOrder['type'] === 'recharge'): ?>
            <span class="muted">在线支付通道未配置，请联系管理员充值。</span>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('panel/index.php?m=' . $m)) ?>">稍后再付</a>
    </div>
</div>
<?php endif; ?>

<?php if ($m === 'overview'): ?>
    <div class="grid-4">
        <div class="stat accent"><span>账户余额</span><strong><?= e(money($balance, true)) ?></strong></div>
        <div class="stat"><span>业务数</span><strong><?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE customer_id = ?', [$cid]) ?></strong></div>
        <div class="stat"><span>待支付订单</span><strong><?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . " WHERE customer_id = ? AND status = 'unpaid'", [$cid]) ?></strong></div>
        <div class="stat"><span>进行中工单</span><strong><?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ticket') . " WHERE customer_id = ? AND status IN ('open','replied')", [$cid]) ?></strong></div>
    </div>
    <div class="card mt-4">
        <div class="section-head"><h2>最近订单</h2><a href="<?= e(url('panel/index.php?m=orders')) ?>">全部 →</a></div>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>状态</th></tr></thead>
            <tbody>
            <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('order') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 6', [$cid]) as $o): ?>
                <tr><td class="mono small"><?= e($o['sn']) ?></td><td class="wrap"><?= e($o['name'] ?: '-') ?></td><td><?= e(money($o['total'], true)) ?></td>
                <td><span class="badge <?= $o['status'] === 'paid' ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($m === 'services'): ?>
    <div class="card">
        <h2>我的业务</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>业务</th><th>产品</th><th>IP</th><th>到期日</th><th>状态</th></tr></thead>
            <tbody>
            <?php foreach (YxDb::all('SELECT s.*, p.name AS product_name FROM ' . YxDb::table('service') . ' s LEFT JOIN ' . YxDb::table('product') . ' p ON p.id = s.product_id WHERE s.customer_id = ? ORDER BY s.id DESC LIMIT 100', [$cid]) as $s): ?>
                <tr><td class="wrap"><?= e($s['name']) ?><br><span class="muted small mono"><?= e($s['sn']) ?></span></td><td><?= e($s['product_name'] ?: '-') ?></td><td class="mono small"><?= e($s['ip'] ?: '-') ?></td><td><?= e($s['next_due_date'] ?: '-') ?></td>
                <td><span class="badge <?= ['active' => 'badge-ok', 'pending' => 'badge-warn', 'suspended' => 'badge-warn', 'terminated' => 'badge-bad', 'expired' => 'badge-bad'][$s['status']] ?? 'badge-mute' ?>"><?= e(YxBusiness::serviceStatusName($s['status'])) ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($m === 'orders'): ?>
    <div class="card">
        <h2>我的订单</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>单号</th><th>类型</th><th>标题</th><th>金额</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('order') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$cid]) as $o): ?>
                <tr><td class="mono small"><?= e($o['sn']) ?></td><td><?= e(['new' => '新购', 'renew' => '续费', 'recharge' => '充值', 'app' => '应用', 'deposit' => '保证金', 'edition' => '授权'][$o['type']] ?? $o['type']) ?></td><td class="wrap"><?= e($o['name'] ?: '-') ?></td><td><?= e(money($o['total'], true)) ?></td>
                <td><span class="badge <?= $o['status'] === 'paid' ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxBusiness::orderStatusName($o['status'])) ?></span></td>
                <td><?php if ($o['status'] === 'unpaid'): ?><a class="btn btn-sm btn-primary" href="<?= e(url('panel/index.php?m=orders&pay=' . (int)$o['id'])) ?>">去支付</a><?php else: ?><span class="muted small">-</span><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($m === 'invoices'): ?>
    <div class="card">
        <h2>我的账单</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>状态</th><th>到期日</th></tr></thead>
            <tbody>
            <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('invoice') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$cid]) as $iv): ?>
                <tr><td class="mono small"><?= e($iv['sn']) ?></td><td class="wrap"><?= e($iv['title'] ?: '-') ?></td><td><?= e(money($iv['amount'], true)) ?></td><td><?= e($iv['status']) ?></td><td><?= e($iv['due_date'] ?: '-') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($m === 'recharge'): ?>
    <div class="grid-2">
        <div class="card">
            <h2>账户充值</h2>
            <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="do" value="recharge_create">
                <label>充值金额（元） <input type="number" step="0.01" min="0.01" name="amount" required></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">生成充值单</button></div>
            </form>
            <p class="muted small mt-2">当前余额：<?= e(money($balance, true)) ?></p>
        </div>
        <div class="card">
            <h2>充值记录</h2>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>单号</th><th>金额</th><th>状态</th><th>时间</th></tr></thead>
                <tbody>
                <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('order') . " WHERE customer_id = ? AND type = 'recharge' ORDER BY id DESC LIMIT 20", [$cid]) as $o): ?>
                    <tr><td class="mono small"><?= e($o['sn']) ?></td><td><?= e(money($o['total'], true)) ?></td><td><?= e(YxBusiness::orderStatusName($o['status'])) ?></td><td class="small muted"><?= e($o['created_at']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>

<?php elseif ($m === 'tickets'): ?>
    <?php $tid = (int)($_GET['id'] ?? 0); $tk = $tid ? YxDb::one('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE id = ? AND customer_id = ?', [$tid, $cid]) : null; ?>
    <?php if ($tk): $replies = YxDb::all('SELECT * FROM ' . YxDb::table('ticket_reply') . ' WHERE ticket_id = ? ORDER BY id ASC', [$tid]); ?>
        <div class="card">
            <div class="section-head"><h2>#<?= (int)$tk['id'] ?> <?= e($tk['subject']) ?></h2>
                <div class="row-actions"><a class="btn btn-sm btn-ghost" href="<?= e(url('panel/index.php?m=tickets')) ?>">返回</a>
                <?php if ($tk['status'] !== 'closed'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ticket_close"><input type="hidden" name="id" value="<?= $tid ?>"><button class="btn btn-sm btn-bad" type="submit">关闭工单</button></form>
                <?php endif; ?></div>
            </div>
            <div class="thread">
                <?php foreach ($replies as $r): ?>
                    <div class="thread-item<?= $r['author_type'] === 'admin' ? ' is-admin' : '' ?>">
                        <div class="thread-head"><strong><?= $r['author_type'] === 'admin' ? '客服' : '我' ?></strong> · <?= e($r['created_at']) ?></div>
                        <div class="thread-body"><?= e($r['content']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($tk['status'] !== 'closed'): ?>
                <form method="post" class="mt-4"><?= csrf_field() ?><input type="hidden" name="do" value="ticket_reply"><input type="hidden" name="id" value="<?= $tid ?>">
                    <label>补充说明 <textarea name="content" required></textarea></label>
                    <button class="btn btn-primary" type="submit">提交回复</button></form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid-2">
            <div class="card">
                <h2>提交工单</h2>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ticket_create">
                    <label>主题 <input type="text" name="subject" required></label>
                    <div class="grid-2">
                        <label>部门 <select name="department"><?php foreach ($DEPARTMENTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
                        <label>优先级 <select name="priority"><?php foreach ($PRIORITIES as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <label>问题描述 <textarea name="content" required></textarea></label>
                    <button class="btn btn-primary" type="submit">提交</button>
                </form>
            </div>
            <div class="card">
                <h2>我的工单</h2>
                <div class="table-wrap"><table class="data">
                    <thead><tr><th>ID</th><th>主题</th><th>状态</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$cid]) as $t): ?>
                        <tr><td><?= (int)$t['id'] ?></td><td class="wrap"><?= e($t['subject']) ?></td><td><?= e($t['status']) ?></td><td><a class="btn btn-sm btn-ghost" href="<?= e(url('panel/index.php?m=tickets&id=' . (int)$t['id'])) ?>">查看</a></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($m === 'checkin'): ?>
    <?php $st = YxCheckin::stats($cid); $cal = YxCheckin::calendar($cid); $pts = (int)YxDb::value('SELECT points FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]); ?>
    <div class="grid-4">
        <div class="stat accent"><span>当前积分</span><strong><?= $pts ?></strong></div>
        <div class="stat"><span>连续签到</span><strong><?= $st['streak'] ?> 天</strong></div>
        <div class="stat"><span>本月签到</span><strong><?= $st['month_days'] ?> 天</strong></div>
        <div class="stat"><span>累计获得</span><strong><?= $st['total_points'] ?> 分</strong></div>
    </div>
    <div class="card mt-4">
        <h2>今日签到</h2>
        <?php if (!YxCheckin::enabled()): ?><div class="empty">签到功能未开启</div>
        <?php elseif ($st['checked']): ?><div class="alert alert-ok">今天已签到，已连续 <?= $st['streak'] ?> 天。</div>
        <?php else: ?>
            <p class="muted">每日签到得 <?= (int)YxCheckin::points() ?> 积分；连续每满 <?= (int)YxCheckin::streakDays() ?> 天额外奖励 <?= (int)YxCheckin::streakBonus() ?> 积分。</p>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="checkin"><button class="btn btn-primary" type="submit">立即签到</button></form>
        <?php endif; ?>
        <p class="muted small mt-3">本月已签到 <?= count($cal) ?> 天。</p>
    </div>

<?php elseif ($m === 'agent'): ?>
    <div class="card">
        <h2>代理自助开通</h2>
        <?php if (!$agent): ?>
            <div class="alert alert-warn">当前账号未关联代理身份。如需代理价自助开通，请联系管理员在「渠道 / 代理」中关联本账号。</div>
        <?php else: ?>
            <p class="muted small">代理折扣 <?= e(rtrim(rtrim(number_format(YxAgent::discount(), 2, '.', ''), '0'), '.')) ?>% · 下单自动按代理价计价，业务归属本账号。</p>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>产品</th><th>原价</th><th>代理价</th><th>下单</th></tr></thead>
                <tbody>
                <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('product') . ' WHERE status = 1 ORDER BY sort ASC, id DESC LIMIT 100') as $p): ?>
                    <tr>
                        <td class="wrap"><?= e($p['name']) ?><?php if (empty($p['auto_setup'])): ?> <span class="badge badge-warn">人工开通</span><?php endif; ?></td>
                        <td><?= e(money(YxBusiness::cyclePrice($p, $p['cycle']), true)) ?></td>
                        <td><strong><?= e(money(YxAgent::priceFor($p, $p['cycle']), true)) ?></strong></td>
                        <td><form method="post" class="inline-form" id="ao<?= (int)$p['id'] ?>"><?= csrf_field() ?>
                            <input type="hidden" name="do" value="agent_order"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                            <select name="cycle"><?php foreach (YxBusiness::CYCLES as $ck => $cv): ?><option value="<?= e($ck) ?>" <?= $ck === $p['cycle'] ? 'selected' : '' ?>><?= e($cv['name']) ?></option><?php endforeach; ?></select>
                            <button class="btn btn-sm btn-primary" type="submit">开通</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

<?php elseif ($m === 'edition'): ?>
    <div class="card">
        <h2>授权版本购买</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>版本</th><th>价格</th><th>有效期</th><th>购买</th></tr></thead>
            <tbody>
            <?php foreach (['basic', 'pro', 'enterprise'] as $ek): $e = panel_edition($ek); ?>
                <tr>
                    <td><?= e($e['name']) ?></td><td><?= e(money($e['price'], true)) ?></td><td><?= $e['days'] > 0 ? ($e['days'] . ' 天') : '永久' ?></td>
                    <td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="edition_buy"><input type="hidden" name="edition" value="<?= e($ek) ?>">
                        <input type="text" name="domain" placeholder="绑定域名" required>
                        <button class="btn btn-sm btn-primary" type="submit">下单</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="muted small mt-2">支付成功后自动为该域名签发授权码；可在门户「授权查询」核验。</p>
    </div>
    <div class="card">
        <h2>我的版本授权订单</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>单号</th><th>标题</th><th>金额</th><th>状态</th><th>授权码</th></tr></thead>
            <tbody>
            <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('order') . " WHERE customer_id = ? AND type = 'edition' ORDER BY id DESC LIMIT 50", [$cid]) as $o):
                $meta = json_decode((string)$o['note'], true) ?: [];
                $k = '';
                if ($o['status'] === 'paid' && !empty($meta['domain'])) {
                    $k = YxLicense::generateKey((string)$meta['domain'], (string)($meta['edition'] ?? 'basic'), (string)($meta['days'] ?? 0));
                }
            ?>
                <tr><td class="mono small"><?= e($o['sn']) ?></td><td class="wrap"><?= e($o['name']) ?></td><td><?= e(money($o['total'], true)) ?></td><td><?= e(YxBusiness::orderStatusName($o['status'])) ?></td><td class="mono small"><?= e($k ?: '-') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($m === 'support'): ?>
    <div class="grid-2">
        <div class="card">
            <h2>服务中心</h2>
            <p class="muted"><?= nl2br(e((string)setting('support_notice', '欢迎使用优享财务系统服务中心。'))) ?></p>
            <div class="form-actions mt-3">
                <a class="btn btn-primary" href="<?= e(url('panel/index.php?m=tickets')) ?>">提交工单</a>
                <a class="btn btn-ghost" href="<?= e(url('user/tickets.php')) ?>">工单历史</a>
            </div>
            <p class="muted small mt-3">客服 QQ：<?= e(setting('contact_qq', '-')) ?> · 邮箱：<?= e(setting('contact_email', '-')) ?>；也可点击右下角「小优助手」咨询或留言。</p>
        </div>
        <div class="card">
            <h2>常见问题（FAQ）</h2>
            <?php $faq = json_decode((string)setting('support_faq', ''), true); ?>
            <?php if (is_array($faq) && $faq): ?>
                <?php foreach ($faq as $f): ?>
                    <p class="my-2"><strong><?= e($f['q'] ?? '') ?></strong><br><span class="muted small"><?= nl2br(e($f['a'] ?? '')) ?></span></p>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty">暂无常见问题，可联系客服</div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($m === 'vuln'): ?>
    <div class="card">
        <h2>漏洞提交</h2>
        <?= YxDispute::noticeHtml() ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="vuln_submit">
            <div class="grid-3">
                <label>漏洞标题 <input type="text" name="title" required></label>
                <label>资产类型 <select name="asset_type"><?php foreach (['web' => '网站/应用', 'server' => '服务器/主机', 'app' => '客户端/插件', 'other' => '其它'] as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
                <label>危害等级 <select name="severity"><?php foreach ($SEVERITY as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'mid' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
                <label>影响目标 <input type="text" name="target" placeholder="域名 / IP / 功能入口"></label>
                <label>联系方式 <input type="text" name="contact" value="<?= e($customer['email']) ?>"></label>
            </div>
            <label>漏洞详情 <textarea name="description" rows="3" required placeholder="漏洞位置、成因、影响"></textarea></label>
            <label>复现步骤 / PoC <textarea name="poc" rows="3" placeholder="复现步骤、请求示例"></textarea></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit">提交漏洞</button></div>
        </form>
    </div>
    <div class="card">
        <h2>我的提交（<?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('vuln_report') . ' WHERE customer_id = ?', [$cid]) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>编号</th><th>标题</th><th>等级</th><th>状态</th><th>时间</th><th>处理说明</th></tr></thead>
            <tbody>
            <?php $vstat = ['pending' => '待处理', 'accepted' => '已受理', 'fixing' => '修复中', 'fixed' => '已修复', 'rejected' => '已忽略']; ?>
            <?php foreach (YxDb::all('SELECT * FROM ' . YxDb::table('vuln_report') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 50', [$cid]) as $v): ?>
                <tr><td class="mono small"><?= e($v['sn']) ?></td><td class="wrap"><?= e($v['title']) ?></td><td><?= e($SEVERITY[$v['severity']] ?? $v['severity']) ?></td><td><?= e($vstat[$v['status']] ?? $v['status']) ?></td><td class="small muted"><?= e($v['created_at']) ?></td><td class="small"><?= e($v['handle_note'] ?: '-') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($m === 'profile'): ?>
    <div class="card" style="max-width:680px">
        <h2>资料设置</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="profile_save">
            <div class="grid-2">
                <label>邮箱 <input type="email" name="email" value="<?= e($customer['email']) ?>"></label>
                <label>手机号 <input type="text" name="phone" value="<?= e($customer['phone']) ?>"></label>
                <label>公司名称 <input type="text" name="company" value="<?= e($customer['company']) ?>"></label>
                <label>新密码（留空不改，≥8 位） <input type="password" name="password" autocomplete="new-password"></label>
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">保存资料</button></div>
        </form>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/panel_footer.php'; ?>
