<?php
/**
 * 优享财务系统 · 门户首页
 *
 * 版式：企业风格（浅色导航 / 大幅首屏 / 快捷入口 / 产品页签 / 企业能力 / 公告资讯 /
 *       生态伙伴 / 行动号召），样式统一 lc- 前缀，定义在 assets/css/home.css。
 * 内容：全部取自后台真实数据（横幅、产品、应用、新闻、合作伙伴 Logo），无写死文案。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

/* ------------------------------ 域名授权查询 ------------------------------ */
$checkResult  = null;
$checkDomain  = '';
$checkError   = '';
// 只接受合规域名：避免把任意输入直接回显到页面
$isDomainInput = static function (string $d): bool {
    return strlen($d) <= 100 && (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $d);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_domain') {
    csrf_check();
    // 浏览器会同步 URL 查询串：提交后刷新仍可看到本次结果
    redirect(url('index.php?' . http_build_query([
        'domain' => trim((string)($_POST['domain'] ?? '')),
        'check'  => '1',
    ]) . '#license'));
}
if (($_GET['check'] ?? '') === '1' && isset($_GET['domain'])) {
    $checkDomain = normalize_domain((string)$_GET['domain']);
    if ($checkDomain === '') {
        $checkError = '请输入要查询的域名';
    } elseif (!$isDomainInput($checkDomain)) {
        $checkError  = '域名格式不正确，请输入例如 www.example.com';
        $checkDomain = '';
    } else {
        $checkResult = YxLicense::query($checkDomain, true);
        YxLicense::log($checkDomain, $checkResult['status'], $checkResult['source'], '门户自助查询');
    }
}

$me    = YxAuth::customer();
$badge = YxLicense::badge();

/* ------------------------------ 官方程序包 ------------------------------ */
$allPkgs = YxDb::all(
    "SELECT * FROM " . YxDb::table('app') . " WHERE author_type = 'official' AND type = 'package' AND status = 'on' ORDER BY id DESC"
);
// 主入口仍取正式版包；开源版包单独列出（同一份代码，授权模块默认关闭）
$pkgRow  = null;
$openPkg = null;
foreach ($allPkgs as $p) {
    $isOpen = stripos((string)$p['key'] . (string)$p['name'], 'open') !== false || mb_strpos((string)$p['name'], '开源版') !== false;
    if ($isOpen) {
        $openPkg = $openPkg ?: $p;
    } else {
        $pkgRow = $pkgRow ?: $p;
    }
}
$pkgPaid  = $pkgRow ? YxBusiness::billType($pkgRow) !== 'free' : false;
$pkgEnt   = $pkgRow ? YxBusiness::entitlement((int)$pkgRow['id'], (int)($me['id'] ?? 0)) : null;
// 程序包本体免费下载（下载接口只需登录）；$pkgPaid 仅代表「授权包」是否收费
$pkgReady = (bool)$pkgRow;

/* ------------------------------ 产品：按分组（页签） ------------------------------ */
// 页签直接取后台「产品分组」，与 products.php 的口径一致；
// 无分组 / 分组已下架的产品归入「其他产品」，保证前台不漏产品。
// 库存为 0 视为售罄，仍可展示（与 products.php 一致的不展示规则仅用于列表页）。
$groupRows = YxDb::all('SELECT * FROM ' . YxDb::table('product_group') . ' WHERE status = 1 ORDER BY sort ASC, id ASC');
$groupMap  = [];
foreach ($groupRows as $g) {
    $groupMap[(int)$g['id']] = (string)$g['name'];
}
$allProducts = YxDb::all('SELECT * FROM ' . YxDb::table('product') . ' WHERE status = 1 ORDER BY sort ASC, id ASC');
$byGroup = [];
foreach ($allProducts as $p) {
    $gid = (int)($p['group_id'] ?? 0);
    $byGroup[isset($groupMap[$gid]) ? $gid : 0][] = $p;
}
// 页签顺序：先按后台分组顺序，再补「其他产品」
$tabs = [];
foreach ($groupMap as $gid => $gname) {
    if (!empty($byGroup[$gid])) {
        $tabs[$gid] = $byGroup[$gid];
    }
}
if (!empty($byGroup[0])) {
    $tabs[0] = $byGroup[0];
}
// 页签副标题：按分组名兜底，避免出现空文案
$tabSubs = [
    '八彩云计算' => '计算、存储与网络按需组合，适合建站与业务系统部署',
    '本地模拟上游（联调用）' => '联调环境商品，用于验证下单与开通流程，正式运营前请下架',
];

