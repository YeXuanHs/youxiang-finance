<?php
/**
 * 优享财务系统 · 新闻公告中心（列表）
 *
 * 分类筛选 + 搜索结果 + 浏览量展示；置顶文章由 YxNews::published() 排序保证。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

if (!YxNews::isEnabled()) {
    http_response_code(404);
    $pageTitle = '新闻公告';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card" style="margin:40px 0"><div class="empty">新闻公告中心暂未开放</div>'
        . '<p style="text-align:center"><a class="btn btn-primary" href="' . e(url('index.php')) . '">返回首页</a></p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$cat = (string)($_GET['cat'] ?? '');
if ($cat !== '' && !isset(YxNews::CATEGORIES[$cat])) {
    $cat = '';
}
$kw = trim((string)($_GET['kw'] ?? ''));

$filter = ['category' => $cat, 'kw' => $kw];
$total  = YxNews::count($filter);
$pg     = paginate($total, 10);

$list = YxNews::published($filter, $pg['per_page'], $pg['offset']);
$counts = YxNews::categoryCounts();

$pageTitle = '新闻公告中心';
require __DIR__ . '/includes/header.php';
?>

<section class="zd-section zd-sec-gray" style="padding:50px 0 30px">
    <div class="portal-container">
        <span class="news-eyebrow">NEWS &amp; NOTICE</span>
        <h1 style="margin:12px 0 8px">新闻公告中心</h1>
        <p class="muted" style="margin:0;line-height:1.9">产品更新、服务公告与公司动态集中发布；每篇文章均展示浏览量，可据此了解关注度。</p>
        <div class="news-stats">
            <div><strong><?= number_format(YxNews::count()) ?></strong><span>篇已发布</span></div>
            <div><strong><?= e(YxNews::viewsText(YxNews::totalViews())) ?></strong><span>累计浏览</span></div>
        </div>
    </div>
</section>

<section class="section" style="padding-top:26px">
    <div class="news-toolbar">
        <div class="news-cats">
            <a class="<?= $cat === '' ? 'active' : '' ?>" href="<?= e(url('news.php')) ?>">全部 <em><?= number_format(array_sum($counts)) ?></em></a>
            <?php foreach (YxNews::CATEGORIES as $k => $name): ?>
                <a class="<?= $cat === $k ? 'active' : '' ?>"
                   href="<?= e(url('news.php?cat=' . urlencode($k))) ?>"><?= e($name) ?> <em><?= (int)($counts[$k] ?? 0) ?></em></a>
            <?php endforeach; ?>
        </div>
        <form method="get" class="news-search">
            <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
            <input type="search" name="kw" value="<?= e($kw) ?>" placeholder="搜索标题或内容">
            <button class="btn btn-primary btn-sm" type="submit">搜索</button>
        </form>
    </div>

    <?php if ($kw !== ''): ?>
        <p class="muted small">关键词「<?= e($kw) ?>」共找到 <?= (int)$total ?> 篇文章<?= $cat !== '' ? '（分类：' . e(YxNews::categoryName($cat)) . '）' : '' ?> · <a href="<?= e(url('news.php' . ($cat !== '' ? '?cat=' . urlencode($cat) : ''))) ?>">清除搜索</a></p>
    <?php endif; ?>

    <?php if (!$list): ?>
        <div class="card"><div class="empty">暂无符合条件的文章</div></div>
    <?php else: ?>
        <div class="news-list">
            <?php foreach ($list as $n): ?>
                <?php $link = url('news_detail.php?id=' . (int)$n['id']); ?>
                <article class="news-item">
                    <?php if (trim((string)$n['cover']) !== ''): ?>
                        <a class="news-cover" href="<?= e($link) ?>">
                            <img src="<?= e(yx_upload_url((string)$n['cover'])) ?>" alt="<?= e($n['title']) ?>" loading="lazy">
                        </a>
                    <?php endif; ?>
                    <div class="news-main">
                        <div class="news-meta">
                            <span class="badge <?= e(YxNews::categoryBadge((string)$n['category'])) ?>"><?= e(YxNews::categoryName((string)$n['category'])) ?></span>
                            <?php if ((int)$n['is_top'] === 1): ?><span class="badge badge-bad">置顶</span><?php endif; ?>
                            <span class="muted small"><?= e(substr((string)($n['published_at'] ?: $n['created_at']), 0, 10)) ?></span>
                        </div>
                        <h3><a href="<?= e($link) ?>"><?= e($n['title']) ?></a></h3>
                        <?php if (trim((string)$n['summary']) !== ''): ?>
                            <p class="news-summary"><?= e($n['summary']) ?></p>
                        <?php endif; ?>
                        <div class="news-foot">
                            <span class="news-views" title="浏览量">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                                <?= e(YxNews::viewsText((int)$n['views'])) ?> 次浏览
                            </span>
                            <a class="news-more" href="<?= e($link) ?>">阅读全文 →</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?= pager($pg) ?>
    <?php endif; ?>
</section>

<style>
.news-eyebrow{display:inline-block;font-size:12px;letter-spacing:.08em;color:#1c7ed6;background:#eef4ff;border-radius:20px;padding:4px 12px}
.news-stats{display:flex;gap:30px;margin-top:20px}
.news-stats div{display:flex;flex-direction:column}
.news-stats strong{font-size:24px;color:#1c7ed6}
.news-stats span{font-size:12px;color:#64748b}
.news-toolbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.news-cats{display:flex;gap:8px;flex-wrap:wrap}
.news-cats a{padding:6px 14px;border-radius:20px;background:#f4f7fc;color:#334155;font-size:13px;text-decoration:none}
.news-cats a.active{background:#1c7ed6;color:#fff}
.news-cats a em{font-style:normal;font-size:11px;opacity:.75;margin-left:2px}
.news-search{display:flex;gap:8px}
.news-search input{min-width:200px}
.news-list{display:grid;gap:14px}
.news-item{display:flex;gap:18px;border:1px solid #e6ecf5;border-radius:14px;padding:18px;background:#fff;transition:border-color .2s,box-shadow .2s}
.news-item:hover{border-color:#c8d8f0;box-shadow:0 8px 24px rgba(28,126,214,.06)}
.news-cover{flex:0 0 180px;height:120px;border-radius:10px;overflow:hidden;background:#f4f7fc}
.news-cover img{width:100%;height:100%;object-fit:cover;display:block}
.news-main{flex:1;min-width:0}
.news-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px}
.news-main h3{margin:0 0 6px;font-size:16px;line-height:1.5}
.news-main h3 a{color:inherit;text-decoration:none}
.news-main h3 a:hover{color:#1c7ed6}
.news-summary{margin:0 0 10px;color:#64748b;font-size:13px;line-height:1.8;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.news-foot{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#64748b}
.news-views{display:inline-flex;align-items:center;gap:4px}
.news-more{color:#1c7ed6;text-decoration:none}
@media(max-width:720px){.news-item{flex-direction:column}.news-cover{flex:none;width:100%;height:160px}}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
