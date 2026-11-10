<?php
/**
 * 优享财务系统 · 渠道 / 代理商（专业版及以上）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('agent');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $user = trim((string)($_POST['username'] ?? ''));
        if ($name === '' || $user === '') {
            flash('渠道名称与账号为必填项', 'error');
            redirect(url('admin/agents.php'));
        }
        $data = [
            'name'            => $name,
            'username'        => $user,
            'domain'          => normalize_domain((string)($_POST['domain'] ?? '')),
            'commission_rate' => round_money($_POST['commission_rate'] ?? 0),
            'level_key'       => (string)($_POST['level_key'] ?? ''),
            'status'          => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
        ];
        // 等级需为已定义的等级标识，空表示不参与加价
        if ($data['level_key'] !== '' && !YxUpstreamMarkup::level($data['level_key'])) {
            $data['level_key'] = '';
        }
        $dup = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('agent') . ' WHERE username = ? AND id <> ?', [$user, $id]);
        if ($dup) {
            flash('该渠道账号已存在', 'error');
            redirect(url('admin/agents.php'));
        }
        if ($id > 0) {
            $pass = (string)($_POST['password'] ?? '');
            if ($pass !== '') {
                if (strlen($pass) < 6) {
                    flash('密码至少 6 位', 'error');
                    redirect(url('admin/agents.php'));
                }
                $data['password'] = YxAuth::hash($pass);
            }
            YxDb::update('agent', $data, 'id = :id', ['id' => $id]);
            flash('渠道信息已更新');
        } else {
            $pass = (string)($_POST['password'] ?? '');
            if (strlen($pass) < 6) {
                flash('请为渠道设置至少 6 位的登录密码', 'error');
                redirect(url('admin/agents.php'));
            }
            $data['password']   = YxAuth::hash($pass);
            $data['balance']    = 0;
            $data['created_at'] = now();
            YxDb::insert('agent', $data);
            flash('渠道已创建');
        }
        log_action('agent.save', $user);
        redirect(url('admin/agents.php'));
    }

    if ($action === 'withdraw') {
        $id     = (int)($_POST['id'] ?? 0);
        $amount = round_money($_POST['amount'] ?? 0);
        $agent  = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$id]);
        if (!$agent) {
            flash('渠道不存在', 'error');
            redirect(url('admin/agents.php'));
        }
        if ($amount <= 0 || $amount > (float)$agent['balance']) {
            flash('提现金额不合法或超出可提余额', 'error');
            redirect(url('admin/agents.php'));
        }
        $n = YxDb::exec('UPDATE ' . YxDb::table('agent') . ' SET balance = balance - ? WHERE id = ? AND balance >= ?', [$amount, $id, $amount]);
        if ($n <= 0) {
            flash('渠道佣金余额不足，提现失败', 'error');
            redirect(url('admin/agents.php'));
        }
        YxFinance::bookExpense($amount, 'agent_commission', '渠道提现：' . $agent['name'], (string)$admin['username']);
        log_action('agent.withdraw', $agent['name'], ['amount' => $amount]);
        flash('提现登记成功：' . money($amount, true));
        redirect(url('admin/agents.php'));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        YxDb::exec('UPDATE ' . YxDb::table('customer') . ' SET agent_id = 0 WHERE agent_id = ?', [$id]);
        YxDb::delete('agent', 'id = :id', ['id' => $id]);
        flash('渠道已删除，其名下客户已解绑');
        redirect(url('admin/agents.php'));
    }

    if ($action === 'save_settings') {
        set_setting('agent_discount', (string)max(0, min(99.99, (float)($_POST['agent_discount'] ?? 0))));
        log_action('agent.save_settings');
        flash('代理折扣已保存');
        redirect(url('admin/agents.php'));
    }
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$editId]) : null;
$rows    = YxDb::all('SELECT * FROM ' . YxDb::table('agent') . ' ORDER BY id DESC');

$pageTitle  = '渠道 / 代理';
$activeMenu = 'agents';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑渠道' : '新增渠道' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>渠道名称 <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
                <label>登录账号 <input type="text" name="username" required value="<?= e($editing['username'] ?? '') ?>"></label>
                <label>绑定域名 <input type="text" name="domain" value="<?= e($editing['domain'] ?? '') ?>" placeholder="agent.example.com"></label>
                <label>佣金比例（%） <input type="number" step="0.01" min="0" max="100" name="commission_rate" value="<?= e($editing['commission_rate'] ?? '0.00') ?>"></label>
                <label>代理等级（加价）
                    <select name="level_key">
                        <option value="">不设置（按原价）</option>
                        <?php foreach (YxUpstreamMarkup::levels() as $lv): ?>
                            <option value="<?= e($lv['key']) ?>" <?= (string)($editing['level_key'] ?? '') === $lv['key'] ? 'selected' : '' ?>><?= e($lv['name']) ?> · <?= e(YxUpstreamMarkup::ruleText($lv)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>登录密码 <?= $editing ? '（留空不修改）' : '' ?><input type="password" name="password" autocomplete="new-password"></label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建渠道' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/agents.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>渠道结算</h2>
        <p class="muted small">佣金按订单实付金额 × 佣金比例自动结算，记入渠道余额；提现会同时登记站方支出。</p>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <label class="grow">渠道
                <select name="id" required>
                    <option value="">请选择渠道</option>
                    <?php foreach ($rows as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?> · 可提 <?= e(money($a['balance'], true)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>提现金额 <input type="number" step="0.01" min="0.01" name="amount" required></label>
            <button class="btn btn-primary" type="submit">登记提现</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>代理自助购买设置</h2>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <label>全局代理折扣（%）
            <input type="number" step="0.01" min="0" max="99.99" name="agent_discount" value="<?= e(setting('agent_discount', '0')) ?>">
        </label>
        <button class="btn btn-primary" type="submit">保存</button>
        <span class="muted small">代理登录入口：<a href="<?= e(url('agent/login.php')) ?>" target="_blank" rel="noopener">/agent/login.php</a>（代理价 = 产品周期价 × (1 − 折扣%)）</span>
    </form>
</div>

<div class="card">
    <h2>渠道列表</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>名称</th><th>账号</th><th>代理客户号</th><th>域名</th><th>等级</th><th>佣金比例</th><th>余额</th><th>名下客户</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a): ?>
            <?php $cname = (int)($a['customer_id'] ?? 0) > 0 ? YxDb::value('SELECT username FROM ' . YxDb::table('customer') . ' WHERE id = ?', [(int)$a['customer_id']]) : ''; ?>
            <tr>
                <td><?= e($a['name']) ?></td>
                <td><?= e($a['username']) ?></td>
                <td class="mono small"><?= e($cname ?: '—') ?></td>
                <td class="mono small"><?= e($a['domain'] ?: '-') ?></td>
                <?php $alv = YxUpstreamMarkup::level((string)($a['level_key'] ?? '')); ?>
                <td><?= $alv ? '<span class="badge badge-info">' . e($alv['name']) . '</span>' : '<span class="muted small">—</span>' ?></td>
                <td><?= e($a['commission_rate']) ?>%</td>
                <td><strong><?= e(money($a['balance'], true)) ?></strong></td>
                <td><?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE agent_id = ?', [(int)$a['id']]) ?></td>
                <td><?= (int)$a['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/agents.php?edit=' . (int)$a['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确认删除该渠道？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">暂无渠道</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
