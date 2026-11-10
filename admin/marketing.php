<?php
/**
 * 优享财务系统 · 售卖品主图 / 产品详情页图片制作
 *
 * 权限：仅管理员可访问（含预览图片出口 ?raw=），代理 / 分站 / 客户会话一律拒绝。
 *
 * 页面结构：
 *   1. 设计器  —— 左侧表单（可从「展示广告位」素材一键预填），右侧实时预览与导出
 *   2. 素材库  —— 已保存的主图 / 详情页，支持预览、复制地址、上下架、删除
 *
 * 图片由 includes/marketing.php 渲染成 SVG：
 *   - 服务器无需中文字体，文案不会出现方块字
 *   - 页面内可直接导出 PNG（浏览器把 SVG 画进 canvas 再导出）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

/* ------------------------------------------------------------ 图片出口 */

// ?raw=ID 输出 SVG（列表缩略图与下载都走这里）
if (isset($_GET['raw'])) {
    $row = YxMarketing::find((int)$_GET['raw']);
    if (!$row) {
        http_response_code(404);
        exit('图片不存在');
    }
    $download = !empty($_GET['download']);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    if ($download) {
        $name = preg_replace('/[^\p{Han}A-Za-z0-9\-_]+/u', '-', (string)$row['title']);
        header('Content-Disposition: attachment; filename="' . ($name !== '' ? $name : ('marketing-' . (int)$row['id'])) . '.svg"');
    }
    echo YxMarketing::svg($row);
    exit;
}

/* ------------------------------------------------------------ 表单处理 */

$tab = (string)($_GET['tab'] ?? 'main');
if (!in_array($tab, ['main', 'detail', 'all'], true)) {
    $tab = 'main';
}

/**
 * 后台表单里的图片字段：优先使用已保存的静态文件地址（与前台完全一致），
 * 静态文件还没写出时才用管理员预览出口。
 */
function yx_marketing_form_url(array $row): string
{
    return YxMarketing::publicUrl($row);
}

/** 从表单读取并清洗数据（预览与保存共用，保证所见即所存） */
function yx_marketing_from_post(): array
{
    $kind = (string)($_POST['kind'] ?? 'main');
    if (!YxMarketing::isKnownKind($kind)) {
        $kind = 'main';
    }
    $bg = (string)($_POST['bg'] ?? 'blue');
    if (!YxMarketing::isKnownPalette($bg)) {
        $bg = 'blue';
    }

    return [
        'kind'       => $kind,
        'layout'     => YxMarketing::resolveLayout($kind, (string)($_POST['layout'] ?? 'hero')),
        'title'      => trim((string)($_POST['title'] ?? '')),
        'subtitle'   => trim((string)($_POST['subtitle'] ?? '')),
        'tag'        => trim((string)($_POST['tag'] ?? '')),
        'price'      => trim((string)($_POST['price'] ?? '')),
        'price_note' => trim((string)($_POST['price_note'] ?? '')),
        'features'   => trim((string)($_POST['features'] ?? '')),
        'cta'        => trim((string)($_POST['cta'] ?? '')),
        'brand'      => trim((string)($_POST['brand'] ?? '')),
        'bg'         => $bg,
        'sort'       => (int)($_POST['sort'] ?? 0),
        'status'     => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
    ];
}

$editing = null;
$editId  = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = YxMarketing::find($editId);
    if (!$editing) {
        flash('该图片记录不存在或已被删除', 'error');
        redirect(url('admin/marketing.php?tab=' . $tab));
    }
}

