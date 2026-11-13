<?php
/**
 * 优享财务系统 · 关于我们（内容全部来自后台「关于我们」配置）
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$show = YxAbout::enabled();
$title = trim(YxAbout::get('about_page_title')) ?: '关于我们';
$company = trim(YxAbout::get('about_company')) ?: setting('site_name', config('app.name'));

if (!$show) {
    http_response_code(404);
    $pageTitle = $title;
    require __DIR__ . '/includes/header.php';
    echo '<div class="card" style="margin:40px 0"><div class="empty">该页面暂未开放</div>'
        . '<p style="text-align:center"><a class="btn btn-primary" href="' . e(url('index.php')) . '">返回首页</a></p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$slogan    = trim(YxAbout::get('about_slogan'));
$intro     = YxAbout::paragraphs(YxAbout::get('about_intro'));
$whoTitle  = trim(YxAbout::get('about_who_title'));
$whoText   = YxAbout::paragraphs(YxAbout::get('about_who_text'));
$brandText = YxAbout::paragraphs(YxAbout::get('about_brand_text'));

$capabilities = YxAbout::items('capability');
$stats        = YxAbout::items('stat');
$values       = YxAbout::items('value');
$milestones   = YxAbout::items('milestone');
$partners     = YxAbout::items('partner');
$reviews      = YxAbout::items('review');
$benefits     = YxAbout::items('benefit');

$joinTitle = trim(YxAbout::get('about_join_title'));
$joinText  = YxAbout::paragraphs(YxAbout::get('about_join_text'));
$joinLink  = trim(YxAbout::get('about_join_link'));

$contacts  = YxAbout::contacts();
$footNote  = trim(YxAbout::get('about_footer_note'));

$pageTitle = $title . ' - ' . $company;
require __DIR__ . '/includes/header.php';
?>

<section class="hero" style="padding:52px 0 26px">
    <h1><?= e($title) ?></h1>
    <?php if ($slogan !== ''): ?><p><?= e($slogan) ?></p><?php endif; ?>
</section>

<?php if ($intro): ?>
<section class="section" style="padding-top:10px">
    <div class="card">
        <?php foreach ($intro as $p): ?>
            <p style="margin:0 0 12px;line-height:1.9"><?= nl2br(e($p)) ?></p>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($capabilities): ?>
<section class="section">
    <div class="section-head"><h2>我们能做什么</h2></div>
    <div class="app-grid">
        <?php foreach ($capabilities as $c): ?>
            <div class="app-card">
                <header>
                    <div class="app-icon"><?= e($c['icon'] ?: mb_substr((string)$c['title'], 0, 1)) ?></div>
                    <h3><?= e($c['title']) ?></h3>
                </header>
                <?php if (trim((string)$c['content']) !== ''): ?>
                    <div class="desc"><?= nl2br(e($c['content'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($stats): ?>
<section class="section">
    <div class="grid-4">
        <?php foreach ($stats as $s): ?>
            <div class="stat accent">
                <span><?= e($s['subtitle']) ?></span>
                <strong><?= e($s['title']) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($whoTitle !== '' || $whoText || $brandText): ?>
<section class="section">
    <?php if ($whoTitle !== ''): ?><div class="section-head"><h2><?= e($whoTitle) ?></h2></div><?php endif; ?>
    <div class="grid-2">
        <?php if ($whoText): ?>
            <div class="card">
                <?php foreach ($whoText as $p): ?>
                    <p style="margin:0 0 12px;line-height:1.9"><?= nl2br(e($p)) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($brandText): ?>
            <div class="card">
                <h2>品牌释义</h2>
                <?php foreach ($brandText as $p): ?>
                    <p style="margin:0 0 12px;line-height:1.9"><?= nl2br(e($p)) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($values): ?>
<section class="section">
    <div class="section-head"><h2>我们的理念</h2></div>
    <div class="grid-3">
        <?php foreach ($values as $i => $v): ?>
            <div class="card">
                <div class="muted small mono"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
                <h3 style="margin:6px 0 8px"><?= e($v['title']) ?></h3>
                <?php if (trim((string)$v['content']) !== ''): ?>
                    <p class="muted" style="margin:0;line-height:1.8"><?= nl2br(e($v['content'])) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($milestones): ?>
<section class="section">
    <div class="section-head"><h2>发展历程</h2></div>
    <div class="timeline">
        <?php foreach ($milestones as $m): ?>
            <div class="timeline-item">
                <div class="timeline-year"><?= e($m['subtitle']) ?></div>
                <div class="timeline-body">
                    <h3><?= e($m['title']) ?></h3>
                    <?php if (trim((string)$m['content']) !== ''): ?>
                        <p class="muted" style="margin:4px 0 0;line-height:1.8"><?= nl2br(e($m['content'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($partners): ?>
<section class="section">
    <div class="section-head">
        <h2>合作伙伴</h2>
        <span class="muted small">共 <?= count($partners) ?> 家</span>
    </div>
    <div class="partner-grid">
        <?php foreach ($partners as $p): ?>
            <?php $link = trim((string)$p['link']); ?>
            <?php if ($link !== ''): ?>
                <a class="partner-chip" href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($p['title']) ?></a>
            <?php else: ?>
                <span class="partner-chip"><?= e($p['title']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($reviews): ?>
<section class="section" id="reviews">
    <div class="section-head">
        <h2>客户评价</h2>
        <span class="muted small">共 <?= count($reviews) ?> 位客户</span>
    </div>
    <div class="review-grid">
        <?php foreach ($reviews as $r): ?>
            <?php
            $meta    = YxAbout::reviewMeta($r);
            $link    = trim((string)$r['link']);
            $avatar  = trim((string)$r['icon']) ?: mb_substr((string)$r['title'], 0, 1);
            ?>
            <div class="review-card">
                <div class="review-stars" aria-label="评分 <?= (int)$meta['rating'] ?> 分">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="<?= $i <= $meta['rating'] ? 'on' : 'off' ?>">★</span>
                    <?php endfor; ?>
                </div>
                <p class="review-text"><?= nl2br(e($r['content'])) ?></p>
                <div class="review-user">
                    <span class="review-avatar"><?= e($avatar) ?></span>
                    <span class="review-info">
                        <strong><?= e($r['title']) ?></strong>
                        <?php if ($meta['version'] !== ''): ?>
                            <span class="review-version"><?= e($meta['version']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ($link !== ''): ?>
                        <a class="review-link" href="<?= e($link) ?>" target="_blank" rel="noopener">查看</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php
$joinBenefits = YxAbout::items('benefit');
$joinJobs     = YxCareer::jobs(6);
$joinMail     = YxCareer::jobMailbox();
$joinOpen     = YxCareer::openForApply();
?>
<?php if ($joinTitle !== '' || $joinText || $joinBenefits || $joinJobs || $joinLink !== ''): ?>
<section class="section" id="careers">
    <div class="section-head">
        <h2><?= e($joinTitle !== '' ? $joinTitle : '加入我们') ?></h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('career.php')) ?>">查看全部职位 →</a>
    </div>

    <div class="card about-join">
        <?php if ($joinText): ?>
            <p class="about-join-lead"><?= nl2br(e(implode(' ', $joinText))) ?></p>
        <?php endif; ?>

        <?php if ($joinBenefits): ?>
            <div class="grid-3" style="margin-top:16px">
                <?php foreach ($joinBenefits as $i => $b): ?>
                    <div class="about-join-benefit">
                        <span class="about-join-no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <strong><?= e($b['title']) ?></strong>
                        <?php if (trim((string)$b['content']) !== ''): ?>
                            <p class="muted small" style="margin:4px 0 0;line-height:1.8"><?= nl2br(e($b['content'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($joinJobs): ?>
            <div class="about-join-jobs">
                <div class="about-join-jobs-head">
                    <b>在招职位</b>
                    <span class="muted small">共 <?= count(YxCareer::jobs()) ?> 个 · 线上投递简历</span>
                </div>
                <?php foreach ($joinJobs as $job): ?>
                    <a class="about-join-job" href="<?= e(url('career.php#job-' . (int)$job['id'])) ?>">
                        <span class="about-join-job-title"><?= e($job['title']) ?></span>
                        <span class="about-join-job-meta">
                            <?php if (trim((string)$job['city']) !== ''): ?><?= e($job['city']) ?> · <?php endif; ?>
                            <?= e(YxCareer::jobTypeName((string)$job['job_type'])) ?>
                            <?php if (trim((string)$job['experience']) !== ''): ?> · <?= e($job['experience']) ?><?php endif; ?>
                        </span>
                        <em><?= e(trim((string)$job['salary']) !== '' ? $job['salary'] : '面议') ?></em>
                        <span class="about-join-job-go">投递 →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:18px">
            <?php if ($joinOpen): ?>
                <a class="btn btn-primary" href="<?= e(url('career.php#apply')) ?>">投递简历</a>
                <a class="btn btn-ghost" href="<?= e(url('career.php#query')) ?>">查询投递进度</a>
            <?php endif; ?>
            <?php if ($joinLink !== ''): ?>
                <a class="btn <?= $joinOpen ? 'btn-ghost' : 'btn-primary' ?>" href="<?= e($joinLink) ?>" target="_blank" rel="noopener">外部招聘链接</a>
            <?php endif; ?>
            <?php if (!$joinOpen && $joinLink === ''): ?>
                <a class="btn btn-primary" href="<?= e(url('career.php')) ?>">查看职位与投递方式</a>
            <?php endif; ?>
            <?php if ($joinMail !== ''): ?>
                <span class="muted small" style="align-self:center">简历接收邮箱：<?= e($joinMail) ?></span>
            <?php endif; ?>
        </div>

        <?php if (!$joinJobs && !$joinOpen): ?>
            <p class="muted small" style="margin-top:12px">当前暂无在招职位，可先通过 <a href="<?= e(url('user/tickets.php')) ?>">工单</a> 联系我们投递简历。</p>
        <?php endif; ?>
    </div>
</section>

<style>
.about-join-lead{margin:0;line-height:1.9;color:#334155}
.about-join-benefit{padding:12px 14px;border:1px solid #eef2f8;border-radius:12px;background:#fbfcfe}
.about-join-no{display:block;font-size:12px;color:#94a3b8;font-family:ui-monospace,monospace;margin-bottom:4px}
.about-join-jobs{margin-top:20px;border-top:1px solid #eef2f8;padding-top:16px}
.about-join-jobs-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
.about-join-job{display:flex;align-items:center;gap:14px;padding:12px 14px;border:1px solid #e6ecf5;border-radius:12px;margin-bottom:8px;color:inherit;text-decoration:none;transition:border-color .2s,background .2s}
.about-join-job:hover{border-color:#1c7ed6;background:#f7faff;text-decoration:none}
.about-join-job-title{font-weight:600;flex:1 1 auto;min-width:140px}
.about-join-job-meta{color:#64748b;font-size:12px;flex:0 1 auto}
.about-join-job em{font-style:normal;color:#e8590c;font-size:13px;white-space:nowrap}
.about-join-job-go{color:#1c7ed6;font-size:12px;white-space:nowrap}
@media(max-width:640px){.about-join-job{flex-wrap:wrap;gap:6px}.about-join-job-meta{order:3;flex-basis:100%}}
</style>
<?php endif; ?>

<?php if ($contacts): ?>
<section class="section">
    <div class="section-head"><h2>联系我们</h2></div>
    <div class="grid-2">
        <div class="card">
            <table class="data">
                <tr><th style="width:120px">公司名称</th><td><?= e($company) ?></td></tr>
                <?php foreach ($contacts as $label => $value): ?>
                    <tr><th><?= e($label) ?></th><td class="wrap"><?= e($value) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="card">
            <h2>需要帮助？</h2>
            <p class="muted">产品咨询、方案沟通或售后问题，都可以直接提交工单，我们会尽快回复。</p>
            <div class="form-actions">
                <a class="btn btn-primary" href="<?= e(url('user/tickets.php')) ?>">提交工单</a>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($footNote !== ''): ?>
<section class="section" style="padding-top:0">
    <p class="muted small" style="text-align:center;line-height:1.9"><?= nl2br(e($footNote)) ?></p>
</section>
<?php endif; ?>

<?php if (!$intro && !$capabilities && !$stats && !$values && !$milestones && !$partners && !$reviews && !$contacts): ?>
<section class="section">
    <div class="card"><div class="empty">「关于我们」内容尚未填写，请登录后台「站务管理 → 关于我们」补充。</div></div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
