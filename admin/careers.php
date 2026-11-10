<?php
/**
 * 优享财务系统 · 招聘职位管理
 *
 * 维护「加入我们」与 career.php 展示的在招职位；已有投递记录的职位只允许下线，不允许删除。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save') {
        $res = YxCareer::saveJob($_POST, $id);
    } elseif ($action === 'toggle') {
        $res = YxCareer::toggleJob($id);
    } elseif ($action === 'delete') {
        $res = YxCareer::removeJob($id);
    } elseif ($action === 'save_setting') {
        set_setting('career_apply_enabled', !empty($_POST['career_apply_enabled']) ? '1' : '0');
        log_action('career.setting', 'apply_enabled', ['on' => !empty($_POST['career_apply_enabled'])]);
        $res = ['ok' => true, 'msg' => '投递开关已保存'];
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/careers.php'));
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxCareer::job($editId) : null;
$jobs    = YxCareer::allJobs();

$stats = YxCareer::stats();
$applyOn = setting('career_apply_enabled', '1') === '1';

$pageTitle  = '招聘管理';
$activeMenu = 'careers';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="card stat"><span>在招职位</span><strong><?= count(YxCareer::jobs()) ?></strong></div>
    <div class="card stat"><span>今日投递</span><strong><?= (int)$stats['today'] ?></strong></div>
    <div class="card stat"><span>待筛选</span><strong><?= (int)$stats['new'] ?></strong></div>
    <div class="card stat"><span>进行中</span><strong><?= (int)$stats['open'] ?></strong></div>
    <div class="card stat"><span>累计投递</span><strong><?= (int)$stats['total'] ?></strong></div>
</div>

<div class="cat-bar" style="margin-top:16px">
    <a class="active" href="<?= e(url('admin/careers.php')) ?>">职位管理</a>
    <a href="<?= e(url('admin/resumes.php')) ?>">简历投递</a>
    <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= e(url('career.php')) ?>" target="_blank">预览前台招聘页 →</a>
</div>

<div class="card">
    <h2>投递设置</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_setting">
        <label class="checkbox">
            <input type="checkbox" name="career_apply_enabled" value="1" <?= $applyOn ? 'checked' : '' ?>>
            开放在线投递简历（关闭后前台仅展示职位，投递表单提示通过工单联系）
        </label>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">保存设置</button></div>
    </form>
</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑职位：' . e($editing['title']) : '新增职位' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <label>职位名称 * <input type="text" name="title" maxlength="120" required
                   value="<?= e($editing['title'] ?? '') ?>" placeholder="如 前端开发工程师"></label>

            <div class="grid-2">
                <label>职位方向
                    <input type="text" name="category" maxlength="40" list="jobCats"
                           value="<?= e($editing['category'] ?? '') ?>" placeholder="如 研发团队">
                    <datalist id="jobCats">
                        <?php foreach (YxCareer::categories() as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
                    </datalist>
                </label>
                <label>工作性质
                    <select name="job_type">
                        <?php foreach (YxCareer::JOB_TYPES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= (string)($editing['job_type'] ?? 'fulltime') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>工作城市 <input type="text" name="city" maxlength="40" value="<?= e($editing['city'] ?? '') ?>" placeholder="如 深圳 / 远程"></label>
                <label>薪资范围 <input type="text" name="salary" maxlength="40" value="<?= e($editing['salary'] ?? '') ?>" placeholder="如 15-25K·14薪，可留空显示面议"></label>
                <label>经验要求 <input type="text" name="experience" maxlength="40" value="<?= e($editing['experience'] ?? '') ?>" placeholder="如 3-5 年"></label>
                <label>学历要求 <input type="text" name="education" maxlength="40" value="<?= e($editing['education'] ?? '') ?>" placeholder="如 本科及以上"></label>
                <label>招聘人数 <input type="number" name="headcount" min="0" max="999" value="<?= (int)($editing['headcount'] ?? 1) ?>"></label>
                <label>排序（小的在前） <input type="number" name="sort" value="<?= (int)($editing['sort'] ?? 0) ?>"></label>
            </div>

            <label>简历接收邮箱 <span class="muted small">留空则使用「关于我们」中配置的联系邮箱</span>
                <input type="email" name="contact" maxlength="120" value="<?= e($editing['contact'] ?? '') ?>" placeholder="hr@example.com"></label>

            <label>岗位职责 <span class="muted small">一行一项，前台自动渲染为列表</span>
                <textarea name="description" rows="5"><?= e($editing['description'] ?? '') ?></textarea></label>

            <label>任职要求 <span class="muted small">一行一项</span>
                <textarea name="requirement" rows="5"><?= e($editing['requirement'] ?? '') ?></textarea></label>

            <label>状态
                <select name="status">
                    <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>招聘中（前台展示）</option>
                    <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停止招聘（前台隐藏）</option>
                </select>
            </label>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '添加职位' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/careers.php')) ?>">取消编辑</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>职位列表（<?= count($jobs) ?> 个）</h2>
        <?php if (!$jobs): ?>
            <div class="empty">暂无职位。添加后前台「加入我们」与招聘页自动展示。</div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>排序</th><th>职位</th><th>投递</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($jobs as $j): ?>
                    <?php $cnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('resume') . ' WHERE job_id = ?', [(int)$j['id']]); ?>
                    <tr>
                        <td><?= (int)$j['sort'] ?></td>
                        <td class="wrap">
                            <strong><?= e($j['title']) ?></strong>
                            <div class="muted small">
                                <?= e($j['category'] ?: '其他') ?> ·
                                <?= e(YxCareer::jobTypeName((string)$j['job_type'])) ?>
                                <?php if (trim((string)$j['city']) !== ''): ?> · <?= e($j['city']) ?><?php endif; ?>
                                · <?= e(trim((string)$j['salary']) !== '' ? $j['salary'] : '面议') ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($cnt > 0): ?>
                                <a href="<?= e(url('admin/resumes.php?job_id=' . (int)$j['id'])) ?>"><?= $cnt ?> 份</a>
                            <?php else: ?><span class="muted small">0</span><?php endif; ?>
                        </td>
                        <td><?= (int)$j['status'] === 1 ? '<span class="badge badge-ok">招聘中</span>' : '<span class="badge badge-mute">已下线</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/careers.php?edit=' . (int)$j['id'])) ?>">编辑</a>
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('career.php#job-' . (int)$j['id'])) ?>" target="_blank">查看</a>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= (int)$j['status'] === 1 ? '下线' : '上线' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认删除该职位？已有投递记录的职位无法删除。')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                    <button class="btn btn-sm btn-bad" type="submit">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
