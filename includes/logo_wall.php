<?php
/**
 * 优享财务系统 · 登录 / 注册页 LOGO 墙
 *
 * 位置：登录 / 注册卡片上方（.fu-auth-main 顶部）。
 * 数据来源（任选其一，可混用）：
 *   1. 后台「系统设置 → LOGO 墙」上传的图片（存 uploads/logo-wall/，记录在 setting.logo_wall_images）
 *   2. 直接把图片放入 assets/logo/wall/ 目录，自动收录（文件名即展示名称，可用 - / _ 分隔）
 * 关闭展示：后台取消勾选「展示 LOGO 墙」，或设置 setting.logo_wall_show = 0。
 *
 * 可选变量：
 *   $wallTitle  板块标题（默认「合作与服务网络」）
 */
if (!defined('YX_ROOT')) {
    return;
}
if (!yx_wall_enabled()) {
    return;
}

$wallTitle = trim((string)($wallTitle ?? setting('logo_wall_title', '合作伙伴 · 服务网络')));
$wallLogos = yx_wall_logos();
?>
<div class="yx-auth-wall">
    <?php if ($wallTitle !== ''): ?>
        <p class="yx-auth-wall-title"><?= e($wallTitle) ?></p>
    <?php endif; ?>
    <ul class="yx-auth-wall-list">
        <?php foreach ($wallLogos as $w): ?>
            <li>
                <img src="<?= e(yx_wall_img_url($w['img'])) ?>" alt="<?= e($w['name'] !== '' ? $w['name'] : 'LOGO') ?>"
                     title="<?= e($w['name']) ?>" loading="lazy">
            </li>
        <?php endforeach; ?>
    </ul>
</div>
