<?php
/**
 * 优享财务系统 · 合作伙伴 / 服务网络
 *
 * 展示内容全部来自后台「合作伙伴」维护（yx_partner），前台只读。
 * 样式见 assets/css/user.css（uc-partner-*）。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();

$partners = YxPartner::active();

$pageTitle  = '合作伙伴';
$userActive = 'partners';
$userTitle  = '合作伙伴 · 服务网络';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="uc-page-head">
    <div class="uc-page-head-txt">
        <h1>合作伙伴 · 服务网络</h1>
        <p>与我们共同提供产品、交付与本地化服务的合作机构。</p>
    </div>
    <?php if (YxEdition::can('ticket')): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('user/tickets.php?new=1')) ?>">申请合作</a>
    <?php endif; ?>
</div>

<?php if (!$partners): ?>
    <div class="card">
        <div class="uc-partner-empty">
            <span class="uc-partner-empty-ico" aria-hidden="true">◎</span>
            <h2>暂无合作伙伴信息</h2>
            <p>合作机构资料正在整理中，稍后再来看看。</p>
            <?php if (YxEdition::can('ticket')): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(url('user/tickets.php?new=1')) ?>">通过工单联系我们</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="section-head">
            <h2>合作机构（共 <?= count($partners) ?> 家）</h2>
            <span class="muted small">按展示顺序排列</span>
        </div>
        <div class="uc-partner-grid">
            <?php foreach ($partners as $p): ?>
                <?php
                $logo = YxPartner::logoUrl($p);
                $href = YxPartner::href((string)$p['url']);
                $desc = trim((string)$p['description']);
                ?>
                <article class="uc-partner-card">
                    <div class="uc-partner-logo<?= $logo === '' ? ' is-text' : '' ?>">
                        <?php if ($logo !== ''): ?>
                            <img src="<?= e($logo) ?>" alt="<?= e((string)$p['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span aria-hidden="true"><?= e(YxPartner::initial($p)) ?></span>
                        <?php endif; ?>
                    </div>
                    <h3><?= e((string)$p['name']) ?></h3>
                    <?php if ($desc !== ''): ?>
                        <p><?= e($desc) ?></p>
                    <?php endif; ?>
                    <?php if ($href !== ''): ?>
                        <a class="uc-partner-link" href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer">访问机构 <span aria-hidden="true">↗</span></a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
