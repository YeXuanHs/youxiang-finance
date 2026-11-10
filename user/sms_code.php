<?php
/**
 * 优享财务系统 · 获取注册短信验证码（AJAX，兼容旧地址）
 *
 * 实际逻辑已合并到 user/verify_code.php（短信 / 邮箱共用），
 * 保留本文件是为了不破坏已经缓存的页面与外部调用。
 */
$_POST['type'] = 'sms';
require __DIR__ . '/verify_code.php';
