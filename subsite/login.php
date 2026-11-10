<?php
/**
 * 优享财务系统 · 分站登录
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if (YxAuth::subsite()) {
    redirect(url('subsite/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = YxAuth::attemptSubsite((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if (!empty($r['ok'])) {
        redirect(url('subsite/index.php'));
    }
    $error = (string)$r['msg'];
}

$pageTitle = '分站登录';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="auth-page">
    <form class="auth-card" method="post">
        <h1>分站后台登录</h1>
        <p class="sub">发展下级分站、管理订单与佣金</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label>分站账号 <input type="text" name="username" required autocomplete="username"></label>
        <label>登录密码 <input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary btn-block" type="submit">登 录</button>
        <div class="auth-foot">账号由上级分站或管理员开通，开通并支付后生效</div>
    </form>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
