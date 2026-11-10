<?php
/**
 * 优享财务系统 · 代理中心布局
 * 使用前需已定义 $agent（当前代理）
 */
$agent       = $agent ?? YxAuth::requireAgent();
$agentTitle  = $agentTitle ?? '代理中心';
$agentActive = $agentActive ?? 'dashboard';
$agentFlash  = flash();
$agentCid    = (int)($agent['customer_id'] ?? 0);
$agentBalance = $agentCid > 0
    ? (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$agentCid])
    : 0.0;
$agentMenu = [
    'dashboard' => ['概览', 'agent/index.php'],
    'products'  => ['产品下单', 'agent/index.php#products'],
    'orders'    => ['我的订单', 'agent/index.php#orders'],
];
if (YxEdition::can('subsite')) {
    $agentMenu['subsites'] = ['我的分站', 'agent/index.php#subsites'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($agentTitle) ?> - 代理中心 · <?= e(setting('site_name', config('app.name'))) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="admin-body user-body">
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="<?= e(yx_logo_url()) ?>" alt="" onerror="this.style.display='none'">
        <div>
            <strong>代理中心</strong>
            <span>v<?= e(config('app.version')) ?></span>
        </div>
    </div>
    <div class="wallet-card">
        <span>代理账号余额</span>
        <strong><?= e(money($agentBalance, true)) ?></strong>
        <a href="<?= e(url('agent/index.php#products')) ?>">去下单</a>
    </div>
    <nav class="sidebar-menu">
        <?php foreach ($agentMenu as $k => $m): ?>
            <a class="menu-item<?= $agentActive === $k ? ' active' : '' ?>" href="<?= e(url($m[1])) ?>"><i>•</i><?= e($m[0]) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <span class="badge badge-edition">代理折扣 <?= e(rtrim(rtrim(number_format(YxAgent::discount(), 2, '.', ''), '0'), '.')) ?>%</span>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <h1><?= e($agentTitle) ?></h1>
            <span class="muted small"><?= e($agent['name']) ?>（<?= e($agent['username']) ?>）</span>
        </div>
        <div class="topbar-right">
            <a class="btn btn-ghost" href="<?= e(url('index.php')) ?>">返回门户</a>
            <div class="user-chip">
                <span class="avatar"><?= e(mb_substr($agent['name'], 0, 1)) ?></span>
                <div>
                    <strong><?= e($agent['name']) ?></strong>
                    <a href="<?= e(url('agent/logout.php')) ?>">退出登录</a>
                </div>
            </div>
        </div>
    </header>

    <?php if ($agentFlash): ?>
        <div class="alert alert-<?= e($agentFlash['type'] === 'error' ? 'error' : ($agentFlash['type'] === 'warn' ? 'warn' : 'ok')) ?>"><?= e($agentFlash['msg']) ?></div>
    <?php endif; ?>

    <div class="content">