$featuredApps = YxDb::all(
    "SELECT * FROM " . YxDb::table('app') . " WHERE status = 'on' AND featured = 1 AND type <> 'package' ORDER BY sales DESC LIMIT 6"
);

/* ------------------------------ 首页统计（真实数据） ------------------------------ */
$statProducts = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('product') . ' WHERE status = 1');

/** 产品描述压缩为单行短文案：上游同步的说明是多行，直接截断会出现半句 */
$lcBrief = static function ($text, int $len, string $fallback = ''): string {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if ($s === '') {
        return $fallback;
    }
    // 上游同步的说明以「来源：…。开通方式：…。上游未标注计费周期…。」这类运营口径开头，
    // 对访客无意义，逐句剔除后取剩下第一句；全部剔除时退回原文。
    $parts = preg_split('/(?<=[。；;！!])\s*/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $keep  = [];
    foreach ($parts as $seg) {
        if (!preg_match('/^(?:来源|开通方式|计费|库存|上游)/u', $seg)) {
            $keep[] = $seg;
        }
    }
    $s = trim(implode('', $keep)) !== '' ? trim(implode('', $keep)) : $s;
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
};
$statApps     = (int)YxDb::value("SELECT COUNT(*) FROM " . YxDb::table('app') . " WHERE status = 'on'");
$statNodes    = 0;
try {
    $statNodes = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('node') . ' WHERE status = 1');
} catch (Throwable $e) {
    $statNodes = 0;
}

/* ------------------------------ 公告与资讯 ------------------------------ */
$newsList = [];
if (YxNews::isEnabled()) {
    try {
        $newsList = array_slice(YxNews::published(10), 0, 6);
    } catch (Throwable $e) {
        $newsList = [];
    }
}

/* ------------------------------ 生态伙伴 Logo ------------------------------ */
$partnerLogos = yx_wall_enabled() ? array_slice(yx_wall_logos(), 0, 8) : [];

/* ------------------------------ 联系方式 ------------------------------ */
$homePhone = trim((string)setting('about_contact_phone', ''));
if ($homePhone === '') { $homePhone = trim((string)setting('contact_phone', '')); }
$homeEmail = trim((string)setting('about_contact_email', ''));
if ($homeEmail === '') { $homeEmail = trim((string)setting('contact_email', '')); }

$siteName  = setting('site_name', config('app.name'));
$siteSub   = setting('site_sub', '');
$pageTitle = $siteName . ($siteSub !== '' ? ' - ' . $siteSub : '');
$pageFull  = true;
$pageHome  = true;
require __DIR__ . '/includes/header.php';
?>

<div class="lc-home">

