<?php
/**
 * 优享财务系统 · 分站管理（后台）
 *
 * 分站套餐维护、分站开通 / 延期 / 停用、佣金查看与结算、登录密码重置。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

// 分站管理功能已下线：后台入口与页面统一拦截，保留代码以便后续恢复
flash('分站管理功能已下线', 'warn');
redirect(url('admin/index.php'));

YxEdition::requireFeature('subsite');

$STATUS = [1 => '正常', 0 => '待激活 / 停用'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $back   = 'admin/subsites.php';

    if ($action === 'plan_save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $res = ['ok' => false, 'msg' => '套餐名称不能为空'];
        } else {
            $data = [
                'name'            => $name,
                'price'           => round_money($_POST['price'] ?? 0),
                'duration_days'   => max(1, (int)($_POST['duration_days'] ?? 365)),
                'commission_rate' => max(0, min(100, round_money($_POST['commission_rate'] ?? 0))),
                'max_children'    => max(0, (int)($_POST['max_children'] ?? 0)),
                'sort'            => (int)($_POST['sort'] ?? 0),
                'status'          => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
                'remark'          => trim((string)($_POST['remark'] ?? '')),
            ];
            if ($id > 0) {
                YxDb::update('subsite_plan', $data, 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '套餐已更新'];
            } else {
                $data['created_at'] = now();
                YxDb::insert('subsite_plan', $data);
                $res = ['ok' => true, 'msg' => '套餐已创建'];
            }
            log_action('subsite.plan_save', $name, ['id' => $id]);
        }
        $back = 'admin/subsites.php#plans';
    } elseif ($action === 'plan_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
        YxDb::update('subsite_plan', ['status' => $st], 'id = :id', ['id' => $id]);
        log_action('subsite.plan_status', 'plan#' . $id, ['status' => $st]);
        $res  = ['ok' => true, 'msg' => $st === 1 ? '套餐已上架' : '套餐已下架'];
        $back = 'admin/subsites.php#plans';
    } elseif ($action === 'create') {
        $res = YxSubsite::createChild([
            'parent_id'   => 0,
            'plan_id'     => (int)($_POST['plan_id'] ?? 0),
            'name'        => (string)($_POST['name'] ?? ''),
            'username'    => (string)($_POST['username'] ?? ''),
            'password'    => (string)($_POST['password'] ?? ''),
            'domain'      => (string)($_POST['domain'] ?? ''),
            'customer_id' => (int)($_POST['customer_id'] ?? 0),
            'agent_id'    => (int)($_POST['agent_id'] ?? 0),
            'remark'      => trim((string)($_POST['remark'] ?? '')),
        ]);
        log_action('subsite.create', (string)($_POST['username'] ?? ''), ['ok' => !empty($res['ok'])]);
    } elseif ($action === 'extend') {
        $id   = (int)($_POST['id'] ?? 0);
        $days = (int)($_POST['days'] ?? 0);
        if ($days === 0) {
            $res = ['ok' => false, 'msg' => '请填写延期天数'];
        } else {
            $res = YxSubsite::activate($id, abs($days));
            log_action('subsite.extend', 'subsite#' . $id, ['days' => $days]);
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $st = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
        YxDb::update('subsite', ['status' => $st, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        log_action('subsite.toggle', 'subsite#' . $id, ['status' => $st]);
        $res = ['ok' => true, 'msg' => $st === 1 ? '分站已启用' : '分站已停用'];
    } elseif ($action === 'password') {
        $id  = (int)($_POST['id'] ?? 0);
        $pwd = (string)($_POST['password'] ?? '');
        $res = YxSubsite::setPassword($id, $pwd);
        log_action('subsite.password', 'subsite#' . $id, ['ok' => !empty($res['ok'])]);
    } elseif ($action === 'renew') {
        $id  = (int)($_POST['id'] ?? 0);
        $res = YxSubsite::renew($id, (int)($_POST['plan_id'] ?? 0));
        log_action('subsite.renew', 'subsite#' . $id, ['ok' => !empty($res['ok'])]);
    } elseif ($action === 'payout') {
        $id     = (int)($_POST['id'] ?? 0);
        $amount = round_money($_POST['amount'] ?? 0);
        $row    = YxSubsite::byId($id);
        if (!$row) {
            $res = ['ok' => false, 'msg' => '分站不存在'];
        } elseif ($amount <= 0 || $amount > (float)$row['balance']) {
            $res = ['ok' => false, 'msg' => '提现金额不合法或超出可提佣金'];
        } else {
            $n = YxDb::exec(
                'UPDATE ' . YxDb::table('subsite') . ' SET balance = balance - ?, updated_at = ? WHERE id = ? AND balance >= ?',
                [$amount, now(), $id, $amount]
            );
            if ($n <= 0) {
                $res = ['ok' => false, 'msg' => '佣金余额不足，提现失败'];
            } else {
                YxFinance::bookExpense($amount, 'subsite_commission', '分站佣金提现：' . $row['name'], (string)$admin['username']);
                YxDb::insert('subsite_commission', [
                    'order_id'       => 0,
                    'order_sn'       => null,
                    'beneficiary_id' => $id,
                    'from_id'        => 0,
                    'level'          => 0,
                    'rate'           => 0,
                    'base_amount'    => 0,
                    'amount'         => -$amount,
                    'status'         => 'paid_out',
                    'remark'         => '佣金提现（操作人 ' . $admin['username'] . '）',
                    'created_at'     => now(),
                ]);
                log_action('subsite.payout', 'subsite#' . $id, ['amount' => $amount]);
                $res = ['ok' => true, 'msg' => '提现登记成功：' . money($amount, true)];
            }
        }
        $back = 'admin/subsites.php#commissions';
    }

    flash((string)($res['msg'] ?? ''), !empty($res['ok']) ? 'success' : 'error');
    redirect(url($back));
}

$editPlan = null;
$planId   = (int)($_GET['plan'] ?? 0);
if ($planId > 0) {
    $editPlan = YxSubsite::plan($planId);
}

$plans = YxSubsite::plans(false);
$planMap = [];
foreach ($plans as $p) {
    $planMap[(int)$p['id']] = $p;
}

$statusF = (string)($_GET['status'] ?? '');
$kwF     = trim((string)($_GET['kw'] ?? ''));
$where   = ' WHERE 1=1';
$bind    = [];
if ($statusF === 'active') {
    $where .= ' AND s.status = 1';
} elseif ($statusF === 'pending') {
    $where .= ' AND s.status = 0';
} elseif ($statusF === 'expired') {
    $where .= " AND s.expire_at IS NOT NULL AND s.expire_at <> '' AND s.expire_at < ?";
    $bind[] = date('Y-m-d');
}
if ($kwF !== '') {
    $where .= ' AND (s.name LIKE ? OR s.username LIKE ? OR s.domain LIKE ?)';
    $like   = '%' . $kwF . '%';
    array_push($bind, $like, $like, $like);
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('subsite') . ' s' . $where, $bind);
$page  = paginate($total, 20);
$rows  = YxDb::all(
    'SELECT s.*, p.name AS plan_name, c.username AS payer_username
     FROM ' . YxDb::table('subsite') . ' s
     LEFT JOIN ' . YxDb::table('subsite_plan') . ' p ON p.id = s.plan_id
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = s.customer_id
     ' . $where . ' ORDER BY s.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$stat = [
    'total'   => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('subsite')),
    'active'  => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('subsite') . ' WHERE status = 1'),
    'pending' => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('subsite') . ' WHERE status = 0'),
    'balance' => (float)YxDb::value('SELECT COALESCE(SUM(balance),0) FROM ' . YxDb::table('subsite')),
];

$commissions = YxDb::all(
    'SELECT k.*, s.name AS beneficiary_name
     FROM ' . YxDb::table('subsite_commission') . ' k
     LEFT JOIN ' . YxDb::table('subsite') . ' s ON s.id = k.beneficiary_id
     ORDER BY k.id DESC LIMIT 100'
);

$customers = YxDb::all('SELECT id, username FROM ' . YxDb::table('customer') . ' ORDER BY id DESC LIMIT 300');
$agents    = YxDb::all('SELECT id, name FROM ' . YxDb::table('agent') . ' ORDER BY id DESC LIMIT 200');

$pageTitle  = '分站管理';
$activeMenu = 'subsites';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="stat accent"><span>分站总数</span><strong><?= $stat['total'] ?></strong><small>含待激活</small></div>
    <div class="stat"><span>正常分站</span><strong><?= $stat['active'] ?></strong></div>
    <div class="stat"><span>待激活 / 停用</span><strong><?= $stat['pending'] ?></strong></div>
    <div class="stat"><span>待结算佣金</span><strong><?= e(money($stat['balance'], true)) ?></strong><small>分站账户余额合计</small></div>
</div>

<div class="grid-2 mt-4">
    <div class="card" id="plans">
        <h2><?= $editPlan ? '编辑分站套餐' : '新增分站套餐' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="plan_save">
            <input type="hidden" name="id" value="<?= (int)($editPlan['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>套餐名称 <input type="text" name="name" required maxlength="120" value="<?= e($editPlan['name'] ?? '') ?>"></label>
                <label>套餐价格 <input type="number" step="0.01" min="0" name="price" value="<?= e($editPlan['price'] ?? '0.00') ?>"></label>
                <label>有效期（天） <input type="number" min="1" name="duration_days" value="<?= e($editPlan['duration_days'] ?? 365) ?>"></label>
                <label>佣金比例（%） <input type="number" step="0.01" min="0" max="100" name="commission_rate" value="<?= e($editPlan['commission_rate'] ?? '0.00') ?>"></label>
                <label>可发展下级数 <input type="number" min="0" name="max_children" value="<?= e($editPlan['max_children'] ?? 0) ?>"><span class="muted small">0 = 不限</span></label>
                <label>排序 <input type="number" name="sort" value="<?= e($editPlan['sort'] ?? 0) ?>"></label>
            </div>
            <label>备注 <input type="text" name="remark" maxlength="255" value="<?= e($editPlan['remark'] ?? '') ?>"></label>
            <label>状态
                <select name="status">
                    <option value="1" <?= (int)($editPlan['status'] ?? 1) === 1 ? 'selected' : '' ?>>上架</option>
                    <option value="0" <?= (int)($editPlan['status'] ?? 1) === 0 ? 'selected' : '' ?>>下架</option>
                </select>
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editPlan ? '保存修改' : '创建套餐' ?></button>
                <?php if ($editPlan): ?><a class="btn btn-ghost" href="<?= e(url('admin/subsites.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>

        <h2 class="mt-4">套餐列表（<?= count($plans) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>ID</th><th>名称</th><th>价格</th><th>时长</th><th>佣金</th><th>下级上限</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
                <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td class="wrap"><?= e($p['name']) ?><?php if ($p['remark']): ?><br><span class="muted small"><?= e($p['remark']) ?></span><?php endif; ?></td>
                    <td><?= e(money($p['price'], true)) ?></td>
                    <td><?= (int)$p['duration_days'] ?> 天</td>
                    <td><?= e(rtrim(rtrim(number_format((float)$p['commission_rate'], 2, '.', ''), '0'), '.')) ?>%</td>
                    <td><?= (int)$p['max_children'] > 0 ? (int)$p['max_children'] : '不限' ?></td>
                    <td><?= (int)$p['status'] === 1 ? '<span class="badge badge-ok">上架</span>' : '<span class="badge badge-mute">下架</span>' ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/subsites.php?plan=' . (int)$p['id'] . '#plans')) ?>">编辑</a>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="plan_status">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="status" value="<?= (int)$p['status'] === 1 ? 0 : 1 ?>">
                                <button class="btn btn-sm btn-ghost" type="submit"><?= (int)$p['status'] === 1 ? '下架' : '上架' ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$plans): ?><tr><td colspan="8" class="empty">暂无套餐，请先创建</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <h2>开通顶级分站</h2>
        <?php if (!$plans): ?>
            <div class="empty">请先创建分站套餐</div>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="grid-2">
                <label>分站套餐
                    <select name="plan_id" required>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['status'] !== 1 ? 'disabled' : '' ?>>
                                <?= e($p['name']) ?> · <?= e(money($p['price'], true)) ?> / <?= (int)$p['duration_days'] ?>天<?= (int)$p['status'] !== 1 ? '（已下架）' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>分站名称 <input type="text" name="name" required maxlength="120" placeholder="如：华东一站"></label>
                <label>登录账号 <input type="text" name="username" required maxlength="50" placeholder="3-50 位字母/数字/_-@."></label>
                <label>登录密码 <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
                <label>付款客户账号
                    <select name="customer_id">
                        <option value="0">（仅 0 元套餐可不选）</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> · <?= e($c['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>归属渠道
                    <select name="agent_id">
                        <option value="0">不归属</option>
                        <?php foreach ($agents as $a): ?>
                            <option value="<?= (int)$a['id'] ?>">#<?= (int)$a['id'] ?> · <?= e($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>绑定域名 <input type="text" name="domain" maxlength="120" placeholder="sub.example.com"></label>
            <label>备注 <input type="text" name="remark" maxlength="255"></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit">创建分站</button></div>
            <p class="muted small mt-2">付费套餐会以所选客户账号生成开通订单，可在「订单管理」中确认收款；0 元套餐立即开通。</p>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="section-head">
        <h2>分站列表（共 <?= $total ?> 个）</h2>
        <form method="get" class="inline-form">
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <option value="active" <?= $statusF === 'active' ? 'selected' : '' ?>>正常</option>
                    <option value="pending" <?= $statusF === 'pending' ? 'selected' : '' ?>>待激活 / 停用</option>
                    <option value="expired" <?= $statusF === 'expired' ? 'selected' : '' ?>>已到期</option>
                </select>
            </label>
            <label>关键字 <input type="text" name="kw" value="<?= e($kwF) ?>" placeholder="名称 / 账号 / 域名"></label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>分站</th><th>上级</th><th>层级</th><th>套餐</th><th>佣金比例</th><th>佣金余额</th><th>到期日</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
            <tr>
                <td><?= (int)$s['id'] ?></td>
                <td class="wrap">
                    <?= e($s['name']) ?><br>
                    <span class="muted small mono"><?= e($s['username']) ?></span>
                    <?php if ($s['domain']): ?><br><span class="muted small mono"><?= e($s['domain']) ?></span><?php endif; ?>
                </td>
                <td class="small"><?= (int)$s['parent_id'] > 0 ? '#' . (int)$s['parent_id'] : '平台直属' ?></td>
                <td>L<?= (int)$s['level'] ?></td>
                <td class="small"><?= e($s['plan_name'] ?: '-') ?></td>
                <td><?= e(rtrim(rtrim(number_format((float)$s['commission_rate'], 2, '.', ''), '0'), '.')) ?>%</td>
                <td><strong><?= e(money($s['balance'], true)) ?></strong></td>
                <td class="small"><?= e($s['expire_at'] ?: '-') ?></td>
                <td>
                    <?php $usable = YxSubsite::isUsable($s); ?>
                    <span class="badge <?= $usable ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxSubsite::statusName((int)$s['status'])) ?></span>
                    <?php if (YxSubsite::isExpired($s)): ?><br><span class="badge badge-bad">已到期</span><?php endif; ?>
                </td>
                <td>
                    <details><summary style="cursor:pointer;color:var(--primary)">管理</summary>
                        <form method="post" class="inline-form mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="extend">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="number" name="days" value="<?= (int)($planMap[(int)$s['plan_id']]['duration_days'] ?? 365) ?>" min="1" style="width:80px">
                            <button class="btn btn-sm btn-primary" type="submit">延期</button>
                        </form>
                        <form method="post" class="inline-form mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="renew">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <select name="plan_id">
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$s['plan_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-ghost" type="submit">生成续费单</button>
                        </form>
                        <form method="post" class="inline-form mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="status" value="<?= (int)$s['status'] === 1 ? 0 : 1 ?>">
                            <button class="btn btn-sm btn-ghost" type="submit"><?= (int)$s['status'] === 1 ? '停用' : '启用' ?></button>
                        </form>
                        <form method="post" class="inline-form mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="password">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="password" name="password" placeholder="重置登录密码" minlength="6" autocomplete="new-password" style="width:140px">
                            <button class="btn btn-sm btn-ghost" type="submit">重置</button>
                        </form>
                        <form method="post" class="inline-form mt-2" onsubmit="return confirm('确认登记该分站佣金提现？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="payout">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="number" step="0.01" min="0.01" name="amount" placeholder="提现金额" style="width:110px" required>
                            <button class="btn btn-sm btn-bad" type="submit">佣金提现</button>
                        </form>
                        <div class="muted small mt-2">
                            付款账号：<?= e($s['payer_username'] ?: '未绑定') ?> · 下级 <?= YxSubsite::childCount((int)$s['id']) ?> 个
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">暂无分站</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<div class="card" id="commissions">
    <h2>佣金流水（最近 100 条）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>时间</th><th>订单号</th><th>受益分站</th><th>来源分站</th><th>层级</th><th>比例</th><th>基数</th><th>佣金</th><th>状态</th></tr></thead>
        <tbody>
        <?php foreach ($commissions as $k): ?>
            <tr>
                <td class="small muted"><?= e($k['created_at']) ?></td>
                <td class="mono small"><?= e($k['order_sn'] ?: ($k['order_id'] > 0 ? '#' . (int)$k['order_id'] : '-')) ?></td>
                <td><?= e($k['beneficiary_name'] ?: ('#' . (int)$k['beneficiary_id'])) ?></td>
                <td class="small"><?= (int)$k['from_id'] > 0 ? '#' . (int)$k['from_id'] : '平台直客' ?></td>
                <td><?= (int)$k['level'] > 0 ? '第 ' . (int)$k['level'] . ' 级上级' : '本级客户订单' ?></td>
                <td><?= e(rtrim(rtrim(number_format((float)$k['rate'], 2, '.', ''), '0'), '.')) ?>%</td>
                <td><?= e(money($k['base_amount'], true)) ?></td>
                <td><strong><?= e(money($k['amount'], true)) ?></strong></td>
                <td>
                    <?php $kcls = $k['status'] === 'settled' ? 'badge-ok' : ($k['status'] === 'paid_out' ? 'badge-info' : 'badge-mute'); ?>
                    <span class="badge <?= $kcls ?>"><?= e($k['status'] === 'settled' ? '已结算' : ($k['status'] === 'paid_out' ? '已提现' : $k['status'])) ?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$commissions): ?><tr><td colspan="9" class="empty">暂无佣金记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
