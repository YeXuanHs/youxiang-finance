<?php
/**
 * 优享财务系统 · 分站退出
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
YxAuth::logoutSubsite();
flash('已退出分站后台');
redirect(url('subsite/login.php'));
