<?php
/**
 * 优享财务系统 · 魔方财务（ZJMF）接口对接
 *
 * 页面提供：
 *   1. 三个开关：API 总开关 / 实名认证开关 / 绑定手机号开关
 *   2. 对接三要素：接口名称、接口地址、API 密钥（加密存储，支持重置）
 *   3. 连通性自测与接口清单
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('openapi');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'toggle') {
        $key = (string)($_POST['key'] ?? '');
        if (!isset(YxZjmf::SWITCHES[$key])) {
            $res = ['ok' => false, 'msg' => '未知开关'];
        } else {
            $on = (string)($_POST['on'] ?? '') === '1';
            YxZjmf::setSwitch($key, $on);
            log_action('zjmf.toggle', $key, ['on' => $on]);
            $res = ['ok' => true, 'msg' => YxZjmf::SWITCHES[$key]['label'] . '已' . ($on ? '开启' : '关闭')];
        }
    } elseif ($action === 'save') {
        $name = trim((string)($_POST['api_name'] ?? ''));
        $url  = trim((string)($_POST['api_url'] ?? ''));
        if ($name === '') {
            $res = ['ok' => false, 'msg' => '接口名称不能为空'];
        } elseif ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $res = ['ok' => false, 'msg' => '接口地址需以 http:// 或 https:// 开头'];
        } else {
            set_setting(YxZjmf::SET_NAME, $name);
            set_setting(YxZjmf::SET_URL, $url);
            YxZjmf::apiKey(true);   // 首次保存时若没有密钥，自动生成一个
            log_action('zjmf.save', $name);
            $res = ['ok' => true, 'msg' => '对接配置已保存'];
        }
    } elseif ($action === 'regen') {
        $key = YxZjmf::rotateKey();
        log_action('zjmf.rotate_key');
        $res = ['ok' => true, 'msg' => '新 API 密钥：' . $key . '（旧密钥立即失效，请同步更新下游配置）'];
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/zjmf.php'));
}

/* 自测：带 api_name / api_key 调自己的 /api/zjmf.php ping，走完整鉴权链路 */
$selfTest = null;
if (isset($_GET['selftest'])) {
    $base   = rtrim(base_url(), '/');
    $target = preg_match('#^https?://#i', YxZjmf::apiUrl()) ? YxZjmf::apiUrl() : $base . '/api/zjmf.php';
    $body   = http_post_json($target, [
        'api_name' => YxZjmf::apiName(),
        'api_key'  => YxZjmf::apiKey(),
        'action'   => 'ping',
    ], ['Accept: application/json'], 8);
    $json = json_decode((string)$body[2], true);
    $selfTest = [
        'url'  => $target,
        'http' => (int)$body[1],
        'raw'  => mb_substr((string)$body[2], 0, 300),
        'ok'   => is_array($json) && (int)($json['status'] ?? 0) === 200,
        'msg'  => is_array($json) ? (string)($json['msg'] ?? '') : '响应非 JSON（检查接口地址是否可从本机访问）',
        'data' => is_array($json) ? ($json['data'] ?? null) : null,
    ];
}

$switches   = YxZjmf::switchStates();
$apiName    = YxZjmf::apiName();
$apiUrl     = YxZjmf::apiUrl();
$keyMasked  = YxZjmf::keyMasked();
$callStats  = [
    '总调用'  => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('api_log') . ' WHERE appid = ?', [YxZjmf::APPID]),
    '近 24 小时' => (int)YxDb::value(
        'SELECT COUNT(*) FROM ' . YxDb::table('api_log') . ' WHERE appid = ? AND created_at >= ?',
        [YxZjmf::APPID, date('Y-m-d H:i:s', time() - 86400)]
    ),
    '最近调用' => (string)(YxDb::value('SELECT MAX(created_at) FROM ' . YxDb::table('api_log') . ' WHERE appid = ?', [YxZjmf::APPID]) ?? ''),
];

$pageTitle  = '接口对接';
$activeMenu = 'zjmf';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <?php foreach ($switches as $key => $sw): ?>
        <div class="stat <?= $sw['on'] ? 'accent' : '' ?>">
            <span><?= e($sw['label']) ?></span>
            <strong><?= $sw['on'] ? '已开启' : '已关闭' ?></strong>
            <form method="post" style="margin-top:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <input type="hidden" name="on" value="<?= $sw['on'] ? '0' : '1' ?>">
                <button class="btn btn-sm <?= $sw['on'] ? 'btn-bad' : 'btn-ok' ?>" type="submit">
                    <?= $sw['on'] ? '关闭' : '开启' ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt-4" style="max-width:860px">
    <h2>开关说明</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>开关</th><th>作用范围</th><th>当前</th></tr></thead>
        <tbody>
        <?php foreach ($switches as $sw): ?>
            <tr>
                <td><?= e($sw['label']) ?></td>
                <td class="small muted"><?= e($sw['desc']) ?></td>
                <td><span class="badge <?= $sw['on'] ? 'badge-ok' : 'badge-mute' ?>"><?= $sw['on'] ? '开启' : '关闭' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">总开关关闭时，<code>/api/v1.php</code>、<code>/api/zjmf.php</code>、<code>/cart/prolist</code> 一律返回 HTTP 503；
       实名 / 手机开关只作用于下单环节，默认关闭以保持既有客户可直接下单。</p>
