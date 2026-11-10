<?php
/**
 * 优享财务系统 · 公共函数库
 */

if (!defined('YX_ROOT')) {
    define('YX_ROOT', dirname(__DIR__));
}

/** 载入配置、初始化时区与数据库、开启会话 */
function yx_bootstrap(): array
{
    static $conf = null;
    if ($conf !== null) {
        return $conf;
    }

    $confFile = YX_ROOT . '/includes/config.php';
    // 允许测试脚本通过常量注入配置（tools/selftest.php 使用独立临时库，不影响正式数据）
    if (defined('YX_CONF_OVERRIDE') && is_array(YX_CONF_OVERRIDE)) {
        $conf = YX_CONF_OVERRIDE;
    } elseif (is_file($confFile)) {
        $conf = require $confFile;
    } else {
        http_response_code(500);
        exit('缺少 includes/config.php');
    }

    date_default_timezone_set($conf['app']['timezone'] ?? 'Asia/Shanghai');

    require_once YX_ROOT . '/includes/db.php';
    YxDb::configure($conf['db']);

    if ($conf['app']['debug'] ?? false) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
        ini_set('display_errors', '0');
    }

    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        // 会话 ID 只接受 32 位以内的字母数字（PHP 默认格式）：外部传入的畸形/超长 ID
        // 会被拼成目录外的文件名或超长文件名，导致 session_start 警告与「刷新即丢会话」
        $sid = (string)($_COOKIE['YXSESSID'] ?? '');
        if ($sid !== '' && !preg_match('/^[A-Za-z0-9,-]{1,40}$/', $sid)) {
            unset($_COOKIE['YXSESSID']);
            if (headers_sent() === false) {
                setcookie('YXSESSID', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
            }
        }

        session_name('YXSESSID');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // 会话目录：优先站点私有目录 runtime/session
        // 原因：PHP 默认 save_path 为 /tmp，若站点以非 www 用户运行（或 /tmp 被安全策略加固），
        // 写会话会失败，表现为「刷新即丢会话」→ 登录/注册提交时报 CSRF 校验失败、验证码无法校验。
        // 仅当进程非 root 且目录可写时切换；cli/selftest（root）与其他部署方式保持 PHP 默认值。
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $sessionDir = YX_ROOT . '/runtime/session';
            $usable = is_dir($sessionDir) && is_writable($sessionDir);
            if (!$usable && !is_dir($sessionDir)) {
                @mkdir($sessionDir, 0700, true);
                $usable = is_dir($sessionDir) && is_writable($sessionDir);
            }
            if ($usable) {
                session_save_path($sessionDir);
            }
        }

        session_start();
    }

    // 自定义后台路径生效后，直接访问默认 admin/ 入口一律 404
    yx_admin_path_guard();

    return $conf;
}

/** 点号读取配置： config('db.driver') */
function config(string $path = null, $default = null)
{
    $conf = yx_bootstrap();
    if ($path === null) {
        return $conf;
    }
    $node = $conf;
    foreach (explode('.', $path) as $seg) {
        if (is_array($node) && array_key_exists($seg, $node)) {
            $node = $node[$seg];
        } else {
            return $default;
        }
    }
    return $node;
}

/** 读取系统设置（带进程内缓存） */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null || !empty($GLOBALS['_yx_setting_dirty'])) {
        $GLOBALS['_yx_setting_dirty'] = false;
        $cache = [];
        try {
            foreach (YxDb::all('SELECT k, v FROM ' . YxDb::table('setting')) as $row) {
                $cache[$row['k']] = $row['v'];
            }
        } catch (Throwable $e) {
            // 未安装时静默
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** 标记设置缓存失效，下次读取重新加载 */
function setting_reload(): void
{
    $GLOBALS['_yx_setting_dirty'] = true;
}

/** 写入系统设置 */
function set_setting(string $key, $value): void
{
    $exists = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('setting') . ' WHERE k = ?', [$key]);
    if ($exists) {
        YxDb::exec('UPDATE ' . YxDb::table('setting') . ' SET v = ?, updated_at = ? WHERE k = ?', [(string)$value, now(), $key]);
    } else {
        YxDb::exec('INSERT INTO ' . YxDb::table('setting') . ' (k, v, updated_at) VALUES (?,?,?)', [$key, (string)$value, now()]);
    }
    setting_reload();
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** HTML 转义 */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 金额格式化 */
function money($v, bool $symbol = false): string
{
    $s = number_format((float)$v, 2, '.', ',');
    return $symbol ? (config('finance.currency_symbol', '¥') . $s) : $s;
}

/** 生成业务单号 */
function make_sn(string $prefix, string $table = null): string
{
    $sn = strtoupper($prefix) . date('ymdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
    if ($table === null) {
        return $sn;
    }
    $try = 0;
    while ($try++ < 5 && YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table($table) . ' WHERE sn = ?', [$sn])) {
        $sn = strtoupper($prefix) . date('ymdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }
    return $sn;
}

/** 随机密钥 */
function random_key(int $len = 32): string
{
    return bin2hex(random_bytes((int)ceil($len / 2)));
}

/** CSRF 令牌 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = random_key(32);
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** 校验 CSRF，失败直接终止 */
function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], (string)$token)) {
        http_response_code(419);
        exit('会话已过期，请刷新页面后重试（CSRF 校验失败）');
    }
}

