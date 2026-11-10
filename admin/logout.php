<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (YxAuth::isAdmin()) {
    log_action('admin.logout', (string)($_SESSION['admin_name'] ?? ''));
}
YxAuth::logoutAdmin();
redirect(url('admin/login.php'));
