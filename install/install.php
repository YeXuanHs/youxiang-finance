<?php
/**
 * 优享财务系统 · 安装向导（五步）
 *
 * 1 许可协议与免责声明 → 2 环境 / 模块 / 目录权限检测 → 3 数据库与站点信息
 * → 4 创始人信息与授权码 → 5 安装完成（自动跳转后台）
 *
 * 支持 MySQL / SQLite 双驱动；重复执行仅补齐缺失表并写入默认数据，不会清空既有数据。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* ================================================================== */
/* 基础工具（沿用原有实现，保持外部引用兼容）                           */
/* ================================================================== */

/** 安全渲染配置数组为 PHP 文件内容 */
function render_conf(array $arr, int $level = 0): string
{
    $ind   = str_repeat('    ', $level);
    $lines = ["["];
    foreach ($arr as $k => $v) {
        $key = is_int($k) ? $k : "'" . $k . "'";
        if (is_array($v)) {
            $lines[] = $ind . '    ' . $key . ' => ' . render_conf($v, $level + 1) . ',';
        } elseif (is_bool($v)) {
            $lines[] = $ind . '    ' . $key . ' => ' . ($v ? 'true' : 'false') . ',';
        } elseif ($v === null) {
            $lines[] = $ind . '    ' . $key . ' => null,';
        } elseif (is_int($v) || is_float($v)) {
            $lines[] = $ind . '    ' . $key . ' => ' . $v . ',';
        } else {
            $lines[] = $ind . '    ' . $key . ' => ' . var_export((string)$v, true) . ',';
        }
    }
    $lines[] = $ind . ']';
    return implode("\n", $lines);
}

function write_config_file(array $conf, string $path): bool
{
    $body = "<?php\n"
        . "/**\n * 优享财务系统 · 全局配置（由安装向导生成于 " . date('Y-m-d H:i:s') . "）\n */\n\n"
        . "return " . render_conf($conf) . ";\n";
    return (bool)file_put_contents($path, $body, LOCK_EX);
}

/* ================================================================== */
/* 授权文案（原为「密封数据」密文，现还原成明文常量，改文案直接改这里） */
/* ================================================================== */

/** 授权包价格文案 */
const YX_AUTH_PRICE = '366 元/次';
/** 授权包名称 */
const YX_AUTH_LABEL = '授权包';
/** 授权码查看入口 */
const YX_AUTH_PAGE  = '/user/licenses.php';
/** 官方商店地址 */
const YX_SHOP       = 'https://www.idcpremiumfinance.cn';

/** 授权包价格文案 */
function install_price_text(): string
{
    return YX_AUTH_PRICE;
}

/** 授权码查看入口 */
function install_auth_page(): string
{
    return YX_AUTH_PAGE;
}

/** 授权包名称 */
function install_auth_label(): string
{
    return YX_AUTH_LABEL;
}

/** 建立连接（按驱动） */
function try_connect(array $db, bool $createDb = false): array
{
    try {
        if ($db['driver'] === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int)$db['port'], $db['charset']);
            if (!$createDb) {
                $dsn .= ';dbname=' . $db['name'];
            }
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($createDb) {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $db['name']) . "` DEFAULT CHARACTER SET utf8mb4");
                $pdo->exec('USE `' . str_replace('`', '', $db['name']) . '`');
            }
            return [true, $pdo, ''];
        }
        $dir = dirname($db['path']);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return [false, null, '无法创建 SQLite 数据目录：' . $dir];
        }
        $pdo = new PDO('sqlite:' . $db['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return [true, $pdo, ''];
    } catch (Throwable $e) {
        return [false, null, $e->getMessage()];
    }
}

/**
 * 归一化 SQLite 数据库路径为绝对路径。
 * 相对路径按站点根目录（YX_ROOT）解析，而不是按 PHP 当前工作目录，
 * 否则安装时可能把库写进 install/ 等子目录，安装完成后系统找不到数据库。
 */
function install_abs_db_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return YX_ROOT . '/runtime/youxiang.db';
    }
    // 已是绝对路径（Unix / Windows 盘符）或流式路径则原样返回
    if ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || strpos($path, '://') !== false) {
        return $path;
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    // 去掉 ./ 前缀，避免出现 YX_ROOT/./runtime
    $path = preg_replace('#^\./+#', '', $path);
    return YX_ROOT . '/' . $path;
}

/** 建表 / 升级结构（增量，仅做加法，不删列不删表） */
function run_schema(PDO $pdo, string $driver): array
{
    $log = YxSchema::sync($pdo, $driver);
    if ($driver === 'sqlite') {
        foreach (YxSchema::sqliteIndexes() as $sql) {
            $pdo->exec($sql);
        }
    }
    return $log;
}

/** 重新载入配置（写入 config.php 之后必须执行，否则仍用旧库） */
function reload_conf(): array
{
    $conf = require dirname(__DIR__) . '/includes/config.php';
    YxDb::configure($conf['db']);
    return $conf;
}

/* ================================================================== */
/* 向导：状态、步骤条与检测                                            */
/* ================================================================== */

function wizard_steps(): array
{
    return [1 => '许可协议', 2 => '环境检测', 3 => '数据库与站点', 4 => '创始人信息', 5 => '安装完成'];
}

function wizard_state(): array
{
    $default = [
        'agreed'      => false,
        'site_name'   => '优享财务系统',
        'site_domain' => '',
        'admin_path'  => 'admin',
        'edition'     => 'basic',
        'debug'       => false,
        'db'          => null,
        'create_db'   => false,
        'admin_user'  => '',
        'admin_email' => '',
        'license_key' => '',
        'done'        => false,
        'result'      => [],
    ];
    $state = $_SESSION['_install'] ?? [];
    return is_array($state) ? array_merge($default, $state) : $default;
}

function wizard_save(array $patch): void
{
    $_SESSION['_install'] = array_merge(wizard_state(), $patch);
}

function wizard_reset(): void
{
    unset($_SESSION['_install']);
}

/** 安装向导的 CSRF 校验：Web 请求必须带令牌，命令行下跳过（能进命令行即已拥有服务器权限） */
function wizard_csrf(): void
{
    if (PHP_SAPI !== 'cli') {
        csrf_check();
    }
}

function wizard_stepper(int $current): string
{
    $html = '<ul class="stepper">';
    foreach (wizard_steps() as $n => $label) {
        $cls  = $n < $current ? 'done' : ($n === $current ? 'cur' : '');
        $mark = $n < $current ? '✓' : (string)$n;
        $html .= '<li class="' . $cls . '"><b>' . $mark . '</b><span>' . e($label) . '</span></li>';
    }
    return $html . '</ul>';
}

/**
 * 环境 / 模块 / 目录文件权限检测
 * status: ok 通过 | warn 可继续但建议处理 | fail 不满足，必须处理
 */
