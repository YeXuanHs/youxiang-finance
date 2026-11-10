<?php
/**
 * 优享财务系统 · 代理登录
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if (YxAuth::agent()) {
    redirect(url('agent/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = YxAuth::attemptAgent((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if (!empty($r['ok'])) {
        redirect(url('agent/index.php'));
    }
    $error = $r['msg'];
}

$pageTitle = '代理登录';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="auth-page">
    <form class="auth-card" method="post">
        <h1>代理中心登录</h1>
        <p class="sub">渠道 / 代理自助下单与管理</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label>代理账号 <input type="text" name="username" required autocomplete="username"></label>
        <label>登录密码 <input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary btn-block" type="submit">登 录</button>
        <div class="auth-foot">账号由管理员在「渠道 / 代理」中创建</div>
    </form>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
