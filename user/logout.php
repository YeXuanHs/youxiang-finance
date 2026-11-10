<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
YxAuth::logoutCustomer();
redirect(url('user/login.php'));
