<?php
/**
 * 优享财务系统 · 资料设置
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'profile') {
        $email = trim((string)($_POST['email'] ?? ''));
        $rawPhone = trim((string)($_POST['phone'] ?? ''));
        $phone = $rawPhone === '' ? '' : YxSms::normalizePhone($rawPhone);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('邮箱格式不正确', 'error');
            redirect(url('user/profile.php'));
        }
        if ($rawPhone !== '' && $phone === '') {
            flash('手机号格式不正确，请填写 11 位手机号或留空', 'error');
            redirect(url('user/profile.php'));
        }
        $dup = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE email = ? AND id <> ?', [$email, $cid]);
        if ($dup) {
            flash('该邮箱已被其他账号使用', 'error');
            redirect(url('user/profile.php'));
        }
        if ($phone !== '' && YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE phone = ? AND id <> ?', [$phone, $cid])) {
            flash('该手机号已被其他账号绑定', 'error');
            redirect(url('user/profile.php'));
        }
        YxDb::update('customer', [
            'email' => $email,
            'phone' => $phone,
        ], 'id = :id', ['id' => $cid]);
        flash('资料已保存');
        redirect(url('user/profile.php'));
    }

    if ($action === 'password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $rep = (string)($_POST['new_password2'] ?? '');
        if (!password_verify($old, $customer['password'])) {
            flash('原密码不正确', 'error');
        } elseif (strlen($new) < 8) {
            flash('新密码至少 8 位', 'error');
        } elseif ($new !== $rep) {
            flash('两次输入的新密码不一致', 'error');
        } else {
            YxDb::update('customer', ['password' => YxAuth::hash($new)], 'id = :id', ['id' => $cid]);
            log_action('customer.change_password', $customer['username']);
            flash('登录密码已更新');
        }
        redirect(url('user/profile.php'));
    }
}

$transactions = YxDb::all('SELECT * FROM ' . YxDb::table('transaction') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 10', [$cid]);

$userTitle  = '资料设置';
$userActive = 'profile';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="grid-2">
    <div class="card">
        <h2>基本资料</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <label>用户ID <input type="text" value="<?= (int)($customer['user_no'] ?? 0) > 0 ? (int)$customer['user_no'] : '--' ?>" disabled></label>
            <label>登录账号 <input type="text" value="<?= e($customer['username']) ?>" disabled></label>
            <label>邮箱 <input type="email" name="email" value="<?= e($customer['email']) ?>" required></label>
            <label>手机号 <input type="text" name="phone" value="<?= e($customer['phone']) ?>"></label>
            <label>实名状态 <input type="text" value="<?= e(yx_realname_status_text($customer)) ?>" disabled></label>
            <label>真实姓名 <input type="text" value="<?= e($customer['realname']) ?>" disabled placeholder="请通过实名认证完成"></label>
            <label>公司名称 <input type="text" value="<?= e($customer['company']) ?>" disabled placeholder="企业认证后自动写入"></label>
            <p class="small muted">真实姓名与公司名称只能通过<a href="<?= e(url('user/realname.php')) ?>">实名认证</a>（扫码人脸识别）变更，无法在此直接修改。</p>
            <button class="btn btn-primary" type="submit">保存资料</button>
        </form>
    </div>

    <div class="card">
        <h2>修改密码</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <label>原密码 <input type="password" name="old_password" required autocomplete="current-password"></label>
            <label>新密码（≥8 位） <input type="password" name="new_password" required autocomplete="new-password"></label>
            <label>确认新密码 <input type="password" name="new_password2" required autocomplete="new-password"></label>
            <button class="btn btn-primary" type="submit">更新密码</button>
        </form>

        <h2 class="mt-6">账户信息</h2>
        <table class="data">
            <tr><th>客户 ID</th><td><?= $cid ?></td></tr>
            <tr><th>当前余额</th><td><?= e(money($customer['balance'], true)) ?></td></tr>
            <tr><th>注册时间</th><td><?= e($customer['created_at']) ?></td></tr>
            <tr><th>最近登录</th><td><?= e($customer['last_login_at'] ?: '-') ?></td></tr>
        </table>
    </div>
</div>

<div class="card">
    <h2>最近资金变动</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>时间</th><th>方向</th><th>分类</th><th>金额</th><th>余额</th><th>备注</th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $t): ?>
            <tr>
                <td class="small muted"><?= e($t['created_at']) ?></td>
                <td><span class="badge <?= $t['direction'] === 'income' ? 'badge-ok' : 'badge-bad' ?>"><?= $t['direction'] === 'income' ? '收入' : '支出' ?></span></td>
                <td><?= e($t['category']) ?></td>
                <td><?= e(money($t['amount'], true)) ?></td>
                <td><?= e(money($t['balance_after'], true)) ?></td>
                <td class="wrap muted small"><?= e($t['remark'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$transactions): ?><tr><td colspan="6" class="empty">暂无记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
