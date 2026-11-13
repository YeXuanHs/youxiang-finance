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
/* 发行包密封数据（重要文字与密钥以密文存放，运行时解密）               */
/* ================================================================== */

/**
 * 读取密封文案
 *
 * 密文见 includes/pkgsecret.php：发行包里不再出现明文产品密钥，也不再出现
 * 官方商店地址与授权包价格等对授权流程有决定性影响的文字。
 * 解密失败返回空串，由调用点按「授权数据不可用」处理，绝不回退到硬编码的明文默认值。
 */
function install_txt(string $field): string
{
    return yx_pkg_sealed($field);
}

/** 授权包价格文案（解密失败时退化为中性描述，不写死价格） */
function install_price_text(): string
{
    $v = install_txt('auth_price');
    return $v !== '' ? $v : '按次付费';
}

/** 正版程序包价格文案（解密失败时退化为中性描述，不写死价格；开源版免费） */
function install_pkg_price_text(): string
{
    $v = install_txt('pkg_price');
    return $v !== '' ? $v : '按次付费';
}

/** 授权码查看入口（解密失败时不展示具体地址） */
function install_auth_page(): string
{
    $v = install_txt('auth_page');
    return $v !== '' ? $v : '用户中心 → 我的授权';
}

/** 授权包名称（解密失败时退化为中性描述） */
function install_auth_label(): string
{
    $v = install_txt('auth_label');
    return $v !== '' ? $v : '授权包';
}

/**
 * 站点目录里的签名授权文件（离线验签，不联网）
 *
 * 授权中心按域名签发的 license.json/授权文件.json 放在站点根目录、根目录下的
 * 「授权文件」目录或 uploads/license 里即可：包内只有公钥，签发私钥从不进发行包，
 * 因此这一步完全离线，断网也能完成安装。
 *
 * 返回 null 表示没有找到授权文件；找到则返回 yx_pkg_license_verify() 的结果 + file。
 */
function install_signed_license(string $domain = ''): ?array
{
    static $cache = [];
    $product = install_package_key();
    $d       = normalize_domain($domain);
    $ck      = $product . '|' . $d;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }
    $root = defined('YX_ROOT') ? YX_ROOT : dirname(__DIR__);
    return $cache[$ck] = yx_pkg_license_find($root, $d, $product);
}

