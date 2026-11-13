<?php
/**
 * 优享财务系统 · 授权中心
 *
 * 查看 / 复核当前域名授权状态，维护授权记录，并（企业版）作为官方授权服务器对外提供按域名查询接口。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$STATUS_OPTS = ['active' => '正常', 'suspended' => '暂停', 'banned' => '封禁'];
$TYPE_OPTS   = ['official' => '官方授权', 'trial' => '试用授权', 'internal' => '内部授权', 'partner' => '合作伙伴'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'recheck') {
        if (YxLicense::isCommunity()) {
            $res = ['ok' => false, 'msg' => '当前为开源版（未启用授权模块），无需校验'];
            log_action('license.recheck', current_domain(), ['skipped' => 'community']);
            flash($res['msg'], 'warn');
            redirect(url('admin/licenses.php'));
        }
        YxLicense::flushCache();
        $r = YxLicense::query(current_domain(), true);
        YxLicense::log(current_domain(), $r['status'], $r['source'], '后台手动复核');
        log_action('license.recheck', current_domain(), ['status' => $r['status']]);
        $res = ['ok' => true, 'msg' => '复核完成：' . $r['message'] . '（来源：' . $r['source'] . '）'];
    } elseif ($action === 'flush') {
        YxLicense::flushCache();
        log_action('license.flush_cache');
        $res = ['ok' => true, 'msg' => '本地授权缓存已清空，下次访问将重新向官方接口校验'];
    } elseif ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $domain = normalize_domain((string)($_POST['domain'] ?? ''));
        if ($domain === '') {
            $res = ['ok' => false, 'msg' => '域名不能为空'];
        } else {
            $edition = isset(YxEdition::EDITIONS[$_POST['edition'] ?? '']) ? $_POST['edition'] : 'basic';
            $expire  = (string)($_POST['expire_at'] ?? '') ?: null;
            $data = [
                'domain'      => $domain,
                'license_key' => trim((string)($_POST['license_key'] ?? '')) ?: YxLicense::generateKey($domain, $edition, (string)$expire),
                'edition'     => $edition,
                'type'        => isset($TYPE_OPTS[$_POST['type'] ?? '']) ? $_POST['type'] : 'official',
                'status'      => isset($STATUS_OPTS[$_POST['status'] ?? '']) ? $_POST['status'] : 'active',
                'expire_at'   => $expire,
                'max_sites'   => (int)($_POST['max_sites'] ?? 1),
                'remark'      => trim((string)($_POST['remark'] ?? '')),
            ];
            $dup = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('license') . ' WHERE domain = ? AND id <> ?', [$domain, $id]);
            if ($dup) {
                $res = ['ok' => false, 'msg' => '该域名的授权记录已存在'];
            } elseif ($id > 0) {
                YxDb::update('license', $data, 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '授权记录已更新'];
            } else {
                $data['bound_at']   = now();
                $data['created_at'] = now();
                YxDb::insert('license', $data);
                $res = ['ok' => true, 'msg' => '授权记录已创建'];
            }
            YxLicense::flushCache();
        }
    } elseif ($action === 'delete') {
        YxDb::delete('license', 'id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        YxLicense::flushCache();
        $res = ['ok' => true, 'msg' => '授权记录已删除'];
    } elseif ($action === 'genkey') {
        $domain  = normalize_domain((string)($_POST['gen_domain'] ?? ''));
        $edition = isset(YxEdition::EDITIONS[$_POST['gen_edition'] ?? '']) ? $_POST['gen_edition'] : 'basic';
        $expire  = (string)($_POST['gen_expire'] ?? '');
        if ($domain === '') {
            $res = ['ok' => false, 'msg' => '请输入需要签发的域名'];
        } else {
            $key = YxLicense::generateKey($domain, $edition, $expire);
            $res = ['ok' => true, 'msg' => '域名 ' . $domain . ' 的授权码为：' . $key];
        }
    } elseif ($action === 'save_credential') {
        // 三要素：授权地址 + 授权ID + 授权密钥
        $api    = trim((string)($_POST['license_official_api'] ?? ''));
        $path   = trim((string)($_POST['license_query_path'] ?? ''));
        $appId  = trim((string)($_POST['license_app_id'] ?? ''));
        $secret = trim((string)($_POST['license_channel_secret'] ?? ''));

        if ($api !== '' && !preg_match('#^https?://#i', $api)) {
            $res = ['ok' => false, 'msg' => '授权地址必须以 http:// 或 https:// 开头'];
        } elseif ($appId !== '' && !preg_match('/^[A-Za-z0-9_\-]{6,40}$/', $appId)) {
            $res = ['ok' => false, 'msg' => '授权ID 需为 6-40 位字母、数字、下划线或短横线'];
        } else {
            set_setting('license_official_api', rtrim($api, '/'));
            set_setting('license_query_path', $path !== '' ? $path : '/api/license.php');
            set_setting('license_app_id', $appId);
            // 密钥留空表示不修改，避免每次保存都要重新粘贴
            if ($secret !== '') {
                if (strlen($secret) < 16) {
                    $res = ['ok' => false, 'msg' => '授权密钥至少 16 位'];
                } else {
                    set_setting('license_channel_secret', $secret);
                    $res = ['ok' => true, 'msg' => '授权对接参数已保存（含新密钥）'];
                }
            } else {
                $res = ['ok' => true, 'msg' => '授权对接参数已保存'];
            }
            if (!empty($res['ok'])) {
                YxLicense::flushCache();
            }
        }
    } elseif ($action === 'gen_credential') {
        // 一键生成授权ID + 授权密钥：写入系统设置，并同步登记到开放接口应用表，
        // 使本机同时具备「客户端」与「授权中心」两种能力。
        $appId  = strtolower('yxlic' . substr(md5(random_nonce(16)), 0, 12));
        $secret = random_nonce(32);
        set_setting('license_app_id', $appId);
        set_setting('license_channel_secret', $secret);
        $exists = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ?', [$appId]);
        if (!$exists) {
            YxDb::insert('api_app', [
                'name'          => '授权中心（本机）',
                'appid'         => $appId,
                'secret'        => yx_encrypt($secret),
                'owner_type'    => 'admin',
                'owner_id'      => 0,
                'rate_limit'    => 600,
                'allow_actions' => 'license.query',
                'status'        => 1,
                'remark'        => '授权中心三要素自动生成',
                'created_at'    => now(),
            ]);
        }
        YxLicense::flushCache();
        $res = ['ok' => true, 'msg' => '已生成授权ID：' . $appId . '　授权密钥：' . $secret . '（请复制到需要校验授权的站点）'];
    } elseif ($action === 'test_credential') {
        $err = '';
        if (!YxLicense::hasCredentials()) {
            $res = ['ok' => false, 'msg' => '三要素未配齐：请填写授权地址、授权ID与授权密钥'];
        } else {
            YxLicense::flushCache();
            $r = YxLicense::query(current_domain(), true);
            if ($r['source'] === 'remote') {
                $res = ['ok' => true, 'msg' => '对接成功：' . $r['message'] . '（授权版本 ' . YxEdition::name($r['edition']) . '）'];
            } else {
                $err = YxLicense::lastError();
                $res = [
                    'ok'  => false,
                    'msg' => '未能从授权地址取得结论（当前结果来自 ' . $r['source'] . '）'
                        . ($err !== '' ? '：' . $err : ''),
                ];
            }
        }
    }

    log_action('license.' . $action, '', ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/licenses.php'));
}

$badge   = YxLicense::badge();
$current = $badge['detail'];
$licMode = YxLicense::mode();

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('license') . ' WHERE id = ?', [$editId]) : null;
$records = YxDb::all('SELECT * FROM ' . YxDb::table('license') . ' ORDER BY id DESC LIMIT 200');
$logs    = YxDb::all('SELECT * FROM ' . YxDb::table('license_log') . ' ORDER BY id DESC LIMIT 50');

$queryUrl = rtrim(YxLicense::officialApi(), '/') . YxLicense::queryPath();

// 三要素现状：密钥仅展示掩码，不回显明文
$credApi    = YxLicense::officialApi();
$credAppId  = YxLicense::appId();
$credSecret = YxLicense::channelSecret();
$credReady  = YxLicense::hasCredentials();
$secretMask = $credSecret === '' ? '' : substr($credSecret, 0, 4) . str_repeat('•', 12) . substr($credSecret, -4);

$pageTitle  = '授权中心';
$activeMenu = 'licenses';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<?php if ($licMode !== 'official'): ?>
    <div class="alert alert-ok">
        <strong>当前为离线授权模式：授权模块不联网校验。</strong>
        <span>系统不会向官方接口发起授权校验，也不写入校验日志；授权结论由本地给出，
        版本能力不受限制。如需按域名联网校验，请在「站务管理 → 授权模式」切换为正式版。</span>
    </div>
<?php endif; ?>

<div class="card" id="credential">
    <h2>授权对接（授权地址 + 授权ID + 授权密钥）</h2>
    <p class="muted small">
        本站将按下列三要素向授权地址发起查询，验证本站域名是否已获得正版授权。
        三要素缺一不可——未配齐时不会发起任何联网校验，结果只来自本地授权表。
        若授权地址就是本站域名（本机同时是授权中心），系统会自动改走本机回环请求，
        因为云主机/NAT 环境下服务器无法访问自身公网 IP。
    </p>
    <table class="data">
        <tr><th>授权状态</th><td>
            <?php if ($credReady): ?>
                <span class="badge badge-ok">✓ 三要素已配齐</span>
            <?php else: ?>
                <span class="badge badge-warn">! 三要素未配齐，暂不联网校验</span>
            <?php endif; ?>
        </td></tr>
        <tr><th>授权地址</th><td class="mono small"><?= e($credApi !== '' ? $credApi : '未配置') ?></td></tr>
        <tr><th>授权ID</th><td class="mono small"><?= e($credAppId !== '' ? $credAppId : '未配置') ?></td></tr>
        <tr><th>授权密钥</th><td class="mono small"><?= e($secretMask !== '' ? $secretMask : '未配置') ?></td></tr>
        <tr><th>完整查询地址</th><td class="mono small"><?= e($credReady ? $queryUrl : '—') ?></td></tr>
    </table>

    <details style="margin-top:14px">
        <summary style="cursor:pointer;color:var(--primary)">修改对接参数</summary>
        <form method="post" style="margin-top:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_credential">
            <div class="grid-2">
                <label>授权地址（如 https://license.example.com，不含接口路径）
                    <input type="text" name="license_official_api" value="<?= e($credApi) ?>" placeholder="https://license.example.com">
                </label>
                <label>查询接口路径
                    <input type="text" name="license_query_path" value="<?= e(YxLicense::queryPath()) ?>" placeholder="/api/license.php">
                </label>
                <label>授权ID
                    <input type="text" name="license_app_id" value="<?= e($credAppId) ?>" placeholder="yxlic...">
                </label>
                <label>授权密钥（留空不修改）
                    <input type="password" name="license_channel_secret" value="" autocomplete="new-password"
                           placeholder="<?= $credSecret !== '' ? '已设置，留空保持不变' : '至少 16 位' ?>">
                </label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存对接参数</button>
            </div>
        </form>
        <form method="post" class="form-actions" style="margin-top:10px" onsubmit="return confirm('将生成新的授权ID与密钥，已保存的旧凭据立即失效。继续？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="gen_credential">
            <button class="btn btn-ghost" type="submit"><?= $credReady ? '重新生成授权ID与密钥' : '一键生成授权ID与密钥' ?></button>
        </form>
        <p class="muted small" style="margin-top:8px">
            「一键生成」会把授权ID与密钥同时登记为本机授权中心凭据，本机即可对外提供授权查询，也可把同一组凭据填到其它站点。
        </p>
    </details>

    <div class="form-actions" style="margin-top:14px">
        <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="test_credential">
            <button class="btn btn-primary" type="submit">测试对接</button>
        </form>
    </div>
    <p class="muted small" style="margin-top:10px">
        签名算法：<code>HMAC-SHA256(除 sign 外全部参数按 key 升序拼成 k=v&amp;k=v…, 授权密钥)</code>，
        与开放接口一致；请求带 <code>ts</code>（±300 秒）与一次性 <code>nonce</code> 防重放，应答亦带签名供客户端复核。
    </p>
</div>

<div class="grid-2">
    <div class="card">
        <h2>当前站点授权状态</h2>
        <p>
            <span class="badge badge-<?= $badge['class'] === 'ok' ? 'ok' : ($badge['class'] === 'warn' ? 'warn' : 'bad') ?>" style="font-size:14px;padding:5px 14px">
                <?= $badge['icon'] ?> <?= e($badge['text']) ?>
            </span>
        </p>
        <table class="data">
            <tr><th>授权域名</th><td class="mono"><?= e($current['domain']) ?></td></tr>
            <tr><th>授权时间</th><td><?= e(($current['issued_at'] ?? '') ?: '-') ?></td></tr>
            <tr><th>到期时间</th><td><?= e($current['expire_at'] ?: ($current['authorized'] ? '无期限' : '-')) ?></td></tr>
            <tr><th>授权官方</th><td><?= e($current['official'] ?? YxLicense::OFFICIAL_NAME) ?></td></tr>
            <tr><th>授权版本</th><td><?= e(YxEdition::name($current['authorized'] ? $current['edition'] : 'basic')) ?></td></tr>
            <tr><th>结果来源</th><td><?= e($current['source']) ?>
                <span class="muted small">（remote=官方接口，local=本地授权表，cache=本地缓存）</span></td></tr>
            <tr><th>校验时间</th><td><?= e($current['checked_at']) ?></td></tr>
            <tr><th>官方接口</th><td class="mono small"><?= e(YxLicense::officialApi()) ?></td></tr>
        </table>
        <div class="form-actions" style="margin-top:14px">
            <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="action" value="recheck">
                <button class="btn btn-primary" type="submit">立即复核</button>
            </form>
            <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="action" value="flush">
                <button class="btn btn-ghost" type="submit">清空本地缓存</button>
            </form>
        </div>
        <p class="muted small" style="margin-top:12px">
            未授权时前台与后台均会显示「域名未在官方授权」。若需在未授权时把功能收敛为普惠版，
            可在「站务管理」中开启 <code>license_enforce</code>。
        </p>
    </div>

    <div class="card">
        <h2>按域名签发授权码</h2>
        <p class="muted small">同一域名 + 版本 + 到期日 生成结果稳定，可离线复核，无需保存明文。</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="genkey">
            <div class="grid-2">
                <label>授权域名 <input type="text" name="gen_domain" placeholder="www.example.com" required></label>
                <label>授权版本
                    <select name="gen_edition">
                        <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                            <option value="<?= e($k) ?>"><?= e($ed['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>到期日期 <input type="date" name="gen_expire"></label>
            </div>
            <button class="btn btn-primary" type="submit">生成授权码</button>
        </form>
    </div>
</div>

<div class="card">
    <details <?= $editing ? 'open' : '' ?>>
        <summary style="cursor:pointer;color:var(--primary)"><?= $editing ? '编辑授权记录：' . e($editing['domain']) : '＋ 新增授权记录' ?></summary>
        <form method="post" style="margin-top:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-3">
                <label>授权域名 <input type="text" name="domain" required value="<?= e($editing['domain'] ?? '') ?>"></label>
                <label>授权版本
                    <select name="edition">
                        <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['edition'] ?? 'basic') === $k ? 'selected' : '' ?>><?= e($ed['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>授权类型
                    <select name="type">
                        <?php foreach ($TYPE_OPTS as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['type'] ?? 'official') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>到期日期（留空为无期限） <input type="date" name="expire_at" value="<?= e($editing['expire_at'] ?? '') ?>"></label>
                <label>可绑定站点数 <input type="number" name="max_sites" value="<?= e($editing['max_sites'] ?? 1) ?>"></label>
                <label>状态
                    <select name="status">
                        <?php foreach ($STATUS_OPTS as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>授权码（留空自动按域名生成） <input type="text" name="license_key" value="<?= e($editing['license_key'] ?? '') ?>" class="mono"></label>
            <label>备注 <input type="text" name="remark" value="<?= e($editing['remark'] ?? '') ?>"></label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建授权' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/licenses.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </details>
</div>

<div class="card">
    <h2>授权记录（<?= count($records) ?> 条）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>域名</th><th>授权码</th><th>版本</th><th>类型</th><th>到期</th><th>最近校验</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($records as $r): ?>
            <?php
            $expired = $r['expire_at'] && $r['expire_at'] < date('Y-m-d');
            $cls = $r['status'] === 'banned' ? 'badge-bad' : ($r['status'] === 'active' && !$expired ? 'badge-ok' : 'badge-warn');
            ?>
            <tr>
                <td class="mono"><?= e($r['domain']) ?></td>
                <td class="mono small"><?= e($r['license_key']) ?></td>
                <td><?= e(YxEdition::name($r['edition'])) ?></td>
                <td><?= e($TYPE_OPTS[$r['type']] ?? $r['type']) ?></td>
                <td><?= e($r['expire_at'] ?: '无期限') ?><?= $expired ? ' <span class="badge badge-bad">已过期</span>' : '' ?></td>
                <td class="small muted"><?= e($r['last_check_at'] ?: '-') ?></td>
                <td><span class="badge <?= $cls ?>"><?= e($STATUS_OPTS[$r['status']] ?? $r['status']) ?></span></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/licenses.php?edit=' . (int)$r['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确认删除该授权记录？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?><tr><td colspan="8" class="empty">暂无授权记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php if (YxEdition::can('license.center')): ?>
<div class="card">
    <h2>对外授权服务（企业版）</h2>
    <p class="muted small">把本系统部署为授权中心后，其它站点可通过下列地址按域名查询授权，返回 JSON。</p>
    <table class="data">
        <tr><th>查询接口</th><td class="mono"><?= e(url('api/license.php')) ?></td></tr>
        <tr><th>请求方式</th><td><code>GET <?= e(url('api/license.php')) ?>?domain=www.example.com&ts=1699&sign=...</code></td></tr>
        <tr><th>返回示例（已授权）</th><td class="mono small">{"code":0,"msg":"域名已正版授权","data":{"domain":"www.example.com","authorized":true,"status":"authorized","edition":"pro","expire_at":"2027-10-13"}}</td></tr>
        <tr><th>返回示例（未授权）</th><td class="mono small">{"code":0,"msg":"域名未在官方授权","data":{"domain":"pirate.com","authorized":false,"status":"unauthorized"}}</td></tr>
    </table>
    <p class="muted small" style="margin-top:10px">
        各客户端在 <code>includes/config.php</code> 的 <code>license.official_api</code> 填入本地址即可自动按域名校验。
    </p>
</div>
<?php else: ?>
<div class="card">
    <h2>对外授权服务</h2>
    <p class="muted">将本系统作为官方授权服务器对外提供按域名查询接口，属于「企业版」能力。当前版本：<?= e(YxEdition::name()) ?>。</p>
    <a class="btn btn-primary" href="<?= e(url('admin/edition.php')) ?>">了解版本能力</a>
</div>
<?php endif; ?>

<div class="card">
    <h2>授权校验日志（最近 50 条）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>时间</th><th>域名</th><th>结果</th><th>来源</th><th>IP</th><th>说明</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td class="small muted"><?= e($l['created_at']) ?></td>
                <td class="mono small"><?= e($l['domain']) ?></td>
                <td><span class="badge <?= $l['result'] === 'authorized' ? 'badge-ok' : 'badge-bad' ?>"><?= e(YxLicense::statusText($l['result'])) ?></span></td>
                <td><?= e($l['source']) ?></td>
                <td class="mono small"><?= e($l['ip']) ?></td>
                <td class="small muted"><?= e($l['message'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6" class="empty">暂无校验日志</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
