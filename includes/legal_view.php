<?php
/**
 * 优享财务系统 · 服务条款 / 隐私政策前台渲染
 *
 * terms.php 与 privacy.php 只是入口，正文、标题、版本与生效日期全部来自
 * yx_legal_doc（后台「系统 → 法律文档」维护），这里负责统一的版式与转义输出。
 *
 * 调用方需先加载 bootstrap.php 并设置 $legalSlot：
 *   $legalSlot = YxLegalDoc::TERMS;   // 或 YxLegalDoc::PRIVACY
 */
if (!isset($legalSlot) || !class_exists('YxLegalDoc') || !YxLegalDoc::isSlot((string)$legalSlot)) {
    http_response_code(404);
    exit;
}

$legalSlot = (string)$legalSlot;
$legalDoc  = YxLegalDoc::get($legalSlot);
$legalDate = YxLegalDoc::effectiveText($legalDoc);
$legalBody = YxLegalDoc::blocks($legalDoc['content']);
$legalUpdated = trim((string)$legalDoc['updated_at']);

$legalOtherSlot = $legalSlot === YxLegalDoc::TERMS ? YxLegalDoc::PRIVACY : YxLegalDoc::TERMS;
$legalOtherName = YxLegalDoc::defaultTitle($legalOtherSlot);

$pageTitle = $legalDoc['title'] . ' - ' . setting('site_name', config('app.name'));
require __DIR__ . '/header.php';
?>

<section class="section yx-legal">
    <div class="yx-legal-head">
        <p class="yx-legal-kicker">法律文档</p>
        <h1><?= e($legalDoc['title']) ?></h1>
        <p class="yx-legal-desc"><?= e(YxLegalDoc::SLOTS[$legalSlot]['tip']) ?></p>
        <div class="yx-legal-meta">
            <?php if ($legalDoc['version'] !== ''): ?>
                <span><b>版本</b>v<?= e($legalDoc['version']) ?></span>
            <?php endif; ?>
            <?php if ($legalDate !== ''): ?>
                <span><b>生效日期</b><?= e($legalDate) ?></span>
            <?php endif; ?>
            <?php if ($legalUpdated !== ''): ?>
                <span><b>最近更新</b><?= e($legalUpdated) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <article class="card yx-legal-body">
        <?php if (!$legalBody): ?>
            <div class="empty">该文档内容尚未发布，请稍后再访问，或先通过工单联系我们。</div>
        <?php else: ?>
            <?php foreach ($legalBody as $block): ?>
                <?php if ($block['type'] === 'heading'): ?>
                    <h2><?= e($block['text']) ?></h2>
                <?php elseif ($block['type'] === 'list'): ?>
                    <ul>
                        <?php foreach ($block['items'] as $item): ?>
                            <li><?= e($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p><?= nl2br(e($block['text'])) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>

    <div class="yx-legal-foot">
        <a href="<?= e(YxLegalDoc::url($legalOtherSlot)) ?>">查看《<?= e($legalOtherName) ?>》</a>
        <a href="<?= e(url('user/register.php')) ?>">返回注册</a>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>