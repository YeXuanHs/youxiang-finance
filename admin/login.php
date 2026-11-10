<?php
/**
 * 优享财务系统 · 管理端登录
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if (YxAuth::isAdmin()) {
    redirect(url('admin/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = YxAuth::attemptAdmin((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($res['ok']) {
        log_action('admin.login', $_POST['username'] ?? '');
        redirect(url('admin/index.php'));
    }
    $error = $res['msg'];
    log_action('admin.login_failed', (string)($_POST['username'] ?? ''));
}

$badge = YxLicense::badge();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理后台登录 - <?= e(setting('site_name', config('app.name'))) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="auth-body">
<form class="auth-card" method="post">
    <h1><?= e(setting('site_name', config('app.name'))) ?></h1>
    <p class="sub">业务与财务一体化管理平台 · 管理后台</p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" style="margin:0 0 14px"><?= e($error) ?></div>
    <?php endif; ?>

    <?= csrf_field() ?>
    <label>管理员账号
        <input type="text" name="username" autocomplete="username" required autofocus>
    </label>
    <label>登录密码
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn btn-primary btn-block">登 录</button>

    <div class="auth-foot">
        <span class="license-pill <?= e($badge['class']) ?>" style="margin-top:12px">
            <b><?= $badge['icon'] ?></b> <?= e($badge['text']) ?>
        </span>
        <div style="margin-top:8px"><?= e(current_domain()) ?></div>
    </div>
</form>
</body>
</html>
