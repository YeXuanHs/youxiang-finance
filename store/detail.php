<?php
/**
 * 优享财务系统 · 应用详情
 *
 * 版式参考 IDC 应用商店详情页：封面 + 标题 + 属性表、开发者卡片、版本报价切换。
 * 价格、计费方式、购买动作均与 user/app_redeem.php 的结算规则一一对应：
 * 页面展示的每个版本报价都来自 YxStore::quotes()，下单金额由 YxStore::priceOf() 复算，
 * 保证「展示价 = 扣款价」。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

$id  = (int)($_GET['id'] ?? 0);
$app = $id > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('app') . " WHERE id = ? AND status = 'on'", [$id]) : null;

if (!$app) {
    http_response_code(404);
    $pageTitle = '应用不存在';
    $pageFull  = true;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<link rel="stylesheet" href="' . e(url('assets/css/store.css')) . '">'
        . '<div class="yx-shop"><div class="yx-shop-wrap is-full">'
        . '<div class="yx-panel" style="margin-top:40px;text-align:center;padding:60px 24px">'
        . '<p style="color:#98a0ad;margin:0 0 18px">应用不存在或已下架</p>'
        . '<a class="btn btn-primary" href="' . e(url('store/index.php')) . '">返回应用中心</a>'
        . '</div></div></div>';
    require dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$cat   = YxDb::one('SELECT * FROM ' . YxDb::table('app_category') . ' WHERE key = ?', [$app['category_key']]);
$me    = YxAuth::customer();
$need  = $app['edition_min'];
$okEd  = YxEdition::rank() >= YxEdition::rank($need);
$myInstalls = $me
    ? YxDb::all('SELECT * FROM ' . YxDb::table('app_install') . " WHERE app_id = ? AND customer_id = ? AND domain <> 'resource'", [(int)$app['id'], (int)$me['id']])
    : [];
$related   = YxDb::all('SELECT * FROM ' . YxDb::table('app') . " WHERE category_key = ? AND id <> ? AND status = 'on' ORDER BY sales DESC LIMIT 3", [$app['category_key'], (int)$app['id']]);
$isResource = (string)($app['author_type'] ?? '') === 'thirdparty';
$isPackage  = (string)($app['type'] ?? '') === 'package';
$bill  = YxBusiness::billType($app);
$ent   = $me ? YxBusiness::entitlement((int)$app['id'], (int)$me['id']) : null;
/*
 * 授权按域名签发：同一客户可以为多个域名各买一份，已有权益只代表「该域名已买过」，
 * 不能据此隐藏购买表单，否则买过第一个域名后就再也无法给第二个域名下单。
 * 这里按域名整理有效权益，供表单提示与「已持有域名」清单使用。
 */
$entByDomain = [];
$entAny      = false;
foreach ($myInstalls as $mi) {
    if (YxPluginCenter::installState($mi)['key'] !== 'enabled') {
        continue;
    }
    $entAny = true;
    $entByDomain[(string)$mi['domain']] = $mi;
}
$cover = trim((string)($app['cover'] ?? ''));
/* 封面图地址存在但文件不在盘上时按无封面处理，避免详情页大图破图 */
if ($cover !== '' && !is_file(YX_ROOT . '/' . ltrim($cover, '/'))) {
    $cover = '';
}
/* 资源文件是否真的在本机：只有目录/报价的条目不能给下载入口，也不能卖 */
$pkgReady = YxStore::packageReady($app);

/*
 * 官方主程序包分三个独立 SKU（见 YxStore::packageKind）：
 *   program 正版程序包：88 元/次，购买（下载权益）后方可下载，不含安装授权码
 *   license 安装授权包：授权文件 + 按域名签发的安装授权码（本页只有该 SKU 要求填域名）
 *   open    开源版：登录即可免费下载
 * $ent 只代表「当前这个 SKU」的购买权益，不能跨 SKU 复用。
 */
$pkgKind       = ($isPackage && !$isResource) ? YxStore::packageKind($app) : '';
$pkgIsProgram  = $pkgKind === 'program';
$pkgIsLicense  = $pkgKind === 'license';
$pkgIsOpen     = $pkgKind === 'open';
$isOfficialPkg = $pkgKind !== '';
/* 同组其它 SKU：程序包页面需要指向授权包的购买入口 */
$sibPkg = [];
if ($isOfficialPkg) {
    $pkgRows = YxDb::all(
        'SELECT * FROM ' . YxDb::table('app') . " WHERE author_type = 'official' AND type = 'package' AND status = 'on' ORDER BY id DESC"
    );
    foreach ($pkgRows as $p) {
        $k = YxStore::packageKind($p);
        if ($k !== '' && !isset($sibPkg[$k])) {
            $sibPkg[$k] = $p;
        }
    }
}

/*
 * 授权包 SKU：授权包页即自身；程序包/开源版页取同组 license 条目，
 * 用于「单独购买授权包」入口的价格与下单 app_id（不能拿程序包自身的 price 当授权包价）。
 */