<!-- ==================== 首屏 ==================== -->
<section class="lc-hero">
    <div class="lc-container lc-hero-inner">
        <div class="lc-hero-copy">
            <span class="lc-hero-label"><?= e($siteSub !== '' ? $siteSub : '业务与财务一体化管理平台') ?></span>
            <h1>让业务管理更简单<br>让财务核算更从容</h1>
            <p>产品、订单、账单与资金流水在一个后台里打通，从客户下单、业务开通到收款对账全程留痕，帮助企业把业务与财务用一套清晰的方式管理起来。</p>
            <div class="lc-hero-actions">
                <a class="lc-button lc-button-dark" href="<?= e(url('products.php')) ?>">了解产品与服务</a>
                <?php if ($me): ?>
                    <a class="lc-button lc-button-outline" href="<?= e(url('user/index.php')) ?>">进入用户中心</a>
                <?php else: ?>
                    <a class="lc-button lc-button-outline" href="<?= e(url('user/register.php')) ?>">免费注册体验</a>
                <?php endif; ?>
            </div>
            <ul class="lc-hero-points">
                <li>业务与财务一套系统</li>
                <li>全流程资金留痕</li>
                <li><?= e(YxEdition::name()) ?>已启用</li>
            </ul>
        </div>

        <div class="lc-hero-art" aria-hidden="true">
            <div class="lc-art-card">
                <div class="lc-art-head">
                    <span class="lc-art-dot"></span><span class="lc-art-dot"></span><span class="lc-art-dot"></span>
                    <em>经营概览</em>
                </div>
                <div class="lc-art-kpi">
                    <div><small>在售产品</small><strong><?= (int)$statProducts ?></strong></div>
                    <div><small>可装应用</small><strong><?= (int)$statApps ?></strong></div>
                    <div><small>资源节点</small><strong><?= (int)$statNodes ?></strong></div>
                </div>
                <div class="lc-art-chart">
                    <i style="--h:38%"></i><i style="--h:62%"></i><i style="--h:48%"></i>
                    <i style="--h:78%"></i><i style="--h:58%"></i><i style="--h:88%"></i><i style="--h:70%"></i>
                </div>
                <div class="lc-art-list">
                    <span><b>订单</b>下单 · 支付 · 退款</span>
                    <span><b>账单</b>自动生成 · 结清</span>
                    <span><b>流水</b>逐笔记录 · 可追溯</span>
                </div>
            </div>
            <span class="lc-art-orb lc-art-orb-a"></span>
            <span class="lc-art-orb lc-art-orb-b"></span>
            <span class="lc-art-line"></span>
        </div>
    </div>
</section>

<!-- ==================== 快捷入口 ==================== -->
<section class="lc-shortcuts">
    <div class="lc-container">
        <div class="lc-shortcut-grid">
            <a href="<?= e(url('products.php')) ?>">
                <i><?= yx_icon('server', 20) ?></i>
                <span><strong>产品服务</strong><small>看配置、比价格、在线开通</small></span>
                <b>→</b>
            </a>
            <a href="<?= e(url('store/index.php')) ?>">
                <i><?= yx_icon('store', 20) ?></i>
                <span><strong>应用商店</strong><small>支付、实名与对接应用</small></span>
                <b>→</b>
            </a>
            <a href="#news">
                <i><?= yx_icon('bill', 20) ?></i>
                <span><strong>最新公告</strong><small>服务动态与维护通知</small></span>
                <b>→</b>
            </a>
            <a href="#license">
                <i><?= yx_icon('key', 20) ?></i>
                <span><strong>授权查询</strong><small>域名正版状态在线查验</small></span>
                <b>→</b>
            </a>
        </div>
    </div>
</section>

