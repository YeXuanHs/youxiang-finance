<?php
/**
 * 优享财务系统 · 我的工单
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
YxEdition::requireFeature('ticket', url('user/index.php'));
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$DEPARTMENTS = ['tech' => '技术支持', 'billing' => '财务计费', 'sales' => '售前咨询', 'abuse' => '投诉与违规'];
$PRIORITIES  = ['low' => '低', 'normal' => '中', 'high' => '高', 'urgent' => '紧急'];
$STATUSES    = ['open' => '待处理', 'replied' => '已回复', 'closed' => '已关闭'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $dept    = isset($DEPARTMENTS[$_POST['department'] ?? '']) ? $_POST['department'] : 'tech';
        $priority = isset($PRIORITIES[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal';
        $sid     = (int)($_POST['service_id'] ?? 0);
        if ($subject === '' || $content === '') {
            flash('请填写工单主题与详细描述', 'error');
        } else {
            if ($sid > 0) {
                $ok = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE id = ? AND customer_id = ?', [$sid, $cid]);
                if (!$ok) {
                    $sid = 0;
                }
            }
            $tid = YxBusiness::createTicket([
                'customer_id' => $cid,
                'service_id'  => $sid,
                'department'  => $dept,
                'subject'     => $subject,
                'priority'    => $priority,
            ]);
            YxBusiness::replyTicket($tid, 'customer', $cid, $content);
            log_action('ticket.front_create', 'ticket#' . $tid);
            flash('工单已提交，编号 #' . $tid . '，我们会尽快处理');
        }
        redirect(url('user/tickets.php'));
    }

    if ($action === 'reply') {
        $tid     = (int)($_POST['id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        $ticket  = YxDb::one('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE id = ? AND customer_id = ?', [$tid, $cid]);
        if (!$ticket) {
            flash('工单不存在', 'error');
        } elseif ($content === '') {
            flash('回复内容不能为空', 'error');
        } else {
            $res = YxBusiness::replyTicket($tid, 'customer', $cid, $content);
            flash($res['msg'], $res['ok'] ? 'success' : 'error');
        }
        redirect(url('user/tickets.php?id=' . $tid));
    }

    if ($action === 'close') {
        $tid    = (int)($_POST['id'] ?? 0);
        $ticket = YxDb::one('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE id = ? AND customer_id = ?', [$tid, $cid]);
        if ($ticket && $ticket['status'] !== 'closed') {
            $res = YxBusiness::closeTicket($tid);
            flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
        } else {
            flash('工单不存在或已关闭', 'error');
        }
        redirect(url('user/tickets.php'));
    }
}

$detailId = (int)($_GET['id'] ?? 0);
// 顶栏「账户管理 → 提交工单」带 new=1 进入时，聚焦到新建表单
$newMode  = !$detailId && (string)($_GET['new'] ?? '') === '1';
$detail   = null;
$replies  = [];
if ($detailId > 0) {
    $detail = YxDb::one('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE id = ? AND customer_id = ?', [$detailId, $cid]);
    if ($detail) {
        $replies = YxDb::all('SELECT * FROM ' . YxDb::table('ticket_reply') . ' WHERE ticket_id = ? ORDER BY id ASC', [$detailId]);
    }
}

$rows = YxDb::all('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$cid]);
$myServices = YxDb::all('SELECT id, name FROM ' . YxDb::table('service') . ' WHERE customer_id = ? ORDER BY id DESC', [$cid]);

$userTitle  = '工单支持';
$userActive = 'tickets';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if ($detail): ?>
<div class="card">
    <div class="section-head">
        <h2>#<?= (int)$detail['id'] ?> <?= e($detail['subject']) ?></h2>
        <div class="row-actions">
            <a class="btn btn-sm btn-ghost" href="<?= e(url('user/tickets.php')) ?>">返回列表</a>
            <?php if ($detail['status'] !== 'closed'): ?>
                <form method="post" onsubmit="return confirm('确认关闭该工单？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                    <button class="btn btn-sm btn-bad" type="submit">关闭工单</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <table class="data">
        <tr><th>工单号</th><td class="mono"><?= e($detail['sn']) ?></td>
            <th>部门</th><td><?= e($DEPARTMENTS[$detail['department']] ?? $detail['department']) ?></td></tr>
        <tr><th>优先级</th><td><?= e($PRIORITIES[$detail['priority']] ?? $detail['priority']) ?></td>
            <th>状态</th><td><span class="badge <?= ['open' => 'badge-warn', 'replied' => 'badge-info', 'closed' => 'badge-mute'][$detail['status']] ?? 'badge-mute' ?>"><?= e($STATUSES[$detail['status']] ?? $detail['status']) ?></span></td></tr>
    </table>

    <h2 class="mt-5">沟通记录</h2>
<div class="thread">
<?php foreach ($replies as $r): $isAdmin = $r['author_type'] === 'admin'; ?>
    <div class="thread-item<?= $isAdmin ? ' is-admin' : '' ?>">
        <div class="thread-head"><strong><?= $isAdmin ? '客服' : '我' ?></strong> · <?= e($r['created_at']) ?></div>
        <div class="thread-body"><?= e($r['content']) ?></div>
    </div>
<?php endforeach; ?>
</div>
    <?php if (!$replies): ?><div class="empty">暂无沟通记录</div><?php endif; ?>

    <?php if ($detail['status'] !== 'closed'): ?>
        <form method="post" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <label>补充说明 <textarea name="content" required></textarea></label>
            <button class="btn btn-primary" type="submit">提交回复</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid-2">
    <div class="card" id="yxTicketNew">
        <h2>提交新工单</h2>
        <?= YxDispute::noticeHtml() ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label>工单主题 <input type="text" name="subject" required placeholder="如：香港主机无法访问"></label>
            <div class="grid-2">
                <label>所属部门
                    <select name="department">
                        <?php foreach ($DEPARTMENTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>优先级
                    <select name="priority">
                        <?php foreach ($PRIORITIES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>关联业务
                <select name="service_id">
                    <option value="0">不关联</option>
                    <?php foreach ($myServices as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>问题描述 <textarea name="content" required placeholder="请尽量提供 IP、报错信息、复现步骤"></textarea></label>
            <button class="btn btn-primary" type="submit">提交工单</button>
        </form>
    </div>

    <div class="card">
        <h2>我的工单（<?= count($rows) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>ID</th><th>主题</th><th>部门</th><th>状态</th><th>更新时间</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td class="wrap"><?= e($t['subject']) ?></td>
                    <td><?= e($DEPARTMENTS[$t['department']] ?? $t['department']) ?></td>
                    <td>
                        <?php $cls = ['open' => 'badge-warn', 'replied' => 'badge-info', 'closed' => 'badge-mute'][$t['status']] ?? 'badge-mute'; ?>
                        <span class="badge <?= $cls ?>"><?= e($STATUSES[$t['status']] ?? $t['status']) ?></span>
                    </td>
                    <td class="small muted"><?= e($t['updated_at']) ?></td>
                    <td><a class="btn btn-sm btn-ghost" href="<?= e(url('user/tickets.php?id=' . (int)$t['id'])) ?>">查看</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无工单</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>

<?php if ($newMode): ?>
<script>
(function () {
    var box = document.getElementById('yxTicketNew');
    if (!box) return;
    box.scrollIntoView({behavior: 'smooth', block: 'start'});
    var first = box.querySelector('input[name=subject]');
    if (first) first.focus({preventScroll: true});
})();
</script>
<?php endif; ?>
