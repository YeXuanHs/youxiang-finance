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
$cover = trim((string)($app['cover'] ?? ''));
/* 资源文件是否真的在本机：只有目录/报价的条目不能给下载入口，也不能卖 */
$pkgReady = YxStore::packageReady($app);

/*
 * 官方程序包：程序包与授权包分开计数
 *  - 下载：程序包本体，一律免费（登录后直接下载）
 *  - 授权：按绑定域名签发的安装授权码，另行按次购买（本商品报价即授权包价格）
 * 已有授权（$ent）只代表「不必再买授权」，与能否下载无关。
 */
$isFreePackage = $isPackage && !$isResource && $bill === 'free';
$isOfficialPkg = $isPackage && !$isResource && !$isFreePackage;

/* 报价与开发者档案 */
$quotes    = YxStore::quotes($app);
$allFree   = YxStore::allFree($app);
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
                        <div class="yx-card-ico<?= $isResource ? ' is-3rd' : '' ?>"><?= e(mb_substr($app['name'], 0, 1)) ?></div>
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
                            <span style="color:#98a0ad">· v<?= e($r['version']) ?> · <?= e(YxBusiness::priceLabel($r)) ?></span>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== 右：购买 ===== -->
        <div>
            <div class="yx-panel">
                <?php if ($allFree): ?>
                    <div class="yx-buy-price is-free">免费</div>
                    <p class="yx-buy-sub">免费获取，按域名签发授权</p>
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
                    <?php
                    // 授权包价格自己拼单位，不用 priceLabel()：后者对一次性商品已经带「一次性」后缀，
                    // 再拼「/ 次」会出现「¥366.00 一次性 / 次」这种语义重复。
                    $licUnit = YxBusiness::billType($app) === 'year' ? '/年' : '/次';
                    ?>
                    <p class="yx-buy-note">程序包下载：<strong>免费</strong>（登录即可下载）· 授权包：<strong><?= e(money((float)$app['price'], true)) ?><?= e($licUnit) ?></strong>（按域名签发安装授权码，本页报价即授权包价格）</p>
                <?php endif; ?>

                <?php if ($isPackage && !$isResource): ?>
                    <?php /* 官方程序包：先免费下载程序包，再按需单独购买授权包 */ ?>
                    <?php if (!$me): ?>
                        <p class="yx-buy-tip">程序包免费下载，登录后即可获取；安装时如需授权码，可另行购买授权包。</p>
                        <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/login.php?back=' . urlencode('/store/detail.php?id=' . (int)$app['id']))) ?>">登录后免费下载</a>

                    <?php elseif (!$okEd): ?>
                        <p class="yx-buy-note is-warn">该应用需要「<?= e(YxEdition::name($need)) ?>」及以上版本</p>
                        <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('store/index.php')) ?>">看看其它应用</a>

                    <?php else: ?>
                        <?php if ($pkgReady): ?>
                            <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('api/store_download.php?id=' . (int)$app['id'])) ?>">免费下载程序包（tar.gz）</a>
                            <p class="yx-buy-tip"><?= e(YxStore::fileSize($app)) ?> · 解压到站点根目录后访问 <code>/install/install.php</code> 安装（本包已内置授权，安装无需授权码）</p>
                        <?php else: ?>
                            <p class="yx-buy-note is-warn">程序包文件暂未上传到本站，请联系平台客服获取。</p>
                        <?php endif; ?>

                        <?php if ($isOfficialPkg): ?>
                            <?php if ($ent): ?>
                                <p class="yx-buy-note is-ok">您已购买该程序包授权，有效期至 <?= e($ent['expire_at'] ?: '长期') ?>。</p>
                                <p class="yx-buy-tip"><a href="<?= e(url('user/licenses.php')) ?>">我的授权 →</a>（查看安装授权码）</p>
                            <?php elseif (!$paid || !YxEdition::can('store.buy')): ?>
                                <?php if ($paid): ?>
                                    <p class="yx-buy-note is-warn">购买授权包需要「专业版」及以上版本</p>
                                    <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('index.php#products')) ?>">了解版本差异</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('user/app_redeem.php')) ?>" class="yx-buy-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                                    <input type="hidden" name="edition" id="buyEdition" value="<?= e($defEd) ?>">
                                    <label>绑定域名（授权码按域名签发）
                                        <input type="text" name="domain" required placeholder="例如 www.example.com" value="<?= e(current_domain()) ?>">
                                    </label>
                                    <button class="yx-buy-btn is-ghost" type="submit">单独购买授权包 ¥<?= e(money($defPrice, false)) ?> / 次</button>
                                    <p class="yx-buy-tip">授权包按次收费：一次购买签发 1 个域名的安装授权码，与程序包下载互不影响。</p>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php elseif (!$me): ?>
                    <p class="yx-buy-tip">请先登录后再获取。</p>
                    <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/login.php?back=' . urlencode('/store/detail.php?id=' . (int)$app['id']))) ?>">登录后操作</a>

                <?php elseif (!$okEd): ?>
                    <p class="yx-buy-note is-warn">该应用需要「<?= e(YxEdition::name($need)) ?>」及以上版本</p>
                    <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('store/index.php')) ?>">看看其它应用</a>

                <?php elseif ($ent): ?>
                    <p class="yx-buy-note is-ok">您已<?= $isResource ? '获取' : '购买' ?>该<?= $isResource ? '资源' : '应用' ?>，有效期至 <?= e($ent['expire_at'] ?: '长期') ?>。</p>
                    <?php if ($isResource): ?>
                        <?php if ($pkgReady): ?>
                            <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('api/store_download.php?id=' . (int)$app['id'])) ?>">下载资源（zip）</a>
                        <?php else: ?>
                            <p class="yx-buy-note is-warn">该资源暂未托管安装包，本站无法提供下载；请通过下方原始地址向上游获取。</p>
                            <?php if (!empty($app['source'])): ?>
                                <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e($app['source']) ?>" target="_blank" rel="noopener nofollow">前往上游获取</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('user/licenses.php')) ?>">查看我的授权</a>
                    <?php endif; ?>

                <?php elseif ($isResource && !$pkgReady): ?>
                    <?php /* 只有目录与报价的第三方条目：没有可交付的文件，不能生成订单，否则等于卖空 */ ?>
                    <p class="yx-buy-note is-warn">该资源暂未托管安装包，本站暂不提供购买与下载。</p>
                    <?php if (!empty($app['source'])): ?>
                        <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e($app['source']) ?>" target="_blank" rel="noopener nofollow">前往上游获取</a>
                    <?php endif; ?>
                    <p class="yx-buy-tip">页面价格取自上游应用市场，仅供比价参考；待本站托管安装包后才会开放下单。</p>

                <?php elseif ($bill === 'free'): ?>
                    <?php if ($isResource): ?>
                        <a class="yx-buy-btn" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('api/store_download.php?id=' . (int)$app['id'])) ?>">免费下载资源（zip）</a>
                        <p class="yx-buy-tip"><?= e(YxStore::fileSize($app)) ?><?php if (!empty($app['source'])): ?> ·
                            <a href="<?= e($app['source']) ?>" target="_blank" rel="noopener">原始地址</a><?php endif; ?></p>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('user/purchase.php')) ?>" class="yx-buy-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="app">
                            <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                            <input type="hidden" name="edition" id="buyEdition" value="<?= e($defEd) ?>">
                            <label>绑定域名（授权按域名签发）
                                <input type="text" name="domain" required placeholder="例如 www.example.com" value="<?= e(current_domain()) ?>">
                            </label>
                            <button class="yx-buy-btn" type="submit">免费安装</button>
                        </form>
                    <?php endif; ?>

                <?php elseif ($paid && !YxEdition::can('store.buy')): ?>
                    <p class="yx-buy-note is-warn">购买付费应用需要「专业版」及以上版本</p>
                    <a class="yx-buy-btn is-ghost" style="text-align:center;line-height:46px;text-decoration:none" href="<?= e(url('index.php#products')) ?>">了解版本差异</a>

                <?php else: ?>
                    <?php
                    // 付款方式：余额 / 扫码 / 银联转账，后端在订单支付页再次校验，这里只做前置提示
                    $myBalance   = (float)$me['balance'];
                    $canTransfer = yx_offline_available();
                    $canScan     = YxPayment::configured();
                    $pointQuote  = null;
                    foreach ($quotes as $q) {
                        if ($q['bill_type'] === 'points') {
                            $pointQuote = $q;
                            break;
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('user/app_redeem.php')) ?>" class="yx-buy-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                        <input type="hidden" name="edition" id="buyEdition" value="<?= e($defEd) ?>">
                        <?php if (!$isResource): ?>
                            <label>绑定域名（授权按域名签发）
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

                        <?php if ($bill === 'points'): ?>
                            <p class="yx-buy-tip">将扣除 <?= (int)$app['points_price'] ?> 积分（当前 <?= (int)$me['points'] ?> 积分）</p>
                            <button class="yx-buy-btn" type="submit">积分兑换</button>
                        <?php else: ?>
                            <p class="yx-buy-tip">
                                账户余额 <?= e(money($myBalance, true)) ?>
                                <?php if ($myBalance < $defPrice): ?>
                                    · <a href="<?= e(url('user/recharge.php')) ?>">去充值</a>
                                <?php endif; ?>
                            </p>
                            <?php if ($myBalance < $defPrice): ?>
                                <p class="yx-buy-note is-warn">
                                    余额不足，下单后可在支付页改用<?= $canScan ? '扫码支付' : '' ?><?= ($canScan && $canTransfer) ? ' 或 ' : '' ?><?= $canTransfer ? '银联卡转账' : '' ?>。
                                </p>
                            <?php endif; ?>
                            <button class="yx-buy-btn" type="submit" id="buySubmit">
                                <?= ($defQuote && $defQuote['bill_type'] === 'year') ? '立即购买 ¥' . e(money($defPrice, false)) . ' / 年' : '立即购买 ¥' . e(money($defPrice, false)) ?>
                            </button>
                        <?php endif; ?>
                    </form>

                    <?php if (!empty($useChoice ?? true) && $canTransfer): ?>
                        <div class="yx-paytags">
                            <b>付款方式</b>
                            <span class="yx-paytag">余额支付</span>
                            <?php if ($canScan): ?><span class="yx-paytag">支付宝 / 微信扫码</span><?php endif; ?>
                            <span class="yx-paytag is-hot">银联卡转账</span>
                        </div>
                    <?php endif; ?>
                    <p class="yx-buy-tip"><?= $isResource ? '支付成功后即可下载该资源。' : '支付成功后自动签发授权码，可在「我的授权」管理。' ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php if (!$allFree && $multi): ?>
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

    function fmt(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function extra() {
        if (!specSel) return 0;
        var opt = specSel.options[specSel.selectedIndex];
        return parseFloat(opt.getAttribute('data-extra')) || 0;
    }

    function render() {
        var total = state.price + extra();
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
                + ' · 实付 ¥' + fmt(total);
        }
        if (submit) {
            submit.textContent = '立即购买 ¥' + fmt(total) + (state.bill === 'year' ? ' / 年' : '');
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
