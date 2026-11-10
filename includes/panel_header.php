<?php
/**
 * 优享财务系统 · 用户管理后台（/panel/）布局
 * 使用前需已定义 $customer（当前客户）与可选 $panelTitle / $panelActive
 */
$customer    = $customer ?? YxAuth::requireCustomer();
$panelTitle  = $panelTitle ?? '概览';
$panelActive = $panelActive ?? 'overview';
$panelFlash  = flash();
$panelMenu = [
    'overview' => ['概览', '◎'],
    'services' => ['我的业务', '☰'],
    'orders'   => ['我的订单', '▤'],
    'invoices' => ['我的账单', '▧'],
    'recharge' => ['余额充值', '＋'],
    'tickets'  => ['工单支持', '✉'],
    'checkin'  => ['每日签到', '✓'],
    'agent'    => ['代理自助开通', '⇄'],
    'edition'  => ['授权版本购买', '★'],
    'support'  => ['服务中心', '☎'],
    'vuln'     => ['漏洞提交', '⚠'],
    'profile'  => ['资料设置', '⚙'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($panelTitle) ?> - 用户后台 · <?= e(setting('site_name', config('app.name'))) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="admin-body user-body">
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="<?= e(yx_logo_url()) ?>" alt="" onerror="this.style.display='none'">
        <div>
            <strong>用户后台</strong>
            <span>v<?= e(config('app.version')) ?></span>
        </div>
    </div>
    <div class="wallet-card">
        <span>账户余额</span>
        <strong><?= e(money($customer['balance'], true)) ?></strong>
        <a href="<?= e(url('panel/index.php?m=recharge')) ?>">立即充值</a>
    </div>
    <nav class="sidebar-menu">
        <?php foreach ($panelMenu as $k => $mi): ?>
            <a class="menu-item<?= $panelActive === $k ? ' active' : '' ?>" href="<?= e(url('panel/index.php?m=' . $k)) ?>"><i><?= e($mi[1]) ?></i><?= e($mi[0]) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><span class="badge badge-edition"><?= e(YxEdition::name()) ?></span></div>
</aside>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <h1><?= e($panelTitle) ?></h1>
            <span class="muted small"><?= e($customer['username']) ?></span>
        </div>
        <div class="topbar-right">
            <a class="btn btn-ghost" href="<?= e(url('index.php')) ?>">返回门户</a>
            <div class="user-chip">
                <span class="avatar"><?= e(mb_substr($customer['username'], 0, 1)) ?></span>
                <div>
                    <strong><?= e($customer['username']) ?></strong>
                    <a href="<?= e(url('panel/logout.php')) ?>">退出登录</a>
                </div>
            </div>
        </div>
    </header>

    <?php if ($panelFlash): ?>
        <div class="alert alert-<?= e($panelFlash['type'] === 'error' ? 'error' : ($panelFlash['type'] === 'warn' ? 'warn' : 'ok')) ?>"><?= e($panelFlash['msg']) ?></div>
    <?php endif; ?>

    <div class="content">
