<?php
/**
 * 优享财务系统 · 代理申请审核（管理端）
 *
 * 审核通过后不会自动创建 agent 账号，需管理员到「渠道 / 代理」中
 * 设置登录账号与密码后开通，避免绕过密码与权限审查。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$statusF = (string)($_GET['status'] ?? '');
if (!isset(YxAgentApply::STATUS[$statusF])) {
    $statusF = '';
}

$res = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'approve' || $action === 'reject') {
        $res = YxAgentApply::review($id, $action === 'approve', trim((string)($_POST['note'] ?? '')), (int)$admin['id']);
    } else {
        $res = ['ok' => false, 'msg' => '未知操作'];
    }
}

$rows    = YxAgentApply::adminRows($statusF);
$pending = YxAgentApply::adminRows('pending');
$counts  = ['all' => count(YxAgentApply::adminRows('', 1000))];
foreach (YxAgentApply::STATUS as $k => $v) {
    $counts[$k] = count(YxAgentApply::adminRows($k, 1000));
}

$pageTitle  = '代理申请';
$activeMenu = 'agent_apply';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<?php if ($res): ?>
    <div class="alert <?= $res['ok'] ? 'alert-ok' : 'alert-error' ?>"><?= e($res['msg']) ?></div>
<?php endif; ?>

<div class="cat-bar">
    <a class="<?= $statusF === '' ? 'active' : '' ?>" href="<?= e(url('admin/agent_apply.php')) ?>">全部（<?= (int)$counts['all'] ?>）</a>
    <?php foreach (YxAgentApply::STATUS as $k => $v): ?>
        <a class="<?= $statusF === $k ? 'active' : '' ?>" href="<?= e(url('admin/agent_apply.php?status=' . $k)) ?>"><?= e($v) ?>（<?= (int)$counts[$k] ?>）</a>
    <?php endforeach; ?>
    <a style="margin-left:auto" href="<?= e(url('admin/agents.php')) ?>">渠道 / 代理 →</a>
</div>

<div class="card">
    <h2>代理申请（<?= count($rows) ?>）</h2>
    <?php if (!$rows): ?>
        <div class="empty">暂无申请记录</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr>
                <th>编号</th><th>申请类型</th><th>申请人 / 企业</th><th>联系方式</th>
                <th>域名</th><th>预估月销售</th><th>实名</th><th>状态</th><th>提交时间</th><th>审核</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="mono small">#<?= (int)$r['id'] ?></td>
                    <td><?= e(YxAgentApply::typeName((string)$r['apply_type'])) ?></td>
                    <td class="wrap">
                        <?= e($r['subject_name']) ?>
                        <?php if ($r['company']): ?><br><span class="muted small"><?= e($r['company']) ?></span><?php endif; ?>
                        <?php if ($r['license_no']): ?><br><span class="muted small mono"><?= e($r['license_no']) ?></span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?= e($r['username'] ?: '—') ?><br>
                        <?= e($r['phone'] ?: '—') ?><br>
                        <span class="muted"><?= e($r['email'] ?: '—') ?></span>
                    </td>
                    <td class="small"><?= e($r['domain'] ?: '—') ?></td>
                    <td class="small"><?= e(money($r['expect_sales'] ?? 0, true)) ?></td>
                    <td class="small"><?= e(YxRealname::statusName((string)($r['cust_realname_status'] ?? 'none'))) ?></td>
                    <td><span class="badge <?= e(YxAgentApply::statusClass((string)$r['status'])) ?>"><?= e(YxAgentApply::statusName((string)$r['status'])) ?></span></td>
                    <td class="small"><?= e($r['created_at'] ?: '—') ?></td>
                    <td>
                        <?php if ((string)$r['status'] === 'pending'): ?>
                            <form method="post" style="display:flex;gap:6px;align-items:center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="text" name="note" placeholder="备注（可选）" style="width:120px">
                                <button class="btn btn-sm" type="submit" name="action" value="approve">通过</button>
                                <button class="btn btn-ghost btn-sm" type="submit" name="action" value="reject">驳回</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small"><?= e($r['admin_remark'] ?: '已处理') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
