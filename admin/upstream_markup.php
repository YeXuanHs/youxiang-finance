<?php
/**
 * 优享财务系统 · 上游对接代理等级加价（插件页面 / 后台）
 *
 * 功能：
 *  - 加价规则：自定义代理等级，每级可选固定额或百分比，加价基数可选上游成本价或当前售价
 *  - 代理等级分配：给渠道 / 代理指定等级，其名下客户下单自动按等级加价
 *  - 上游自动检测：一键检测全部上游对接，并在定时任务中按间隔自动执行
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('upstream.markup');

$app = YxUpstreamMarkup::app();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save_levels') {
        $rows = [];
        foreach ((array)($_POST['levels'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'key'   => (string)($row['key'] ?? ''),
                'name'  => (string)($row['name'] ?? ''),
                'type'  => (string)($row['type'] ?? 'fixed'),
                'value' => (string)($row['value'] ?? '0'),
                'note'  => (string)($row['note'] ?? ''),
            ];
        }
        $res = YxUpstreamMarkup::saveLevels($rows);
        log_action('upstream_markup.save_levels', '', ['count' => count((array)($res['levels'] ?? []))]);
    } elseif ($action === 'save_settings') {
        YxUpstreamMarkup::setEnabled(!empty($_POST['enabled']));
        YxUpstreamMarkup::setBaseMode((string)($_POST['base'] ?? 'cost'));
        $iv = (int)($_POST['interval'] ?? 30);
        set_setting('upstream_check_interval', (string)max(5, min(1440, $iv)));
        log_action('upstream_markup.settings', '', [
            'enabled'  => YxUpstreamMarkup::enabled(),
            'base'     => YxUpstreamMarkup::baseMode(),
            'interval' => max(5, min(1440, $iv)),
        ]);
        $res = ['ok' => true, 'msg' => '插件设置已保存'];
    } elseif ($action === 'save_agents') {
        $map   = (array)($_POST['agent_level'] ?? []);
        $saved = 0;
        foreach ($map as $agentId => $levelKey) {
            $agentId = (int)$agentId;
            if ($agentId <= 0) {
                continue;
            }
            $levelKey = (string)$levelKey;
            if ($levelKey !== '' && !YxUpstreamMarkup::level($levelKey)) {
                $levelKey = '';
            }
            YxDb::update('agent', ['level_key' => $levelKey], 'id = :id', ['id' => $agentId]);
            $saved++;
        }
        log_action('upstream_markup.save_agents', '', ['count' => $saved]);
        $res = ['ok' => true, 'msg' => '已保存 ' . $saved . ' 个代理的等级'];
    } elseif ($action === 'check_all') {
        $res = YxUpstreamMarkup::checkAll();
        $res['type'] = $res['ok'] ? 'success' : 'error';
        log_action('upstream_markup.check_all', '', ['total' => $res['total'], 'failed' => $res['failed']]);
    }

    flash((string)($res['msg'] ?? ''), (string)($res['type'] ?? (!empty($res['ok']) ? 'success' : 'error')));
    redirect(url('admin/upstream_markup.php'));
}

$levels  = YxUpstreamMarkup::levels();
$agents  = YxDb::all('SELECT * FROM ' . YxDb::table('agent') . ' ORDER BY id DESC') ?: [];
$lastAt  = YxUpstreamMarkup::lastCheckAt();
$upstreams = YxDb::all('SELECT * FROM ' . YxDb::table('upstream') . ' ORDER BY id ASC') ?: [];
$cusUps    = YxDb::all('SELECT id, customer_id, name, driver, status, last_test_at, last_test_ok, last_test_msg FROM ' . YxDb::table('customer_upstream') . ' ORDER BY id ASC') ?: [];

$pageTitle  = '代理等级加价';
$activeMenu = 'upstream_markup';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<?php if (!$app): ?>
    <div class="card">
        <h2>插件未上架</h2>
        <div class="empty">商城缺少应用记录（标识 <?= e(YxUpstreamMarkup::APP_KEY) ?>），请先在「商店管理」中上架本插件。</div>
    </div>
<?php endif; ?>

<div class="card">
    <h2>插件状态</h2>
    <p class="muted small">
        本插件为付费插件（<?= e($app ? YxBusiness::priceLabel($app) : '未上架') ?>）。启用后：
        代理名下客户在上游自动对接下单时，按「<?= e(YxUpstreamMarkup::baseName()) ?>」为基数叠加等级加价；
        加价金额在下单时写入订单，后续调整规则不影响已生成订单。
    </p>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <label>插件开关
            <select name="enabled">
                <option value="1" <?= YxUpstreamMarkup::enabled() ? 'selected' : '' ?>>启用</option>
                <option value="0" <?= YxUpstreamMarkup::enabled() ? '' : 'selected' ?>>停用</option>
            </select>
        </label>
        <label>加价基数
            <select name="base">
                <option value="cost" <?= YxUpstreamMarkup::baseMode() === 'cost' ? 'selected' : '' ?>>上游成本价</option>
                <option value="sale" <?= YxUpstreamMarkup::baseMode() === 'sale' ? 'selected' : '' ?>>当前售价</option>
            </select>
        </label>
        <label>自动检测间隔（分钟）
            <input type="number" min="5" max="1440" name="interval" value="<?= e(setting('upstream_check_interval', '30')) ?>">
        </label>
        <button class="btn btn-primary" type="submit">保存设置</button>
        <span class="muted small">上次自动检测：<?= $lastAt !== '' ? e($lastAt) : '尚未执行' ?>（由定时任务 tools/cron.php 触发）</span>
    </form>
</div>

<div class="card">
    <h2>加价规则（代理等级）</h2>
    <p class="muted small">
        每级可选「固定额」或「百分比」；不留等级标识时按名称自动生成。
        百分比按基数 × 比例计算，固定额直接叠加，结果均为四舍五入到分。
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_levels">
        <div class="table-wrap"><table class="data">
            <thead><tr><th>等级标识</th><th>等级名称</th><th>加价方式</th><th>加价数值</th><th>说明</th><th>示例</th></tr></thead>
            <tbody>
            <?php foreach ($levels as $lv): ?>
                <tr>
                    <td class="mono small"><input type="text" name="levels[<?= e($lv['key']) ?>][key]" value="<?= e($lv['key']) ?>" class="field-sm"></td>
                    <td><input type="text" name="levels[<?= e($lv['key']) ?>][name]" value="<?= e($lv['name']) ?>" required class="field-sm"></td>
                    <td>
                        <select name="levels[<?= e($lv['key']) ?>][type]" class="field-inline">
                            <option value="fixed" <?= $lv['type'] === 'fixed' ? 'selected' : '' ?>>固定额（元）</option>
                            <option value="percent" <?= $lv['type'] === 'percent' ? 'selected' : '' ?>>百分比（%）</option>
                        </select>
                    </td>
                    <td><input type="number" step="0.01" min="0" name="levels[<?= e($lv['key']) ?>][value]" value="<?= e($lv['value']) ?>" class="field-sm"></td>
                    <td><input type="text" name="levels[<?= e($lv['key']) ?>][note]" value="<?= e($lv['note']) ?>" class="field-sm" placeholder="可选，如：金牌代理"></td>
                    <td class="muted small"><?= e(YxUpstreamMarkup::ruleText($lv)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < 2; $i++): ?>
                <tr>
                    <td class="mono small"><input type="text" name="levels[new<?= $i ?>][key]" value="" class="field-sm" placeholder="自动"></td>
                    <td><input type="text" name="levels[new<?= $i ?>][name]" value="" class="field-sm" placeholder="新增等级名称"></td>
                    <td>
                        <select name="levels[new<?= $i ?>][type]" class="field-inline">
                            <option value="fixed">固定额（元）</option>
                            <option value="percent">百分比（%）</option>
                        </select>
                    </td>
                    <td><input type="number" step="0.01" min="0" name="levels[new<?= $i ?>][value]" value="0" class="field-sm"></td>
                    <td><input type="text" name="levels[new<?= $i ?>][note]" value="" class="field-sm"></td>
                    <td class="muted small">名称留空即忽略该行</td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table></div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存加价规则</button>
            <span class="muted small">共 <?= count($levels) ?> 个等级</span>
        </div>
    </form>
</div>

<div class="card">
    <h2>代理等级分配</h2>
    <?php if (!$agents): ?>
        <div class="empty">还没有渠道 / 代理。请先在「客户与渠道 → 渠道 / 代理」中创建。</div>
    <?php else: ?>
        <p class="muted small">未设置等级的代理，其客户下单按原价，不加价。</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_agents">
            <div class="table-wrap"><table class="data">
                <thead><tr><th>代理名称</th><th>账号</th><th>当前等级</th><th>设置为</th><th>名下客户</th></tr></thead>
                <tbody>
                <?php foreach ($agents as $a): ?>
                    <?php
                    $cur  = YxUpstreamMarkup::level((string)($a['level_key'] ?? ''));
                    $cnt  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE agent_id = ?', [(int)$a['id']]);
                    ?>
                    <tr>
                        <td><?= e($a['name']) ?></td>
                        <td class="mono small"><?= e($a['username']) ?></td>
                        <td><?= $cur ? '<span class="badge badge-info">' . e($cur['name']) . '</span>' : '<span class="muted small">未设置</span>' ?></td>
                        <td>
                            <select name="agent_level[<?= (int)$a['id'] ?>]" class="field-inline">
                                <option value="">不设置（按原价）</option>
                                <?php foreach ($levels as $lv): ?>
                                    <option value="<?= e($lv['key']) ?>" <?= (string)($a['level_key'] ?? '') === $lv['key'] ? 'selected' : '' ?>><?= e($lv['name']) ?> · <?= e(YxUpstreamMarkup::ruleText($lv)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><?= $cnt ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">保存代理等级</button></div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>上游对接连通性检测</h2>
    <p class="muted small">
        自动检测默认每 <?= e(setting('upstream_check_interval', '30')) ?> 分钟执行一次（需插件启用，由定时任务触发）；
        保存上游配置时也会立即检测一次。检测结论会写回各上游记录的「最近测试」。
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="check_all">
        <button class="btn btn-ok" type="submit">立即检测全部对接</button>
    </form>

    <h3>平台上游（<?= count($upstreams) ?>）</h3>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>名称</th><th>驱动</th><th>状态</th><th>最近检测</th><th>结论</th></tr></thead>
        <tbody>
        <?php foreach ($upstreams as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td class="muted small"><?= e(YxUpstream::driverName($u['driver'])) ?></td>
                <td><?= (int)$u['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                <td class="muted small"><?= e($u['last_test_at'] ?: '未测试') ?></td>
                <td>
                    <?php if ($u['last_test_at']): ?>
                        <span class="badge <?= (int)$u['last_test_ok'] === 1 ? 'badge-ok' : 'badge-bad' ?>"><?= (int)$u['last_test_ok'] === 1 ? '正常' : '失败' ?></span>
                        <span class="muted small wrap"><?= e((string)($u['last_test_msg'] ?? '')) ?></span>
                    <?php else: ?>
                        <span class="muted small">未测试</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$upstreams): ?><tr><td colspan="5" class="empty">暂无平台上游</td></tr><?php endif; ?>
        </tbody>
    </table></div>

    <h3>客户自有对接（<?= count($cusUps) ?>）</h3>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>客户 ID</th><th>名称</th><th>驱动</th><th>状态</th><th>最近检测</th><th>结论</th></tr></thead>
        <tbody>
        <?php foreach ($cusUps as $c): ?>
            <tr>
                <td><?= (int)$c['customer_id'] ?></td>
                <td><?= e($c['name']) ?></td>
                <td class="muted small"><?= e(YxCustomerUpstream::driverName($c['driver'])) ?></td>
                <td><?= (int)$c['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
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
        <?php if (!$cusUps): ?><tr><td colspan="6" class="empty">暂无客户自有对接</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
