<?php
/**
 * 优享财务系统 · 优惠券（专业版及以上）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('coupon');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        if ($code === '') {
            flash('优惠码不能为空', 'error');
            redirect(url('admin/coupons.php'));
        }
        $data = [
            'code'        => $code,
            'type'        => ($_POST['type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed',
            'value'       => round_money($_POST['value'] ?? 0),
            'min_amount'  => round_money($_POST['min_amount'] ?? 0),
            'total'       => (int)($_POST['total'] ?? 0),
            'edition_min' => isset(YxEdition::EDITIONS[$_POST['edition_min'] ?? '']) ? $_POST['edition_min'] : 'pro',
            'expire_at'   => (string)($_POST['expire_at'] ?? '') ?: null,
            'status'      => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
        ];
        $dup = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('coupon') . ' WHERE code = ? AND id <> ?', [$code, $id]);
        if ($dup) {
            flash('优惠码已存在', 'error');
            redirect(url('admin/coupons.php'));
        }
        if ($id > 0) {
            YxDb::update('coupon', $data, 'id = :id', ['id' => $id]);
            flash('优惠券已更新');
        } else {
            YxDb::insert('coupon', $data);
            flash('优惠券已创建');
        }
        log_action('coupon.save', $code);
        redirect(url('admin/coupons.php'));
    }

    if ($action === 'delete') {
        YxDb::delete('coupon', 'id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        flash('优惠券已删除');
        redirect(url('admin/coupons.php'));
    }
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('coupon') . ' WHERE id = ?', [$editId]) : null;
$rows    = YxDb::all('SELECT * FROM ' . YxDb::table('coupon') . ' ORDER BY id DESC');

$pageTitle  = '优惠券';
$activeMenu = 'coupons';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑优惠券' : '新增优惠券' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>优惠码 <input type="text" name="code" required value="<?= e($editing['code'] ?? '') ?>" placeholder="YX10"></label>
                <label>类型
                    <select name="type">
                        <option value="fixed" <?= ($editing['type'] ?? 'fixed') === 'fixed' ? 'selected' : '' ?>>固定金额（元）</option>
                        <option value="percent" <?= ($editing['type'] ?? '') === 'percent' ? 'selected' : '' ?>>百分比（%）</option>
                    </select>
                </label>
                <label>优惠值 <input type="number" step="0.01" name="value" value="<?= e($editing['value'] ?? '0.00') ?>"></label>
                <label>最低消费 <input type="number" step="0.01" name="min_amount" value="<?= e($editing['min_amount'] ?? '0.00') ?>"></label>
                <label>发行总量（0 表示不限） <input type="number" name="total" value="<?= e($editing['total'] ?? 0) ?>"></label>
                <label>到期日期 <input type="date" name="expire_at" value="<?= e($editing['expire_at'] ?? '') ?>"></label>
                <label>最低适用版本
                    <select name="edition_min">
                        <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['edition_min'] ?? 'pro') === $k ? 'selected' : '' ?>><?= e($ed['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建优惠券' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/coupons.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>优惠券列表</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>优惠码</th><th>优惠</th><th>门槛</th><th>已用 / 总量</th><th>到期</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td class="mono"><?= e($c['code']) ?></td>
                    <td><?= $c['type'] === 'percent' ? e($c['value']) . '%' : e(money($c['value'], true)) ?></td>
                    <td><?= e(money($c['min_amount'], true)) ?></td>
                    <td><?= (int)$c['used'] ?> / <?= (int)$c['total'] === 0 ? '不限' : (int)$c['total'] ?></td>
                    <td class="small"><?= e($c['expire_at'] ?: '不限') ?></td>
                    <td><?= (int)$c['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/coupons.php?edit=' . (int)$c['id'])) ?>">编辑</a>
                            <form method="post" onsubmit="return confirm('确认删除该优惠券？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无优惠券</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