$previewRow = null;   // 本页渲染的预览数据（预览请求或编辑态）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'preview') {
        // 仅渲染预览，不落库；同时回填表单，便于继续调整
        $data = yx_marketing_from_post();
        $previewRow = $data + ['id' => (int)($_POST['id'] ?? 0), 'created_at' => now()];
        $editing    = $previewRow;
        $tab        = $data['kind'];
    } elseif ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = yx_marketing_from_post();
        if ($data['title'] === '') {
            // 只提示错误，表单本次提交的输入随重定向丢失，标题外的字段仍需重新填写
            flash('标题不能为空', 'error');
            redirect(url('admin/marketing.php?tab=' . $data['kind'] . ($id > 0 ? '&edit=' . $id : '')));
        }
        if ($id > 0) {
            $old = YxMarketing::find($id);
            if (!$old) {
                flash('该图片记录不存在或已被删除', 'error');
                redirect(url('admin/marketing.php?tab=' . $data['kind']));
            }
            $data['updated_at'] = now();
            YxDb::update('marketing_image', $data, 'id = :id', ['id' => $id]);
            YxMarketing::writeFile($data + ['id' => $id]);
            log_action('marketing.update', $data['title'] . ' #' . $id);
            flash(YxMarketing::fileExists($id)
                ? '图片已更新（展示页立即生效）'
                : '图片已更新，但静态文件写入失败，请检查 uploads/marketing 目录权限');
            redirect(url('admin/marketing.php?tab=' . $data['kind'] . '&edit=' . $id));
        }
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $newId = YxDb::insert('marketing_image', $data);
        YxMarketing::writeFile($data + ['id' => $newId]);
        log_action('marketing.create', $data['title'] . ' #' . $newId);
        flash(YxMarketing::fileExists($newId)
            ? '图片已生成，可在下方素材库预览与下载'
            : '图片已保存，但静态文件写入失败，请检查 uploads/marketing 目录权限');
        redirect(url('admin/marketing.php?tab=' . $data['kind'] . '&edit=' . $newId));
    } elseif ($action === 'delete') {
        $row = YxMarketing::find((int)($_POST['id'] ?? 0));
        if (!$row) {
            flash('该图片记录不存在或已被删除', 'error');
        } else {
            YxDb::delete('marketing_image', 'id = :id', ['id' => (int)$row['id']]);
            YxMarketing::removeFile((int)$row['id']);
            // 商品卡封面若指向这张主图，同步改为无图，避免前台出现破图
            $rel = YxMarketing::fileRel((int)$row['id']);
            try {
                YxDb::exec(
                    'UPDATE ' . YxDb::table('ad_item') . ' SET image = NULL, updated_at = ? WHERE image = ?',
                    [now(), $rel]
                );
            } catch (Throwable $e) {
                // 封面清理失败不影响删除结果
            }
            log_action('marketing.delete', (string)$row['title']);
            flash('图片已删除');
        }
        redirect(url('admin/marketing.php?tab=' . $tab));
    } elseif ($action === 'apply') {
        // 把已保存的主图套用到「展示广告位」的商品卡封面（前台 goods.php 读取该字段）
        $row  = YxMarketing::find((int)($_POST['id'] ?? 0));
        $item = YxAd::findItem((int)($_POST['item_id'] ?? 0));
        if (!$row) {
            flash('图片记录不存在', 'error');
        } elseif (!$item) {
            flash('请选择要套用的展示商品卡', 'error');
        } elseif ((string)$row['kind'] !== 'main') {
            flash('只有「商品主图」可以套用到商品卡封面', 'error');
        } elseif (!YxMarketing::fileExists((int)$row['id'])) {
            flash('这张主图的静态文件不存在，请先保存一次再套用', 'error');
        } else {
            YxDb::update('ad_item', ['image' => YxMarketing::fileRel((int)$row['id']), 'updated_at' => now()], 'id = :id', ['id' => (int)$item['id']]);
            log_action('marketing.apply', (string)$row['title'] . ' → 素材 #' . (int)$item['id']);
            flash('已套用到商品卡「' . (string)$item['title'] . '」的封面');
        }
        redirect(url('admin/marketing.php?tab=' . $tab));
    } elseif ($action === 'toggle') {
        $row = YxMarketing::find((int)($_POST['id'] ?? 0));
        if (!$row) {
            flash('该图片记录不存在或已被删除', 'error');
        } else {
            $next = (int)$row['status'] === 1 ? 0 : 1;
            YxDb::update('marketing_image', ['status' => $next, 'updated_at' => now()], 'id = :id', ['id' => (int)$row['id']]);
            log_action('marketing.toggle', (string)$row['title']);
            flash($next === 1 ? '已启用（展示页可见）' : '已停用（展示页不再展示）');
        }
        redirect(url('admin/marketing.php?tab=' . $tab));
    }
}