function install_checks(): array
{
    $groups = [];

    // 1) 运行环境
    $phpNeed = '8.0.0';
    $limit   = (string)ini_get('memory_limit');
    $groups['env'] = [
        'title' => '运行环境',
        'items' => [
            [
                'name'     => 'PHP 版本',
                'val'      => PHP_VERSION,
                'status'   => version_compare(PHP_VERSION, $phpNeed, '>=') ? 'ok' : 'fail',
                'hint'     => '要求 PHP ' . $phpNeed . ' 及以上',
                'required' => true,
            ],
            [
                'name'     => '服务器系统',
                'val'      => PHP_OS . ' · ' . php_uname('m'),
                'status'   => 'ok',
                'hint'     => '',
                'required' => false,
            ],
            [
                'name'     => 'PHP 运行方式',
                'val'      => PHP_SAPI,
                'status'   => PHP_SAPI === 'cli' ? 'warn' : 'ok',
                'hint'     => PHP_SAPI === 'cli'
                    ? '当前在命令行下运行，向导逻辑可验证；正式安装请通过 Web 访问'
                    : '',
                'required' => false,
            ],
            [
                'name'     => 'Web 服务器',
                'val'      => (string)($_SERVER['SERVER_SOFTWARE'] ?? '命令行'),
                'status'   => 'ok',
                'hint'     => '',
                'required' => false,
            ],
            [
                'name'     => '内存上限',
                'val'      => $limit,
                'status'   => ($limit === '-1' || (int)$limit >= 128) ? 'ok' : 'warn',
                'hint'     => '建议 128M 及以上',
                'required' => false,
            ],
            [
                'name'     => '时区',
                'val'      => date_default_timezone_get(),
                'status'   => 'ok',
                'hint'     => '',
                'required' => false,
            ],
        ],
    ];

    // 2) PHP 模块
    $exts = [
        ['pdo',        'PDO 数据库抽象层',              true,  '核心依赖，缺失将无法安装'],
        ['json',       'JSON 编解码',                   true,  '核心依赖'],
        ['mbstring',   '多字节字符串处理',              true,  '核心依赖（中文截断、编码处理）'],
        ['openssl',    'AES 加密与 HMAC 签名',          true,  '核心依赖（业务密码、接口签名）'],
        ['session',    '会话管理',                      true,  '核心依赖（登录态）'],
        ['ctype',      '字符类型判断',                  false, '建议开启'],
        ['curl',       'HTTP 请求（上游 / 支付对接）',  false, '缺失时回退 file_get_contents，建议开启'],
        ['simplexml',  'XML 响应解析（上游可选协议）',  false, '仅在对接 XML 接口时需要'],
        ['gd',         '图片处理（文档配图工具）',      false, '仅 tools/make_doc_images.php 需要'],
        ['fileinfo',   '上传文件类型识别',              false, '建议开启'],
        ['zip',        '备份压缩',                      false, '建议开启'],
        ['pdo_sqlite', 'SQLite 数据驱动',               false, '选择 SQLite 时必需'],
        ['pdo_mysql',  'MySQL 数据驱动',                false, '选择 MySQL 时必需'],
    ];
    $items = [];
    foreach ($exts as $ext) {
        [$key, $label, $required, $hint] = $ext;
        $loaded = extension_loaded($key);
        $items[] = [
            'name'     => $label . '（' . $key . '）',
            'val'      => $loaded ? '已启用' : '未启用',
            'status'   => $loaded ? 'ok' : ($required ? 'fail' : 'warn'),
            'hint'     => $hint,
            'required' => $required,
        ];
    }
    $groups['ext'] = ['title' => 'PHP 模块', 'items' => $items];

    // 3) 目录与文件权限
    $paths = [
        ['.',        '站点根目录（创建自定义后台入口）', true],
        ['includes', '配置目录（写入 config.php）',      true],
        ['runtime',  '运行数据目录（数据库 / 缓存）',    true],
        ['cache',    '缓存目录',                          true],
        ['uploads',  '上传目录',                          true],
        ['assets',   '静态资源目录',                      false],
        ['docs',     '文档中心目录',                      false],
    ];
    $items = [];
    foreach ($paths as $p) {
        [$rel, $label, $required] = $p;
        $abs = $rel === '.' ? YX_ROOT : YX_ROOT . '/' . $rel;
        if (!is_dir($abs)) {
            $items[] = [
                'name'     => $label,
                'val'      => '目录不存在',
                'status'   => $required ? 'fail' : 'warn',
                'hint'     => $rel === '.' ? $abs : $abs,
                'required' => $required,
            ];
            continue;
        }
        $writable = is_writable($abs);
        $items[] = [
            'name'     => $label,
            'val'      => $writable ? '可写' : '不可写',
            'status'   => $writable ? 'ok' : ($required ? 'fail' : 'warn'),
            'hint'     => $rel === '.' ? $abs : $abs,
            'required' => $required,
        ];
    }

    // 配置文件（已存在时需可写，否则需所在目录可写）
    $confFile = YX_ROOT . '/includes/config.php';
    if (is_file($confFile)) {
        $items[] = [
            'name'     => '配置文件 includes/config.php',
            'val'      => is_writable($confFile) ? '可写' : '不可写',
            'status'   => is_writable($confFile) ? 'ok' : 'fail',
            'hint'     => '安装时需要写入数据库配置',
            'required' => true,
        ];
    } else {
        $items[] = [
            'name'     => '配置文件 includes/config.php',
            'val'      => '尚未生成',
            'status'   => is_writable(YX_ROOT . '/includes') ? 'ok' : 'fail',
            'hint'     => '安装时由向导生成',
            'required' => true,
        ];
    }

    // 自定义后台入口依赖符号链接
    $items[] = [
        'name'     => 'symlink 函数（自定义后台路径）',
        'val'      => function_exists('symlink') ? '可用' : '被禁用',
        'status'   => function_exists('symlink') ? 'ok' : 'warn',
        'hint'     => function_exists('symlink') ? '用于生成自定义后台入口目录' : '被禁用时仍可使用默认后台路径 admin',
        'required' => false,
    ];

    $groups['dir'] = ['title' => '目录与文件权限', 'items' => $items];

    // 4) 授权前提：正版程序包必须先在官方商店购买「正版主程序包」并取得授权码
    //    这里只做「是否已取得授权码」的准入判断（确定性），完整校验第 4 步在线执行。
    $groups['license'] = [
        'title' => '授权前提',
        'items' => [
            install_license_gate_item(),
        ],
    ];

    return $groups;
}

/**
 * 第 2 步的授权准入项
 *
 * 本程序包已内置授权包，不再需要授权码：这一项恒为「已授权」，不参与阻断。
 */
function install_state_key(): string
{
    $state = wizard_state();
    return strtoupper((string)preg_replace('/\s+/', '', trim((string)($state['license_key'] ?? ''))) ?? '');
}

function install_license_gate_item(): array
{
    return [
        'name'     => '安装授权',
        'val'      => '已授权 · 无需授权码',
        'status'   => 'ok',
        'hint'     => '本程序包已内置「' . install_auth_label() . '」（' . install_price_text() . '），无需填写授权码即可安装，安装后即为正版授权（企业版）。',
        'required' => false,
    ];
}

