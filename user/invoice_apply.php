<?php
/**
 * 优享财务系统 · 发票申请
 *
 * 系统当前没有独立的发票数据表（见 README「已知限制」：实际开票未对接税控），
 * 因此本页不伪造「发票状态」，而是把开票信息按统一格式整理成一张财务工单，
 * 由财务在后台处理并回填结果。这样申请内容可追溯、可在工单里跟进进度。
 *
 * 只允许对「已结清」的账单申请开票，避免对未收到的款项开票。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
YxEdition::requireFeature('finance.invoice', url('user/index.php'));
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$INVOICE_KINDS = ['normal' => '增值税普通发票', 'special' => '增值税专用发票'];

/** 已结清且未申请过的账单（申请记录写在 remark 里，形如 invoice_req:TK-xxx） */
$paidInvoices = YxDb::all(
    'SELECT * FROM ' . YxDb::table('invoice')
    . " WHERE customer_id = ? AND status = 'paid'"
    . ' ORDER BY id DESC LIMIT 200',
    [$cid]
);

$preId = (int)($_GET['invoice_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids    = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['invoice_ids'] ?? [])))));
    $kind   = isset($INVOICE_KINDS[$_POST['kind'] ?? '']) ? (string)$_POST['kind'] : 'normal';
    $title  = trim((string)($_POST['title'] ?? ''));
    $taxNo  = trim((string)($_POST['tax_no'] ?? ''));
    $addr   = trim((string)($_POST['addr'] ?? ''));
    $tel    = trim((string)($_POST['tel'] ?? ''));
    $bank   = trim((string)($_POST['bank'] ?? ''));
    $account= trim((string)($_POST['account'] ?? ''));
    $email  = trim((string)($_POST['email'] ?? ''));
    $note   = trim((string)($_POST['note'] ?? ''));

    if (!$ids) {
        flash('请选择需要开票的账单', 'error');
        redirect(url('user/invoice_apply.php'));
    }
    if ($title === '') {
        flash('请填写发票抬头', 'error');
        redirect(url('user/invoice_apply.php'));
    }
    if ($kind === 'special' && ($taxNo === '' || $addr === '' || $tel === '' || $bank === '' || $account === '')) {
        flash('专用发票需完整填写税号、地址、电话、开户行与账号', 'error');
        redirect(url('user/invoice_apply.php'));
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('请填写正确的接收邮箱', 'error');
        redirect(url('user/invoice_apply.php'));
    }

    // 逐条校验归属与状态，任一条不合法整体拒绝，避免半途写入
    $picked = [];
    $amountSum = 0.0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $bind = array_merge([$cid], $ids);
    $chk = YxDb::all(
        'SELECT * FROM ' . YxDb::table('invoice')
        . ' WHERE customer_id = ? AND id IN (' . $in . ')',
        $bind
    );
    $found = [];
    foreach ($chk as $row) {
        $found[(int)$row['id']] = $row;
    }
    foreach ($ids as $id) {
        if (!isset($found[$id])) {
            flash('账单 #' . $id . ' 不存在或不属于当前账号', 'error');
            redirect(url('user/invoice_apply.php'));
        }
        if ($found[$id]['status'] !== 'paid') {
            flash('账单 ' . $found[$id]['sn'] . ' 尚未结清，不能申请开票', 'error');
            redirect(url('user/invoice_apply.php'));
        }
        if (strpos((string)$found[$id]['remark'], 'invoice_req:') !== false) {
            flash('账单 ' . $found[$id]['sn'] . ' 已提交过开票申请，请勿重复申请', 'error');
            redirect(url('user/invoice_apply.php'));
        }
        $picked[] = $found[$id];
        $amountSum += (float)$found[$id]['paid_amount'];
    }

    $lines = [];
    $lines[] = '【发票申请】';
    $lines[] = '发票类型：' . $INVOICE_KINDS[$kind];
    $lines[] = '发票抬头：' . $title;
    if ($taxNo !== '')   { $lines[] = '纳税人识别号：' . $taxNo; }
    if ($addr !== '')    { $lines[] = '注册地址：' . $addr; }
    if ($tel !== '')     { $lines[] = '注册电话：' . $tel; }
    if ($bank !== '')    { $lines[] = '开户银行：' . $bank; }
    if ($account !== '') { $lines[] = '银行账号：' . $account; }
    $lines[] = '接收邮箱：' . $email;
    $lines[] = '开票金额：' . money($amountSum, true);
    $lines[] = '包含账单：';
    foreach ($picked as $p) {
        $lines[] = '  · ' . $p['sn'] . ' ' . ($p['title'] ?: '-') . ' ' . money($p['paid_amount'], true);
    }
    if ($note !== '') {
        $lines[] = '备注：' . $note;
    }

    $tid = YxBusiness::createTicket([
        'customer_id' => $cid,
        'service_id'  => 0,
        'department'  => 'billing',
        'subject'     => '发票申请（' . count($picked) . ' 笔账单 / ' . money($amountSum, true) . '）',
        'priority'    => 'normal',
    ]);
    YxBusiness::replyTicket($tid, 'customer', $cid, implode("\n", $lines));

    // 在账单 remark 上标记已申请，形成幂等保护
    foreach ($picked as $p) {
        YxDb::update('invoice', [
            'remark' => trim((string)$p['remark'] . ' invoice_req:TK-' . $tid),
        ], 'id = :id', ['id' => (int)$p['id']]);
    }

    log_action('customer.invoice_apply', $customer['username'], [
        'ticket'  => $tid,
        'count'   => count($picked),
        'amount'  => $amountSum,
    ]);
    flash('开票申请已提交，工单编号 #' . $tid . '，财务将在 1-3 个工作日内处理');
    redirect(url('user/tickets.php?id=' . $tid));
}

$userTitle  = '发票申请';
$userActive = 'invoice_apply';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="alert alert-warn">
    本系统暂未直连税控开票，提交后由财务人工开具并通过预留邮箱发送。
    <b>仅「已结清」的账单可申请开票</b>，同一账单不可重复申请。
</div>

<form method="post" class="mt-4">
    <?= csrf_field() ?>
    <div class="card">
        <div class="section-head"><h2>选择账单</h2></div>
        <?php if (!$paidInvoices): ?>
            <p class="empty">暂无可开票的已结清账单。</p>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th style="width:40px"></th><th>账单号</th><th>标题</th><th>实收金额</th><th>结清时间</th><th>申请情况</th></tr></thead>
            <tbody>
            <?php foreach ($paidInvoices as $p): ?>
                <?php $applied = strpos((string)$p['remark'], 'invoice_req:') !== false; ?>
                <tr>
                    <td>
                        <?php if ($applied || $p['status'] !== 'paid'): ?>
                            <input type="checkbox" disabled title="已申请或未结清">
                        <?php else: ?>
                            <input type="checkbox" name="invoice_ids[]" value="<?= (int)$p['id'] ?>"
                                <?= $preId === (int)$p['id'] ? 'checked' : '' ?>>
                        <?php endif; ?>
                    </td>
                    <td class="mono small"><?= e($p['sn']) ?></td>
                    <td class="wrap"><?= e($p['title'] ?: '-') ?></td>
                    <td><?= e(money($p['paid_amount'], true)) ?></td>
                    <td class="small muted"><?= e($p['paid_at'] ?: '-') ?></td>
                    <td>
                        <?php if ($applied): ?>
                            <span class="badge badge-info">已申请</span>
                        <?php else: ?>
                            <span class="badge badge-ok">可申请</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="grid-2 mt-4">
        <div class="card">
            <h2>开票信息</h2>
            <label>发票类型
                <select name="kind">
                    <?php foreach ($INVOICE_KINDS as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>发票抬头 <input type="text" name="title" value="<?= e($customer['company'] ?: $customer['realname'] ?: '') ?>" required></label>
            <label>纳税人识别号 <input type="text" name="tax_no" value="<?= e($customer['license_no'] ?: '') ?>" placeholder="专用发票必填"></label>
            <label>注册地址 <input type="text" name="addr" placeholder="专用发票必填"></label>
            <label>注册电话 <input type="text" name="tel" placeholder="专用发票必填"></label>
            <label>开户银行 <input type="text" name="bank" placeholder="专用发票必填"></label>
            <label>银行账号 <input type="text" name="account" placeholder="专用发票必填"></label>
        </div>

        <div class="card">
            <h2>接收与备注</h2>
            <label>接收邮箱 <input type="email" name="email" value="<?= e($customer['email']) ?>" required></label>
            <label>备注 <textarea name="note" placeholder="如有特殊要求请在此说明"></textarea></label>
            <p class="small muted">
                提交后将以「财务计费」工单进入处理队列，可在
                <a href="<?= e(url('user/tickets.php')) ?>">工单支持</a> 中跟进。
            </p>
            <button class="btn btn-primary" type="submit">提交开票申请</button>
        </div>
    </div>
</form>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
