<?php
/**
 * 优享财务系统 · 工单举报处理（后台）
 *
 * 受理客户 / 分站 / 游客提交的违规举报，可回复、变更状态并留痕。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'reply') {
        $res = YxTicketReport::addReply($id, 'admin', (int)$admin['id'], (string)($_POST['content'] ?? ''));
        log_action('ticket_report.reply', 'report#' . $id, ['ok' => !empty($res['ok'])]);
    } elseif ($action === 'status') {
        $res = YxTicketReport::setStatus(
            $id,
            (string)($_POST['status'] ?? ''),
            (int)$admin['id'],
            (string)($_POST['note'] ?? '')
        );
    } elseif ($action === 'priority') {
        $p = (string)($_POST['priority'] ?? 'normal');
        if (!isset(YxTicketReport::PRIORITIES[$p])) {
            $p = 'normal';
        }
        YxDb::update('ticket_report', ['priority' => $p, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        log_action('ticket_report.priority', 'report#' . $id, ['priority' => $p]);
        $res = ['ok' => true, 'msg' => '优先级已更新'];
    }

    flash((string)($res['msg'] ?? ''), !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/ticket_reports.php' . ($id > 0 ? '?id=' . $id : '')));
}

$filter = [
    'status'   => (string)($_GET['status'] ?? 'open'),
    'category' => (string)($_GET['category'] ?? ''),
    'priority' => (string)($_GET['priority'] ?? ''),
    'kw'       => (string)($_GET['kw'] ?? ''),
    'manual'   => (string)($_GET['manual'] ?? ''),
];

$detailId = (int)($_GET['id'] ?? 0);
$detail   = $detailId > 0 ? YxTicketReport::detail($detailId) : null;

if ($detail) {
    $reporter = '游客';
    if ((int)$detail['customer_id'] > 0) {
        $u = YxDb::value('SELECT username FROM ' . YxDb::table('customer') . ' WHERE id = ?', [(int)$detail['customer_id']]);
        $reporter = '客户 ' . ($u ?: ('#' . (int)$detail['customer_id']));
    } elseif ((int)$detail['subsite_id'] > 0) {
        $u = YxDb::value('SELECT name FROM ' . YxDb::table('subsite') . ' WHERE id = ?', [(int)$detail['subsite_id']]);
        $reporter = '分站 ' . ($u ?: ('#' . (int)$detail['subsite_id']));
    }
    $detail['reporter_label'] = $reporter;
    if ((int)$detail['ticket_id'] > 0) {
        $detail['ticket_sn'] = (string)YxDb::value('SELECT sn FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [(int)$detail['ticket_id']]);
    }
}

$total = YxTicketReport::count($filter);
$page  = paginate($total, 20);
$rows  = YxTicketReport::search($filter, $page['per_page'], $page['offset']);
$stats = YxTicketReport::stats();

$pageTitle  = '工单举报';
$activeMenu = 'ticket_reports';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="stat accent"><span>待处理</span><strong><?= $stats['pending'] ?></strong><small>需尽快受理</small></div>
    <div class="stat"><span>处理中</span><strong><?= $stats['open'] ?></strong><small>含待处理</small></div>
    <div class="stat"><span>今日新增</span><strong><?= $stats['today'] ?></strong></div>
    <div class="stat"><span>累计举报</span><strong><?= $stats['total'] ?></strong></div>
</div>

<?php if ($detail): ?>
<div class="card mt-4">
    <div class="section-head">
        <h2><?= e($detail['sn']) ?> · <?= e($detail['subject']) ?></h2>
        <div class="row-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/ticket_reports.php')) ?>">返回列表</a>
        </div>
    </div>
    <?= YxDispute::adminPanel($detail) ?>
    <table class="data">
        <tr>
            <th>提交方</th><td><?= e($detail['reporter_label']) ?></td>
            <th>联系方式</th><td><?= e($detail['contact'] ?: '-') ?></td>
        </tr>
        <tr>
            <th>举报类型</th><td><?= e(YxTicketReport::categoryName((string)$detail['category'])) ?></td>
            <th>优先级</th><td><span class="badge <?= e(YxTicketReport::priorityBadge((string)$detail['priority'])) ?>"><?= e(YxTicketReport::priorityName((string)$detail['priority'])) ?></span></td>
        </tr>
        <tr>
            <th>状态</th><td><span class="badge <?= e(YxTicketReport::statusBadge((string)$detail['status'])) ?>"><?= e(YxTicketReport::statusName((string)$detail['status'])) ?></span></td>
            <th>关联工单</th><td class="mono small"><?= e($detail['ticket_sn'] ?? '') !== '' ? e($detail['ticket_sn']) : '-' ?></td>
        </tr>
        <tr>
            <th>提交时间</th><td><?= e($detail['created_at']) ?></td>
            <th>处理时间</th><td><?= e($detail['handled_at'] ?: '-') ?></td>
        </tr>
        <tr><th>举报说明</th><td colspan="3" style="white-space:pre-wrap"><?= e($detail['content']) ?></td></tr>
        <?php if ($detail['evidence']): ?><tr><th>证据</th><td colspan="3" class="mono small"><?= e($detail['evidence']) ?></td></tr><?php endif; ?>
        <?php if ($detail['handle_note']): ?><tr><th>处理结论</th><td colspan="3"><?= e($detail['handle_note']) ?></td></tr><?php endif; ?>
    </table>

    <h2 class="mt-4">沟通记录</h2>
    <?php if (!$detail['replies']): ?>
        <div class="empty">暂无回复</div>
    <?php else: ?>
        <?php foreach ($detail['replies'] as $r): $isAdmin = $r['author_type'] === 'admin'; ?>
            <div style="border-left:3px solid <?= $isAdmin ? '#1c7ed6' : '#c9d3e3' ?>;padding:0 0 10px 12px;margin-bottom:14px">
                <div class="small muted"><strong><?= $isAdmin ? '平台客服' : ($r['author_type'] === 'subsite' ? '分站' : '客户') ?></strong> · <?= e($r['created_at']) ?></div>
                <div style="white-space:pre-wrap"><?= e($r['content']) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="grid-2 mt-3">
        <div>
            <h2>回复</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                <label>回复内容 <textarea name="content" required placeholder="告知受理进度或处理结论"><?= !empty($detail['manual_only']) ? e(YxDispute::handoffText()) : '' ?></textarea></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">提交回复</button></div>
            </form>
        </div>
        <div>
            <h2>处理</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                <label>状态
                    <select name="status">
                        <?php foreach (YxTicketReport::STATUSES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $detail['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>处理说明 <input type="text" name="note" maxlength="255" value="<?= e($detail['handle_note']) ?>" placeholder="会展示给提交方"></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">保存处理结果</button></div>
            </form>
            <form method="post" class="inline-form mt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="priority">
                <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                <label>优先级
                    <select name="priority">
                        <?php foreach (YxTicketReport::PRIORITIES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $detail['priority'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-ghost btn-sm" type="submit">更新优先级</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="section-head">
        <h2>举报列表（共 <?= $total ?> 条）</h2>
        <form method="get" class="inline-form">
            <label>状态
                <select name="status">
                    <option value="open" <?= $filter['status'] === 'open' ? 'selected' : '' ?>>未结束</option>
                    <option value="" <?= $filter['status'] === '' ? 'selected' : '' ?>>全部</option>
                    <?php foreach (YxTicketReport::STATUSES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $filter['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>类型
                <select name="category">
                    <option value="">全部</option>
                    <?php foreach (YxTicketReport::CATEGORIES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $filter['category'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>优先级
                <select name="priority">
                    <option value="">全部</option>
                    <?php foreach (YxTicketReport::PRIORITIES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $filter['priority'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>关键字 <input type="text" name="kw" value="<?= e($filter['kw']) ?>" placeholder="编号 / 标题 / 联系方式"></label>
            <label>受理方式
                <select name="manual">
                    <option value="" <?= $filter['manual'] === '' ? 'selected' : '' ?>>全部</option>
                    <option value="1" <?= $filter['manual'] === '1' ? 'selected' : '' ?>>仅人工办理（报案类）</option>
                    <option value="0" <?= $filter['manual'] === '0' ? 'selected' : '' ?>>常规举报</option>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>编号</th><th>标题</th><th>类型</th><th>优先级</th><th>提交方</th><th>回复</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono small"><?= e($r['sn']) ?></td>
                <td class="wrap"><?= e($r['subject']) ?> <?= YxDispute::badge($r) ?><br><span class="muted small"><?= e(mb_substr((string)$r['content'], 0, 50)) ?></span></td>
                <td class="small"><?= e(YxTicketReport::categoryName((string)$r['category'])) ?></td>
                <td><span class="badge <?= e(YxTicketReport::priorityBadge((string)$r['priority'])) ?>"><?= e(YxTicketReport::priorityName((string)$r['priority'])) ?></span></td>
                <td class="small"><?= (int)$r['customer_id'] > 0 ? '客户 #' . (int)$r['customer_id'] : ((int)$r['subsite_id'] > 0 ? '分站 #' . (int)$r['subsite_id'] : '游客') ?></td>
                <td><?= (int)$r['reply_count'] ?></td>
                <td><span class="badge <?= e(YxTicketReport::statusBadge((string)$r['status'])) ?>"><?= e(YxTicketReport::statusName((string)$r['status'])) ?></span></td>
                <td class="small muted"><?= e($r['created_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="<?= e(url('admin/ticket_reports.php?id=' . (int)$r['id'])) ?>">处理</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty">暂无举报</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
