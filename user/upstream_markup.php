<?php
/**
 * 优享财务系统 · 上游对接代理等级加价（插件 upstream-agent-markup · 客户侧）
 *
 * 本页面向已购买插件的客户，展示「与我有关」的部分：
 *  - 我的代理等级与适用加价规则（等级由平台在后台分配）
 *  - 加价试算：输入上游成本价，算出加价额与实付
 *  - 加价明细：已生成订单里落库的加价快照（规则后续调整不影响历史单）
 *  - 我的上游对接连通性：逐个检测并回写最近检测结论
 *
 * 规则配置与代理等级分配在后台 admin/upstream_markup.php，此处只读 + 自检。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

$app      = YxUpstreamMarkup::app();
$licensed = $app ? YxUpstreamMarkup::licensed($cid) : false;
$ent      = $app ? YxBusiness::entitlement((int)$app['id'], $cid) : null;

// 我的代理归属与等级
$agentId  = (int)($customer['agent_id'] ?? 0);
$agentRow = $agentId > 0
    ? YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$agentId])
    : null;
$myLevelKey = YxUpstreamMarkup::levelForCustomer($cid);
$myLevel    = $myLevelKey !== '' ? YxUpstreamMarkup::level($myLevelKey) : null;

$calc   = null;
$tested = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if (!$licensed) {
        flash('该功能需购买「代理等级加价」插件后使用', 'error');
        redirect(url('user/upstream_markup.php'));
    }

    if ($action === 'calc') {
        $base = (float)($_POST['base'] ?? 0);
        $base = round_money(max(0, min(1000000, $base)));
        $calc = ['base' => $base, 'q' => YxUpstreamMarkup::quote($myLevelKey, $base)];
    } elseif ($action === 'check_mine') {
        $items  = [];
        $failed = 0;
        foreach (YxCustomerUpstream::rows($cid) as $row) {
            $res = YxCustomerUpstream::test((int)$row['id'], $cid);
            if (empty($res['ok'])) {
                $failed++;
            }
            $items[] = [
                'name' => (string)$row['name'],
                'ok'   => !empty($res['ok']),
                'msg'  => mb_substr((string)($res['msg'] ?? ''), 0, 200),
            ];
        }
        $tested = ['items' => $items, 'total' => count($items), 'failed' => $failed];
        log_action('upstream_markup.customer_check', 'customer#' . $cid, ['total' => count($items), 'failed' => $failed]);
        if (!$items) {
            flash('还没有上游对接，请先在「自动对接」中添加', 'warn');
        }
    }
}

$conns = $licensed ? YxCustomerUpstream::rows($cid) : [];

$orders = [];
if ($licensed) {
    $orders = YxDb::all(
        'SELECT id, sn, name, total, paid_at, created_at, upstream_markup FROM ' . YxDb::table('order')
        . " WHERE customer_id = ? AND COALESCE(upstream_markup, '') <> '' ORDER BY id DESC LIMIT 20",
        [$cid]
    ) ?: [];
    foreach ($orders as $i => $o) {
        $orders[$i]['mk'] = YxUpstreamMarkup::orderMarkup($o);
    }
}

$userTitle  = '代理等级加价';
$userActive = 'upstream_markup';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if (!$app): ?>
<div class="card">
    <h2>插件未上架</h2>
    <div class="empty">商城暂未上架本插件，请联系平台管理员。</div>
</div>
<?php elseif (!$licensed): ?>
<div class="card">
    <h2><?= e($app['name']) ?></h2>
    <p class="muted small">
        本插件按代理等级对上游自动对接的订单叠加加价：等级由平台分配，加价基数可选上游成本价或当前售价，
        每级可用固定额或百分比。下单时把加价写入订单，之后调整规则不影响已生成的订单。
    </p>
    <p class="muted small">购买后本页解锁：我的等级、加价试算、加价明细、上游对接自检。</p>
    <div class="alert alert-warn">尚未购买该插件，当前显示为购买入口。</div>
    <form method="post" action="<?= e(url('user/app_redeem.php')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
        <label>绑定域名
            <input type="text" name="domain" required placeholder="例如 www.example.com" value="<?= e(current_domain()) ?>">
        </label>
        <button class="btn btn-primary" type="submit">购买插件（<?= e(YxBusiness::priceLabel($app)) ?>）</button>
        <a class="btn" href="<?= e(url('store/detail.php?id=' . (int)$app['id'])) ?>">查看详情</a>
    </form>
</div>
<?php else: ?>

<div class="card">
    <h2>插件状态</h2>
    <p class="muted small">
        已解锁<?= $ent && !empty($ent['expire_at']) ? '，有效期至 ' . e($ent['expire_at']) : '' ?>。
        加价基数：<strong><?= e(YxUpstreamMarkup::baseName()) ?></strong>。
        当前全局开关：<span class="badge <?= YxUpstreamMarkup::enabled() ? 'badge-ok' : 'badge-mute' ?>"><?= YxUpstreamMarkup::enabled() ? '已启用' : '已停用' ?></span>
    </p>
    <?php if (!YxUpstreamMarkup::enabled()): ?>
        <div class="alert alert-warn">平台已暂停本插件，订单暂不叠加加价。</div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>我的代理等级</h2>
    <?php if ($agentRow && $myLevel): ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>所属代理</th><th>等级</th><th>加价规则</th><th>加价基数</th></tr></thead>
            <tbody>
            <tr>
                <td><?= e($agentRow['name']) ?><span class="muted small"> · <?= e($agentRow['username']) ?></span></td>
                <td><span class="badge badge-info"><?= e($myLevel['name']) ?></span><?php if ($myLevel['note'] !== ''): ?><span class="muted small"> <?= e($myLevel['note']) ?></span><?php endif; ?></td>
                <td><?= e(YxUpstreamMarkup::ruleText($myLevel)) ?></td>
                <td class="muted small"><?= e(YxUpstreamMarkup::baseName()) ?></td>
            </tr>
            </tbody>
        </table></div>
    <?php elseif ($agentRow): ?>
        <p class="muted small">所属代理「<?= e($agentRow['name']) ?>」暂未分配等级，名下客户下单按原价。</p>
    <?php else: ?>
        <p class="muted small">当前账号未关联渠道 / 代理，下单按原价，不叠加等级加价。</p>
        <p class="muted small">需要等级加价请联系平台客服分配代理归属与等级。</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>加价试算</h2>
    <p class="muted small">
        输入一份上游价格，按我的等级算出加价额与实付。试算仅用于预览，不生成订单。
        <?php if ($myLevel): ?>当前等级「<?= e($myLevel['name']) ?>」<?= e(YxUpstreamMarkup::ruleText($myLevel)) ?>。<?php endif; ?>
    </p>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="calc">
        <label>加价基数（<?= e(YxUpstreamMarkup::baseName()) ?>）
            <input type="number" step="0.01" min="0" max="1000000" name="base" value="<?= $calc ? e($calc['base']) : '100.00' ?>" required>
        </label>
        <button class="btn btn-primary" type="submit">试算</button>
    </form>
    <?php if ($calc): ?>
        <?php $q = $calc['q']; ?>
        <div class="table-wrap" style="margin-top:12px"><table class="data">
            <thead><tr><th>基数</th><th>等级</th><th>加价</th><th>实付合计</th><th>说明</th></tr></thead>
            <tbody>
            <tr>
                <td><?= e(money($calc['base'], true)) ?></td>
                <td><?= $q['level'] ? '<span class="badge badge-info">' . e($q['level']['name']) . '</span>' : '<span class="muted small">无等级</span>' ?></td>
                <td><?= e(money($q['amount'], true)) ?></td>
                <td><strong><?= e(money($q['sale'], true)) ?></strong></td>
                <td class="muted small"><?= e($q['msg']) ?></td>
            </tr>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>我的加价明细</h2>
    <p class="muted small">下表为含等级加价的订单，金额为下单时写入的快照，后续调规则不回溯。</p>
    <?php if (!$orders): ?>
        <div class="empty">暂无加价订单。加价只作用于「自动对接」中原价采购的订单。</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>订单号</th><th>商品</th><th>等级</th><th>加价</th><th>实付</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <?php $mk = $o['mk']; ?>
                <tr>
                    <td class="mono small"><?= e($o['sn']) ?></td>
                    <td class="wrap"><?= e($o['name']) ?></td>
                    <td>
                        <?php if ($mk): ?>
                            <span class="badge badge-info"><?= e((string)($mk['name'] ?? $mk['level'] ?? '-')) ?></span>
                            <span class="muted small"><?= (($mk['type'] ?? '') === 'percent') ? '+' . e($mk['value']) . '%' : '+' . e(money($mk['value'] ?? 0, true)) ?></span>
                        <?php else: ?>
                            <span class="muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $mk ? e(money($mk['amount'] ?? 0, true)) : '<span class="muted small">-</span>' ?></td>
                    <td><strong><?= e(money($o['total'], true)) ?></strong></td>
                    <td class="muted small"><?= e($o['paid_at'] ?: $o['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>我的上游对接连通性</h2>
    <p class="muted small">检测结果会写回「自动对接」里各对接的最近检测记录。平台定时任务也会按间隔自动巡检。</p>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="check_mine">
        <button class="btn btn-ok" type="submit">立即检测我的对接</button>
        <?php if ($licensed): ?><a class="btn" href="<?= e(url('user/upstream.php')) ?>">管理对接</a><?php endif; ?>
    </form>

    <?php if ($tested): ?>
        <div class="alert alert-<?= $tested['failed'] === 0 ? 'ok' : 'warn' ?>" style="margin-top:12px">
            <?= $tested['total'] === 0 ? '没有可检测的对接' : sprintf('检测完成：%d 个对接，%d 个异常', $tested['total'], $tested['failed']) ?>
        </div>
        <?php if ($tested['items']): ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>对接</th><th>结论</th><th>说明</th></tr></thead>
                <tbody>
                <?php foreach ($tested['items'] as $it): ?>
                    <tr>
                        <td><?= e($it['name']) ?></td>
                        <td><span class="badge <?= $it['ok'] ? 'badge-ok' : 'badge-bad' ?>"><?= $it['ok'] ? '正常' : '失败' ?></span></td>
                        <td class="muted small wrap"><?= e($it['msg']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$conns): ?>
        <div class="empty">还没有上游对接，去「自动对接」添加后可在此自检。</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>名称</th><th>协议</th><th>状态</th><th>最近检测</th><th>结论</th></tr></thead>
            <tbody>
            <?php foreach ($conns as $c): ?>
                <tr>
                    <td><?= e($c['name']) ?></td>
                    <td class="small"><?= e(YxCustomerUpstream::driverName((string)$c['driver'])) ?></td>
                    <td><span class="badge <?= (int)$c['status'] === 1 ? 'badge-ok' : 'badge-mute' ?>"><?= (int)$c['status'] === 1 ? '启用' : '停用' ?></span></td>
                    <td class="muted small"><?= e($c['last_test_at'] ?: '未测试') ?></td>
                    <td>
                        <?php if ($c['last_test_at']): ?>
                            <span class="badge <?= (int)$c['last_test_ok'] === 1 ? 'badge-ok' : 'badge-bad' ?>"><?= (int)$c['last_test_ok'] === 1 ? '正常' : '失败' ?></span>
                            <span class="muted small wrap"><?= e((string)($c['last_test_msg'] ?? '')) ?></span>
                        <?php else: ?>
                            <span class="muted small">未测试</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
