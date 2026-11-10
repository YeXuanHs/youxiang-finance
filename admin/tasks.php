<?php
/**
 * 优享财务系统 · 任务队列
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('upstream');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'run') {
        $stat = YxTask::run(max(1, min(200, (int)($_POST['limit'] ?? 50))));
        $res = [
            'ok'  => true,
            'msg' => sprintf('执行完成：成功 %d，待重试 %d，失败 %d', $stat['done'], $stat['retry'], $stat['failed']),
        ];
        $_SESSION['_task_run_detail'] = $stat['items'];
    } elseif ($action === 'retry') {
        $res = YxTask::retry($id) ? ['ok' => true, 'msg' => '已重新入队'] : ['ok' => false, 'msg' => '仅已失败/已取消的任务可重试'];
    } elseif ($action === 'cancel') {
        $res = YxTask::cancel($id) ? ['ok' => true, 'msg' => '已取消'] : ['ok' => false, 'msg' => '该任务无法取消'];
    } elseif ($action === 'purge') {
        $n = YxTask::purge((int)($_POST['days'] ?? 30));
        $res = ['ok' => true, 'msg' => '已清理 ' . $n . ' 条历史任务'];
    }

    log_action('task.' . $action, 'task#' . $id, ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/tasks.php'));
}

$detail = $_SESSION['_task_run_detail'] ?? null;
unset($_SESSION['_task_run_detail']);

$stats = YxTask::stats();

$statusF = (string)($_GET['status'] ?? '');
$typeF   = (string)($_GET['type'] ?? '');
$where   = ' WHERE 1=1';
$bind    = [];
if ($statusF !== '') {
    $where .= ' AND status = ?';
    $bind[] = $statusF;
}
if ($typeF !== '') {
    $where .= ' AND type = ?';
    $bind[] = $typeF;
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('task') . $where, $bind);
$page  = paginate($total, 25);
$rows  = YxDb::all('SELECT * FROM ' . YxDb::table('task') . $where . ' ORDER BY id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'], $bind);

$svcMap = [];
foreach (YxDb::all('SELECT id, sn, name FROM ' . YxDb::table('service') . ' WHERE id IN (SELECT ref_id FROM ' . YxDb::table('task') . " WHERE ref_type = 'service' LIMIT 500)") as $s) {
    $svcMap[(int)$s['id']] = $s['sn'] . ' · ' . $s['name'];
}

$pageTitle  = '任务队列';
$activeMenu = 'tasks';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="stat accent"><span>待执行</span><strong><?= (int)$stats['pending'] ?></strong></div>
    <div class="stat"><span>执行中</span><strong><?= (int)$stats['running'] ?></strong></div>
    <div class="stat"><span>已完成</span><strong><?= (int)$stats['done'] ?></strong></div>
    <div class="stat"><span>已失败</span><strong style="color:<?= (int)$stats['failed'] > 0 ? '#e03131' : 'inherit' ?>"><?= (int)$stats['failed'] ?></strong></div>
</div>

<div class="card" style="margin-top:16px">
    <h2>队列说明</h2>
    <p class="muted small">
        上游开通、续费、开关机、重装、销毁与下游事件回调均以异步任务执行，由定时任务
        <code>tools/cron.php</code> 驱动（建议每 5-10 分钟一次）。失败任务按 1/5/15/60/120 分钟退避重试，
        超过上限标记为「已失败」，可在此手动重试。同一业务同类型任务默认去重，避免重复开通。
    </p>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run">
        <label style="max-width:160px">本次执行条数 <input type="number" name="limit" value="50"></label>
        <button class="btn btn-primary" type="submit">立即执行队列</button>
    </form>
    <?php if ($detail): ?>
        <div style="margin-top:14px">
            <h3>上次执行明细</h3>
            <ul class="tight muted mono small">
                <?php foreach ($detail as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
                <?php if (!$detail): ?><li>没有可执行的任务</li><?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="section-head">
        <h2>任务列表（共 <?= $total ?> 条）</h2>
        <form method="get" class="inline-form">
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <?php foreach (['pending', 'running', 'done', 'failed', 'cancelled'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $statusF === $s ? 'selected' : '' ?>><?= e(YxTask::statusName($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>类型
                <select name="type">
                    <option value="">全部</option>
                    <?php foreach (YxTask::types() as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $typeF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>类型</th><th>对象</th><th>状态</th><th>尝试</th><th>下次重试</th><th>结果 / 错误</th><th>更新时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td><?= (int)$t['id'] ?></td>
                <td><?= e(YxTask::typeName($t['type'])) ?><br><span class="mono muted small"><?= e($t['type']) ?></span></td>
                <td class="small">
                    <?php if ($t['ref_type'] === 'service'): ?>
                        <?= e($svcMap[(int)$t['ref_id']] ?? ('业务#' . $t['ref_id'])) ?>
                    <?php elseif ($t['ref_type'] === 'api_app'): ?>
                        下游应用#<?= (int)$t['ref_id'] ?>
                    <?php else: ?>
                        <?= e($t['ref_type'] ?: '-') ?>#<?= (int)$t['ref_id'] ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $cls = ['pending' => 'badge-warn', 'running' => 'badge-info', 'done' => 'badge-ok', 'failed' => 'badge-bad', 'cancelled' => 'badge-mute'][$t['status']] ?? 'badge-mute';
                    ?>
                    <span class="badge <?= $cls ?>"><?= e(YxTask::statusName($t['status'])) ?></span>
                </td>
                <td><?= (int)$t['attempts'] ?> / <?= (int)$t['max_attempts'] ?></td>
                <td class="small muted"><?= e($t['status'] === 'pending' ? ($t['next_retry_at'] ?: '-') : '-') ?></td>
                <td class="wrap small">
                    <?php if ($t['error']): ?>
                        <span style="color:#b02323"><?= e($t['error']) ?></span>
                    <?php elseif ($t['result']): ?>
                        <span class="mono"><?= e(mb_substr((string)$t['result'], 0, 120)) ?></span>
                    <?php else: ?>
                        <span class="muted">-</span>
                    <?php endif; ?>
                </td>
                <td class="small muted"><?= e($t['updated_at'] ?: $t['created_at']) ?></td>
                <td>
                    <div class="row-actions">
                        <?php if (in_array($t['status'], ['failed', 'cancelled'], true)): ?>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-sm btn-primary" type="submit">重试</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($t['status'], ['pending', 'running'], true)): ?>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">取消</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty">暂无任务。客户下单并支付后，上游开通任务会自动出现在这里。</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>

    <form method="post" class="form-actions" style="margin-top:14px" onsubmit="return confirm('确认清理 30 天前的已结束任务？')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="purge">
        <input type="hidden" name="days" value="30">
        <button class="btn btn-ghost btn-sm" type="submit">清理 30 天前的历史任务</button>
    </form>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
