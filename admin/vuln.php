<?php
/**
 * 优享财务系统 · 漏洞审核（后台）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string)($_POST['action'] ?? '') === 'handle') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pending', 'accepted', 'fixing', 'fixed', 'rejected'], true) ? $_POST['status'] : 'accepted';
        YxDb::update('vuln_report', [
            'status' => $status, 'handle_note' => trim((string)($_POST['note'] ?? '')),
            'handled_by' => (int)$admin['id'], 'handled_at' => now(), 'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
        log_action('vuln.handle', 'vuln#' . $id, ['status' => $status]);
        flash('已更新漏洞处理状态');
    }
    redirect(url('admin/vuln.php'));
}

$rows    = YxDb::all('SELECT * FROM ' . YxDb::table('vuln_report') . ' ORDER BY id DESC LIMIT 200');
$pending = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('vuln_report') . " WHERE status = 'pending'");
$SEVERITY = ['low' => '低', 'mid' => '中', 'high' => '高', 'critical' => '严重'];
$VSTAT    = ['pending' => '待处理', 'accepted' => '已受理', 'fixing' => '修复中', 'fixed' => '已修复', 'rejected' => '已忽略'];
$sevCls   = ['critical' => 'badge-bad', 'high' => 'badge-bad', 'mid' => 'badge-warn', 'low' => 'badge-info'];

$pageTitle  = '漏洞审核';
$activeMenu = 'vuln';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="card">
    <h2>漏洞提交（<?= count($rows) ?>，待处理 <?= $pending ?>）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>编号</th><th>标题</th><th>资产</th><th>等级</th><th>提交人</th><th>状态</th><th>时间</th><th>处理</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $v): ?>
            <tr>
                <td class="mono small"><?= e($v['sn']) ?></td>
                <td class="wrap"><?= e($v['title']) ?> <?= YxDispute::badge($v) ?><br><span class="muted small"><?= e(mb_substr((string)$v['description'], 0, 60)) ?></span></td>
                <td class="small"><?= e($v['asset_type']) ?><?php if ($v['target']): ?><br><span class="mono"><?= e($v['target']) ?></span><?php endif; ?></td>
                <td><span class="badge <?= e($sevCls[$v['severity']] ?? 'badge-mute') ?>"><?= e($SEVERITY[$v['severity']] ?? $v['severity']) ?></span></td>
                <td class="small"><?= e($v['reporter']) ?><br><?= e($v['contact']) ?></td>
                <td><?= e($VSTAT[$v['status']] ?? $v['status']) ?></td>
                <td class="small muted"><?= e($v['created_at']) ?></td>
                <td>
                    <details><summary style="cursor:pointer;color:var(--primary)">详情/处理</summary>
                        <?= YxDispute::adminPanel($v) ?>
                        <?php if ($v['poc']): ?><pre class="mono small" style="white-space:pre-wrap;background:#f6f8fc;padding:8px;border-radius:6px"><?= e($v['poc']) ?></pre><?php endif; ?>
                        <form method="post" class="inline-form mt-2">
                            <?= csrf_field() ?><input type="hidden" name="action" value="handle"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <select name="status"><?php foreach ($VSTAT as $k => $lab): ?><option value="<?= e($k) ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
                            <input type="text" name="note" value="<?= e($v['handle_note']) ?>" placeholder="处理说明" style="width:160px">
                            <button class="btn btn-sm btn-primary" type="submit">提交</button>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty">暂无漏洞提交</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