$licPkg   = $pkgIsLicense ? $app : ($sibPkg['license'] ?? null);
$licEnt   = ($me && $licPkg) ? YxBusiness::entitlement((int)$licPkg['id'], (int)$me['id']) : null;
$licQuote = $licPkg ? YxStore::quote($licPkg) : null;   // 与下单默认档一致
$licPrice = $licQuote ? (float)$licQuote['price'] : ($licPkg ? (float)$licPkg['price'] : 0.0);
$licEd    = $licQuote ? (string)$licQuote['edition'] : '';
$licUnit  = (($licQuote ? (string)$licQuote['bill_type'] : ($licPkg ? YxBusiness::billType($licPkg) : 'once')) === 'year') ? '/年' : '/次';

/* 报价与开发者档案 */
$quotes    = YxStore::quotes($app);
$allFree   = YxStore::allFree($app);
/* 官方程序包/开源版：只有开源版登录即可免费下载，不看条目残留的 price */
$pkgFree   = $isOfficialPkg ? YxStore::packageFree($app) : $allFree;
/* 正版程序包/授权包的下载权益：与 api/store_download.php 同一口径（权益或已支付订单） */
$pkgBought = ($me && $isOfficialPkg && !$pkgFree) ? YxStore::hasPurchased($app, (int)$me['id']) : false;
$multi     = count($quotes) > 1;
$currentEd = YxEdition::current();
$defQuote  = YxStore::quote($app);                 // 默认选中：当前授权版本，否则最低档
$defEd     = $defQuote ? (string)$defQuote['edition'] : '';
$developer = YxStore::developer($app);
$certify   = YxStore::certify($app);
$author    = YxStore::authorName($app);
$devUrl    = YxStore::developerUrl($app);
$devStats  = YxStore::developerStats($developer, $app);
$infoRows  = YxStore::infoRows($app, $cat);
$versions  = YxStore::versions((int)$app['id']);

/** 价格拆分展示 */
$appSpecs   = class_exists('YxServiceCenter') ? YxServiceCenter::appSpecs($app) : [];
$paid       = !YxStore::allFree($app);          // 是否存在付费报价（全部免费时为 false）
$defPrice   = $defQuote ? (float)$defQuote['price'] : 0.0;
$priceInt   = (int)floor($defPrice);
$priceDec   = str_pad((string)(int)round(($defPrice - $priceInt) * 100), 2, '0', STR_PAD_LEFT);

/** 版本标识样式：官方 / 企业 / 个人 */
$ownerClass = YxStore::certifyClass($app);

