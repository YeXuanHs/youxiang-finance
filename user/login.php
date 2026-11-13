<?php
/**
 * 优享财务系统 · 客户端登录
 *
 * 版式仿 007idc 会员中心：左侧品牌区 + 右侧表单区。
 * 提交逻辑、跳转校验与 CSRF 保持不变，仅调整结构与样式。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if (YxAuth::customer()) {
    redirect(url('user/index.php'));
}

$back  = (string)($_GET['back'] ?? ($_POST['back'] ?? url('user/index.php')));
// 仅允许站内相对地址，避免开放重定向
if (preg_match('#^https?://#i', $back) || strpos($back, '//') === 0) {
    $back = url('user/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = YxAuth::attemptCustomer((string)($_POST['account'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($res['ok']) {
        redirect($back);
    }
    $error = $res['msg'];
}

// 登录方式：手机登录 / 邮箱登录（默认手机，切换只改变输入框类型）
$mode = yx_auth_mode($_POST['mode'] ?? ($_GET['mode'] ?? 'phone'));
$byPhone = $mode === 'phone';

$pageTitle = '用户登录';
$siteName  = setting('site_name', config('app.name'));
$siteSub   = setting('site_sub', '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e($siteName) ?></title>
<link rel="icon" href="<?= e(url('assets/logo/favicon.png')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/logo/favicon.png') ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/auth.css') ?>">
</head>
<body class="yx-auth-body">
<div class="yx-auth">

    <aside class="yx-auth-hero">
        <span class="yx-auth-art" aria-hidden="true">
            <i class="art-orb"></i>
            <i class="art-ring"></i>
            <i class="art-line-1"></i>
            <i class="art-line-2"></i>
        </span>
        <div class="yx-auth-hero-inner">
            <a class="yx-auth-brand" href="<?= e(url('index.php')) ?>">
                <img src="<?= e(yx_logo_url()) ?>" alt="<?= e($siteName) ?>" onerror="this.style.display='none'">
                <span><?= e($siteName) ?></span>
            </a>

            <div class="yx-auth-hero-body">
                <h1>让业务与财务<br>在同一套系统里闭环</h1>
                <p>客户、产品、订单、账单与收支记账集中在一处管理，交付、续费与对账不必再跨系统搬运，业务规模变化时账目依然清晰可查。</p>
                <div class="yx-auth-actions">
                    <a class="yx-auth-action is-solid" href="<?= e(url('index.php')) ?>">返回首页</a>
                    <a class="yx-auth-action is-ghost" href="<?= e(url('index.php#products')) ?>">了解产品</a>
                </div>
            </div>

            <ul class="yx-auth-points">
                <li>业务实例与订单账单同屏</li>
                <li>余额充值与在线支付实时到账</li>
                <li>工单与在线客服快速响应</li>
            </ul>
        </div>
    </aside>

    <main class="yx-auth-panel">
        <div class="yx-auth-box">

            <div class="yx-auth-logo">
                <a href="<?= e(url('index.php')) ?>">
                    <img src="<?= e(yx_logo_url()) ?>" alt="<?= e($siteName) ?>" onerror="this.style.display='none'">
                </a>
            </div>

            <div class="yx-auth-head">
                <em><?= e($siteName) ?></em>
                <h1>登录</h1>
                <p><?= $siteSub !== '' ? e($siteSub) : '登录后可管理业务、账单与账户安全设置' ?></p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e($back) ?>">
                <input type="hidden" name="mode" value="<?= e($mode) ?>">

                <?php /*
                 * 登录方式放在表单顶部，只负责切换 mode（并保留 back 回跳），
                 * 真正的提交统一由条款下方的「登 录」按钮完成。
                 */ ?>
                <div class="yx-auth-modes" role="group" aria-label="登录方式">
                    <?php if ($byPhone): ?>
                        <a class="yx-auth-mode is-active" href="<?= e(url('user/login.php?mode=phone&back=' . urlencode($back))) ?>" aria-current="true">手机登录</a>
                        <a class="yx-auth-mode" href="<?= e(url('user/login.php?mode=email&back=' . urlencode($back))) ?>">邮箱登录</a>
                    <?php else: ?>
                        <a class="yx-auth-mode" href="<?= e(url('user/login.php?mode=phone&back=' . urlencode($back))) ?>">手机登录</a>
                        <a class="yx-auth-mode is-active" href="<?= e(url('user/login.php?mode=email&back=' . urlencode($back))) ?>" aria-current="true">邮箱登录</a>
                    <?php endif; ?>
                </div>

                <div class="yx-auth-field">
                    <label for="loginAccount"><?= $byPhone ? '手机号' : '邮箱地址' ?></label>
                    <input type="<?= $byPhone ? 'tel' : 'email' ?>" id="loginAccount" name="account" required autofocus
                           autocomplete="username" placeholder="<?= $byPhone ? '请输入注册时的手机号' : '请输入注册时的邮箱地址' ?>">
                </div>

                <div class="yx-auth-field">
                    <label for="loginPassword">登录密码</label>
                    <input type="password" id="loginPassword" name="password" required
                           autocomplete="current-password" placeholder="请输入登录密码">
                </div>

                <?php /* 同意声明：登录即代表已阅读并同意两份法律文档，下方紧跟主提交按钮 */ ?>
                <div class="yx-auth-legal yx-auth-legal--static">
                    <span class="yx-auth-legal-txt">登录即表示已阅读并同意<a href="<?= e(YxLegalDoc::url(YxLegalDoc::TERMS)) ?>" target="_blank" rel="noopener">《服务条款》</a>与<a href="<?= e(YxLegalDoc::url(YxLegalDoc::PRIVACY)) ?>" target="_blank" rel="noopener">《隐私政策》</a></span>
                </div>

                <button class="yx-auth-submit" type="submit">登 录</button>

                <div class="yx-auth-foot">
                    <a href="<?= e(url('index.php')) ?>">← 返回首页</a>
                    <a href="<?= e(url('user/tickets.php')) ?>">联系客服</a>
                </div>
            </form>

            <p class="yx-auth-switch">还没有账户？<a href="<?= e(url('user/register.php?mode=phone')) ?>">手机注册</a><span class="yx-auth-switch-sep">·</span><a href="<?= e(url('user/register.php?mode=email')) ?>">邮箱注册</a></p>
        </div>
    </main>
</div>
</body>
</html>