/** 统计检测结果 */
function install_check_stats(array $groups): array
{
    $stat = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'blocking' => []];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $stat[$item['status']]++;
            if ($item['status'] === 'fail' && !empty($item['required'])) {
                $stat['blocking'][] = $item['name'];
            }
        }
    }
    return $stat;
}

/**
 * 授权码是否为本发行包强制要求
 *
 * 本程序包已内置授权包，恒返回 false：安装过程不再索要、也不再校验授权码。
 * （原逻辑：official 模式强制、community 模式不强制、可用 YX_LICENSE_REQUIRED 关闭。）
 */
function install_license_required(): bool
{
    return false;
}

/**
 * 当前发行包对应的主程序包类型（用于复核安装授权码）
 *
 * 安装时数据库尚未就绪、站点 app.key 也还没生成，因此授权码不能用 app.key 签名，
 * 也无法查询商店数据，只能依赖发行包里固定的产品密钥（YxLicense::packageKey）：
 * 正版包与开源包分别对应 official / community 两种产品，两者的码互不通用。
 */
function install_package_key(): string
{
    return (string)config('license.mode', 'official') === 'community' ? 'community' : 'official';
}

/**
 * 校验授权码
 * level: ok 通过 | warn 本地无法复核（待官方核验） | err 明确不通过
 *
 * 本程序包已内置授权包：无论域名与填写的授权码是什么，一律判定为「已授权（企业版）」，
 * 安装流程不会被授权环节阻断。
 *
 * 原始校验逻辑（本地授权库 / 域名签发规则 / 商店安装码）已在备份目录
 * 「优享加密版.bak-20261110/install/install.php」中保留，需要恢复时取回即可。
 */
function install_check_license(string $domain, string $key, bool $required = false): array
{
    return [
        'level'   => 'ok',
        'msg'     => '已授权：本程序包内置「' . install_auth_label() . '」，无需授权码（企业版，永久有效）。',
        'edition' => 'enterprise',
    ];
}

/**
 * 执行安装
 * 任何写操作都发生在本函数内，前面的步骤只做校验与暂存。
 */
function install_run(array $state): array
{
    $db     = $state['db'];
    $errors = [];

    if (!is_array($db) || empty($db['driver'])) {
        return ['ok' => false, 'errors' => ['数据库配置缺失，请返回第 3 步重新填写']];
    }
    if ($db['driver'] === 'sqlite' && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return ['ok' => false, 'errors' => ['当前 PHP 未启用 pdo_sqlite，请改用 MySQL 或先安装该扩展']];
    }
    if ($db['driver'] === 'mysql' && !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        return ['ok' => false, 'errors' => ['当前 PHP 未启用 pdo_mysql，请先安装该扩展']];
    }

    // 1) 连接数据库
    [$ok, $pdo, $err] = try_connect($db, !empty($state['create_db']));
    if (!$ok) {
        return ['ok' => false, 'errors' => ['数据库连接失败：' . $err]];
    }

    // 2) 自定义后台入口：失败直接中止，不落地任何配置
    $adminPath = (string)($state['admin_path'] ?: 'admin');
    if ($adminPath !== 'admin') {
        $sync = yx_admin_path_sync($adminPath, '');
        if (empty($sync['ok'])) {
            return ['ok' => false, 'errors' => ['后台路径「' . $adminPath . '」不可用：' . $sync['msg']]];
        }
    }

    // 3) 写入配置
    $newConf = config();
    $newConf['app']['name']      = (string)$state['site_name'];
    $newConf['app']['debug']     = !empty($state['debug']);
    $newConf['app']['installed'] = true;
    if ((string)($newConf['app']['key'] ?? '') === '') {
        $newConf['app']['key'] = random_key(48);
    }
    $newConf['edition']['current'] = in_array($state['edition'], ['basic', 'pro', 'enterprise'], true) ? $state['edition'] : 'basic';
    $newConf['db'] = $db;

    if (!write_config_file($newConf, YX_ROOT . '/includes/config.php')) {
        return ['ok' => false, 'errors' => ['无法写入 includes/config.php，请检查目录权限']];
    }

    $conf = reload_conf();
    YxDb::configure($conf['db']);

    try {
        $schemaLog = run_schema(YxDb::pdo(), $db['driver']);
        $seed      = seed_defaults((string)$state['admin_user'], (string)$state['admin_pass'], (string)$state['admin_email']);

        set_setting('edition', $newConf['edition']['current']);
        set_setting('site_name', (string)$state['site_name']);
        set_setting('site_domain', normalize_domain((string)$state['site_domain']));
        set_setting('site_url', 'https://' . normalize_domain((string)$state['site_domain']));
        set_setting('license_mode', (string)config('license.mode', 'official'));
        if ((string)$state['admin_email'] !== '') {
            set_setting('contact_email', (string)$state['admin_email']);
        }
        set_setting('admin_path', $adminPath);
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['初始化数据失败：' . $e->getMessage()]];
    }

    // 4) 授权码登记（不改写状态为 active，除非已在本机复核通过）
    //    本程序包已内置授权：未填授权码时不登记任何记录，视为已授权
    $license = ['level' => 'none', 'msg' => '已内置授权，无需登记授权码'];
    $key     = strtoupper(preg_replace('/\s+/', '', trim((string)$state['license_key'])) ?? '');
    if ($key !== '') {
        $checked = install_check_license((string)$state['site_domain'], $key);
        $license = $checked;
        try {
            $domain = normalize_domain((string)$state['site_domain']);
            $row    = YxDb::one('SELECT * FROM ' . YxDb::table('license') . ' WHERE domain = ? LIMIT 1', [$domain]);
            if (!$row) {
                YxDb::insert('license', [
                    'domain'      => $domain,
                    'license_key' => $key,
                    // 复核通过才按 active 记录，否则登记为待核验（不会因此获得授权）
                    'edition'     => $checked['edition'] ?? ($newConf['edition']['current']),
                    'type'        => 'official',
                    'status'      => ($checked['level'] === 'ok') ? 'active' : 'pending',
                    'expire_at'   => null,
                    'remark'      => $checked['level'] === 'ok' ? '安装向导登记' : '安装向导登记，待官方核验',
                    'bound_at'    => now(),
                    'created_at'  => now(),
                ]);
            }
        } catch (Throwable $e) {
            $license['msg'] .= '（授权码登记失败：' . $e->getMessage() . '）';
        }
    }

    return [
        'ok'         => true,
        'schema'     => $schemaLog,
        'admin_path' => $adminPath,
        'admin_new'  => !empty($seed['admin_created']),
        'license'    => $license,
        'driver'     => $db['driver'],
    ];
}

/**
 * 写入默认数据（幂等）
 * @return array{admin_created:bool}
 */
