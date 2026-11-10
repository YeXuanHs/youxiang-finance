<?php
/**
 * 优享财务系统 · 授权码生成工具
 *
 * 本开源版已移除安装授权码校验：安装向导不再索要授权码，装完即为「企业版」。
 * 本工具用于兼容早前的正式版发行包——那套包在安装第 4 步会按当前域名离线
 * 复核授权码，用这里的码即可通过。
 *
 * 用法：
 *   php tools/gen_license.php example.com            # 正版产品标识（official）
 *   php tools/gen_license.php example.com community  # 开源版产品标识（community）
 *
 * 输出示例：
 *   域名     : example.com
 *   产品标识 : official
 *   授权码   : XXXX-XXXX-XXXX-XXXX
 *
 * 算法与 includes/license.php 的 YxLicense::packageKey() 完全一致：
 *   strtoupper(substr(hash_hmac('sha256', 'package|{product}|{domain}', SECRET), 0, 16))
 *   → 每 4 位一组，用 - 连接
 */

if (PHP_SAPI !== 'cli') {
    exit("该脚本仅支持命令行执行\n");
}

/* 主程序包产品密钥（与 includes/license.php 的 PACKAGE_SECRET 保持一致） */
const PKG_SECRET = 'YX-PKG-9F2C7A41D6B85E30A1C4F7D29B6E5A83C0D1F7E2';

$domain = trim((string)($argv[1] ?? ''));
$product = strtolower(trim((string)($argv[2] ?? 'official')));
if ($domain === '') {
    fwrite(STDERR, "用法：php tools/gen_license.php <域名> [official|community]\n");
    exit(1);
}
if ($product !== 'community') {
    $product = 'official';
}

/* 规范化域名：与系统内 normalize_domain() 完全同口径
   （去协议头 → 去路径/查询串/锚点 → 去端口 → 去首尾空白与点）
   注意：不剥离 www.，与系统保持一致，否则签出的码对不上 */
$domain = strtolower(trim($domain));
$domain = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $domain) ?? $domain;
$domain = preg_replace('#[/?\#].*$#', '', $domain) ?? $domain;
$domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
$domain = trim($domain, " \t\n\r\0\x0B.");

if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
    fwrite(STDERR, "域名格式不正确\n");
    exit(1);
}

$raw = strtoupper(substr(hash_hmac('sha256', 'package|' . $product . '|' . $domain, PKG_SECRET), 0, 16));

echo "域名     : {$domain}\n";
echo "产品标识 : {$product}\n";
echo "授权码   : " . implode('-', str_split($raw, 4)) . "\n";
