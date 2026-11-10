<?php
/**
 * 优享财务系统 · 电脑版访问
 *
 * 后台的「电脑版访问」入口页：给出站点桌面版地址，并当场探测一次访问状态，
 * 避免管理员把地址发出去之后才发现站点打不开。
 *
 * 本系统没有移动端独立模板，也没有 UA 重定向，桌面版与手机版是同一套响应式页面
 * （assets/css/style.css 内按宽度自适应），因此这里的地址即站点根地址。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

/** 站点根地址：优先显式配置，其次按当前请求推断；CLI 下都拿不到时返回空串 */
$siteUrl = '';
foreach ([(string)setting('site_url', ''), (string)config('app.url', '')] as $cand) {
    $cand = trim($cand);
    if ($cand !== '') {
        $siteUrl = rtrim($cand, '/');
        break;
    }
}
if ($siteUrl === '') {
    $siteUrl = base_url();
}

/** 桌面版地址（首页） */
$desktopUrl = $siteUrl !== '' ? $siteUrl . '/' : url('index.php');

/**
 * 探测站点是否可访问
 *
 * 只请求本站首页，跟随重定向（http → https 很常见），超时 8 秒。
 * 结论只反映「服务器到自身」的连通性，用于排查配置问题，不代表外网一定可达。
 */
$probe = ['ok' => false, 'code' => 0, 'msg' => '', 'final' => '', 'time' => 0.0];
if ($siteUrl !== '') {
    $t0 = microtime(true);
    if (function_exists('curl_init')) {
        $ch = curl_init($siteUrl . '/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'YxPanel-DesktopCheck/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $probe['code']  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $probe['final'] = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        $probe['ok']  = $body !== false && $probe['code'] > 0 && $probe['code'] < 400;
        $probe['msg'] = $probe['ok']
            ? 'HTTP ' . $probe['code']
            : ($err !== '' ? $err : 'HTTP ' . $probe['code']);
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $body = @file_get_contents($siteUrl . '/', false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        $probe['code'] = $code;
        $probe['ok']   = $body !== false && $code > 0 && $code < 400;
        $probe['msg']  = $probe['ok'] ? 'HTTP ' . $code : '请求失败或服务未响应';
    }
    $probe['time'] = round(microtime(true) - $t0, 3);

    // 区分「站点真的挂了」与「服务器绕不回来访问自己」：
    // 云主机常把域名解析到 NAT 公网 IP，本机回不到该 IP，探测必然失败，
    // 但外部客户访问是正常的。此时用回环地址再试一次，能通即说明服务本身没问题。
    $probe['loopback'] = false;
    if (!$probe['ok']) {
        $host = normalize_domain($siteUrl);
        if ($host !== '' && ($probe['code'] === 0 || $probe['code'] >= 400)) {
            $scheme = stripos($siteUrl, 'https://') === 0 ? 'https' : 'http';
            $ch2 = function_exists('curl_init') ? curl_init($scheme . '://127.0.0.1' . '/') : null;
            if ($ch2) {
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_HTTPHEADER     => ['Host: ' . $host],
                    CURLOPT_USERAGENT      => 'YxPanel-DesktopCheck/1.0',
                ]);
                $body2 = curl_exec($ch2);
                $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                $probe['loopback'] = $body2 !== false && $code2 > 0 && $code2 < 400;
                $probe['loopback_code'] = $code2;
            }
        }
    }

    log_action('desktop.check', $desktopUrl, ['ok' => $probe['ok'], 'code' => $probe['code'], 'loopback' => $probe['loopback']]);
}

$pageTitle  = '电脑版访问';
$activeMenu = 'desktop';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="alert alert-warn">
    <strong>电脑版地址就是站点首页。</strong>
    <span>
        本系统没有独立的移动端模板，前台页面统一使用响应式布局，手机与电脑访问的是同一套地址，
        不需要也不会自动跳转到 <code>m.</code> 之类的子域。把下面的地址发给客户即可打开电脑版。
    </span>
</div>

