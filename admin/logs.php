<?php
/**
 * 优享财务系统 · 操作日志
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$keyword = trim((string)($_GET['kw'] ?? ''));
$where   = ' WHERE 1=1';
$bind    = [];
if ($keyword !== '') {
    $where .= ' AND (action LIKE ? OR target LIKE ?)';
    $bind[] = '%' . $keyword . '%';
    $bind[] = '%' . $keyword . '%';
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('admin_log') . $where, $bind);
$page  = paginate($total, 30);
$rows  = YxDb::all(
    'SELECT l.*, a.username FROM ' . YxDb::table('admin_log') . ' l
     LEFT JOIN ' . YxDb::table('admin') . ' a ON a.id = l.admin_id
     ' . $where . ' ORDER BY l.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$pageTitle  = '操作日志';
$activeMenu = 'logs';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2>审计记录（共 <?= $total ?> 条）</h2>
        <form method="get" class="inline-form">
            <label>关键词 <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="动作 / 对象"></label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>时间</th><th>管理员</th><th>动作</th><th>对象</th><th>明细</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="small muted"><?= e($r['created_at']) ?></td>
                <td><?= e($r['username'] ?: ('#' . $r['admin_id'])) ?></td>
                <td class="mono small"><?= e($r['action']) ?></td>
                <td class="wrap"><?= e($r['target'] ?: '-') ?></td>
                <td class="mono small wrap"><?= e($r['detail'] ?: '-') ?></td>
                <td class="mono small"><?= e($r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