/** 跳转 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** 一次性提示 */
function flash(string $msg = null, string $type = 'success')
{
    if ($msg === null) {
        $f = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return $f;
    }
    $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
    return null;
}

/**
 * 生成客户 5 位用户编号（对外展示的「用户ID」，与自增主键解耦）
 * 起始 10001，之后取当前最大值 +1；编号用尽后回收空号，始终保持 5 位。
 */
function customer_next_no(): int
{
    $max = (int)YxDb::value('SELECT MAX(user_no) FROM ' . YxDb::table('customer'));
    $candidate = max(10001, $max + 1);
    if ($candidate <= 99999) {
        return $candidate;
    }
    for ($no = 10001; $no <= 99999; $no++) {
        if (!YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE user_no = ?', [$no])) {
            return $no;
        }
    }
    return $candidate;
}

/**
 * 创建客户：自动分配 5 位用户编号，遇到并发抢号冲突时重新取号
 */
function customer_insert(array $data): int
{
    for ($try = 0; $try < 5; $try++) {
        $data['user_no'] = customer_next_no();
        try {
            return YxDb::insert('customer', $data);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'user_no') === false) {
                throw $e;
            }
        }
    }
    unset($data['user_no']);
    return YxDb::insert('customer', $data);
}

/** 输出 JSON */
function json_out($data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 站点根地址（含子目录时自动识别） */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = config('app.url', '');
    if ($configured) {
        $base = rtrim($configured, '/');
        return $base;
    }
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        $base = '';
        return $base;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'];
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $root    = realpath(YX_ROOT) ?: YX_ROOT;
    $path    = '';
    if ($docroot && strpos($root, $docroot) === 0) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($root, strlen($docroot)));
    }
    $base = $scheme . '://' . $host . rtrim($path, '/');
    return $base;
}

/**
 * 生成站内绝对地址
 * 后台入口目录可自定义（后台路径），这里统一把 admin/ 前缀替换为实际目录。
 */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $adminPath = yx_admin_path();
    if ($adminPath !== 'admin' && $path !== '') {
        if ($path === 'admin' || $path === 'admin/') {
            $path = $adminPath . '/';
        } elseif (strncmp($path, 'admin/', 6) === 0) {
            $path = $adminPath . '/' . substr($path, 6);
        }
    }
    return base_url() . '/' . $path;
}

/* ------------------------------------------------------------------ */
/* 后台入口路径（安装向导可选，默认 admin）                             */
/* ------------------------------------------------------------------ */

/** 后台路径保留名：与系统目录或常见路径重名时不允许使用 */
function yx_admin_path_reserved(): array
{
    return [
        'install', 'includes', 'assets', 'uploads', 'runtime', 'cache', 'docs', 'tools',
        'user', 'panel', 'store', 'api', 'agent', 'subsite', 'service', 'goods', 'report',
        'www', 'static', 'public', 'config', 'data', 'log', 'logs', 'tmp', 'temp',
        'backup', 'vendor', 'node_modules', 'cgi-bin', 'well-known',
    ];
}

/** 后台路径格式校验：小写字母开头，可含数字 / 下划线 / 连字符，3-24 位 */
function yx_admin_path_valid(string $path): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_-]{2,23}$/', $path);
}

