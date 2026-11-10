<?php
/**
 * 优享财务系统 · 下游开放接口管理（应用 / 调用日志）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('openapi');

$OWNER_TYPES = ['customer' => '客户自用（以该客户身份操作）', 'agent' => '渠道 / 代理商（可为名下终端用户建档）', 'admin' => '平台级（可操作全部客户）'];
$ALL_ACTIONS = array_keys(YxApi::actions());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = isset($OWNER_TYPES[$_POST['owner_type'] ?? '']) ? $_POST['owner_type'] : 'customer';
        if ($name === '') {
            $res = ['ok' => false, 'msg' => '应用名称不能为空'];
        } else {
            $allow = isset($_POST['allow_actions']) && is_array($_POST['allow_actions'])
                ? implode(',', array_intersect($_POST['allow_actions'], $ALL_ACTIONS))
                : '';
            $data = [
                'name'         => $name,
                'owner_type'   => $type,
                'owner_id'     => (int)($_POST['owner_id'] ?? 0),
                'notify_url'   => trim((string)($_POST['notify_url'] ?? '')),
                'ip_whitelist' => trim((string)($_POST['ip_whitelist'] ?? '')),
                'rate_limit'   => max(0, (int)($_POST['rate_limit'] ?? 120)),
                'allow_actions' => $allow ?: null,
                'status'       => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
                'remark'       => trim((string)($_POST['remark'] ?? '')),
            ];
            if ($type === 'customer' && $data['owner_id'] <= 0) {
                $res = ['ok' => false, 'msg' => '客户自用型应用必须指定客户'];
            } elseif ($id > 0) {
                YxDb::update('api_app', $data, 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '应用已更新'];
            } else {
                $appid  = 'yx' . date('ymd') . strtolower(substr(md5(random_nonce(8) . microtime()), 0, 10));
                $secret = random_nonce(32);
                $data['appid']      = $appid;
                $data['secret']     = yx_encrypt($secret);
                $data['created_at'] = now();
                $id = YxDb::insert('api_app', $data);
                $res = ['ok' => true, 'msg' => '应用已创建。AppID：' . $appid . '　AppSecret：' . $secret . '（请立即保存，密钥仅此一次完整显示）'];
            }
            log_action('api_app.save', $name, ['id' => $id]);
        }
    } elseif ($action === 'regen') {
        $id     = (int)($_POST['id'] ?? 0);
        $secret = random_nonce(32);
        YxDb::update('api_app', ['secret' => yx_encrypt($secret)], 'id = :id', ['id' => $id]);
        log_action('api_app.regen_secret', 'api_app#' . $id);
        $res = ['ok' => true, 'msg' => '密钥已重置，新 AppSecret：' . $secret . '（旧密钥立即失效）'];
    } elseif ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $app = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE id = ?', [$id]);
        if ($app) {
            YxDb::update('api_app', ['status' => (int)$app['status'] === 1 ? 0 : 1], 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '状态已切换'];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        YxDb::delete('api_app', 'id = :id', ['id' => $id]);
        log_action('api_app.delete', 'api_app#' . $id);
        $res = ['ok' => true, 'msg' => '应用已删除（历史日志保留）'];
    }

    if ($action !== 'save' || !empty($res['ok'])) {
        log_action('api_app.' . $action, '', ['ok' => $res['ok'] ?? false]);
    }
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/api_apps.php'));
}

$tab = (string)($_GET['tab'] ?? 'apps');

$apps = YxDb::all('SELECT * FROM ' . YxDb::table('api_app') . ' ORDER BY id DESC');
$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE id = ?', [$editId]) : null;

$customers = YxDb::all('SELECT id, username, email FROM ' . YxDb::table('customer') . ' ORDER BY id DESC LIMIT 300');
$agents    = YxDb::all('SELECT id, name FROM ' . YxDb::table('agent') . ' ORDER BY id DESC');
$agentMap  = [];
foreach ($agents as $a) {
    $agentMap[(int)$a['id']] = $a['name'];
}
$custMap = [];
foreach ($customers as $c) {
    $custMap[(int)$c['id']] = $c['username'];
}

$where  = ' WHERE 1=1';
$bind   = [];
if (($kw = trim((string)($_GET['kw'] ?? ''))) !== '') {
    $where .= ' AND (appid LIKE ? OR action LIKE ? OR msg LIKE ?)';
    $bind[] = '%' . $kw . '%';
    $bind[] = '%' . $kw . '%';
    $bind[] = '%' . $kw . '%';
}
$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('api_log') . $where, $bind);
$page  = paginate($total, 30);
$logs  = YxDb::all('SELECT * FROM ' . YxDb::table('api_log') . $where . ' ORDER BY id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'], $bind);

$apiUrl = url('api/v1.php');

$pageTitle  = '下游接口';
$activeMenu = 'api_apps';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="<?= $tab === 'apps' ? 'active' : '' ?>" href="<?= e(url('admin/api_apps.php?tab=apps')) ?>">应用管理（<?= count($apps) ?>）</a>
    <a class="<?= $tab === 'logs' ? 'active' : '' ?>" href="<?= e(url('admin/api_apps.php?tab=logs')) ?>">调用日志（<?= $total ?>）</a>
    <a class="<?= $tab === 'doc' ? 'active' : '' ?>" href="<?= e(url('admin/api_apps.php?tab=doc')) ?>">接口文档</a>
</div>

<?php if ($tab === 'logs'): ?>
    <div class="card">
        <div class="section-head">
            <h2>调用日志</h2>
            <form method="get" class="inline-form">
                <input type="hidden" name="tab" value="logs">
                <label>关键词 <input type="text" name="kw" value="<?= e($_GET['kw'] ?? '') ?>" placeholder="appid / 动作 / 消息"></label>
                <button class="btn btn-primary" type="submit">查询</button>
            </form>
        </div>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>时间</th><th>AppID</th><th>动作</th><th>结果</th><th>消息</th><th>耗时</th><th>IP</th><th>参数</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="small muted"><?= e($l['created_at']) ?></td>
                    <td class="mono small"><?= e($l['appid']) ?></td>
                    <td class="mono small"><?= e($l['action']) ?></td>
                    <td><span class="badge <?= (int)$l['code'] === 0 ? 'badge-ok' : 'badge-bad' ?>"><?= (int)$l['code'] ?></span></td>
                    <td class="wrap small"><?= e($l['msg']) ?></td>
                    <td class="small"><?= (int)$l['cost_ms'] ?>ms</td>
                    <td class="mono small"><?= e($l['ip']) ?></td>
                    <td class="mono small wrap"><?= e(mb_substr((string)$l['params'], 0, 120)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="8" class="empty">暂无调用日志</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?= pager($page) ?>
    </div>

<?php elseif ($tab === 'doc'): ?>
    <div class="card">
        <h2>接入信息</h2>
        <table class="data">
            <tr><th>接口地址</th><td class="mono"><?= e($apiUrl) ?></td></tr>
            <tr><th>请求方式</th><td>POST（支持 form-urlencoded 或 JSON；GET 亦可用，便于排查）</td></tr>
            <tr><th>公共参数</th><td class="mono">appid, action, ts, nonce, sign</td></tr>
            <tr><th>签名算法</th><td>取除 <code>sign</code> 外所有一级参数，过滤空值 → 按 key 升序 → 拼 <code>k=v&amp;k=v</code> → <code>HMAC-SHA256(串, AppSecret)</code>，小写十六进制</td></tr>
            <tr><th>时间戳容差</th><td>±<?= YxApi::TS_WINDOW ?> 秒；nonce 同应用内一次性（防重放）</td></tr>
            <tr><th>响应格式</th><td class="mono">{"code":0,"msg":"ok","data":{...},"ts":1730000000}</td></tr>
            <tr><th>错误码</th><td>400 参数错误 / 401 验签失败 / 403 无权限 / 404 不存在 / 429 超出频率 / 500 服务端异常</td></tr>
        </table>
        <h2 style="margin-top:20px">可用动作</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>动作</th><th>名称</th><th>主要参数</th></tr></thead>
            <tbody>
            <?php foreach (YxApi::actions() as $code => $meta): ?>
                <tr>
                    <td class="mono"><?= e($code) ?></td>
                    <td><?= e($meta['name']) ?></td>
                    <td class="mono small"><?= e($meta['params'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="muted small" style="margin-top:12px">完整协议、签名示例与回调格式见 <code>docs/接口对接说明.md</code>。</p>
    </div>

<?php else: ?>
    <div class="alert alert-warn">
        <strong>下游 = 接入你的代理商 / 分销系统。</strong>
        <span>每个下游分配一对 AppID / AppSecret，按动作授权，支持 IP 白名单、频次限制、终端用户映射与事件回调。
        接口调用全量留痕，可在「调用日志」中追溯。</span>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2><?= $editing ? '编辑应用：' . e($editing['name']) : '新建下游应用' ?></h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="grid-2">
                    <label>应用名称 <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
                    <label>归属类型
                        <select name="owner_type">
                            <?php foreach ($OWNER_TYPES as $k => $v): ?>
                                <option value="<?= e($k) ?>" <?= ($editing['owner_type'] ?? 'customer') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>客户（归属类型为客户时必填）
                        <select name="owner_id">
                            <option value="0">-- 不指定 --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (int)($editing['owner_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['username']) ?></option>
                            <?php endforeach; ?>
                            <?php foreach ($agents as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= (int)($editing['owner_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>[渠道] <?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>事件回调地址 <input type="text" name="notify_url" value="<?= e($editing['notify_url'] ?? '') ?>" placeholder="https://downstream.com/notify"></label>
                    <label>IP 白名单 <input type="text" name="ip_whitelist" value="<?= e($editing['ip_whitelist'] ?? '') ?>" placeholder="1.2.3.4,5.6.7.*,10.0.0.0/8（留空不限制）"></label>
                    <label>每分钟调用上限 <input type="number" name="rate_limit" value="<?= e($editing['rate_limit'] ?? 120) ?>"></label>
                    <label>状态
                        <select name="status">
                            <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                            <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>禁用</option>
                        </select>
                    </label>
                </div>
                <label>动作授权（不勾选=全部允许）</label>
                <?php
                $allowed = $editing && trim((string)$editing['allow_actions']) !== ''
                    ? explode(',', (string)$editing['allow_actions'])
                    : [];
                ?>
                <div class="grid-3" style="margin-bottom:14px">
                    <?php foreach (YxApi::actions() as $code => $meta): ?>
                        <label class="checkbox" style="margin-top:0">
                            <input type="checkbox" name="allow_actions[]" value="<?= e($code) ?>" <?= in_array($code, $allowed, true) ? 'checked' : '' ?>>
                            <?= e($code) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <label>备注 <input type="text" name="remark" value="<?= e($editing['remark'] ?? '') ?>"></label>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建应用（生成密钥）' ?></button>
                    <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/api_apps.php')) ?>">取消</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>应用列表</h2>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>名称</th><th>AppID</th><th>归属</th><th>限流</th><th>调用</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($apps as $a): ?>
                    <tr>
                        <td><?= e($a['name']) ?></td>
                        <td class="mono small"><?= e($a['appid']) ?></td>
                        <td class="small">
                            <?php
                            if ($a['owner_type'] === 'customer') {
                                echo '客户：' . e($custMap[(int)$a['owner_id']] ?? ('#' . $a['owner_id']));
                            } elseif ($a['owner_type'] === 'agent') {
                                echo '渠道：' . e($agentMap[(int)$a['owner_id']] ?? ('#' . $a['owner_id']));
                            } else {
                                echo '平台级';
                            }
                            ?>
                            <?= trim((string)$a['allow_actions']) !== '' ? '<br><span class="muted">限定动作</span>' : '' ?>
                        </td>
                        <td class="small"><?= (int)$a['rate_limit'] ?>/分</td>
                        <td class="small"><?= (int)$a['call_count'] ?><br><span class="muted"><?= e($a['last_call_at'] ?: '-') ?></span></td>
                        <td><?= (int)$a['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">禁用</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/api_apps.php?edit=' . (int)$a['id'])) ?>">编辑</a>
                                <form method="post" onsubmit="return confirm('重置密钥后旧密钥立即失效，确认？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="regen">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-sm btn-warn" type="submit">重置密钥</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= (int)$a['status'] === 1 ? '禁用' : '启用' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认删除该应用？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-sm btn-bad" type="submit">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$apps): ?><tr><td colspan="7" class="empty">还没有下游应用</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
