<?php
/**
 * 优享财务系统 · 关于我们（内容编辑）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save_scalars') {
        foreach (YxAbout::SCALARS as $key => $meta) {
            if (array_key_exists($key, $_POST)) {
                set_setting($key, trim((string)$_POST[$key]));
            }
        }
        set_setting('about_show', !empty($_POST['about_show']) ? '1' : '0');
        log_action('about.save_scalars');
        $res = ['ok' => true, 'msg' => '公司信息已保存'];
    } elseif ($action === 'save_item') {
        $id      = (int)($_POST['id'] ?? 0);
        $section = (string)($_POST['section'] ?? '');
        if (!YxAbout::isKnownSection($section)) {
            $res = ['ok' => false, 'msg' => '未知的内容分组'];
        } else {
            $data = [
                'section'  => $section,
                'sort'     => (int)($_POST['sort'] ?? 0),
                'title'    => trim((string)($_POST['title'] ?? '')),
                'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
                'content'  => trim((string)($_POST['content'] ?? '')),
                'icon'     => trim((string)($_POST['icon'] ?? '')),
                'link'     => trim((string)($_POST['link'] ?? '')),
                'status'   => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            ];
            if ($data['title'] === '' && $data['subtitle'] === '') {
                $res = ['ok' => false, 'msg' => '标题不能为空'];
            } elseif ($id > 0) {
                YxDb::update('about_item', $data, 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '内容项已更新'];
            } else {
                $data['created_at'] = now();
                YxDb::insert('about_item', $data);
                $res = ['ok' => true, 'msg' => '内容项已添加'];
            }
            log_action('about.save_item', $section);
        }
    } elseif ($action === 'delete_item') {
        YxDb::delete('about_item', 'id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        log_action('about.delete_item', (string)($_POST['id'] ?? ''));
        $res = ['ok' => true, 'msg' => '内容项已删除'];
    } elseif ($action === 'toggle_item') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('about_item') . ' WHERE id = ?', [$id]);
        if ($row) {
            YxDb::update('about_item', ['status' => (int)$row['status'] === 1 ? 0 : 1], 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '显示状态已切换'];
        }
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/about.php?tab=' . urlencode((string)($_POST['tab'] ?? ($_POST['section'] ?? 'info')))));
}

$tab     = (string)($_GET['tab'] ?? 'info');
if ($tab !== 'info' && !YxAbout::isKnownSection($tab)) {
    $tab = 'info';
}
$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('about_item') . ' WHERE id = ?', [$editId]) : null;
$show    = YxAbout::enabled();
$front   = url('about.php');

$pageTitle  = '关于我们';
$activeMenu = 'about';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="<?= $tab === 'info' ? 'active' : '' ?>" href="<?= e(url('admin/about.php?tab=info')) ?>">公司信息</a>
    <?php foreach (YxAbout::SECTIONS as $key => $sec): ?>
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="<?= e(url('admin/about.php?tab=' . urlencode($key))) ?>">
            <?= e($sec[0]) ?>（<?= count(YxAbout::adminItems($key)) ?>）
        </a>
    <?php endforeach; ?>
    <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= e($front) ?>" target="_blank">预览前台页面 →</a>
</div>

<?php if ($tab === 'info'): ?>
    <div class="card">
        <h2>公司信息</h2>
        <p class="muted small">
            这些内容会展示在前台 <a href="<?= e($front) ?>" target="_blank">关于我们</a> 页面。
            <strong>留空的字段与空的分组会自动跳过、不显示</strong>，所以你可以先只填简介，其余以后补。
            涉及公司事实（成立时间、客户数量、合作伙伴等）请按实际情况填写。
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_scalars">
            <input type="hidden" name="tab" value="info">

            <label class="checkbox" style="margin-bottom:16px">
                <input type="checkbox" name="about_show" value="1" <?= $show ? 'checked' : '' ?>>
                在前台开放「关于我们」页面（取消勾选则该页面返回 404）
            </label>

            <div class="grid-2">
                <?php foreach (YxAbout::SCALARS as $key => $meta): ?>
                    <?php if ($meta[1] === 'textarea'): ?>
                        <label style="grid-column:1/-1">
                            <?= e($meta[0]) ?><?= $meta[2] !== '' ? ' <span class="muted small">' . e($meta[2]) . '</span>' : '' ?>
                            <textarea name="<?= e($key) ?>" style="min-height:110px"><?= e(YxAbout::get($key)) ?></textarea>
                        </label>
                    <?php else: ?>
                        <label>
                            <?= e($meta[0]) ?><?= $meta[2] !== '' ? ' <span class="muted small">' . e($meta[2]) . '</span>' : '' ?>
                            <input type="text" name="<?= e($key) ?>" value="<?= e(YxAbout::get($key)) ?>">
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存公司信息</button>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php $sec = YxAbout::SECTIONS[$tab]; ?>
    <div class="grid-2">
        <div class="card">
            <h2><?= $editing ? '编辑：' . e($editing['title'] ?: $editing['subtitle']) : '新增' . e($sec[0]) . '内容项' ?></h2>
            <p class="muted small"><?= e($sec[2]) ?></p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="section" value="<?= e($tab) ?>">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                <?php foreach ($sec[1] as $f): ?>
                    <?php
                    $label = ['icon' => '图标（一个字符或 emoji，可留空）', 'title' => '标题 / 数值 / 名称',
                              'subtitle' => '副标题（年份等）', 'content' => '正文说明', 'link' => '链接地址（可留空）'][$f] ?? $f;
                    $val = $editing[$f] ?? '';
                    ?>
                    <?php if ($f === 'content'): ?>
                        <label><?= e($label) ?><textarea name="content" style="min-height:90px"><?= e($val) ?></textarea></label>
                    <?php else: ?>
                        <label><?= e($label) ?><input type="text" name="<?= e($f) ?>" value="<?= e($val) ?>"></label>
                    <?php endif; ?>
                <?php endforeach; ?>

                <div class="grid-2">
                    <label>排序（小的在前） <input type="number" name="sort" value="<?= e($editing['sort'] ?? 0) ?>"></label>
                    <label>状态
                        <select name="status">
                            <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>显示</option>
                            <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>隐藏</option>
                        </select>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '添加' ?></button>
                    <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/about.php?tab=' . urlencode($tab))) ?>">取消</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h2><?= e($sec[0]) ?>（<?= count(YxAbout::adminItems($tab)) ?> 条）</h2>
            <?php $items = YxAbout::adminItems($tab); ?>
            <?php if (!$items): ?>
                <div class="empty">暂无内容。留空则该板块在前台不显示。</div>
            <?php else: ?>
                <div class="table-wrap"><table class="data">
                    <thead><tr><th>排序</th><th>内容</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= (int)$it['sort'] ?></td>
                            <td class="wrap">
                                <?php if ($it['icon']): ?><span class="badge badge-mute"><?= e($it['icon']) ?></span> <?php endif; ?>
                                <strong><?= e($it['title']) ?></strong>
                                <?php if ($it['subtitle']): ?><span class="muted small">（<?= e($it['subtitle']) ?>）</span><?php endif; ?>
                                <?php if (trim((string)$it['content']) !== ''): ?>
                                    <br><span class="muted small"><?= e(mb_substr((string)$it['content'], 0, 60)) ?>…</span>
                                <?php endif; ?>
                                <?php if (trim((string)$it['link']) !== ''): ?>
                                    <br><span class="mono small muted"><?= e($it['link']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$it['status'] === 1 ? '<span class="badge badge-ok">显示</span>' : '<span class="badge badge-mute">隐藏</span>' ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/about.php?tab=' . urlencode($tab) . '&edit=' . (int)$it['id'])) ?>">编辑</a>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_item">
                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                        <button class="btn btn-sm" type="submit"><?= (int)$it['status'] === 1 ? '隐藏' : '显示' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('确认删除该内容项？')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
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
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