<div class="grid-2">
    <div class="card">
        <h2>电脑版访问地址</h2>
        <p class="muted small">点击「打开电脑版」会在新窗口打开前台首页。</p>
        <label>站点根地址
            <input type="text" readonly value="<?= e($siteUrl !== '' ? $siteUrl : '（未配置，无法推断）') ?>"
                   onclick="this.select()" class="mono">
        </label>
        <label>桌面版首页
            <input type="text" readonly value="<?= e($desktopUrl) ?>" onclick="this.select()" class="mono">
        </label>
        <div class="form-actions">
            <a class="btn btn-primary" href="<?= e($desktopUrl) ?>" target="_blank" rel="noopener">打开电脑版</a>
            <a class="btn btn-ghost" href="<?= e(url('admin/settings.php')) ?>">修改站点地址</a>
        </div>
        <?php if ($siteUrl === ''): ?>
            <p class="muted small">
                站点根地址为空（CLI 或未配置 <code>site_url</code>），请到「站务管理 → 站点根地址」填写，
                例如 <code>https://<?= e(site_domain()) ?></code>。
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>访问状态检测</h2>
        <p class="muted small">
            刚从服务器本机请求了一次站点首页，跟随重定向并统计耗时。
            该结论只代表<strong>本机能访问到站点</strong>，供排查配置使用；客户从外网访问不受此限制。
        </p>
        <div class="table-wrap"><table class="data">
            <tbody>
                <tr>
                    <th style="width:130px">检测结果</th>
                    <td>
                        <?php if ($siteUrl === ''): ?>
                            <span class="badge badge-mute">未检测（缺少站点地址）</span>
                        <?php elseif ($probe['ok']): ?>
                            <span class="badge badge-ok">可正常访问</span>
                        <?php elseif (!empty($probe['loopback'])): ?>
                            <span class="badge badge-warn">服务正常（本机回环可用）</span>
                        <?php else: ?>
                            <span class="badge badge-bad">访问异常</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>HTTP 状态码</th><td class="mono"><?= $probe['code'] > 0 ? (int)$probe['code'] : '-' ?></td></tr>
                <tr><th>实际访问地址</th><td class="mono small wrap"><?= e($probe['final'] !== '' ? $probe['final'] : ($desktopUrl ?: '-')) ?></td></tr>
                <tr><th>耗时</th><td class="mono"><?= $probe['time'] > 0 ? e((string)$probe['time']) . ' 秒' : '-' ?></td></tr>
                <tr><th>说明</th><td class="small"><?= e($probe['msg'] !== '' ? $probe['msg'] : '未执行检测') ?></td></tr>
                <tr>
                    <th>回环探测</th>
                    <td class="small">
                        <?php if (!empty($probe['loopback'])): ?>
                            <span class="badge badge-ok">通过</span>
                            HTTP <?= (int)($probe['loopback_code'] ?? 0) ?>（127.0.0.1 带 Host 头）
                        <?php elseif ($siteUrl !== '' && !$probe['ok']): ?>
                            <span class="badge badge-mute">未通过</span>
                        <?php else: ?>
                            <span class="badge badge-mute">未执行</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table></div>
        <?php if ($siteUrl !== '' && !$probe['ok'] && !empty($probe['loopback'])): ?>
            <div class="alert alert-warn" style="margin-top:12px">
                <strong>站点服务本身正常，只是服务器不能通过域名访问自己。</strong>
                <span>
                    域名解析到的是公网 IP，而本机可能处在 NAT 之后（或防火墙只对外放行），
                    所以「本机 → 域名」会超时；用回环地址带 Host 头请求同一站点是 200。
                    这种情况<strong>不影响客户从外网打开电脑版</strong>，可以直接把上面的地址发给客户。
                </span>
            </div>
        <?php elseif ($siteUrl !== '' && !$probe['ok']): ?>
            <div class="alert alert-error" style="margin-top:12px">
                <strong>连回环地址也访问不通，站点确实存在故障。</strong>
                <span>
                    请依次确认：nginx 站点已启动（<code>nginx -t</code>）、PHP 进程正常、
                    站点根目录与 <code>index.php</code> 存在、SSL 证书未过期。
                </span>
            </div>
        <?php endif; ?>
        <p class="muted small" style="margin-top:10px">
            本系统会把 <code>http</code> 请求 301 跳转到 <code>https</code>，证书异常时两种探测都会失败。
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
