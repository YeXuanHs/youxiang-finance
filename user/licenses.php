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

/*
 * 官方主程序包三个 SKU，权益与价格各自独立：
 *   program 正版程序包：88 元/次，购买下载权益后方可下载，不含安装授权码
 *   license 安装授权包：授权文件 + 按域名签发的安装授权码
 *   open    开源版：登录即可免费下载，不需要授权码
 */
$pkgs = YxDb::all(
    "SELECT * FROM " . YxDb::table('app') . " WHERE author_type = 'official' AND type = 'package' AND status = 'on' ORDER BY id DESC"
);
$pkgByKind = ['program' => null, 'license' => null, 'open' => null];
foreach ($pkgs as $p) {
    $k = YxStore::packageKind($p);
    if ($k !== '' && !$pkgByKind[$k]) {
        $pkgByKind[$k] = $p;
    }
}
$programPkg = $pkgByKind['program'];
$licensePkg = $pkgByKind['license'];
$openPkg    = $pkgByKind['open'];
$licenseEnt = $licensePkg ? YxBusiness::entitlement((int)$licensePkg['id'], $cid) : null;
/* 正版程序包下载权益：与下载入口同一口径（权益或已支付订单） */
$programBought = $programPkg ? YxStore::hasPurchased($programPkg, $cid) : false;
$pkgPriceTxt = static function (?array $p): string {
    if (!$p) {
        return '';
    }
    /* 仅开源版免费下载：正版程序包/授权包按标价显示 */
    if (YxStore::packageFree($p)) {
        return '免费';
    }
    $bill = YxBusiness::billType($p);
    return money((float)$p['price'], true) . ($bill === 'year' ? '/年' : '/次');
};

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

    <?php if ($programPkg || $licensePkg || $openPkg): ?>
    <div class="card">
        <h2>程序包与授权包</h2>
        <p class="muted small">程序包与授权包是<b>两件独立商品</b>，价格与权益各自计算：程序包只含程序本体，授权包含授权文件与按域名签发的安装授权码，购买其一不等于获得另一项。发行格式：正版程序包与开源包为<b>明文 zip</b>（<code>.zip</code>，无解压密码），安装授权包为<b>整包密文</b>（<code>.zip.enc</code>，解密密钥随购买凭证由本站客服发放）。解压/解包后覆盖到站点根目录，访问 <code>/install/install.php</code> 完成安装；授权包购买成功后系统按结账域名<b>自动签发</b>安装授权码，可在本页下方查看。包内普通文件可自由修改，仅授权核心文件（<code>includes/license.php</code>、<code>includes/license/*.php</code>、<code>includes/pkgsecret.php</code>、<code>install/install.php</code>）保持只读。</p>
        <div class="table-wrap"><table class="data mobile-cards">
            <thead><tr><th>商品</th><th>价格</th><th>包大小</th><th>我的状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php if ($programPkg): ?>
                <tr>
                    <td data-label="商品">正版程序包<br><span class="muted small">v<?= e($programPkg['version'] ?: '-') ?> · 程序本体 + 官方授权模块，不含授权码</span></td>
                    <td data-label="价格"><?= e($pkgPriceTxt($programPkg)) ?></td>
                    <td data-label="包大小"><?= e(number_format(((int)$programPkg['package_size']) / 1048576, 2)) ?> MB</td>
                    <td data-label="我的状态"><?= $programBought ? '<span class="badge badge-ok">已购买</span>' : '<span class="badge badge-mute">未购买</span>' ?></td>
                    <td data-label="操作">
                        <?php if ($programBought): ?>
                            <?php if (YxStore::packageReady($programPkg)): ?>
                                <a class="btn btn-primary btn-sm" href="<?= e(url('api/store_download.php?id=' . (int)$programPkg['id'])) ?>">下载正版程序包</a>
                            <?php else: ?>
                                <span class="muted small">发行文件待上传</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="btn btn-sm" href="<?= e(url('store/detail.php?id=' . (int)$programPkg['id'])) ?>">购买正版程序包</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($licensePkg): ?>
                <tr>
                    <td data-label="商品">安装授权包<br><span class="muted small">v<?= e($licensePkg['version'] ?: '-') ?> · 授权文件 + 按域名签发的安装授权码</span></td>
                    <td data-label="价格"><?= e($pkgPriceTxt($licensePkg)) ?></td>
                    <td data-label="包大小"><?= e(number_format(((int)$licensePkg['package_size']) / 1048576, 2)) ?> MB</td>
                    <td data-label="我的状态"><?= $licenseEnt ? '<span class="badge badge-ok">已购买</span>' : '<span class="badge badge-mute">未购买</span>' ?></td>
                    <td data-label="操作">
                        <?php if ($licenseEnt): ?>
                            <?php if (YxStore::packageReady($licensePkg)): ?>
                                <a class="btn btn-primary btn-sm" href="<?= e(url('api/store_download.php?id=' . (int)$licensePkg['id'])) ?>">下载授权包</a>
                            <?php else: ?>
                                <span class="muted small">发行文件待上传</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="btn btn-sm" href="<?= e(url('store/detail.php?id=' . (int)$licensePkg['id'])) ?>">单独购买授权包</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($openPkg): ?>
                <tr>
                    <td data-label="商品">开源版<br><span class="muted small">v<?= e($openPkg['version'] ?: '-') ?> · 授权模块不启用，安装后不能切换为正版</span></td>
                    <td data-label="价格">免费</td>
                    <td data-label="包大小"><?= e(number_format(((int)$openPkg['package_size']) / 1048576, 2)) ?> MB</td>
                    <td data-label="我的状态"><span class="badge badge-info">免费下载</span></td>
                    <td data-label="操作">
                        <?php if (YxStore::packageReady($openPkg)): ?>
                            <a class="btn btn-sm" href="<?= e(url('api/store_download.php?id=' . (int)$openPkg['id'])) ?>">下载开源版</a>
                        <?php else: ?>
                            <span class="muted small">发行文件待上传</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <tr>
                <td data-label="商品">发行包<br><span class="muted small">按项目定制分发，授权逻辑同正版</span></td>
                <td data-label="价格">不公开</td>
                <td data-label="包大小">-</td>
                <td data-label="我的状态"><span class="badge badge-mute">未开通</span></td>
                <td data-label="操作"><a class="btn btn-sm btn-ghost" href="<?= e(url('user/tickets.php')) ?>">联系客服开通</a></td>
            </tr>
            </tbody>
        </table></div>
        <?php if ($licenseEnt && (string)($licenseEnt['license_key'] ?? '') !== ''): ?>
            <div class="alert alert-ok mt-3" id="license-code">
                <strong>安装授权码（与绑定域名 <?= e($licenseEnt['domain']) ?> 一一对应）</strong>
                <p class="mono" style="font-size:18px;letter-spacing:1px;margin:8px 0"><?= e($licenseEnt['license_key']) ?></p>
                <p class="muted small" style="margin:0">安装时访问 <code>/install/install.php</code>，在「创始人信息与授权码」步骤填入该码；正版发行包强校验授权码，缺失或不匹配将无法完成安装。</p>
            </div>
            <?php
            $certRow = YxDb::one(
                'SELECT id, sn FROM ' . YxDb::table('certificate') . ' WHERE customer_id = ? AND domain = ? AND product = ? ORDER BY id DESC LIMIT 1',
                [$cid, (string)$licenseEnt['domain'], 'official']
            );
            ?>
            <?php if ($certRow): ?>
                <a class="btn btn-ghost btn-block mt-2" href="<?= e(url('user/certificate.php?id=' . (int)$certRow['id'])) ?>">查看正版购买证明（<?= e($certRow['sn']) ?> · 加盖电子公章）</a>
            <?php endif; ?>
            <p class="muted small mt-2">授权包有效期内可重复使用；更换绑定域名会重新签发授权码，旧码随即失效。</p>
        <?php elseif ($licensePkg): ?>
            <div class="alert alert-warn mt-3">
                未购买安装授权包时，正版发行包会以「未授权」状态运行；安装向导要求填写授权码时需先购买授权包（<?= e($pkgPriceTxt($licensePkg)) ?>，按域名签发，一次购买对应一个域名）。
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
        <div class="empty">还没有安装应用。</div>
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