$pageTitle = $app['name'] . ' - 应用中心';
$pageFull  = true;
require dirname(__DIR__) . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= e(url('assets/css/store.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/store.css') ?>">

<div class="yx-shop">
<div class="yx-shop-wrap is-full">

    <p class="yx-crumb">
        <a href="<?= e(url('store/index.php')) ?>">应用中心</a> /
        <a href="<?= e(url('store/index.php?cat=' . urlencode((string)$app['category_key']))) ?>"><?= e($cat['name'] ?? '未分类') ?></a> /
        <?= e($app['name']) ?>
    </p>

    <div class="yx-detail-grid">

        <!-- ===== 左：应用信息 ===== -->
        <div>
            <div class="yx-panel yx-detail-hero">
                <div class="yx-detail-media">
                    <?php if ($cover !== ''): ?>
                        <img src="<?= e(url($cover)) ?>" alt="<?= e($app['name']) ?>">
                    <?php else: ?>
                        <?php $ico = YxStore::icon($app); ?>
                        <div class="yx-card-ico<?= $isResource ? ' is-3rd' : '' ?>" style="--ico-a:<?= e($ico['a']) ?>;--ico-b:<?= e($ico['b']) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $ico['svg'] ?></svg>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="yx-detail-head">
                    <div style="min-width:0">
                        <h1><?= e($app['name']) ?></h1>
                        <div class="yx-detail-meta">
                            <span>版本 v<?= e($app['version'] ?: '1.0.0') ?></span>
                            <span>作者 <?= e($author) ?></span>
                            <span><?= (int)$app['downloads'] ?> 次下载</span>
                            <span><?= (int)$app['sales'] ?> 次购买</span>
                        </div>
                    </div>
                    <span class="yx-owner <?= e($ownerClass) ?>" title="<?= e($certify['title']) ?>">
                        <i><?= e($certify['icon']) ?></i><?= e($certify['label']) ?>
                    </span>
                </div>

                <p class="yx-detail-desc"><?= e($app['description']) ?></p>

                <h2 style="margin-top:22px">商品信息</h2>
                <table class="yx-kv">
                    <?php foreach ($infoRows as [$k, $v]): ?>
                        <tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr>
                    <?php endforeach; ?>
                    <tr><th>所需版本</th><td>
                        <span class="badge <?= $okEd ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxEdition::name($need)) ?></span>
                        <?php if (!$okEd): ?>
                            <span style="color:#98a0ad;font-size:12.5px;margin-left:6px">当前站点为 <?= e(YxEdition::name()) ?>，暂不可用</span>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>安装授权</th><td><?= $isResource ? '第三方资源，仅供下载；本站不提供安装与授权' : '按绑定域名签发授权码，一个域名一份授权' ?></td></tr>
                </table>
            </div>

            <!-- 开发者卡片 -->
            <div class="yx-panel yx-dev-card">
                <h2>开发者信息</h2>
                <div class="yx-dev-head">
                    <div class="yx-dev-avatar"><?= e(mb_substr($author, 0, 1)) ?></div>
                    <div class="yx-dev-id">
                        <div class="yx-dev-name">
                            <?php if ($devUrl !== ''): ?>
                                <a href="<?= e($devUrl) ?>"><?= e($author) ?></a>
                            <?php else: ?>
                                <?= e($author) ?>
                            <?php endif; ?>
                            <span class="yx-owner <?= e($ownerClass) ?>" title="<?= e($certify['title']) ?>">
                                <i><?= e($certify['icon']) ?></i><?= e($certify['label']) ?>
                            </span>
                        </div>
                        <?php if ($developer && trim((string)($developer['intro'] ?? '')) !== ''): ?>
                            <p class="yx-dev-intro"><?= e($developer['intro']) ?></p>
                        <?php endif; ?>
                        <div class="yx-dev-stats">
                            <span>在售 <b><?= (int)$devStats['apps'] ?></b> 个商品</span>
                            <span>累计销量 <b><?= (int)$devStats['sales'] ?></b></span>
                        </div>
                    </div>
                </div>

                <?php
                $siteUrl = trim((string)($developer['website'] ?? ''));
                if ($siteUrl !== '' && !preg_match('#^https?://#i', $siteUrl)) {
                    $siteUrl = 'http://' . $siteUrl;
                }
                $mail  = trim((string)($developer['email'] ?? ''));
                $qq    = trim((string)($developer['qq'] ?? ''));
                $wechat = trim((string)($developer['wechat'] ?? ''));
                if (!$developer) {
                    // 官方自营：没有开发者档案时回落到站点配置的联系方式，不臆造资料
                    if ($siteUrl === '') {
                        $siteUrl = trim((string)setting('site_url', ''));
                    }
                    if ($qq === '') {
                        $qq = trim((string)setting('contact_qq', ''));
                    }
                    if ($mail === '') {
                        $mail = trim((string)setting('contact_email', ''));
                    }
                }
                ?>
                <table class="yx-kv yx-dev-contact">
                    <tr><th>作者网站</th><td>
                        <?php if ($siteUrl !== ''): ?>
                            <a href="<?= e($siteUrl) ?>" target="_blank" rel="noopener nofollow"><?= e(preg_replace('#^https?://#i', '', rtrim($siteUrl, '/'))) ?></a>
                        <?php else: ?><span class="yx-muted">未填写</span><?php endif; ?>
                    </td></tr>
                    <tr><th>电子邮箱</th><td>
                        <?php if ($mail !== ''): ?>
                            <a href="mailto:<?= e($mail) ?>"><?= e($mail) ?></a>
                        <?php else: ?><span class="yx-muted">未填写</span><?php endif; ?>
                    </td></tr>
                    <tr><th>咨询 QQ</th><td>
                        <?php if ($qq !== ''): ?>
                            <a href="https://wpa.qq.com/msgrd?v=3&amp;uin=<?= e(urlencode($qq)) ?>&amp;site=qq&amp;menu=yes" target="_blank" rel="noopener nofollow"><?= e($qq) ?></a>
                        <?php else: ?><span class="yx-muted">未填写</span><?php endif; ?>
                    </td></tr>
                    <?php if ($wechat !== ''): ?>
                        <tr><th>联系微信</th><td><?= e($wechat) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php if (!$developer): ?>
                    <p class="yx-buy-tip">该商品由本站官方应用中心直接提供，购买后由平台负责技术支持与售后。</p>
                <?php elseif ($devUrl !== ''): ?>
                    <p class="yx-buy-tip"><a href="<?= e($devUrl) ?>">查看该开发者的全部商品 →</a></p>
                <?php endif; ?>
            </div>

            <?php if ($versions): ?>
                <div class="yx-panel">
                    <h2>版本记录</h2>
                    <table class="yx-kv">
                        <?php foreach ($versions as $v): ?>
                            <tr>
                                <th class="yx-mono">v<?= e($v['version']) ?></th>
                                <td>
                                    <?= e($v['changelog'] ?: '（无更新说明）') ?>
                                    <span class="yx-muted" style="margin-left:8px"><?= e(date('Y-m-d', strtotime((string)$v['created_at']))) ?>
                                        <?= (int)$v['package_size'] > 0 ? ' · ' . e(YxStore::fileSize(['package_size' => $v['package_size']])) : '' ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($app['changelog']): ?>
                <div class="yx-panel">
                    <h2>更新日志</h2>
                    <p class="yx-detail-desc"><?= e($app['changelog']) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($myInstalls): ?>
                <div class="yx-panel">
                    <h2>我的安装</h2>
                    <table class="yx-kv">
                        <?php foreach ($myInstalls as $mi):
                            // 本区块只渲染当前登录客户自己的安装记录；仍按有效/失效区分，
                            // 失效记录给掩码，避免过期授权码被照抄后继续沿用。
                            $miValid = YxPluginCenter::installState($mi)['key'] === 'enabled';
                        ?>
                            <tr>
                                <th class="yx-mono"><?= e($mi['domain']) ?></th>
                                <td class="yx-mono"><?= e($miValid ? $mi['license_key'] : YxBusiness::maskLicenseKey((string)$mi['license_key'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <p style="margin:14px 0 0"><a class="btn btn-ghost btn-sm" href="<?= e(url('user/licenses.php')) ?>">管理授权 →</a></p>
                </div>
            <?php endif; ?>

            <?php if ($related): ?>
                <div class="yx-panel">
                    <h2>同类应用</h2>
                    <?php foreach ($related as $r): ?>
                        <p style="margin:10px 0;font-size:13.5px">
                            <a href="<?= e(url('store/detail.php?id=' . (int)$r['id'])) ?>"><?= e($r['name']) ?></a>
                            <span style="color:#98a0ad">· v<?= e($r['version']) ?> · <?= e(YxStore::priceText($r)) ?></span>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== 右：购买 ===== -->
        <div>
            <div class="yx-panel">
                <?php if ($pkgFree): ?>
                    <div class="yx-buy-price is-free">免费</div>
                    <p class="yx-buy-sub">
                        <?php if ($pkgIsOpen): ?>开源版免费下载，授权模块不启用
                        <?php else: ?>免费获取，按域名签发授权<?php endif; ?>
                    </p>
                <?php else: ?>
                    <div class="yx-buy-price" id="buyPrice">
                        <small>¥</small><span id="buyPriceInt"><?= $priceInt ?></span><span class="dec" style="font-size:18px">.<span id="buyPriceDec"><?= $priceDec ?></span></span><?php if ($defQuote && $defQuote['bill_type'] === 'year'): ?><em id="buyPriceUnit">/年</em><?php endif; ?>
                    </div>
                    <p class="yx-buy-sub" id="buyPriceSub">
                        计费方式：<?= e($defQuote ? $defQuote['bill_name'] : YxBusiness::billName($bill)) ?><?= ($defQuote && $defQuote['bill_type'] === 'year') ? '（授权 1 年）' : '' ?> · <?= (int)$app['sales'] ?> 人已购买
                    </p>

                    <?php if ($multi): ?>
                        <div class="yx-buy-editions" role="radiogroup" aria-label="授权版本">
                            <?php foreach ($quotes as $q):
                                $isCur  = $q['edition'] === $currentEd;
                                $isDef  = $q['edition'] === $defEd;
                                $tagTxt = $isCur ? '当前站点版本' : '';
                            ?>
                                <button type="button"
                                        class="yx-buy-edition<?= $isDef ? ' is-active' : '' ?>"
                                        data-edition="<?= e($q['edition']) ?>"
                                        data-price="<?= e(money($q['price'], false)) ?>"
                                        data-bill="<?= e($q['bill_type']) ?>"
                                        role="radio"
                                        aria-checked="<?= $isDef ? 'true' : 'false' ?>">
                                    <span class="yx-buy-edition-name"><?= e($q['label']) ?></span>
                                    <span class="yx-buy-edition-price">
                                        <?php if ($q['bill_type'] === 'free'): ?>免费
                                        <?php else: ?>¥<?= e(money($q['price'], false)) ?><?= $q['bill_type'] === 'year' ? '<i>/年</i>' : '' ?><?php endif; ?>
                                    </span>
                                    <?php if ($tagTxt !== ''): ?><span class="yx-buy-edition-tag"><?= e($tagTxt) ?></span><?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="yx-buy-tip">价格随所购授权版本变化：普惠版 / 专业版 / 企业版，切换后即为实际支付金额。</p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($isResource): ?>
                    <p class="yx-buy-note is-warn">第三方资源 · 仅供下载，未随优享财务系统提供安装与授权，使用前请自行评估代码安全。</p>
                <?php endif; ?>

                <?php if ($isOfficialPkg): ?>
                    <?php if ($pkgIsProgram): ?>
                        <p class="yx-buy-note">正版程序包 <strong><?= e(money($defPrice, true)) ?>次</strong>：购买下载权益后即可下载，含程序本体与官方授权模块，<strong>不含安装授权码</strong>；安装时需要的授权码属「安装授权包」<?= $licPkg ? '（<strong>' . e(money($licPrice, true)) . e($licUnit) . '</strong>）' : '' ?>，需另行购买。</p>
                    <?php elseif ($pkgIsLicense): ?>
                        <p class="yx-buy-note">安装授权包 <strong><?= e(money((float)$app['price'], true)) ?><?= e($licUnit) ?></strong>：授权文件 + 按域名签发的安装授权码，一次购买对应一个域名。</p>
                    <?php elseif ($pkgIsOpen): ?>
                        <p class="yx-buy-note">开源版：登录后免费下载，授权模块不启用；安装后不能切换为正版。</p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($isPackage && !$isResource): ?>
                    <?php /* 正版程序包/安装授权包：购买后下载；开源版：登录后免费下载；三页都给出授权包购买入口 */ ?>
                    <?php if (!$me): ?>
                        <?php if ($pkgIsProgram): ?>
                            <p class="yx-buy-tip">正版程序包 <?= e(money($defPrice, true)) ?>/次，购买后即可在本页下载；安装授权码需另购授权包。</p>
                            <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/login.php?back=' . urlencode('/store/detail.php?id=' . (int)$app['id']))) ?>">登录后购买</a>
                        <?php elseif ($pkgFree): ?>
                            <p class="yx-buy-tip"><?= $pkgIsOpen ? '开源版免费下载，登录后即可获取；授权模块不启用。' : '程序包免费下载，登录后即可获取。' ?></p>
                            <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/login.php?back=' . urlencode('/store/detail.php?id=' . (int)$app['id']))) ?>">登录后免费下载</a>
                        <?php else: ?>
                            <p class="yx-buy-tip">授权包按域名签发安装授权码，请先登录后购买。</p>
                            <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/login.php?back=' . urlencode('/store/detail.php?id=' . (int)$app['id']))) ?>">登录后购买</a>
                        <?php endif; ?>

                    <?php elseif (!$okEd): ?>
                        <p class="yx-buy-note is-warn">该应用需要「<?= e(YxEdition::name($need)) ?>」及以上版本</p>
                        <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('store/index.php')) ?>">看看其它应用</a>

                    <?php else: ?>
                        <?php if ($pkgIsProgram): ?>
                            <?php if ($pkgBought): ?>
                                <p class="yx-buy-note is-ok">您已购买正版程序包，可直接下载。</p>
                                <?php if ($pkgReady): ?>
                                    <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('api/store_download.php?id=' . (int)$app['id'])) ?>">下载正版程序包</a>
                                    <p class="yx-buy-tip"><?= e(YxStore::fileSize($app)) ?> · 明文 zip 发行包（<code>.zip</code>，无解压密码）；解压到站点根目录，访问 <code>/install/install.php</code> 安装；安装授权码需另购授权包，购买后按域名自动签发。</p>
                                <?php else: ?>
                                    <p class="yx-buy-note is-warn">程序包文件暂未上传到本站，请联系平台客服获取。</p>
                                <?php endif; ?>
                            <?php elseif (!$paid || !YxEdition::can('store.buy')): ?>
                                <p class="yx-buy-note is-warn">购买程序包需要「<?= e(YxEdition::name(YxEdition::FEATURES['store.buy'])) ?>」及以上版本</p>
                                <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('index.php#products')) ?>">了解版本差异</a>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('user/purchase.php')) ?>" class="yx-buy-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="kind" value="app">
                                    <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                                    <input type="hidden" name="edition" id="buyEdition" value="<?= e($defEd) ?>">
                                    <button class="yx-buy-btn" type="submit" id="buySubmit">购买正版程序包 <?= e(money($defPrice, true)) ?></button>
                                    <p class="yx-buy-tip">正版程序包按次收费：支付成功后即可在本页与 <a href="<?= e(url('user/licenses.php')) ?>">我的授权</a> 下载，安装授权码需另购授权包。</p>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($pkgFree): ?>
                            <?php if ($pkgReady): ?>
                                <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('api/store_download.php?id=' . (int)$app['id'])) ?>"><?= $pkgIsOpen ? '免费下载开源版' : '免费下载程序包' ?></a>
                                <p class="yx-buy-tip"><?= e(YxStore::fileSize($app)) ?> · 明文 zip 发行包（<code>.zip</code>，无解压密码）；解压到站点根目录，访问 <code>/install/install.php</code> 安装<?= $pkgIsOpen ? '（开源版授权模块不启用）' : '（不装授权包时以「未授权」状态运行）' ?></p>
                            <?php else: ?>
                                <p class="yx-buy-note is-warn">程序包文件暂未上传到本站，请联系平台客服获取。</p>
                            <?php endif; ?>
                        <?php elseif ($licEnt): ?>
                            <p class="yx-buy-note is-ok">您已购买安装授权包，有效期至 <?= e($licEnt['expire_at'] ?: '长期') ?>。</p>
                            <?php if ($pkgReady): ?>
                                <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('api/store_download.php?id=' . (int)$app['id'])) ?>">下载安装授权包</a>
                                <p class="yx-buy-tip"><?= e(YxStore::fileSize($app)) ?> · 整包密文（<code>.zip.enc</code>），解密密钥随购买凭证由本站客服发放；解包后覆盖到站点根目录，访问 <code>/install/install.php</code> 安装并填写授权码。</p>
                            <?php else: ?>
                                <p class="yx-buy-note is-warn">授权包文件暂未上传到本站，请联系平台客服获取。</p>
                            <?php endif; ?>
                            <p class="yx-buy-tip"><a href="<?= e(url('user/licenses.php')) ?>">我的授权 →</a>（查看安装授权码）</p>
                        <?php endif; ?>

                        <?php /*
                         * 授权包购买入口：金额与 app_id 一律取授权包 SKU（程序包页不能拿自身 price 当授权包价）。
                         * 已持有某一域名时仍保留表单，否则给第二个域名下单时会被前端挡住。
                         */ ?>
                        <?php if ($licPkg): ?>
                            <?php if ($licEnt): ?>
                                <p class="yx-buy-tip">已为 <b><?= e((string)$licEnt['domain']) ?></b> 持有授权包，仍可为其它域名单独购买（一次购买对应一个域名）。</p>
                            <?php endif; ?>
                            <?php if (!$paid || !YxEdition::can('store.buy')): ?>
                                <?php if ($paid): ?>
                                    <p class="yx-buy-note is-warn">购买授权包需要「<?= e(YxEdition::name(YxEdition::FEATURES['store.buy'])) ?>」及以上版本</p>
                                    <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('index.php#products')) ?>">了解版本差异</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('user/purchase.php')) ?>" class="yx-buy-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="kind" value="app">
                                    <input type="hidden" name="app_id" value="<?= (int)$licPkg['id'] ?>">
                                    <input type="hidden" name="edition" value="<?= e($licEd) ?>">
                                    <label>绑定域名（授权码按域名签发）
                                        <input type="text" name="domain" required placeholder="例如 www.example.com" value="<?= e(current_domain()) ?>">
                                    </label>
                                    <button class="yx-buy-btn is-ghost" type="submit">单独购买授权包 <?= e(money($licPrice, true)) ?><?= e($licUnit) ?></button>
                                    <p class="yx-buy-tip">授权包按次收费：一次购买签发 1 个域名的安装授权码，与程序包下载互不影响。</p>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php elseif (!$me): ?>
                    <?php if ($paid): ?>
                        <p class="yx-buy-tip"><?= e(money($defPrice, true)) ?> 一次性购买，购买后在本页下载程序包。</p>
                        <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/login.php?back=' . urlencode('/store/detail.php?id=' . (int)$app['id']))) ?>">登录后购买</a>
                    <?php else: ?>
                        <p class="yx-buy-tip">请先登录后再获取。</p>
                        <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/login.php?back=' . urlencode('/store/detail.php?id=' . (int)$app['id']))) ?>">登录后操作</a>
                    <?php endif; ?>

                <?php elseif (!$okEd): ?>
                    <p class="yx-buy-note is-warn">该应用需要「<?= e(YxEdition::name($need)) ?>」及以上版本</p>
                    <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('store/index.php')) ?>">看看其它应用</a>

                <?php elseif ($ent && $isResource): ?>
                    <p class="yx-buy-note is-ok">您已获取该资源，有效期至 <?= e($ent['expire_at'] ?: '长期') ?>。</p>
                    <?php if ($pkgReady): ?>
                        <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('api/store_download.php?id=' . (int)$app['id'])) ?>">下载资源（zip）</a>
                    <?php else: ?>
                        <p class="yx-buy-note is-warn">该资源暂未托管安装包，本站无法提供下载；请通过下方原始地址向上游获取。</p>
                        <?php if (!empty($app['source'])): ?>
                            <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e($app['source']) ?>" target="_blank" rel="noopener nofollow">前往上游获取</a>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php elseif ($isResource && !$pkgReady): ?>
                    <?php /* 只有目录与报价的第三方条目：没有可交付的文件，不能生成订单，否则等于卖空 */ ?>
                    <p class="yx-buy-note is-warn">该资源暂未托管安装包，本站暂不提供购买与下载。</p>
                    <?php if (!empty($app['source'])): ?>
                        <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e($app['source']) ?>" target="_blank" rel="noopener nofollow">前往上游获取</a>
                    <?php endif; ?>
                    <p class="yx-buy-tip">页面价格取自上游应用市场，仅供比价参考；待本站托管安装包后才会开放下单。</p>

                <?php else: ?>
                    <?php
                    /*
                     * 应用购买区（含免费应用、已购部分域名、需要升档三种情况）
                     *
                     * 统一保留表单：
                     *  - 已持有域名 → 表单上方列出域名、授权版本与到期时间，表单里再次填写同档域名会被后端拦下；
                     *  - 更高档位 → 同一域名允许按升级下单（后端按档位比较放行）；
                     *  - 报价里同时存在免费与付费档位时，按钮与金额随所选档位切换，
                     *    不能再按商品基础 bill_type 一刀切成「免费安装」。
                     */
                    $myBalance   = (float)$me['balance'];
                    $canTransfer = yx_offline_available();
                    $canScan     = YxPayment::configured();
                    $pointQuote  = YxStore::pointsQuote($app);   // 报价里的积分档（没有则 null）
                    $hasFreeQ    = false;
                    foreach ($quotes as $q) {
                        if ($q['bill_type'] === 'free') {
                            $hasFreeQ = true;
                            break;
                        }
                    }
                    $curEdRank   = 0;
                    ?>

                    <?php if ($entByDomain): ?>
                        <p class="yx-buy-note is-ok">您已为该应用的 <?= count($entByDomain) ?> 个域名开通授权；仍可为其它域名单独购买，或为已有域名升级到更高档位。</p>
                        <table class="yx-kv is-ent" style="margin-bottom:14px">
                            <tr><th>已开通域名</th><th>授权版本</th><th>到期</th></tr>
                            <?php foreach ($entByDomain as $d => $mi):
                                $miEd = (string)($mi['edition'] ?? 'basic');
                            ?>
                                <tr>
                                    <td class="yx-mono"><?= e($d) ?></td>
                                    <td><?= e(YxEdition::name($miEd)) ?></td>
                                    <td><?= e(($mi['expire_at'] ?? '') ?: '长期') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        <p class="yx-buy-tip"><a href="<?= e(url('user/licenses.php')) ?>">在「我的授权」查看授权码与管理 →</a></p>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('user/purchase.php')) ?>" class="yx-buy-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="kind" value="app">
                        <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                        <input type="hidden" name="edition" id="buyEdition" value="<?= e($defEd) ?>">
                        <?php if (!$isResource): ?>
                            <label>绑定域名（授权按域名签发，一个域名一份授权）
                                <input type="text" name="domain" required placeholder="例如 www.example.com" value="<?= e(current_domain()) ?>">
                            </label>
                        <?php endif; ?>

                        <?php if ($appSpecs): ?>
                            <label>应用设置
                                <select name="spec" id="appSpec" data-base="<?= e(money($defPrice, false)) ?>">
                                    <option value="" data-extra="0.00">默认（不加装）</option>
                                    <?php foreach ($appSpecs as $sp): ?>
                                        <option value="<?= e($sp['name']) ?>" data-extra="<?= e(money(round_money((float)$sp['price']), false)) ?>">
                                            <?= e($sp['name']) ?><?= (float)$sp['price'] > 0 ? '（+¥' . e(money(round_money((float)$sp['price']), false)) . '）' : '（免费）' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <p class="yx-buy-tip" id="appSpecTip">默认不含附加设置，可在此基础上加装。</p>
                        <?php endif; ?>

                        <div id="buyPointsBox" style="<?= ($defQuote && $defQuote['bill_type'] === 'points') ? '' : 'display:none' ?>">
                            <p class="yx-buy-tip">将扣除 <?= (int)$app['points_price'] ?> 积分（当前 <?= (int)$me['points'] ?> 积分）</p>
                        </div>

                        <div id="buyPaidBox" style="<?= ($defQuote && $defQuote['bill_type'] === 'points') ? 'display:none' : '' ?>">
                            <?php if ($hasFreeQ): ?>
                                <p class="yx-buy-tip">所选档位若为免费，提交后直接签发授权，无需支付。</p>
                            <?php endif; ?>
                            <?php if ($paid): ?>
                                <p class="yx-buy-tip">
                                    账户余额 <?= e(money($myBalance, true)) ?>
                                    <?php if ($myBalance < $defPrice): ?>
                                        · <a href="<?= e(url('user/recharge.php')) ?>">去充值</a>
                                    <?php endif; ?>
                                </p>
                                <?php if ($myBalance < $defPrice): ?>
                                    <p class="yx-buy-note is-warn" id="buyBalanceWarn">
                                        余额不足，下单后可在支付页改用<?= $canScan ? '扫码支付' : '' ?><?= ($canScan && $canTransfer) ? ' 或 ' : '' ?><?= $canTransfer ? '银联卡转账' : '' ?>。
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <button class="yx-buy-btn" type="submit" id="buySubmit">
                            <?php
                            if ($defQuote && $defQuote['bill_type'] === 'points') {
                                echo '积分兑换';
                            } elseif ($defQuote && $defQuote['bill_type'] === 'free') {
                                echo '免费安装';
                            } elseif ($defQuote && $defQuote['bill_type'] === 'year') {
                                echo '立即购买 ¥' . e(money($defPrice, false)) . ' / 年';
                            } else {
                                echo '立即购买 ¥' . e(money($defPrice, false));
                            }
                            ?>
                        </button>
                    </form>

                    <?php if ($paid && $canTransfer): ?>
                        <div class="yx-paytags">
                            <b>付款方式</b>
                            <span class="yx-paytag">余额支付</span>
                            <?php if ($canScan): ?><span class="yx-paytag">支付宝 / 微信扫码</span><?php endif; ?>
                            <span class="yx-paytag is-hot">银联卡转账</span>
                        </div>
                    <?php endif; ?>
                    <p class="yx-buy-tip"><?= $isResource ? '支付成功后即可下载该资源。' : '免费档位直接签发授权；付费档位支付成功后自动签发授权码，可在「我的授权」管理。' ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php if (!$pkgFree && $multi): ?>
