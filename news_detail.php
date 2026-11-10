<?php
/**
 * 优享财务系统 · 新闻公告详情
 *
 * 访问一次即计一次浏览：同一访客（cookie + UA 指纹）30 分钟内对同一篇只计一次，
 * 计数据在 yx_news.views 上原子自增，列表页与详情页展示同一数值。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$id = (int)($_GET['id'] ?? 0);
$news = YxNews::find($id);

if (!$news || (int)$news['status'] !== 1 || !YxNews::isEnabled()) {
    http_response_code(404);
    $pageTitle = '文章不存在';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card" style="margin:40px 0"><div class="empty">文章不存在或已下线</div>'
        . '<p style="text-align:center"><a class="btn btn-primary" href="' . e(url('news.php')) . '">返回新闻公告中心</a></p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// 浏览量：先计数再展示，保证本次访问计入
$views = YxNews::hit($id);

$publishedAt = (string)($news['published_at'] ?: $news['created_at']);
$related = YxNews::published(['category' => (string)$news['category']], 6);
$related = array_values(array_filter($related, static function ($r) use ($id) {
    return (int)$r['id'] !== $id;
}));
$related = array_slice($related, 0, 4);
if (!$related) {
    $related = array_slice(array_values(array_filter(YxNews::published([], 6), static function ($r) use ($id) {
        return (int)$r['id'] !== $id;
    })), 0, 4);
}

$tags = array_values(array_filter(array_map('trim', explode(',', (string)$news['tags']))));

$pageTitle = $news['title'] . ' - 新闻公告';
require __DIR__ . '/includes/header.php';
?>

<article class="section news-detail" style="padding-top:26px">
    <nav class="news-crumb">
        <a href="<?= e(url('index.php')) ?>">首页</a>
        <span>/</span>
        <a href="<?= e(url('news.php')) ?>">新闻公告中心</a>
        <span>/</span>
        <a href="<?= e(url('news.php?cat=' . urlencode((string)$news['category']))) ?>"><?= e(YxNews::categoryName((string)$news['category'])) ?></a>
    </nav>

    <div class="grid-2 news-detail-wrap">
        <div class="card news-article">
            <div class="news-meta">
                <span class="badge <?= e(YxNews::categoryBadge((string)$news['category'])) ?>"><?= e(YxNews::categoryName((string)$news['category'])) ?></span>
                <?php if ((int)$news['is_top'] === 1): ?><span class="badge badge-bad">置顶</span><?php endif; ?>
                <span class="muted small"><?= e(substr($publishedAt, 0, 16)) ?></span>
            </div>
            <h1><?= e($news['title']) ?></h1>

            <div class="news-info-bar">
                <span class="news-views" title="浏览量">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                    浏览量 <strong><?= number_format($views) ?></strong>
                </span>
                <?php if (trim((string)$news['source']) !== ''): ?><span>来源：<?= e($news['source']) ?></span><?php endif; ?>
                <span>发布于 <?= e(substr($publishedAt, 0, 10)) ?></span>
            </div>

            <?php if (trim((string)$news['cover']) !== ''): ?>
                <div class="news-detail-cover"><img src="<?= e(yx_upload_url((string)$news['cover'])) ?>" alt="<?= e($news['title']) ?>"></div>
            <?php endif; ?>

            <?php if (trim((string)$news['summary']) !== ''): ?>
                <p class="news-detail-summary"><?= nl2br(e($news['summary'])) ?></p>
            <?php endif; ?>

            <div class="news-content">
                <?= YxNews::render((string)$news['content']) ?>
            </div>

            <?php if ($tags): ?>
                <div class="news-tags">
                    <?php foreach ($tags as $tg): ?>
                        <a href="<?= e(url('news.php?kw=' . urlencode($tg))) ?>">#<?= e($tg) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-actions" style="margin-top:22px">
                <a class="btn btn-ghost" href="<?= e(url('news.php')) ?>">← 返回列表</a>
                <a class="btn btn-primary" href="<?= e(url('news.php?cat=' . urlencode((string)$news['category']))) ?>">查看更多<?= e(YxNews::categoryName((string)$news['category'])) ?></a>
            </div>
        </div>

        <aside class="news-aside">
            <div class="card">
                <h3 style="margin-top:0">热门阅读</h3>
                <?php $hot = YxNews::published([], 6); ?>
                <?php if (!$hot): ?>
                    <div class="empty">暂无内容</div>
                <?php else: ?>
                    <ol class="news-hot">
                        <?php foreach ($hot as $i => $h): ?>
                            <li>
                                <a href="<?= e(url('news_detail.php?id=' . (int)$h['id'])) ?>"><?= e($h['title']) ?></a>
                                <span class="muted small"><?= e(YxNews::viewsText((int)$h['views'])) ?> 次浏览</span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>

            <?php if ($related): ?>
                <div class="card">
                    <h3 style="margin-top:0">相关文章</h3>
                    <ul class="news-related">
                        <?php foreach ($related as $r): ?>
                            <li>
                                <a href="<?= e(url('news_detail.php?id=' . (int)$r['id'])) ?>"><?= e($r['title']) ?></a>
                                <em class="muted small"><?= e(substr((string)($r['published_at'] ?: $r['created_at']), 0, 10)) ?></em>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-top:0">投递简历</h3>
                <p class="muted small" style="line-height:1.8">我们在持续招聘产品、研发、测试与市场岗位，欢迎加入我们。</p>
                <a class="btn btn-primary btn-sm" href="<?= e(url('career.php')) ?>">查看在招职位 →</a>
            </div>
        </aside>
    </div>
</article>

<style>
.news-crumb{display:flex;gap:8px;align-items:center;font-size:12px;color:#94a3b8;margin-bottom:14px;flex-wrap:wrap}
.news-crumb a{color:#64748b;text-decoration:none}
.news-crumb a:hover{color:#1c7ed6}
.news-detail-wrap{grid-template-columns:minmax(0,1fr) 320px;align-items:start}
.news-article h1{font-size:24px;line-height:1.45;margin:10px 0 12px}
.news-info-bar{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#64748b;padding-bottom:14px;border-bottom:1px solid #eef2f8;margin-bottom:18px}
.news-views{display:inline-flex;align-items:center;gap:5px}
.news-views strong{color:#1c7ed6;font-size:13px}
.news-detail-cover{margin:0 0 18px;border-radius:12px;overflow:hidden;background:#f4f7fc}
.news-detail-cover img{width:100%;display:block}
.news-detail-summary{background:#f7faff;border-left:3px solid #1c7ed6;padding:12px 14px;border-radius:0 8px 8px 0;color:#334155;line-height:1.9;font-size:14px}
.news-content{font-size:14px;line-height:2;color:#1f2937}
.news-content h3{margin:22px 0 10px;font-size:16px}
.news-content p{margin:0 0 14px}
.news-content ul{margin:0 0 14px;padding-left:22px}
.news-content li{margin-bottom:6px}
.news-tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
.news-tags a{font-size:12px;color:#1c7ed6;background:#eef4ff;padding:4px 10px;border-radius:20px;text-decoration:none}
.news-aside{display:grid;gap:14px}
.news-hot{margin:0;padding-left:20px;font-size:13px;line-height:1.7}
.news-hot li{margin-bottom:10px}
.news-hot a{color:#1f2937;text-decoration:none;display:block}
.news-hot a:hover{color:#1c7ed6}
.news-hot span{font-size:11px;color:#94a3b8}
.news-related{list-style:none;margin:0;padding:0;font-size:13px}
.news-related li{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px dashed #eef2f8}
.news-related li:last-child{border-bottom:0}
.news-related a{color:#1f2937;text-decoration:none}
.news-related a:hover{color:#1c7ed6}
.news-related em{font-style:normal;white-space:nowrap;color:#94a3b8;font-size:11px}
@media(max-width:900px){.news-detail-wrap{grid-template-columns:1fr}}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
