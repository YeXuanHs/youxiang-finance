<?php
/**
 * 优享财务系统 · 服务中心（前台 · 可购买的服务目录）
 *
 * 版式参考 IDC 服务市场：英雄区 + 分组筛选 + 卡片网格。
 * 价格 / 计费方式全部取自 yx_service_item，购买动作交由 service/detail.php。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

$groupF = trim((string)($_GET['group'] ?? ''));
$kw     = trim((string)($_GET['kw'] ?? ''));

$groups = YxServiceCenter::GROUPS;
if ($groupF !== '' && !isset($groups[$groupF])) {
    $groupF = '';
}

$catalog = YxServiceCenter::catalog(true);

// 关键词过滤
$items = [];
foreach ($catalog as $g) {
    foreach ($g['items'] as $it) {
        if ($groupF !== '' && $g['key'] !== $groupF) {
            continue;
        }
        if ($kw !== '') {
            $hay = $it['name'] . ' ' . ($it['subtitle'] ?? '') . ' ' . ($it['description'] ?? '');
            if (mb_stripos($hay, $kw) === false) {
                continue;
            }
        }
        $it['_group'] = $g['key'];
        $items[] = $it;
    }
}
// 精选优先、排序值降序
usort($items, static function ($a, $b) {
    $fa = (int)($a['featured'] ?? 0);
    $fb = (int)($b['featured'] ?? 0);
    if ($fa !== $fb) {
        return $fb <=> $fa;
    }
    return ((int)($b['sort'] ?? 0)) <=> ((int)($a['sort'] ?? 0));
});

// 分组计数
$groupCount = [];
foreach ($catalog as $g) {
    $groupCount[$g['key']] = count($g['items']);
}
$allCount = array_sum($groupCount);

$pageTitle = '服务中心 - ' . setting('site_name', config('app.name'));
$pageFull  = true;
require dirname(__DIR__) . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= e(url('assets/css/service.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/service.css') ?>">

<div class="yx-svc">
<div class="yx-svc-wrap">

    <section class="yx-svc-hero">
        <div class="yx-svc-hero-inner">
            <h1>服务中心</h1>
            <p>运维托管、安全加固、建站开发与技术支持，明码标价，下单即可安排工程师对接。</p>
            <form class="yx-svc-search" method="get" action="<?= e(url('service/index.php')) ?>">
                <input type="search" name="kw" value="<?= e($kw) ?>" placeholder="搜索服务，如：数据迁移 / 安全加固" aria-label="搜索服务">
                <?php if ($groupF !== ''): ?><input type="hidden" name="group" value="<?= e($groupF) ?>"><?php endif; ?>
                <button type="submit">搜索</button>
            </form>
        </div>
    </section>

    <section class="yx-svc-main">
        <div class="yx-svc-filters">
            <a class="yx-svc-tag<?= $groupF === '' ? ' is-active' : '' ?>" href="<?= e(url('service/index.php' . ($kw !== '' ? '?kw=' . urlencode($kw) : ''))) ?>">全部<em><?= $allCount ?></em></a>
            <?php foreach ($groups as $gk => $g): ?>
                <?php if (empty($groupCount[$gk])) continue; ?>
                <a class="yx-svc-tag<?= $groupF === $gk ? ' is-active' : '' ?>" href="<?= e(url('service/index.php?group=' . urlencode($gk) . ($kw !== '' ? '&kw=' . urlencode($kw) : ''))) ?>">
                    <i><?= e($g['icon']) ?></i><?= e($g['name']) ?><em><?= $groupCount[$gk] ?></em>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$items): ?>
            <div class="yx-svc-empty">没有找到相关服务</div>
        <?php else: ?>
            <ul class="yx-svc-grid">
                <?php foreach ($items as $it): ?>
                    <?php
                    $specs    = YxServiceCenter::specs($it);
                    $features = YxServiceCenter::features($it);
                    $bill     = (string)($it['bill_type'] ?? 'once');
                    ?>
                    <li class="yx-svc-card">
                        <a href="<?= e(url('service/detail.php?id=' . (int)$it['id'])) ?>" class="yx-svc-card-link">
                            <div class="yx-svc-card-top">
                                <span class="yx-svc-ico"><?= e($it['icon'] ?: YxServiceCenter::groupIcon($it['_group'])) ?></span>
                                <div class="yx-svc-card-head">
                                    <h3><?= e($it['name']) ?></h3>
                                    <div class="yx-svc-card-meta">
                                        <span class="yx-svc-group"><?= e(YxServiceCenter::groupName($it['_group'])) ?></span>
                                        <?php if (!empty($it['badge'])): ?><span class="yx-svc-badge"><?= e($it['badge']) ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($it['subtitle'])): ?>
                                <p class="yx-svc-sub"><?= e($it['subtitle']) ?></p>
                            <?php endif; ?>
                            <?php if ($features): ?>
                                <ul class="yx-svc-feats">
                                    <?php foreach (array_slice($features, 0, 4) as $f): ?>
                                        <li><?= e($f) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="yx-svc-card-foot">
                                <div class="yx-svc-price">
                                    <strong><?= $bill === 'quote' ? '面议' : e(YxServiceCenter::priceLabel($it, true)) ?></strong>
                                    <?php if ($specs): ?><small><?= count($specs) ?> 档可选</small><?php endif; ?>
                                </div>
                                <span class="yx-svc-buy"><?= $bill === 'quote' ? '提交需求' : '立即购买' ?></span>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
