<?php
/**
 * 优享财务系统 · 门户布局头部（营销版式）
 *
 * 可选变量：
 *   $pageTitle  页面标题
 *   $pageFull   为 true 时不输出 .portal-wrap 容器（首页等全宽页自行管理 .portal-container）
 *   $pageHome   为 true 时额外加载首页专属样式 assets/css/home.css
 */
require_once __DIR__ . '/nav.php';

$pageTitle = $pageTitle ?? setting('site_name', config('app.name'));
$licenseBadgePublic = YxLicense::badge();
$meCust = YxAuth::customer();
$portalFull = !empty($pageFull);
$topNotice = trim((string)setting('notice', ''));
$siteName = setting('site_name', config('app.name'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e(setting('site_sub', '')) ?>">
<meta name="shenma-site-verification" content="07359afd6179b13bbec64c9306123e92_1793962492">
<link rel="icon" href="<?= e(url('assets/logo/favicon.png')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/logo/favicon.png') ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<?php // 首页专属版式（lc- 前缀），仅首页加载，避免影响其他页面 ?>
<?php if (!empty($pageHome) && is_file(__DIR__ . '/../assets/css/home.css')): ?>
<link rel="stylesheet" href="<?= e(url('assets/css/home.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/home.css') ?>">
<?php endif; ?>
<script src="<?= e(url('assets/js/portal.js')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/portal.js') ?>" defer></script>
</head>
<body class="portal-body">

<div class="zd-topbar">
    <div class="portal-container">
        <span class="zd-topbar-note"><?= $topNotice !== '' ? e($topNotice) : ('欢迎访问 ' . e($siteName) . '，' . e(setting('site_sub', ''))) ?></span>
        <div class="zd-topbar-links">
            <a href="<?= e(url('report.php')) ?>">工单举报</a>
            <a href="<?= e(url('infringe.php')) ?>">版权侵权</a>
            <?php if ($meCust): ?><a href="<?= e(url('panel/index.php')) ?>">用户后台</a><?php endif; ?>
        </div>
    </div>
</div>

<header class="zd-header">
    <div class="portal-container">
        <a class="zd-logo" href="<?= e(url('index.php')) ?>">
            <img src="<?= e(yx_logo_url()) ?>" alt="" onerror="this.style.display='none'">
            <span>
                <strong><?= e($siteName) ?></strong>
                <small><?= e(setting('site_sub', '')) ?></small>
            </span>
        </a>
        <nav class="zd-nav" id="zdNav">
            <div class="zd-nav-close">导航菜单<button type="button" id="zdNavClose" aria-label="关闭菜单">✕</button></div>
            <a href="<?= e(url('index.php')) ?>">首页</a>
            <a href="<?= e(url('index.php#products')) ?>">产品服务</a>
            <?php if (YxAd::activeSlots('goods')): ?><a href="<?= e(url('goods.php')) ?>">版本授权</a><?php endif; ?>
            <a href="<?= e(url('store/index.php')) ?>">应用商店</a>
            <a href="<?= e(url('service/index.php')) ?>">服务中心</a>
            <a href="<?= e(url('index.php#license')) ?>">授权查询</a>
            <?php if (YxNews::isEnabled()): ?><a href="<?= e(url('news.php')) ?>">新闻公告</a><?php endif; ?>
            <?php if (YxCareer::jobs()): ?><a href="<?= e(url('career.php')) ?>">加入我们</a><?php endif; ?>
            <?php if (YxAbout::hasContent()): ?><a href="<?= e(url('about.php')) ?>">关于我们</a><?php endif; ?>
        </nav>
        <div class="zd-header-actions">
            <a class="license-pill <?= e($licenseBadgePublic['class']) ?>" href="<?= e(url('index.php#license')) ?>">
                <b><?= e($licenseBadgePublic['icon']) ?></b> <?= e($licenseBadgePublic['text']) ?>
            </a>
            <?php if ($meCust): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('user/index.php')) ?>">用户中心</a>
                <a class="btn btn-primary btn-sm" href="<?= e(url('panel/index.php')) ?>">用户后台</a>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('user/login.php')) ?>">登录</a>
                <a class="btn btn-primary btn-sm" href="<?= e(url('user/register.php')) ?>">免费注册</a>
            <?php endif; ?>
            <button type="button" class="zd-menu-btn" id="zdMenuBtn" aria-label="打开菜单" aria-expanded="false" aria-controls="zdNav">☰</button>
        </div>
    </div>
    <div class="zd-nav-mask" id="zdNavMask"></div>
</header>
<?php if (!$portalFull): ?>
<div class="portal-wrap">
<?php endif; ?>
