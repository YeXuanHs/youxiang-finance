<?php
/**
 * 优享财务系统 · 全局配置（由安装向导生成于 2026-10-13 14:11:01）
 */

return [
    'app' => [
        'name' => '优享财务系统',
        'sub_name' => '业务与财务一体化管理平台',
        'version'  => '1.6.2',
        'build'    => '20261103',
        'debug' => false,
        'timezone' => 'Asia/Shanghai',
        'key' => '',
        'url' => '',
        'installed' => false,
    ],
    'db' => [
        'driver' => 'sqlite',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'youxiang_finance',
        'user' => 'youxiang_finance',
        'pass' => '',
        'charset' => 'utf8mb4',
        'prefix' => 'yx_',
        'path' => 'runtime/youxiang.db',
    ],
    'edition' => [
        'current' => 'enterprise',
        'allow_switch' => true,
    ],
    'license' => [
        'mode' => 'official',
        'official_api' => 'https://license.youxiang.local',
        'query_path' => '/api/license.php',
        'cache_ttl' => 21600,
        'offline_ok' => true,
        'timeout' => 5,
    ],
    'store' => [
        'remote_api' => '',
        'commission' => 20,
    ],
    'security' => [
        'admin_login_limit' => 5,
        'login_lock_seconds' => 900,
        'session_ttl' => 7200,
        'password_algo' => '2y',
    ],
    'finance' => [
        'currency' => 'CNY',
        'currency_symbol' => '¥',
        'invoice_prefix' => 'INV',
        'order_prefix' => 'YX',
        'service_prefix' => 'SVC',
    ],
];