<script>
(function () {
    var btns    = Array.prototype.slice.call(document.querySelectorAll('.yx-buy-edition'));
    var edInput = document.getElementById('buyEdition');
    var elInt   = document.getElementById('buyPriceInt');
    var elDec   = document.getElementById('buyPriceDec');
    var elUnit  = document.getElementById('buyPriceUnit');
    var elSub   = document.getElementById('buyPriceSub');
    var submit  = document.getElementById('buySubmit');
    var specSel = document.getElementById('appSpec');
    var specTip = document.getElementById('appSpecTip');
    if (!btns.length) return;

    var billNames = {free: '免费', once: '一次性', year: '按年', points: '积分兑换'};
    // 初始档位必须与后端默认选中项一致，否则首屏渲染会把服务端价格改回最低档
    var activeBtn = document.querySelector('.yx-buy-edition.is-active') || btns[0];
    var state = {price: parseFloat(activeBtn.getAttribute('data-price')) || 0,
                 bill: activeBtn.getAttribute('data-bill') || 'once'};
    var pointsBox = document.getElementById('buyPointsBox');
    var paidBox   = document.getElementById('buyPaidBox');
    var paytags   = document.querySelector('.yx-paytags');

    function fmt(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function extra() {
        if (!specSel) return 0;
        var opt = specSel.options[specSel.selectedIndex];
        return parseFloat(opt.getAttribute('data-extra')) || 0;
    }

    function render() {
        var total = state.price + extra();
        var isPoints = state.bill === 'points';
        var isFree   = state.bill === 'free';
        if (elInt) elInt.textContent = String(Math.floor(total));
        if (elDec) elDec.textContent = (total - Math.floor(total)).toFixed(2).slice(2);
        if (elUnit) {
            if (state.bill === 'year') { elUnit.textContent = '/年'; elUnit.style.display = ''; }
            else { elUnit.style.display = 'none'; }
        }
        if (elSub) {
            var name = billNames[state.bill] || state.bill;
            elSub.textContent = '计费方式：' + name
                + (state.bill === 'year' ? '（授权 1 年）' : (state.bill === 'once' ? '（长期授权）' : ''))
                + (isFree || isPoints ? '' : ' · 实付 ¥' + fmt(total));
        }
        // 积分档与付费档互斥：选积分时隐藏余额与付款方式，选免费时按钮换成「免费安装」
        if (pointsBox) pointsBox.style.display = isPoints ? '' : 'none';
        if (paidBox)   paidBox.style.display   = isPoints ? 'none' : '';
        if (paytags)   paytags.style.display   = (isPoints || isFree) ? 'none' : '';
        if (submit) {
            submit.textContent = isPoints ? '积分兑换'
                : (isFree ? '免费安装'
                    : '立即购买 ¥' + fmt(total) + (state.bill === 'year' ? ' / 年' : ''));
        }
        if (specSel && specTip) {
            var ex = extra();
            specTip.textContent = ex > 0
                ? ('已选「' + specSel.value + '」，合计 ¥' + fmt(total))
                : '默认不含附加设置，可在此基础上加装。';
        }
    }

    btns.forEach(function (b) {
        b.addEventListener('click', function () {
            btns.forEach(function (x) {
                x.classList.remove('is-active');
                x.setAttribute('aria-checked', 'false');
            });
            b.classList.add('is-active');
            b.setAttribute('aria-checked', 'true');
            state.price = parseFloat(b.getAttribute('data-price')) || 0;
            state.bill  = b.getAttribute('data-bill') || 'once';
            if (edInput) edInput.value = b.getAttribute('data-edition');
            if (specSel) specSel.setAttribute('data-base', String(state.price));
            render();
        });
    });
    if (specSel) specSel.addEventListener('change', render);
    render();
})();
</script>
<?php elseif ($appSpecs): ?>
<script>
(function () {
    var sel = document.getElementById('appSpec');
    var tip = document.getElementById('appSpecTip');
    var btn = document.getElementById('buySubmit');
    if (!sel) return;
    var base = parseFloat(sel.getAttribute('data-base')) || 0;
    var year = <?= $bill === 'year' ? 'true' : 'false' ?>;
    function fmt(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function update() {
        var opt = sel.options[sel.selectedIndex];
        var extra = parseFloat(opt.getAttribute('data-extra')) || 0;
        var total = base + extra;
        if (btn) btn.textContent = '立即购买 ¥' + fmt(total) + (year ? ' / 年' : '');
        if (tip) tip.textContent = extra > 0
            ? ('已选「' + opt.value + '」，合计 ¥' + fmt(total))
            : '默认不含附加设置，可在此基础上加装。';
    }
    sel.addEventListener('change', update);
    update();
})();
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
