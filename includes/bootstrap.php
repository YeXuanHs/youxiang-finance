<?php
/**
 * 优享财务系统 · 统一入口引导
 * 所有页面首行都应 require 本文件。
 */

require_once __DIR__ . '/functions.php';
// 发行包密封数据（授权密钥与关键文案的密文），必须在 license.php 之前载入
require_once __DIR__ . '/pkgsecret.php';
// 授权中心本机的签发特权通道；发行包里不包含该文件，买家环境按不可用处理
if (is_file(__DIR__ . '/pkg_signer.php')) {
    require_once __DIR__ . '/pkg_signer.php';
}
// 签名授权文件（Ed25519 公钥验签，只验不签），安装向导与授权接口共用
require_once __DIR__ . '/pkglicense.php';

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
require_once __DIR__ . '/partner.php';
require_once __DIR__ . '/legal_doc.php';
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
// 侵权处置规则：检测 + 判定 + 证据固化 + 待审记录（不含任何对外发函能力）
require_once __DIR__ . '/infringe_legal.php';
// 电子印章（红色圆形公章，SVG + PNG）与律师函业务流程（人工编辑 / AI 辅助 / 法务复核 / 签发签章）
require_once __DIR__ . '/legal_seal.php';
require_once __DIR__ . '/legal_letter.php';
require_once __DIR__ . '/subsite.php';
require_once __DIR__ . '/ticket_report.php';
// 诉求性质判定（报案/涉警类诉求仅人工办理），供举报、侵权、漏洞等入口共用
require_once __DIR__ . '/dispute.php';
require_once __DIR__ . '/career.php';
require_once __DIR__ . '/news.php';
require_once __DIR__ . '/seo_push.php';

// 推广链接落地：任意页面带 ?aff= 都记录推广码（邀请链接落在门户首页）
YxAffiliate::captureVisitor();