/** 该目录是否为本系统生成的后台入口别名目录（内含指向 admin/*.php 的符号链接） */
function yx_admin_path_is_alias(string $path): bool
{
    if (!yx_admin_path_valid($path)) {
        return false;
    }
    $dir = YX_ROOT . '/' . $path;
    if (!is_dir($dir) || is_link($dir)) {
        return false;
    }
    $link = $dir . '/index.php';
    if (!is_link($link)) {
        return false;
    }
    $target = realpath($link);
    $real   = realpath(YX_ROOT . '/admin/index.php');
    return $target !== false && $real !== false && $target === $real;
}

/**
 * 当前生效的后台目录名
 * 别名目录不可用（例如被误删）时回退默认 admin，避免整站后台不可达。
 */
function yx_admin_path(): string
{
    $path = strtolower(trim((string)setting('admin_path', 'admin')));
    if ($path === '' || $path === 'admin') {
        return 'admin';
    }
    if (!yx_admin_path_valid($path) || in_array($path, yx_admin_path_reserved(), true)) {
        return 'admin';
    }
    return yx_admin_path_is_alias($path) ? $path : 'admin';
}

/** 校验后台路径，返回空串表示通过，否则返回错误文案 */
function yx_admin_path_validate(string $path): string
{
    $path = strtolower(trim($path));
    if ($path === '') {
        return '后台路径不能为空';
    }
    if (!yx_admin_path_valid($path)) {
        return '后台路径需以小写字母开头，只能包含小写字母、数字、下划线、连字符，长度 3-24 位';
    }
    if ($path === 'admin') {
        return '';
    }
    if (in_array($path, yx_admin_path_reserved(), true)) {
        return '「' . $path . '」与系统目录重名，请换一个';
    }
    if (file_exists(YX_ROOT . '/' . $path) && !yx_admin_path_is_alias($path)) {
        return '「' . $path . '」已存在同名目录或文件，请换一个';
    }
    return '';
}

/**
 * 建立（或迁移）后台入口别名目录
 * 代码只有一份：别名目录里都是指向 ../admin/x.php 的相对符号链接。
 */
function yx_admin_path_sync(string $path, ?string $previous = null): array
{
    $path = strtolower(trim($path));
    $err  = yx_admin_path_validate($path);
    if ($err !== '') {
        return ['ok' => false, 'msg' => $err, 'created' => 0];
    }

    // 迁移：旧入口是本系统生成的别名目录时一并清理
    $old = $previous === null
        ? strtolower(trim((string)setting('admin_path', 'admin')))
        : strtolower(trim($previous));
    if ($old !== '' && $old !== $path && yx_admin_path_is_alias($old)) {
        yx_admin_path_alias_remove($old);
    }

    if ($path === 'admin') {
        return ['ok' => true, 'msg' => '已使用默认后台目录 admin', 'created' => 0];
    }

    if (!yx_admin_path_is_alias($path)) {
        if (file_exists(YX_ROOT . '/' . $path)) {
            return ['ok' => false, 'msg' => '目录「' . $path . '」已存在且不是本系统生成的入口', 'created' => 0];
        }
        if (!@mkdir(YX_ROOT . '/' . $path, 0755, true)) {
            return ['ok' => false, 'msg' => '无法创建后台入口目录：' . YX_ROOT . '/' . $path, 'created' => 0];
        }
    }

    $created = 0;
    $failed  = [];
    foreach ((array)glob(YX_ROOT . '/admin/*.php') as $file) {
        $name = basename($file);
        $link = YX_ROOT . '/' . $path . '/' . $name;
        if (is_link($link)) {
            if (realpath($link) === realpath($file)) {
                continue;
            }
            @unlink($link);
        } elseif (file_exists($link)) {
            $failed[] = $name;
            continue;
        }
        if (@symlink('../admin/' . $name, $link)) {
            $created++;
        } else {
            $failed[] = $name;
        }
    }

    if (!yx_admin_path_is_alias($path)) {
        return ['ok' => false, 'msg' => '后台入口未建立（index.php 链接失败），请检查站点根目录写权限，已保留默认入口', 'created' => $created];
    }
    return [
        'ok'      => true,
        'msg'     => $failed ? ('后台入口已就绪，但以下文件未能建立链接：' . implode('、', $failed)) : '后台入口已就绪',
        'created' => $created,
    ];
}

