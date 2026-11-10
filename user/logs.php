<?php
/**
 * 优享财务系统 · 系统日志
 *
 * 展示当前客户的账户操作审计记录（改密、绑定、接口开关等）与登录记录。
 * 数据来源：admin_log 表中 target 为「用户名」或「customer#ID」的记录。
 *
 * 安全约束：只按当前登录客户自身的标识过滤，任何 action 前缀都不会跨客户查询。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

/**
 * 审计明细 JSON → 可读文本（仅展示已知的脱敏字段）
 */
function yx_log_detail_text(?string $detail): string
{
    if ($detail === null || $detail === '') {
        return '-';
    }
    $data = json_decode($detail, true);
    if (!is_array($data)) {
        return '-';
    }
    $parts = [];
    foreach ($data as $k => $v) {
        if (is_bool($v)) {
            $v = $v ? '是' : '否';
        } elseif (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $parts[] = $k . '=' . $v;
    }
    return $parts ? implode('，', $parts) : '-';
}

$ACTIONS = [
    'customer.change_password'  => '修改登录密码',
    'customer.session_reset'    => '重置会话',
    'customer.bind_phone'       => '绑定 / 更换手机',
    'customer.unbind_phone'     => '解绑手机',
    'customer.bind_email'       => '绑定 / 更换邮箱',
    'customer.unbind_email'     => '解绑邮箱',
    'customer.api_enable'       => '开启接口对接',
    'customer.api_disable'      => '关闭接口对接',
];

$typeF = (string)($_GET['type'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));

// target 有两种写法：用户名（多数场景）与 customer#ID（接口开关）
$targets = [(string)$customer['username'], 'customer#' . $cid];
$in      = implode(',', array_fill(0, count($targets), '?'));

$where = ' WHERE target IN (' . $in . ')';
$bind  = $targets;

if ($typeF === 'security') {
    // 安全类：密码、会话、绑定
    $where .= " AND action IN ('customer.change_password','customer.session_reset','customer.bind_phone','customer.unbind_phone','customer.bind_email','customer.unbind_email')";
} elseif ($typeF === 'api') {
    $where .= " AND action LIKE 'customer.api%'";
}

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('admin_log') . $where, $bind);
$pager = paginate($total, 20);
$rows  = YxDb::all(
    'SELECT action, target, detail, ip, created_at FROM ' . YxDb::table('admin_log')
    . $where . ' ORDER BY id DESC LIMIT ' . $pager['per_page'] . ' OFFSET ' . $pager['offset'],
    $bind
);

$userTitle  = '系统日志';
$userActive = 'logs';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2>账户操作记录<em><?= $total ?> 条</em></h2>
        <form method="get" class="inline-form">
            <label>类型
                <select name="type">
                    <option value="">全部</option>
                    <option value="security" <?= $typeF === 'security' ? 'selected' : '' ?>>安全相关</option>
                    <option value="api" <?= $typeF === 'api' ? 'selected' : '' ?>>接口对接</option>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">筛选</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>时间</th><th>操作</th><th>明细</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="small muted"><?= e($r['created_at']) ?></td>
                <td><?= e($ACTIONS[$r['action']] ?? $r['action']) ?></td>
                <td class="small wrap muted"><?= e(yx_log_detail_text($r['detail'])) ?></td>
                <td class="mono small"><?= e($r['ip'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="empty">暂无操作记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($pager) ?>
</div>

<div class="card mt-4">
    <h2>登录信息</h2>
    <table class="data">
        <tr><th>最近登录时间</th><td><?= e($customer['last_login_at'] ?: '-') ?></td></tr>
        <tr><th>注册时间</th><td><?= e($customer['created_at'] ?: '-') ?></td></tr>
        <tr><th>注册 IP</th><td class="mono"><?= e($customer['reg_ip'] ?: '-') ?></td></tr>
    </table>
    <p class="small muted">本站未单独保存历史登录明细，如需查询更早的登录记录请联系客服。</p>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
