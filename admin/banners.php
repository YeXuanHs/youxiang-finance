<?php
/**
 * 优享财务系统 · 首页横幅管理
 *
 * 一条记录 = 一张幻灯：图片或渐变底色 + 文案 + 最多两个按钮。
 * 图片上传到 uploads/banner/，保存 / 删除时同步清理旧文件。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save') {
        $id  = (int)($_POST['id'] ?? 0);
        $old = $id > 0 ? YxBanner::find($id) : null;
        if ($id > 0 && !$old) {
            flash('横幅不存在或已被删除', 'error');
            redirect(url('admin/banners.php'));
        }

        $bg = (string)($_POST['bg'] ?? 'blue');
        if (!YxBanner::isKnownPalette($bg)) {
            $bg = 'blue';
        }

        $data = [
            'tag'        => trim((string)($_POST['tag'] ?? '')),
            'title'      => trim((string)($_POST['title'] ?? '')),
            'subtitle'   => trim((string)($_POST['subtitle'] ?? '')),
            'bg'         => $bg,
            'btn1_text'  => trim((string)($_POST['btn1_text'] ?? '')),
            'btn1_url'   => trim((string)($_POST['btn1_url'] ?? '')),
            'btn2_text'  => trim((string)($_POST['btn2_text'] ?? '')),
            'btn2_url'   => trim((string)($_POST['btn2_url'] ?? '')),
            'sort'       => (int)($_POST['sort'] ?? 0),
            'status'     => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at' => now(),
        ];

        // 按钮必须「文字 + 链接」成对出现，链接需为安全地址
        $err = '';
        foreach ([1, 2] as $n) {
            $text = $data['btn' . $n . '_text'];
            $raw  = $data['btn' . $n . '_url'];
            if ($text === '' && $raw === '') {
                continue;
            }
            if ($text === '' || $raw === '') {
                $err = '按钮 ' . $n . '：文字和链接需要同时填写';
                break;
            }
            if (YxBanner::href($raw) === '') {
                $err = '按钮 ' . $n . '：链接格式无效（支持 http(s) 外链或站内相对路径）';
                break;
            }
        }
        if ($err === '') {
            if ($data['title'] === '' && $data['subtitle'] === '') {
                $err = '标题和副标题不能同时为空';
            }
        }
        if ($err !== '') {
            flash($err, 'error');
            redirect(url('admin/banners.php' . ($id > 0 ? '?edit=' . $id : '')));
        }

        $image = (string)($old['image'] ?? '');

        // 1) 移除现有图片
        if (!empty($_POST['remove_image']) && $image !== '') {
            YxBanner::removeImageFile($image);
            $image = '';
        }

        // 2) 上传新图片（覆盖旧图）
        if (!empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = yx_upload($_FILES['image'], [
                'sub'      => 'banner',
                'prefix'   => 'banner',
                'exts'     => ['jpg', 'jpeg', 'png', 'webp'],
                'mimes'    => ['image/jpeg', 'image/png', 'image/webp'],
                'max_size' => 4 * 1024 * 1024,
            ]);
            if (!$up['ok']) {
                flash('图片上传失败：' . $up['msg'], 'error');
                redirect(url('admin/banners.php' . ($id > 0 ? '?edit=' . $id : '')));
            }
            if ($image !== '') {
                YxBanner::removeImageFile($image);
            }
            $image = $up['path'];
        }
        $data['image'] = $image;

        if ($id > 0) {
            YxDb::update('banner', $data, 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '横幅已更新'];
        } else {
            $data['created_at'] = now();
            YxDb::insert('banner', $data);
            $res = ['ok' => true, 'msg' => '横幅已添加'];
        }
        log_action('banner.save', $data['title'] !== '' ? $data['title'] : ('#' . $id));
    } elseif ($action === 'delete') {
        $row = YxBanner::find((int)($_POST['id'] ?? 0));
        if ($row) {
            YxDb::delete('banner', 'id = :id', ['id' => (int)$row['id']]);
            YxBanner::removeImageFile((string)$row['image']);
            log_action('banner.delete', (string)$row['title']);
            $res = ['ok' => true, 'msg' => '横幅已删除'];
        } else {
            $res = ['ok' => false, 'msg' => '横幅不存在'];
        }
    } elseif ($action === 'toggle') {
        $row = YxBanner::find((int)($_POST['id'] ?? 0));
        if ($row) {
            $next = (int)$row['status'] === 1 ? 0 : 1;
            YxDb::update('banner', ['status' => $next, 'updated_at' => now()], 'id = :id', ['id' => (int)$row['id']]);
            log_action('banner.toggle', (string)$row['title']);
            $res = ['ok' => true, 'msg' => $next === 1 ? '横幅已启用' : '横幅已停用'];
        } else {
            $res = ['ok' => false, 'msg' => '横幅不存在'];
        }
    } elseif ($action === 'move') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'down' ? 'down' : 'up';
        $rows = YxBanner::all();
        $pos  = null;
        foreach ($rows as $i => $r) {
            if ((int)$r['id'] === $id) {
                $pos = $i;
                break;
            }
        }
        $target = $pos === null ? null : ($dir === 'up' ? $pos - 1 : $pos + 1);
        if ($pos === null || $target === null || $target < 0 || $target >= count($rows)) {
            $res = ['ok' => false, 'msg' => '已经到顶或到底了'];
        } else {
            $tmp         = $rows[$pos];
            $rows[$pos]  = $rows[$target];
            $rows[$target] = $tmp;
            foreach ($rows as $i => $r) {
                $want = ($i + 1) * 10;
                if ((int)$r['sort'] !== $want) {
                    YxDb::update('banner', ['sort' => $want], 'id = :id', ['id' => (int)$r['id']]);
                }
            }
            log_action('banner.move', $id . ' ' . $dir);
            $res = ['ok' => true, 'msg' => '排序已调整'];
        }
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/banners.php'));
}

// 默认横幅只在安装 / 升级时写入（seedDefaults），后台不再自动补种，避免删空后又冒出来
$rows    = YxBanner::all();
$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxBanner::find($editId) : null;
$front   = url('index.php');
$editImg = $editing ? YxBanner::imageUrl($editing) : '';

$pageTitle  = '首页横幅';
$activeMenu = 'banners';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="active" href="<?= e(url('admin/banners.php')) ?>">横幅管理（<?= count($rows) ?>）</a>
    <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= e($front) ?>" target="_blank">预览首页 →</a>
</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑横幅' : '新增横幅' ?></h2>
        <p class="muted small">
            图片建议 1920×560 以上的横图（JPG / PNG / WebP，≤4 MB），文案会叠加在图片上方；
            不上传图片时使用所选渐变底色。按钮可留空，也可只填一个。
        </p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <label>角标文字（可留空）
                <input type="text" name="tag" maxlength="80" value="<?= e($editing['tag'] ?? '') ?>" placeholder="例如：专业版 · 正版授权可查">
            </label>
            <label>主标题
                <input type="text" name="title" maxlength="160" value="<?= e($editing['title'] ?? '') ?>" placeholder="留空则该行不显示">
            </label>
            <label>副标题 / 说明
                <textarea name="subtitle" maxlength="500" style="min-height:90px" placeholder="留空则该行不显示"><?= e($editing['subtitle'] ?? '') ?></textarea>
            </label>

            <div class="grid-2">
                <label>按钮 1 文字 <input type="text" name="btn1_text" maxlength="40" value="<?= e($editing['btn1_text'] ?? '') ?>"></label>
                <label>按钮 1 链接 <input type="text" name="btn1_url" maxlength="255" value="<?= e($editing['btn1_url'] ?? '') ?>" placeholder="https://… 或 store/index.php"></label>
                <label>按钮 2 文字 <input type="text" name="btn2_text" maxlength="40" value="<?= e($editing['btn2_text'] ?? '') ?>"></label>
                <label>按钮 2 链接 <input type="text" name="btn2_url" maxlength="255" value="<?= e($editing['btn2_url'] ?? '') ?>" placeholder="可留空"></label>
            </div>

            <label>背景图
                <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
            </label>
            <?php if ($editImg !== ''): ?>
                <div style="margin:8px 0 14px">
                    <img src="<?= e($editImg) ?>" alt="当前背景图" style="max-width:100%;border-radius:8px;border:1px solid var(--line)">
                    <label class="checkbox" style="margin-top:8px">
                        <input type="checkbox" name="remove_image" value="1"> 删除当前背景图（改用渐变底色）
                    </label>
                </div>
            <?php endif; ?>

            <label>渐变底色（未上传图片时生效）
                <select name="bg">
                    <?php foreach (YxBanner::PALETTES as $key => $meta): ?>
                        <option value="<?= e($key) ?>" <?= (string)($editing['bg'] ?? 'blue') === $key ? 'selected' : '' ?>><?= e($meta[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="grid-2">
                <label>排序（小的在前） <input type="number" name="sort" value="<?= e($editing['sort'] ?? ((count($rows) + 1) * 10)) ?>"></label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '添加横幅' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/banners.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>当前横幅（<?= count($rows) ?> 条）</h2>
        <p class="muted small">前台只展示「启用」的横幅，按排序值从小到大轮播。全部停用时首页使用内置兜底幻灯。</p>
        <?php if (!$rows): ?>
            <div class="empty">还没有横幅，填写左侧表单添加第一张。</div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>排序</th><th>横幅</th><th>底色</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $img = YxBanner::imageUrl($r); ?>
                    <tr>
                        <td><?= (int)$r['sort'] ?></td>
                        <td class="wrap">
                            <?php if ($img !== ''): ?>
                                <img src="<?= e($img) ?>" alt="" style="width:120px;height:44px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px">
                            <?php endif; ?>
                            <?php if (trim((string)$r['tag']) !== ''): ?><span class="badge badge-mute"><?= e($r['tag']) ?></span><?php endif; ?>
                            <strong><?= e($r['title']) ?></strong>
                            <?php if (trim((string)$r['subtitle']) !== ''): ?>
                                <br><span class="muted small"><?= e(mb_substr((string)$r['subtitle'], 0, 46)) ?>…</span>
                            <?php endif; ?>
                            <?php $btnTexts = array_column(YxBanner::buttons($r), 'text'); ?>
                            <?php if ($btnTexts): ?>
                                <br><span class="muted small">按钮：<?= e(implode(' / ', $btnTexts)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="muted small"><?= e(YxBanner::paletteName((string)$r['bg'])) ?></td>
                        <td><?= (int)$r['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/banners.php?edit=' . (int)$r['id'])) ?>">编辑</a>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="move">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="dir" value="up">
                                    <button class="btn btn-sm" type="submit" title="上移">↑</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="move">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="dir" value="down">
                                    <button class="btn btn-sm" type="submit" title="下移">↓</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= (int)$r['status'] === 1 ? '停用' : '启用' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认删除该横幅？背景图会一并删除。')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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
