<?php
/**
 * 优享财务系统 · 插件中心（管理端）
 *
 * 统一入口：把「商店管理」「开发者中心」里与插件相关的能力收敛到一页。
 * 本页只做聚合展示与审核动作，插件的新增/编辑/上架仍走 admin/store.php，
 * 开发者审核仍走 admin/developers.php，避免同一份数据出现两套写入口。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxAuth::requireAdminPermission('store');

$tab = (string)($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview', 'pending', 'installs'], true)) {
    $tab = 'overview';
}

$res = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'approve' || $action === 'reject') {
        $app = $id > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$id]) : null;
        if (!$app) {
            $res = ['ok' => false, 'msg' => '插件不存在'];
        } else {
            $note = trim((string)($_POST['note'] ?? ''));
            YxDb::update('app', [
                'status'      => $action === 'approve' ? 'on' : 'rejected',
                'review_note' => $note !== '' ? $note : null,
                'updated_at'  => now(),
            ], 'id = :id', ['id' => $id]);
            log_action('plugin.' . $action, 'app#' . $id, ['key' => $app['key'], 'note' => $note]);
            $res = ['ok' => true, 'msg' => $action === 'approve' ? '已通过审核并上架' : '已驳回'];
        }
    }
}

$stats     = YxPluginCenter::statusStats();
$typeStats = YxPluginCenter::typeStats();
$market    = YxPluginCenter::marketStats();
$pending   = YxPluginCenter::pendingApps(100);

$installs = [];
$installCnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_install'));
if ($tab === 'installs') {
    $installs = YxDb::all(
        'SELECT i.*, a.name AS app_name, a.type AS app_type, c.username
           FROM ' . YxDb::table('app_install') . ' i
           LEFT JOIN ' . YxDb::table('app') . ' a ON a.id = i.app_id
           LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = i.customer_id
          ORDER BY i.id DESC LIMIT 200'
    );
}

$pageTitle  = '插件中心';
$activeMenu = 'plugin_center';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<?php if ($res): ?>
    <div class="alert <?= $res['ok'] ? 'alert-ok' : 'alert-error' ?>"><?= e($res['msg']) ?></div>
<?php endif; ?>

<div class="cat-bar">
    <a class="<?= $tab === 'overview' ? 'active' : '' ?>" href="<?= e(url('admin/plugin_center.php?tab=overview')) ?>">概览</a>
    <a class="<?= $tab === 'pending' ? 'active' : '' ?>" href="<?= e(url('admin/plugin_center.php?tab=pending')) ?>">待审核（<?= count($pending) ?>）</a>
    <a class="<?= $tab === 'installs' ? 'active' : '' ?>" href="<?= e(url('admin/plugin_center.php?tab=installs')) ?>">授权记录（<?= $installCnt ?>）</a>
    <a style="margin-left:auto" href="<?= e(url('admin/store.php')) ?>">插件上架 / 编辑 →</a>
</div>

<?php if ($tab === 'overview'): ?>
    <div class="grid-4" style="margin-bottom:16px">
        <div class="card"><h2>在售插件</h2><div style="font-size:26px;font-weight:700"><?= (int)$market['on'] ?></div>
            <p class="muted small">官方 <?= (int)$market['official'] ?> · 第三方 <?= (int)$market['thirdparty'] ?></p></div>
        <div class="card"><h2>待审核</h2><div style="font-size:26px;font-weight:700"><?= (int)$stats['pending'] ?></div>
            <p class="muted small">草稿 <?= (int)$stats['draft'] ?> 个</p></div>
        <div class="card"><h2>累计销量</h2><div style="font-size:26px;font-weight:700"><?= (int)$market['sales'] ?></div>
            <p class="muted small">已下架 <?= (int)$stats['off'] ?> 个</p></div>
        <div class="card"><h2>已驳回</h2><div style="font-size:26px;font-weight:700"><?= (int)$stats['rejected'] ?></div>
            <p class="muted small">可要求开发者修改后重新提交</p></div>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>按类型分布</h2>
            <table>
                <tr><th>类型</th><th>数量</th></tr>
                <?php foreach ($typeStats as $k => $v): ?>
                    <tr><td><?= e(YxPluginCenter::typeName($k)) ?></td><td><?= (int)$v ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="card">
            <h2>按状态分布</h2>
            <table>
                <tr><th>状态</th><th>数量</th></tr>
                <?php foreach ($stats as $k => $v): ?>
                    <tr><td><?= e(YxDeveloper::appStatusName($k)) ?></td><td><?= (int)$v ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'pending'): ?>
    <div class="card">
        <h2>待审核插件（<?= count($pending) ?>）</h2>
        <?php if (!$pending): ?>
            <div class="empty">暂无待审核的插件</div>
        <?php else: ?>
            <table>
                <tr><th>插件</th><th>标识</th><th>类型</th><th>开发者</th><th>定价</th><th>状态</th><th>审核</th></tr>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><?= e($p['name']) ?> <span class="muted small">v<?= e($p['version']) ?></span></td>
                        <td><code><?= e($p['key']) ?></code></td>
                        <td><?= e(YxPluginCenter::typeName((string)$p['type'])) ?></td>
                        <td><?= e($p['dev_company'] ?: ($p['dev_realname'] ?: '官方')) ?></td>
                        <td><?= e(YxBusiness::priceLabel($p)) ?></td>
                        <td><?= e(YxDeveloper::appStatusName((string)$p['status'])) ?></td>
                        <td>
                            <form method="post" style="display:flex;gap:6px;align-items:center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="text" name="note" placeholder="审核备注（可选）" style="width:130px">
                                <button class="btn btn-sm" type="submit" name="action" value="approve">通过</button>
                                <button class="btn btn-ghost btn-sm" type="submit" name="action" value="reject">驳回</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($tab === 'installs'): ?>
    <div class="card">
        <h2>授权记录（<?= count($installs) ?>，最多 200 条）</h2>
        <?php if (!$installs): ?>
            <div class="empty">暂无授权记录</div>
        <?php else: ?>
            <table>
                <tr><th>插件</th><th>客户</th><th>绑定域名</th><th>授权码</th><th>到期</th><th>状态</th></tr>
                <?php foreach ($installs as $r): $st = YxPluginCenter::installState($r); ?>
                    <tr>
                        <td><?= e($r['app_name'] ?: ($r['app_key'] ?: '—')) ?></td>
                        <td><?= e($r['username'] ?: ('#' . (int)$r['customer_id'])) ?></td>
                        <td><?= $r['domain'] === 'resource' ? '<span class="muted">资源权益</span>' : e($r['domain']) ?></td>
                        <td><code><?= e($r['license_key'] ?: '—') ?></code></td>
                        <td><?= e($r['expire_at'] ?: '长期') ?></td>
                        <td><span class="badge <?= e($st['class']) ?>"><?= e($st['name']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