function seed_defaults(string $adminUser, string $adminPass, string $adminEmail = ''): array
{
    $adminCreated = false;

    // 1) 管理员
    $exists = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('admin'));
    if ($exists === 0) {
        YxDb::insert('admin', [
            'username'   => $adminUser,
            'password'   => YxAuth::hash($adminPass),
            'nickname'   => '超级管理员',
            'role'       => 'owner',
            'status'     => 1,
            'created_at' => now(),
        ]);
        $adminCreated = true;
    }

    // 2) 系统设置
    $defaults = [
        'site_name'       => '优享财务系统',
        'site_sub'        => '业务与财务一体化管理平台',
        'site_url'        => '',
        'icp'             => '',
        'contact_qq'      => '',
        'contact_email'   => $adminEmail,
        'edition'         => 'basic',
        'license_enforce' => '0',
        'currency'        => 'CNY',
        'notice'          => '欢迎使用优享财务系统，请先在「版本与模块」中确认当前版本。',
        'renew_notice_days' => '7',
        'commission_rate' => '20',
    ];
    foreach ($defaults as $k => $v) {
        if (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('setting') . ' WHERE k = ?', [$k]) == 0) {
            YxDb::insert('setting', ['k' => $k, 'v' => $v, 'updated_at' => now()]);
        }
    }

    // 2.5) 「关于我们」默认内容（安装与升级共用同一份实现，避免两处漂移）
    YxAbout::seedDefaults();

    // 2.6) 首页默认横幅（同上，升级工具也会补齐）
    YxBanner::seedDefaults();

    // 2.7) 展示广告位默认内容（同上）
    YxAd::seedDefaults();

    // 3) 产品分组
    if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('product_group')) === 0) {
        $groups = [
            ['云服务器', 10], ['虚拟主机', 20], ['云电脑 / 挂机宝', 30], ['CDN 与增值服务', 40], ['域名与证书', 50],
        ];
        foreach ($groups as $i => $g) {
            YxDb::insert('product_group', ['name' => $g[0], 'sort' => $g[1], 'status' => 1]);
        }
        $gid = [];
        foreach (YxDb::all('SELECT id, name FROM ' . YxDb::table('product_group')) as $row) {
            $gid[$row['name']] = (int)$row['id'];
        }

        $products = [
            ['云服务器', '香港轻量云 2核2G', 'host', 'month', 39.00, 0, -1, 1, 'basic', 'CN2 优化线路，适合建站与开发测试，自助开通。'],
            ['云服务器', '香港精品云 4核8G', 'host', 'month', 129.00, 0, -1, 1, 'basic', 'PCCW 大带宽，三网优化，适合中小型业务。'],
            ['云服务器', '美国 CN2GIA 4核8G', 'host', 'month', 99.00, 0, -1, 1, 'basic', '电信 CN2GIA 回国优化线路，50G 防御。'],
            ['云服务器', '高防云服务器 8核16G', 'vps', 'month', 399.00, 0, 50, 0, 'pro', '200G DDoS 高防，人工开通。'],
            ['虚拟主机', '香港虚拟主机 1G', 'host', 'year', 99.00, 0, -1, 1, 'basic', '免备案，支持 PHP 8.2，含 10 个数据库。'],
            ['虚拟主机', '美国虚拟主机 5G', 'host', 'year', 199.00, 0, -1, 1, 'basic', '不限流量，含 SSL 证书。'],
            ['云电脑 / 挂机宝', '云电脑 4核8G（挂机）', 'vps', 'month', 59.00, 0, 30, 0, 'pro', '适合游戏挂机、脚本托管、24 小时任务。'],
            ['云电脑 / 挂机宝', '游戏云电脑 8核16G', 'vps', 'month', 199.00, 0, 10, 0, 'enterprise', '独立显卡算力，人工实名后开通。'],
            ['CDN 与增值服务', 'CDN 流量包 100G', 'other', 'month', 29.00, 0, -1, 1, 'basic', '全国节点加速，按流量计费。'],
            ['域名与证书', 'SSL 证书（DV 单域名）', 'other', 'year', 0.00, 0, -1, 1, 'basic', '免费 DV 证书，自助申请。'],
        ];
        foreach ($products as $p) {
            YxDb::insert('product', [
                'group_id'    => $gid[$p[0]] ?? 0,
                'name'        => $p[1],
                'type'        => $p[2],
                'cycle'       => $p[3],
                'price'       => $p[4],
                'setup_fee'   => $p[5],
                'stock'       => $p[6],
                'auto_setup'  => $p[7],
                'edition_min' => $p[8],
                'description' => $p[9],
                'status'      => 1,
                'sort'        => 0,
                'created_at'  => now(),
            ]);
        }
    }

    // 4) 资源节点
    if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('node')) === 0) {
        foreach ([
            ['香港 CN2 节点', 'host', 'hk-node-01.example.com', 443, 200, 0],
            ['美国 CN2GIA 节点', 'host', 'us-node-01.example.com', 443, 200, 0],
            ['高防资源池', 'vps', 'shield.example.com', 8443, 50, 0],
        ] as $n) {
            YxDb::insert('node', [
                'name' => $n[0], 'type' => $n[1], 'host' => $n[2], 'port' => $n[3],
                'capacity' => $n[4], 'used' => $n[5], 'status' => 1, 'created_at' => now(),
            ]);
        }
    }

    // 5) 商店分类与应用（原创示例数据，可在后台自行增删）
    if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_category')) === 0) {
        $cats = [
            ['pay', '支付通道', '¥', '聚合码、微信、支付宝等收款通道接入', 10],
            ['cert', '实名认证', 'ID', '实名核验、活体检测、行为验证码', 20],
            ['cloud', '云服务对接', '☁', '对接上游云平台与虚拟化，实现自动开通', 30],
            ['system', '系统增强', '⚙', '续费、同步、退款、限流等能力补强', 40],
            ['notify', '通知消息', '✉', '站务事件推送到群机器人或第三方服务', 50],
            ['theme', '前台主题', '🎨', '用户中心与门户站点模板', 60],
            ['tool', '运维工具', '🛠', '授权检测、备份、对账等辅助工具', 70],
        ];
        foreach ($cats as $c) {
            YxDb::insert('app_category', [
                'key' => $c[0], 'name' => $c[1], 'icon' => $c[2], 'desc' => $c[3], 'sort' => $c[4], 'status' => 1,
            ]);
        }
    }

    if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app')) === 0) {
        $apps = [
            ['pay', 'agg-qrcode', '聚合码支付', '2.0.4', 'basic', 0, 1, '多通道收款码合一，用户扫码后自动匹配通道并回调下单结果。'],
            ['pay', 'wxpay-native', '微信支付通道', '1.6.2', 'basic', 0, 0, '基于微信支付原生接口，支持 PC 扫码与 H5 支付。'],
            ['pay', 'alipay-gateway', '支付宝通道', '1.5.0', 'basic', 0, 0, '对接支付宝当面付，支持退款回调与对账。'],
            ['cert', 'realname-local', '实名认证（本地留存）', '3.1.0', 'pro', 29.00, 1, '认证数据本地留存，支持三要素与人脸活体比对。'],
            ['cert', 'cert-api', '数据核验接口', '1.2.0', 'pro', 19.00, 0, '按次调用权威数据源，低成本完成姓名与证件核验。'],
            ['cert', 'captcha-slider', '行为验证码', '2.3.0', 'basic', 0, 0, '登录、注册、下单等关键动作的人机校验。'],
            ['cloud', 'cloud-vps', '云服务器对接', '4.0.1', 'enterprise', 99.00, 1, '对接上游云平台，下单后可选择多种 IP 类型并自动开通。'],
            ['cloud', 'cloud-game', '游戏云对接', '1.1.3', 'enterprise', 99.00, 0, '对接上游游戏云资源池，支持配置与镜像选择。'],
            ['cloud', 'docker-instance', '容器化实例', '1.0.7', 'enterprise', 59.00, 0, '基于容器快速开设轻量实例，支持一键重装与快照。'],
            ['system', 'auto-renew', '自动续费增强', '2.2.1', 'pro', 0, 1, '到期前自动从余额扣费续期，扣费失败自动转入待续费队列。'],
            ['system', 'upstream-sync-fix', '上游同步修复', '1.0.9', 'pro', 0, 0, '修复无限同步上游信息的问题，补齐同步日志。'],
            ['system', 'refund-callback', '原路退款回调', '1.3.0', 'pro', 0, 0, '监听网关退款通知，自动处理订单与账单回滚。'],
            ['system', 'api-throttle', '接口防刷限流', '1.4.2', 'pro', 0, 0, '按 IP 与账号维度限流，保护开放接口免受恶意请求。'],
            ['system', 'upstream-lookup', '上游资源查询', '1.0.4', 'pro', 0, 0, '一键查询业务对应的上游实例，快速定位资源归属。'],
            ['notify', 'bot-notify', '群机器人通知', '2.0.0', 'pro', 0, 0, '把下单、支付、工单等站务事件推送到群机器人。'],
            ['notify', 'webhook-push', 'Webhook 推送', '1.1.0', 'enterprise', 0, 0, '以标准 Webhook 推送站务事件，便于对接自有系统。'],
            ['theme', 'theme-user-center', '用户中心主题', '1.8.0', 'pro', 0, 1, '轻量用户中心模板，含业务、账单、工单三大模块。'],
            ['theme', 'theme-portal', '门户主题', '1.5.2', 'pro', 0, 0, '门户站点模板，支持产品展示与活动页配置。'],
            ['tool', 'license-checker', '域名授权检测', '1.0.0', 'basic', 0, 1, '检测站点域名授权状态，输出正版 / 未授权结论与到期时间。'],
            ['tool', 'data-backup', '数据备份助手', '2.0.3', 'enterprise', 0, 0, '定期备份数据库与上传目录，支持异地保存。'],
        ];
        foreach ($apps as $i => $a) {
            YxDb::insert('app', [
                'category_key' => $a[0],
                'key'          => $a[1],
                'name'         => $a[2],
                'version'      => $a[3],
                'edition_min'  => $a[4],
                'price'        => $a[5],
                'featured'     => $a[6],
                'description'  => $a[7],
                'changelog'    => "v" . $a[3] . "：适配最新版本核心接口，修复已知问题。",
                'compatible'   => '>= 1.0.0',
                'author'       => '官方应用中心',
                'downloads'    => random_int(50, 900),
                'sales'        => random_int(0, 120),
                'status'       => 'on',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    // 6) 优惠券示例
    if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('coupon')) === 0) {
        YxDb::insert('coupon', [
            'code' => 'YX10', 'type' => 'fixed', 'value' => 10.00, 'min_amount' => 50.00,
            'total' => 1000, 'used' => 0, 'edition_min' => 'pro',
            'expire_at' => date('Y-m-d', strtotime('+90 day')), 'status' => 1,
        ]);
    }

    return ['admin_created' => $adminCreated];
}