/* ------------------------------------------------------------ 数据准备 */

// 展示页素材：用于「一键预填」与「套用到商品卡」
$adItems = [];
try {
    $adItems = YxDb::all(
        'SELECT i.*, s.name AS slot_name FROM ' . YxDb::table('ad_item') . ' i'
        . ' LEFT JOIN ' . YxDb::table('ad_slot') . ' s ON s.id = i.slot_id'
        . ' ORDER BY i.slot_id ASC, i.sort ASC, i.id ASC'
    );
} catch (Throwable $e) {
    $adItems = [];
}

// 预填：从展示页素材带入标题 / 价格 / 卖点
$fromItemId = (int)($_GET['from_item'] ?? 0);
if ($fromItemId > 0 && $editing === null) {
    foreach ($adItems as $it) {
        if ((int)$it['id'] === $fromItemId) {
            $draft = YxMarketing::draftFromItem($it);
            $editing = $draft + [
                'id'       => 0,
                'kind'     => (($tab === 'detail') ? 'detail' : 'main'),
                'layout'   => 'hero',
                'sort'     => 0,
                'status'   => 1,
            ];
            break;
        }
    }
    if ($editing === null) {
        flash('该展示素材不存在', 'error');
    }
}

$images = YxMarketing::all();
$listByKind = ['main' => [], 'detail' => []];
foreach ($images as $img) {
    $k = (string)$img['kind'];
    if (isset($listByKind[$k])) {
        $listByKind[$k][] = $img;
    }
}
$listImages = $tab === 'all' ? $images : ($listByKind[$tab] ?? []);

$form = $editing ?: [
    'id'         => 0,
    'kind'       => $tab === 'detail' ? 'detail' : 'main',
    'layout'     => 'hero',
    'title'      => '',
    'subtitle'   => '',
    'tag'        => '',
    'price'      => '',
    'price_note' => '',
    'features'   => '',
    'cta'        => '扫码立即咨询',
    'brand'      => '',
    'bg'         => 'blue',
    'sort'       => 0,
    'status'     => 1,
];
if ($previewRow !== null) {
    $form = $previewRow;
}
// 表单里 kind 跟随当前页签（切页签即切换产物类型）
$formKind = YxMarketing::isKnownKind((string)($form['kind'] ?? '')) ? (string)$form['kind'] : 'main';

$svgMarkup = YxMarketing::svg($form + ['id' => (int)($form['id'] ?? 0)]);

$pageTitle  = '商品图制作';
$activeMenu = 'marketing';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<style>
.mk-tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:16px;flex-wrap:wrap}
.mk-tabs a{padding:9px 14px;color:var(--muted);font-size:13.5px;border-bottom:2px solid transparent;text-decoration:none}
.mk-tabs a.on{color:var(--primary);border-bottom-color:var(--primary);font-weight:600}
.mk-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start}
@media(max-width:1100px){.mk-layout{grid-template-columns:1fr}}
.mk-stage{background:var(--surface-3);border:1px dashed var(--line);border-radius:var(--radius);padding:12px;display:flex;justify-content:center;align-items:flex-start;min-height:220px}
.mk-stage svg{max-width:100%;height:auto;border-radius:8px;box-shadow:var(--shadow)}
.mk-stage .empty{color:var(--muted);font-size:13px}
.mk-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;font-size:12px;color:var(--muted)}
.mk-thumb{width:96px;height:96px;border-radius:8px;object-fit:cover;background:var(--surface-2);display:block}
.mk-thumb.tall{height:150px}
.mk-hint{font-size:12px;color:var(--muted);margin:0 0 10px;line-height:1.7}
.mk-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.mk-swatches{display:flex;gap:8px;flex-wrap:wrap}
.mk-swatches label{position:relative;cursor:pointer}
.mk-swatches input{position:absolute;opacity:0;inset:0}
.mk-swatches span{display:inline-block;padding:6px 12px;border:1px solid var(--line);border-radius:999px;font-size:12.5px}
.mk-swatches input:checked+span{border-color:var(--primary);color:var(--primary);background:var(--primary-soft);font-weight:600}
.mk-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;background:var(--surface-2);border:1px solid var(--line);border-radius:6px;padding:3px 6px;word-break:break-all}
</style>

