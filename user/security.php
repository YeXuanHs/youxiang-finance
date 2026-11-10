<?php
/**
 * 优享财务系统 · 安全设置
 *
 * 与「资料设置」的职责划分：
 *   资料设置 —— 邮箱 / 手机号等联系方式
 *   安全设置 —— 登录密码、会话状态、账户安全体检与最近安全事件
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $rep = (string)($_POST['new_password2'] ?? '');
        if (!password_verify($old, $customer['password'])) {
            flash('原密码不正确', 'error');
        } elseif (strlen($new) < 8) {
            flash('新密码至少 8 位', 'error');
        } elseif (!preg_match('/[a-zA-Z]/', $new) || !preg_match('/\d/', $new)) {
            flash('新密码需同时包含字母与数字', 'error');
        } elseif ($new !== $rep) {
            flash('两次输入的新密码不一致', 'error');
        } elseif ($new === $old) {
            flash('新密码不能与原密码相同', 'error');
        } else {
            YxDb::update('customer', ['password' => YxAuth::hash($new)], 'id = :id', ['id' => $cid]);
            log_action('customer.change_password', $customer['username']);
            flash('登录密码已更新，请牢记新密码');
        }
        redirect(url('user/security.php'));
    }

    // 重置会话：销毁当前会话并让用户重新登录，避免旧会话被继续使用
    if ($action === 'revoke_others') {
        log_action('customer.session_reset', $customer['username']);
        YxAuth::logoutCustomer();
        flash('会话已重置，请重新登录');
        redirect(url('user/login.php'));
    }
}

// 安全体检项：每项给出「是否达标 + 说明 + 引导动作」
$checks = [];
$hasPwd = (string)($customer['password'] ?? '') !== '';
$checks[] = [
    'ok'    => $hasPwd,
    'title' => '登录密码',
    'desc'  => $hasPwd ? '已设置登录密码' : '尚未设置登录密码，请立即设置',
    'act'   => ['#yxPwdForm', '设置密码'],
];
$hasPhone = trim((string)($customer['phone'] ?? '')) !== '';
$checks[] = [
    'ok'    => $hasPhone,
    'title' => '手机绑定',
    'desc'  => $hasPhone ? '已绑定 ' . YxSms::maskPhone((string)$customer['phone']) : '未绑定手机号，无法接收安全提醒',
    'act'   => [url('user/binding.php'), '去绑定'],
];
$hasEmail = trim((string)($customer['email'] ?? '')) !== '';
$checks[] = [
    'ok'    => $hasEmail,
    'title' => '邮箱绑定',
    'desc'  => $hasEmail ? '已绑定 ' . YxMail::maskEmail((string)$customer['email']) : '未绑定邮箱，找回密码将受影响',
    'act'   => [url('user/binding.php'), '去绑定'],
];
$rnOk = (string)($customer['realname_status'] ?? 'none') === 'verified';
$checks[] = [
    'ok'    => $rnOk,
    'title' => '实名认证',
    'desc'  => $rnOk ? '已完成实名认证' : '未完成实名认证，部分业务将受限',
    'act'   => [url('user/realname.php'), $rnOk ? '查看' : '去认证'],
];
$accountOk = (int)($customer['status'] ?? 1) === 1;
$checks[] = [
    'ok'    => $accountOk,
    'title' => '账户状态',
    'desc'  => $accountOk ? '正常' : '账户已被限制，请联系客服',
    'act'   => [url('user/tickets.php'), '联系客服'],
];

$passCount = 0;
foreach ($checks as $c) {
    if (!empty($c['ok'])) {
        $passCount++;
    }
}
$score = (int)round($passCount / max(1, count($checks)) * 100);

// 最近安全事件：取当前客户相关的审计记录（改密 / 登录 / 绑定等）
$events = YxDb::all(
    'SELECT action, target, ip, created_at FROM ' . YxDb::table('admin_log')
    . " WHERE target = ? AND (action LIKE 'customer.%' OR action LIKE 'admin.customer%')"
    . ' ORDER BY id DESC LIMIT 15',
    [(string)$customer['username']]
);

$userTitle  = '安全设置';
$userActive = 'security';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="grid-3">
    <div class="stat accent"><span>安全评分</span><strong><?= $score ?> 分</strong></div>
    <div class="stat"><span>已完成检查</span><strong><?= $passCount ?> / <?= count($checks) ?></strong></div>
    <div class="stat"><span>最近登录</span><strong class="small"><?= e($customer['last_login_at'] ?: '-') ?></strong></div>
</div>

<div class="card mt-4">
    <div class="section-head"><h2>安全体检</h2></div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>检查项</th><th>状态</th><th>说明</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
            <tr>
                <td><?= e($c['title']) ?></td>
                <td><span class="badge <?= $c['ok'] ? 'badge-ok' : 'badge-warn' ?>"><?= $c['ok'] ? '已达标' : '待完善' ?></span></td>
                <td class="wrap small muted"><?= e($c['desc']) ?></td>
                <td><a class="btn btn-ghost btn-sm" href="<?= e($c['act'][0]) ?>"><?= e($c['act'][1]) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="grid-2 mt-4">
    <div class="card" id="yxPwdForm">
        <h2>修改登录密码</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <label>原密码 <input type="password" name="old_password" required autocomplete="current-password"></label>
            <label>新密码（≥8 位，含字母与数字） <input type="password" name="new_password" required autocomplete="new-password"></label>
            <label>确认新密码 <input type="password" name="new_password2" required autocomplete="new-password"></label>
            <button class="btn btn-primary" type="submit">更新密码</button>
        </form>
    </div>

    <div class="card">
        <h2>登录与设备</h2>
        <table class="data">
            <tr><th>最近登录时间</th><td><?= e($customer['last_login_at'] ?: '-') ?></td></tr>
            <tr><th>注册时间</th><td><?= e($customer['created_at'] ?: '-') ?></td></tr>
            <tr><th>当前会话</th><td><span class="badge badge-ok">在线</span></td></tr>
        </table>
        <form method="post" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke_others">
            <button class="btn btn-ghost" type="submit">重置当前会话</button>
        </form>
        <p class="small muted">发现异常登录时，建议立即修改密码并重置会话。</p>
    </div>
</div>

<div class="card mt-4">
    <div class="section-head"><h2>最近安全事件</h2></div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>时间</th><th>事件</th><th>对象</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
            <tr>
                <td class="small muted"><?= e($ev['created_at']) ?></td>
                <td><?= e($ev['action']) ?></td>
                <td class="small"><?= e($ev['target'] ?: '-') ?></td>
                <td class="mono small"><?= e($ev['ip'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="4" class="empty">暂无安全事件记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
