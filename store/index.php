<?php
/**
 * 优享财务系统 · 应用中心（前台商店）
 *
 * 版式参考 IDC 应用中心目录页：英雄区搜索 + 排序条 + 多行筛选 + 四列卡片。
 * 价格与计费方式完全取自数据库（bill_type / price / points_price），
 * 购买动作交由 user/app_redeem.php，与详情页保持一致。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

$cats = YxDb::all('SELECT * FROM ' . YxDb::table('app_category') . ' WHERE status = 1 ORDER BY sort ASC, id ASC');

$catF    = trim((string)($_GET['cat'] ?? ''));
$kw      = trim((string)($_GET['kw'] ?? ''));
$authorF = trim((string)($_GET['author'] ?? ''));   // official | thirdparty
$billF   = trim((string)($_GET['bill'] ?? ''));     // free | once | year | points
$sortF   = trim((string)($_GET['sort'] ?? 'default'));

$where = " WHERE status = 'on'";
$bind  = [];

if ($catF !== '') {
    $where  .= ' AND category_key = ?';
    $bind[] = $catF;
}
if ($kw !== '') {
    $where .= ' AND (name LIKE ? OR description LIKE ? OR author LIKE ?)';
    $kwl    = '%' . $kw . '%';
    array_push($bind, $kwl, $kwl, $kwl);
}
if ($authorF === 'official') {
    $where .= " AND COALESCE(author_type,'official') <> 'thirdparty'";
} elseif ($authorF === 'thirdparty') {
    $where .= " AND author_type = 'thirdparty'";
}
if (in_array($billF, ['free', 'once', 'year', 'points'], true)) {
    $where  .= ' AND bill_type = ?';
    $bind[] = $billF;
}

/** 排序：只允许白名单，避免拼接注入 */
$orderMap = [
    'default'    => 'featured DESC, sales DESC, id DESC',
    'downloads'  => 'downloads DESC, id DESC',
    'sales'      => 'sales DESC, id DESC',
    'price_asc'  => 'price ASC, id DESC',
    'price_desc' => 'price DESC, id DESC',
    'new'        => 'id DESC',
];
$order = $orderMap[$sortF] ?? $orderMap['default'];

$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . $where, $bind);
$page  = paginate($total, 16);
$apps  = YxDb::all(
    'SELECT * FROM ' . YxDb::table('app') . $where . ' ORDER BY ' . $order
    . ' LIMIT ' . (int)$page['per_page'] . ' OFFSET ' . (int)$page['offset'],
    $bind
);

/** 分类计数（不受当前分类筛选影响，便于横向比较） */
$catCount = [];
foreach (YxDb::all("SELECT category_key, COUNT(*) AS cnt FROM " . YxDb::table('app') . " WHERE status = 'on' GROUP BY category_key") as $r) {
    $catCount[$r['category_key']] = (int)$r['cnt'];
}
$allCount = (int)YxDb::value("SELECT COUNT(*) FROM " . YxDb::table('app') . " WHERE status = 'on'");

/** 认证类型计数 */
$officialCount = (int)YxDb::value("SELECT COUNT(*) FROM " . YxDb::table('app') . " WHERE status = 'on' AND COALESCE(author_type,'official') <> 'thirdparty'");
$thirdCount    = (int)YxDb::value("SELECT COUNT(*) FROM " . YxDb::table('app') . " WHERE status = 'on' AND author_type = 'thirdparty'");

/** 费用类型计数 */
$billCount = ['free' => 0, 'once' => 0, 'year' => 0, 'points' => 0];
foreach (YxDb::all("SELECT bill_type, COUNT(*) AS cnt FROM " . YxDb::table('app') . " WHERE status = 'on' GROUP BY bill_type") as $r) {
    $k = (string)$r['bill_type'];
    if (isset($billCount[$k])) {
        $billCount[$k] = (int)$r['cnt'];
    }
}

/** 保留筛选条件的链接 */
function store_url(array $override = []): string
{
    $q = array_merge([
        'cat'    => (string)($_GET['cat'] ?? ''),
        'kw'     => (string)($_GET['kw'] ?? ''),
        'author' => (string)($_GET['author'] ?? ''),
        'bill'   => (string)($_GET['bill'] ?? ''),
        'sort'   => (string)($_GET['sort'] ?? ''),
        'page'   => '',
    ], $override);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
    return url('store/index.php' . ($q ? '?' . http_build_query($q) : ''));
}

/** 价格拆分为整数 / 小数两位，复刻参考站展示 */
function store_price_parts($price): array
{
    $n   = (float)$price;
    $int = (int)floor($n);
    $dec = (int)round(($n - $int) * 100);
    return [$int, str_pad((string)$dec, 2, '0', STR_PAD_LEFT)];
}