/** 移除本系统生成的后台入口别名目录（只删自己建立的符号链接） */
function yx_admin_path_alias_remove(string $path): void
{
    if (!yx_admin_path_is_alias($path)) {
        return;
    }
    $dir = YX_ROOT . '/' . $path;
    foreach ((array)glob($dir . '/*.php') as $file) {
        if (is_link($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

/**
 * 后台入口保护：配置了自定义后台路径时，默认 admin/ 下的脚本一律返回 404。
 * 别名目录缺失或对应文件未链接时放行（宁可少拦一次，也不能把管理员锁在门外）。
 */
function yx_admin_path_guard(): void
{
    if (PHP_SAPI === 'cli' || !(config('app.installed'))) {
        return;
    }
    $alias = yx_admin_path();
    if ($alias === 'admin') {
        return;
    }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (!preg_match('#(?:^|/)admin/([A-Za-z0-9_.\-]+\.php)$#', $script, $m)) {
        return;
    }
    if (!yx_admin_path_is_alias($alias) || !is_file(YX_ROOT . '/' . $alias . '/' . $m[1])) {
        return;
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>404 Not Found</title></head>'
        . '<body style="font-family:system-ui,-apple-system,sans-serif;padding:48px;color:#333">'
        . '<h1 style="font-size:22px">404 Not Found</h1><p>请求的页面不存在。</p></body></html>');
}

/** 当前访问域名（用于授权校验，去掉端口与 www 前缀） */
function current_domain(): string
{
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        return normalize_domain((string)$_SERVER['HTTP_HOST']);
    }
    // CLI（定时任务等）没有 Host，优先取站点自身配置，避免误用机器主机名
    return site_domain();
}

/**
 * 站点自身域名：site_domain > site_url > app.url
 * CLI 场景下授权校验必须用站点域名而不是机器主机名。
 */
function site_domain(): string
{
    foreach ([
        (string)setting('site_domain', ''),
        (string)setting('site_url', ''),
        (string)config('app.url', ''),
    ] as $candidate) {
        $d = normalize_domain($candidate);
        if ($d !== '' && $d !== 'localhost') {
            return $d;
        }
    }
    $host = gethostname();
    return strtolower((string)$host);
}

/** 归一化域名 */
function normalize_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    // 去掉协议头
    $domain = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $domain);
    // 去掉路径、查询串与锚点
    $domain = preg_replace('#[/?\#].*$#', '', $domain);
    // 去掉端口
    $domain = preg_replace('/:\d+$/', '', $domain);
    return trim($domain, " \t\n\r\0\x0B.");
}

function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/** 分页参数 */
function paginate(int $total, int $perPage = 20, string $param = 'page'): array
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int)ceil($total / $perPage));
    $page    = max(1, min($pages, (int)($_GET[$param] ?? 1)));
    return [
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
        'param'    => $param,
    ];
}

/** 渲染分页条 */
function pager(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $query = $_GET;
    $link = function ($page) use ($query, $p) {
        $query[$p['param']] = $page;
        return '?' . http_build_query($query);
    };
    $html = '<nav class="pager">';
    $html .= $p['page'] > 1
        ? '<a href="' . e($link($p['page'] - 1)) . '">上一页</a>'
        : '<span class="disabled">上一页</span>';
    $html .= '<span class="cur">第 ' . $p['page'] . ' / ' . $p['pages'] . ' 页 · 共 ' . $p['total'] . ' 条</span>';
    $html .= $p['page'] < $p['pages']
        ? '<a href="' . e($link($p['page'] + 1)) . '">下一页</a>'
        : '<span class="disabled">下一页</span>';
    return $html . '</nav>';
}

