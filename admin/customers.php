<?php
/**
 * 优享财务系统 · 客户管理
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$LEVELS = ['normal' => '普通客户', 'vip' => 'VIP 客户', 'agent' => '渠道客户', 'blacklist' => '黑名单'];

/** 分站下拉选项（id => 名称） */
$subsiteOptions = [];
foreach (YxDb::all('SELECT id, name, username FROM ' . YxDb::table('subsite') . ' ORDER BY id ASC') as $s) {
    $subsiteOptions[(int)$s['id']] = $s['name'] . '（' . $s['username'] . '）';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $subsiteId = (int)($_POST['subsite_id'] ?? 0);
        if ($subsiteId > 0 && !isset($subsiteOptions[$subsiteId])) {
            $subsiteId = 0;
        }
        $data = [
            'username'   => $username,
            'email'      => $email,
            'phone'      => trim((string)($_POST['phone'] ?? '')),
            'company'    => trim((string)($_POST['company'] ?? '')),
            'level'      => isset($LEVELS[$_POST['level'] ?? '']) ? $_POST['level'] : 'normal',
            'status'     => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            'subsite_id' => $subsiteId,
            'remark'     => trim((string)($_POST['remark'] ?? '')),
        ];
        if ($username === '' || $email === '') {
            flash('账号与邮箱为必填项', 'error');
            redirect(url('admin/customers.php'));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('邮箱格式不正确', 'error');
            redirect(url('admin/customers.php'));
        }

        $dup = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE username = ? AND id <> ?', [$username, $id]);
        if ($dup) {
            flash('账号已存在', 'error');
            redirect(url('admin/customers.php'));
        }

        if ($id > 0) {
            $password = (string)($_POST['password'] ?? '');
            if ($password !== '') {
                if (strlen($password) < 6) {
                    flash('密码至少 6 位', 'error');
                    redirect(url('admin/customers.php'));
                }
                $data['password'] = YxAuth::hash($password);
            }
            YxDb::update('customer', $data, 'id = :id', ['id' => $id]);
            log_action('customer.update', $username, ['id' => $id]);
            flash('客户资料已更新');
        } else {
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 6) {
                flash('请为新客户设置至少 6 位的密码', 'error');
                redirect(url('admin/customers.php'));
            }
            $data['password']   = YxAuth::hash($password);
            $data['balance']    = round_money($_POST['balance'] ?? 0);
            $data['credit']     = 0;
            $data['points']     = 0;
            $data['agent_id']   = 0;
            $data['reg_ip']     = client_ip();
            $data['created_at'] = now();
            $newId = customer_insert($data);
            log_action('customer.create', $username, ['id' => $newId]);
            flash('客户已创建');
        }
        redirect(url('admin/customers.php'));
    }

    if ($action === 'adjust') {
        $id     = (int)($_POST['id'] ?? 0);
        $amount = round_money($_POST['amount'] ?? 0);
        $dir    = ($_POST['direction'] ?? 'income') === 'expense' ? 'expense' : 'income';
        $remark = trim((string)($_POST['remark'] ?? '')) ?: '后台手工调整';
        if ($amount <= 0) {
            flash('调整金额必须大于 0', 'error');
            redirect(url('admin/customers.php'));
        }
        $res = $dir === 'income'
            ? YxFinance::credit($id, $amount, 'manual', ['method' => 'manual', 'ref_type' => 'manual', 'ref_id' => $id, 'remark' => $remark, 'operator' => $admin['username']])
            : YxFinance::debit($id, $amount, 'manual', ['method' => 'manual', 'ref_type' => 'manual', 'ref_id' => $id, 'remark' => $remark, 'operator' => $admin['username']]);
        log_action('customer.adjust', 'customer#' . $id, ['direction' => $dir, 'amount' => $amount, 'ok' => $res['ok']]);
        flash($res['msg'] . '（当前余额 ' . money($res['balance'] ?? 0, true) . '）', $res['ok'] ? 'success' : 'error');
        redirect(url('admin/customers.php'));
    }

    if ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $svc = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE customer_id = ?', [$id]);
        if ($svc > 0) {
            flash('该客户仍有 ' . $svc . ' 个业务，建议改为冻结而非删除', 'error');
        } else {
            YxDb::delete('customer', 'id = :id', ['id' => $id]);
            log_action('customer.delete', 'customer#' . $id);
            flash('客户已删除');
        }
        redirect(url('admin/customers.php'));
    }
}

