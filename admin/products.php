<?php
/**
 * 优享财务系统 · 产品与套餐管理
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$TYPES  = ['host' => '虚拟主机', 'vps' => '云服务器', 'server' => '独立服务器', 'other' => '其他 / 增值'];
$STATUS = ['1' => '上架', '0' => '下架'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_product') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [
            'group_id'    => (int)($_POST['group_id'] ?? 0),
            'name'        => trim((string)($_POST['name'] ?? '')),
            'type'        => isset($TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'host',
            'cycle'       => isset(YxBusiness::CYCLES[$_POST['cycle'] ?? '']) ? $_POST['cycle'] : 'month',
            'price'       => round_money($_POST['price'] ?? 0),
            'setup_fee'   => round_money($_POST['setup_fee'] ?? 0),
            'stock'       => (int)($_POST['stock'] ?? -1),
            'auto_setup'  => !empty($_POST['auto_setup']) ? 1 : 0,
            'edition_min' => isset(YxEdition::EDITIONS[$_POST['edition_min'] ?? '']) ? $_POST['edition_min'] : 'basic',
            'description' => trim((string)($_POST['description'] ?? '')),
            'status'      => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
            'sort'        => (int)($_POST['sort'] ?? 0),
        ];
        if ($data['name'] === '') {
            flash('产品名称不能为空', 'error');
            redirect(url('admin/products.php'));
        }
        // 允许周期：勾选为空视为不限制；勾了就按勾选顺序存成逗号串
        $picked = (array)($_POST['cycles'] ?? []);
        $keep   = array_filter(array_keys(YxBusiness::CYCLES), static function ($k) use ($picked) {
            return in_array($k, $picked, true);
        });
        $data['cycles'] = count($keep) === 0 || count($keep) === count(YxBusiness::CYCLES) ? null : implode(',', $keep);
        if ($data['cycles'] !== null && !in_array($data['cycle'], $keep, true)) {
            // 默认周期必须在允许范围内，否则下单页会拿不到默认值
            $data['cycle'] = reset($keep);
        }
        if ($id > 0) {
            YxDb::update('product', $data, 'id = :id', ['id' => $id]);
            log_action('product.update', $data['name'], ['id' => $id]);
            flash('产品已更新');
        } else {
            $data['created_at'] = now();
            $newId = YxDb::insert('product', $data);
            log_action('product.create', $data['name'], ['id' => $newId]);
            flash('产品已创建');
        }
        redirect(url('admin/products.php'));
    }

    if ($action === 'delete_product') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE product_id = ?', [$id]);
        if ($used > 0) {
            flash('该产品已被 ' . $used . ' 个业务实例使用，无法删除，建议改为下架', 'error');
        } else {
            YxDb::delete('product', 'id = :id', ['id' => $id]);
            log_action('product.delete', (string)$id);
            flash('产品已删除');
        }
        redirect(url('admin/products.php'));
    }

    if ($action === 'save_group') {
        $gid  = (int)($_POST['gid'] ?? 0);
        $name = trim((string)($_POST['gname'] ?? ''));
        if ($name !== '') {
            if ($gid > 0) {
                YxDb::update('product_group', ['name' => $name, 'sort' => (int)($_POST['gsort'] ?? 0)], 'id = :id', ['id' => $gid]);
                flash('分组已更新');
            } else {
                YxDb::insert('product_group', ['name' => $name, 'sort' => (int)($_POST['gsort'] ?? 0), 'status' => 1]);
                flash('分组已创建');
            }
        }
        redirect(url('admin/products.php'));
    }

    if ($action === 'delete_group') {
        $gid = (int)($_POST['gid'] ?? 0);
        YxDb::delete('product_group', 'id = :id', ['id' => $gid]);
        YxDb::exec('UPDATE ' . YxDb::table('product') . ' SET group_id = 0 WHERE group_id = ?', [$gid]);
        flash('分组已删除，原产品已归入「未分组」');
        redirect(url('admin/products.php'));
    }
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ?', [$editId]) : null;

$groups   = YxDb::all('SELECT * FROM ' . YxDb::table('product_group') . ' ORDER BY sort ASC, id ASC');
$groupMap = [];
foreach ($groups as $g) {
    $groupMap[(int)$g['id']] = $g['name'];
}

$keyword = trim((string)($_GET['kw'] ?? ''));
$where    = '';
$bind     = [];
if ($keyword !== '') {
    $where  = ' WHERE name LIKE ?';
    $bind[] = '%' . $keyword . '%';
}
$total   = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('product') . $where, $bind);
$page    = paginate($total, 15);
$products = YxDb::all(
    'SELECT * FROM ' . YxDb::table('product') . $where . ' ORDER BY sort ASC, id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$pageTitle  = '产品与套餐';
$activeMenu = 'products';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑产品 #' . (int)$editing['id'] : '新增产品' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>产品名称 <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
                <label>所属分组
                    <select name="group_id">
                        <option value="0">未分组</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= (int)($editing['group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>产品类型
                    <select name="type">
                        <?php foreach ($TYPES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['type'] ?? 'host') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>默认周期
                    <select name="cycle">
                        <?php foreach (YxBusiness::CYCLES as $k => $c): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['cycle'] ?? 'month') === $k ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>价格（元） <input type="number" step="0.01" min="0" name="price" value="<?= e($editing['price'] ?? '0.00') ?>"></label>
                <label>初装费（元） <input type="number" step="0.01" min="0" name="setup_fee" value="<?= e($editing['setup_fee'] ?? '0.00') ?>"></label>
                <label>库存（-1 表示不限） <input type="number" name="stock" value="<?= e($editing['stock'] ?? '-1') ?>"></label>
                <label>排序 <input type="number" name="sort" value="<?= e($editing['sort'] ?? 0) ?>"></label>
                <label>最低适用版本
                    <select name="edition_min">
                        <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['edition_min'] ?? 'basic') === $k ? 'selected' : '' ?>><?= e($ed['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>状态
                    <select name="status">
                        <?php foreach ($STATUS as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= (int)($editing['status'] ?? 1) === (int)$k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>产品说明 <textarea name="description"><?= e($editing['description'] ?? '') ?></textarea></label>
            <div class="form-row">
                <span class="muted small">允许下单的周期（全不勾 = 不限制；上游按年计费的商品建议只勾年付）</span>
                <div class="checkbox-row">
                    <?php $curCycles = YxBusiness::allowedCycles($editing ?: []); ?>
                    <?php foreach (YxBusiness::CYCLES as $k => $c): ?>
                        <label class="checkbox mt-0"><input type="checkbox" name="cycles[]" value="<?= e($k) ?>" <?= in_array($k, $curCycles, true) ? 'checked' : '' ?>> <?= e($c['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="checkbox"><input type="checkbox" name="auto_setup" value="1" <?= !empty($editing['auto_setup']) ? 'checked' : '' ?>> 支持自动开通（对接节点后下单即开通）</label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建产品' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/products.php')) ?>">取消编辑</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>产品分组</h2>
        <form method="post" class="inline-form" style="margin-bottom:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_group">
            <label class="grow">分组名称 <input type="text" name="gname" placeholder="如：香港云服务器" required></label>
            <label style="max-width:100px">排序 <input type="number" name="gsort" value="0"></label>
            <button class="btn btn-primary" type="submit">新增分组</button>
        </form>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>名称</th><th>排序</th><th>产品数</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <?php $cnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('product') . ' WHERE group_id = ?', [(int)$g['id']]); ?>
                <tr>
                    <td><?= e($g['name']) ?></td>
                    <td><?= (int)$g['sort'] ?></td>
                    <td><?= $cnt ?></td>
                    <td>
                        <form method="post" class="row-actions" onsubmit="return confirm('删除分组后其下产品将归入未分组，确认？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$groups): ?><tr><td colspan="4" class="empty">暂无分组</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="card">
    <div class="section-head">
        <h2>产品列表（共 <?= $total ?> 个）</h2>
        <div class="row-actions">
            <?php if (YxEdition::can('upstream')): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(url('admin/product_import.php')) ?>">导入商品</a>
            <?php endif; ?>
            <form method="get" class="inline-form">
                <label>关键词 <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="产品名称"></label>
                <button class="btn btn-ghost" type="submit">搜索</button>
            </form>
        </div>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>名称</th><th>分组</th><th>类型</th><th>价格</th><th>周期</th><th>可选周期</th><th>自动开通</th><th>最低版本</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?= (int)$p['id'] ?></td>
                <td class="wrap"><?= e($p['name']) ?></td>
                <td><?= e($groupMap[(int)$p['group_id']] ?? '未分组') ?></td>
                <td><?= e($TYPES[$p['type']] ?? $p['type']) ?></td>
                <td><?= e(money($p['price'], true)) ?></td>
                <td><?= e(YxBusiness::cycleName($p['cycle'])) ?></td>
                <td class="small"><?= e(YxBusiness::cycleLabel($p) ?: '不限') ?></td>
                <td><?= $p['auto_setup'] ? '<span class="badge badge-ok">是</span>' : '<span class="badge badge-mute">否</span>' ?></td>
                <td><?= e(YxEdition::name($p['edition_min'])) ?></td>
                <td><?= (int)$p['status'] === 1 ? '<span class="badge badge-ok">上架</span>' : '<span class="badge badge-mute">下架</span>' ?></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/products.php?edit=' . (int)$p['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确认删除该产品？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="11" class="empty">暂无产品</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