<!-- ==================== 产品与服务 ==================== -->
<section class="lc-section lc-products" id="products">
    <div class="lc-container">
        <div class="lc-heading">
            <span>产品与服务</span>
            <h2>把复杂的技术，变成简单可用的服务</h2>
            <p>围绕真实业务需求，提供清晰、易选、可持续扩展的产品，价格与配置公开透明。</p>
        </div>

        <?php if ($tabs): ?>
            <div class="lc-product-tabs" role="tablist">
                <?php $ti = 0; foreach ($tabs as $gid => $items): $gname = $gid > 0 ? (string)($groupMap[$gid] ?? '') : '其他产品'; ?>
                    <button type="button" role="tab" class="<?= $ti === 0 ? 'active' : '' ?>" data-lc-tab="lcPanel<?= $ti ?>" aria-selected="<?= $ti === 0 ? 'true' : 'false' ?>">
                        <em><?= e(sprintf('%02d', $ti + 1)) ?></em><?= e($gname) ?>
                    </button>
                <?php $ti++; endforeach; ?>
            </div>

            <div class="lc-product-panels">
                <?php $ti = 0; foreach ($tabs as $gid => $items):
                    $gname   = $gid > 0 ? (string)($groupMap[$gid] ?? '') : '其他产品';
                    $gsub    = $tabSubs[$gname] ?? '按需选择配置与计费周期，支持在线开通与续费';
                    $feature = array_shift($items); // 首个产品作为该页签的主推卡
                ?>
                <div class="lc-product-panel<?= $ti === 0 ? ' is-active' : '' ?>" id="lcPanel<?= $ti ?>" role="tabpanel"<?= $ti === 0 ? '' : ' hidden' ?>>
                    <div class="lc-product-feature">
                        <span class="lc-product-type"><?= e($gname) ?> · 推荐</span>
                        <div class="lc-product-feature-icon"><?= yx_icon($ti === 0 ? 'server' : 'store', 24) ?></div>
                        <h3><?= e($feature['name']) ?></h3>
                        <p><?= e($lcBrief($feature['description'] ?? '', 110, $gsub)) ?></p>
                        <ul>
                            <?php if ((int)$feature['stock'] === 0): ?>
                                <li>当前已售罄，补货后可再订购</li>
                            <?php elseif ((int)$feature['stock'] > 0): ?>
                                <li>当前库存 <?= (int)$feature['stock'] ?> 台</li>
                            <?php else: ?>
                                <li>库存充足，即开即用</li>
                            <?php endif; ?>
                            <li><?= e(YxBusiness::cycleName($feature['cycle'])) ?>计费 · <?= e(YxEdition::name($feature['edition_min'])) ?>起可用</li>
                            <li><?= (int)$feature['auto_setup'] === 1 ? '支付后自动开通' : '人工审核后开通' ?></li>
                        </ul>
                        <?php if (!$me): ?>
                            <a class="lc-button lc-button-dark" href="<?= e(url('user/login.php?back=' . urlencode('/products.php'))) ?>">登录后查看配置</a>
                        <?php else: ?>
                            <a class="lc-button lc-button-dark" href="<?= e(url('products.php')) ?>">查看产品与配置</a>
                        <?php endif; ?>
                        <span class="lc-feature-decoration"><i></i><i></i><i></i></span>
                    </div>

                    <?php if ($items): ?>
                    <div class="lc-product-grid">
                        <?php foreach (array_slice($items, 0, 6) as $k => $p):
                            $minOk = YxEdition::rank() >= YxEdition::rank($p['edition_min']);
                            $tones = ['lc-icon-blue', 'lc-icon-green', 'lc-icon-orange', 'lc-icon-purple', 'lc-icon-cyan', 'lc-icon-lime'];
                        ?>
                        <article class="lc-product-card">
                            <div class="lc-product-icon <?= $tones[$k % count($tones)] ?>"><?= yx_icon('server', 20) ?></div>
                            <h3><?= e($p['name']) ?></h3>
                            <p><?= e($lcBrief($p['description'] ?? '', 56, '标准配置，可按需选择计费周期')) ?></p>
                            <strong><?= e(money($p['price'], true)) ?> <small>/ <?= e(YxBusiness::cycleName($p['cycle'])) ?>起</small></strong>
                            <?php if (!$minOk): ?>
                                <a class="lc-product-link" href="<?= e(url('products.php')) ?>">需<?= e(YxEdition::name($p['edition_min'])) ?>权限 →</a>
                            <?php else: ?>
                                <a class="lc-product-link" href="<?= e(url('products.php')) ?>">查看详情 →</a>
                            <?php endif; ?>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php $ti++; endforeach; ?>
            </div>

            <p class="lc-section-foot"><a class="lc-link" href="<?= e(url('products.php')) ?>">查看全部产品与套餐 →</a></p>
        <?php else: ?>
            <div class="lc-empty">暂未上架产品，请先在后台「产品与套餐」中配置。</div>
        <?php endif; ?>
    </div>
</section>

