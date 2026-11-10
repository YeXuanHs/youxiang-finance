<?php
/**
 * 优享财务系统 · 业务实例管理
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('business.service');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    switch ($action) {
        case 'activate':
            $res = YxBusiness::activateService($id, trim((string)($_POST['ip'] ?? '')));
            break;
        case 'suspend':
            $res = YxBusiness::suspendService($id, trim((string)($_POST['reason'] ?? '')));
            break;
        case 'unsuspend':
            $res = YxBusiness::unsuspendService($id);
            break;
        case 'terminate':
            $res = YxBusiness::terminateService($id, trim((string)($_POST['reason'] ?? '')));
            break;
        case 'renew':
            $res = YxBusiness::renewService($id, (string)($_POST['cycle'] ?? ''), (float)($_POST['price'] ?? 0), 'admin');
            break;
        case 'create':
            $cid = (int)($_POST['customer_id'] ?? 0);
            if ($cid <= 0) {
                $res = ['ok' => false, 'msg' => '请选择客户'];
                break;
            }
            $pid  = (int)($_POST['product_id'] ?? 0);
            $prod = $pid ? YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ?', [$pid]) : null;
            $sid  = YxBusiness::createService([
                'customer_id'   => $cid,
                'product_id'    => $pid,
                'node_id'       => (int)($_POST['node_id'] ?? 0),
                'name'          => trim((string)($_POST['name'] ?? ($prod['name'] ?? '手动开通业务'))),
                'billing_cycle' => (string)($_POST['cycle'] ?? ($prod['cycle'] ?? 'month')),
                'price'         => (float)($_POST['price'] ?? ($prod['price'] ?? 0)),
                'ip'            => trim((string)($_POST['ip'] ?? '')),
                'status'        => 'pending',
            ]);
            $res = ['ok' => true, 'msg' => '业务已创建（编号 #' . $sid . '）'];
            break;
    }

    log_action('service.' . $action, 'service#' . $id, ['ok' => $res['ok']]);
    flash($res['msg'], $res['ok'] ? 'success' : 'error');
    redirect(url('admin/services.php' . (isset($_POST['back']) ? '?' . $_POST['back'] : '')));
}

$status  = (string)($_GET['status'] ?? '');
$keyword = trim((string)($_GET['kw'] ?? ''));
$where   = ' WHERE 1=1';
$bind    = [];
if ($status !== '' && isset(YxBusiness::SERVICE_STATUS[$status])) {
    $where .= ' AND s.status = ?';
    $bind[] = $status;
}
if ($keyword !== '') {
    $where .= ' AND (s.name LIKE ? OR s.sn LIKE ? OR s.ip LIKE ? OR s.hostname LIKE ?)';
    $kw = '%' . $keyword . '%';
    array_push($bind, $kw, $kw, $kw, $kw);
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' s' . $where, $bind);
$page  = paginate($total, 15);
$rows  = YxDb::all(
    'SELECT s.*, c.username, c.email, p.name AS product_name, n.name AS node_name
     FROM ' . YxDb::table('service') . ' s
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = s.customer_id
     LEFT JOIN ' . YxDb::table('product') . ' p ON p.id = s.product_id
     LEFT JOIN ' . YxDb::table('node') . ' n ON n.id = s.node_id
     ' . $where . ' ORDER BY s.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$customers = YxDb::all('SELECT id, username, email FROM ' . YxDb::table('customer') . ' ORDER BY id DESC LIMIT 200');
$products  = YxDb::all('SELECT id, name, price, cycle FROM ' . YxDb::table('product') . ' WHERE status = 1 ORDER BY id DESC LIMIT 200');
$nodes     = YxDb::all('SELECT id, name FROM ' . YxDb::table('node') . ' ORDER BY id DESC');

$pageTitle  = '业务实例';
$activeMenu = 'services';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2>筛选（共 <?= $total ?> 个业务）</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/services.php?status=pending')) ?>">待人工开通（<?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . " WHERE status = 'pending'") ?>）</a>
        <form method="get" class="inline-form">
            <label>状态
                <select name="status">
                    <option value="">全部状态</option>
                    <?php foreach (YxBusiness::SERVICE_STATUS as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>关键词 <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="名称 / 编号 / IP"></label>
            <button class="btn btn-primary" type="submit">查询</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/services.php')) ?>">重置</a>
        </form>
    </div>

    <details style="margin-bottom:14px">
        <summary style="cursor:pointer;color:var(--primary)">＋ 手动开通业务</summary>
        <form method="post" class="inline-form" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="grow">客户
                <select name="customer_id" required>
                    <option value="">请选择客户</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['username']) ?>（<?= e($c['email']) ?>）</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="grow">产品
                <select name="product_id">
                    <option value="0">自定义（不关联产品）</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-price="<?= e($p['price']) ?>" data-cycle="<?= e($p['cycle']) ?>">
                            <?= e($p['name']) ?> · <?= e(money($p['price'], true)) ?>/<?= e(YxBusiness::cycleName($p['cycle'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="grow">业务名称 <input type="text" name="name" placeholder="留空取产品名"></label>
            <label>节点
                <select name="node_id">
                    <option value="0">不指定</option>
                    <?php foreach ($nodes as $n): ?><option value="<?= (int)$n['id'] ?>"><?= e($n['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>周期
                <select name="cycle">
                    <?php foreach (YxBusiness::CYCLES as $k => $c): ?><option value="<?= e($k) ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>价格 <input type="number" step="0.01" name="price" value="0.00"></label>
            <label>IP <input type="text" name="ip" placeholder="可留空"></label>
            <button class="btn btn-primary" type="submit">创建业务</button>
        </form>
    </details>

    <div class="table-wrap"><table class="data">
        <thead><tr><th>编号</th><th>业务名称</th><th>客户</th><th>产品</th><th>节点</th><th>IP</th><th>周期</th><th>价格</th><th>到期日</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
            <tr>
                <td class="mono small"><?= e($s['sn']) ?></td>
                <td class="wrap"><?= e($s['name']) ?></td>
                <td><?= e($s['username'] ?: '-') ?></td>
                <td class="wrap"><?= e($s['product_name'] ?: '-') ?></td>
                <td><?= e($s['node_name'] ?: '-') ?></td>
                <td class="mono small"><?= e($s['ip'] ?: '-') ?></td>
                <td><?= e(YxBusiness::cycleName($s['billing_cycle'])) ?></td>
                <td><?= e(money($s['price'], true)) ?></td>
                <td><?= e($s['next_due_date'] ?: '-') ?></td>
                <td>
                    <?php
                    $cls = ['active' => 'badge-ok', 'pending' => 'badge-warn', 'suspended' => 'badge-warn', 'terminated' => 'badge-bad', 'expired' => 'badge-bad'][$s['status']] ?? 'badge-mute';
                    ?>
                    <span class="badge <?= $cls ?>"><?= e(YxBusiness::serviceStatusName($s['status'])) ?></span>
                </td>
                <td>
                    <div class="row-actions">
                        <?php $post = function (string $act, string $label, string $cls, array $extra = []) use ($s) {
                            echo '<form method="post" class="row-actions">' . csrf_field();
                            echo '<input type="hidden" name="action" value="' . e($act) . '">';
                            echo '<input type="hidden" name="id" value="' . (int)$s['id'] . '">';
                            foreach ($extra as $k => $v) {
                                echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
                            }
                            echo '<button class="btn btn-sm ' . $cls . '" type="submit">' . e($label) . '</button></form>';
                        }; ?>
                        <?php if (in_array($s['status'], ['pending', 'suspended'], true)) $post('activate', '开通', 'btn-ok'); ?>
                        <?php if ($s['status'] === 'active') $post('suspend', '暂停', 'btn-warn'); ?>
                        <?php if ($s['status'] === 'suspended') $post('unsuspend', '恢复', 'btn-ok'); ?>
                        <?php $post('renew', '续费', 'btn-ghost', ['cycle' => $s['billing_cycle'], 'price' => $s['price']]); ?>
                        <?php if ($s['status'] !== 'terminated') $post('terminate', '终止', 'btn-bad'); ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="empty">暂无业务实例</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
