<?php
/**
 * 优享财务系统 · 用户后台退出（/panel/）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
YxAuth::logoutCustomer();
redirect(url('panel/login.php'));
