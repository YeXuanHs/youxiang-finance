<?php
/**
 * 优享财务系统 · 用户中心布局头部（控制台式外壳）
 * 使用前需已定义 $customer（当前客户）
 *
 * 版式：左侧分组侧栏（账户 / 业务 / 应用与授权 / 支持）+ 顶部工具条 + 内容区，
 * 与 user/index.php 的 yx-* 仪表盘同一视觉语言。样式见 style.css 第 22 节。
 */
require_once __DIR__ . '/nav.php';

$customer   = $customer ?? YxAuth::requireCustomer();
$userActive = $userActive ?? '';
$userTitle  = $userTitle ?? '账户概览';
$userBadge  = YxLicense::badge();
$userFlash  = flash();

// 面包屑：由激活项反查所属一级功能与二级功能名称，未命中则回落到 $userTitle
$crumbParent = '';
$crumbTitle  = $userTitle;
$navHit      = $userActive !== '' ? yx_user_nav_find($userActive) : null;
if ($navHit) {
    $crumbParent = $navHit['parent'];
    $crumbTitle  = $navHit['label'];
}

// 顶栏一级功能下拉：当前访问地址用于判断归属（同一页面可能承载多个二级功能）
$navUri = (string)($_SERVER['REQUEST_URI'] ?? '');

// 侧栏未读工单数：仅在开启工单能力时统计
$navTicketCnt = 0;
if (YxEdition::can('ticket')) {
    $navTicketCnt = (int)YxDb::value(
        'SELECT COUNT(*) FROM ' . YxDb::table('ticket') . " WHERE customer_id = ? AND status IN ('open','replied')",
        [(int)$customer['id']]
    );
}

$siteName = setting('site_name', config('app.name'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($userTitle) ?> - 用户中心 · <?= e($siteName) ?></title>
<link rel="icon" href="<?= e(url('assets/logo/favicon.png')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/logo/favicon.png') ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/user.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/user.css') ?>">
</head>
<body class="admin-body user-body">
<aside class="sidebar" id="yxSidebar">
    <div class="sidebar-brand">
        <img src="<?= e(yx_logo_url()) ?>" alt="" onerror="this.style.display='none'">
        <div>
            <strong>用户中心</strong>
            <span><?= e($siteName) ?></span>
        </div>
    </div>

    <div class="wallet-card">
        <span>账户余额</span>
        <strong><?= e(money($customer['balance'], true)) ?></strong>
        <a href="<?= e(url('user/recharge.php')) ?>">立即充值</a>
    </div>

    <nav class="sidebar-menu"><?= yx_render_menu_groups(yx_user_menu_groups(), $userActive, url('')) ?></nav>

    <div class="sidebar-foot">
        <span class="badge badge-edition" style="--c:<?= e(YxEdition::info()['color']) ?>"><?= e(YxEdition::name()) ?></span>
    </div>
</aside>
<div class="sidebar-mask" id="yxSidebarMask"></div>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="menu-toggle" id="yxMenuToggle" aria-label="打开菜单">☰</button>
            <a class="yx-topbar-brand" href="<?= e(url('user/index.php')) ?>" title="<?= e($siteName) ?> 用户中心">
                <img src="<?= e(yx_logo_url()) ?>" alt="" onerror="this.style.display='none'">
                <span>用户中心</span>
            </a>
            <!-- 一级功能 + 下拉：账户概览 / 用户中心 / 我的订单 / 账户管理 / 我的账单 / 新闻中心 / 帮助中心 -->
            <nav class="yx-navbar" id="yxNavbar" aria-label="功能导航">
                <?= yx_render_user_nav_dropdown($userActive, url(''), $navUri) ?>
            </nav>
        </div>
        <div class="topbar-right">
            <a class="yx-topbar-bell<?= $navTicketCnt > 0 ? ' has-dot' : '' ?>" href="<?= e(url('user/tickets.php')) ?>" title="进行中的工单">
                <?= yx_icon('mail', 18) ?>
                <?php if ($navTicketCnt > 0): ?><em><?= $navTicketCnt > 99 ? '99+' : $navTicketCnt ?></em><?php endif; ?>
            </a>
            <a class="license-pill <?= e($userBadge['class']) ?>" href="<?= e(url('user/licenses.php')) ?>">
                <b><?= $userBadge['icon'] ?></b> <?= e($userBadge['text']) ?>
            </a>
            <div class="yx-userbox" id="yxUserbox">
                <button type="button" class="yx-userbox-btn" aria-expanded="false" aria-haspopup="true">
                    <span class="avatar"><?= e(mb_substr($customer['username'], 0, 1)) ?></span>
                    <span class="yx-userbox-name"><?= e($customer['username']) ?></span>
                    <i class="yx-nav-caret" aria-hidden="true"></i>
                </button>
                <div class="yx-userbox-menu">
                    <div class="yx-userbox-head">
                        <strong><?= e($customer['username']) ?></strong>
                        <span>用户 ID：<?= (int)($customer['user_no'] ?? 0) > 0 ? (int)$customer['user_no'] : (int)$customer['id'] ?></span>
                    </div>
                    <a href="<?= e(url('user/index.php')) ?>"><i>◉</i>账户概览</a>
                    <a href="<?= e(url('user/profile.php')) ?>"><i>⚙</i>资料设置</a>
                    <a href="<?= e(url('user/security.php')) ?>"><i>🛡</i>安全设置</a>
                    <a href="<?= e(url('user/binding.php')) ?>"><i>⇄</i>账号绑定</a>
                    <a href="<?= e(url('user/logs.php')) ?>"><i>≡</i>系统日志</a>
                    <a href="<?= e(url('index.php')) ?>"><i>⌂</i>返回门户</a>
                    <a class="yx-userbox-out" href="<?= e(url('user/logout.php')) ?>"><i>⏻</i>退出登录</a>
                </div>
            </div>
        </div>
    </header>
    <div class="yx-subbar">
        <nav class="yx-crumb" aria-label="面包屑">
            <a href="<?= e(url('user/index.php')) ?>">用户中心</a>
            <?php if ($crumbParent !== ''): ?>
                <i>/</i><span><?= e($crumbParent) ?></span>
            <?php endif; ?>
            <i>/</i><b><?= e($crumbTitle) ?></b>
        </nav>
        <?php if ($crumbParent !== ''): ?>
            <a class="yx-subbar-back" href="<?= e(url('user/index.php')) ?>">回到概览</a>
        <?php endif; ?>
    </div>

    <?php if ($userFlash): ?>
        <div class="alert alert-<?= e($userFlash['type'] === 'error' ? 'error' : ($userFlash['type'] === 'warn' ? 'warn' : 'ok')) ?>"><?= e($userFlash['msg']) ?></div>
    <?php endif; ?>

    <div class="content">
