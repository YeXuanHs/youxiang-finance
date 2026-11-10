<?php
/**
 * 优享财务系统 · 展示广告位管理
 *
 * 两级结构：
 *   广告位（yx_ad_slot）= 一块投放区域，决定位置（首页 / 展示页）与版式（横幅 / 商品卡）
 *   素材（yx_ad_item）  = 广告位里的一条内容，按排序值升序展示，仅「启用」的前台可见
 *
 * 图片统一上传到 uploads/ad/，保存 / 删除时同步清理旧文件。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save_slot') {
        $id  = (int)($_POST['id'] ?? 0);
        $old = $id > 0 ? YxAd::findSlot($id) : null;
        if ($id > 0 && !$old) {
            flash('广告位不存在或已被删除', 'error');
            redirect(url('admin/ads.php'));
        }

        $position = (string)($_POST['position'] ?? 'goods');
        $layout   = (string)($_POST['layout'] ?? 'goods');
        if (!YxAd::isKnownPosition($position)) {
            $position = 'goods';
        }
        if (!YxAd::isKnownLayout($layout)) {
            $layout = 'goods';
        }

        $data = [
            'name'       => trim((string)($_POST['name'] ?? '')),
            'position'   => $position,
            'layout'     => $layout,
            'subtitle'   => trim((string)($_POST['subtitle'] ?? '')),
            'sort'       => (int)($_POST['sort'] ?? 0),
            'status'     => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at' => now(),
        ];

        if ($data['name'] === '') {
            flash('广告位名称不能为空', 'error');
            redirect(url('admin/ads.php' . ($id > 0 ? '?edit_slot=' . $id : '')));
        }

        if ($id > 0) {
            YxDb::update('ad_slot', $data, 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '广告位已更新'];
        } else {
            $data['created_at'] = now();
            $id  = YxDb::insert('ad_slot', $data);
            $res = ['ok' => true, 'msg' => '广告位已添加，可在下方为该广告位添加素材'];
        }
        log_action('ad.slot.save', $data['name'] . ' #' . $id);
    } elseif ($action === 'delete_slot') {
        $row = YxAd::findSlot((int)($_POST['id'] ?? 0));
        if (!$row) {
            $res = ['ok' => false, 'msg' => '广告位不存在'];
        } else {
            foreach (YxAd::items((int)$row['id'], false) as $item) {
                YxAd::removeImageFile((string)$item['image']);
            }
            YxDb::delete('ad_item', 'slot_id = :sid', ['sid' => (int)$row['id']]);
            YxDb::delete('ad_slot', 'id = :id', ['id' => (int)$row['id']]);
            log_action('ad.slot.delete', (string)$row['name']);
            $res = ['ok' => true, 'msg' => '广告位及其素材已删除'];
        }
    } elseif ($action === 'toggle_slot') {
        $row = YxAd::findSlot((int)($_POST['id'] ?? 0));
        if (!$row) {
            $res = ['ok' => false, 'msg' => '广告位不存在'];
        } else {
            $next = (int)$row['status'] === 1 ? 0 : 1;
            YxDb::update('ad_slot', ['status' => $next, 'updated_at' => now()], 'id = :id', ['id' => (int)$row['id']]);
            log_action('ad.slot.toggle', (string)$row['name']);
            $res = ['ok' => true, 'msg' => $next === 1 ? '广告位已启用' : '广告位已停用'];
        }
    } elseif ($action === 'save_item') {
        $id     = (int)($_POST['id'] ?? 0);
        $old    = $id > 0 ? YxAd::findItem($id) : null;
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $slot   = YxAd::findSlot($slotId);
        if ($id > 0 && !$old) {
            flash('素材不存在或已被删除', 'error');
            redirect(url('admin/ads.php'));
        }
        if (!$slot) {
            flash('请先选择素材所属的广告位', 'error');
            redirect(url('admin/ads.php' . ($id > 0 ? '?edit_item=' . $id : '')));
        }

        $cycle = (string)($_POST['cycle'] ?? 'forever');
        if (!isset(YxAd::CYCLES[$cycle])) {
            $days = (int)preg_replace('/\D/', '', $cycle);
            $cycle = $days > 0 ? YxAd::cycleFromDays($days) : 'forever';
        }
        $bg = (string)($_POST['bg'] ?? 'blue');
        if (!YxBanner::isKnownPalette($bg)) {
            $bg = 'blue';
        }

        $data = [
            'slot_id'    => $slotId,
            'title'      => trim((string)($_POST['title'] ?? '')),
            'subtitle'   => trim((string)($_POST['subtitle'] ?? '')),
            'tag'        => trim((string)($_POST['tag'] ?? '')),
            'subgroup'   => trim((string)($_POST['subgroup'] ?? '')),
            'price'      => max(0, (float)($_POST['price'] ?? 0)),
            'cycle'      => $cycle,
            'bg'         => $bg,
            'btn_text'   => trim((string)($_POST['btn_text'] ?? '')),
            'btn_url'    => trim((string)($_POST['btn_url'] ?? '')),
            'sort'       => (int)($_POST['sort'] ?? 0),
            'status'     => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at' => now(),
        ];

        $err = '';
        if ($data['title'] === '') {
            $err = '标题不能为空';
        } elseif ($data['subtitle'] === '') {
            $err = '副标题 / 说明不能为空（前台卡片与横幅都会展示这一行）';
        } elseif (($data['btn_text'] === '') !== ($data['btn_url'] === '')) {
            $err = '按钮文字与链接需要同时填写';
        } elseif ($data['btn_url'] !== '' && YxAd::href($data['btn_url']) === '') {
            $err = '按钮链接格式无效（支持 http(s) 外链或站内相对路径）';
        }
        if ($err !== '') {
            flash($err, 'error');
            redirect(url('admin/ads.php' . ($id > 0 ? '?edit_item=' . $id : '?slot=' . $slotId)));
        }

        $image = (string)($old['image'] ?? '');
        if (!empty($_POST['remove_image']) && $image !== '') {
            YxAd::removeImageFile($image);
            $image = '';
        }
        if (!empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = yx_upload($_FILES['image'], [
                'sub'      => 'ad',
                'prefix'   => 'ad',
                'exts'     => ['jpg', 'jpeg', 'png', 'webp'],
                'mimes'    => ['image/jpeg', 'image/png', 'image/webp'],
                'max_size' => 4 * 1024 * 1024,
            ]);
            if (!$up['ok']) {
                flash('图片上传失败：' . $up['msg'], 'error');
                redirect(url('admin/ads.php' . ($id > 0 ? '?edit_item=' . $id : '?slot=' . $slotId)));
            }
            if ($image !== '') {
                YxAd::removeImageFile($image);
            }
            $image = $up['path'];
        }
        $data['image'] = $image;

        if ($id > 0) {
            YxDb::update('ad_item', $data, 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '素材已更新'];
        } else {
            $data['created_at'] = now();
            YxDb::insert('ad_item', $data);
            $res = ['ok' => true, 'msg' => '素材已添加'];
        }
        log_action('ad.item.save', $data['title'] . ' #' . $id);
    } elseif ($action === 'delete_item') {
        $row = YxAd::findItem((int)($_POST['id'] ?? 0));
        if (!$row) {
            $res = ['ok' => false, 'msg' => '素材不存在'];
        } else {
            YxDb::delete('ad_item', 'id = :id', ['id' => (int)$row['id']]);
            YxAd::removeImageFile((string)$row['image']);
            log_action('ad.item.delete', (string)$row['title']);
            $res = ['ok' => true, 'msg' => '素材已删除'];
        }
    } elseif ($action === 'toggle_item') {
        $row = YxAd::findItem((int)($_POST['id'] ?? 0));
        if (!$row) {
            $res = ['ok' => false, 'msg' => '素材不存在'];
        } else {
            $next = (int)$row['status'] === 1 ? 0 : 1;
            YxDb::update('ad_item', ['status' => $next, 'updated_at' => now()], 'id = :id', ['id' => (int)$row['id']]);
            log_action('ad.item.toggle', (string)$row['title']);
            $res = ['ok' => true, 'msg' => $next === 1 ? '素材已启用' : '素材已停用'];
        }
    } elseif ($action === 'move_item') {
        $id   = (int)($_POST['id'] ?? 0);
        $dir  = ($_POST['dir'] ?? '') === 'down' ? 'down' : 'up';
        $row  = YxAd::findItem($id);
        $rows = $row ? YxAd::items((int)$row['slot_id'], false) : [];
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
                    YxDb::update('ad_item', ['sort' => $want], 'id = :id', ['id' => (int)$r['id']]);
                }
            }
            log_action('ad.item.move', $id . ' ' . $dir);
            $res = ['ok' => true, 'msg' => '排序已调整'];
        }
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/ads.php' . (!empty($_POST['back']) ? '?' . (string)$_POST['back'] : '')));
}

// 默认广告位只在安装 / 升级时写入（seedDefaults），后台不再自动补种，避免删空后又冒出来
$slots     = YxAd::allSlots();
$slotParam = (int)($_GET['slot'] ?? 0);
$editSlot  = (int)($_GET['edit_slot'] ?? 0);
$editingSlot = $editSlot > 0 ? YxAd::findSlot($editSlot) : null;

// 编辑素材时自动定位到它所属的广告位
$editItemId = (int)($_GET['edit_item'] ?? 0);
$editingItem = $editItemId > 0 ? YxAd::findItem($editItemId) : null;
if ($editingItem) {
    $slotParam = (int)$editingItem['slot_id'];
}
$current = null;
foreach ($slots as $s) {
    if ((int)$s['id'] === $slotParam) {
        $current = $s;
        break;
    }
}
if (!$current && $slots) {
    $current   = $slots[0];
    $slotParam = (int)$current['id'];
}
if ($editingItem && (!$current || (int)$editingItem['slot_id'] !== (int)$current['id'])) {
    $editingItem = null;
}
$items  = $current ? YxAd::items($slotParam, false) : [];
$backTo = $current ? 'slot=' . (int)$current['id'] : '';
$editImg = $editingItem ? YxAd::imageUrl($editingItem) : '';
$isGoods = $current && (string)$current['layout'] === 'goods';

$pageTitle  = '展示广告位';
$activeMenu = 'ads';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="active" href="<?= e(url('admin/ads.php')) ?>">广告位管理（<?= count($slots) ?>）</a>
    <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= e(url('goods.php')) ?>" target="_blank">预览展示页 →</a>
</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editingSlot ? '编辑广告位' : '新增广告位' ?></h2>
        <p class="muted small">
            广告位决定「投在哪」和「怎么排版」；素材在该广告位下按排序值依次展示。
            本功能只做对外展示，素材里的价格不参与下单，购买按钮请指向站内真实购买入口。
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_slot">
            <input type="hidden" name="id" value="<?= (int)($editingSlot['id'] ?? 0) ?>">

            <label>广告位名称
                <input type="text" name="name" maxlength="80" value="<?= e($editingSlot['name'] ?? '') ?>" placeholder="例如：优享财务系统 · 授权版本">
            </label>

            <div class="grid-2">
                <label>投放位置
                    <select name="position">
                        <?php foreach (YxAd::POSITIONS as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= (string)($editingSlot['position'] ?? 'goods') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>版式
                    <select name="layout">
                        <?php foreach (YxAd::LAYOUTS as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= (string)($editingSlot['layout'] ?? 'goods') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label>广告位说明（展示页页头文案，可留空）
                <textarea name="subtitle" maxlength="255" style="min-height:70px"><?= e($editingSlot['subtitle'] ?? '') ?></textarea>
            </label>

            <div class="grid-2">
                <label>排序（小的在前） <input type="number" name="sort" value="<?= e($editingSlot['sort'] ?? ((count($slots) + 1) * 10)) ?>"></label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editingSlot['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= (int)($editingSlot['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editingSlot ? '保存修改' : '添加广告位' ?></button>
                <?php if ($editingSlot): ?><a class="btn btn-ghost" href="<?= e(url('admin/ads.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>广告位列表（<?= count($slots) ?> 个）</h2>
        <?php if (!$slots): ?>
            <div class="empty">还没有广告位，填写左侧表单添加第一个。</div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>排序</th><th>广告位</th><th>位置 / 版式</th><th>素材</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($slots as $s): ?>
                    <?php $cnt = count(YxAd::items((int)$s['id'], false)); ?>
                    <tr<?= (int)$s['id'] === $slotParam ? ' style="background:rgba(28,126,214,.06)"' : '' ?>>
                        <td><?= (int)$s['sort'] ?></td>
                        <td class="wrap">
                            <a href="<?= e(url('admin/ads.php?slot=' . (int)$s['id'])) ?>"><strong><?= e($s['name']) ?></strong></a>
                            <?php if (trim((string)$s['subtitle']) !== ''): ?>
                                <br><span class="muted small"><?= e(mb_substr((string)$s['subtitle'], 0, 40)) ?>…</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted small"><?= e(YxAd::positionName((string)$s['position'])) ?><br><?= e(YxAd::layoutName((string)$s['layout'])) ?></td>
                        <td>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/ads.php?slot=' . (int)$s['id'])) ?>"><?= $cnt ?> 条</a>
                        </td>
                        <td><?= (int)$s['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/ads.php?edit_slot=' . (int)$s['id'])) ?>">编辑</a>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_slot">
                                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= (int)$s['status'] === 1 ? '停用' : '启用' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认删除该广告位？其下素材与图片会一并删除。')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_slot">
                                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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

<?php if ($current): ?>
<div class="grid-2" style="margin-top:18px">
    <div class="card">
        <h2><?= $editingItem ? '编辑素材' : '新增素材' ?></h2>
        <p class="muted small">
            当前广告位：<strong><?= e($current['name']) ?></strong>
            （<?= e(YxAd::positionName((string)$current['position'])) ?> · <?= e(YxAd::layoutName((string)$current['layout'])) ?>）。
            <?php if ($isGoods): ?>
                商品卡版式会按「二级分组」在前台生成筛选标签，同一广告位的素材价格建议与购买页保持一致。
            <?php else: ?>
                横幅版式只展示角标、标题、说明与一个按钮，价格与计费周期不参与展示。
            <?php endif; ?>
        </p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_item">
            <input type="hidden" name="id" value="<?= (int)($editingItem['id'] ?? 0) ?>">
            <input type="hidden" name="slot_id" value="<?= (int)$current['id'] ?>">
            <input type="hidden" name="back" value="<?= e($backTo) ?>">

            <label>标题
                <input type="text" name="title" maxlength="120" value="<?= e($editingItem['title'] ?? '') ?>" placeholder="例如：专业版授权">
            </label>
            <label>副标题 / 说明
                <textarea name="subtitle" maxlength="255" style="min-height:70px" placeholder="一句话说明这个广告要传达的价值"><?= e($editingItem['subtitle'] ?? '') ?></textarea>
            </label>

            <div class="grid-2">
                <label>角标（可留空） <input type="text" name="tag" maxlength="40" value="<?= e($editingItem['tag'] ?? '') ?>" placeholder="例如：增长首选"></label>
                <label>二级分组（商品卡筛选用，可留空） <input type="text" name="subgroup" maxlength="40" value="<?= e($editingItem['subgroup'] ?? '') ?>" placeholder="例如：按版本授权"></label>
            </div>

            <div class="grid-2">
                <label>价格（元，0 表示免费）
                    <input type="number" name="price" step="0.01" min="0" value="<?= e($editingItem['price'] ?? 0) ?>">
                </label>
                <label>计费周期
                    <?php $curCycle = (string)($editingItem['cycle'] ?? 'forever'); ?>
                    <select name="cycle">
                        <?php foreach (YxAd::CYCLES as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $curCycle === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset(YxAd::CYCLES[$curCycle]) && preg_match('/^(\d+)d$/', $curCycle, $dm)): ?>
                            <option value="<?= e($curCycle) ?>" selected><?= (int)$dm[1] ?> 天</option>
                        <?php endif; ?>
                    </select>
                </label>
                <label>按钮文字 <input type="text" name="btn_text" maxlength="40" value="<?= e($editingItem['btn_text'] ?? '') ?>" placeholder="例如：立即购买"></label>
                <label>按钮链接 <input type="text" name="btn_url" maxlength="255" value="<?= e($editingItem['btn_url'] ?? '') ?>" placeholder="例如：panel/index.php?m=edition"></label>
            </div>

            <label>图片（可留空，横幅作背景图 / 商品卡作封面）
                <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
            </label>
            <?php if ($editImg !== ''): ?>
                <div style="margin:8px 0 14px">
                    <img src="<?= e($editImg) ?>" alt="当前图片" style="max-width:100%;border-radius:8px;border:1px solid var(--line)">
                    <label class="checkbox" style="margin-top:8px">
                        <input type="checkbox" name="remove_image" value="1"> 删除当前图片（改用下方渐变底色）
                    </label>
                </div>
            <?php endif; ?>

            <label>渐变底色（未上传图片时生效）
                <select name="bg">
                    <?php foreach (YxBanner::PALETTES as $key => $meta): ?>
                        <option value="<?= e($key) ?>" <?= (string)($editingItem['bg'] ?? 'blue') === $key ? 'selected' : '' ?>><?= e($meta[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="grid-2">
                <label>排序（小的在前） <input type="number" name="sort" value="<?= e($editingItem['sort'] ?? ((count($items) + 1) * 10)) ?>"></label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editingItem['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= (int)($editingItem['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editingItem ? '保存修改' : '添加素材' ?></button>
                <?php if ($editingItem): ?><a class="btn btn-ghost" href="<?= e(url('admin/ads.php?' . $backTo)) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>素材列表（<?= count($items) ?> 条）</h2>
        <p class="muted small">前台只展示「启用」广告位下的「启用」素材，按排序值从小到大排列。</p>
        <?php if (!$items): ?>
            <div class="empty">该广告位还没有素材。</div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>排序</th><th>素材</th><th>价格</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($items as $r): ?>
                    <?php $img = YxAd::imageUrl($r); ?>
                    <tr>
                        <td><?= (int)$r['sort'] ?></td>
                        <td class="wrap">
                            <?php if ($img !== ''): ?>
                                <img src="<?= e($img) ?>" alt="" style="width:88px;height:44px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px">
                            <?php endif; ?>
                            <?php if (trim((string)$r['tag']) !== ''): ?><span class="badge badge-mute"><?= e($r['tag']) ?></span><?php endif; ?>
                            <strong><?= e($r['title']) ?></strong>
                            <?php if (trim((string)$r['subtitle']) !== ''): ?>
                                <br><span class="muted small"><?= e(mb_substr((string)$r['subtitle'], 0, 46)) ?>…</span>
                            <?php endif; ?>
                            <?php if (trim((string)$r['subgroup']) !== ''): ?>
                                <br><span class="muted small">分组：<?= e($r['subgroup']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="muted small"><?= e(YxAd::priceLabel($r)) ?><br><?= e(YxAd::cycleLabel($r)) ?></td>
                        <td><?= (int)$r['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/ads.php?edit_item=' . (int)$r['id'])) ?>">编辑</a>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="move_item">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="dir" value="up">
                                    <input type="hidden" name="back" value="<?= e($backTo) ?>">
                                    <button class="btn btn-sm" type="submit" title="上移">↑</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="move_item">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="dir" value="down">
                                    <input type="hidden" name="back" value="<?= e($backTo) ?>">
                                    <button class="btn btn-sm" type="submit" title="下移">↓</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_item">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="back" value="<?= e($backTo) ?>">
                                    <button class="btn btn-sm" type="submit"><?= (int)$r['status'] === 1 ? '停用' : '启用' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认删除该素材？图片会一并删除。')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="back" value="<?= e($backTo) ?>">
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