/** 管理端操作审计 */
function log_action(string $action, string $target = '', array $detail = []): void
{
    try {
        YxDb::insert('admin_log', [
            'admin_id'   => (int)($_SESSION['admin_id'] ?? 0),
            'action'     => $action,
            'target'     => $target,
            'detail'     => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
            'ip'         => client_ip(),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        // 审计失败不影响主流程
    }
}

/** 是否已完成安装 */
function yx_installed(): bool
{
    if (!(config('app.installed'))) {
        return false;
    }
    try {
        return YxDb::hasTable('setting');
    } catch (Throwable $e) {
        return false;
    }
}

/** 未安装时引导到安装向导 */
function yx_require_installed(): void
{
    if (yx_installed()) {
        return;
    }
    redirect(url('install/install.php'));
}

/** 简单的对称加密（用于业务密码字段，密钥来自 app.key） */
function yx_encrypt(string $plain): string
{
    $key = config('app.key', '');
    if ($key === '') {
        return $plain;
    }
    $iv    = substr(hash('sha256', $key), 0, 16);
    $data  = openssl_encrypt($plain, 'AES-256-CBC', substr(hash('sha256', $key), 0, 32), 0, $iv);
    return 'enc:' . $data;
}

function yx_decrypt(string $cipher): string
{
    if (strpos($cipher, 'enc:') !== 0) {
        return $cipher;
    }
    $key = config('app.key', '');
    if ($key === '') {
        return substr($cipher, 4);
    }
    $iv   = substr(hash('sha256', $key), 0, 16);
    $out  = openssl_decrypt(substr($cipher, 4), 'AES-256-CBC', substr(hash('sha256', $key), 0, 32), 0, $iv);
    return $out === false ? '' : $out;
}

/** 金额四舍五入到分 */
function round_money($v): float
{
    return round((float)$v, 2);
}

/**
 * 通用 GET 请求（优先 curl），可附加自定义请求头，失败返回 null
 */
function http_get(string $url, int $timeout = 5, array $headers = []): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'YouxiangFinance/' . config('app.version', '1.0.0'),
        ]);
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        return ($err === 0 && is_string($body)) ? $body : null;
    }
    $opt = ['timeout' => $timeout, 'ignore_errors' => true];
    if ($headers) {
        $opt['header'] = implode("\r\n", $headers);
    }
    $ctx  = stream_context_create(['http' => $opt]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

/**
 * 通用 POST JSON 请求（优先 curl），返回 [ok, httpCode, body]
 */
function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 10): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers[] = 'Content-Type: application/json; charset=utf-8';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'YouxiangFinance/' . config('app.version', '1.0.0'),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return [false, 0, $err];
        }
        return [true, $code, (string)$resp];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return [false, 0, 'allow_url_fopen 不可用或网络异常'];
    }
    return [true, 200, (string)$resp];
}

/**
 * 通用 GET 请求（优先 curl），返回 [ok, httpCode, body]
 *
 * 与 http_get() 的差别：带回 HTTP 状态码，供上游驱动判定失败（如 404 路径不存在、
 * 502 网关拦截）；不跟随跳转，避免把登录页 HTML 当成接口响应。
 */
function http_get_status(string $url, int $timeout = 10, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'YouxiangFinance/' . config('app.version', '1.0.0'),
        ]);
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return [false, 0, $err];
        }
        return [true, $code, (string)$resp];
    }

    $opt = ['timeout' => $timeout, 'ignore_errors' => true, 'method' => 'GET'];
    if ($headers) {
        $opt['header'] = implode("\r\n", $headers);
    }
    $ctx  = stream_context_create(['http' => $opt]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return [false, 0, 'allow_url_fopen 不可用或网络异常'];
    }
    $code = 200;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return [true, $code, (string)$resp];
}

/** 生成随机串（接口 nonce 等） */
function random_nonce(int $len = 32): string
{
    return bin2hex(random_bytes(max(8, (int)ceil($len / 2))));
}

/**
 * 通用 POST 表单请求（application/x-www-form-urlencoded），返回 [ok, httpCode, body]
 */
function http_post_form(string $url, array $data, int $timeout = 10, array $headers = []): array
{
    $body = http_build_query($data);
    $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'YouxiangFinance/' . config('app.version', '1.0.0'),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return [false, 0, $err];
        }
        return [true, $code, (string)$resp];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return [false, 0, 'allow_url_fopen 不可用或网络异常'];
    }
    return [true, 200, (string)$resp];
}

/** 按点号路径从数组取值：dig($a, 'data.host.id') */
function array_dig(array $arr, string $path, $default = null)
{
    if ($path === '') {
        return $arr;
    }
    $node = $arr;
    foreach (explode('.', $path) as $seg) {
        if (is_array($node) && array_key_exists($seg, $node)) {
            $node = $node[$seg];
        } else {
            return $default;
        }
    }
    return $node;
}

