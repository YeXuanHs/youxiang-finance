<?php
/**
 * 优享财务系统 · 用户后台登录（/panel/）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if (YxAuth::customer()) {
    redirect(url('panel/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = YxAuth::attemptCustomer((string)($_POST['account'] ?? ''), (string)($_POST['password'] ?? ''));
    if (!empty($r['ok'])) {
        redirect(url('panel/index.php'));
    }
    $error = $r['msg'];
}

$pageTitle = '用户后台登录';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="auth-page">
    <form class="auth-card" method="post">
        <h1>用户后台登录</h1>
        <p class="sub">业务、订单、账单、工单与服务中心统一入口</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label>账号（用户名 / 邮箱 / 手机号） <input type="text" name="account" required autocomplete="username"></label>
        <label>登录密码 <input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary btn-block" type="submit">登 录</button>
        <div class="auth-foot">还没有账号？<a href="<?= e(url('user/register.php')) ?>">免费注册</a></div>
    </form>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
