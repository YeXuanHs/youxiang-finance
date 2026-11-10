<?php
/**
 * 优享财务系统 · 服务中心设置（后台）
 *   - 服务项目 CRUD（价格 / 计费方式 / 规格 / 亮点 / 上下架）
 *   - 服务购买记录查看（可关闭 / 标记已开通）
 *   - 公告 / FAQ / 版本授权价格（原有内容）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

/** 多行文本 → 亮点数组（去空行、截断） */
function self_features($raw): array
{
    if (is_array($raw)) {
        $raw = implode("\n", $raw);
    }
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = mb_substr($line, 0, 80);
        }
    }
    return array_slice($out, 0, 12);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    /* ---------- 服务项目保存 ---------- */
    if ($action === 'save_item') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'group_key'   => isset(YxServiceCenter::GROUPS[$_POST['group_key'] ?? '']) ? (string)$_POST['group_key'] : 'ops',
            'name'        => trim((string)($_POST['name'] ?? '')),
            'subtitle'    => trim((string)($_POST['subtitle'] ?? '')) ?: null,
            'icon'        => trim((string)($_POST['icon'] ?? '')) ?: null,
            'price'       => round_money($_POST['price'] ?? 0),
            'bill_type'   => isset(YxServiceCenter::BILL_TYPES[$_POST['bill_type'] ?? '']) ? (string)$_POST['bill_type'] : 'once',
            'unit'        => trim((string)($_POST['unit'] ?? '')) ?: null,
            'stock'       => (int)($_POST['stock'] ?? -1),
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'features'    => json_encode(self_features($_POST['features'] ?? ''), JSON_UNESCAPED_UNICODE),
            'specs'       => json_encode(YxServiceCenter::cleanSpecs($_POST['specs'] ?? '[]'), JSON_UNESCAPED_UNICODE),
            'badge'       => trim((string)($_POST['badge'] ?? '')) ?: null,
            'featured'    => !empty($_POST['featured']) ? 1 : 0,
            'sort'        => (int)($_POST['sort'] ?? 0),
            'status'      => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at'  => now(),
        ];
        if ($data['name'] === '') {
            flash('服务名称不能为空', 'error');
            redirect(url('admin/service_center.php'));
        }
        if ($id > 0) {
            YxDb::update('service_item', $data, 'id = :id', ['id' => $id]);
            log_action('service_item.update', $data['name'], ['id' => $id]);
            flash('服务项目已更新');
        } else {
            $data['created_at'] = now();
            $newId = YxDb::insert('service_item', $data);
            log_action('service_item.create', $data['name'], ['id' => $newId]);
            flash('服务项目已创建');
        }
        redirect(url('admin/service_center.php'));
    }

    /* ---------- 删除 ---------- */
    if ($action === 'delete_item') {
        $id   = (int)($_POST['id'] ?? 0);
        $used = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service_order') . ' WHERE item_id = ?', [$id]);
        if ($used > 0) {
            flash('该服务已有 ' . $used . ' 条购买记录，无法删除，建议改为下架', 'error');
        } else {
            YxDb::exec('DELETE FROM ' . YxDb::table('service_item') . ' WHERE id = ?', [$id]);
            log_action('service_item.delete', (string)$id);
            flash('服务项目已删除');
        }
        redirect(url('admin/service_center.php'));
    }

    /* ---------- 关闭 / 开通服务单 ---------- */
    if ($action === 'order_status') {
        $rid    = (int)($_POST['rid'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (isset(YxServiceCenter::STATUS[$status])) {
            YxDb::update('service_order', ['status' => $status, 'updated_at' => now()], 'id = :id', ['id' => $rid]);
            log_action('service_order.status', (string)$rid, ['status' => $status]);
            flash('服务单状态已更新为「' . YxServiceCenter::statusName($status) . '」');
        }
        redirect(url('admin/service_center.php#orders'));
    }

    /* ---------- 写入示例服务 ---------- */
    if ($action === 'seed') {
        $n = YxServiceCenter::seedDefaults();
        log_action('service_item.seed', (string)$n);
        flash($n > 0 ? ('已写入 ' . $n . ' 个示例服务') : '已有服务项目，未重复写入');
        redirect(url('admin/service_center.php'));
    }

    /* ---------- 原有：站点内容与版本价格 ---------- */
    if ($action === 'save') {
        set_setting('support_notice', trim((string)($_POST['support_notice'] ?? '')));
        $faqRaw = trim((string)($_POST['support_faq'] ?? ''));
        if ($faqRaw !== '') {
            $faq = json_decode($faqRaw, true);
            if (!is_array($faq)) {
                flash('FAQ 不是合法 JSON', 'error');
                redirect(url('admin/service_center.php'));
            }
            set_setting('support_faq', json_encode($faq, JSON_UNESCAPED_UNICODE));
        } else {
            set_setting('support_faq', '');
        }
        foreach (['basic' => 10, 'pro' => 1600, 'enterprise' => 3600] as $k => $defPrice) {
            set_setting('edition_price_' . $k, (string)round_money($_POST['edition_price_' . $k] ?? $defPrice));
            set_setting('edition_days_' . $k, (string)max(0, (int)($_POST['edition_days_' . $k] ?? ($k === 'basic' ? 0 : 365))));
        }
        log_action('service_center.save');
        flash('已保存');
        redirect(url('admin/service_center.php'));
    }
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxServiceCenter::find($editId) : null;

$items = YxServiceCenter::all(false);
$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service_order'));
$page  = paginate($total, 20);
$orders = YxDb::all(
    'SELECT * FROM ' . YxDb::table('service_order') . ' ORDER BY id DESC LIMIT ' . (int)$page['per_page'] . ' OFFSET ' . (int)$page['offset']
);

$editSpecs = $editing ? json_encode(YxServiceCenter::specs($editing), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';
$editFeats = $editing ? YxServiceCenter::features($editing) : [];

$pageTitle  = '服务中心设置';
$activeMenu = 'service_center';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2><?= $editing ? '编辑服务项目 #' . (int)$editing['id'] : '新增服务项目' ?></h2>
        <a class="btn btn-ghost" href="<?= e(url('service/index.php')) ?>" target="_blank" rel="noopener">查看前台</a>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_item">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

        <div class="grid-2">
            <label>服务名称 <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="如：网站数据迁移"></label>
            <label>分组
                <select name="group_key">
                    <?php foreach (YxServiceCenter::GROUPS as $gk => $g): ?>
                        <option value="<?= e($gk) ?>" <?= (string)($editing['group_key'] ?? 'ops') === $gk ? 'selected' : '' ?>><?= e($g['icon'] . ' ' . $g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>一句话简介 <input type="text" name="subtitle" value="<?= e($editing['subtitle'] ?? '') ?>" placeholder="展示在卡片标题下"></label>
            <label>图标（emoji 可选） <input type="text" name="icon" maxlength="8" value="<?= e($editing['icon'] ?? '') ?>" placeholder="🚚"></label>
            <label>价格（元） <input type="number" step="0.01" min="0" name="price" value="<?= e($editing['price'] ?? '0.00') ?>"></label>
            <label>计费方式
                <select name="bill_type">
                    <?php foreach (YxServiceCenter::BILL_TYPES as $bk => $bn): ?>
                        <option value="<?= e($bk) ?>" <?= (string)($editing['bill_type'] ?? 'once') === $bk ? 'selected' : '' ?>><?= e($bn) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>单位 <input type="text" name="unit" value="<?= e($editing['unit'] ?? '') ?>" placeholder="次 / 月 / 套"></label>
            <label>库存（-1 不限，0 表示售罄） <input type="number" name="stock" value="<?= e($editing['stock'] ?? '-1') ?>"></label>
            <label>角标文案 <input type="text" name="badge" value="<?= e($editing['badge'] ?? '') ?>" placeholder="热销 / 推荐"></label>
            <label>排序（越大越靠前） <input type="number" name="sort" value="<?= e($editing['sort'] ?? 0) ?>"></label>
            <label>状态
                <select name="status">
                    <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>上架</option>
                    <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>下架</option>
                </select>
            </label>
        </div>

        <label>服务说明 <textarea name="description" rows="3"><?= e($editing['description'] ?? '') ?></textarea></label>

        <label>包含内容（每行一条）
            <textarea name="features" rows="4" class="mono" placeholder="数据库完整导出/导入&#10;附件与配置同步"><?= e(implode("\n", $editFeats)) ?></textarea>
        </label>

        <label>可选规格（JSON：<code>[{"name":"中型站","price":399,"desc":""}]</code>，price 为该档实际售价，0 表示含在基础价内）
            <textarea name="specs" rows="6" class="mono" placeholder='[{"name":"基础站","price":199}]'><?= e($editSpecs) ?></textarea>
        </label>

        <label class="checkbox"><input type="checkbox" name="featured" value="1" <?= !empty($editing['featured']) ? 'checked' : '' ?>> 设为精选（前台优先展示）</label>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建服务' ?></button>
            <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/service_center.php')) ?>">取消编辑</a><?php endif; ?>
            <?php if (!$editing && !$items): ?>
                <button class="btn btn-ghost" type="submit" name="action" value="seed" formnovalidate onclick="this.form.querySelector('[name=name]').removeAttribute('required')">写入示例服务</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2>服务项目（共 <?= count($items) ?> 个）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>名称</th><th>分组</th><th>价格</th><th>计费</th><th>规格</th><th>已服务</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= (int)$it['id'] ?></td>
                <td><?= e($it['icon'] ?: '') ?> <?= e($it['name']) ?><?= !empty($it['featured']) ? ' <span class="badge badge-ok">精选</span>' : '' ?></td>
                <td><?= e(YxServiceCenter::groupName((string)$it['group_key'])) ?></td>
                <td><?= e(YxServiceCenter::priceLabel($it, true)) ?></td>
                <td><?= e(YxServiceCenter::billName((string)$it['bill_type'])) ?></td>
                <td><?= count(YxServiceCenter::specs($it)) ?> 档</td>
                <td><?= (int)$it['sales'] ?></td>
                <td><?= (int)$it['sort'] ?></td>
                <td><span class="badge <?= (int)$it['status'] === 1 ? 'badge-ok' : 'badge-mute' ?>"><?= (int)$it['status'] === 1 ? '上架' : '下架' ?></span></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/service_center.php?edit=' . (int)$it['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确认删除该服务项目？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="10" class="empty">暂无服务项目</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card" id="orders">
    <h2>服务购买记录（共 <?= $total ?> 条）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>服务单号</th><th>客户</th><th>服务项目</th><th>规格</th><th>金额</th><th>联系方式</th><th>状态</th><th>到期日</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $r): ?>
            <tr>
                <td class="mono small"><?= e($r['sn']) ?></td>
                <td><?php $cn = $r['customer_id'] ? YxDb::value('SELECT username FROM ' . YxDb::table('customer') . ' WHERE id = ?', [(int)$r['customer_id']]) : ''; ?><?= e($cn ?: '—') ?></td>
                <td><?= e($r['name']) ?></td>
                <td><?= e($r['spec_text'] ?: '—') ?></td>
                <td><?= e(money($r['amount'], true)) ?></td>
                <td class="small"><?= e($r['contact'] ?: '—') ?></td>
                <td><span class="badge <?= e(YxServiceCenter::statusBadge((string)$r['status'])) ?>"><?= e(YxServiceCenter::statusName((string)$r['status'])) ?></span></td>
                <td><?= e($r['expire_at'] ?: '—') ?></td>
                <td class="small muted"><?= e($r['created_at']) ?></td>
                <td>
                    <form method="post" class="row-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="order_status">
                        <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
                        <select name="status" onchange="this.form.submit()">
                            <?php foreach (YxServiceCenter::STATUS as $sk => $sn): ?>
                                <option value="<?= e($sk) ?>" <?= (string)$r['status'] === $sk ? 'selected' : '' ?>><?= e($sn) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="10" class="empty">暂无购买记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<div class="card" style="max-width:880px">
    <h2>服务中心内容</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <label>公告 <textarea name="support_notice" rows="3" placeholder="显示在用户后台「服务中心」顶部"><?= e(setting('support_notice', '')) ?></textarea></label>
        <label>常见问题 FAQ（JSON：<code>[{"q":"问题","a":"答案"}]</code>）
            <textarea name="support_faq" class="mono" rows="6"><?= e(setting('support_faq', '')) ?></textarea>
        </label>

        <h2 class="mt-4">版本授权价格与有效期</h2>
        <div class="grid-3">
            <?php foreach (['basic' => '普惠版', 'pro' => '专业版', 'enterprise' => '企业版'] as $k => $name): ?>
                <label><?= e($name) ?> 价格（元） <input type="number" step="0.01" min="0" name="edition_price_<?= e($k) ?>" value="<?= e(setting('edition_price_' . $k, $k === 'basic' ? '10' : ($k === 'pro' ? '1600' : '3600'))) ?>"></label>
                <label><?= e($name) ?> 有效期（天，0=永久） <input type="number" min="0" name="edition_days_<?= e($k) ?>" value="<?= e(setting('edition_days_' . $k, $k === 'basic' ? '0' : '365')) ?>"></label>
            <?php endforeach; ?>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存</button></div>
    </form>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