/* ================================================================== */
/* 路由                                                                */
/* ================================================================== */

$state      = wizard_state();
$errors     = [];
$installed  = yx_installed();
$force      = !empty($_GET['force']);
$isPost     = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$step       = max(1, min(5, (int)($_GET['step'] ?? 1)));

/* 授权码实时校验（AJAX） */
if (($_GET['ajax'] ?? '') === 'license') {
    wizard_csrf();
    // 第 4 步页面不再重复填写域名，未随请求提交时取第 3 步暂存值
    $ajaxDomain = (string)($_POST['site_domain'] ?? ($state['site_domain'] ?? ''));
    json_out(install_check_license($ajaxDomain, (string)($_POST['license_key'] ?? ''), install_license_required()));
}

/* 允许的步骤：未完成的步骤不允许直接跳转 */
$maxStep = 1;
if (!empty($state['agreed'])) {
    $maxStep = 3;
}
if (!empty($state['agreed']) && is_array($state['db'])) {
    $maxStep = 4;
}
if (!empty($state['done'])) {
    $maxStep = 5;
}
if ($step > $maxStep && !$isPost) {
    redirect('?step=' . $maxStep);
}

if ($isPost) {
    wizard_csrf();
    $action = (string)($_POST['action'] ?? '');

    /* ---------------- 第 1 步：许可协议 ---------------- */
    if ($action === 'step1') {
        if (empty($_POST['agree'])) {
            flash('请先阅读并勾选同意《软件使用协议》与《免责声明》', 'error');
            redirect('?step=1');
        }
        wizard_save(['agreed' => true]);
        redirect('?step=2');
    }

    /* ---------------- 第 2 步：环境检测 ---------------- */
    if ($action === 'step2') {
        if (empty($state['agreed'])) {
            redirect('?step=1');
        }
        $stats = install_check_stats(install_checks());
        if ($stats['blocking']) {
            $msg = '以下检测项未通过，请处理后重新检测：' . implode('、', $stats['blocking']);
            // 仅当「安装授权码」本身是阻断项时才改写成授权提示，
            // 否则会把真实的目录权限等问题掩盖成「未购买授权包」。
            if (in_array('安装授权码', $stats['blocking'], true)) {
                $msg = '未填写授权码：本程序包不可安装使用。程序包本身可免费下载，但安装需先到官方商店单独购买「' . install_auth_label() . '」（' . install_price_text() . '）取得授权码。';
            }
            flash($msg, 'error');
            redirect('?step=2');
        }
        redirect('?step=3');
    }

    /* ---------------- 第 3 步：数据库与站点信息 ---------------- */
    if ($action === 'step3') {
        if (empty($state['agreed'])) {
            redirect('?step=1');
        }
        $siteName   = trim((string)($_POST['site_name'] ?? ''));
        $siteDomain = normalize_domain((string)($_POST['site_domain'] ?? ''));
        $adminPath  = strtolower(trim((string)($_POST['admin_path'] ?? 'admin')));
        $edition    = in_array($_POST['edition'] ?? '', ['basic', 'pro', 'enterprise'], true) ? $_POST['edition'] : 'basic';
        $driver     = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';

        if ($siteName === '') {
            $errors[] = '请填写系统名称';
        }
        $hostOk = (bool)filter_var($siteDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || (bool)filter_var($siteDomain, FILTER_VALIDATE_IP);
        if ($siteDomain === '' || !$hostOk) {
            $errors[] = '请填写正确的网站域名（如 www.example.com，不要带 http:// 与路径）';
        }
        if ($adminPath === '') {
            $adminPath = 'admin';
        }
        $pathErr = yx_admin_path_validate($adminPath);
        if ($pathErr !== '') {
            $errors[] = $pathErr;
        }

        $db = [
            'driver'  => $driver,
            'host'    => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
            'port'    => max(1, (int)($_POST['db_port'] ?? 3306)),
            'name'    => trim((string)($_POST['db_name'] ?? 'youxiang_finance')),
            'user'    => trim((string)($_POST['db_user'] ?? '')),
            'pass'    => (string)($_POST['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
            'prefix'  => preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['prefix'] ?? 'yx_')) ?: 'yx_',
            'path'    => trim((string)($_POST['db_path'] ?? '')) ?: YX_ROOT . '/runtime/youxiang.db',
        ];

        if ($driver === 'mysql') {
            if ($db['name'] === '' || $db['user'] === '') {
                $errors[] = '请填写 MySQL 数据库名与数据库账号';
            }
            if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
                $errors[] = '当前 PHP 未启用 pdo_mysql，无法使用 MySQL';
            }
        } elseif (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $errors[] = '当前 PHP 未启用 pdo_sqlite，请改用 MySQL';
        } else {
            // SQLite 路径归一化为绝对路径：相对路径会落到 PHP 进程的当前工作目录
            // （例如 install/ 下），导致安装后系统找不到数据库。
            $db['path'] = install_abs_db_path((string)$db['path']);
        }

        if (!$errors) {
            [$ok, $pdo, $err] = try_connect($db, !empty($_POST['create_db']));
            if (!$ok) {
                $errors[] = '数据库连接失败：' . $err;
            } else {
                wizard_save([
                    'site_name'   => $siteName,
                    'site_domain' => $siteDomain,
                    'admin_path'  => $adminPath,
                    'edition'     => $edition,
                    'debug'       => !empty($_POST['debug']),
                    'db'          => $db,
                    'create_db'   => !empty($_POST['create_db']),
                ]);
                redirect('?step=4');
            }
        }

        // 校验失败：保留已填内容，回到第 3 步
        $state = array_merge($state, [
            'site_name'   => $siteName,
            'site_domain' => $siteDomain,
            'admin_path'  => $adminPath,
            'edition'     => $edition,
            'debug'       => !empty($_POST['debug']),
        ]);
        $state['db'] = $db;
    }

    /* ---------------- 第 4 步：创始人信息 + 授权码 → 执行安装 ---------------- */
    if ($action === 'step4') {
        if (empty($state['agreed']) || !is_array($state['db'])) {
            redirect('?step=3');
        }
        // 表单提交到当前 URL，若不带 ?step=4 会回落到第 1 步渲染，
        // 校验失败时用户看到的就是许可协议页而不是报错所在的表单。
        $step = 4;
        $adminUser  = trim((string)($_POST['admin_user'] ?? ''));
        $adminPass  = (string)($_POST['admin_pass'] ?? '');
        $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
        $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $licenseKey = trim((string)($_POST['license_key'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $adminUser)) {
            $errors[] = '管理员账号需为 3-32 位字母、数字、下划线、点或连字符';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = '管理员密码至少 8 位';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = '两次输入的密码不一致';
        }
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '请填写有效的邮箱地址';
        }
        $licenseRequired = install_license_required();
        $licenseState    = ['level' => 'none', 'msg' => ''];
        if ($licenseKey !== '' || $licenseRequired) {
            $licenseState = install_check_license((string)$state['site_domain'], $licenseKey, $licenseRequired);
            if ($licenseState['level'] === 'err') {
                $errors[] = $licenseState['msg'];
            }
        }

        if (!$errors) {
            $result = install_run(array_merge($state, [
                'admin_user'  => $adminUser,
                'admin_pass'  => $adminPass,
                'admin_email' => $adminEmail,
                'license_key' => $licenseKey,
            ]));
            if (empty($result['ok'])) {
                $errors = $result['errors'];
            } else {
                // 安装成功后清理会话中的敏感项
                wizard_save([
                    'done'        => true,
                    'result'      => $result,
                    'db'          => null,
                    'admin_user'  => '',
                    'admin_email' => '',
                    'license_key' => '',
                ]);
                redirect('?step=5');
            }
        }

        $state = array_merge($state, [
            'admin_user'  => $adminUser,
            'admin_email' => $adminEmail,
            'license_key' => $licenseKey,
        ]);
    }
}