<!-- ==================== 企业能力 ==================== -->
<section class="lc-section lc-capabilities">
    <div class="lc-container">
        <div class="lc-heading">
            <span>企业能力</span>
            <h2>为业务增长准备好每一层基础能力</h2>
            <p>从下单交付、资金核收到售后支持，用一致的产品逻辑构建业务环境。</p>
        </div>
        <div class="lc-capability-grid">
            <article class="lc-capability-card">
                <span>01</span>
                <i><?= yx_icon('bill', 22) ?></i>
                <h3>业务与订单</h3>
                <p>产品、套餐与业务实例统一管理，下单、支付、开通、续费在一条流程上流转。</p>
                <ul>
                    <li>多计费周期与自动开通</li>
                    <li>业务状态机可追溯</li>
                    <li>到期前自动提醒</li>
                </ul>
                <a href="<?= e(url('products.php')) ?>">查看产品能力 →</a>
            </article>
            <article class="lc-capability-card">
                <span>02</span>
                <i><?= yx_icon('wallet', 22) ?></i>
                <h3>财务与资金</h3>
                <p>余额、账单与收支流水逐笔对应，对账时直接查看，不必再翻聊天记录。</p>
                <ul>
                    <li>余额变动全程留痕</li>
                    <li>账单自动生成与结清</li>
                    <li>收支分类清晰可导</li>
                </ul>
                <a href="<?= e(url('user/recharge.php')) ?>">了解财务能力 →</a>
            </article>
            <article class="lc-capability-card">
                <span>03</span>
                <i><?= yx_icon('store', 22) ?></i>
                <h3>应用与生态</h3>
                <p>支付通道、实名认证与云服务对接以应用形式扩展，按需启用。</p>
                <ul>
                    <li>免费与付费应用统一管理</li>
                    <li>安装即绑定域名授权</li>
                    <li>开放接口支持对接</li>
                </ul>
                <a href="<?= e(url('store/index.php')) ?>">浏览应用商店 →</a>
            </article>
            <article class="lc-capability-card">
                <span>04</span>
                <i><?= yx_icon('shield', 22) ?></i>
                <h3>安全与合规</h3>
                <p>实名认证、权限分级与操作留痕，让经营过程可以被核验。</p>
                <ul>
                    <li>客户实名与分级权限</li>
                    <li>关键操作审计日志</li>
                    <li>资金变动可回溯</li>
                </ul>
                <a href="<?= e(url('user/realname.php')) ?>">查看安全能力 →</a>
            </article>
        </div>
        <p class="lc-capability-note">具体产品能力、资源交付时间及服务范围请以产品页面、库存情况和对应服务协议为准。</p>
    </div>
</section>

