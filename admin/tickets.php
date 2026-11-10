<?php
/**
 * 优享财务系统 · 工单协同（专业版及以上）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('ticket');

$DEPARTMENTS = ['tech' => '技术支持', 'billing' => '财务计费', 'sales' => '售前咨询', 'abuse' => '投诉与违规'];
$PRIORITIES  = ['low' => '低', 'normal' => '中', 'high' => '高', 'urgent' => '紧急'];
$STATUSES    = ['open' => '待处理', 'replied' => '已回复', 'closed' => '已关闭'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'reply') {
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content === '') {
            $res = ['ok' => false, 'msg' => '回复内容不能为空'];
        } else {
            $res = YxBusiness::replyTicket($id, 'admin', (int)$admin['id'], $content);
        }
        log_action('ticket.reply', 'ticket#' . $id, ['ok' => $res['ok']]);
        flash($res['msg'], $res['ok'] ? 'success' : 'error');
        redirect(url('admin/tickets.php?id=' . $id));
    }

    if ($action === 'close') {
        $res = YxBusiness::closeTicket($id);
        log_action('ticket.close', 'ticket#' . $id, ['ok' => !empty($res['ok'])]);
        flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
        redirect(url('admin/tickets.php'));
    }

    if ($action === 'priority') {
        $p = isset($PRIORITIES[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal';
        YxDb::update('ticket', ['priority' => $p, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        flash('优先级已更新');
        redirect(url('admin/tickets.php?id=' . $id));
    }
}

$detailId = (int)($_GET['id'] ?? 0);
$detail   = null;
$replies  = [];
if ($detailId > 0) {
    $detail = YxDb::one(
        'SELECT t.*, c.username, c.email, s.name AS service_name
         FROM ' . YxDb::table('ticket') . ' t
         LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = t.customer_id
         LEFT JOIN ' . YxDb::table('service') . ' s ON s.id = t.service_id
         WHERE t.id = ?',
        [$detailId]
    );
    if ($detail) {
        $replies = YxDb::all('SELECT * FROM ' . YxDb::table('ticket_reply') . ' WHERE ticket_id = ? ORDER BY id ASC', [$detailId]);
    }
}

$statusF = (string)($_GET['status'] ?? '');
$where   = ' WHERE 1=1';
$bind    = [];
if ($statusF !== '' && isset($STATUSES[$statusF])) {
    $where .= ' AND t.status = ?';
    $bind[] = $statusF;
} else {
    $where .= " AND t.status IN ('open','replied')";
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ticket') . ' t' . $where, $bind);
$page  = paginate($total, 15);
$rows  = YxDb::all(
    'SELECT t.*, c.username FROM ' . YxDb::table('ticket') . ' t
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = t.customer_id
     ' . $where . ' ORDER BY t.updated_at DESC, t.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$pageTitle  = '工单协同';
$activeMenu = 'tickets';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<?php if ($detail): ?>
<div class="card">
    <div class="section-head">
        <h2>#<?= (int)$detail['id'] ?> <?= e($detail['subject']) ?></h2>
        <div class="row-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/tickets.php')) ?>">返回列表</a>
            <?php if ($detail['status'] !== 'closed'): ?>
                <form method="post" class="row-actions" onsubmit="return confirm('确认关闭该工单？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                    <button class="btn btn-bad btn-sm" type="submit">关闭工单</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <table class="data">
        <tr><th>工单号</th><td class="mono"><?= e($detail['sn']) ?></td><th>客户</th><td><?= e($detail['username'] ?: '-') ?>（<?= e($detail['email'] ?: '-') ?>）</td></tr>
        <tr><th>部门</th><td><?= e($DEPARTMENTS[$detail['department']] ?? $detail['department']) ?></td><th>关联业务</th><td><?= e($detail['service_name'] ?: '-') ?></td></tr>
        <tr><th>状态</th><td>
            <?php $cls = ['open' => 'badge-warn', 'replied' => 'badge-info', 'closed' => 'badge-mute'][$detail['status']] ?? 'badge-mute'; ?>
            <span class="badge <?= $cls ?>"><?= e($STATUSES[$detail['status']] ?? $detail['status']) ?></span>
        </td><th>创建时间</th><td><?= e($detail['created_at']) ?></td></tr>
    </table>

    <form method="post" class="inline-form" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="priority">
        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
        <label>优先级
            <select name="priority">
                <?php foreach ($PRIORITIES as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $detail['priority'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-ghost btn-sm" type="submit">更新优先级</button>
    </form>
</div>

<div class="card">
    <h2>沟通记录</h2>
    <?php if (!$replies): ?>
        <div class="empty">暂无回复</div>
    <?php else: ?>
        <?php foreach ($replies as $r): $isAdmin = $r['author_type'] === 'admin'; ?>
            <div style="border-left:3px solid <?= $isAdmin ? '#1c7ed6' : '#c9d3e3' ?>;padding:0 0 10px 12px;margin-bottom:14px">
                <div class="small muted">
                    <strong><?= $isAdmin ? '客服' : '客户' ?></strong> · <?= e($r['created_at']) ?>
                </div>
                <div style="white-space:pre-wrap"><?= e($r['content']) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($detail['status'] !== 'closed'): ?>
        <form method="post" style="margin-top:16px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <label>回复内容 <textarea name="content" required placeholder="请输入回复内容"></textarea></label>
            <button class="btn btn-primary" type="submit">提交回复</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="section-head">
        <h2>工单列表（共 <?= $total ?> 条）</h2>
        <form method="get" class="inline-form">
            <label>状态
                <select name="status">
                    <option value="">待处理 + 已回复</option>
                    <?php foreach ($STATUSES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>主题</th><th>客户</th><th>部门</th><th>优先级</th><th>回复数</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td><?= (int)$t['id'] ?></td>
                <td class="wrap"><?= e($t['subject']) ?></td>
                <td><?= e($t['username'] ?: '-') ?></td>
                <td><?= e($DEPARTMENTS[$t['department']] ?? $t['department']) ?></td>
                <td><?= e($PRIORITIES[$t['priority']] ?? $t['priority']) ?></td>
                <td><?= (int)$t['reply_count'] ?></td>
                <td>
                    <?php $cls = ['open' => 'badge-warn', 'replied' => 'badge-info', 'closed' => 'badge-mute'][$t['status']] ?? 'badge-mute'; ?>
                    <span class="badge <?= $cls ?>"><?= e($STATUSES[$t['status']] ?? $t['status']) ?></span>
                </td>
                <td class="small muted"><?= e($t['updated_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="<?= e(url('admin/tickets.php?id=' . (int)$t['id'])) ?>">处理</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty">暂无工单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
