<?php
/**
 * 优享财务系统 · 新闻公告管理
 *
 * 文章增删改、置顶、发布/草稿切换、浏览量查看与清零。
 * 正文支持「空行分段 + 一行一项列表 + ## 小标题」的轻量格式，前台按此渲染。
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
        $res = YxNews::save($_POST, $id);
    } elseif ($action === 'toggle') {
        $res = YxNews::toggle($id, (string)($_POST['field'] ?? ''));
    } elseif ($action === 'delete') {
        $res = YxNews::remove($id) ? ['ok' => true, 'msg' => '文章已删除'] : ['ok' => false, 'msg' => '文章不存在'];
    } elseif ($action === 'reset_views') {
        $res = YxNews::resetViews($id);
    } elseif ($action === 'save_setting') {
        set_setting('news_show', !empty($_POST['news_show']) ? '1' : '0');
        log_action('news.setting', 'news_show', ['on' => !empty($_POST['news_show'])]);
        $res = ['ok' => true, 'msg' => '前台开关已保存'];
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    $backId = !empty($res['ok']) && $action === 'save' ? (int)($res['id'] ?? $id) : 0;
    redirect(url('admin/news.php' . ($backId > 0 ? '?edit=' . $backId : '')));
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxNews::find($editId) : null;

$filter = [
    'status'   => (string)($_GET['status'] ?? ''),
    'category' => (string)($_GET['cat'] ?? ''),
    'kw'       => trim((string)($_GET['kw'] ?? '')),
];
$total = YxNews::adminCount($filter);
$pg    = paginate($total, 15);
$rows  = YxNews::adminList($filter, $pg['per_page'], $pg['offset']);

$stats = YxNews::adminStats();
$show  = YxNews::isEnabled();

$publishedAt = (string)($editing['published_at'] ?? '');
$publishedAt = $publishedAt !== '' ? substr(str_replace(' ', 'T', $publishedAt), 0, 16) : '';

$pageTitle  = '新闻公告';
$activeMenu = 'news';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="card stat"><span>已发布</span><strong><?= (int)$stats['published'] ?></strong></div>
    <div class="card stat"><span>草稿</span><strong><?= (int)$stats['draft'] ?></strong></div>
    <div class="card stat"><span>置顶</span><strong><?= (int)$stats['top'] ?></strong></div>
    <div class="card stat"><span>累计浏览</span><strong><?= number_format((int)$stats['views']) ?></strong></div>
</div>

<div class="card" style="margin-top:16px">
    <form method="post" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_setting">
        <label class="checkbox" style="margin:0">
            <input type="checkbox" name="news_show" value="1" <?= $show ? 'checked' : '' ?>>
            前台开放「新闻公告中心」（关闭后列表与详情页均返回 404）
        </label>
        <button class="btn btn-primary btn-sm" type="submit">保存</button>
        <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= e(url('news.php')) ?>" target="_blank">预览前台 →</a>
    </form>
</div>

<div class="grid-2" style="margin-top:16px">
    <div class="card">
        <h2><?= $editing ? '编辑文章' : '发布新文章' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <label>标题 * <input type="text" name="title" maxlength="200" required value="<?= e($editing['title'] ?? '') ?>"></label>

            <div class="grid-2">
                <label>分类
                    <select name="category">
                        <?php foreach (YxNews::CATEGORIES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= (string)($editing['category'] ?? 'notice') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>发布时间 <input type="datetime-local" name="published_at" value="<?= e($publishedAt) ?>"></label>
                <label>来源 <span class="muted small">可留空</span>
                    <input type="text" name="source" maxlength="120" value="<?= e($editing['source'] ?? '') ?>" placeholder="如 官方 / 转载媒体">
                </label>
                <label>标签 <span class="muted small">英文逗号分隔</span>
                    <input type="text" name="tags" maxlength="200" value="<?= e($editing['tags'] ?? '') ?>" placeholder="产品, 更新">
                </label>
            </div>

            <label>摘要 <span class="muted small">列表页展示，留空则只显示标题</span>
                <textarea name="summary" rows="2" maxlength="500"><?= e($editing['summary'] ?? '') ?></textarea></label>

            <label>封面图地址 <span class="muted small">可选，填写完整 URL 或 uploads/ 下的相对路径</span>
                <input type="text" name="cover" maxlength="255" value="<?= e($editing['cover'] ?? '') ?>"></label>

            <label>正文 <span class="muted small">空行分段；一行一项自动成列表；以「## 」开头的行为小标题</span>
                <textarea name="content" rows="12"><?= e($editing['content'] ?? '') ?></textarea></label>

            <div class="grid-2">
                <label class="checkbox"><input type="checkbox" name="is_top" value="1" <?= (int)($editing['is_top'] ?? 0) === 1 ? 'checked' : '' ?>> 置顶（列表优先展示）</label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>发布</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>草稿（前台不可见）</option>
                    </select>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '发布文章' ?></button>
                <?php if ($editing): ?>
                    <a class="btn btn-ghost" href="<?= e(url('admin/news.php')) ?>">取消编辑</a>
                    <a class="btn btn-ghost" href="<?= e(url('news_detail.php?id=' . (int)$editing['id'])) ?>" target="_blank">前台预览</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>文章列表（<?= (int)$total ?> 篇）</h2>
        <form method="get" class="form-inline" style="margin-bottom:12px">
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <option value="1" <?= $filter['status'] === '1' ? 'selected' : '' ?>>已发布</option>
                    <option value="0" <?= $filter['status'] === '0' ? 'selected' : '' ?>>草稿</option>
                </select>
            </label>
            <label>分类
                <select name="cat">
                    <option value="">全部</option>
                    <?php foreach (YxNews::CATEGORIES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $filter['category'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>关键词 <input type="search" name="kw" value="<?= e($filter['kw']) ?>"></label>
            <button class="btn btn-primary btn-sm" type="submit">筛选</button>
            <?php if ($filter['status'] !== '' || $filter['category'] !== '' || $filter['kw'] !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/news.php')) ?>">重置</a>
            <?php endif; ?>
        </form>

        <?php if (!$rows): ?>
            <div class="empty">暂无文章。左侧表单发布第一篇公告后，前台「新闻公告中心」自动开放。</div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>标题</th><th>浏览</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $n): ?>
                    <tr>
                        <td class="wrap">
                            <strong>
                                <?php if ((int)$n['is_top'] === 1): ?><span class="badge badge-bad">置顶</span> <?php endif; ?>
                                <?= e($n['title']) ?>
                            </strong>
                            <div class="muted small">
                                <span class="badge <?= e(YxNews::categoryBadge((string)$n['category'])) ?>"><?= e(YxNews::categoryName((string)$n['category'])) ?></span>
                                <?= e(substr((string)($n['published_at'] ?: $n['created_at']), 0, 16)) ?>
                            </div>
                        </td>
                        <td class="mono"><?= number_format((int)$n['views']) ?></td>
                        <td><?= (int)$n['status'] === 1 ? '<span class="badge badge-ok">已发布</span>' : '<span class="badge badge-mute">草稿</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/news.php?edit=' . (int)$n['id'])) ?>">编辑</a>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="field" value="is_top">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= (int)$n['is_top'] === 1 ? '取消置顶' : '置顶' ?></button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="field" value="status">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= (int)$n['status'] === 1 ? '转草稿' : '发布' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认删除该文章？不可恢复。')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button class="btn btn-sm btn-bad" type="submit">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?= pager($pg) ?>
        <?php endif; ?>
    </div>
</div>

<style>
.form-inline{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.form-inline label{margin:0;display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted)}
.form-inline select,.form-inline input{min-width:140px}
</style>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
