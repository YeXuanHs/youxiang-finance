<?php
/**
 * 优享财务系统 · 代理退出
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
YxAuth::logoutAgent();
redirect(url('agent/login.php'));