$pageTitle = '应用中心 - ' . setting('site_name', config('app.name'));
$pageFull  = true;
require dirname(__DIR__) . '/includes/header.php';
$bills     = [
    ''       => '全部',
    'free'   => '免费',
    'once'   => '一次性',
    'year'   => '按年',
    'points' => '积分兑换',
];
?>

<link rel="stylesheet" href="<?= e(url('assets/css/store.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/store.css') ?>">

<div class="yx-shop">
<div class="yx-shop-wrap is-full">

    <!-- ===== 英雄区 ===== -->
    <section class="yx-shop-hero">
        <div class="yx-shop-hero-inner">
            <h1>应用中心</h1>
            <p>支付通道、实名认证、云服务对接、系统增强、通知消息与运维工具，按绑定域名签发授权，购买后即刻可用。</p>
            <form class="yx-shop-search" method="get" action="<?= e(url('store/index.php')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="7.3"></circle><path d="m16.1 16.1 4.4 4.4"></path></svg>
                <input type="search" name="kw" value="<?= e($kw) ?>" placeholder="搜索应用与服务，如：聚合码支付 / 自动续费" aria-label="搜索应用与服务">
                <?php if ($catF !== ''): ?><input type="hidden" name="cat" value="<?= e($catF) ?>"><?php endif; ?>
                <?php if ($authorF !== ''): ?><input type="hidden" name="author" value="<?= e($authorF) ?>"><?php endif; ?>
                <?php if ($billF !== ''): ?><input type="hidden" name="bill" value="<?= e($billF) ?>"><?php endif; ?>
                <button type="submit">开始搜索</button>
            </form>
        </div>
    </section>

    <!-- ===== 目录主区 ===== -->
    <section class="yx-shop-main">

        <header class="yx-shop-heading">
            <div class="yx-shop-title">
                <h2>全部应用</h2>
                <span><?= $total ?> 个结果</span>
            </div>
            <div class="yx-shop-sorts" role="group" aria-label="商品排序">
                <?php
                $sorts = [
                    'default'    => ['综合推荐', false],
                    'downloads'  => ['下载量', false],
                    'sales'      => ['购买量', false],
                    'price'      => ['价格', true],
                    'new'        => ['最新上架', false],
                ];
                foreach ($sorts as $sk => $meta):
                    [$label, $arrow] = $meta;
                    if ($sk === 'price') {
                        $ascOn  = $sortF === 'price_asc';
                        $descOn = $sortF === 'price_desc';
                        $active = $ascOn || $descOn;
                        $next   = $ascOn ? 'price_desc' : 'price_asc';
                    } else {
                        $active = ($sortF === $sk) || ($sk === 'default' && $sortF === '');
                        $next   = $sk;
                    }
                ?>
                <a class="yx-shop-sort<?= $active ? ' is-active' : '' ?>" href="<?= e(store_url(['sort' => $next])) ?>">
                    <span><?= e($label) ?></span>
                    <?php if ($arrow): ?>
                        <span class="yx-shop-sort-arrows" aria-hidden="true">
                            <i class="yx-shop-sort-arrow is-asc<?= $ascOn ? ' is-active' : '' ?>"></i>
                            <i class="yx-shop-sort-arrow is-desc<?= $descOn ? ' is-active' : '' ?>"></i>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </header>

        <div class="yx-shop-filters">
            <div class="yx-shop-filter-row is-full">
                <div class="yx-shop-filter-label">应用分类</div>
                <div class="yx-shop-filter-values">
                    <a class="yx-shop-tag<?= $catF === '' ? ' is-active' : '' ?>" href="<?= e(store_url(['cat' => ''])) ?>">全部<em><?= $allCount ?></em></a>
                    <?php foreach ($cats as $c): ?>
                        <a class="yx-shop-tag<?= $catF === $c['key'] ? ' is-active' : '' ?>" href="<?= e(store_url(['cat' => $c['key']])) ?>">
                            <?= e($c['name']) ?><em><?= $catCount[$c['key']] ?? 0 ?></em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="yx-shop-filter-row">
                <div class="yx-shop-filter-label">认证类型</div>
                <div class="yx-shop-filter-values">
                    <a class="yx-shop-tag<?= $authorF === '' ? ' is-active' : '' ?>" href="<?= e(store_url(['author' => ''])) ?>">全部</a>
                    <a class="yx-shop-tag<?= $authorF === 'official' ? ' is-active' : '' ?>" href="<?= e(store_url(['author' => 'official'])) ?>">官方认证<em><?= $officialCount ?></em></a>
                    <a class="yx-shop-tag<?= $authorF === 'thirdparty' ? ' is-active' : '' ?>" href="<?= e(store_url(['author' => 'thirdparty'])) ?>">第三方<em><?= $thirdCount ?></em></a>
                </div>
            </div>

            <div class="yx-shop-filter-row">
                <div class="yx-shop-filter-label">费用类型</div>
                <div class="yx-shop-filter-values">
                    <?php foreach ($bills as $bk => $bl): ?>
                        <a class="yx-shop-tag<?= $billF === $bk ? ' is-active' : '' ?>" href="<?= e(store_url(['bill' => $bk])) ?>">
                            <?= e($bl) ?><?php if ($bk !== ''): ?><em><?= $billCount[$bk] ?? 0 ?></em><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="yx-shop-content">
            <?php if (!$apps): ?>
                <div class="yx-shop-empty">没有找到相关内容</div>
            <?php else: ?>
                <ul class="yx-shop-grid">
                    <?php foreach ($apps as $a): ?>
                        <?php
                        $bill    = YxBusiness::billType($a);
                        $isThird = (string)($a['author_type'] ?? '') === 'thirdparty';
                        $cover   = trim((string)($a['cover'] ?? ''));
                        $author  = YxStore::authorName($a);
                        $certify = YxStore::certify($a);
                        $ownerCl = YxStore::certifyClass($a);
                        // 版本报价：多版本取起价并加「起」，单一报价直接展示
                        $free    = YxStore::allFree($a);
                        $from    = YxStore::fromPrice($a);
                        $isFrom  = YxStore::hasMultiPrice($a);
                        $quote   = YxStore::quote($a);
                        [$pInt, $pDec] = store_price_parts($from !== null ? $from : ($quote['price'] ?? 0));
                        // 评分：以购买量映射 0-5 星，保证无评价数据时也有稳定展示
                        $score   = (float)$a['sales'] > 0 ? min(5, 3.5 + min(1.5, (float)$a['sales'] / 100)) : 5.0;
                        ?>
                        <li class="yx-shop-item">
                            <a class="yx-card" href="<?= e(url('store/detail.php?id=' . (int)$a['id'])) ?>">
                                <div class="yx-card-media">
                                    <?php if ($cover !== ''): ?>
                                        <img src="<?= e(url($cover)) ?>" alt="<?= e($a['name']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="yx-card-ico<?= $isThird ? ' is-3rd' : '' ?>"><?= e(mb_substr($a['name'], 0, 1)) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="yx-card-body">
                                    <div class="yx-card-title">
                                        <h3 class="yx-card-name" title="<?= e($a['name']) ?>"><?= e($a['name']) ?></h3>
                                        <span class="yx-owner <?= e($ownerCl) ?>" title="<?= e($certify['title']) ?>">
                                            <i><?= e($certify['icon']) ?></i><?= e(str_replace(['认证', '未认证'], '', $certify['label'])) ?>
                                        </span>
                                    </div>
                                    <div class="yx-card-signals">
                                        <div class="yx-stars" aria-label="<?= e(number_format($score, 1)) ?> 星评分">
                                            <div class="yx-stars-on" style="width:<?= (int)round($score / 5 * 100) ?>%">
                                                <i>★</i><i>★</i><i>★</i><i>★</i><i>★</i>
                                            </div>
                                            <div class="yx-stars-off"><i>★</i><i>★</i><i>★</i><i>★</i><i>★</i></div>
                                        </div>
                                        <div class="yx-card-activity"><?= (int)$a['sales'] ?>+ 活跃度</div>
                                    </div>
                                    <div class="yx-card-footer">
                                        <span class="yx-card-author">
                                            <i class="yx-card-author-ico"><?= e(mb_substr($author, 0, 1)) ?></i>
                                            <span class="yx-card-author-name" title="<?= e($author) ?>"><?= e($author) ?></span>
                                        </span>
                                        <?php if ($free || $from === null): ?>
                                            <strong class="yx-card-price is-free">免费</strong>
                                        <?php elseif ($quote && $quote['bill_type'] === 'points'): ?>
                                            <strong class="yx-card-price"><small></small><?= (int)($a['points_price'] ?? 0) ?><em>积分</em></strong>
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

        <div class="yx-shop-more">
            <?php if ($page['page'] < $page['pages']): ?>
                <a href="<?= e(store_url(['page' => $page['page'] + 1])) ?>">加载更多应用</a>
            <?php elseif ($total > 0): ?>
                <span>已加载全部 <?= $total ?> 个应用</span>
            <?php endif; ?>
        </div>

    </section>
</div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
