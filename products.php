<?php
/**
 * 优享财务系统 · 产品目录（全量产品，按分组页签浏览）
 *
 * 首页「热门产品」每类只展示 4 个推荐位（index.php 里的 array_slice），
 * 上游同步进来的上百个商品在首页看不到，因此单独提供本页：
 *   - 顶部按「产品分组」生成页签（与下游 /cart/prolist 的 fgs 分组同源），?g=分组ID 可直达；
 *   - 未分组产品归入「其他产品」，仅在确实有货时出现；
 *   - 商品卡复用首页 .zd-market-* 样式，购买周期收敛为产品允许的周期（cycles）。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$me = YxAuth::customer();

$groupRows = YxDb::all(
    'SELECT * FROM ' . YxDb::table('product_group') . ' WHERE status = 1 ORDER BY sort ASC, id ASC'
);
$groupMap = [];
foreach ($groupRows as $g) {
    $groupMap[(int)$g['id']] = $g;
}

// 只列上架产品；库存为 0 的等同于售罄，前台不展示（与首页口径一致）
$rows = YxDb::all(
    'SELECT * FROM ' . YxDb::table('product') . ' WHERE status = 1 ORDER BY sort ASC, id ASC'
);

$byGroup = [];
$total   = 0;
foreach ($rows as $p) {
    if ((int)$p['stock'] === 0) {
        continue;
    }
    $gid = (int)$p['group_id'];
    if (!isset($groupMap[$gid])) {
        $gid = 0;   // 未分组 / 分组已下架
    }
    $byGroup[$gid][] = $p;
    $total++;
}

// 页签：有货的分组才出现（八彩云计算 → 其他产品）
$tabList = [];
foreach ($groupMap as $gid => $g) {
    if (!empty($byGroup[$gid])) {
        $tabList[$gid] = (string)$g['name'];
    }
}
if (!empty($byGroup[0])) {
    $tabList[0] = '其他产品';
}

// 当前分组：?g= 优先，非法值退回第一个页签
$curGid = (int)($_GET['g'] ?? 0);
if (!isset($tabList[$curGid])) {
    $curGid = $tabList ? (int)array_key_first($tabList) : 0;
}
$curItems = $byGroup[$curGid] ?? [];

// 关键词过滤（站内搜索，仅作用于当前分组）
$keyword = trim((string)($_GET['kw'] ?? ''));
if ($keyword !== '') {
    $curItems = array_values(array_filter($curItems, static function (array $p) use ($keyword): bool {
        return mb_stripos((string)$p['name'], $keyword) !== false
            || mb_stripos((string)$p['description'], $keyword) !== false;
    }));
}

$pageTitle = '全部产品 - ' . setting('site_name', config('app.name'));
$pageFull  = true;
require __DIR__ . '/includes/header.php';
?>

<section class="zd-section" id="products">
    <div class="portal-container">
        <div class="zd-section-head">
            <h3>全部产品</h3>
            <p>共 <?= (int)$total ?> 个在售产品<?= $tabList ? '，当前「' . e($tabList[$curGid]) . '」' . count($curItems) . ' 个' : '' ?></p>
        </div>

        <?php if ($tabList): ?>
        <div class="zd-tabs-bar">
            <?php foreach ($tabList as $gid => $gname): ?>
                <?php $cnt = count($byGroup[$gid]); ?>
                <a class="zd-tabs-item<?= (int)$gid === (int)$curGid ? ' on' : '' ?>" href="<?= e(url('products.php?g=' . (int)$gid)) ?>">
                    <em><?= e($gname) ?></em>
                    <span><?= (int)$cnt ?> 个产品</span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" class="zd-catalog-search">
            <input type="hidden" name="g" value="<?= (int)$curGid ?>">
            <input type="text" name="kw" value="<?= e($keyword) ?>" placeholder="在「<?= e($tabList[$curGid]) ?>」中搜索：配置 / 地区 / 机型">
            <button class="btn btn-primary btn-sm" type="submit">搜索</button>
            <?php if ($keyword !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('products.php?g=' . (int)$curGid)) ?>">清除</a>
            <?php endif; ?>
        </form>

        <?php if ($curItems): ?>
        <div class="zd-market-grid zd-catalog-grid">
            <?php foreach ($curItems as $p): ?>
                <?php
                $minOk    = YxEdition::rank() >= YxEdition::rank($p['edition_min']);
                $allowed  = YxBusiness::allowedCycles($p);
                $defCycle = YxBusiness::defaultCycle($p);
                $cycleTxt = YxBusiness::cycleLabel($p);
                ?>
                <div class="zd-market-info">
                    <div class="zd-market-title-wrap">
                        <span class="zd-market-title"><?= e($p['name']) ?></span>
                        <span class="zd-market-tags">
                            <?php if ($cycleTxt !== ''): ?><i class="zd-market-tag"><?= e($cycleTxt) ?></i><?php endif; ?>
                            <i class="zd-market-tag"><?= e(YxBusiness::cycleName($defCycle)) ?></i>
                        </span>
                    </div>
                    <div class="zd-market-tc">
                        <p><?= e(mb_substr((string)$p['description'], 0, 100)) ?></p>
                        <?php if ((int)$p['stock'] >= 0): ?><p>库存：<?= (int)$p['stock'] ?> 台</p><?php endif; ?>
                        <p>版本要求：<?= e(YxEdition::name($p['edition_min'])) ?>起</p>
                    </div>
                    <div class="zd-market-prices">
                        <span class="zd-market-prices-num"><?= e(money($p['price'], true)) ?><small> / <?= e(YxBusiness::cycleName($defCycle)) ?></small></span>
                        <?php if (!$minOk): ?>
                            <button class="btn btn-ghost btn-sm" disabled>需<?= e(YxEdition::name($p['edition_min'])) ?></button>
                        <?php elseif (!$me): ?>
                            <a class="btn btn-primary btn-sm" href="<?= e(url('user/login.php?back=' . urlencode('/products.php?g=' . (int)$curGid))) ?>">登录后购买</a>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('user/purchase.php')) ?>" class="zd-buy-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="kind" value="product">
                                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                <?php if (count($allowed) === 1): ?>
                                    <input type="hidden" name="cycle" value="<?= e($defCycle) ?>">
                                <?php else: ?>
                                    <select name="cycle">
                                        <?php foreach ($allowed as $k): ?>
                                            <option value="<?= e($k) ?>" <?= $defCycle === $k ? 'selected' : '' ?>><?= e(YxBusiness::cycleName($k)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button class="btn btn-primary btn-sm" type="submit">立即购买</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="card"><div class="empty"><?= $keyword !== '' ? '没有匹配「' . e($keyword) . '」的产品' : '该分组暂无可售产品' ?></div></div>
        <?php endif; ?>

        <?php else: ?>
            <div class="card"><div class="empty">暂未上架产品，请先在后台「产品与套餐」中配置</div></div>
        <?php endif; ?>

        <p class="zd-more"><a href="<?= e(url('index.php#products')) ?>">← 返回首页精选推荐</a></p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
