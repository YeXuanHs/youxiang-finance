<?php
/**
 * 优享财务系统 · 简历投递管理
 *
 * 列表筛选（状态 / 职位 / 关键词）+ 详情查看 + 状态流转 + 删除。
 * 附件只通过 download.php 鉴权下载，不直接暴露 uploads 路径。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'status') {
        $res = YxCareer::setStatus(
            $id,
            (string)($_POST['status'] ?? ''),
            (int)$admin['id'],
            (string)($_POST['note'] ?? '')
        );
    } elseif ($action === 'delete') {
        $res = YxCareer::remove($id)
            ? ['ok' => true, 'msg' => '投递记录已删除，附件同时清理']
            : ['ok' => false, 'msg' => '投递记录不存在'];
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    $back = 'admin/resumes.php';
    $qs   = [];
    foreach (['status', 'job_id', 'kw', 'page'] as $k) {
        if (isset($_POST['back_' . $k]) && $_POST['back_' . $k] !== '') {
            $qs[$k] = (string)$_POST['back_' . $k];
        }
    }
    $view = (int)($_POST['back_view'] ?? 0);
    if ($view > 0) {
        $qs['view'] = $view;
    }
    redirect(url($back . ($qs ? '?' . http_build_query($qs) : '')));
}

$filter = [
    'status' => (string)($_GET['status'] ?? ''),
    'job_id' => (int)($_GET['job_id'] ?? 0),
    'kw'     => trim((string)($_GET['kw'] ?? '')),
];
$total = YxCareer::count($filter);
$pg    = paginate($total, 20);
$rows  = YxCareer::search($filter, $pg['per_page'], $pg['offset']);

$stats    = YxCareer::stats();
$viewId   = (int)($_GET['view'] ?? 0);
$viewing  = $viewId > 0
    ? YxDb::one('SELECT * FROM ' . YxDb::table('resume') . ' WHERE id = ? LIMIT 1', [$viewId])
    : null;
$jobList  = YxCareer::allJobs();

/** 保留当前筛选条件的查询串片段 */
$keep = array_filter([
    'status' => $filter['status'],
    'job_id' => $filter['job_id'] > 0 ? (string)$filter['job_id'] : '',
    'kw'     => $filter['kw'],
], static fn($v) => $v !== '');
$keepQs = $keep ? '&' . http_build_query($keep) : '';
$keepUrl = $keep ? '?' . http_build_query($keep) : '';

$pageTitle  = '简历投递';
$activeMenu = 'careers';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="card stat"><span>今日投递</span><strong><?= (int)$stats['today'] ?></strong></div>
    <div class="card stat"><span>待筛选</span><strong><?= (int)$stats['new'] ?></strong></div>
    <div class="card stat"><span>进行中</span><strong><?= (int)$stats['open'] ?></strong></div>
    <div class="card stat"><span>累计投递</span><strong><?= (int)$stats['total'] ?></strong></div>
</div>

<div class="cat-bar" style="margin-top:16px">
    <a href="<?= e(url('admin/careers.php')) ?>">职位管理</a>
    <a class="active" href="<?= e(url('admin/resumes.php')) ?>">简历投递</a>
</div>

