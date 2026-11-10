<?php
/**
 * 优享财务系统 · 分站后台布局
 * 使用前需已定义 $subsite（当前分站）
 */
$subsite       = $subsite ?? YxAuth::requireSubsite();
$subsiteTitle  = $subsiteTitle ?? '分站概览';
$subsiteActive = $subsiteActive ?? 'overview';
$subsiteFlash  = flash();
$subsiteMenus  = [
    'overview'    => ['概览', 'subsite/index.php'],
    'children'    => ['下级分站', 'subsite/index.php?m=children'],
    'orders'      => ['订单记录', 'subsite/index.php?m=orders'],
    'customers'   => ['我的客户', 'subsite/index.php?m=customers'],
    'commissions' => ['佣金明细', 'subsite/index.php?m=commissions'],
    'reports'     => ['举报工单', 'subsite/index.php?m=reports'],
    'settings'    => ['分站设置', 'subsite/index.php?m=settings'],
];
$subsiteExpire = (string)($subsite['expire_at'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($subsiteTitle) ?> - 分站后台 · <?= e(setting('site_name', config('app.name'))) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="admin-body user-body">
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="<?= e(yx_logo_url()) ?>" alt="" onerror="this.style.display='none'">
        <div>
            <strong>分站后台</strong>
            <span>v<?= e(config('app.version')) ?></span>
        </div>
    </div>
    <div class="wallet-card">
        <span>分站佣金余额</span>
        <strong><?= e(money($subsite['balance'], true)) ?></strong>
        <small class="muted">有效期至 <?= e($subsiteExpire !== '' ? $subsiteExpire : '未开通') ?></small>
    </div>
    <nav class="sidebar-menu">
        <?php foreach ($subsiteMenus as $key => $menu): ?>
            <a class="menu-item<?= $subsiteActive === $key ? ' active' : '' ?>" href="<?= e(url($menu[1])) ?>"><i>•</i><?= e($menu[0]) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <span class="badge badge-edition">分站层级 L<?= (int)$subsite['level'] ?></span>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <h1><?= e($subsiteTitle) ?></h1>
            <span class="muted small"><?= e($subsite['name']) ?>（<?= e($subsite['username']) ?>）</span>
        </div>
        <div class="topbar-right">
            <a class="btn btn-ghost" href="<?= e(url('index.php')) ?>">返回门户</a>
            <div class="user-chip">
                <span class="avatar"><?= e(mb_substr((string)$subsite['name'], 0, 1)) ?></span>
                <div>
                    <strong><?= e($subsite['name']) ?></strong>
                    <a href="<?= e(url('subsite/logout.php')) ?>">退出登录</a>
                </div>
            </div>
        </div>
    </header>

    <?php if ($subsiteFlash): ?>
        <div class="alert alert-<?= e($subsiteFlash['type'] === 'error' ? 'error' : ($subsiteFlash['type'] === 'warn' ? 'warn' : 'ok')) ?>"><?= e($subsiteFlash['msg']) ?></div>
    <?php endif; ?>

    <div class="content">
