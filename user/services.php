<?php
/**
 * 优享财务系统 · 我的业务
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $sid    = (int)($_POST['id'] ?? 0);

    $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ? AND customer_id = ?', [$sid, $cid]);
    if (!$svc) {
        flash('业务不存在或不属于当前账号', 'error');
        redirect(url('user/services.php'));
    }

    if ($action === 'renew') {
        if (!YxEdition::can('business.renew')) {
            flash('在线续费需要「专业版」及以上版本，请联系客服人工续费', 'error');
            redirect(url('user/services.php'));
        }
        if ($svc['status'] === 'terminated') {
            flash('已终止的业务无法续费', 'error');
            redirect(url('user/services.php'));
        }
        $cycle = (string)($_POST['cycle'] ?? $svc['billing_cycle']);
        $prod  = $svc['product_id'] ? YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ?', [(int)$svc['product_id']]) : null;
        $price = $prod ? YxBusiness::cyclePrice($prod, $cycle) : round_money((float)$svc['price'] * YxBusiness::cycleMonths($cycle));

        $created = YxBusiness::createOrder([
            'customer_id' => $cid,
            'type'        => 'renew',
            'product_id'  => (int)$svc['product_id'],
            'service_id'  => $sid,
            'name'        => '续费：' . $svc['name'],
            'amount'      => $price,
            'note'        => '用户自助续费',
        ]);
        if (!$created['ok']) {
            flash($created['msg'], 'error');
        } else {
            flash('续费订单已生成，请完成支付');
            redirect(url('user/orders.php?pay=' . (int)$created['order_id']));
        }
        redirect(url('user/services.php'));
    }
}

$rows = YxDb::all(
    'SELECT s.*, p.name AS product_name, p.type AS product_type FROM ' . YxDb::table('service') . ' s
     LEFT JOIN ' . YxDb::table('product') . ' p ON p.id = s.product_id
     WHERE s.customer_id = ? ORDER BY s.id DESC',
    [$cid]
);

/* ---------------- 订购产品：分类下拉 + 产品列表 ---------------- */
$tab = (string)($_GET['tab'] ?? 'order');
if (!in_array($tab, ['order', 'mine'], true)) {
    $tab = 'order';
}

$type = trim((string)($_GET['type'] ?? ''));
if ($type !== '' && !isset(YxBusiness::PRODUCT_TYPES[$type])) {
    $type = '';
}
$kw = trim((string)($_GET['kw'] ?? ''));

// 各分类的在售数量，用于下拉项展示（全部由一次分组查询得出）
$typeCount = ['all' => 0];
foreach (YxDb::all(
    'SELECT type, COUNT(*) AS c FROM ' . YxDb::table('product') . ' WHERE status = 1 GROUP BY type'
) as $r) {
    $typeCount[(string)$r['type']] = (int)$r['c'];
    $typeCount['all'] += (int)$r['c'];
}

$pw    = ' WHERE status = 1';
$pb    = [];
if ($type !== '') {
    $pw .= ' AND type = ?';
    $pb[] = $type;
}
if ($kw !== '') {
    $pw .= ' AND (name LIKE ? OR code LIKE ?)';
    $like = '%' . $kw . '%';
    $pb[] = $like;
    $pb[] = $like;
}
$products = YxDb::all(
    'SELECT * FROM ' . YxDb::table('product') . $pw . ' ORDER BY sort ASC, id ASC LIMIT 200',
    $pb
);

$userTitle  = '我的业务';
$userActive = 'services';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="cat-bar" style="margin-bottom:14px">
    <a class="<?= $tab === 'order' ? 'active' : '' ?>" href="<?= e(url('user/services.php?tab=order')) ?>">订购产品</a>
    <a class="<?= $tab === 'mine' ? 'active' : '' ?>" href="<?= e(url('user/services.php?tab=mine')) ?>">我的业务（<?= count($rows) ?>）</a>
</div>

