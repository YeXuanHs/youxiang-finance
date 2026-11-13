<?php
/**
 * 优享财务系统 · 授权模块加载器
 *
 * 1.6.4 起授权逻辑按发行形态拆成两套互不复用的实现：
 *   includes/license/official.php   正版发行包：三要素签名校验授权中心与安装授权码；
 *   includes/license/community.php  开源发行包：完全离线、无密钥、无签名的独立实现。
 *
 * 构建脚本会为每个发行包只保留其中一个模块文件，并把下方的默认模块名
 * 改写为该包对应的形态；运行期也可用常量 YX_LICENSE_MODULE 提前指定。
 * 两个模块都定义同一个 YxLicense 类（同名接口、不同实现），因此这里只允许
 * 载入一个，不能同时引入。
 */

if (!defined('YX_LICENSE_MODULE')) {
    define('YX_LICENSE_MODULE', 'community');
}

$yx_license_module = strtolower(trim((string) YX_LICENSE_MODULE));
if ($yx_license_module !== 'community') {
    $yx_license_module = 'official';
}

$yx_license_file = __DIR__ . '/license/' . $yx_license_module . '.php';
if (!is_file($yx_license_file)) {
    // 发行包只带一套实现：请求的形态缺失时退回另一套，避免整站因缺文件白屏。
    $yx_license_fallback = __DIR__ . '/license/' . ($yx_license_module === 'official' ? 'community' : 'official') . '.php';
    if (!is_file($yx_license_fallback)) {
        throw new RuntimeException('授权模块缺失：includes/license/official.php 与 includes/license/community.php 均不存在');
    }
    $yx_license_file = $yx_license_fallback;
}

require_once $yx_license_file;

unset($yx_license_module, $yx_license_file, $yx_license_fallback);