/* ------------------------------------------------------------------ */
/* 公众号展示（后台可编辑图片 + 标题，前台首页展示）                    */
/* ------------------------------------------------------------------ */

/** 公众号展示图片列表：每项 ['img' => 相对路径, 'title' => 标题, 'desc' => 说明] */
function wechat_images(): array
{
    $raw = trim((string)setting('wechat_images', ''));
    if ($raw === '') {
        return [];
    }
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $item) {
        if (!is_array($item)) {
            continue;
        }
        $img = trim((string)($item['img'] ?? ''));
        // 仅接受 uploads 下的相对路径，拒绝协议、绝对路径与目录穿越
        if ($img === '' || strpos($img, '..') !== false || preg_match('#^([a-z]+:)?//#i', $img) || strpos($img, '\\') !== false) {
            continue;
        }
        $out[] = [
            'img'   => ltrim($img, '/'),
            'title' => trim((string)($item['title'] ?? '')),
            'desc'  => trim((string)($item['desc'] ?? '')),
        ];
    }
    return $out;
}

/** 公众号板块是否在前台展示 */
function wechat_enabled(): bool
{
    return setting('wechat_show', '1') !== '0' && wechat_images() !== [];
}

/* ------------------------------------------------------------------ */
/* 站点 LOGO（后台可上传，登录/注册页展示；未上传时回落到内置图片）      */
/* ------------------------------------------------------------------ */

/** 内置兜底 LOGO 的相对路径 */
function yx_logo_default(): string
{
    return 'assets/logo/logo-mark.png';
}

/** 已上传 LOGO 在 uploads 下的相对路径；未设置为空串 */
function yx_logo_upload_path(): string
{
    $raw = trim((string)setting('site_logo', ''));
    if ($raw === '' || strpos($raw, '..') !== false || strpos($raw, '\\') !== false
        || preg_match('#^([a-z]+:)?//#i', $raw) || strpos($raw, '/') === 0) {
        return '';
    }
    $raw = ltrim($raw, '/');
    return is_file(YX_ROOT . '/uploads/' . $raw) ? $raw : '';
}

/** LOGO 相对路径（相对站点根，可直接交给 url()） */
function yx_logo_file(): string
{
    $own = yx_logo_upload_path();
    return $own !== '' ? 'uploads/' . $own : yx_logo_default();
}

/** LOGO 绝对路径（读取文件修改时间做缓存戳用） */
function yx_logo_abs(): string
{
    return YX_ROOT . '/' . yx_logo_file();
}

/** LOGO 访问地址（带修改时间缓存戳） */
function yx_logo_url(): string
{
    $abs = yx_logo_abs();
    return url(yx_logo_file()) . '?v=' . (int)@filemtime($abs);
}

/* ------------------------------------------------------------------ */
/* 登录 / 注册页 LOGO 墙（后台可上传，也可直接替换 assets 目录下的图片） */
/* ------------------------------------------------------------------ */

/** LOGO 墙图片目录（相对站点根） */
function yx_wall_dir(): string
{
    return 'assets/logo/wall';
}

/**
 * LOGO 墙图片列表
 * 每项：['img' => 相对站点根的路径, 'name' => 展示名称（可为空）, 'src' => 'db'|'file']
 * 优先读取后台配置的上传图片，再合并 assets/logo/wall/ 目录内的图片文件。
 */
function yx_wall_logos(): array
{
    $out  = [];
    $seen = [];

    $raw = trim((string)setting('logo_wall_images', ''));
    if ($raw !== '') {
        $list = json_decode($raw, true);
        if (is_array($list)) {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $img = trim((string)($item['img'] ?? ''));
                // 仅接受 uploads/ 下的相对路径，拒绝协议、绝对路径与目录穿越
                if ($img === '' || strpos($img, '..') !== false || strpos($img, '\\') !== false
                    || preg_match('#^([a-z]+:)?//#i', $img) || strpos($img, '/') === 0) {
                    continue;
                }
                $img = 'uploads/' . ltrim($img, '/');
                if (!is_file(YX_ROOT . '/' . $img) || isset($seen[$img])) {
                    continue;
                }
                $seen[$img] = true;
                $out[] = [
                    'img'  => $img,
                    'name' => trim((string)($item['name'] ?? '')),
                    'src'  => 'db',
                ];
            }
        }
    }

    // 文件目录兜底：直接把图片丢进 assets/logo/wall/ 即可生效
    $dir = YX_ROOT . '/' . yx_wall_dir();
    if (is_dir($dir)) {
        $names = @scandir($dir) ?: [];
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($names as $f) {
            if ($f === '.' || $f === '..' || strpos($f, '.') === 0) {
                continue;
            }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                continue;
            }
            $rel = yx_wall_dir() . '/' . $f;
            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;
            $out[] = [
                'img'  => $rel,
                'name' => trim((string)preg_replace('/[-_]+/', ' ', pathinfo($f, PATHINFO_FILENAME))),
                'src'  => 'file',
            ];
        }
    }

    return $out;
}

