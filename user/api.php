<?php
/**
 * 优享财务系统 · 我的接口密钥（下游对接自助）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$enabled = YxEdition::can('openapi');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$enabled) {
        flash('当前版本不支持开放接口，请升级到「企业版」', 'error');
        redirect(url('user/api.php'));
    }
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $cnt  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('api_app') . " WHERE owner_type = 'customer' AND owner_id = ?", [$cid]);
        if ($name === '') {
            $res = ['ok' => false, 'msg' => '请填写应用名称'];
        } elseif ($cnt >= 5) {
            $res = ['ok' => false, 'msg' => '最多创建 5 个应用，请先删除不用的'];
        } else {
            $appid  = 'yx' . date('ymd') . strtolower(substr(md5(random_nonce(8) . microtime()), 0, 10));
            $secret = random_nonce(32);
            YxDb::insert('api_app', [
                'name'       => $name,
                'appid'      => $appid,
                'secret'     => yx_encrypt($secret),
                'owner_type' => 'customer',
                'owner_id'   => $cid,
                'rate_limit' => 120,
                'status'     => 1,
                'created_at' => now(),
            ]);
            log_action('api_app.self_create', $appid, ['customer' => $cid]);
            $res = ['ok' => true, 'msg' => '创建成功！AppID：' . $appid . '　AppSecret：' . $secret . '（密钥仅此一次完整显示，请立即保存）'];
        }
    } elseif ($action === 'regen') {
        $app = YxDb::one(
            'SELECT * FROM ' . YxDb::table('api_app') . " WHERE id = ? AND owner_type = 'customer' AND owner_id = ?",
            [(int)($_POST['id'] ?? 0), $cid]
        );
        if (!$app) {
            $res = ['ok' => false, 'msg' => '应用不存在'];
        } else {
            $secret = random_nonce(32);
            YxDb::update('api_app', ['secret' => yx_encrypt($secret)], 'id = :id', ['id' => (int)$app['id']]);
            log_action('api_app.self_regen', $app['appid'], ['customer' => $cid]);
            $res = ['ok' => true, 'msg' => '密钥已重置，新 AppSecret：' . $secret . '（旧密钥立即失效）'];
        }
    } elseif ($action === 'save') {
        $app = YxDb::one(
            'SELECT * FROM ' . YxDb::table('api_app') . " WHERE id = ? AND owner_type = 'customer' AND owner_id = ?",
            [(int)($_POST['id'] ?? 0), $cid]
        );
        if (!$app) {
            $res = ['ok' => false, 'msg' => '应用不存在'];
        } else {
            YxDb::update('api_app', [
                'notify_url'   => trim((string)($_POST['notify_url'] ?? '')),
                'ip_whitelist' => trim((string)($_POST['ip_whitelist'] ?? '')),
            ], 'id = :id', ['id' => (int)$app['id']]);
            $res = ['ok' => true, 'msg' => '配置已保存'];
        }
    } elseif ($action === 'delete') {
        YxDb::delete('api_app', "id = :id AND owner_type = 'customer' AND owner_id = :cid", ['id' => (int)($_POST['id'] ?? 0), 'cid' => $cid]);
        $res = ['ok' => true, 'msg' => '应用已删除'];
    }

    log_action('api_app.self_' . $action, 'customer#' . $cid, ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('user/api.php'));
}

$apps = $enabled
    ? YxDb::all('SELECT * FROM ' . YxDb::table('api_app') . " WHERE owner_type = 'customer' AND owner_id = ? ORDER BY id DESC", [$cid])
    : [];

$appids = array_column($apps, 'appid');
$logs = [];
if ($appids) {
    $place = implode(',', array_fill(0, count($appids), '?'));
    $logs  = YxDb::all(
        'SELECT * FROM ' . YxDb::table('api_log') . " WHERE appid IN ($place) ORDER BY id DESC LIMIT 20",
        $appids
    );
}

$apiUrl = url('api/v1.php');

$userTitle  = '接口密钥';
$userActive = 'api';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if (!$enabled): ?>
    <div class="card">
        <h2>开放接口</h2>
        <p class="alert alert-warn" style="margin:0 0 12px">
            开放接口属于「企业版」能力。当前站点版本为 <strong><?= e(YxEdition::name()) ?></strong>。
        </p>
        <p class="muted">升级后你可以获得 AppID / AppSecret，通过 HTTPS 接口下单、开通、续费与查询业务。</p>
    </div>
<?php else: ?>
    <div class="card">
        <h2>接口对接说明</h2>
        <table class="data">
            <tr><th>接口地址</th><td class="mono"><?= e($apiUrl) ?></td></tr>
            <tr><th>鉴权参数</th><td class="mono">appid、action、ts、nonce、sign</td></tr>
            <tr><th>签名算法</th><td>除 <code>sign</code> 外的一级参数（过滤空值）按 key 升序拼接为 <code>k=v&amp;k=v</code>，再用 AppSecret 做 HMAC-SHA256</td></tr>
            <tr><th>时间容差</th><td>±<?= YxApi::TS_WINDOW ?> 秒；nonce 一次性，防重放</td></tr>
        </table>
        <h3 style="margin-top:18px">签名示例（PHP）</h3>
        <pre class="mono" style="background:#f8fafd;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto">$secret = '你的 AppSecret';
$params = [
    'appid'  => '你的 AppID',
    'action' => 'product.list',
    'ts'     => time(),
    'nonce'  => bin2hex(random_bytes(8)),
];
ksort($params);
$pairs = [];
foreach ($params as $k => $v) { $pairs[] = $k . '=' . $v; }
$params['sign'] = hash_hmac('sha256', implode('&', $pairs), $secret);

// POST JSON 到接口地址
$ch = curl_init('<?= e($apiUrl) ?>');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($params),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
echo curl_exec($ch);</pre>
        <p class="muted small">可用动作：<?= e(implode('、', array_keys(YxApi::actions()))) ?></p>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>创建应用</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <label>应用名称 <input type="text" name="name" required placeholder="如：我的分销系统"></label>
                <button class="btn btn-primary" type="submit">创建并生成密钥</button>
            </form>
            <p class="muted small" style="margin-top:10px">AppSecret 仅在创建/重置时完整显示一次，请妥善保存。</p>
        </div>

        <div class="card">
            <h2>我的应用（<?= count($apps) ?>）</h2>
            <?php if (!$apps): ?>
                <div class="empty">还没有接口应用</div>
            <?php else: ?>
                <?php foreach ($apps as $a): ?>
                    <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:12px">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                            <strong><?= e($a['name']) ?></strong>
                            <span class="badge <?= (int)$a['status'] === 1 ? 'badge-ok' : 'badge-mute' ?>"><?= (int)$a['status'] === 1 ? '启用' : '禁用' ?></span>
                        </div>
                        <table class="data" style="margin-top:8px">
                            <tr><th>AppID</th><td class="mono"><?= e($a['appid']) ?></td></tr>
                            <tr><th>AppSecret</th><td class="mono">••••••••（仅创建时显示）</td></tr>
                            <tr><th>调用次数</th><td><?= (int)$a['call_count'] ?> 次 · 最近 <?= e($a['last_call_at'] ?: '-') ?></td></tr>
                        </table>
                        <form method="post" style="margin-top:8px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <label>事件回调地址 <input type="text" name="notify_url" value="<?= e($a['notify_url']) ?>" placeholder="https://你的系统/notify"></label>
                            <label>IP 白名单 <input type="text" name="ip_whitelist" value="<?= e($a['ip_whitelist']) ?>" placeholder="留空不限制"></label>
                            <div class="row-actions">
                                <button class="btn btn-sm btn-primary" type="submit">保存配置</button>
                            </div>
                        </form>
                        <div class="row-actions" style="margin-top:8px">
                            <form method="post" onsubmit="return confirm('重置后旧密钥立即失效，确认？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="regen">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm btn-warn" type="submit">重置密钥</button>
                            </form>
                            <form method="post" onsubmit="return confirm('确认删除该应用？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">删除</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>最近调用记录</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>时间</th><th>AppID</th><th>动作</th><th>结果</th><th>消息</th><th>耗时</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="small muted"><?= e($l['created_at']) ?></td>
                    <td class="mono small"><?= e($l['appid']) ?></td>
                    <td class="mono small"><?= e($l['action']) ?></td>
                    <td><span class="badge <?= (int)$l['code'] === 0 ? 'badge-ok' : 'badge-bad' ?>"><?= (int)$l['code'] ?></span></td>
                    <td class="wrap small"><?= e($l['msg']) ?></td>
                    <td class="small"><?= (int)$l['cost_ms'] ?>ms</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="6" class="empty">暂无调用记录</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
