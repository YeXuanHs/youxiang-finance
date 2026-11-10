<?php
/**
 * 优享财务系统 · 统一入口引导
 * 所有页面首行都应 require 本文件。
 */

require_once __DIR__ . '/functions.php';

yx_bootstrap();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/edition.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/auth_modes.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/certificate.php';
require_once __DIR__ . '/service_center.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/offline_pay.php';
require_once __DIR__ . '/task.php';
require_once __DIR__ . '/upstream.php';
require_once __DIR__ . '/customer_upstream.php';
require_once __DIR__ . '/upstream_markup.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/api_gateway.php';
require_once __DIR__ . '/zjmf.php';
require_once __DIR__ . '/about.php';
require_once __DIR__ . '/banner.php';
require_once __DIR__ . '/ad.php';
require_once __DIR__ . '/marketing.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/developer.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/plugin_center.php';
require_once __DIR__ . '/agent_apply.php';
require_once __DIR__ . '/affiliate.php';
require_once __DIR__ . '/realname.php';
require_once __DIR__ . '/promoter.php';
require_once __DIR__ . '/checkin.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/agent.php';
require_once __DIR__ . '/infringe.php';
require_once __DIR__ . '/subsite.php';
require_once __DIR__ . '/ticket_report.php';
require_once __DIR__ . '/career.php';
require_once __DIR__ . '/news.php';
require_once __DIR__ . '/seo_push.php';

// 推广链接落地：任意页面带 ?aff= 都记录推广码（邀请链接落在门户首页）
YxAffiliate::captureVisitor();
