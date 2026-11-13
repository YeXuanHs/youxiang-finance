<?php
/**
 * 优享财务系统 · 合作伙伴 / 服务网络管理
 *
 * 一条记录 = 一家合作机构：名称 + Logo + 说明 + 可选链接。
 * 图片上传到 uploads/partner/，保存 / 删除时同步清理旧文件。
 * 前台展示位置：用户中心 → 合作伙伴（user/partners.php）。
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
        $old = $id > 0 ? YxPartner::find($id) : null;
        if ($id > 0 && !$old) {
            flash('该合作伙伴不存在或已被删除', 'error');
            redirect(url('admin/partners.php'));
        }

        $data = [
            'name'        => trim((string)($_POST['name'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'url'         => trim((string)($_POST['url'] ?? '')),
            'sort'        => (int)($_POST['sort'] ?? 0),
            'status'      => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at'  => now(),
        ];

        $err = '';
        if ($data['name'] === '') {
            $err = '请填写机构名称';
        } elseif ($data['url'] !== '' && YxPartner::href($data['url']) === '') {
            $err = '链接格式无效（支持 http(s) 外链或站内相对路径）';
        }
        if ($err !== '') {
            flash($err, 'error');
            redirect(url('admin/partners.php' . ($id > 0 ? '?edit=' . $id : '')));
        }

        $logo = (string)($old['logo'] ?? '');

        // 1) 移除现有图片
        if (!empty($_POST['remove_logo']) && $logo !== '') {
            YxPartner::removeLogoFile($logo);
            $logo = '';
        }

        // 2) 上传新图片（覆盖旧图）
        if (!empty($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = yx_upload($_FILES['logo'], [
                'sub'      => 'partner',
                'prefix'   => 'partner',
                'exts'     => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                'mimes'    => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                'max_size' => 2 * 1024 * 1024,
            ]);
            if (!$up['ok']) {
                flash('图片上传失败：' . $up['msg'], 'error');
                redirect(url('admin/partners.php' . ($id > 0 ? '?edit=' . $id : '')));
            }
            if ($logo !== '') {
                YxPartner::removeLogoFile($logo);
            }
            $logo = $up['path'];
        }
        $data['logo'] = $logo;

        if ($id > 0) {
            YxDb::update('partner', $data, 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '合作伙伴已更新'];
        } else {
            $data['created_at'] = now();
            YxDb::insert('partner', $data);
            $res = ['ok' => true, 'msg' => '合作伙伴已添加'];
        }
        log_action('partner.save', $data['name']);
    } elseif ($action === 'delete') {
        $row = YxPartner::find((int)($_POST['id'] ?? 0));
        if ($row) {
            YxDb::delete('partner', 'id = :id', ['id' => (int)$row['id']]);
            YxPartner::removeLogoFile((string)$row['logo']);
            log_action('partner.delete', (string)$row['name']);
            $res = ['ok' => true, 'msg' => '合作伙伴已删除'];
        } else {
            $res = ['ok' => false, 'msg' => '该合作伙伴不存在'];
        }
    } elseif ($action === 'toggle') {
        $row = YxPartner::find((int)($_POST['id'] ?? 0));
        if ($row) {
            $next = (int)$row['status'] === 1 ? 0 : 1;
            YxDb::update('partner', ['status' => $next, 'updated_at' => now()], 'id = :id', ['id' => (int)$row['id']]);
            log_action('partner.toggle', (string)$row['name']);
            $res = ['ok' => true, 'msg' => $next === 1 ? '已在前台显示' : '已在前台隐藏'];
        } else {
            $res = ['ok' => false, 'msg' => '该合作伙伴不存在'];
        }
    } elseif ($action === 'move') {
        $id   = (int)($_POST['id'] ?? 0);
        $dir  = ($_POST['dir'] ?? '') === 'down' ? 'down' : 'up';
        $rows = YxPartner::all();
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
            $tmp           = $rows[$pos];
            $rows[$pos]    = $rows[$target];
            $rows[$target] = $tmp;
            foreach ($rows as $i => $r) {
                $want = ($i + 1) * 10;
                if ((int)$r['sort'] !== $want) {
                    YxDb::update('partner', ['sort' => $want], 'id = :id', ['id' => (int)$r['id']]);
                }
            }
            log_action('partner.move', $id . ' ' . $dir);
            $res = ['ok' => true, 'msg' => '排序已调整'];
        }
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/partners.php'));
}

$rows    = YxPartner::all();
$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxPartner::find($editId) : null;
$front   = url('user/partners.php');
$editImg = $editing ? YxPartner::logoUrl($editing) : '';

$pageTitle  = '合作伙伴';
$activeMenu = 'partners';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="active" href="<?= e(url('admin/partners.php')) ?>">合作伙伴（<?= count($rows) ?>）</a>
    <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= e($front) ?>" target="_blank">预览前台页面 →</a>
</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑合作伙伴' : '新增合作伙伴' ?></h2>
        <p class="muted small">
            Logo 建议使用透明底 PNG 或正方形图片（≤2 MB），前台会等比缩放展示；
            说明文字与链接可留空，留空的链接不会生成跳转。前台展示顺序按排序值从小到大。
        </p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <label>机构名称 <span class="muted small">必填</span>
                <input type="text" name="name" maxlength="120" value="<?= e($editing['name'] ?? '') ?>" placeholder="例如：某某云计算有限公司">
            </label>
            <label>说明文字（可留空）
                <textarea name="description" style="min-height:90px" placeholder="一句话介绍合作内容或服务范围"><?= e($editing['description'] ?? '') ?></textarea>
            </label>
            <label>机构链接（可留空）
                <input type="text" name="url" maxlength="255" value="<?= e($editing['url'] ?? '') ?>" placeholder="https://… 或站内相对路径">
            </label>

            <label>Logo 图片
                <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,.gif">
            </label>
            <?php if ($editImg !== ''): ?>
                <div style="margin:8px 0 14px">
                    <img src="<?= e($editImg) ?>" alt="当前 Logo" style="max-width:220px;max-height:96px;object-fit:contain;border-radius:8px;border:1px solid var(--line);background:#fff;padding:8px">
                    <label class="checkbox" style="margin-top:8px">
                        <input type="checkbox" name="remove_logo" value="1"> 删除当前 Logo（改用文字占位）
                    </label>
                </div>
            <?php endif; ?>

            <div class="grid-2">
                <label>排序（小的在前） <input type="number" name="sort" value="<?= e($editing['sort'] ?? ((count($rows) + 1) * 10)) ?>"></label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>显示</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>隐藏</option>
                    </select>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '添加合作伙伴' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/partners.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>当前合作伙伴（<?= count($rows) ?> 家）</h2>
        <p class="muted small">前台只展示「显示」的记录，按排序值从小到大排列。全部隐藏时，前台页面展示空状态提示。</p>
        <?php if (!$rows): ?>
            <div class="empty">还没有合作伙伴，填写左侧表单添加第一家。</div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>排序</th><th>机构</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $img = YxPartner::logoUrl($r); ?>
                    <tr>
                        <td><?= (int)$r['sort'] ?></td>
                        <td class="wrap">
                            <?php if ($img !== ''): ?>
                                <img src="<?= e($img) ?>" alt="" style="width:52px;height:52px;object-fit:contain;border-radius:6px;border:1px solid var(--line);background:#fff;vertical-align:middle;margin-right:10px">
                            <?php else: ?>
                                <span class="badge badge-mute" style="vertical-align:middle"><?= e(YxPartner::initial($r)) ?></span>
                            <?php endif; ?>
                            <strong><?= e($r['name']) ?></strong>
                            <?php if (trim((string)$r['description']) !== ''): ?>
                                <br><span class="muted small"><?= e(mb_substr((string)$r['description'], 0, 60)) ?>…</span>
                            <?php endif; ?>
                            <?php if (trim((string)$r['url']) !== ''): ?>
                                <br><span class="mono small muted"><?= e($r['url']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$r['status'] === 1 ? '<span class="badge badge-ok">显示</span>' : '<span class="badge badge-mute">隐藏</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/partners.php?edit=' . (int)$r['id'])) ?>">编辑</a>
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
                                    <button class="btn btn-sm" type="submit"><?= (int)$r['status'] === 1 ? '隐藏' : '显示' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认删除该合作伙伴？Logo 会一并删除。')">
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