/* 已安装站点：默认只提示，不重复写入配置（避免误触覆盖线上设置） */
if ($installed && !$force && $step !== 5) {
    $adminUrl = url('admin/login.php');
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>系统已安装 - 优享财务系统</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    </head>
    <body class="install-body">
    <div class="install-wrap">
        <header class="install-head">
            <div class="brand">
                <span class="brand-mark">优</span>
                <div>
                    <h1>优享财务系统</h1>
                    <p>业务与财务一体化管理平台 · 安装向导</p>
                </div>
            </div>
        </header>
        <div class="card">
            <h2>系统已完成安装</h2>
            <div class="alert alert-ok" style="margin:0 0 14px">检测到 includes/config.php 已标记安装，且数据库设置表可用，安装向导不再重复写入。</div>
            <ul class="tight">
                <li>后台入口：<a href="<?= e($adminUrl) ?>"><?= e($adminUrl) ?></a></li>
                <li>客户端入口：<a href="<?= e(url('user/login.php')) ?>"><?= e(url('user/login.php')) ?></a></li>
                <li>门户首页：<a href="<?= e(url('/')) ?>"><?= e(url('/')) ?></a></li>
            </ul>
            <p class="muted small mt-3">升级后补齐新增表 / 字段请执行 <code>php tools/upgrade.php</code>；
            确实需要重新运行安装向导（不会清空数据，但会覆盖站点名称、域名、后台路径等设置）请在地址后追加 <code>?force=1</code>。</p>
            <p class="muted small">出于安全考虑，正式环境应在 Nginx 中禁止访问 <code>install/</code> 目录。</p>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* 检测结果（第 2 步与提交校验共用） */
$checks = install_checks();
$stats  = install_check_stats($checks);

$flash = flash();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 · <?= e(wizard_steps()[$step]) ?> - 优享财务系统</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="install-body">
<div class="install-wrap">
    <header class="install-head">
        <div class="brand">
            <span class="brand-mark">优</span>
            <div>
                <h1>优享财务系统</h1>
                <p>业务与财务一体化管理平台 · 安装向导（第 <?= $step ?> / 5 步）</p>
            </div>
        </div>
    </header>

    <?= wizard_stepper($step) ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'ok' : 'error' ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endforeach; ?>

<?php if ($step === 1): ?>
    <form method="post" action="?step=1" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="step1">
        <h2>软件使用协议与免责声明</h2>
        <div class="agreement">
            <h3>一、协议范围</h3>
            <ol>
                <li>本协议是您（自然人或法人，下称「使用者」）与优享财务系统著作权人之间关于本软件使用的约定，自勾选同意并完成安装起生效。</li>
                <li>本系统为业务与财务一体化管理平台，提供产品管理、订单计费、业务开通、账务流水、工单与客户管理等功能。</li>
                <li>软件按「现状」授权使用。授权以域名（或部署实例）为单位签发，授权码与域名绑定，可在后台「授权中心」核验。</li>
            </ol>
            <h3>二、使用限制</h3>
            <ol>
                <li>不得删除、隐藏或修改软件中的版权标识与授权校验机制，不得将未授权实例对外宣称为正版授权。</li>
                <li>不得对软件进行反向工程、反编译、破解、二次分发或用于搭建面向第三方的盗版分发服务。</li>
                <li>单个授权仅限授权域名对应的站点使用；多站点部署需按站点数量获取相应授权。</li>
            </ol>
            <h3>三、数据与运维</h3>
            <ol>
                <li>使用者应自行建立数据库与上传目录的备份机制，并妥善保管服务器、数据库与后台账号密码。</li>
                <li>系统升级、迁移、二次开发引起的结构与数据变更，由使用者自行评估并承担风险。</li>
                <li>安装完成后建议禁止外部访问 <code>install/</code> 目录、关闭调试模式，并配置 HTTPS。</li>
            </ol>
            <h3>四、免责声明</h3>
            <ol>
                <li>本软件不构成对任何经营资质、备案、实名制、税务合规的替代或担保，使用者应自行确保经营行为符合所在地法律法规。</li>
                <li>因上游供应商、支付通道、实名核验、短信邮件等第三方服务中断、政策调整或接口变更造成的损失，由使用者与对应服务方协商解决。</li>
                <li>因使用者未备份、弱口令、服务器被入侵、误操作、违规使用等自身原因造成的数据丢失或损失，软件著作权人不承担责任。</li>
                <li>在法律允许的最大范围内，软件著作权人对因使用本软件产生的间接损失、利润损失不承担赔偿责任。</li>
            </ol>
            <h3>五、其他</h3>
            <ol>
                <li>本协议未尽事宜，以软件著作权人发布的正式授权协议与官方文档为准。</li>
                <li>继续安装即表示您已阅读、理解并同意上述全部条款。</li>
            </ol>
        </div>
        <label class="checkbox mt-0"><input type="checkbox" name="agree" value="1" <?= !empty($state['agreed']) ? 'checked' : '' ?>> 我已阅读并同意《软件使用协议》与《免责声明》</label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">同意并继续</button>
            <span class="muted">下一项将检测服务器环境、PHP 模块与目录权限。</span>
        </div>
    </form>

<?php elseif ($step === 2): ?>
    <div class="card">
        <h2>环境与权限检测</h2>
        <p class="muted small">带「必检」标记的项目未通过时无法进入下一步；标记为「建议」的项目不影响安装，可按需处理。</p>
        <?php foreach ($checks as $group): ?>
            <div class="check-group">
                <h3><?= e($group['title']) ?></h3>
                <ul class="check-list">
                    <?php foreach ($group['items'] as $item): ?>
                        <li class="<?= $item['status'] === 'fail' ? 'fail' : '' ?>">
                            <span class="ck <?= e($item['status']) ?>"><?= $item['status'] === 'ok' ? '✓' : ($item['status'] === 'warn' ? '!' : '✕') ?></span>
                            <span class="ck-name"><?= e($item['name']) ?><?= !empty($item['required']) ? ' <span class="badge badge-mute">必检</span>' : ' <span class="badge badge-mute">建议</span>' ?>
                                <?php if (!empty($item['hint'])): ?><small><?= e($item['hint']) ?></small><?php endif; ?>
                            </span>
                            <span class="ck-val"><?= e($item['val']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <div class="check-summary">
            <span>通过 <?= (int)$stats['ok'] ?> 项</span>
            <span>建议处理 <?= (int)$stats['warn'] ?> 项</span>
            <span>未通过 <?= (int)$stats['fail'] ?> 项</span>
        </div>
        <form method="post" action="?step=2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="step2">
            <div class="form-actions mt-4">
                <a class="btn btn-ghost" href="?step=1">上一步</a>
                <a class="btn btn-ghost" href="?step=2&amp;recheck=1">重新检测</a>
                <button type="submit" class="btn btn-primary" <?= $stats['blocking'] ? 'disabled' : '' ?>>下一步</button>
                <?php if ($stats['blocking']): ?>
                    <span class="muted small">请先处理：<?= e(implode('、', $stats['blocking'])) ?></span>
                <?php endif; ?>
            </div>
        </form>
    </div>

<?php elseif ($step === 3): ?>
    <form method="post" action="?step=3" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="step3">
        <h2>数据库与站点信息</h2>

        <h3 class="mt-0">站点信息</h3>
        <div class="grid-2">
            <label>系统名称
                <input type="text" name="site_name" value="<?= e($state['site_name']) ?>" required>
            </label>
            <label>网站域名（授权绑定用，不要带协议与路径）
                <input type="text" name="site_domain" value="<?= e($state['site_domain']) ?>" placeholder="www.example.com" required>
            </label>
            <label>后台路径（默认 admin，可自定义以降低扫描风险）
                <input type="text" name="admin_path" value="<?= e($state['admin_path']) ?>" placeholder="admin" pattern="[a-z][a-z0-9_-]{2,23}">
            </label>
            <label>初始版本
                <select name="edition">
                    <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                        <option value="<?= e($k) ?>" <?= $state['edition'] === $k ? 'selected' : '' ?>>
                            <?= e($ed['name']) ?> · <?= e($ed['tag']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="checkbox mt-0"><input type="checkbox" name="debug" value="1" <?= !empty($state['debug']) ? 'checked' : '' ?>> 开启调试模式（生产环境请关闭）</label>
        <p class="muted small">自定义后台路径会生成同名目录（内部为指向 admin/ 的符号链接），直接访问 admin/ 将返回 404；需要服务器允许创建符号链接。</p>

        <h3>数据库</h3>
        <div class="grid-2">
            <label>驱动
                <select name="driver" id="driver">
                    <option value="sqlite" <?= ($state['db']['driver'] ?? 'sqlite') === 'sqlite' ? 'selected' : '' ?>>SQLite（免配置，适合单机）</option>
                    <option value="mysql" <?= ($state['db']['driver'] ?? '') === 'mysql' ? 'selected' : '' ?>>MySQL（推荐生产使用）</option>
                </select>
            </label>
            <label>表前缀
                <input type="text" name="prefix" value="<?= e($state['db']['prefix'] ?? 'yx_') ?>">
            </label>
        </div>

        <div id="sqlite-box" class="grid-2">
            <label>SQLite 数据文件
                <input type="text" name="db_path" value="<?= e($state['db']['path'] ?? (YX_ROOT . '/runtime/youxiang.db')) ?>">
            </label>
        </div>

        <div id="mysql-box" class="grid-2">
            <label>主机 <input type="text" name="db_host" value="<?= e($state['db']['host'] ?? '127.0.0.1') ?>"></label>
            <label>端口 <input type="text" name="db_port" value="<?= e($state['db']['port'] ?? '3306') ?>"></label>
            <label>数据库名 <input type="text" name="db_name" value="<?= e($state['db']['name'] ?? 'youxiang_finance') ?>"></label>
            <label>账号 <input type="text" name="db_user" value="<?= e($state['db']['user'] ?? '') ?>"></label>
            <label>密码 <input type="password" name="db_pass" value="<?= e($state['db']['pass'] ?? '') ?>"></label>
            <label class="checkbox mt-0"><input type="checkbox" name="create_db" value="1" <?= !empty($state['create_db']) ? 'checked' : '' ?>> 库不存在时自动创建（需账号有权限）</label>
        </div>

        <div class="form-actions">
            <a class="btn btn-ghost" href="?step=2">上一步</a>
            <button type="submit" class="btn btn-primary">下一步</button>
            <span class="muted">本步只做连接测试，点击下一步后才写入数据库配置。</span>
        </div>
    </form>
    <script>
    (function () {
        const driver = document.getElementById('driver');
        const box = {sqlite: document.getElementById('sqlite-box'), mysql: document.getElementById('mysql-box')};
        function sync() {
            box.sqlite.style.display = driver.value === 'sqlite' ? '' : 'none';
            box.mysql.style.display = driver.value === 'mysql' ? '' : 'none';
        }
        driver.addEventListener('change', sync);
        sync();
    })();
    </script>

<?php elseif ($step === 4): ?>
    <form method="post" action="?step=4" class="card" id="founderForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="step4">
        <h2>创始人信息与授权码</h2>
        <div class="grid-2">
            <label>管理员账号
                <input type="text" name="admin_user" value="<?= e($state['admin_user']) ?>" placeholder="3-32 位字母数字" required>
            </label>
            <label>管理员密码（至少 8 位）
                <input type="password" name="admin_pass" required autocomplete="new-password">
            </label>
            <label>重复密码
                <input type="password" name="admin_pass2" required autocomplete="new-password">
            </label>
            <label>邮箱（接收系统通知，前台展示为联系邮箱）
                <input type="email" name="admin_email" value="<?= e($state['admin_email']) ?>" placeholder="admin@example.com" required>
            </label>
        </div>

        <label>授权码（本包已内置授权，可留空；格式 XXXX-XXXX-XXXX-XXXX）
            <input type="text" name="license_key" id="licenseKey" class="mono" value="<?= e($state['license_key']) ?>" placeholder="留空即为内置授权" autocomplete="off">
        </label>
        <div class="form-actions" style="margin-bottom:10px">
            <button type="button" class="btn btn-ghost btn-sm" id="btnCheckLicense">检测序列码</button>
            <span class="muted small">绑定域名：<?= e($state['site_domain']) ?></span>
        </div>
        <div id="licenseResult" class="alert alert-warn" style="display:none;margin:0 0 14px"></div>
        <p class="muted small">本安装包已内置「<?= e(install_auth_label()) ?>」（<?= e(install_price_text()) ?>）：<strong>无需填写授权码</strong>，安装完成后即为正版授权（企业版，永久有效）。</p>

        <div class="form-actions">
            <a class="btn btn-ghost" href="?step=3">上一步</a>
            <button type="submit" class="btn btn-primary">开始安装</button>
            <span class="muted">数据库：<?= e($state['db']['driver'] === 'mysql' ? 'MySQL · ' . $state['db']['name'] : 'SQLite · ' . $state['db']['path']) ?></span>
        </div>
    </form>
    <script>
    (function () {
        const btn = document.getElementById('btnCheckLicense');
        const box = document.getElementById('licenseResult');
        const keyEl = document.getElementById('licenseKey');
        const domain = <?= json_encode((string)$state['site_domain'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        btn.addEventListener('click', function () {
            const fd = new FormData();
            fd.append('license_key', keyEl.value.trim());
            fd.append('site_domain', domain);
            const hidden = document.querySelector('input[name=_csrf]');
            if (hidden) { fd.append('_csrf', hidden.value); }
            btn.disabled = true;
            box.style.display = '';
            box.className = 'alert alert-warn';
            box.textContent = '正在校验…';
            fetch('?step=4&ajax=license', {method: 'POST', body: fd, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    const cls = {ok: 'alert-ok', warn: 'alert-warn', err: 'alert-error', none: 'alert-warn'}[d.level] || 'alert-warn';
                    box.className = 'alert ' + cls;
                    box.textContent = d.msg || '校验完成';
                })
                .catch(function () {
                    box.className = 'alert alert-error';
                    box.textContent = '校验请求失败，请检查网络后重试';
                })
                .finally(function () { btn.disabled = false; });
        });
    })();
    </script>

<?php else: ?>
    <?php $result = $state['result'] ?? []; ?>
    <div class="card">
        <h2>安装完成</h2>
        <div class="alert alert-ok" style="margin:0 0 14px">
            系统已安装完成，<strong id="countdown">5</strong> 秒后自动跳转到后台登录页。
        </div>
        <ul class="tight">
            <li>后台入口：<a href="<?= e(url('admin/login.php')) ?>"><?= e(url('admin/login.php')) ?></a></li>
            <li>客户端入口：<a href="<?= e(url('user/login.php')) ?>"><?= e(url('user/login.php')) ?></a></li>
            <li>门户首页：<a href="<?= e(url('/')) ?>"><?= e(url('/')) ?></a></li>
            <li>数据库：<?= e(($result['driver'] ?? 'sqlite') === 'mysql' ? 'MySQL' : 'SQLite') ?></li>
            <li>管理员账号：<?= !empty($result['admin_new']) ? '已按本次填写创建' : '系统中已存在管理员，本次未覆盖' ?></li>
        </ul>
        <?php if (!empty($result['license']['msg'])): ?>
            <p class="muted small mt-3">授权码：<?= e($result['license']['msg']) ?></p>
        <?php endif; ?>
        <?php if (!empty($result['admin_path']) && $result['admin_path'] !== 'admin'): ?>
            <p class="muted small">后台路径已设置为 <code><?= e($result['admin_path']) ?></code>，直接访问 <code>admin/</code> 会返回 404。</p>
        <?php endif; ?>
        <p class="muted small">出于安全考虑，请在生产环境禁止外部访问 <code>install/</code> 目录（Nginx：<code>location ^~ /install/ { return 404; }</code>）。</p>
        <div class="form-actions">
            <a class="btn btn-primary" href="<?= e(url('admin/login.php')) ?>">立即进入后台</a>
        </div>
    </div>
    <?php if (!empty($result['schema'])): ?>
    <div class="card">
        <h2>本次结构变更（<?= count($result['schema']) ?> 项）</h2>
        <ul class="tight muted">
            <?php foreach (array_slice($result['schema'], 0, 60) as $line): ?>
                <li><?= e($line) ?></li>
            <?php endforeach; ?>
            <?php if (count($result['schema']) > 60): ?><li>…（其余略）</li><?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>
    <script>
    (function () {
        var n = 5;
        var el = document.getElementById('countdown');
        var target = <?= json_encode(url('admin/login.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var timer = setInterval(function () {
            n--;
            if (el) { el.textContent = String(Math.max(n, 0)); }
            if (n <= 0) {
                clearInterval(timer);
                window.location.href = target;
            }
        }, 1000);
    })();
    </script>
<?php endif; ?>
</div>
</body>
</html>