/** LOGO 墙图片访问地址（带修改时间缓存戳） */
function yx_wall_img_url(string $rel): string
{
    $abs = YX_ROOT . '/' . ltrim($rel, '/');
    return url($rel) . '?v=' . (int)@filemtime($abs);
}

/** LOGO 墙是否展示 */
function yx_wall_enabled(): bool
{
    return setting('logo_wall_show', '1') !== '0' && yx_wall_logos() !== [];
}

/**
 * 用户中心内联图标（线性 SVG，随 currentColor 变色）
 * 不引入图标字体与外链资源，保持零第三方依赖。
 */
function yx_icon(string $name, int $size = 22): string
{
    static $map = [
        'user'    => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'company' => '<path d="M3.5 20h17"/><path d="M5.5 20V7l6-3v16"/><path d="M11.5 20V9l7 3v8"/><path d="M8 9.5v.01M8 12.5v.01M8 15.5v.01M14.5 13.5v.01M14.5 16.5v.01"/>',
        'phone'   => '<path d="M7 3.5h2.2l1.4 3.6-1.8 1.2a10.5 10.5 0 0 0 5.4 5.4l1.2-1.8 3.6 1.4V20a1.5 1.5 0 0 1-1.7 1.5A16.5 16.5 0 0 1 5.5 5.2A1.5 1.5 0 0 1 7 3.5Z"/>',
        'mail'    => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="m4 7.5 8 5.5 8-5.5"/>',
        'shield'  => '<path d="M12 3 5 5.8v5.4c0 4.2 2.9 7.6 7 9.3 4.1-1.7 7-5.1 7-9.3V5.8L12 3Z"/><path d="m9 11.8 2.2 2.2L15.5 9.7"/>',
        'key'     => '<circle cx="8" cy="15.5" r="3.5"/><path d="m10.8 13 7-7"/><path d="m14.5 6.5 2 2"/><path d="m16.8 4.2 2 2"/>',
        'server'  => '<rect x="3.5" y="4" width="17" height="6.5" rx="1.8"/><rect x="3.5" y="13.5" width="17" height="6.5" rx="1.8"/><path d="M7 7.2v.01M7 16.7v.01"/>',
        'check'   => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.2 2.4 2.4 4.6-4.9"/>',
        'bill'    => '<path d="M6 3.5h12v17l-3-1.8-3 1.8-3-1.8-3 1.8V3.5Z"/><path d="M9.5 8h5M9.5 12h5"/>',
        'wallet'  => '<rect x="3.5" y="6" width="17" height="13" rx="3"/><path d="M3.5 10h17"/><circle cx="16.5" cy="14.5" r="1.1"/>',
        'store'   => '<path d="M4 9.5V19a1.5 1.5 0 0 0 1.5 1.5h13A1.5 1.5 0 0 0 20 19V9.5"/><path d="M3 9.5h18l-1.6-5H4.6L3 9.5Z"/><path d="M9.5 20.5v-5h5v5"/>',
    ];
    $body = $map[$name] ?? $map['check'];
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" '
        . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

/**
 * 客户实名状态中文名（customer.realname_status）
 * 仅有早前手工填写的 realname 而无核验状态的旧数据，一律视为未认证。
 */
function yx_realname_status_text(array $customer): string
{
    $s = (string)($customer['realname_status'] ?? '');
    return YxRealname::statusName($s);
}

/** 客户是否已通过扫码实名核验 */
function yx_customer_verified(array $customer): bool
{
    return (string)($customer['realname_status'] ?? '') === 'verified';
}