<!-- ==================== 公告与资讯 ==================== -->
<?php if ($newsList): ?>
<section class="lc-section lc-news" id="news">
    <div class="lc-container">
        <div class="lc-news-head">
            <div class="lc-heading">
                <span>公告与资讯</span>
                <h2>及时了解产品动态与服务通知</h2>
            </div>
            <a class="lc-link" href="<?= e(url('news.php')) ?>">查看全部公告 →</a>
        </div>
        <ul class="lc-news-list">
            <?php foreach ($newsList as $n): ?>
                <li>
                    <a href="<?= e(url('news_detail.php?id=' . (int)$n['id'])) ?>"><?= e($n['title']) ?></a>
                    <span><?= e(mb_substr((string)(($n['published_at'] ?? '') ?: ($n['created_at'] ?? '')), 0, 10)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<!-- ==================== 推荐应用 ==================== -->
<?php if ($featuredApps): ?>
<section class="lc-section lc-apps">
    <div class="lc-container">
        <div class="lc-heading">
            <span>精选推荐</span>
            <h2>装完即用的扩展应用</h2>
            <p>支付通道、实名认证、云服务对接，按业务需要随时安装。</p>
        </div>
        <div class="lc-app-grid">
            <?php foreach ($featuredApps as $a): ?>
                <a class="lc-app-card" href="<?= e(url('store/detail.php?id=' . (int)$a['id'])) ?>">
                    <div class="lc-app-head">
                        <span class="lc-app-icon"><?= e(mb_substr((string)$a['name'], 0, 1)) ?></span>
                        <em>v<?= e($a['version']) ?></em>
                    </div>
                    <h3><?= e($a['name']) ?></h3>
                    <p><?= e($lcBrief($a['description'] ?? '', 60, '安装后按应用说明完成配置即可使用')) ?></p>
                    <div class="lc-app-meta">
                        <span>需<?= e(YxEdition::name($a['edition_min'])) ?></span>
                        <b><?= e(YxBusiness::priceLabel($a)) ?></b>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ==================== 生态伙伴 ==================== -->
<?php if ($partnerLogos): ?>
<section class="lc-section lc-partners">
    <div class="lc-container">
        <div class="lc-heading lc-heading-center">
            <span>生态伙伴</span>
            <h2>与业界生态伙伴深度合作</h2>
            <p>与硬件、网络、证书与应用服务伙伴一起，为业务提供稳定的基础支撑。</p>
        </div>
        <div class="lc-partner-grid">
            <?php foreach (array_slice($partnerLogos, 0, 6) as $logo): ?>
                <div class="lc-partner-item">
                    <img src="<?= e(yx_wall_img_url((string)$logo['img'])) ?>" alt="<?= e((string)$logo['name'] !== '' ? (string)$logo['name'] : '合作伙伴') ?>" loading="lazy">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ==================== 正版授权查询 ==================== -->
<?php
$lqPkgSize = static function ($row): string {
    $rel = (string)($row['package_path'] ?? '');
    if ($rel === '') {
        return '';
    }
    $file = YX_ROOT . '/uploads/' . ltrim($rel, '/');
    if (!is_file($file)) {
        return '';
    }
    $bytes = (int)filesize($file);
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
};

$lqStatusMap = [
    YxLicense::STATUS_OK        => ['已正版授权', 'ok'],
    YxLicense::STATUS_NO        => ['未授权', 'bad'],
    YxLicense::STATUS_EXPIRED   => ['已过期', 'warn'],
    YxLicense::STATUS_BANNED    => ['已封禁', 'bad'],
    YxLicense::STATUS_COMMUNITY => ['开源版', 'info'],
];
$lqCopyMap = [
    YxLicense::STATUS_OK        => '该域名已在官方授权库登记，可按授权版本正常使用；正版程序包可在右侧下载或购买。',
    YxLicense::STATUS_NO        => '该域名未在官方授权库登记，属于非官方正版授权，请谨慎下单，避免财务损失。',
    YxLicense::STATUS_EXPIRED   => '该域名授权已过期，版本能力将受限，请续费后重新查询；期间请谨慎下单。',
    YxLicense::STATUS_BANNED    => '该域名授权已被官方封禁，来源存疑，请谨慎核验并谨慎下单，避免财务损失。',
    YxLicense::STATUS_COMMUNITY => '该域名运行开源版，授权模块未启用，不参与官方授权校验。',
];
$lqState      = 'idle';
$lqBadge      = '';
$lqTone       = 'info';
$lqCopy       = '';
$lqEdKey      = '';
$lqHasEdition = false;
if ($checkResult) {
    $lqStatus     = (string)($checkResult['status'] ?? '');
    $lqState      = $checkResult['authorized'] ? 'ok' : 'bad';
    [$lqBadge, $lqTone] = $lqStatusMap[$lqStatus] ?? ['请谨慎核验', 'warn'];
    $lqCopy  = $lqCopyMap[$lqStatus] ?? '授权结论以官方授权库记录为准，请谨慎核验后下单。';
    $lqEdKey = (string)($checkResult['edition'] ?? '');
    $lqHasEdition = $checkResult['authorized']
        || in_array($lqStatus, [YxLicense::STATUS_EXPIRED, YxLicense::STATUS_BANNED, YxLicense::STATUS_COMMUNITY], true);
}
?>
<section class="lc-section lc-license" id="license">
    <div class="lc-container">
        <div class="lc-heading">
            <span>正版授权</span>
            <h2>核验授权状态，保障部署来源可靠</h2>
            <p>输入已部署本系统的访问域名，查询授权状态、授权版本与到期时间，避免因非正版来源造成财务损失。</p>
        </div>

        <div class="lc-license-layout">
            <article class="lc-license-card">
                <div class="lc-license-top">
                    <span>01 / 授权查询</span>
                    <small>官方授权库实时核验</small>
                </div>
                <form class="lc-license-form" method="post" action="<?= e(url('index.php') . '#license') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="check_domain">
                    <label for="zdLqDomain">网站域名</label>
                    <div class="lc-license-row">
                        <input id="zdLqDomain" type="text" name="domain" inputmode="url" autocomplete="off" spellcheck="false"
                               value="<?= e($checkDomain) ?>" placeholder="例如 www.example.com" aria-describedby="zdLqHint" required>
                        <button class="lc-button lc-button-dark" type="submit">查询授权</button>
                    </div>
                    <p class="lc-license-hint" id="zdLqHint">请输入产品实际绑定的主域名或访问域名，无需带 http:// 前缀。</p>
                </form>

                <?php if ($checkError !== ''): ?>
                    <div class="lc-license-msg" role="alert"><?= e($checkError) ?></div>
                <?php endif; ?>

                <?php if ($checkResult): ?>
                <section class="lc-license-result is-<?= e($lqState) ?>" aria-live="polite">
                    <div class="lc-license-result-head">
                        <span>授权查询结果</span>
                        <b class="lc-license-badge is-<?= e($lqTone) ?>"><?= e($lqBadge) ?></b>
                    </div>
                    <h3><?= e($checkResult['message']) ?></h3>
                    <p class="lc-license-copy"><?= e($lqCopy) ?></p>
                    <dl class="lc-license-meta">
                        <div><dt>网站域名</dt><dd class="mono"><?= e($checkResult['domain']) ?></dd></div>
                        <div>
                            <dt>授权版本</dt>
                            <dd>
                                <?php if ($lqHasEdition && isset(YxEdition::EDITIONS[$lqEdKey])): ?>
                                    <?= e(YxEdition::name($lqEdKey)) ?>
                                <?php else: ?>
                                    <span class="lc-license-empty">未登记版本</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div><dt>授权时间</dt><dd><?= e(($checkResult['issued_at'] ?? '') ?: '-') ?></dd></div>
                        <div><dt>到期时间</dt><dd><?= e($checkResult['expire_at'] ?: ($checkResult['authorized'] ? '无期限' : '-')) ?></dd></div>
                    </dl>
                    <p class="lc-license-verify">查询时间 <?= e((string)($checkResult['checked_at'] ?? now())) ?>。授权状态请谨慎核验，正式授权以官方出具的授权凭证为准。</p>
                </section>
                <?php endif; ?>

                <div class="lc-license-note">
                    <span class="license-pill <?= e($badge['class']) ?>"><b><?= e($badge['icon']) ?></b> 当前域名：<?= e($badge['text']) ?></span>
                    <span class="lc-license-tip">非官方正版授权，请谨慎下单，避免财务损失。</span>
                </div>
            </article>

            <aside class="lc-license-side">
                <div class="lc-license-block">
                    <h4>查询说明</h4>
                    <ul>
                        <li>授权以域名为单位签发，一域名一授权</li>
                        <li>命中官方授权库：提示「域名已正版授权」</li>
                        <li>未登记域名：提示「域名未在官方授权」</li>
                        <li>已过期 / 已封禁：按状态单独提示</li>
                    </ul>
                </div>

                <div class="lc-license-block">
                    <h4>开发者接口</h4>
                    <p class="lc-license-api"><code>GET <?= e(url('api/license.php')) ?>?domain=你的域名</code></p>
                    <p class="lc-license-small">需携带授权ID与授权密钥签名，返回 JSON 结果。</p>
                </div>

                <?php if ($pkgRow || $openPkg): ?>
                <div class="lc-license-block">
                    <h4>程序包下载</h4>
                    <p class="lc-license-small">程序包免费下载（登录即可）；安装正版发行包需要的授权码属「授权包」，单独按次收费。开源版程序包同样免费下载。<br>
                        <strong>两者是两种固定形态：开源版装好后不能切换为正式版。</strong></p>
                    <?php if ($pkgRow && $pkgReady): ?>
                        <a class="lc-button lc-button-dark lc-button-block" href="<?= e(url('api/store_download.php?id=' . (int)$pkgRow['id'])) ?>" download>
                            免费下载正版程序包（v<?= e($pkgRow['version'] ?: '-') ?><?= $lqPkgSize($pkgRow) !== '' ? ' · ' . e($lqPkgSize($pkgRow)) : '' ?>）
                        </a>
                        <?php if ($pkgPaid): ?>
                            <p class="lc-license-small">
                                授权包：<strong><?= e(YxBusiness::priceLabel($pkgRow)) ?> / 次</strong>（按域名签发安装授权码）
                                <?php if ($pkgEnt): ?>
                                    · 您已购买授权，到期 <?= e($pkgEnt['expire_at'] ?: '永久') ?>
                                <?php endif; ?>
                                <br><a href="<?= e(url('store/detail.php?id=' . (int)$pkgRow['id'])) ?>">查看 / 单独购买授权包 →</a>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($openPkg): ?>
                        <a class="lc-button lc-button-outline lc-button-block" href="<?= e(url('api/store_download.php?id=' . (int)$openPkg['id'])) ?>" download>
                            下载开源版程序包（v<?= e($openPkg['version'] ?: '-') ?><?= $lqPkgSize($openPkg) !== '' ? ' · ' . e($lqPkgSize($openPkg)) : '' ?>）
                        </a>
                        <p class="lc-license-small"><strong>开源版安装后不可切换正式版。</strong>开源版与正版为同一份代码，但发行包把授权模式固定为开源版：授权模块不启用，界面显示「开源版 · 未启用授权模块」。</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>

<!-- ==================== 行动号召 ==================== -->
<section class="lc-cta">
    <div class="lc-container lc-cta-inner">
        <div>
            <span class="lc-cta-kicker">READY TO START</span>
            <h2>现在开始，用一套系统管好业务与财务</h2>
            <p>注册即可体验产品订购、订单支付、账单与资金流水的完整闭环。</p>
        </div>
        <div class="lc-cta-actions">
            <?php if ($me): ?>
                <a class="lc-button lc-button-dark" href="<?= e(url('user/index.php')) ?>">进入用户中心</a>
                <a class="lc-button lc-button-outline" href="<?= e(url('products.php')) ?>">订购产品</a>
            <?php else: ?>
                <a class="lc-button lc-button-dark" href="<?= e(url('user/register.php')) ?>">免费注册</a>
                <?php if ($homePhone !== ''): ?>
                    <a class="lc-button lc-button-outline" href="tel:<?= e(preg_replace('/[^\d+]/', '', $homePhone)) ?>">电话咨询 <?= e($homePhone) ?></a>
                <?php elseif ($homeEmail !== ''): ?>
                    <a class="lc-button lc-button-outline" href="mailto:<?= e($homeEmail) ?>">邮件咨询</a>
                <?php else: ?>
                    <a class="lc-button lc-button-outline" href="<?= e(url('user/tickets.php')) ?>">联系顾问</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

</div><!-- /.lc-home -->

<script>
(function () {
    // 产品页签：点击切换，保持角色语义与可见性同步
    var tabs = document.querySelectorAll('[data-lc-tab]');
    Array.prototype.forEach.call(tabs, function (tab) {
        tab.addEventListener('click', function () {
            Array.prototype.forEach.call(tabs, function (t) {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
                var p = document.getElementById(t.getAttribute('data-lc-tab'));
                if (p) { p.hidden = true; p.classList.remove('is-active'); }
            });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            var panel = document.getElementById(tab.getAttribute('data-lc-tab'));
            if (panel) { panel.hidden = false; panel.classList.add('is-active'); }
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