$keyword = trim((string)($_GET['kw'] ?? ''));
$statusF = (string)($_GET['status'] ?? '');
$where   = ' WHERE 1=1';
$bind    = [];
if ($keyword !== '') {
    $where .= ' AND (username LIKE ? OR email LIKE ? OR phone LIKE ? OR company LIKE ?';
    $kw = '%' . $keyword . '%';
    array_push($bind, $kw, $kw, $kw, $kw);
    if (ctype_digit($keyword)) {
        $where .= ' OR user_no = ?';
        $bind[] = (int)$keyword;
    }
    $where .= ')';
}
if ($statusF === '1' || $statusF === '0') {
    $where .= ' AND status = ?';
    $bind[] = (int)$statusF;
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . $where, $bind);
$page  = paginate($total, 15);
$rows  = YxDb::all(
    'SELECT * FROM ' . YxDb::table('customer') . $where . ' ORDER BY id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$editId]) : null;

$pageTitle  = '客户管理';
$activeMenu = 'customers';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑客户：' . e($editing['username']) : '新增客户' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>登录账号 <input type="text" name="username" required value="<?= e($editing['username'] ?? '') ?>"></label>
                <label>邮箱 <input type="email" name="email" required value="<?= e($editing['email'] ?? '') ?>"></label>
                <label>手机号 <input type="text" name="phone" value="<?= e($editing['phone'] ?? '') ?>"></label>
                <label>公司名称 <input type="text" name="company" value="<?= e($editing['company'] ?? '') ?>"></label>
                <label>客户等级
                    <select name="level">
                        <?php foreach ($LEVELS as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['level'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>所属分站
                    <select name="subsite_id">
                        <option value="0">平台直客（无归属）</option>
                        <?php foreach ($subsiteOptions as $sid => $sname): ?>
                            <option value="<?= (int)$sid ?>" <?= (int)($editing['subsite_id'] ?? 0) === (int)$sid ? 'selected' : '' ?>><?= e($sname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>正常</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>冻结</option>
                    </select>
                </label>
                <?php if (!$editing): ?>
                    <label>初始余额 <input type="number" step="0.01" name="balance" value="0.00"></label>
                <?php endif; ?>
                <label>密码 <?= $editing ? '（留空不修改）' : '' ?>
                    <input type="password" name="password" autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
                </label>
            </div>
            <label>备注 <textarea name="remark"><?= e($editing['remark'] ?? '') ?></textarea></label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建客户' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/customers.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>余额调整</h2>
        <p class="muted small">调整会写入收支流水，可追溯操作人。减少余额时若余额不足会失败。</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust">
            <div class="grid-2">
                <label>客户
                    <select name="id" required>
                        <option value="">请选择客户</option>
                        <?php foreach ($rows as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= e($c['username']) ?><?= (int)($c['user_no'] ?? 0) > 0 ? '（ID ' . (int)$c['user_no'] . '）' : '' ?> · 余额 <?= e(money($c['balance'], true)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>方向
                    <select name="direction">
                        <option value="income">增加余额（入账）</option>
                        <option value="expense">减少余额（出账）</option>
                    </select>
                </label>
                <label>金额 <input type="number" step="0.01" min="0.01" name="amount" required></label>
                <label>备注 <input type="text" name="remark" placeholder="如：活动赠送"></label>
            </div>
            <button class="btn btn-primary" type="submit">提交调整</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="section-head">
        <h2>客户列表（共 <?= $total ?> 位）</h2>
        <form method="get" class="inline-form">
            <label>关键词 <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="账号 / 邮箱 / 手机"></label>
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <option value="1" <?= $statusF === '1' ? 'selected' : '' ?>>正常</option>
                    <option value="0" <?= $statusF === '0' ? 'selected' : '' ?>>冻结</option>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>用户ID</th><th>账号</th><th>邮箱</th><th>等级</th><th>所属分站</th><th>余额</th><th>业务数</th><th>注册时间</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr>
                <td><?= (int)($c['user_no'] ?? 0) > 0 ? (int)$c['user_no'] : '<span class="muted">--</span>' ?></td>
                <td><?= e($c['username']) ?><?= $c['company'] ? '<br><span class="muted small">' . e($c['company']) . '</span>' : '' ?></td>
                <td class="small"><?= e($c['email']) ?></td>
                <td><?= e($LEVELS[$c['level']] ?? $c['level']) ?></td>
                <td class="small"><?= (int)($c['subsite_id'] ?? 0) > 0 ? e($subsiteOptions[(int)$c['subsite_id']] ?? ('#' . (int)$c['subsite_id'])) : '<span class="muted">平台直客</span>' ?></td>
                <td><?= e(money($c['balance'], true)) ?></td>
                <td><?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE customer_id = ?', [(int)$c['id']]) ?></td>
                <td class="small muted"><?= e($c['created_at']) ?></td>
                <td><?= (int)$c['status'] === 1 ? '<span class="badge badge-ok">正常</span>' : '<span class="badge badge-bad">冻结</span>' ?></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/customers.php?edit=' . (int)$c['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确认删除该客户？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">暂无客户</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