<div class="card">
    <form method="get" class="form-inline">
        <label>状态
            <select name="status">
                <option value="">全部</option>
                <option value="open" <?= $filter['status'] === 'open' ? 'selected' : '' ?>>未结束</option>
                <?php foreach (YxCareer::STATUSES as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $filter['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>职位
            <select name="job_id">
                <option value="0">全部职位</option>
                <?php foreach ($jobList as $j): ?>
                    <option value="<?= (int)$j['id'] ?>" <?= $filter['job_id'] === (int)$j['id'] ? 'selected' : '' ?>>
                        <?= e($j['title']) ?><?= (int)$j['status'] === 1 ? '' : '（已下线）' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>关键词 <input type="search" name="kw" value="<?= e($filter['kw']) ?>" placeholder="编号 / 姓名 / 手机 / 邮箱"></label>
        <button class="btn btn-primary btn-sm" type="submit">筛选</button>
        <?php if ($keep): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/resumes.php')) ?>">重置</a><?php endif; ?>
        <span class="muted small">共 <?= (int)$total ?> 条</span>
    </form>
</div>

<?php if ($viewing): ?>
<div class="card">
    <div class="section-head" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <h2 style="margin:0">
            <?= e($viewing['name']) ?> · <?= e($viewing['job_title']) ?>
            <span class="badge <?= e(YxCareer::statusBadge((string)$viewing['status'])) ?>"><?= e(YxCareer::statusName((string)$viewing['status'])) ?></span>
        </h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/resumes.php' . $keepUrl)) ?>">关闭详情</a>
    </div>

    <div class="grid-2" style="margin-top:12px">
        <div>
            <table class="data">
                <tr><th style="width:110px">投递编号</th><td class="mono"><?= e($viewing['sn']) ?></td></tr>
                <tr><th>应聘职位</th><td><?= e($viewing['job_title']) ?></td></tr>
                <tr><th>手机号</th><td><?= e($viewing['phone']) ?></td></tr>
                <tr><th>邮箱</th><td><?= e($viewing['email']) ?></td></tr>
                <tr><th>性别 / 出生年</th><td><?= e($viewing['gender'] ?: '—') ?> / <?= (int)$viewing['birth_year'] > 0 ? (int)$viewing['birth_year'] . ' 年' : '—' ?></td></tr>
                <tr><th>现居城市</th><td><?= e($viewing['city'] ?: '—') ?></td></tr>
                <tr><th>学历 / 经验</th><td><?= e($viewing['education'] ?: '—') ?> / <?= e($viewing['experience'] ?: '—') ?></td></tr>
                <tr><th>当前公司</th><td><?= e($viewing['current_company'] ?: '—') ?></td></tr>
                <tr><th>作品集</th><td class="wrap">
                    <?php if ($viewing['portfolio']): ?>
                        <a href="<?= e($viewing['portfolio']) ?>" target="_blank" rel="noopener nofollow"><?= e($viewing['portfolio']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td></tr>
                <tr><th>简历附件</th><td>
                    <?php if ($viewing['file_path']): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/resume_file.php?id=' . (int)$viewing['id'])) ?>" target="_blank">下载 <?= e($viewing['file_name'] ?: '简历') ?></a>
                    <?php else: ?><span class="muted">未上传</span><?php endif; ?>
                </td></tr>
                <tr><th>投递信息</th><td class="muted small">来源 <?= e($viewing['source'] === 'about' ? '关于我们' : '招聘页') ?>
                    · <?= e($viewing['created_at']) ?> · IP <?= e($viewing['ip'] ?: '—') ?></td></tr>
            </table>
        </div>
        <div>
            <h3>自我介绍 / 求职留言</h3>
            <div class="career-message"><?= $viewing['message'] ? nl2br(e($viewing['message'])) : '<span class="muted">未填写</span>' ?></div>

            <h3 style="margin-top:18px">处理流程</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
                <input type="hidden" name="back_view" value="<?= (int)$viewing['id'] ?>">
                <input type="hidden" name="back_status" value="<?= e($filter['status']) ?>">
                <input type="hidden" name="back_job_id" value="<?= (int)$filter['job_id'] ?>">
                <input type="hidden" name="back_kw" value="<?= e($filter['kw']) ?>">
                <input type="hidden" name="back_page" value="<?= (int)$pg['page'] ?>">
                <label>状态
                    <select name="status">
                        <?php foreach (YxCareer::STATUSES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= (string)$viewing['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>给候选人的反馈 <span class="muted small">投递人凭编号查询时可见，留空则不覆盖原备注</span>
                    <textarea name="note" rows="4"><?= e($viewing['note'] ?? '') ?></textarea></label>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">保存处理结果</button>
                </div>
            </form>

            <form method="post" style="margin-top:14px" onsubmit="return confirm('确认删除该投递记录？附件会一并删除且不可恢复。')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
                <input type="hidden" name="back_status" value="<?= e($filter['status']) ?>">
                <input type="hidden" name="back_job_id" value="<?= (int)$filter['job_id'] ?>">
                <input type="hidden" name="back_kw" value="<?= e($filter['kw']) ?>">
                <button class="btn btn-sm btn-bad" type="submit">删除这条投递</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>投递列表</h2>
    <?php if (!$rows): ?>
        <div class="empty">暂无投递记录。前台 <a href="<?= e(url('career.php')) ?>" target="_blank">招聘页</a> 收到的简历会出现在这里。</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>编号</th><th>候选人</th><th>应聘职位</th><th>状态</th><th>投递时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="mono small"><?= e($r['sn']) ?></td>
                    <td class="wrap">
                        <strong><?= e($r['name']) ?></strong>
                        <?php if ((int)$r['birth_year'] > 0 || $r['gender']): ?>
                            <span class="muted small"><?= e($r['gender'] ?: '') ?><?= (int)$r['birth_year'] > 0 ? ' · ' . (int)$r['birth_year'] . '年' : '' ?></span>
                        <?php endif; ?>
                        <div class="muted small"><?= e($r['phone']) ?> · <?= e($r['email']) ?></div>
                    </td>
                    <td class="wrap">
                        <?= e($r['job_title']) ?>
                        <?php if ($r['file_path']): ?><div class="muted small">📎 <?= e($r['file_name'] ?: '附件') ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge <?= e(YxCareer::statusBadge((string)$r['status'])) ?>"><?= e(YxCareer::statusName((string)$r['status'])) ?></span></td>
                    <td class="small muted"><?= e(substr((string)$r['created_at'], 0, 16)) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/resumes.php?view=' . (int)$r['id'] . $keepQs)) ?>">详情</a>
                            <?php if ($r['file_path']): ?>
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/resume_file.php?id=' . (int)$r['id'])) ?>" target="_blank">简历</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pager($pg) ?>
    <?php endif; ?>
</div>

<style>
.form-inline{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.form-inline label{margin:0;display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted)}
.form-inline select,.form-inline input{min-width:150px}
.career-message{padding:12px 14px;border:1px solid var(--line);border-radius:8px;background:var(--surface-2);line-height:1.85;font-size:13px}
</style>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
