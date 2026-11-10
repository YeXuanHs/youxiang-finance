<?php
/**
 * 优享财务系统 · 展示页（优享财务系统展示广告位）
 *
 * 版式参考商品列表页：顶部广告位页签 + 二级分组筛选 + 商品卡网格。
 * 数据全部来自后台「展示广告位」（YxAd），
 *   fpg_id = 广告位 ID（一级分组）   spg_id = 素材二级分组
 * 页面只做展示，卡片上的按钮指向素材里配置的真实购买入口。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$slots = YxAd::activeSlots('goods');

// 一级分组：fpg_id = 广告位
$fpg   = (int)($_GET['fpg_id'] ?? 0);
$slot  = null;
foreach ($slots as $s) {
    if ((int)$s['id'] === $fpg) {
        $slot = $s;
        break;
    }
}
if (!$slot && $slots) {
    $slot = $slots[0];
    $fpg  = (int)$slot['id'];
}

$items = $slot ? YxAd::items($fpg) : [];

// 二级分组：spg_id = 素材 subgroup（仅保留确实有素材的分组）
$subgroups = [];
foreach ($items as $it) {
    $g = trim((string)$it['subgroup']);
    if ($g !== '' && !in_array($g, $subgroups, true)) {
        $subgroups[] = $g;
    }
}
$spg = trim((string)($_GET['spg_id'] ?? ''));
if ($spg !== '' && !in_array($spg, $subgroups, true)) {
    $spg = '';
}
if ($spg !== '') {
    $items = array_values(array_filter($items, static function (array $it) use ($spg): bool {
        return trim((string)$it['subgroup']) === $spg;
    }));
}

$banners = YxAd::renderBanners('goods');

// 产品详情页图片（后台「商品图制作」启用后，商品卡出现「产品详情」入口）
$posters = [];
foreach (YxMarketing::byKind('detail') as $p) {
    $posters[(int)$p['id']] = $p;
}
$cardPoster = !empty($posters);
$detailPoster = null;
$detailItemId = (int)($_GET['detail'] ?? 0);
if ($detailItemId > 0 && $cardPoster) {
    foreach ($items as $it) {
        if ((int)$it['id'] === $detailItemId) {
            // 取第一张启用的详情长图（与商品卡一一对应关系由后台「商品图制作」自行维护）
            $detailPoster = reset($posters);
            $detailItem   = $it;
            break;
        }
    }
    if ($detailPoster === null) {
        $detailItemId = 0;
    }
}

$pageTitle = ($slot ? (string)$slot['name'] : '优享财务系统展示') . ' - ' . setting('site_name', config('app.name'));
$pageFull  = true;
require __DIR__ . '/includes/header.php';
?>

<?php if ($detailPoster !== null): ?>
<div class="zd-goods-head">
    <div class="portal-container">
        <div class="zd-goods-crumb">
            <a href="<?= e(url('index.php')) ?>">首页</a><span>/</span>
            <a href="<?= e(url('goods.php?fpg_id=' . $fpg)) ?>"><?= e($slot ? (string)$slot['name'] : '优享财务系统') ?></a><span>/</span>
            <span>产品详情</span>
        </div>
        <h1><?= e($detailItem['title']) ?></h1>
        <p class="zd-goods-lead"><?= e((string)$detailItem['subtitle']) ?></p>
    </div>
</div>

<div class="portal-container zd-goods-body">
    <div class="zd-goods-poster">
        <?php $posterUrl = YxMarketing::publicUrl($detailPoster); ?>
        <?php if ($posterUrl !== ''): ?>
            <img src="<?= e($posterUrl) ?>"
                 alt="<?= e($detailPoster['title']) ?>"
                 width="<?= (int)YxMarketing::size('detail')[0] ?>"
                 height="<?= (int)YxMarketing::size('detail')[1] ?>">
        <?php endif; ?>
    </div>
    <div class="zd-goods-foot-actions">
        <span class="zd-goods-price"><?= e(YxAd::priceLabel($detailItem)) ?><small> / <?= e(YxAd::cycleLabel($detailItem)) ?></small></span>
        <?php $href = YxAd::href((string)($detailItem['btn_url'] ?? '')); $btn = trim((string)($detailItem['btn_text'] ?? '')); ?>
        <?php if ($btn !== '' && $href !== ''): ?><a class="btn btn-primary" href="<?= e($href) ?>"><?= e($btn) ?></a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('goods.php?fpg_id=' . $fpg)) ?>">返回列表</a>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php exit; ?><?php endif; ?>

<!-- 页头：广告位页签 -->
<div class="zd-goods-head">
    <div class="portal-container">
        <div class="zd-goods-crumb">
            <a href="<?= e(url('index.php')) ?>">首页</a><span>/</span>
            <span>优享财务系统</span>
        </div>
        <h1><?= e($slot ? (string)$slot['name'] : '优享财务系统展示') ?></h1>
        <p class="zd-goods-lead">
            <?= e($slot && trim((string)$slot['subtitle']) !== ''
                ? (string)$slot['subtitle']
                : '正版授权按域名签发，价格与购买页一致，下单后即可在用户后台查看授权与下载入口。') ?>
        </p>
        <?php if (count($slots) > 1): ?>
            <div class="zd-goods-slots">
                <?php foreach ($slots as $s): ?>
                    <a class="zd-goods-slot<?= (int)$s['id'] === $fpg ? ' on' : '' ?>"
                       href="<?= e(url('goods.php?fpg_id=' . (int)$s['id'])) ?>"><?= e($s['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="portal-container zd-goods-body">
    <?php if ($banners !== ''): ?>
        <div class="zd-goods-banners"><?= $banners ?></div>
    <?php endif; ?>

    <?php if ($subgroups): ?>
        <div class="zd-goods-chips">
            <a class="zd-goods-chip<?= $spg === '' ? ' on' : '' ?>"
               href="<?= e(url('goods.php?fpg_id=' . $fpg)) ?>">全部</a>
            <?php foreach ($subgroups as $g): ?>
                <a class="zd-goods-chip<?= $spg === $g ? ' on' : '' ?>"
                   href="<?= e(url('goods.php?fpg_id=' . $fpg . '&spg_id=' . urlencode($g))) ?>"><?= e($g) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($items): ?>
        <div class="zd-goods-grid">
            <?php foreach ($items as $it): ?>
                <?php
                $img   = YxAd::imageUrl($it);
                $bgCss = $img !== '' ? "url('" . $img . "')" : YxBanner::paletteCss((string)($it['bg'] ?? 'blue'));
                $tag   = trim((string)($it['tag'] ?? ''));
                $btn   = trim((string)($it['btn_text'] ?? ''));
                $href  = YxAd::href((string)($it['btn_url'] ?? ''));
                ?>
                <div class="zd-goods-card">
                    <div class="zd-goods-cover" style="background-image:<?= e($bgCss) ?>">
                        <?php if ($tag !== ''): ?><span class="zd-goods-tag"><?= e($tag) ?></span><?php endif; ?>
                        <span class="zd-goods-cover-title"><?= e($it['title']) ?></span>
                    </div>
                    <div class="zd-goods-main">
                        <h3><?= e($it['title']) ?></h3>
                        <p class="zd-goods-desc"><?= e((string)$it['subtitle']) ?></p>
                        <?php if (trim((string)$it['subgroup']) !== ''): ?>
                            <span class="zd-goods-sub"><?= e($it['subgroup']) ?></span>
                        <?php endif; ?>
                        <div class="zd-goods-foot">
                            <span class="zd-goods-price"><?= e(YxAd::priceLabel($it)) ?><small> / <?= e(YxAd::cycleLabel($it)) ?></small></span>
                            <?php if (!empty($cardPoster)): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('goods.php?fpg_id=' . $fpg . '&detail=' . (int)$it['id'])) ?>">产品详情</a>
                            <?php endif; ?>
                            <?php if ($btn !== '' && $href !== ''): ?>
                                <a class="btn btn-primary btn-sm" href="<?= e($href) ?>"><?= e($btn) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card"><div class="empty">
            <?= $slot ? '该分类下暂无可展示的内容，请稍后再来看看。' : '展示内容尚未配置，请先在后台「展示广告位」中添加。' ?>
        </div></div>
    <?php endif; ?>

    <div class="zd-goods-note">
        <h3>说明</h3>
        <ul>
            <li>本页为官方展示位，卡片中的价格与购买入口以后台「版本与模块」的实时配置为准。</li>
            <li>授权以域名为单位签发，购买后在「我的授权」中查看授权码与到期时间。</li>
            <li>如需了解具体功能差异，可在
                <a href="<?= e(url('index.php#editions')) ?>">版本对比</a> 与
                <a href="<?= e(url('docs/')) ?>">安装教程</a> 中查看。
            </li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
