<?php
/**
 * 优享财务系统 · 开发者主页
 *
 * 展示开发者档案（名称、认证标识、作者网站、电子邮箱、咨询 QQ、简介）
 * 与其已上架商品列表。商品认证标识与详情页统一取 YxStore::certify()，
 * 避免两处口径不一致。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

$id  = (int)($_GET['id'] ?? 0);
$dev = $id > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('developer') . ' WHERE id = ?', [$id]) : null;

$notFound = !$dev || (string)($dev['status'] ?? '') !== 'approved';
if ($notFound) {
    http_response_code(404);
}

$apps  = [];
$stats = ['apps' => 0, 'sales' => 0];
if (!$notFound) {
    $apps = YxDb::all(
        'SELECT * FROM ' . YxDb::table('app') . " WHERE developer_id = ? AND status = 'on' ORDER BY featured DESC, sales DESC, id DESC",
        [(int)$dev['id']]
    ) ?: [];
    $stats = YxStore::developerStats($dev);
}

$devName = $notFound ? '' : ((string)($dev['company'] ?? '') ?: ((string)($dev['real_name'] ?? '') ?: ('开发者#' . (int)$dev['id'])));
$devApp  = $notFound ? [] : ['author_type' => 'official', 'developer_id' => (int)$dev['id']];
$certify = $notFound ? null : YxStore::certify($devApp);
$ownerCl = !$certify ? 'is-official' : ($certify['key'] === 'enterprise' ? 'is-company' : ($certify['key'] === 'personal' ? 'is-person' : 'is-official'));

$siteUrl = $notFound ? '' : trim((string)($dev['website'] ?? ''));
if ($siteUrl !== '' && !preg_match('#^https?://#i', $siteUrl)) {
    $siteUrl = 'http://' . $siteUrl;
}
$mail   = $notFound ? '' : trim((string)($dev['email'] ?? ''));
$qq     = $notFound ? '' : trim((string)($dev['qq'] ?? ''));
$wechat = $notFound ? '' : trim((string)($dev['wechat'] ?? ''));
$intro  = $notFound ? '' : trim((string)($dev['intro'] ?? ''));

/** 价格拆分展示 */
function dev_price_parts($price): array
{
    $n   = (float)$price;
    $int = (int)floor($n);
    return [$int, str_pad((string)(int)round(($n - $int) * 100), 2, '0', STR_PAD_LEFT)];
}

$pageTitle = $notFound ? '开发者不存在' : $devName . ' - 开发者主页';
$pageFull  = true;
require dirname(__DIR__) . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= e(url('assets/css/store.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/store.css') ?>">

<div class="yx-shop">
<div class="yx-shop-wrap is-full">

    <p class="yx-crumb">
        <a href="<?= e(url('store/index.php')) ?>">应用中心</a> / 开发者主页
    </p>

    <?php if ($notFound): ?>
        <div class="yx-panel" style="margin-top:20px;text-align:center;padding:56px 24px">
            <p style="color:#98a0ad;margin:0 0 18px">开发者不存在或未通过审核</p>
            <a class="btn btn-primary" href="<?= e(url('store/index.php')) ?>">返回应用中心</a>
        </div>
    <?php else: ?>

    <div class="yx-detail-grid">
        <div>
            <div class="yx-panel">
                <div class="yx-dev-head">
                    <div class="yx-dev-avatar" style="width:60px;height:60px;font-size:24px;border-radius:12px"><?= e(mb_substr($devName, 0, 1)) ?></div>
                    <div class="yx-dev-id">
                        <div class="yx-dev-name" style="font-size:18px">
                            <?= e($devName) ?>
                            <span class="yx-owner <?= e($ownerCl) ?>" title="<?= e($certify['title']) ?>">
                                <i><?= e($certify['icon']) ?></i><?= e($certify['label']) ?>
                            </span>
                        </div>
                        <?php if ($intro !== ''): ?>
                            <p class="yx-dev-intro"><?= e($intro) ?></p>
                        <?php endif; ?>
                        <div class="yx-dev-stats">
                            <span>在售 <b><?= (int)$stats['apps'] ?></b> 个商品</span>
                            <span>累计销量 <b><?= (int)$stats['sales'] ?></b></span>
                            <?php if (!empty($dev['approved_at'])): ?>
                                <span>入驻时间 <?= e(date('Y-m-d', strtotime((string)$dev['approved_at']))) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="yx-panel">
                <h2>全部商品（<?= count($apps) ?>）</h2>
                <?php if (!$apps): ?>
                    <div class="yx-shop-empty">该开发者暂无在售商品</div>
                <?php else: ?>
                    <ul class="yx-shop-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                        <?php foreach ($apps as $a):
                            $free   = YxStore::allFree($a);
                            $from   = YxStore::fromPrice($a);
                            $isFrom = YxStore::hasMultiPrice($a);
                            $quote  = YxStore::quote($a);
                            [$pInt, $pDec] = dev_price_parts($from !== null ? $from : ($quote['price'] ?? 0));
                        ?>
                            <li class="yx-shop-item">
                                <a class="yx-card" href="<?= e(url('store/detail.php?id=' . (int)$a['id'])) ?>">
                                    <div class="yx-card-media">
                                        <?php if (trim((string)($a['cover'] ?? '')) !== ''): ?>
                                            <img src="<?= e(url($a['cover'])) ?>" alt="<?= e($a['name']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <div class="yx-card-ico"><?= e(mb_substr($a['name'], 0, 1)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="yx-card-body" style="min-height:0">
                                        <div class="yx-card-title">
                                            <h3 class="yx-card-name" title="<?= e($a['name']) ?>"><?= e($a['name']) ?></h3>
                                        </div>
                                        <div class="yx-card-signals">
                                            <div class="yx-card-activity"><?= e(YxStore::typeName($a['type'])) ?> · v<?= e($a['version']) ?></div>
                                            <div class="yx-card-activity"><?= (int)$a['sales'] ?> 次购买</div>
                                        </div>
                                        <div class="yx-card-footer">
                                            <span class="yx-card-author">
                                                <span class="yx-card-author-name"><?= e(YxStore::updatedAt($a)) ?></span>
                                            </span>
                                            <?php if ($free || $from === null): ?>
                                                <strong class="yx-card-price is-free">免费</strong>
                                            <?php else: ?>
                                                <strong class="yx-card-price">
                                                    <small>¥</small><?= $pInt ?><span class="dec">.<?= $pDec ?></span><?= $isFrom ? '<em>起</em>' : '' ?>
                                                </strong>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="yx-panel">
                <h2>联系方式</h2>
                <table class="yx-kv yx-dev-contact">
                    <tr><th>开发者</th><td><?= e($devName) ?></td></tr>
                    <tr><th>认证类型</th><td><?= e($certify['label']) ?></td></tr>
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
                <p class="yx-buy-tip">商品购买、安装与售后由平台统一处理，联系开发者仅用于技术咨询。</p>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
