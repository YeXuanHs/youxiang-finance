<?php
/**
 * 优享财务系统 · 全局配置（由安装向导生成于 2026-10-13 14:11:01）
 */

return [
    'app' => [
        'name' => '优享财务系统',
        'sub_name' => '业务与财务一体化管理平台',
        'version'  => '1.6.4',
        'build'    => '20261111',
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
        'lock_mode' => false,
        'official_api' => '',
        'query_path' => '/api/license.php',
        'cache_ttl' => 21600,
        'offline_ok' => true,
        'timeout' => 5,
    ],
    'store' => [
        'remote_api' => '',
        'commission' => 20,
        /* 发行包受控分发目录：必须位于站点根目录之外，且仅 root 可写。
           授权管理员通过 后台 → 商店管理 → 发行包分发 读取，前台不提供任何直链。 */
        'release_dir' => '',
        'key_dir'     => '',
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
