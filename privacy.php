<?php
/**
 * 优享财务系统 · 隐私政策
 * 内容由后台「系统 → 法律文档」维护，本文件只负责入口与渲染。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$legalSlot = YxLegalDoc::PRIVACY;
require __DIR__ . '/includes/legal_view.php';