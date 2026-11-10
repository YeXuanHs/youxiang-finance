<?php
/**
 * 优享财务系统 · 我的授权（应用授权 + 域名授权检测）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$checkResult = null;
$checkDomain = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'check_domain') {
        $checkDomain = normalize_domain((string)($_POST['domain'] ?? ''));
        if ($checkDomain === '') {
            flash('请输入要检测的域名', 'error');
        } else {
            $checkResult = YxLicense::query($checkDomain, true);
            YxLicense::log($checkDomain, $checkResult['status'], $checkResult['source'], '客户端自助检测');
            log_action('license.front_check', $checkDomain, ['status' => $checkResult['status']]);
        }
    } elseif ($action === 'bind_domain') {
        $id     = (int)($_POST['id'] ?? 0);
        $domain = normalize_domain((string)($_POST['domain'] ?? ''));
        $inst   = YxDb::one('SELECT * FROM ' . YxDb::table('app_install') . ' WHERE id = ? AND customer_id = ?', [$id, $cid]);
        if (!$inst) {
            flash('安装记录不存在', 'error');
        } elseif ($domain === '') {
            flash('请填写绑定域名', 'error');
        } else {
            $dup = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_install') . ' WHERE app_id = ? AND domain = ? AND id <> ?', [(int)$inst['app_id'], $domain, $id]);
            if ($dup) {
                flash('该域名已绑定过此应用', 'error');
            } else {
                YxDb::update('app_install', [
                    'domain'      => $domain,
                    'license_key' => YxBusiness::appLicenseKey((string)$inst['app_key'], $domain),
                ], 'id = :id', ['id' => $id]);
                flash('授权域名已更新，授权码同步重新签发');
            }
        }
    }

    redirect(url('user/licenses.php'));
}

$installs = YxDb::all(
    'SELECT i.*, a.name AS app_name, a.version FROM ' . YxDb::table('app_install') . ' i
     LEFT JOIN ' . YxDb::table('app') . ' a ON a.id = i.app_id
     WHERE i.customer_id = ? ORDER BY i.id DESC',
    [$cid]
);

$siteBadge = YxLicense::badge();

// 官方正版程序包：程序包本体免费下载（登录即可），授权包（安装授权码）在商店另行按次购买
$pkg = YxDb::one(
    "SELECT * FROM " . YxDb::table('app') . " WHERE author_type = 'official' AND type = 'package' AND status = 'on' AND key NOT LIKE '%open%' ORDER BY id DESC LIMIT 1"
);
$openPkg = YxDb::one(
    "SELECT * FROM " . YxDb::table('app') . " WHERE author_type = 'official' AND type = 'package' AND status = 'on' AND key LIKE '%open%' ORDER BY id DESC LIMIT 1"
);
$pkgEnt = $pkg ? YxBusiness::entitlement((int)$pkg['id'], $cid) : null;

$userTitle  = '我的授权';
$userActive = 'licenses';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="grid-2">
    <div class="card">
        <h2>当前站点授权状态</h2>
        <p>
            <span class="badge badge-lg badge-<?= in_array($siteBadge['class'], ['ok', 'warn', 'bad', 'info'], true) ? $siteBadge['class'] : 'bad' ?>">
                <?= $siteBadge['icon'] ?> <?= e($siteBadge['text']) ?>
            </span>
        </p>
        <table class="data kv">
            <tr><th>授权域名</th><td class="mono"><?= e($siteBadge['detail']['domain']) ?></td></tr>
            <tr><th>授权时间</th><td><?= e($siteBadge['detail']['issued_at'] ?? '' ?: '-') ?></td></tr>
            <tr><th>到期时间</th><td><?= e($siteBadge['detail']['expire_at'] ?: '无期限') ?></td></tr>
            <tr><th>授权版本</th><td><?= e(YxEdition::name($siteBadge['detail']['authorized'] ? $siteBadge['detail']['edition'] : 'basic')) ?></td></tr>
            <tr><th>授权官方</th><td><?= e(YxLicense::OFFICIAL_NAME) ?></td></tr>
        </table>
    </div>

    <?php if ($pkg || $openPkg): ?>
    <?php $pkgCard = $pkg ?: $openPkg; ?>
    <div class="card">
        <h2>程序包下载</h2>
        <p class="muted small">程序包免费下载，登录即可获取；安装正版发行包时需要的授权码属「授权包」，按次（按域名）单独购买。解压到站点根目录后访问 <code>/install/install.php</code> 完成安装。</p>
        <table class="data kv">
            <tr><th>程序包</th><td><?= e($pkgCard['name']) ?></td></tr>
            <tr><th>版本</th><td><?= e($pkgCard['version'] ?: '-') ?></td></tr>
            <tr><th>运行环境</th><td><?= e($pkgCard['compatible'] ?: 'PHP 7.4 - 8.2') ?></td></tr>
            <tr><th>包大小</th><td><?= e(number_format(((int)$pkgCard['package_size']) / 1048576, 2)) ?> MB</td></tr>
            <tr><th>程序包价格</th><td>免费</td></tr>
            <tr><th>授权包价格</th><td><?= e($pkg ? money((float)$pkg['price'], true) . (YxBusiness::billType($pkg) === 'year' ? '/年' : '/次') . '（按域名签发授权码）' : '不适用（开源版无需授权码）') ?></td></tr>
        </table>
        <?php if ($pkg): ?>
            <a class="btn btn-primary btn-block mt-3" href="<?= e(url('api/store_download.php?id=' . (int)$pkg['id'])) ?>">免费下载正版程序包（tar.gz）</a>
            <?php if ($pkgEnt && (string)($pkgEnt['license_key'] ?? '') !== ''): ?>
                <div class="alert alert-ok mt-3">
                    <strong>安装授权码（与绑定域名 <?= e($pkgEnt['domain']) ?> 一一对应）</strong>
                    <p class="mono" style="font-size:18px;letter-spacing:1px;margin:8px 0"><?= e($pkgEnt['license_key']) ?></p>
                    <p class="muted small" style="margin:0">安装时访问 <code>/install/install.php</code>，在「创始人信息与授权码」步骤填入该码；正版发行包强校验授权码，缺失或不匹配将无法完成安装。</p>
                </div>
                <p class="muted small mt-2">已购买的授权包有效期内可重复使用；程序包可随时免费重新下载。</p>
                <?php
                $certRow = YxDb::one(
                    'SELECT id, sn FROM ' . YxDb::table('certificate') . ' WHERE customer_id = ? AND domain = ? AND product = ? ORDER BY id DESC LIMIT 1',
                    [$cid, (string)$pkgEnt['domain'], 'official']
                );
                ?>
                <?php if ($certRow): ?>
                    <a class="btn btn-ghost btn-block mt-2" href="<?= e(url('user/certificate.php?id=' . (int)$certRow['id'])) ?>">查看正版购买证明（<?= e($certRow['sn']) ?> · 加盖电子公章）</a>
                <?php endif; ?>
            <?php else: ?>
                <?php
                // 与上面「授权包价格」同一口径：金额 + 单位，避免「¥366.00 一次性 / 次」重复
                $pkgPriceTxt = money((float)$pkg['price'], true) . (YxBusiness::billType($pkg) === 'year' ? '/年' : '/次');
                ?>
                <div class="alert alert-warn mt-3">
                    程序包可免费下载安装；未购买授权包时，正版发行包会以「未授权」状态运行，安装向导要求填写授权码时需先购买授权包（<?= e($pkgPriceTxt) ?>）。
                </div>
                <a class="btn btn-block" href="<?= e(url('store/detail.php?id=' . (int)$pkg['id'])) ?>">单独购买授权包 · <?= e($pkgPriceTxt) ?></a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($openPkg): ?>
            <a class="btn btn-block mt-2" href="<?= e(url('api/store_download.php?id=' . (int)$openPkg['id'])) ?>">下载开源版程序包（tar.gz）</a>
            <div class="alert alert-warn mt-2">
                <strong>开源版安装后不可切换正式版。</strong>
                开源版与正版为同一份代码，但开源发行包把授权模式固定为开源版：授权模块不启用，界面显示「开源版 · 未启用授权模块」，
                后台不提供切换正式版的入口。需要按域名校验授权，请下载并安装正版程序包。
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>域名授权检测</h2>
        <p class="muted small">按域名查询授权状态，正版会提示「域名已正版授权」，未登记则提示「域名未在官方授权」。</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="check_domain">
            <label>域名 <input type="text" name="domain" value="<?= e($checkDomain) ?>" placeholder="www.example.com" required></label>
            <button class="btn btn-primary" type="submit">检测授权</button>
        </form>

        <?php if ($checkResult): ?>
            <div class="alert alert-<?= $checkResult['authorized'] ? 'ok' : 'error' ?> mt-4">
                <strong><?= e($checkResult['message']) ?></strong>
            </div>
            <table class="data kv mt-3">
                <tr><th>授权域名</th><td class="mono"><?= e($checkResult['domain']) ?></td></tr>
                <tr><th>授权时间</th><td><?= e(($checkResult['issued_at'] ?? '') ?: '-') ?></td></tr>
                <tr><th>到期时间</th><td><?= e($checkResult['expire_at'] ?: ($checkResult['authorized'] ? '无期限' : '-')) ?></td></tr>
                <tr><th>授权版本</th><td><?= e($checkResult['authorized'] ? YxEdition::name($checkResult['edition']) : '-') ?></td></tr>
                <tr><th>授权官方</th><td><?= e($checkResult['official'] ?? YxLicense::OFFICIAL_NAME) ?></td></tr>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>我的应用授权（<?= count($installs) ?> 个）</h2>
    <p class="muted small">授权码与绑定域名一一对应；更换域名后会重新签发，旧授权码随即失效。</p>
    <?php if (!$installs): ?>
        <div class="empty">还没有安装应用，<a href="<?= e(url('store/index.php')) ?>">去应用商店看看</a></div>
    <?php else: ?>
    <div class="table-wrap"><table class="data mobile-cards">
        <thead><tr><th>应用</th><th>版本</th><th>绑定域名</th><th>授权码</th><th>到期</th><th>状态</th><th>更新绑定域名</th></tr></thead>
        <tbody>
        <?php foreach ($installs as $i):
            // 只有「启用且未过期」的记录视为有效权益，完整授权码仅对这类记录展示；
            // 已过期 / 已停用的记录只给掩码，避免把失效但也可能被继续沿用的码摊开。
            $iValid = YxPluginCenter::installState($i)['key'] === 'enabled';
        ?>
            <tr>
                <td data-label="应用"><?= e($i['app_name'] ?: ('#' . $i['app_id'])) ?></td>
                <td data-label="版本"><?= e($i['version'] ?: '-') ?></td>
                <td data-label="绑定域名" class="mono small"><?= e($i['domain']) ?></td>
                <td data-label="授权码" class="mono small"><?= e($iValid ? $i['license_key'] : YxBusiness::maskLicenseKey((string)$i['license_key'])) ?></td>
                <td data-label="到期"><?= e($i['expire_at'] ?: '-') ?></td>
                <td data-label="状态"><?= $i['status'] === 'enabled' ? '<span class="badge badge-ok">已启用</span>' : '<span class="badge badge-mute">已停用</span>' ?></td>
                <td data-label="更新绑定域名">
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="bind_domain">
                        <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                        <input type="text" name="domain" placeholder="新域名" class="field-sm">
                        <button class="btn btn-sm btn-ghost" type="submit">重新签发</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