<?php if ($tab === 'order'): ?>
    <div class="card" style="margin-bottom:16px">
        <h2>选择产品类别</h2>
        <p class="muted small" style="margin:0 0 12px">按类别筛选后选购，付款成功后业务会自动开通，并在「我的业务」中显示。</p>
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="tab" value="order">
            <label style="min-width:220px">产品类别
                <select name="type" onchange="this.form.submit()">
                    <option value="">全部类别（<?= (int)($typeCount['all'] ?? 0) ?>）</option>
                    <?php foreach (YxBusiness::ORDER_TYPES as $k):
                        $meta = YxBusiness::PRODUCT_TYPES[$k]; ?>
                        <option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>>
                            <?= e($meta['icon'] . ' ' . $meta['name']) ?>（<?= (int)($typeCount[$k] ?? 0) ?>）
                        </option>
                    <?php endforeach; ?>
                    <?php
                    // 历史分类（vps / other）有数据时也一并可选，避免旧产品无处可买
                    foreach (['vps', 'other'] as $k):
                        if (empty($typeCount[$k])) { continue; }
                        $meta = YxBusiness::PRODUCT_TYPES[$k]; ?>
                        <option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>>
                            <?= e($meta['icon'] . ' ' . $meta['name']) ?>（<?= (int)$typeCount[$k] ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="flex:1;min-width:200px">关键词
                <input type="text" name="kw" value="<?= e($kw) ?>" placeholder="产品名称 / 编码">
            </label>
            <button class="btn btn-primary btn-sm" type="submit">筛选</button>
            <?php if ($type !== '' || $kw !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('user/services.php?tab=order')) ?>">重置</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$products): ?>
        <div class="card"><div class="empty">该类别下暂无在售产品</div></div>
    <?php else: ?>
        <div class="grid-3">
            <?php foreach ($products as $p):
                $pType  = (string)($p['type'] ?? 'host');
                $cycles = YxBusiness::allowedCycles($p);
                $dft    = YxBusiness::defaultCycle($p);
            ?>
                <div class="card">
                    <h2 style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <span><?= e($p['name']) ?></span>
                        <span class="badge badge-info"><?= e(YxBusiness::productTypeName($pType)) ?></span>
                    </h2>
                    <p class="muted small" style="margin:4px 0 10px">
                        <?= e(YxBusiness::PRODUCT_TYPES[$pType]['desc'] ?? '') ?>
                    </p>
                    <p style="font-weight:700;margin:0 0 4px">
                        <?= e(money(YxBusiness::cyclePrice($p, $dft), true)) ?>
                        <span class="muted small">/ <?= e(YxBusiness::cycleName($dft)) ?></span>
                    </p>
                    <p class="muted small" style="margin:0 0 10px">
                        可选周期：<?= e(implode(' / ', array_map(function ($c) { return YxBusiness::cycleName($c); }, $cycles))) ?>
                        <?php if ((int)$p['stock'] === 0): ?> · <span style="color:#d9534f">已售罄</span><?php endif; ?>
                    </p>
                    <form method="post" action="<?= e(url('user/purchase.php')) ?>" style="display:flex;gap:6px;align-items:center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="kind" value="product">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <select name="cycle" style="flex:1">
                            <?php foreach ($cycles as $c): ?>
                                <option value="<?= e($c) ?>" <?= $c === $dft ? 'selected' : '' ?>><?= e(YxBusiness::cycleName($c)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary btn-sm" type="submit" <?= (int)$p['stock'] === 0 ? 'disabled' : '' ?>>立即订购</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'mine'): ?>
<?php if (YxEdition::can('upstream')):
    $upConns  = YxCustomerUpstream::rows($cid);
    $upReady  = 0;
    $upFirst  = 0;
    foreach ($upConns as $u) {
        if ((int)$u['status'] === 1) {
            $upFirst = $upFirst ?: (int)$u['id'];
            if ((int)$u['last_test_ok'] === 1) {
                $upReady++;
            }
        }
    }
?>
<div class="card">
    <h2>购买，自动对接</h2>
    <?php if (!$upConns): ?>
        <p class="muted small" style="margin:0 0 12px">
            填写你上游服务商的 API 地址、账户 ID 与密钥，之后可直接在这里下单，付款后自动向上游开通，无需等待人工处理。
        </p>
        <a class="btn btn-primary" href="<?= e(url('user/upstream.php')) ?>">填写上游信息，开启自动对接</a>
    <?php else: ?>
        <p class="muted small" style="margin:0 0 12px">
            已配置 <?= count($upConns) ?> 个上游对接，其中 <?= (int)$upReady ?> 个测试正常。
            <?php if ($upFirst <= 0): ?>当前没有启用的对接，启用后即可下单。<?php endif; ?>
        </p>
        <div class="row-actions">
            <?php if ($upFirst > 0): ?>
                <a class="btn btn-primary" href="<?= e(url('user/upstream.php?id=' . $upFirst)) ?>">选购上游产品（付款即自动开通）</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(url('user/upstream.php')) ?>">管理上游对接</a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>业务列表（<?= count($rows) ?> 个）</h2>
    <p class="muted small">已开通业务可查看 IP 与登录信息；到期前 7 天系统会提示续费。</p>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>编号</th><th>业务名称</th><th>产品</th><th>类别</th><th>IP</th><th>登录信息</th><th>周期</th><th>价格</th><th>到期日</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
            <tr>
                <td class="mono small"><?= e($s['sn']) ?></td>
                <td class="wrap"><?= e($s['name']) ?></td>
                <td class="wrap"><?= e($s['product_name'] ?: '-') ?></td>
                <td class="small"><?= $s['product_type'] ? e(YxBusiness::productTypeName((string)$s['product_type'])) : '-' ?></td>
                <td class="mono small"><?= e($s['ip'] ?: '-') ?></td>
                <td class="small mono">
                    <?php if ($s['login_user']): ?>
                        <?= e($s['login_user']) ?> /
                        <span class="yx-pass" data-pass="<?= e(yx_decrypt((string)$s['login_pass'])) ?>">••••••</span>
                        <a href="javascript:void(0)" class="yx-pass-toggle" data-shown="0">显示</a>
                        <?= $s['hostname'] ? '<br><span class="muted">主机：' . e($s['hostname']) . '</span>' : '' ?>
                    <?php else: ?>-<?php endif; ?>
                </td>
                <td><?= e(YxBusiness::cycleName($s['billing_cycle'])) ?></td>
                <td><?= e(money($s['price'], true)) ?></td>
                <td>
                    <?php if ($s['next_due_date'] && $s['next_due_date'] < date('Y-m-d')): ?>
                        <span class="badge badge-bad"><?= e($s['next_due_date']) ?> 已到期</span>
                    <?php else: ?>
                        <?= e($s['next_due_date'] ?: '-') ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $cls = ['active' => 'badge-ok', 'pending' => 'badge-warn', 'suspended' => 'badge-warn', 'terminated' => 'badge-bad', 'expired' => 'badge-bad'][$s['status']] ?? 'badge-mute'; ?>
                    <span class="badge <?= $cls ?>"><?= e(YxBusiness::serviceStatusName($s['status'])) ?></span>
                </td>
                <td>
                    <?php if (!in_array($s['status'], ['terminated'], true)): ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="renew">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <select name="cycle" class="field-inline">
                                <?php foreach (YxBusiness::CYCLES as $k => $c): ?>
                                    <option value="<?= e($k) ?>" <?= $s['billing_cycle'] === $k ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary" type="submit">续费</button>
                        </form>
                    <?php else: ?><span class="muted small">-</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="empty">还没有业务，<a href="<?= e(url('user/services.php?tab=order')) ?>">去订购产品</a></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