<div class="mk-tabs">
    <a class="<?= $tab === 'main' ? 'on' : '' ?>" href="<?= e(url('admin/marketing.php?tab=main')) ?>">商品主图（<?= count($listByKind['main']) ?>）</a>
    <a class="<?= $tab === 'detail' ? 'on' : '' ?>" href="<?= e(url('admin/marketing.php?tab=detail')) ?>">产品详情页（<?= count($listByKind['detail']) ?>）</a>
    <a class="<?= $tab === 'all' ? 'on' : '' ?>" href="<?= e(url('admin/marketing.php?tab=all')) ?>">全部素材（<?= count($images) ?>）</a>
</div>

<div class="mk-layout">
    <!-- 左侧：参数表单 -->
    <div class="card">
        <div class="section-head">
            <h2><?= !empty($form['id']) ? '编辑图片 #' . (int)$form['id'] : ('制作' . e(YxMarketing::kindName($formKind))) ?></h2>
            <?php if (!empty($form['id'])): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/marketing.php?tab=' . $tab)) ?>">＋ 新建</a>
            <?php endif; ?>
        </div>

        <?php if ($adItems): ?>
        <form method="get" class="inline-form" style="margin-bottom:14px">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <label class="grow">从展示页素材预填
                <select name="from_item">
                    <option value="0">— 不预填，手工填写 —</option>
                    <?php foreach ($adItems as $it): ?>
                        <option value="<?= (int)$it['id'] ?>">
                            <?= e(($it['slot_name'] ? $it['slot_name'] . ' / ' : '') . $it['title']) ?>
                            （<?= e(YxAd::priceLabel($it)) ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-ghost" type="submit">带入</button>
        </form>
        <?php else: ?>
            <p class="mk-hint">还没有展示页素材，也可以在「展示广告位」中先添加商品卡，本页即可一键预填文案与价格。</p>
        <?php endif; ?>

        <?php if ($formKind === 'main' && $adItems): ?>
            <p class="mk-hint">保存后可在下方素材库点「用作封面」，把主图套到展示页商品卡上。</p>
        <?php endif; ?>

        <form method="post" id="mk-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
            <input type="hidden" name="kind" value="<?= e($formKind) ?>">

            <div class="grid-2">
                <label>主标题 <input type="text" name="title" required maxlength="80" value="<?= e($form['title'] ?? '') ?>" placeholder="如：优享财务系统·企业版授权"></label>
                <label>角标（可空） <input type="text" name="tag" maxlength="40" value="<?= e($form['tag'] ?? '') ?>" placeholder="如：官方授权 / 限时特惠"></label>
            </div>
            <label>副标题 <input type="text" name="subtitle" maxlength="160" value="<?= e($form['subtitle'] ?? '') ?>" placeholder="一句话说明卖点"></label>

            <div class="grid-3">
                <label>价格文案 <input type="text" name="price" maxlength="40" value="<?= e($form['price'] ?? '') ?>" placeholder="3600 或 面议"></label>
                <label>价格后缀 <input type="text" name="price_note" maxlength="60" value="<?= e($form['price_note'] ?? '') ?>" placeholder="/永久 或 /年"></label>
                <label>排序 <input type="number" name="sort" value="<?= (int)($form['sort'] ?? 0) ?>"></label>
            </div>

            <?php if ($formKind === 'detail'): ?>
                <label>功能清单（一行一项，最多 6 项，详情页渲染成清单卡）
                    <textarea name="features" rows="6" placeholder="订单 · 业务 · 收支一体化记账"><?= e($form['features'] ?? '') ?></textarea>
                </label>
            <?php else: ?>
                <label>功能清单（主图不展示，填了也不占位；如需清单请切换到「产品详情页」）
                    <textarea name="features" rows="3" placeholder="可选"><?= e($form['features'] ?? '') ?></textarea>
                </label>
            <?php endif; ?>

            <div class="grid-2">
                <label>行动按钮文案 <input type="text" name="cta" maxlength="60" value="<?= e($form['cta'] ?? '') ?>" placeholder="扫码立即咨询"></label>
                <label>品牌署名（留空取站点名称） <input type="text" name="brand" maxlength="60" value="<?= e($form['brand'] ?? '') ?>" placeholder="<?= e(setting('site_name', config('app.name'))) ?>"></label>
            </div>

            <?php if ($formKind === 'main'): ?>
                <label>主图版式</label>
                <div class="mk-swatches" style="margin-bottom:12px">
                    <?php foreach (['hero' => '大标题', 'price' => '价格突出'] as $lk => $lv): ?>
                        <label>
                            <input type="radio" name="layout" value="<?= e($lk) ?>" <?= ($form['layout'] ?? 'hero') === $lk ? 'checked' : '' ?>>
                            <span><?= e($lv) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <label>配色</label>
            <div class="mk-swatches" style="margin-bottom:12px">
                <?php foreach (YxMarketing::PALETTES as $pk => $pv): ?>
                    <label>
                        <input type="radio" name="bg" value="<?= e($pk) ?>" <?= ($form['bg'] ?? 'blue') === $pk ? 'checked' : '' ?>>
                        <span><?= e($pv[0]) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <label>状态
                <select name="status">
                    <option value="1" <?= (int)($form['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用（展示页可见）</option>
                    <option value="0" <?= (int)($form['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                </select>
            </label>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit" name="action" value="save">保存到素材库</button>
                <button class="btn btn-ghost" type="submit" name="action" value="preview">仅预览</button>
            </div>
        </form>
    </div>

    <!-- 右侧：实时预览与导出 -->
    <div class="card">
        <h2>预览（<?= (int)YxMarketing::size($formKind)[0] ?>×<?= (int)YxMarketing::size($formKind)[1] ?>）</h2>
        <p class="mk-hint">预览为矢量渲染；「下载 PNG」由浏览器转成位图，可直接用于电商平台发布。</p>
        <div class="mk-stage" id="mk-stage"><?= $svgMarkup ?></div>
        <div class="mk-meta">
            <span>产物：<?= e(YxMarketing::kindName($formKind)) ?></span>
            <span>配色：<?= e(YxMarketing::PALETTES[$form['bg'] ?? 'blue'][0] ?? '蓝色') ?></span>
            <span>文案长度：<?= (int)mb_strlen((string)($form['title'] ?? ''), 'UTF-8') ?>/24（超出自动截断）</span>
        </div>
        <div class="mk-actions">
            <button class="btn btn-primary btn-sm" type="button" id="mk-png">下载 PNG</button>
            <?php if (!empty($form['id'])): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/marketing.php?raw=' . (int)$form['id'] . '&download=1')) ?>">下载 SVG</a>
            <?php endif; ?>
            <button class="btn btn-ghost btn-sm" type="button" id="mk-svg">下载当前预览</button>
        </div>
        <?php if (!empty($form['id'])): ?>
            <?php $publicUrl = yx_marketing_form_url($form); ?>
            <p class="mk-hint" style="margin-top:12px">公开图片地址（前台展示页 / 电商平台直接引用，无需登录）：</p>
            <div class="mk-code" id="mk-url"><?= e($publicUrl !== '' ? $publicUrl : '（静态文件未生成，保存后可用）') ?></div>
            <div class="mk-actions">
                <?php if ($publicUrl !== ''): ?>
                    <button class="btn btn-ghost btn-sm" type="button" data-copy="mk-url">复制地址</button>
                <?php endif; ?>
                <span class="mk-code">预览出口：<?= e(url('admin/marketing.php?raw=' . (int)$form['id'])) ?>（仅管理员登录后可见）</span>
            </div>
        <?php else: ?>
            <p class="mk-hint" style="margin-top:12px">尚未保存，保存后才会生成固定地址。</p>
        <?php endif; ?>
    </div>
</div>

<!-- 素材库 -->
<div class="card">
    <div class="section-head">
        <h2><?= $tab === 'all' ? '全部素材' : e(YxMarketing::kindName($tab)) ?>（共 <?= count($listImages) ?> 张）</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('goods.php')) ?>" target="_blank">查看展示页 →</a>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>预览</th><th>类型</th><th>标题</th><th>配色</th><th>排序</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($listImages as $img): ?>
            <tr>
                <td>
                    <img class="mk-thumb<?= (string)$img['kind'] === 'detail' ? ' tall' : '' ?>"
                         src="<?= e(url('admin/marketing.php?raw=' . (int)$img['id'])) ?>"
                         alt="<?= e($img['title']) ?>" loading="lazy">
                </td>
                <td><?= e(YxMarketing::kindName((string)$img['kind'])) ?></td>
                <td class="wrap"><?= e($img['title']) ?></td>
                <td><?= e(YxMarketing::PALETTES[$img['bg']][0] ?? $img['bg']) ?></td>
                <td><?= (int)$img['sort'] ?></td>
                <td><?= (int)$img['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                <td class="small muted"><?= e($img['updated_at'] ?: $img['created_at']) ?></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/marketing.php?tab=' . (string)$img['kind'] . '&edit=' . (int)$img['id'])) ?>">编辑</a>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/marketing.php?raw=' . (int)$img['id'] . '&download=1')) ?>">SVG</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
                            <button class="btn btn-sm" type="submit"><?= (int)$img['status'] === 1 ? '停用' : '启用' ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('确认删除该图片？删除后无法恢复。')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                        <?php if ((string)$img['kind'] === 'main' && $adItems): ?>
                            <form method="post" class="inline-form" style="margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="apply">
                                <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
                                <select name="item_id" style="max-width:150px" title="套用到商品卡封面">
                                    <?php foreach ($adItems as $it): ?>
                                        <option value="<?= (int)$it['id'] ?>" <?= (string)($it['image'] ?? '') === YxMarketing::fileRel((int)$img['id']) ? 'selected' : '' ?>>
                                            <?= e($it['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-ghost" type="submit">用作封面</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$listImages): ?><tr><td colspan="8" class="empty">暂无素材，先在左侧制作一张</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<script>
(function () {
    var stage   = document.getElementById('mk-stage');
    var svgNode = stage ? stage.querySelector('svg') : null;

    function svgText(node) {
        if (!node) { return ''; }
        var clone = node.cloneNode(true);
        clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        return new XMLSerializer().serializeToString(clone);
    }

    function naturalSize(node) {
        var w = parseInt(node.getAttribute('width'), 10);
        var h = parseInt(node.getAttribute('height'), 10);
        return { w: w || 800, h: h || 800 };
    }

    function download(blob, name) {
        var a = document.createElement('a');
        var url = URL.createObjectURL(blob);
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1200);
    }

    var pngBtn = document.getElementById('mk-png');
    if (pngBtn) {
        pngBtn.addEventListener('click', function () {
            if (!svgNode) { return; }
            var size = naturalSize(svgNode);
            var src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgText(svgNode));
            var img = new Image();
            pngBtn.disabled = true;
            img.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = size.w;
                canvas.height = size.h;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, size.w, size.h);
                var out = function (blob) {
                    pngBtn.disabled = false;
                    if (blob) {
                        download(blob, 'marketing-' + size.w + 'x' + size.h + '-' + Date.now() + '.png');
                    } else {
                        alert('当前浏览器不支持导出 PNG，请改用「下载 SVG」。');
                    }
                };
                if (canvas.toBlob) {
                    canvas.toBlob(out, 'image/png');
                } else {
                    out(null);
                }
            };
            img.onerror = function () {
                pngBtn.disabled = false;
                alert('预览渲染失败，请刷新页面后重试。');
            };
            img.src = src;
        });
    }

    var svgBtn = document.getElementById('mk-svg');
    if (svgBtn) {
        svgBtn.addEventListener('click', function () {
            if (!svgNode) { return; }
            var size = naturalSize(svgNode);
            download(new Blob([svgText(svgNode)], { type: 'image/svg+xml;charset=utf-8' }),
                'marketing-' + size.w + 'x' + size.h + '-' + Date.now() + '.svg');
        });
    }

    // 复制图片地址
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.getAttribute('data-copy'));
            if (!el) { return; }
            var text = el.textContent.trim();
            var done = function () { btn.textContent = '已复制'; setTimeout(function () { btn.textContent = '复制地址'; }, 1500); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { window.prompt('手动复制：', text); });
            } else {
                window.prompt('手动复制：', text);
            }
        });
    });
})();
</script>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
