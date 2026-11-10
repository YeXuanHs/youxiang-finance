<?php
/**
 * 优享财务系统 · 账单 / 发票中心
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('finance.invoice');

$STATUS = ['unpaid' => '未支付', 'partial' => '部分支付', 'paid' => '已结清', 'void' => '已作废'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'pay') {
        $inv = YxDb::one('SELECT * FROM ' . YxDb::table('invoice') . ' WHERE id = ?', [$id]);
        if (!$inv) {
            $res = ['ok' => false, 'msg' => '账单不存在'];
        } elseif ($inv['status'] === 'paid' || $inv['status'] === 'void') {
            $res = ['ok' => false, 'msg' => '账单已结清或已作废'];
        } else {
            // 先交付订单，成功后才核销账单：
            // 反过来会出现「账单已结清、订单却还没支付」的错账。
            $res = ['ok' => true, 'msg' => '账单已结清'];
            $order = (int)$inv['order_id'] > 0
                ? YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$inv['order_id']])
                : null;
            if ($order && $order['status'] === 'unpaid') {
                $pay = YxBusiness::payOrderExternal((int)$order['id'], 'invoice');
                if (empty($pay['ok'])) {
                    $res = ['ok' => false, 'msg' => '订单交付失败，账单未核销：' . (string)($pay['msg'] ?? '未知原因')];
                }
            }
            if (!empty($res['ok'])) {
                $done = YxFinance::payInvoice($id, (float)$inv['amount'] - (float)$inv['paid_amount']);
                if (!$done) {
                    $res = ['ok' => false, 'msg' => '账单状态已变化（可能已被其他操作结清），请刷新后重试'];
                }
            }
        }
    } elseif ($action === 'void') {
        $n = YxDb::exec("UPDATE " . YxDb::table('invoice') . " SET status = 'void' WHERE id = ? AND status <> 'paid'", [$id]);
        $res = $n ? ['ok' => true, 'msg' => '账单已作废'] : ['ok' => false, 'msg' => '已结清的账单不可作废'];
    } elseif ($action === 'create') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if ($cid <= 0) {
            $res = ['ok' => false, 'msg' => '请选择客户'];
        } else {
            $id2 = YxFinance::createInvoice([
                'customer_id' => $cid,
                'title'       => trim((string)($_POST['title'] ?? '手工账单')),
                'amount'      => (float)($_POST['amount'] ?? 0),
                'type'        => 'manual',
                'due_date'    => (string)($_POST['due_date'] ?? date('Y-m-d', strtotime('+7 day'))),
                'remark'      => trim((string)($_POST['remark'] ?? '')),
            ]);
            $res = ['ok' => true, 'msg' => '账单已生成（#' . $id2 . '）'];
        }
    }

    log_action('invoice.' . $action, 'invoice#' . $id, ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/invoices.php'));
}

$statusF = (string)($_GET['status'] ?? '');
$keyword = trim((string)($_GET['kw'] ?? ''));
$where   = ' WHERE 1=1';
$bind    = [];
if ($statusF !== '' && isset($STATUS[$statusF])) {
    $where .= ' AND i.status = ?';
    $bind[] = $statusF;
}
if ($keyword !== '') {
    $where .= ' AND (i.sn LIKE ? OR i.title LIKE ? OR c.username LIKE ?)';
    $kw = '%' . $keyword . '%';
    array_push($bind, $kw, $kw, $kw);
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('invoice') . ' i LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = i.customer_id' . $where, $bind);
$page  = paginate($total, 15);
$rows  = YxDb::all(
    'SELECT i.*, c.username FROM ' . YxDb::table('invoice') . ' i
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = i.customer_id
     ' . $where . ' ORDER BY i.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $bind
);

$customers = YxDb::all('SELECT id, username FROM ' . YxDb::table('customer') . ' ORDER BY id DESC LIMIT 200');
$sumUnpaid = (float)YxDb::value('SELECT COALESCE(SUM(amount - paid_amount),0) FROM ' . YxDb::table('invoice') . " WHERE status IN ('unpaid','partial')");
$sumPaid   = (float)YxDb::value('SELECT COALESCE(SUM(paid_amount),0) FROM ' . YxDb::table('invoice') . " WHERE status = 'paid'");

$pageTitle  = '账单中心';
$activeMenu = 'invoices';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-3">
    <div class="stat"><span>待收金额</span><strong><?= e(money($sumUnpaid, true)) ?></strong><small>未支付 + 部分支付</small></div>
    <div class="stat"><span>已结清金额</span><strong><?= e(money($sumPaid, true)) ?></strong></div>
    <div class="stat"><span>账单总数</span><strong><?= $total ?></strong></div>
</div>

<div class="card" style="margin-top:16px">
    <details>
        <summary style="cursor:pointer;color:var(--primary)">＋ 手工生成账单</summary>
        <form method="post" class="inline-form" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="grow">客户
                <select name="customer_id" required>
                    <option value="">请选择客户</option>
                    <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['username']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="grow">账单标题 <input type="text" name="title" placeholder="如：2026 年 10 月服务费"></label>
            <label>金额 <input type="number" step="0.01" name="amount" required></label>
            <label>到期日 <input type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+7 day'))) ?>"></label>
            <label class="grow">备注 <input type="text" name="remark"></label>
            <button class="btn btn-primary" type="submit">生成账单</button>
        </form>
    </details>
</div>

<div class="card">
    <div class="section-head">
        <h2>账单列表</h2>
        <form method="get" class="inline-form">
            <label>关键词 <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="账单号 / 标题 / 客户"></label>
            <label>状态
                <select name="status">
                    <option value="">全部</option>
                    <?php foreach ($STATUS as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">查询</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>账单号</th><th>客户</th><th>标题</th><th>类型</th><th>金额</th><th>已收</th><th>到期日</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i): ?>
            <tr>
                <td class="mono small"><?= e($i['sn']) ?></td>
                <td><?= e($i['username'] ?: '-') ?></td>
                <td class="wrap"><?= e($i['title']) ?></td>
                <td><?= e($i['type'] === 'manual' ? '手工' : '业务') ?></td>
                <td><?= e(money($i['amount'], true)) ?></td>
                <td><?= e(money($i['paid_amount'], true)) ?></td>
                <td>
                    <span class="<?= ($i['status'] !== 'paid' && $i['due_date'] && $i['due_date'] < date('Y-m-d')) ? 'badge badge-bad' : '' ?>">
                        <?= e($i['due_date'] ?: '-') ?>
                    </span>
                </td>
                <td>
                    <?php $cls = ['paid' => 'badge-ok', 'unpaid' => 'badge-warn', 'partial' => 'badge-info', 'void' => 'badge-mute'][$i['status']] ?? 'badge-mute'; ?>
                    <span class="badge <?= $cls ?>"><?= e($STATUS[$i['status']] ?? $i['status']) ?></span>
                </td>
                <td>
                    <div class="row-actions">
                        <?php if (in_array($i['status'], ['unpaid', 'partial'], true)): ?>
                            <form method="post" onsubmit="return confirm('确认将该账单标记为已结清？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="pay">
                                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                <button class="btn btn-sm btn-ok" type="submit">结清</button>
                            </form>
                            <form method="post" onsubmit="return confirm('确认作废该账单？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="void">
                                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">作废</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small">-</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty">暂无账单</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