</div>

<div class="card mt-4" style="max-width:860px">
    <h2>对接信息</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <label>接口名称 <input type="text" name="api_name" required value="<?= e($apiName) ?>" placeholder="双方约定的接口名称"></label>
        <label>接口地址 <input type="text" name="api_url" value="<?= e((string)setting(YxZjmf::SET_URL, '')) ?>" placeholder="<?= e(rtrim(base_url(), '/') . '/api/zjmf.php') ?>"></label>
        <label>API 密钥 <input type="text" readonly value="<?= e($keyMasked) ?>"></label>
        <div class="muted small">接口地址留空时默认使用 <code class="mono"><?= e(rtrim(base_url(), '/') . '/api/zjmf.php') ?></code>；密钥加密存储，明文只在生成时显示一次。</div>
        <div style="margin-top:12px">
            <button class="btn btn-primary" type="submit">保存</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/zjmf.php?selftest=1')) ?>">连通性自测</a>
        </div>
    </form>
    <form method="post" style="margin-top:10px" onsubmit="return confirm('重置后旧密钥立即失效，下游需同步更新，确认继续？')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regen">
        <button class="btn btn-sm btn-bad" type="submit">重置密钥</button>
    </form>
</div>

<?php if ($selfTest): ?>
<div class="card mt-4" style="max-width:860px">
    <h2>自测结果</h2>
    <p>
        <span class="badge <?= $selfTest['ok'] ? 'badge-ok' : 'badge-bad' ?>"><?= $selfTest['ok'] ? '通过' : '未通过' ?></span>
        <span class="mono small"><?= e($selfTest['url']) ?></span>
        <span class="small muted">HTTP <?= (int)$selfTest['http'] ?></span>
    </p>
    <p class="small"><?= e($selfTest['msg']) ?></p>
    <?php if ($selfTest['data']): ?>
        <pre class="mono small" style="white-space:pre-wrap"><?= e(json_encode($selfTest['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
    <?php else: ?>
        <pre class="mono small" style="white-space:pre-wrap"><?= e($selfTest['raw']) ?></pre>
    <?php endif; ?>
    <p class="muted small">若提示连接超时，多为服务器回环访问自身域名受限（hairpin NAT），不影响外网下游调用。</p>
</div>
<?php endif; ?>

<div class="grid-3 mt-4">
    <div class="stat"><span>接口总调用</span><strong><?= (int)$callStats['总调用'] ?></strong></div>
    <div class="stat"><span>近 24 小时</span><strong><?= (int)$callStats['近 24 小时'] ?></strong></div>
    <div class="stat"><span>最近调用</span><strong class="small"><?= e($callStats['最近调用'] !== '' ? $callStats['最近调用'] : '暂无') ?></strong></div>
</div>

<div class="card mt-4" style="max-width:860px">
    <h2>接口清单</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>地址</th><th>说明</th><th>鉴权</th></tr></thead>
        <tbody>
            <tr>
                <td class="mono small">/cart/prolist</td>
                <td class="small">公开商品树：分类 → 商品组 → 商品（与魔方前台购物车同结构）</td>
                <td class="small">无需密钥（受总开关约束）</td>
            </tr>
            <tr>
                <td class="mono small">/api/zjmf.php</td>
                <td class="small">动作：ping、products、product.detail、order.create、order.query</td>
                <td class="small">api_name + api_key</td>
            </tr>
            <tr>
                <td class="mono small">/api/v1.php</td>
                <td class="small">既有开放接口（appid + 签名，动作见「下游接口」）</td>
                <td class="small">appid + HMAC 签名（受总开关约束）</td>
            </tr>
        </tbody>
    </table></div>
    <p class="muted small">调用方在浏览器或命令行可直接用
        <code class="mono">POST <?= e(rtrim(base_url(), '/') . '/api/zjmf.php') ?></code>，
        参数 <code>api_name</code>、<code>api_key</code>、<code>action</code>。</p>
</div>

<div class="card mt-4" style="max-width:860px">
    <h2>下游配置填写示例</h2>
    <pre class="mono small" style="white-space:pre-wrap">接口名称：<?= e($apiName) ?>
接口地址：<?= e($apiUrl) ?>
API 密钥：<?= e($keyMasked) ?>（完整值请查看生成记录或点「重置密钥」后获取）

商品同步（无需密钥）：GET  <?= e(rtrim(base_url(), '/') . '/cart/prolist') ?>
下单示例：          POST <?= e(rtrim(base_url(), '/') . '/api/zjmf.php') ?>
                    api_name=<?= e($apiName) ?>&amp;api_key=***&amp;action=order.create&amp;product_id=1&amp;cycle=month&amp;email=buyer@example.com&amp;external_sn=20261103001
订单查询：          POST <?= e(rtrim(base_url(), '/') . '/api/zjmf.php') ?>
                    api_name=<?= e($apiName) ?>&amp;api_key=***&amp;action=order.query&amp;order_sn=YX20261103000001</pre>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
