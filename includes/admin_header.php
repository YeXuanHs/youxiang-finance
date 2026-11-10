<?php
/**
 * 优享财务系统 · 管理端布局
 * 使用前需已定义 $admin（当前管理员）与可选 $activeMenu / $pageTitle
 */

require_once __DIR__ . '/nav.php';

$admin       = $admin ?? YxAuth::requireAdmin();
$activeMenu  = $activeMenu ?? '';
$pageTitle   = $pageTitle ?? '仪表盘';
$licenseBadge = YxLicense::badge();
$currentEdition = YxEdition::name();
$flash       = flash();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e(setting('site_name', config('app.name'))) ?> 管理后台</title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="admin-body">
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="<?= e(yx_logo_url()) ?>" alt="logo" onerror="this.style.display='none'">
        <div>
            <strong><?= e(setting('site_name', config('app.name'))) ?></strong>
            <span>管理后台 v<?= e(config('app.version')) ?></span>
        </div>
    </div>
    <nav class="sidebar-menu"><?= yx_render_menu(yx_admin_menu(), $activeMenu, url('')) ?></nav>
    <div class="sidebar-foot">
        <span class="badge badge-edition" style="--c:<?= e(YxEdition::info()['color']) ?>"><?= e($currentEdition) ?></span>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <h1><?= e($pageTitle) ?></h1>
            <span class="muted small"><?= e(current_domain()) ?></span>
        </div>
        <div class="topbar-right">
            <a class="license-pill <?= e($licenseBadge['class']) ?>" href="<?= e(url('admin/licenses.php')) ?>"
               title="<?= e($licenseBadge['detail']['message']) ?>">
                <b><?= $licenseBadge['icon'] ?></b> <?= e($licenseBadge['text']) ?>
            </a>
            <div class="user-chip">
                <span class="avatar"><?= e(mb_substr($admin['nickname'] ?: $admin['username'], 0, 1)) ?></span>
                <div>
                    <strong><?= e($admin['nickname'] ?: $admin['username']) ?></strong>
                    <a href="<?= e(url('admin/logout.php')) ?>">退出登录</a>
                </div>
            </div>
        </div>
    </header>

    <?php if (in_array($licenseBadge['class'], ['bad', 'warn'], true)): ?>
        <div class="alert alert-warn license-banner">
            <strong><?= e($licenseBadge['text']) ?></strong>
            <span>当前域名 <code><?= e(current_domain()) ?></code> 未通过官方授权校验（来源：<?= e($licenseBadge['detail']['source']) ?>）。
            请前往 <a href="<?= e(url('admin/licenses.php')) ?>">授权中心</a> 提交域名授权，或核对授权服务器配置。</span>
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warn' ? 'warn' : 'ok')) ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="content">
