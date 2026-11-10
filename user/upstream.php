<?php
/**
 * 优享财务系统 · 购买，自动对接（客户自有上游）
 *
 * 客户填写上游 API 地址 / 账户 ID / 密钥后，可在此直接选购上游产品；
 * 订单支付成功后由 YxProvision 自动向上游申请开通，无需人工介入。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

YxEdition::requireFeature('upstream', url('user/services.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save') {
        $res = YxCustomerUpstream::save($cid, [
            'id'       => (int)($_POST['id'] ?? 0),
            'name'     => (string)($_POST['name'] ?? ''),
            'driver'   => (string)($_POST['driver'] ?? 'http'),
            'endpoint' => (string)($_POST['endpoint'] ?? ''),
            'appid'    => (string)($_POST['appid'] ?? ''),
            'secret'   => (string)($_POST['secret'] ?? ''),
            'config'   => (string)($_POST['config'] ?? ''),
            'timeout'  => (int)($_POST['timeout'] ?? 10),
            'status'   => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
        ]);
    } elseif ($action === 'test') {
        $res = YxCustomerUpstream::test((int)($_POST['id'] ?? 0), $cid);
        $res['msg'] = (!empty($res['ok']) ? '✅ 对接成功：' : '❌ 对接失败：') . (string)($res['msg'] ?? '');
    } elseif ($action === 'delete') {
        $res = YxCustomerUpstream::remove((int)($_POST['id'] ?? 0), $cid);
    } elseif ($action === 'buy') {
        $connId = (int)($_POST['conn_id'] ?? 0);
        $code   = trim((string)($_POST['code'] ?? ''));
        $cycle  = (string)($_POST['cycle'] ?? 'month');
        if (!isset(YxBusiness::CYCLES[$cycle])) {
            $cycle = 'month';
        }

        $conn = YxCustomerUpstream::row($connId, $cid);
        if (!$conn) {
            $res = ['ok' => false, 'msg' => '上游对接不存在或不属于当前账号'];
        } elseif ((int)$conn['status'] !== 1) {
            $res = ['ok' => false, 'msg' => '该上游对接已停用，请先启用'];
        } elseif ($code === '') {
            $res = ['ok' => false, 'msg' => '请选择要购买的上游产品'];
        } else {
            // 价格与规格以服务端重新拉取的上游数据为准，不信任表单提交值
            $list = YxCustomerUpstream::products($connId, $cid);
            $hit  = null;
            foreach (($list['items'] ?? []) as $it) {
                if ((string)$it['code'] === $code) {
                    $hit = $it;
                    break;
                }
            }
            if (empty($list['ok'])) {
                $res = ['ok' => false, 'msg' => (string)($list['msg'] ?? '获取上游产品失败')];
            } elseif (!$hit) {
                $res = ['ok' => false, 'msg' => '上游产品「' . $code . '」不存在或已下架'];
            } elseif ((float)$hit['price'] <= 0) {
                $res = ['ok' => false, 'msg' => '该产品未在上游设置有效价格，暂不可下单'];
            } elseif ($hit['stock'] !== null && (int)$hit['stock'] <= 0) {
                $res = ['ok' => false, 'msg' => '该产品上游库存不足（剩余 ' . (int)$hit['stock'] . '），暂不可下单'];
            } else {
                $months = YxBusiness::cycleMonths($cycle);
                $cost   = round_money((float)$hit['price'] * $months);
                // 代理等级加价：按等级对成本价/售价叠加，快照随订单落库，规则后续调整不影响本单
                $mk      = YxUpstreamMarkup::applyForCustomer($cid, $cost);
                $total   = round_money($mk['amount']);
                $note    = '客户自有上游自动对接：' . $conn['name'];
                if (!empty($mk['level'])) {
                    $note .= '（' . $mk['level']['name'] . '加价 ' . money($mk['added'], true) . '）';
                }
                $order  = YxBusiness::createOrder([
                    'customer_id'          => $cid,
                    'type'                 => 'upstream',
                    'customer_upstream_id' => $connId,
                    'remote_code'          => $hit['code'],
                    'remote_spec'          => $hit['spec'],
                    'billing_cycle'        => $cycle,
                    'name'                 => $hit['name'] . '（' . YxBusiness::cycleName($cycle) . '）',
                    'amount'               => $total,
                    'upstream_markup'      => $mk['snapshot'],
                    'note'                 => $note,
                ]);
                if (!empty($order['ok'])) {
                    flash('订单已生成，请完成支付；付款后将自动向上游开通', 'success');
                    redirect(url('user/orders.php?pay=' . (int)$order['order_id']));
                }
                $res = $order;
            }
        }
    }

    flash((string)($res['msg'] ?? ''), (string)($res['type'] ?? (!empty($res['ok']) ? 'success' : 'error')));
    $back = url('user/upstream.php');
    if ($action === 'save' && !empty($res['ok']) && !empty($res['id'])) {
        $back = url('user/upstream.php?id=' . (int)$res['id']);
    }
    redirect($back);
}

$conns = YxCustomerUpstream::rows($cid);

$editId = (int)($_GET['edit'] ?? 0);
$edit   = $editId > 0 ? YxCustomerUpstream::row($editId, $cid) : null;

$viewId = (int)($_GET['id'] ?? 0);
$view   = $viewId > 0 ? YxCustomerUpstream::row($viewId, $cid) : null;
$prods  = $view ? YxCustomerUpstream::products($viewId, $cid) : null;

$userTitle  = '自动对接';
$userActive = 'upstream';
require dirname(__DIR__) . '/includes/user_header.php';

// 当前表单回填值（密钥永不回显）
$f = [
    'id'       => $edit ? (int)$edit['id'] : 0,
    'name'     => $edit ? (string)$edit['name'] : '',
    'driver'   => $edit ? (string)$edit['driver'] : 'http',
    'endpoint' => $edit ? (string)$edit['endpoint'] : '',
    'appid'    => $edit ? (string)$edit['appid'] : '',
    'timeout'  => $edit ? (int)$edit['timeout'] : 10,
    'status'   => $edit ? (int)$edit['status'] : 1,
    'config'   => $edit ? (string)($edit['config'] ?? '') : '',
];

// 新增且选择需要协议配置的驱动时，预填内置模板，便于按对方文档校准
if (!$edit && in_array($f['driver'], YxCustomerUpstream::CONFIG_DRIVERS, true)) {
    $f['config'] = (string)json_encode(
        YxUpstream::defaultConfigFor($f['driver']),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
$needConfig = in_array($f['driver'], YxCustomerUpstream::CONFIG_DRIVERS, true);
?>

<div class="card">
    <h2>购买，自动对接</h2>
    <p class="muted small">
        填写你自己服务商的 API 地址、账户 ID 与密钥并保存后，系统会自动测试连通性；
        之后在下方选购上游产品，付款成功即自动向上游下单开通，业务直接出现在「我的业务」中。
    </p>
    <?php if (!$conns): ?>
        <div class="empty">还没有上游对接，请先在下方添加</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>名称</th><th>协议</th><th>接口地址</th><th>账户 ID</th><th>最近测试</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($conns as $c): ?>
                <tr>
                    <td class="wrap"><strong><?= e($c['name']) ?></strong></td>
                    <td class="small"><?= e(YxCustomerUpstream::driverName((string)$c['driver'])) ?></td>
                    <td class="mono small wrap"><?= e($c['endpoint'] ?: '-') ?></td>
                    <td class="mono small"><?= e($c['appid'] ?: '-') ?></td>
                    <td class="small">
                        <?php if ($c['last_test_at']): ?>
                            <span class="badge <?= (int)$c['last_test_ok'] === 1 ? 'badge-ok' : 'badge-bad' ?>"><?= (int)$c['last_test_ok'] === 1 ? '正常' : '失败' ?></span>
                            <span class="muted"><?= e($c['last_test_at']) ?></span>
                            <?php if ($c['last_test_msg']): ?><br><span class="muted"><?= e($c['last_test_msg']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="muted">未测试</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= (int)$c['status'] === 1 ? 'badge-ok' : 'badge-mute' ?>"><?= (int)$c['status'] === 1 ? '启用' : '停用' ?></span></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-primary" href="<?= e(url('user/upstream.php?id=' . (int)$c['id'])) ?>">选购产品</a>
                            <a class="btn btn-sm" href="<?= e(url('user/upstream.php?edit=' . (int)$c['id'])) ?>">编辑</a>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-sm" type="submit">测试</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('删除后该对接下的业务将无法自动续费/操作，确认删除？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<?php if ($view): ?>
<div class="card">
    <h2>上游产品 · <?= e($view['name']) ?></h2>
    <p class="muted small">
        价格与规格实时读取自上游；下单金额 = 上游单价 × 周期月数<?php $myMk = YxUpstreamMarkup::applyForCustomer($cid, 0); ?><?php if (!empty($myMk['level'])): ?>，
        再按你的代理等级「<?= e($myMk['level']['name']) ?>」<?= e(YxUpstreamMarkup::ruleText($myMk['level'])) ?>（基数：<?= e(YxUpstreamMarkup::baseName()) ?>）<?php endif; ?>。
    </p>
    <?php if (empty($prods['ok'])): ?>
        <p class="alert alert-warn" style="margin:0 0 12px"><?= e((string)($prods['msg'] ?? '获取上游产品失败')) ?></p>
        <p class="muted small">请先在 <a href="<?= e(url('user/upstream.php?edit=' . (int)$view['id'])) ?>">编辑对接</a> 中检查地址与密钥，或点「测试」确认连通性。</p>
    <?php elseif (empty($prods['items'])): ?>
        <div class="empty">上游暂未返回可售产品</div>
    <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>产品编号</th><th>名称</th><th>上游单价</th><th>货源库存</th><th>规格</th><th>购买</th></tr></thead>
            <tbody>
            <?php foreach ($prods['items'] as $p): ?>
                <?php
                $stock   = $p['stock'];
                $soldOut = $stock !== null && (int)$stock <= 0;
                ?>
                <tr>
                    <td class="mono small"><?= e($p['code']) ?></td>
                    <td class="wrap"><?= e($p['name']) ?></td>
                    <td><?= e(money($p['price'], true)) ?></td>
                    <td class="small">
                        <?php if ($stock === null): ?>
                            <span class="muted">上游未提供</span>
                        <?php elseif ($soldOut): ?>
                            <span class="badge badge-bad">缺货（0）</span>
                        <?php else: ?>
                            <span class="badge badge-ok"><?= (int)$stock ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small muted wrap">
                        <?php
                        $specTxt = [];
                        foreach (($p['spec'] ?? []) as $k => $v) {
                            if (is_scalar($v)) {
                                $specTxt[] = $k . '=' . $v;
                            }
                        }
                        echo e($specTxt ? implode(' · ', $specTxt) : '-');
                        ?>
                    </td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="buy">
                            <input type="hidden" name="conn_id" value="<?= (int)$view['id'] ?>">
                            <input type="hidden" name="code" value="<?= e($p['code']) ?>">
                            <select name="cycle" class="field-inline">
                                <?php foreach (YxBusiness::CYCLES as $k => $cy): ?>
                                    <option value="<?= e($k) ?>"><?= e($cy['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary" type="submit" <?= ((float)$p['price'] <= 0 || $soldOut) ? 'disabled' : '' ?>>购买</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= $edit ? '编辑上游对接' : '添加上游对接' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
        <div class="grid-2">
            <label>对接名称
                <input type="text" name="name" maxlength="80" value="<?= e($f['name']) ?>" placeholder="如：XX云 API">
            </label>
            <label>对接协议
                <select name="driver">
                    <?php foreach (YxCustomerUpstream::DRIVERS as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $f['driver'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>上游 API 地址
            <input type="text" name="endpoint" maxlength="255" value="<?= e($f['endpoint']) ?>" placeholder="https://api.example.com/open">
        </label>
        <div class="grid-2">
            <label>上游账户 ID
                <input type="text" name="appid" maxlength="80" value="<?= e($f['appid']) ?>" placeholder="服务商提供的账户 ID / AppID">
            </label>
            <label>上游密钥
                <input type="password" name="secret" maxlength="200" value="" autocomplete="new-password"
                       placeholder="<?= $edit ? '留空表示不修改' : '服务商提供的密钥 / AppSecret' ?>">
            </label>
        </div>
        <details <?= $needConfig ? 'open' : '' ?> style="margin-bottom:12px">
            <summary style="cursor:pointer">协议配置（高级：魔方财务 / 乐众 / 自定义 HTTP）</summary>
            <label style="margin-top:10px">协议配置（JSON）
                <textarea name="config" rows="10" class="mono" spellcheck="false"
                          placeholder="留空则使用内置默认模板；可按对方接口文档校准 actions / fields / auth / response"><?= e($f['config']) ?></textarea>
            </label>
            <p class="muted small">
                用于描述对方的动作名、字段名、签名方式与响应解析路径；「标准 HTTP/JSON」与「本地模拟」会忽略此项。
                留空保存时自动写入对应协议的内置模板。
            </p>
        </details>
        <div class="grid-2">
            <label>超时（秒）
                <input type="number" name="timeout" min="5" max="60" value="<?= (int)$f['timeout'] ?>">
            </label>
            <label>状态
                <select name="status">
                    <option value="1" <?= (int)$f['status'] === 1 ? 'selected' : '' ?>>启用</option>
                    <option value="0" <?= (int)$f['status'] === 0 ? 'selected' : '' ?>>停用</option>
                </select>
            </label>
        </div>
        <div class="row-actions">
            <button class="btn btn-primary" type="submit">保存并自动测试</button>
            <?php if ($edit): ?>
                <a class="btn" href="<?= e(url('user/upstream.php')) ?>">取消编辑</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="muted small" style="margin-top:10px">
        密钥仅以密文保存、页面不回显；接口地址需为公网 http/https 地址，内网与本机地址会被拒绝。
        「本地模拟」协议用于演示与联调，不会产生真实上游调用。
    </p>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