/** 签名授权文件的有效期文案（永久/到期日） */
function install_signed_license_expire(array $data): string
{
    $exp = trim((string)($data['expire_at'] ?? ''));
    return $exp === '' ? '永久有效' : ('到期 ' . $exp);
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
 * 向导阶段（步骤条只展示三站：检测环境 / 创建数据 / 完成安装）
 * 页面步骤与阶段的对应关系：1 协议（不显示步骤条）→ 2 环境 → 3 数据库 → 4 网站与创始人 → 5 安装
 */
function wizard_phases(): array
{
    return [1 => '检测环境', 2 => '创建数据', 3 => '完成安装'];
}

function wizard_phase(int $step): int
{
    if ($step <= 1) {
        return 0;
    }
    if ($step === 2) {
        return 1;
    }
    return $step <= 4 ? 2 : 3;
}

/** 三站式阶段条（许可协议页不显示） */
function wizard_phase_bar(int $step): string
{
    $phase = wizard_phase($step);
    $html  = '<ol class="ywz-steps">';
    foreach (wizard_phases() as $n => $label) {
        $cls = $n < $phase ? 'done' : ($n === $phase ? 'cur' : '');
        $num = $n < $phase ? '✓' : (string)$n;
        $html .= '<li class="ywz-step ' . $cls . '"><span class="ywz-num">' . $num . '</span>'
            . '<span class="ywz-t">' . e($label) . '</span></li>';
    }
    return $html . '</ol>';
}

/**
 * 安装向导样式（内联，避免依赖前台 style.css，升级后样式不会互相影响）
 * 视觉：深色品牌头 + 白色卡片 + 蓝色主色，与官网前台保持同一套配色。
 */
function wizard_css(): string
{
    return <<<'CSS'
<style>
*,*::before,*::after{box-sizing:border-box}
body.ywz-body{margin:0;min-height:100vh;color:#0f172a;
  font:14px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
  background:
    radial-gradient(900px 460px at 8% -10%,rgba(56,132,255,.34),transparent 62%),
    radial-gradient(760px 400px at 98% 2%,rgba(14,165,233,.26),transparent 66%),
    linear-gradient(180deg,#0b1b33 0%,#122c52 30%,#eef2f9 30.02%,#f6f8fc 100%);
  -webkit-font-smoothing:antialiased}
.ywz-shell{max-width:1000px;margin:0 auto;padding:34px 20px 44px}
/* 顶部品牌 */
.ywz-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:2px 4px 0;color:#fff}
.ywz-brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
.ywz-logo{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-size:20px;font-weight:700;color:#fff;
  background:linear-gradient(135deg,#3b82f6,#0ea5e9);box-shadow:0 10px 24px -10px rgba(59,130,246,.95),inset 0 1px 0 rgba(255,255,255,.45)}
.ywz-brand-t strong{display:block;font-size:17px;letter-spacing:.4px;line-height:1.3}
.ywz-brand-t small{display:block;font-size:12px;color:#9fb6d8;letter-spacing:.2px}
.ywz-meta{display:flex;align-items:center;gap:10px;font-size:12px;color:#a9c1e0}
.ywz-ver{padding:3px 10px;border-radius:99px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.16);color:#dbe8ff}
/* 卡片 */
.ywz-card{margin-top:22px;background:#fff;border-radius:18px;overflow:hidden;
  box-shadow:0 30px 70px -28px rgba(9,25,52,.55),0 2px 10px rgba(15,23,42,.05)}
.ywz-stepbar{padding:18px 30px;border-bottom:1px solid #eef2f8;background:linear-gradient(180deg,#fff,#fbfdff)}
.ywz-steps{display:flex;align-items:center;list-style:none;margin:0;padding:0}
.ywz-step{display:flex;align-items:center;gap:9px;flex:1;min-width:0}
.ywz-step:not(:last-child)::after{content:"";flex:1;height:2px;margin:0 10px;border-radius:2px;background:#e8eef8}
.ywz-step.done:not(:last-child)::after{background:linear-gradient(90deg,#34d399,#10b981)}
.ywz-num{flex:0 0 28px;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-size:13px;font-weight:700;
  background:#f1f5fb;color:#94a3b8;border:1px solid #e5ebf5}
.ywz-step.cur .ywz-num{background:linear-gradient(135deg,#2563eb,#0ea5e9);color:#fff;border-color:transparent;box-shadow:0 8px 16px -8px rgba(37,99,235,.95)}
.ywz-step.done .ywz-num{background:#e9f9f0;color:#0f9d58;border-color:#c7efd9}
.ywz-t{font-size:13px;font-weight:600;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ywz-step.cur .ywz-t{color:#0f172a}
.ywz-step.done .ywz-t{color:#0f9d58}
.ywz-main{padding:28px 30px 30px}
/* 标题 */
.ywz-h2{margin:0 0 6px;font-size:19px;font-weight:700;letter-spacing:.2px;color:#0f172a}
.ywz-sub{margin:0 0 18px;color:#64748b;font-size:13px;line-height:1.7}
.ywz-h3{margin:24px 0 12px;padding-left:10px;font-size:14px;font-weight:700;color:#1e293b;border-left:3px solid #3b82f6;line-height:1.2}
/* 提示 */
.ywz-alert{display:flex;gap:8px;align-items:flex-start;padding:11px 14px;border-radius:10px;font-size:13px;line-height:1.6;margin:0 0 14px}
.ywz-alert.ok{background:#effaf3;border:1px solid #cdeedb;color:#127a45}
.ywz-alert.err{background:#fef3f3;border:1px solid #fbd7d7;color:#b42318}
.ywz-alert.warn{background:#fffaeb;border:1px solid #fce4a6;color:#93540a}
.ywz-tip{color:#94a3b8;font-size:12px;line-height:1.6}
.ywz-tip.err{color:#dc2626}
/* 协议 */
.ywz-agreement{max-height:340px;overflow:auto;padding:6px 20px 16px;border:1px solid #e8eef7;border-radius:12px;background:#fbfdff;
  font-size:13px;color:#3f4b5f;line-height:1.85}
.ywz-agreement h3{margin:16px 0 4px;font-size:13.5px;color:#0f172a;font-weight:700}
.ywz-agreement ol{margin:0;padding-left:20px}
.ywz-agreement li{margin:2px 0}
.ywz-agreement code{padding:1px 5px;border-radius:5px;background:#eef2f9;color:#1d4ed8;font-size:12px}
.ywz-chk{display:flex;align-items:center;gap:9px;margin:18px 0 0;font-size:13.5px;color:#1e293b;cursor:pointer;user-select:none}
.ywz-chk input{width:16px;height:16px;accent-color:#2563eb;cursor:pointer}
/* 表单 */
.ywz-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px}
.ywz-field{display:flex;flex-direction:column;gap:6px;min-width:0}
.ywz-field>span{font-size:13px;font-weight:600;color:#334155}
.ywz-field>span em{font-style:normal;font-weight:500;color:#94a3b8;font-size:12px}
.ywz-field input[type=text],.ywz-field input[type=password],.ywz-field input[type=email],.ywz-field select{
  width:100%;height:40px;padding:0 12px;border:1px solid #dbe3ee;border-radius:10px;background:#fff;
  font-size:13.5px;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s}
.ywz-field input:focus,.ywz-field select:focus{border-color:#7db3f8;box-shadow:0 0 0 3px rgba(37,99,235,.13)}
.ywz-field .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.6px}
.ywz-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:24px;padding-top:18px;border-top:1px dashed #e8eef7}
.ywz-actions .ywz-tip{flex:1;min-width:180px}
.ywz-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:40px;padding:0 20px;border-radius:10px;
  border:1px solid transparent;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;line-height:1;
  transition:transform .12s,box-shadow .15s,background .15s}
.ywz-btn.primary{color:#fff;background:linear-gradient(135deg,#2563eb,#1d4ed8);box-shadow:0 12px 24px -14px rgba(37,99,235,1)}
.ywz-btn.primary:hover{transform:translateY(-1px);box-shadow:0 16px 26px -14px rgba(37,99,235,1)}
.ywz-btn.ghost{color:#334155;background:#fff;border-color:#dbe3ee}
.ywz-btn.ghost:hover{border-color:#b8c7dd;background:#f8fafc}
.ywz-btn.sm{height:34px;padding:0 14px;font-size:13px}
.ywz-btn[disabled]{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
/* 检测表 */
.ywz-checkgroup{border:1px solid #e8eef7;border-radius:12px;overflow:hidden;margin:0 0 14px;background:#fff}
.ywz-checkgroup>h3{margin:0;padding:11px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;
  font-size:13px;font-weight:700;color:#1e293b;background:#f8fafc;border-bottom:1px solid #eef2f7}
.ywz-gsum{font-weight:500;font-size:12px;color:#94a3b8}
.ywz-crow{display:grid;grid-template-columns:minmax(0,1fr) 180px 68px;gap:14px;align-items:center;padding:10px 16px;border-top:1px solid #f2f6fb;font-size:13px}
.ywz-crow:first-of-type{border-top:0}
.ywz-crow.is-fail{background:#fffafa}
.ywz-cname b{font-weight:600;color:#0f172a}
.ywz-chint{display:block;margin-top:2px;color:#94a3b8;font-size:12px;line-height:1.55;word-break:break-all}
.ywz-cval{color:#475569;font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;word-break:break-all}
.ywz-cst{text-align:right}
.ywz-tag{display:inline-block;min-width:50px;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;text-align:center;line-height:1.6}
.tag-ok{background:#e9f9f0;color:#0f9d58}
.tag-warn{background:#fff6e6;color:#b45309}
.tag-fail{background:#fef2f2;color:#dc2626}
.ywz-badge{display:inline-block;margin-left:6px;padding:0 6px;border-radius:6px;background:#f1f5f9;color:#64748b;font-size:11px;font-weight:600}
.ywz-sum{display:flex;align-items:center;gap:18px;flex-wrap:wrap;padding:11px 16px;margin:2px 0 0;border-radius:10px;background:#f8fafc;color:#475569;font-size:12.5px}
.ywz-sum i{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:1px}
.ywz-sum i.ok{background:#10b981}.ywz-sum i.warn{background:#f59e0b}.ywz-sum i.fail{background:#ef4444}
/* 独立站点说明 */
.ywz-note{display:flex;gap:12px;padding:14px 16px;border-radius:12px;margin:0 0 20px;
  background:linear-gradient(135deg,#eff6ff,#f5fbff);border:1px solid #dbeafe}
.ywz-note-ico{flex:0 0 34px;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:#fff;font-weight:700;
  background:linear-gradient(135deg,#2563eb,#0ea5e9);box-shadow:0 8px 16px -10px rgba(37,99,235,1)}
.ywz-note b{display:block;color:#1e3a8a;font-size:13.5px;margin-bottom:2px}
.ywz-note p{margin:0;color:#475569;font-size:12.5px;line-height:1.75}
.ywz-note code{padding:1px 5px;border-radius:5px;background:#e6f0ff;color:#1d4ed8;font-size:12px}
/* 数据库选择卡 */
.ywz-dbs{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:0 0 16px}
.ywz-dbcard{position:relative;display:block;cursor:pointer}
.ywz-dbcard input{position:absolute;opacity:0;width:0;height:0}
.ywz-dbcard-in{display:block;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:12px;background:#fff;transition:.15s}
.ywz-dbcard-in b{display:block;font-size:13.5px;color:#0f172a}
.ywz-dbcard-in em{display:block;font-style:normal;font-size:12px;color:#94a3b8;margin-top:3px}
.ywz-dbcard input:checked+.ywz-dbcard-in{border-color:#2563eb;background:linear-gradient(135deg,#f5f9ff,#eef5ff);
  box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.ywz-panel{border:1px solid #eef2f8;border-radius:12px;padding:16px;background:#fbfdff}
.ywz-panel[hidden]{display:none}
/* 安装进度 */
.ywz-bar{height:10px;border-radius:99px;background:#eef2f9;overflow:hidden}
.ywz-bar>i{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#22c55e,#0ea5e9);transition:width .28s ease}
.ywz-barmeta{display:flex;align-items:center;justify-content:space-between;margin-top:8px;font-size:12px;color:#64748b}
.ywz-log{list-style:none;margin:16px 0 0;padding:14px 16px;border-radius:12px;background:#0b1a33;color:#c7d7ee;
  font:12px/1.95 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;max-height:320px;overflow:auto}
.ywz-log li{opacity:0;transform:translateY(4px);transition:opacity .22s,transform .22s;word-break:break-all}
.ywz-log li.show{opacity:1;transform:none}
.ywz-log li .k{color:#4ade80;margin-right:6px}
/* 完成 */
.ywz-done-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:6px}
.ywz-done-ico{flex:0 0 44px;width:44px;height:44px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:22px;
  background:linear-gradient(135deg,#34d399,#0ea5e9);box-shadow:0 14px 26px -14px rgba(16,185,129,1)}
.ywz-kv{margin:16px 0 0;border:1px solid #eef2f8;border-radius:12px;overflow:hidden}
.ywz-kv-row{display:grid;grid-template-columns:130px minmax(0,1fr);gap:12px;padding:10px 16px;border-top:1px solid #f2f6fb;font-size:13px}
.ywz-kv-row:first-child{border-top:0}
.ywz-kv-row dt{margin:0;color:#94a3b8;font-size:12.5px}
.ywz-kv-row dd{margin:0;color:#0f172a;word-break:break-all}
.ywz-kv-row dd a{color:#1d4ed8;text-decoration:none}
.ywz-kv-row dd a:hover{text-decoration:underline}
.ywz-kv-row code{padding:1px 6px;border-radius:5px;background:#f1f5f9;color:#1e293b;font-size:12px}
.ywz-next{margin:18px 0 0;padding:14px 16px;border-radius:12px;background:#f8fafc;border:1px solid #eef2f8}
.ywz-next b{display:block;font-size:13px;color:#1e293b;margin-bottom:6px}
.ywz-next ol{margin:0;padding-left:20px;color:#475569;font-size:12.5px;line-height:1.9}
.ywz-foot{margin-top:18px;text-align:center;color:#8899b0;font-size:12px;line-height:1.8}
.ywz-foot p{margin:0}
.ywz-loading{position:fixed;inset:0;z-index:99;display:none;place-items:center;background:rgba(9,22,42,.62);backdrop-filter:blur(2px)}
.ywz-loading.on{display:grid}
.ywz-loading-box{background:#fff;border-radius:14px;padding:22px 28px;text-align:center;box-shadow:0 30px 60px -20px rgba(0,0,0,.5)}
.ywz-spin{width:30px;height:30px;margin:0 auto 10px;border-radius:50%;border:3px solid #e2e8f0;border-top-color:#2563eb;animation:ywzspin .9s linear infinite}
@keyframes ywzspin{to{transform:rotate(360deg)}}
@media (max-width:760px){
  .ywz-shell{padding:22px 12px 32px}
  .ywz-meta .ywz-meta-t{display:none}
  .ywz-main{padding:20px 18px 24px}
  .ywz-stepbar{padding:14px 18px}
  .ywz-t{display:none}
  .ywz-grid,.ywz-dbs{grid-template-columns:1fr}
  .ywz-crow{grid-template-columns:minmax(0,1fr) auto;gap:6px 10px}
  .ywz-cval{grid-column:1/-1}
  .ywz-gsum{display:none}
  .ywz-kv-row{grid-template-columns:1fr}
}
</style>
CSS;
}

/**
 * 官网域名（授权中心地址）
 *
 * 独立站点要求：本系统按域名授权，购买授权后买家在自有域名上独立经营，
 * 该站点与官网不是同一个站点，安装向导不允许把系统部署到官网域名上。
 */
function install_official_host(): string
{
    $host = normalize_domain((string)config('license.official_api', ''));
    return $host;
}

/** 官网主域名（含裸域名） */
function install_official_domains(): array
{
    $host = install_official_host();
    $base = preg_replace('/^www\./', '', $host);
    return array_values(array_unique(array_filter([$host, $base])));
}

/** 是否属于官网域名（等于官网或官网的子域名） */
function install_is_official_domain(string $domain): bool
{
    $d = normalize_domain($domain);
    if ($d === '') {
        return false;
    }
    $d = preg_replace('/^www\./', '', $d);
    foreach (install_official_domains() as $official) {
        $official = preg_replace('/^www\./', '', $official);
        if ($d === $official || substr($d, -strlen('.' . $official)) === '.' . $official) {
            return true;
        }
    }
    return false;
}

/** 安装向导当前所在域名 */
function install_current_host(): string
{
    return normalize_domain((string)($_SERVER['HTTP_HOST'] ?? ''));
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

    // 4) 独立站点部署前提：本站必须与官网是两个站点（独立域名、独立数据库、独立数据）
    $currentHost = install_current_host();
    $official    = install_official_host();
    $siteDomain  = normalize_domain((string)(wizard_state()['site_domain'] ?? ''));
    $onOfficial  = $currentHost !== '' && install_is_official_domain($currentHost);
    $groups['independent'] = [
        'title' => '独立站点部署',
        'items' => [
            [
                'name'     => '部署形态',
                'val'      => '独立站点',
                'status'   => 'ok',
                'hint'     => '购买授权后在自有域名上独立部署、独立经营，与官网互不隶属',
                'required' => false,
            ],
            [
                'name'     => '当前访问域名',
                'val'      => $onOfficial ? $currentHost . '（官网域名）' : ($currentHost !== '' ? $currentHost : '命令行'),
                'status'   => $onOfficial ? 'fail' : 'ok',
                'hint'     => $onOfficial
                    ? '当前访问的是官网域名 ' . $official . '：官网与独立站点不能为同一站点，请改用您自己的独立站点域名访问本向导'
                    : '当前域名不是官网域名 ' . $official . '，可作为独立站点安装',
                'required' => true,
            ],
            [
                'name'     => '授权绑定域名',
                'val'      => $siteDomain !== '' ? $siteDomain : '待填写（创建数据时填写）',
                'status'   => $siteDomain !== '' ? (install_is_official_domain($siteDomain) ? 'fail' : 'ok') : 'warn',
                'hint'     => $siteDomain !== ''
                    ? '授权按域名签发，一个授权对应一个独立站点'
                    : '网站域名在“创建数据 → 网站配置”中填写，授权与数据均绑定该独立域名',
                'required' => false,
            ],
            [
                'name'     => '数据归属',
                'val'      => '本站独立存储',
                'status'   => 'ok',
                'hint'     => '业务、订单、账务与客户数据全部保存在本站数据库，官网不存储、不代管',
                'required' => false,
            ],
        ],
    ];

    // 5) 授权前提：正版程序包安装必须取得授权码（授权码属于安装授权包，程序包本身不含）
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
 * 正版程序包安装必须取得授权码：这里在第 2 步就提示「未填写授权码不可安装」，
 * 让买家在填数据库、建管理员之前就知道必须先去官方商店购买授权包（价格文案来自密封数据）
 * 取得授权码，而不是安装到最后一步才被拦下。
 */
function install_state_key(): string
{
    $state = wizard_state();
    return strtoupper((string)preg_replace('/\s+/', '', trim((string)($state['license_key'] ?? ''))) ?? '');
}

function install_license_gate_item(): array
{
    if (!install_license_required()) {
        return [
            'name'     => '安装授权码',
            'val'      => '开源版 · 不需要',
            'status'   => 'ok',
            'hint'     => '开源版发行包不启用授权模块，无需授权码即可安装',
            'required' => false,
        ];
    }
    // 发行包文案缺失（商店地址为空）属于包本身损坏，必须阻断：此时无法判断授权闭环是否可用
    if (!yx_pkg_sealed_ok()) {
        return [
            'name'     => '发行包授权数据',
            'val'      => '数据缺失',
            'status'   => 'fail',
            'hint'     => '发行包内的授权文案数据缺失（includes/pkgsecret.php 被删改或打包不完整），安装已中止。请到官方重新下载正版程序包后再安装。',
            'required' => true,
        ];
    }
    $key = install_state_key();
    // 已有签名授权文件（离线验签通过）时，第 2 步直接显示为已就绪，不必再等第 4 步
    $signed = $key === '' ? install_signed_license((string)(wizard_state()['site_domain'] ?? '')) : null;
    if ($signed !== null && !empty($signed['ok'])) {
        return [
            'name'     => '安装授权码',
            'val'      => '签名授权文件 · 离线验签通过',
            'status'   => 'ok',
            'hint'     => '站点目录中已找到授权中心签发的授权文件（' . (string)($signed['file'] ?? 'license.json') . '），'
                . '域名 ' . (string)$signed['data']['domain'] . ' · ' . install_signed_license_expire($signed['data'])
                . '；第 4 步将在本机离线验签，无需联网。',
            'required' => false,
        ];
    }
    if ($key === '') {
        // 第 2 步不阻断：授权码在第 4 步才填写，此处只做提醒。
        // 若标记为 required+fail 会进入 blocking 列表、把「下一步」永久禁用，
        // 用户反而走不到第 4 步去填码。真正的强制校验在第 4 步（install_check_license）。
        return [
            'name'     => '安装授权码',
            'val'      => '待填写（第 4 步）',
            'status'   => 'warn',
            'hint'     => '本程序包为正版程序包：正版程序包需单独购买（' . install_pkg_price_text() . '，开源版免费），安装还必须填写授权码。授权码属「' . install_txt('auth_label') . '」，需在官方商店单独按次购买（' . install_price_text() . '，与绑定域名一一对应），'
                . '支付成功后自动签发（登录后「我的订单 → 我的授权」页，地址 ' . install_auth_page() . '），在第 4 步「创始人信息与授权码」填入；未填写授权码将无法完成安装。',
            'required' => false,
        ];
    }
    return [
        'name'     => '安装授权码',
        'val'      => substr($key, 0, 4) . '-****-****-' . substr($key, -4),
        'status'   => 'ok',
        'hint'     => '已填写授权码，进入第 4 步后将按当前域名复核（不匹配或未购买将终止安装）',
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
 * 正版程序包（license.mode = official 且未锁定）必须凭授权码安装：
 * 卖方在优享商店购买授权包后签发授权码，安装时强校验。
 * 开源版发行包（mode = community，lock_mode = true）不启用授权模块，不做强制。
 * 可用 setting/环境变量 YX_LICENSE_REQUIRED=0 关闭（例如内网自用部署）。
 */
function install_license_required(): bool
{
    $mode = (string)config('license.mode', 'official');
    if ($mode === 'community' || (bool)config('license.lock_mode', false)) {
        return false;
    }
    $env = getenv('YX_LICENSE_REQUIRED');
    if ($env !== false && $env !== '') {
        return $env === '1';
    }
    return true;
}

/**
 * 当前发行包对应的主程序包类型（用于复核安装授权码）
 *
 * 正版包与开源包分别对应 official / community 两种产品，两者的码互不通用。
 * 授权码的复核不在本地完成（发行包里已不含任何签发/校验密钥），
 * 统一交授权中心在线复核，见 YxLicense::remoteVerifyPackageCode()。
 */
function install_package_key(): string
{
    return (string)config('license.mode', 'official') === 'community' ? 'community' : 'official';
}

/**
 * 校验授权码
 * level: ok 通过 | warn 本地无法复核（待官方核验） | err 明确不通过
 */
function install_check_license(string $domain, string $key, bool $required = false): array
{
    $domain = normalize_domain($domain);
    $raw    = trim($key);
    $isJson = $raw !== '' && $raw[0] === '{';
    $key    = $isJson ? '' : strtoupper(preg_replace('/\s+/', '', $raw) ?? '');

    // 0.1) 粘贴的签名授权文件：包内只有公钥，离线验签即可，不联网也不需要产品密钥
    if ($isJson) {
        if ($domain === '') {
            return ['level' => 'err', 'msg' => '请先填写网站域名：签名授权文件与域名绑定，没有域名无法验签。'];
        }
        $lic = yx_pkg_license_parse($raw);
        if ($lic === null) {
            return ['level' => 'err', 'msg' => '授权文件内容不是合法的 JSON：请原样粘贴授权中心交付的 license.json 内容。'];
        }
        $chk = yx_pkg_license_verify($lic, null, $domain, install_package_key());
        if (empty($chk['ok'])) {
            return ['level' => 'err', 'msg' => '签名授权文件校验不通过：' . (string)($chk['message'] ?? '未知原因')];
        }
        return [
            'level'   => 'ok',
            'msg'     => '校验通过：签名授权文件在本机离线验签通过（域名 ' . (string)$chk['data']['domain'] . '，' . install_signed_license_expire($chk['data']) . '），无需联网。',
            'edition' => (string)$chk['data']['edition'],
            'code'    => (string)$chk['data']['code'],
            'signed'  => true,
        ];
    }

    // 0.2) 站点目录里的签名授权文件（离线）：填了码且与文件内一致，或没填码但文件有效
    $signed = $domain !== '' ? install_signed_license($domain) : null;
    if ($signed !== null && !empty($signed['ok'])) {
        $signedCode = (string)($signed['data']['code'] ?? '');
        if ($key === '' || ($signedCode !== '' && hash_equals($signedCode, $key))) {
            return [
                'level'   => 'ok',
                'msg'     => '校验通过：站点目录中的签名授权文件（' . (string)($signed['file'] ?? 'license.json') . '）离线验签通过（域名 '
                    . (string)$signed['data']['domain'] . '，' . install_signed_license_expire($signed['data']) . '），无需联网。',
                'edition' => (string)$signed['data']['edition'],
                'code'    => $signedCode,
                'signed'  => true,
            ];
        }
        // 填写的授权码与文件内的不一致：继续走在线复核，不把「填错码」误判成文件损坏
    }

    // 密封文案缺失不再阻断：发行包只带文案密文，授权码校验已改为在线完成，
    // 本地没有密钥也不影响复核（文案缺失时 install_txt() 退化为中性描述）。

    if ($key === '') {
        if ($required) {
            return ['level' => 'err', 'msg' => '未填写授权码：本发行包为正版程序包，必须填写与当前域名绑定的授权码（或放入授权中心签发的签名授权文件 license.json）才能安装。授权码在优享商店购买「' . install_auth_label() . '」（' . install_price_text() . '）后自动签发，可登录后到「我的订单 → 我的授权」（' . install_auth_page() . '）查看。'];
        }
        return ['level' => 'none', 'msg' => '未填写授权码：本发行包不校验授权码，可继续安装。'];
    }
    if (!preg_match('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $key)) {
        return ['level' => 'err', 'msg' => '授权码格式不正确：应为 4 组、每组 4 位十六进制字符（示例 8F41-CB20-2D8B-9A76）。'];
    }
    if ($domain === '') {
        return ['level' => 'err', 'msg' => '请先填写网站域名：授权码与域名绑定，没有域名无法校验。'];
    }

    // 1) 本地授权库（权威记录）
    $row = null;
    try {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('license') . ' WHERE domain = ? LIMIT 1', [$domain]);
    } catch (Throwable $e) {
        $row = null;
    }
    if ($row) {
        $stored = strtoupper((string)$row['license_key']);
        if ($stored !== '' && hash_equals($stored, $key)) {
            $ed  = YxEdition::EDITIONS[$row['edition']]['name'] ?? (string)$row['edition'];
            $exp = !empty($row['expire_at']) ? '，到期 ' . $row['expire_at'] : '，永久有效';
            $st  = (string)$row['status'] === 'active' ? '已授权' : ('状态 ' . (string)$row['status']);
            return [
                'level'   => 'ok',
                'msg'     => '校验通过：与本地授权库中该域名的授权码一致（' . $ed . '，' . $st . $exp . '）。',
                'edition' => (string)$row['edition'],
            ];
        }
        if ($stored !== '') {
            return [
                'level' => 'err',
                'msg'   => '授权码不匹配：本地授权库中域名 ' . $domain . ' 登记的授权码为 ' . substr($stored, 0, 4) . '-****-****-' . substr($stored, -4) . '，与本次填写的授权码不一致。',
            ];
        }
    }

    // 2) 按官方签发规则复核（仅适用于已安装站点：那时才有站点密钥）
    //    全新安装时 app.key 为空，channelSecret() 也是空串，generateKey() 会返回空码，
    //    这一步不会放行，必须走下面的授权中心在线复核。
    if (YxLicense::channelSecret() !== '') {
        foreach (['enterprise', 'pro', 'basic'] as $ed) {
            if (hash_equals(YxLicense::generateKey($domain, $ed, ''), $key)) {
                return [
                    'level'   => 'ok',
                    'msg'     => '校验通过：与域名 ' . $domain . '、版本 ' . (YxEdition::EDITIONS[$ed]['name'] ?? $ed) . ' 的签发规则一致（永久授权）。',
                    'edition' => $ed,
                ];
            }
        }
    }

    // 2.1) 商店签发的安装授权码：交授权中心在线复核
    //      发行包里不再包含可用于签发或本地复核的密钥（密钥只存在于授权中心），
    //      因此这里必须联网；网络不可达时正版包不放行，绝不用本地推算代替。
    $remote = YxLicense::remoteVerifyPackageCode($domain, install_package_key(), $key);
    if ($remote !== null && !empty($remote['ok'])) {
        return [
            'level'   => 'ok',
            'msg'     => '校验通过：授权中心确认该授权码已签发给域名 ' . $domain . '。',
            'edition' => (string)($remote['edition'] !== '' ? $remote['edition'] : config('edition.current', 'basic')),
        ];
    }
    if ($remote !== null) {
        $why = trim((string)($remote['message'] ?? ''));
        return [
            'level' => 'err',
            'msg'   => '授权码无效：授权中心未通过该域名的安装授权码校验'
                . ($why !== '' ? '（' . $why . '）' : '')
                . '。请确认授权码与当前域名一致，或在优享商店重新获取。',
        ];
    }
    if ($required) {
        $err = YxLicense::lastError();
        return [
            'level' => 'err',
            'msg'   => '无法完成在线复核：' . ($err !== '' ? $err : '授权中心不可达')
                . '。安装授权码必须由授权中心在线校验，请确认服务器可访问 '
                . (YxLicense::officialApi() !== '' ? YxLicense::officialApi() : '官方授权中心')
                . '（放行出网 443）后重试；内网自用可用环境变量 YX_LICENSE_REQUIRED=0 跳过强制校验。',
        ];
    }
    return [
        'level' => 'warn',
        'msg'   => '本地授权库中没有该域名的授权记录，也无法用本站密钥复核该授权码。可继续安装，系统会登记为「待官方核验」，安装完成后在后台「授权中心」联网核验。',
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
    $steps  = [];

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
    $steps[] = [
        'ok'   => true,
        'text' => '数据库连接成功（' . ($db['driver'] === 'mysql'
            ? 'MySQL · ' . $db['host'] . ':' . (int)$db['port'] . ' · ' . $db['name']
            : 'SQLite · ' . $db['path']) . '）',
    ];

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
    $steps[] = ['ok' => true, 'text' => '已写入数据库配置（includes/config.php）'];

    $conf = reload_conf();
    YxDb::configure($conf['db']);

    try {
        $schemaLog = run_schema(YxDb::pdo(), $db['driver']);
        foreach ($schemaLog as $i => $line) {
            $steps[] = ['ok' => true, 'text' => '[sql' . ($i + 1) . '] 执行成功 · ' . $line];
        }
        $steps[] = [
            'ok'   => true,
            'text' => '数据结构初始化完成（新建或补齐 ' . count($schemaLog) . ' 项）',
        ];
        $seed      = seed_defaults((string)$state['admin_user'], (string)$state['admin_pass'], (string)$state['admin_email']);
        $steps[] = [
            'ok'   => true,
            'text' => '基础数据写入完成（' . (!empty($seed['admin_created']) ? '创始人账号已创建' : '管理员已存在，未覆盖') . '）',
        ];

        set_setting('edition', $newConf['edition']['current']);
        set_setting('site_name', (string)$state['site_name']);
        set_setting('site_domain', normalize_domain((string)$state['site_domain']));
        set_setting('site_url', 'https://' . normalize_domain((string)$state['site_domain']));
        set_setting('license_mode', (string)config('license.mode', 'official'));
        if ((string)$state['admin_email'] !== '') {
            set_setting('contact_email', (string)$state['admin_email']);
        }
        set_setting('admin_path', $adminPath);
        // 独立站点标记：本站为买家自有的独立站点，与官网各自独立运营
        set_setting('site_mode', 'independent');
        set_setting('official_site', install_official_host());
        set_setting('independent_site', '1');
        $steps[] = [
            'ok'   => true,
            'text' => '站点配置写入完成（独立站点：' . normalize_domain((string)$state['site_domain']) . '）',
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['初始化数据失败：' . $e->getMessage()]];
    }

    // 4) 授权码登记（不改写状态为 active，除非已在本机复核通过）
    $license = [
        'level' => 'none',
        'msg'   => install_license_required() ? '未登记授权码' : '开源版 · 未启用授权校验',
    ];
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

    $steps[] = [
        'ok'   => true,
        'text' => $key === ''
            ? (install_license_required() ? '未填写授权码，跳过授权登记' : '开源版无需授权码，跳过授权登记')
            : ($license['level'] === 'ok'
                ? '授权登记完成（域名 ' . normalize_domain((string)$state['site_domain']) . '，本机校验通过）'
                : '授权信息已登记，可在后台「授权中心」核验'),
    ];
    $steps[] = ['ok' => true, 'text' => '数据库配置已就绪，安装收尾完成'];

    return [
        'ok'         => true,
        'schema'     => $schemaLog,
        'admin_path' => $adminPath,
        'admin_new'  => !empty($seed['admin_created']),
        'license'    => $license,
        'driver'     => $db['driver'],
        'steps'      => $steps,
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
    $maxStep = 2;
}
if (!empty($state['env_ok'])) {
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
            flash('请阅读《软件使用协议》与《免责声明》后点击「我同意」', 'error');
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
            if (in_array('当前访问域名', $stats['blocking'], true)) {
                $msg = '当前访问的是优享财务系统官网域名，官网与独立站点不能使用同一站点：请在您自己的独立站点域名上访问安装向导。';
            } elseif (in_array('安装授权码', $stats['blocking'], true)) {
                $msg = '未填写授权码：本程序包不可安装使用。正版程序包需单独购买（' . install_pkg_price_text() . '，开源版免费），安装还需购买「' . install_auth_label() . '」（' . install_price_text() . '）取得授权码。';
            }
            flash($msg, 'error');
            redirect('?step=2');
        }
        wizard_save(['env_ok' => true]);
        redirect('?step=3');
    }

    /* ---------------- 第 3 步：数据库信息 ---------------- */
    if ($action === 'step3') {
        if (empty($state['agreed'])) {
            redirect('?step=1');
        }
        if (empty($state['env_ok'])) {
            flash('请先完成环境检测', 'error');
            redirect('?step=2');
        }
        $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';

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
                $errors[] = '请填写数据库名与数据库用户名';
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
                    'db'        => $db,
                    'create_db' => !empty($_POST['create_db']),
                ]);
                redirect('?step=4');
            }
        }

        // 校验失败：保留已填内容，回到第 3 步
        $state['db'] = $db;
    }

    /* ---------------- 第 4 步：网站配置 + 创始人信息 + 序列码 → 执行安装 ---------------- */
    if ($action === 'step4') {
        if (empty($state['agreed']) || !is_array($state['db'])) {
            redirect('?step=3');
        }
        // 表单提交到当前 URL，若不带 ?step=4 会回落到第 1 步渲染，
        // 校验失败时用户看到的就是许可协议页而不是报错所在的表单。
        $step = 4;
        $siteName   = trim((string)($_POST['site_name'] ?? ''));
        $siteDomain = normalize_domain((string)($_POST['site_domain'] ?? ''));
        $adminPath  = strtolower(trim((string)($_POST['admin_path'] ?? 'admin')));
        $edition    = in_array($_POST['edition'] ?? '', ['basic', 'pro', 'enterprise'], true) ? $_POST['edition'] : 'basic';
        $adminUser  = trim((string)($_POST['admin_user'] ?? ''));
        $adminPass  = (string)($_POST['admin_pass'] ?? '');
        $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
        $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $licenseKey = trim((string)($_POST['license_key'] ?? ''));

        if ($siteName === '') {
            $errors[] = '请填写系统名称';
        }
        $hostOk = (bool)filter_var($siteDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || (bool)filter_var($siteDomain, FILTER_VALIDATE_IP);
        if ($siteDomain === '' || !$hostOk) {
            $errors[] = '请填写正确的网站域名（如 www.example.com，不要带 http:// 与路径）';
        } elseif (install_is_official_domain($siteDomain)) {
            $errors[] = '网站域名不能是优享财务系统官网域名（' . install_official_host() . '）：官网与独立站点不能使用同一站点，请填写您自己的独立站点域名。';
        }
        if ($adminPath === '') {
            $adminPath = 'admin';
        }
        $pathErr = yx_admin_path_validate($adminPath);
        if ($pathErr !== '') {
            $errors[] = $pathErr;
        }
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
            $licenseState = install_check_license($siteDomain, $licenseKey, $licenseRequired);
            if ($licenseState['level'] === 'err') {
                $errors[] = $licenseState['msg'];
            } elseif (!empty($licenseState['code'])) {
                // 签名授权文件里带了授权码：安装时按该码登记，未购买/未填码也能对上授权库
                $licenseKey = (string)$licenseState['code'];
            }
        }

        if (!$errors) {
            $result = install_run(array_merge($state, [
                'site_name'   => $siteName,
                'site_domain' => $siteDomain,
                'admin_path'  => $adminPath,
                'edition'     => $edition,
                'debug'       => !empty($_POST['debug']),
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
                    'env_ok'      => true,
                    'agreed'      => true,
                    'site_name'   => $siteName,
                    'site_domain' => $siteDomain,
                    'admin_path'  => $adminPath,
                    'edition'     => $edition,
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
            'site_name'   => $siteName,
            'site_domain' => $siteDomain,
            'admin_path'  => $adminPath,
            'edition'     => $edition,
            'admin_user'  => $adminUser,
            'admin_email' => $adminEmail,
            'license_key' => $licenseKey,
        ]);
    }
}

/* 未完成安装时不允许直接打开安装结果页 */
if ($step === 5 && empty($state['done'])) {
    redirect('?step=' . ($maxStep > 0 ? $maxStep : 1));
}

/* 已安装站点：默认只提示，不重复写入配置（避免误触覆盖线上设置） */
if ($installed && !$force && $step !== 5) {
    $adminUrl = url('admin/login.php');
    $siteName = (string)config('app.name', '优享财务系统');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>系统已安装 · <?= e($siteName) ?></title>
<?= wizard_css() ?>
</head>
<body class="ywz-body">
<div class="ywz-shell">
    <header class="ywz-top">
        <div class="ywz-brand">
            <span class="ywz-logo">优</span>
            <span class="ywz-brand-t">
                <strong><?= e($siteName) ?></strong>
                <small>业务与财务一体化管理平台 · 安装向导</small>
            </span>
        </div>
        <div class="ywz-meta"><span class="ywz-ver">安装向导 v1.6.4</span></div>
    </header>

    <main class="ywz-card">
        <div class="ywz-main">
            <div class="ywz-done-head">
                <span class="ywz-done-ico">✓</span>
                <div>
                    <h2 class="ywz-h2">本站已完成安装</h2>
                    <p class="ywz-sub" style="margin:4px 0 0">检测到 <code>includes/config.php</code> 已标记安装且数据表可用，安装向导不会重复写入数据库与站点配置。</p>
                </div>
            </div>

            <dl class="ywz-kv">
                <div class="ywz-kv-row"><dt>后台入口</dt><dd><a href="<?= e($adminUrl) ?>"><?= e($adminUrl) ?></a></dd></div>
                <div class="ywz-kv-row"><dt>客户端入口</dt><dd><a href="<?= e(url('user/login.php')) ?>"><?= e(url('user/login.php')) ?></a></dd></div>
                <div class="ywz-kv-row"><dt>门户首页</dt><dd><a href="<?= e(url('/')) ?>"><?= e(url('/')) ?></a></dd></div>
                <div class="ywz-kv-row"><dt>本站身份</dt><dd>独立站点 · 自有域名独立运营，与官网 <?= e(install_official_host()) ?> 互不隶属</dd></div>
            </dl>

            <div class="ywz-next">
                <b>运维提示</b>
                <ol>
                    <li>正式环境请在 Nginx 中禁止访问 <code>install/</code> 目录：<code>location ^~ /install/ { return 404; }</code>；</li>
                    <li>升级后补齐新增表 / 字段：在站点根目录执行 <code>php tools/upgrade.php</code>；</li>
                    <li>确需重新运行安装向导（不会清空数据，但会覆盖站点名称、域名、后台路径等设置），请在地址后追加 <code>?force=1</code>。</li>
                </ol>
            </div>

            <div class="ywz-actions">
                <a class="ywz-btn primary" href="<?= e($adminUrl) ?>">进入后台管理</a>
                <a class="ywz-btn ghost" href="<?= e(url('/')) ?>">访问门户首页</a>
            </div>
        </div>
    </main>

    <footer class="ywz-foot"><p>优享财务系统 · 安装向导</p></footer>
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
<meta name="robots" content="noindex,nofollow">
<title><?= e(wizard_steps()[$step]) ?> · <?= e($state['site_name']) ?> 安装向导</title>
<?= wizard_css() ?>
</head>
<body class="ywz-body">
<div class="ywz-shell">
    <header class="ywz-top">
        <div class="ywz-brand">
            <span class="ywz-logo">优</span>
            <span class="ywz-brand-t">
                <strong>优享财务系统安装向导</strong>
                <small>业务与财务一体化管理平台 · 独立站点部署</small>
            </span>
        </div>
        <div class="ywz-meta">
            <span class="ywz-ver">v1.6.4</span>
            <span class="ywz-meta-t">授权支持 <?= e(install_official_host()) ?></span>
        </div>
    </header>

    <main class="ywz-card">
        <?php if (wizard_phase($step) > 0): ?>
        <div class="ywz-stepbar"><?= wizard_phase_bar($step) ?></div>
        <?php endif; ?>
        <div class="ywz-main">
            <?php if ($flash): ?>
                <div class="ywz-alert <?= $flash['type'] === 'success' ? 'ok' : 'err' ?>"><?= $flash['type'] === 'success' ? '✓' : '!' ?> <?= e($flash['msg']) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="ywz-alert err">! <?= e($err) ?></div>
            <?php endforeach; ?>

<?php if ($step === 1): ?>
    <form method="post" action="?step=1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="step1">
        <h2 class="ywz-h2">软件使用协议与免责声明</h2>
        <p class="ywz-sub">请阅读以下条款并勾选同意。点击「同意协议并继续」后，向导将依次完成环境检测、创建数据、安装完成三步。</p>
        <div class="ywz-agreement">
            <h3>一、协议范围</h3>
            <ol>
                <li>本协议是您（自然人或法人，下称「使用者」）与优享财务系统著作权人之间关于本软件使用的约定，自勾选同意并完成安装起生效。</li>
                <li>本系统为业务与财务一体化管理平台，提供产品管理、订单计费、业务开通、账务流水、工单与客户管理等功能。</li>
                <li>软件按「现状」授权使用；授权以域名（或部署实例）为单位签发，授权码与域名绑定，可在后台「授权中心」核验。</li>
            </ol>

            <h3>二、独立站点与授权</h3>
            <ol>
                <li>本软件为<strong>独立部署、独立运营</strong>的系统：购买授权后，您在自己购买的服务器、自己备案的域名上搭建本站，本站的数据库、数据、后台与经营行为全部由您独立掌控。</li>
                <li>官网（<code><?= e(install_official_host()) ?></code>）是软件发行方与授权中心的站点，与本站为<strong>两个互不隶属的站点</strong>：官网不存储本站业务数据、不代管本站客户、不参与本站经营，本站也不得对外宣称为官网或官方授权中心。</li>
                <li>本站域名不得使用官网域名及其子域名。一个授权对应一个独立站点；本站如需多域名或多站点部署，须按站点数量另行获取授权。</li>
            </ol>

            <h3>三、使用限制</h3>
            <ol>
                <li>不得删除、隐藏或修改软件中的版权标识与授权校验机制，不得将未授权实例对外宣称为正版授权。</li>
                <li>不得对软件进行反向工程、反编译、破解、二次分发，或用于搭建面向第三方的盗版分发服务。</li>
                <li>不得利用本站从事违反所在地法律法规的业务，包括但不限于未取得资质经营、洗钱、赌诈、侵权等行为。</li>
            </ol>

            <h3>四、数据与运维</h3>
            <ol>
                <li>使用者应自行建立数据库与上传目录的备份机制，并妥善保管服务器、数据库与后台账号密码。</li>
                <li>系统升级、迁移、二次开发引起的结构与数据变更，由使用者自行评估并承担风险。</li>
                <li>安装完成后建议禁止外部访问 <code>install/</code> 目录、关闭调试模式，并配置 HTTPS。</li>
            </ol>

            <h3>五、免责声明</h3>
            <ol>
                <li>本软件不构成对任何经营资质、备案、实名制、税务合规的替代或担保，使用者应自行确保经营行为符合所在地法律法规。</li>
                <li>因上游供应商、支付通道、实名核验、短信邮件等第三方服务中断、政策调整或接口变更造成的损失，由使用者与对应服务方协商解决。</li>
                <li>因使用者未备份、弱口令、服务器被入侵、误操作、违规使用等自身原因造成的数据丢失或损失，软件著作权人不承担责任。</li>
                <li>在法律允许的最大范围内，软件著作权人对因使用本软件产生的间接损失、利润损失不承担赔偿责任。</li>
            </ol>

            <h3>六、其他</h3>
            <ol>
                <li>本协议未尽事宜，以软件著作权人发布的正式授权协议与官方文档为准。</li>
                <li>继续安装即表示您已阅读、理解并同意上述全部条款。</li>
            </ol>
        </div>
        <label class="ywz-chk"><input type="checkbox" name="agree" value="1" <?= !empty($state['agreed']) ? 'checked' : '' ?>> <span>我已阅读并同意《软件使用协议》与《免责声明》</span></label>
        <div class="ywz-actions">
            <button type="submit" class="ywz-btn primary">同意协议并继续</button>
            <span class="ywz-tip">下一步将检测服务器环境、PHP 模块与目录权限。</span>
        </div>
    </form>

<?php elseif ($step === 2): ?>
    <h2 class="ywz-h2">环境与权限检测</h2>
    <p class="ywz-sub">向导已自动检测当前服务器环境、PHP 模块、目录权限与部署形态。带「必检」标记的项目未通过时无法进入下一步；标记为「建议」的项目不影响安装，可按需处理。</p>
    <?php foreach ($checks as $group):
        $gok = $gwarn = $gfail = 0;
        foreach ($group['items'] as $it) {
            if ($it['status'] === 'ok') {
                $gok++;
            } elseif ($it['status'] === 'warn') {
                $gwarn++;
            } else {
                $gfail++;
            }
        }
    ?>
    <section class="ywz-checkgroup">
        <h3>
            <span><?= e($group['title']) ?></span>
            <span class="ywz-gsum"><?= (int)$gok ?> 项通过<?= $gwarn ? ' · ' . (int)$gwarn . ' 项建议' : '' ?><?= $gfail ? ' · ' . (int)$gfail . ' 项未通过' : '' ?></span>
        </h3>
        <?php foreach ($group['items'] as $item): ?>
        <div class="ywz-crow<?= $item['status'] === 'fail' ? ' is-fail' : '' ?>">
            <div class="ywz-cname">
                <b><?= e($item['name']) ?></b><?= !empty($item['required']) ? '<span class="ywz-badge">必检</span>' : '<span class="ywz-badge">建议</span>' ?>
                <?php if (!empty($item['hint'])): ?><span class="ywz-chint"><?= e($item['hint']) ?></span><?php endif; ?>
            </div>
            <div class="ywz-cval"><?= e($item['val']) ?></div>
            <div class="ywz-cst"><span class="ywz-tag tag-<?= e($item['status']) ?>"><?= $item['status'] === 'ok' ? '通过' : ($item['status'] === 'warn' ? '建议' : '未通过') ?></span></div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
    <div class="ywz-sum">
        <span><i class="ok"></i>通过 <?= (int)$stats['ok'] ?> 项</span>
        <span><i class="warn"></i>建议处理 <?= (int)$stats['warn'] ?> 项</span>
        <span><i class="fail"></i>未通过 <?= (int)$stats['fail'] ?> 项</span>
        <span style="margin-left:auto">PHP <?= e(PHP_VERSION) ?> · <?= e(PHP_SAPI) ?></span>
    </div>
    <form method="post" action="?step=2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="step2">
        <div class="ywz-actions">
            <a class="ywz-btn ghost" href="?step=1">上一步</a>
            <a class="ywz-btn ghost" href="?step=2&amp;recheck=1">重新检测</a>
            <button type="submit" class="ywz-btn primary" <?= $stats['blocking'] ? 'disabled' : '' ?>>下一步：创建数据</button>
            <?php if ($stats['blocking']): ?>
                <span class="ywz-tip err">请先处理：<?= e(implode('、', $stats['blocking'])) ?></span>
            <?php else: ?>
                <span class="ywz-tip">检测通过后进入数据库配置。</span>
            <?php endif; ?>
        </div>
    </form>

<?php elseif ($step === 3): ?>
    <?php $dbDriver = ($state['db']['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite'; ?>
    <form method="post" action="?step=3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="step3">
        <h2 class="ywz-h2">数据库配置</h2>
        <p class="ywz-sub">本站的产品、订单、账务与客户数据全部保存在您自己的数据库中，与官网数据库相互独立。本步只做连接测试，点击下一步后暂存配置，安装时才写入 <code>includes/config.php</code>。</p>

        <div class="ywz-dbs">
            <label class="ywz-dbcard">
                <input type="radio" name="driver" value="mysql" <?= $dbDriver === 'mysql' ? 'checked' : '' ?>>
                <span class="ywz-dbcard-in"><b>MySQL</b><em>推荐生产环境使用 · 需 pdo_mysql 扩展</em></span>
            </label>
            <label class="ywz-dbcard">
                <input type="radio" name="driver" value="sqlite" <?= $dbDriver === 'sqlite' ? 'checked' : '' ?>>
                <span class="ywz-dbcard-in"><b>SQLite</b><em>免安装免配置 · 适合单机或轻量站点</em></span>
            </label>
        </div>

        <div class="ywz-panel" data-dbpanel="mysql"<?= $dbDriver === 'mysql' ? '' : ' hidden' ?>>
            <div class="ywz-grid">
                <label class="ywz-field"><span>主机地址</span><input type="text" name="db_host" value="<?= e($state['db']['host'] ?? '127.0.0.1') ?>"></label>
                <label class="ywz-field"><span>端口</span><input type="text" name="db_port" value="<?= e((string)($state['db']['port'] ?? '3306')) ?>"></label>
                <label class="ywz-field"><span>数据库名</span><input type="text" name="db_name" value="<?= e($state['db']['name'] ?? 'youxiang_finance') ?>"></label>
                <label class="ywz-field"><span>数据库账号</span><input type="text" name="db_user" value="<?= e($state['db']['user'] ?? '') ?>" autocomplete="off"></label>
                <label class="ywz-field"><span>数据库密码</span><input type="password" name="db_pass" value="<?= e($state['db']['pass'] ?? '') ?>" autocomplete="new-password"></label>
            </div>
            <label class="ywz-chk"><input type="checkbox" name="create_db" value="1" <?= !empty($state['create_db']) ? 'checked' : '' ?>> <span>数据库不存在时自动创建（需账号具备 CREATE 权限）</span></label>
        </div>

        <div class="ywz-panel" data-dbpanel="sqlite"<?= $dbDriver === 'sqlite' ? '' : ' hidden' ?>>
            <div class="ywz-grid">
                <label class="ywz-field"><span>SQLite 数据文件 <em>建议保留在 runtime/ 目录</em></span><input type="text" name="db_path" class="mono" value="<?= e($state['db']['path'] ?? (YX_ROOT . '/runtime/youxiang.db')) ?>"></label>
            </div>
            <p class="ywz-tip" style="margin:10px 0 0">相对路径会按站点根目录解析为绝对路径；安装完成后该文件即本站全部业务数据，请纳入备份计划。</p>
        </div>

        <div class="ywz-grid" style="margin-top:16px">
            <label class="ywz-field"><span>数据表前缀</span><input type="text" name="prefix" value="<?= e($state['db']['prefix'] ?? 'yx_') ?>"></label>
        </div>

        <div class="ywz-actions">
            <a class="ywz-btn ghost" href="?step=2">上一步</a>
            <button type="submit" class="ywz-btn primary">测试连接并继续</button>
            <span class="ywz-tip">下一步填写网站域名、创始人信息与授权码。</span>
        </div>
    </form>
    <script>
    (function () {
        var radios = document.querySelectorAll('input[name="driver"]');
        var panels = {
            mysql:  document.querySelector('[data-dbpanel="mysql"]'),
            sqlite: document.querySelector('[data-dbpanel="sqlite"]')
        };
        function sync(v) {
            Object.keys(panels).forEach(function (k) {
                if (panels[k]) { panels[k].hidden = (k !== v); }
            });
        }
        Array.prototype.forEach.call(radios, function (r) {
            r.addEventListener('change', function () { sync(r.value); });
        });
        var checked = document.querySelector('input[name="driver"]:checked');
        sync(checked ? checked.value : 'sqlite');
    })();
    </script>

<?php elseif ($step === 4): ?>
    <?php
    $licenseRequired = install_license_required();
    // 站点目录里已有签名授权文件时，本步可离线验签，授权码输入框不再是必填
    $licenseSigned   = $licenseRequired ? install_signed_license((string)$state['site_domain']) : null;
    $licenseReady    = $licenseSigned !== null && !empty($licenseSigned['ok']);
    $dbSummary       = ($state['db']['driver'] ?? 'sqlite') === 'mysql'
        ? 'MySQL · ' . $state['db']['host'] . ':' . (int)$state['db']['port'] . ' · ' . $state['db']['name']
        : 'SQLite · ' . $state['db']['path'];
    ?>
    <form method="post" action="?step=4" id="founderForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="step4">
        <h2 class="ywz-h2">网站配置与创始人信息</h2>
        <p class="ywz-sub">以下信息构成本站的独立身份：网站域名即授权绑定域名，本站数据、后台与授权均绑定该域名。</p>

        <div class="ywz-note">
            <span class="ywz-note-ico">独</span>
            <div>
                <b>独立站点部署</b>
                <p>购买授权后，您在本站自有域名上独立运营，数据库、数据与客户资料全部由您掌握。官网 <code><?= e(install_official_host()) ?></code> 与本站在不同域名、不同数据库上运行，是两个互不隶属的站点；本站域名不得填写官网域名或其子域名。</p>
            </div>
        </div>

        <h3 class="ywz-h3">网站配置</h3>
        <div class="ywz-grid">
            <label class="ywz-field"><span>系统名称</span><input type="text" name="site_name" value="<?= e($state['site_name']) ?>" placeholder="如：优享财务" required></label>
            <label class="ywz-field"><span>网站域名 <em>授权绑定，不带 http:// 与路径</em></span><input type="text" name="site_domain" id="siteDomain" value="<?= e($state['site_domain']) ?>" placeholder="www.example.com" required></label>
            <label class="ywz-field"><span>后台路径 <em>默认 admin，可自定义降低扫描风险</em></span><input type="text" name="admin_path" value="<?= e($state['admin_path']) ?>" placeholder="admin" pattern="[a-z][a-z0-9_-]{2,23}"></label>
            <label class="ywz-field"><span>初始版本</span>
                <select name="edition">
                    <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                        <option value="<?= e($k) ?>" <?= $state['edition'] === $k ? 'selected' : '' ?>><?= e($ed['name']) ?> · <?= e($ed['tag']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="ywz-chk"><input type="checkbox" name="debug" value="1" <?= !empty($state['debug']) ? 'checked' : '' ?>> <span>开启调试模式（生产环境请关闭）</span></label>
        <p class="ywz-tip" style="margin:8px 0 0">自定义后台路径会生成同名目录（内部指向 admin/ 的符号链接），直接访问 admin/ 返回 404；需要服务器允许创建符号链接。</p>

        <h3 class="ywz-h3">创始人信息</h3>
        <div class="ywz-grid">
            <label class="ywz-field"><span>管理员账号 <em>3-32 位字母、数字、下划线、点或连字符</em></span><input type="text" name="admin_user" value="<?= e($state['admin_user']) ?>" autocomplete="off" required></label>
            <label class="ywz-field"><span>管理员邮箱 <em>接收系统通知，前台展示为联系邮箱</em></span><input type="email" name="admin_email" value="<?= e($state['admin_email']) ?>" placeholder="admin@example.com" required></label>
            <label class="ywz-field"><span>管理员密码 <em>至少 8 位</em></span><input type="password" name="admin_pass" autocomplete="new-password" required></label>
            <label class="ywz-field"><span>重复密码</span><input type="password" name="admin_pass2" autocomplete="new-password" required></label>
        </div>

        <h3 class="ywz-h3">授权码</h3>
        <?php if ($licenseRequired && !$licenseReady && trim((string)$state['license_key']) === ''): ?>
        <div class="ywz-alert warn" style="display:block">
            <strong>未填写授权码，本程序包不可完成安装。</strong>正版程序包需单独购买（<?= e(install_pkg_price_text()) ?>，开源版免费）；安装还需购买「<?= e(install_auth_label()) ?>」（<?= e(install_price_text()) ?>，按域名签发）。支付后到「我的订单 → 我的授权」（<?= e(install_auth_page()) ?>）获取与本站域名对应的授权码。
        </div>
        <?php endif; ?>
        <div class="ywz-grid">
            <label class="ywz-field"><span>授权码 <em><?= $licenseRequired ? ($licenseReady ? '可选 · 已有签名授权文件' : '必填') : '选填' ?>，格式 XXXX-XXXX-XXXX-XXXX</em></span>
                <input type="text" name="license_key" id="licenseKey" class="mono" value="<?= e($state['license_key']) ?>" placeholder="8F41-CB20-2D8B-9A76" autocomplete="off"<?= ($licenseRequired && !$licenseReady) ? ' required' : '' ?>>
            </label>
        </div>
        <p class="ywz-tip" style="margin:8px 0 0">没有授权码时，也可把授权中心交付的 <code>license.json</code> 放到站点根目录（或根目录下的 <code>授权文件/</code>），或直接粘贴其内容到上面输入框：包内只有公钥，本机离线验签即可，不需要联网。</p>
        <div class="ywz-actions" style="border-top:0;padding-top:12px;margin-top:12px">
            <button type="button" class="ywz-btn ghost sm" id="btnCheckLicense">检测序列码</button>
            <span class="ywz-tip">绑定域名：<b id="boundDomain"><?= e($state['site_domain'] !== '' ? $state['site_domain'] : '请先填写网站域名') ?></b></span>
        </div>
        <div id="licenseResult" class="ywz-alert warn" style="display:none"></div>

        <?php if ($licenseReady): ?>
        <p class="ywz-tip" style="color:#0f9d58">已找到签名授权文件（<?= e((string)($licenseSigned['file'] ?? 'license.json')) ?>）：<?= e((string)$licenseSigned['data']['domain']) ?> · <?= e(install_signed_license_expire($licenseSigned['data'])) ?>，本机离线验签通过，可直接开始安装。</p>
        <?php elseif (!$licenseRequired): ?>
        <p class="ywz-tip">本安装包不校验授权码，安装后可正常使用全部基础功能。</p>
        <?php endif; ?>

        <div class="ywz-actions">
            <a class="ywz-btn ghost" href="?step=3">上一步</a>
            <button type="submit" class="ywz-btn primary">开始安装</button>
            <span class="ywz-tip">数据库：<?= e($dbSummary) ?></span>
        </div>
    </form>

    <div class="ywz-loading" id="installLoading">
        <div class="ywz-loading-box">
            <div class="ywz-spin"></div>
            <p style="margin:0;font-size:13px;color:#334155">正在创建数据表并写入基础数据，请勿关闭页面…</p>
        </div>
    </div>

    <script>
    (function () {
        var btn    = document.getElementById('btnCheckLicense');
        var box    = document.getElementById('licenseResult');
        var keyEl  = document.getElementById('licenseKey');
        var domEl  = document.getElementById('siteDomain');
        var bound  = document.getElementById('boundDomain');
        var form   = document.getElementById('founderForm');
        var mask   = document.getElementById('installLoading');

        function syncBound() {
            if (bound) { bound.textContent = domEl.value.trim() || '请先填写网站域名'; }
        }
        domEl.addEventListener('input', syncBound);
        syncBound();

        btn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('license_key', keyEl.value.trim());
            fd.append('site_domain', domEl.value.trim());
            var hidden = document.querySelector('input[name=_csrf]');
            if (hidden) { fd.append('_csrf', hidden.value); }
            btn.disabled = true;
            box.style.display = '';
            box.className = 'ywz-alert warn';
            box.textContent = '正在校验…';
            fetch('?step=4&ajax=license', {method: 'POST', body: fd, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var cls = {ok: 'ok', warn: 'warn', err: 'err', none: 'warn'}[d.level] || 'warn';
                    box.className = 'ywz-alert ' + cls;
                    box.textContent = d.msg || '校验完成';
                })
                .catch(function () {
                    box.className = 'ywz-alert err';
                    box.textContent = '校验请求失败，请检查网络后重试';
                })
                .finally(function () { btn.disabled = false; });
        });

        form.addEventListener('submit', function () {
            mask.classList.add('on');
            var submitBtn = form.querySelector('button[type=submit]');
            if (submitBtn) { submitBtn.disabled = true; }
        });
    })();
    </script>

<?php else: ?>
    <?php
    $result     = $state['result'] ?? [];
    $logSteps   = is_array($result['steps'] ?? null) ? $result['steps'] : [];
    $adminPath  = (string)($result['admin_path'] ?? 'admin');
    $adminUrl   = url('admin/login.php');
    $siteDomain = (string)($state['site_domain'] ?? '');
    ?>
    <div id="ywzRunning">
        <h2 class="ywz-h2">正在执行安装</h2>
        <p class="ywz-sub">正在创建数据表、写入基础数据并登记授权信息，请不要关闭浏览器。</p>
        <div class="ywz-bar"><i id="ywzFill"></i></div>
        <div class="ywz-barmeta"><span id="ywzBarText">准备中…</span><span id="ywzBarPct">0%</span></div>
        <ul class="ywz-log" id="ywzLog">
            <?php foreach ($logSteps as $line): ?>
            <li><span class="k">✓</span><?= e((string)($line['text'] ?? '')) ?></li>
            <?php endforeach; ?>
            <?php if (!$logSteps): ?><li class="show"><span class="k">✓</span>安装已完成</li><?php endif; ?>
        </ul>
    </div>

    <div id="ywzDone" style="display:none">
        <div class="ywz-done-head">
            <span class="ywz-done-ico">✓</span>
            <div>
                <h2 class="ywz-h2">恭喜，安装完成</h2>
                <p class="ywz-sub" style="margin:4px 0 0">本站已作为独立站点安装完成。后台账号、站点配置与业务数据均保存在您自己的服务器与数据库中。</p>
            </div>
        </div>

        <dl class="ywz-kv">
            <div class="ywz-kv-row"><dt>系统名称</dt><dd><?= e((string)($state['site_name'] ?? '优享财务系统')) ?></dd></div>
            <div class="ywz-kv-row"><dt>独立站点域名</dt><dd><?= e($siteDomain !== '' ? $siteDomain : '—') ?></dd></div>
            <div class="ywz-kv-row"><dt>数据库</dt><dd><?= e(($result['driver'] ?? 'sqlite') === 'mysql' ? 'MySQL · 本站独立数据库' : 'SQLite · 本站独立数据文件') ?></dd></div>
            <div class="ywz-kv-row"><dt>后台入口</dt><dd><a href="<?= e($adminUrl) ?>"><?= e($adminUrl) ?></a><?php if ($adminPath !== 'admin'): ?> <span class="ywz-tip">自定义路径 <code><?= e($adminPath) ?></code>，直接访问 admin/ 返回 404</span><?php endif; ?></dd></div>
            <div class="ywz-kv-row"><dt>客户端入口</dt><dd><a href="<?= e(url('user/login.php')) ?>"><?= e(url('user/login.php')) ?></a></dd></div>
            <div class="ywz-kv-row"><dt>创始人账号</dt><dd><?= !empty($result['admin_new']) ? '已按本次填写创建' : '管理员已存在，本次未覆盖' ?></dd></div>
            <div class="ywz-kv-row"><dt>授权状态</dt><dd><?= e((string)($result['license']['msg'] ?? '未登记授权码')) ?></dd></div>
        </dl>

        <div class="ywz-note" style="margin:18px 0 0">
            <span class="ywz-note-ico">独</span>
            <div>
                <b>本站与官网是两个独立站点</b>
                <p>本站运行在 <code><?= e($siteDomain !== '' ? $siteDomain : '您的域名') ?></code>，数据保存在本站数据库；官网 <code><?= e(install_official_host()) ?></code> 仅提供程序包与授权签发，不存储、不代管本站数据。本站的备案、经营资质、内容与数据安全由您自行负责。</p>
            </div>
        </div>

        <div class="ywz-next">
            <b>安装后必做的三件事</b>
            <ol>
                <li>禁止外部访问 <code>install/</code> 目录（Nginx：<code>location ^~ /install/ { return 404; }</code>），避免被重复安装；</li>
                <li>登录后台完成基础配置：支付通道、短信 / 邮件、产品与价格、工单与客服；</li>
                <li>为域名配置 HTTPS，并建立数据库与上传目录的定时备份。</li>
            </ol>
        </div>

        <div class="ywz-actions">
            <a class="ywz-btn primary" href="<?= e($adminUrl) ?>">进入后台管理</a>
            <a class="ywz-btn ghost" href="<?= e(url('/')) ?>">访问门户首页</a>
            <a class="ywz-btn ghost" href="<?= e(url('user/login.php')) ?>">客户端登录</a>
        </div>
    </div>

    <script>
    (function () {
        var items   = document.querySelectorAll('#ywzLog li');
        var fill    = document.getElementById('ywzFill');
        var text    = document.getElementById('ywzBarText');
        var pct     = document.getElementById('ywzBarPct');
        var running = document.getElementById('ywzRunning');
        var done    = document.getElementById('ywzDone');
        var total   = items.length;

        function showDone() {
            if (running) { running.style.display = 'none'; }
            if (done) { done.style.display = ''; }
        }
        if (!total) { showDone(); return; }
        var i = 0;
        // 日志行数不固定（结构变更多则行数多），按总时长控制节奏：约 2.4 秒走完全部
        var tick = Math.max(35, Math.min(110, Math.round(2400 / total)));
        var timer = setInterval(function () {
            var li = items[i];
            if (li) { li.classList.add('show'); }
            i++;
            var p = Math.round(i / total * 100);
            if (fill) { fill.style.width = p + '%'; }
            if (pct) { pct.textContent = p + '%'; }
            if (text && li) { text.textContent = li.textContent.replace(/^\s*✓\s*/, ''); }
            if (i >= total) {
                clearInterval(timer);
                if (text) { text.textContent = '安装完成'; }
                setTimeout(showDone, 420);
            }
        }, tick);
    })();
    </script>
<?php endif; ?>
        </div>
    </main>

    <footer class="ywz-foot">
        <p>优享财务系统 · 独立站点部署安装向导</p>
        <p>官网 <?= e(install_official_host()) ?> 与本站在不同域名、不同数据库上独立运行</p>
    </footer>
</div>
</body>
</html>
