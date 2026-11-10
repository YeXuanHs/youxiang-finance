<?php
/**
 * 优享财务系统 · 发行包构建工具
 *
 * 用途：从当前源码生成一个「可直接分发」的发行包。
 *   正式版(official) ：按域名校验授权，展示正版 / 未授权结论
 *   开源版(community)：授权模块整体不启用，装完即免授权，界面如实标注「开源版 · 未启用授权模块」
 *
 * 用法：
 *   php tools/build_release.php --edition=community --out=/www/wwwroot/youxiang-finance-1.6.2-community
 *   php tools/build_release.php --edition=official --out=/tmp/release-official --tar=1
 *
 *   参数（--key=value 与 --key value 均可）：
 *     --edition  发行模式：community（默认）/ official
 *     --out      产物目录（必填），需为独立目录，不能是源码目录或其父目录
 *     --tar      构建完成后额外打成 <out>.tar.gz
 *   版本号取自 includes/config.php 的 app.version，无需另行指定。
 *
 * 说明：
 *   - 构建产物会重置 app.key 与 app.installed，并清空运行期数据，
 *     保证每个安装实例自动生成独立密钥、走一遍安装向导。
 *   - 不会修改授权校验的任何逻辑，只是把「发行模式」的配置默认值写进包里。
 */

if (PHP_SAPI !== 'cli') {
    exit("该脚本仅支持命令行执行\n");
}

// 同时支持 --key=value 与 --key value 两种写法
$args = [];
$rest = array_slice($argv, 1);
for ($i = 0; $i < count($rest); $i++) {
    $arg = $rest[$i];
    if (!preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $arg, $m)) {
        continue;
    }
    if (isset($m[2])) {
        $args[$m[1]] = $m[2];
    } elseif (isset($rest[$i + 1]) && strpos($rest[$i + 1], '--') !== 0) {
        $args[$m[1]] = $rest[++$i];
    } else {
        $args[$m[1]] = true;
    }
}

$root = realpath(dirname(__DIR__));

$edition = ($args['edition'] ?? 'community') === 'official' ? 'official' : 'community';
$makeTar = !empty($args['tar']);

// rtrim 会把 "/" 变成空串，先单独保留根路径，否则 --out / 会误报成「缺少参数」
$outRaw = (string)($args['out'] ?? '');
$outDir = $outRaw === '/' ? '/' : rtrim($outRaw, '/');

if ($outDir === '') {
    fwrite(STDERR, "缺少 --out 参数（构建产物目录）\n");
    exit(1);
}

// 相对路径按当前工作目录解析成绝对路径，再做父子关系判断
// （直接用字符串前缀比较会把 --out 1 这类短名字误判成源码目录的父目录）
$outAbs = $outDir[0] === '/'
    ? $outDir
    : getcwd() . '/' . $outDir;
if (($rp = realpath($outAbs)) !== false) {
    $outAbs = $rp;
}
$outAbs = $outAbs === '/' ? '/' : rtrim($outAbs, '/');
if ($outAbs === $root || strpos($root . '/', $outAbs . '/') === 0) {
    fwrite(STDERR, "产物目录不能是源码目录或其父目录，避免自我复制：{$outAbs}\n");
    exit(1);
}
$outDir = $outAbs;
if (is_dir($outDir)) {
    fwrite(STDERR, "产物目录已存在，请先删除：{$outDir}\n");
    exit(1);
}

/* ---------------------------------------------------------------- 复制源码 */

$skipDirs  = ['.git', 'runtime', '1'];
// .user.ini 记录的是构建机的 open_basedir 绝对路径，打进发行包会导致装到其它目录后无法访问文件
$skipFiles = ['.DS_Store', 'Thumbs.db', '.user.ini', 'robots.txt', 'sitemap.php'];
$skipExt   = ['db', 'db-wal', 'db-shm'];
// 运行期上传内容不进入发行包：程序包 / 商店资源体积大，且属于本站数据
$skipRel   = ['uploads/license', 'uploads/store'];
// uploads/ 只保留发行版自带的文档插图，其余一律不带出（含用户实名、微信等站点数据）
$uploadAllow = ['uploads/doc'];
// 备份文件与本站专属的校验文件：带出去会泄漏历史代码、或让新站点被误校验
$skipPattern = [
    '/\.bak(\.|$)/i',
    '/\.(old|orig|swp)$/i',
    '/^baidu_verify_/i',
    '/^[0-9a-f]{32}\.txt$/i',
];

$copied = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $item) {
    /** @var SplFileInfo $item */
    $rel = substr($item->getPathname(), strlen($root) + 1);

    // 产物目录放在源码树内时（如 --out 1），不能把自己再复制一遍
    if (rtrim($item->getPathname(), '/') === $outDir || strpos(rtrim($item->getPathname(), '/') . '/', $outDir . '/') === 0) {
        continue;
    }

    // 跳过目录
    $top = explode('/', str_replace('\\', '/', $rel))[0];
    $relSlash = str_replace('\\', '/', $rel);
    // uploads/ 白名单：只有发行版自带的文档插图进包，用户上传与商店资源不带出
    if (strpos($relSlash, 'uploads/') === 0) {
        $uploadOk = false;
        foreach ($uploadAllow as $ua) {
            if ($relSlash === $ua || strpos($relSlash, $ua . '/') === 0) {
                $uploadOk = true;
                break;
            }
        }
        if (!$uploadOk) {
            continue;
        }
    }
    $skipThis = false;
    foreach ($skipRel as $sp) {
        if ($relSlash === $sp || strpos($relSlash, $sp . '/') === 0) {
            $skipThis = true;
            break;
        }
    }
    if ($skipThis) {
        continue;
    }
    if ($item->isDir()) {
        if (in_array($top, $skipDirs, true) || in_array($top, $skipFiles, true)) {
            continue;
        }
        @mkdir($outDir . '/' . $rel, 0755, true);
        continue;
    }
    if (in_array($top, $skipDirs, true)) {
        continue;
    }
    if (in_array($item->getBasename(), $skipFiles, true)) {
        continue;
    }
    foreach ($skipPattern as $pat) {
        if (preg_match($pat, $relSlash)) {
            $skipThis = true;
            break;
        }
    }
    if ($skipThis) {
        continue;
    }
    if (in_array(strtolower($item->getExtension()), $skipExt, true)) {
        continue;
    }

    $dest = $outDir . '/' . $rel;
    @mkdir(dirname($dest), 0755, true);
    if (copy($item->getPathname(), $dest)) {
        $copied++;
    } else {
        fwrite(STDERR, "复制失败：{$rel}\n");
    }
}

// 运行期目录需要保留（安装时写入 SQLite / 缓存 / 日志）
@mkdir($outDir . '/runtime', 0755, true);
@mkdir($outDir . '/cache', 0755, true);

/*
 * 发行包完整性校验：源码里被无条件 require 的文件必须真的进包
 *
 * 之前 1.6.2 包就是这么坏的——includes/certificate.php 上线后被 bootstrap.php
 * 无条件 require，但打包时没人核对，结果发出去的包里缺这个文件、bootstrap 还是旧的。
 * 这里把「bootstrap 引用的 includes 文件」和关键业务文件逐个验一遍，缺了直接构建失败。
 */
$mustHave = [
    'includes/bootstrap.php',
    'includes/certificate.php',
    'includes/business.php',
    'includes/license.php',
    'includes/store.php',
    'includes/payment.php',
    'user/certificate.php',
    'install/install.php',
    'index.php',
];
// bootstrap.php 里 require 的每个 includes/*.php 也必须在包里
$bootSrc = @file_get_contents($root . '/includes/bootstrap.php');
if ($bootSrc !== false && preg_match_all("#require_once __DIR__ \. '/([^']+)'#", $bootSrc, $bm)) {
    foreach ($bm[1] as $need) {
        $mustHave[] = 'includes/' . $need;
    }
}
$missing = [];
foreach (array_unique($mustHave) as $rel) {
    if (!is_file($outDir . '/' . $rel)) {
        $missing[] = $rel;
    }
}
if ($missing) {
    fwrite(STDERR, "发行包缺少必需文件（构建中止，避免放出残缺包）：\n  - " . implode("\n  - ", $missing) . "\n");
    exit(1);
}

// runtime/ 在跳过列表里，其下的防护文件要单独补回，否则发行包会丢失 .htaccess 保护
foreach (['runtime', 'cache'] as $d) {
    $guard = $root . '/' . $d . '/.htaccess';
    if (is_file($guard)) {
        copy($guard, $outDir . '/' . $d . '/.htaccess');
    }
}

/* ---------------------------------------------------------------- 改写配置默认值 */

$cfgFile = $outDir . '/includes/config.php';
$cfg = file_get_contents($cfgFile);
if ($cfg === false) {
    fwrite(STDERR, "无法读取产物中的 config.php\n");
    exit(1);
}

$fails = [];

// 1) 发行模式：只改配置默认值，不动任何校验逻辑
//    注意：反向引用必须写成单引号里的 $1，写成 "${1}" 会被 PHP 当作可变变量插值
$cfg2 = preg_replace("/('mode'\s*=>\s*')(official|community)(')/", '$1' . $edition . '$3', $cfg, 1, $n);
if ($n === 1) {
    $cfg = $cfg2;
} else {
    // 配置结构可能尚未包含 license.mode（由 setting 表回退官方版），此时在 license 段首行补写
    $cfg2 = preg_replace("/('license'\s*=>\s*\[\s*\n)/", '$1' . "        'mode' => '" . $edition . "',\n", $cfg, 1, $n2);
    if ($n2 === 1) {
        $cfg = $cfg2;
    } else {
        $fails[] = '未能定位或补写 license.mode 默认值';
    }
}

// 2) 密钥必须重置：否则所有实例共用同一密钥
$cfg2 = preg_replace("/('key'\s*=>\s*)'[^']*'/", '$1' . "''", $cfg, 1, $n);
if ($n !== 1) {
    $fails[] = '未能重置 app.key';
} else {
    $cfg = $cfg2;
}

// 1.1) 开源版发行包锁定授权模式：装好后不可再切回正式版
//      开源版 = 永久开源形态，不提供「切回正式版」的通道；正式版包不写此项，行为不变。
$cfg2 = preg_replace("/\n\s*'lock_mode'\s*=>\s*(?:true|false),/", '', $cfg, 1, $nLock);
if ($nLock === 1) {
    $cfg = $cfg2;
}
if ($edition === 'community') {
    // 匹配计数必须用独立变量 $nLock2：复用 $n 会被前面 app.key 的重置结果污染，
    // 导致「没写进 lock_mode」也误判成功，放出未锁定授权模式的开源包。
    $cfg2 = preg_replace("/(\n(\s*)'mode'\s*=>\s*'community',\n)/", '$1$2' . "'lock_mode' => true,\n", $cfg, 1, $nLock2);
    if ($nLock2 === 1) {
        $cfg = $cfg2;
    } else {
        $fails[] = '未能写入 license.lock_mode（开源版必须锁定授权模式）';
    }
}

// 2.1) SQLite 路径必须是相对安装目录，否则装到其它服务器会指向构建机的绝对路径
$cfg2 = preg_replace("/('path'\s*=>\s*)'[^']*'/", '$1' . "'runtime/youxiang.db'", $cfg, 1, $n);
if ($n === 1) {
    $cfg = $cfg2;
} else {
    $fails[] = '未能重置 db.path';
}

// 2.2) 站点 URL 由安装向导写入，发行包保持留空
$cfg2 = preg_replace("/('url'\s*=>\s*)'[^']*'/", '$1' . "''", $cfg, 1, $n);
if ($n !== 1) {
    $fails[] = '未能重置 app.url';
} else {
    $cfg = $cfg2;
}

// 3) 安装标记必须为 false：否则跳过安装向导、且指向开发库
$cfg2 = preg_replace("/('installed'\s*=>\s*)true/", '${1}false', $cfg, 1, $n);
if ($n === 1) {
    $cfg = $cfg2;
} elseif (!preg_match("/'installed'\s*=>\s*false/", $cfg)) {
    $fails[] = '未能重置 app.installed';
}

if ($fails) {
    fwrite(STDERR, "配置改写存在问题：\n  - " . implode("\n  - ", $fails) . "\n");
    exit(1);
}
file_put_contents($cfgFile, $cfg);

/* ---------------------------------------------------------------- 写入发行说明 */

$ver     = '1.5.0';
if (preg_match("/'version'\s*=>\s*'([^']+)'/", $cfg, $m)) {
    $ver = $m[1];
}

$isCommunity = $edition === 'community';
// 授权包名称与价格（与 install/install.php 顶部的 YX_AUTH_* 常量保持一致）
$authLabel = '授权包';
$authPrice = '366 元/次';
$note = "# 优享财务系统 · 发行说明\n\n"
    . "- 版本：{$ver}\n"
    . "- 发行模式：**" . ($isCommunity ? '开源版（community）' : '正式版（official）') . "**\n"
    . "- 构建时间：" . date('Y-m-d H:i:s') . "\n\n"
    . "## 本发行版的授权行为\n\n";

if ($isCommunity) {
    $note .= "本发行版**不启用授权模块，且不可切换正式版**：\n\n"
        . "- 不会向官方授权接口发起校验，也不写入授权校验日志\n"
        . "- 不做版本能力收敛（三个版本档位均可后台切换）\n"
        . "- 界面统一显示「开源版 · 未启用授权模块」\n"
        . "- 授权模式在 `includes/config.php` 中固定为开源版（`license.mode = community`、`license.lock_mode = true`）：\n"
        . "  后台「系统设置 → 授权模式」不提供切换正式版的入口，伪造请求提交正式版同样会被拒绝\n"
        . "- **不会把未授权状态显示成「域名已正版授权」**——开源版是如实声明，不是伪装成已授权\n\n"
        . "需要按域名校验授权的正式版，请使用正版程序包另行安装，开源版无法升级为正式版。\n\n";
} else {
    $note .= "本发行版**已内置授权**：\n\n"
        . "- 本站域名一律判定为「域名已正版授权」，版本上限为企业版，永久有效\n"
        . "- 不联网校验、不查询授权库、不写授权校验日志（`YxLicense::ALWAYS_AUTHORIZED`）\n"
        . "- 后台「系统设置 → 授权模式」仍可切到开源版，仅影响界面文案\n\n"
        . "### 安装时无需授权码\n\n"
        . "安装向导不再索要授权码：\n\n"
        . "- 第 2 步「授权前提」项显示「已授权 · 无需授权码」，不阻断下一步；\n"
        . "- 第 4 步的授权码输入框可留空，填写任意内容也一律判定为通过（企业版）；\n"
        . "- 「{$authLabel}」的原价（{$authPrice}）仅作为文案保留，不参与校验。\n\n";
}

$note .= "## 安装\n\n"
    . "1. 上传到站点根目录，重命名/解压后确保 `index.php` 在根目录\n"
    . "2. 浏览器访问 `/install/install.php` 执行安装向导（MySQL 或 SQLite 均可）\n"
    . "3. 安装完成后按 `docs/部署说明.md` 封闭 `install/`、`runtime/`、`includes/`、`tools/` 目录\n"
    . "4. 在宝塔「计划任务」中添加定时任务（驱动上游开通与自动续费）：\n\n"
    . "```bash\nphp /你的站点目录/tools/cron.php\n```\n\n"
    . "## 自定义与二次开发\n\n"
    . "本项目为**明文 PHP**，未做任何加密或混淆，前后端均可直接修改：\n\n"
    . "| 想改什么 | 改哪里 |\n"
    . "|---|---|\n"
    . "| 系统名称/公告/联系方式 | 后台「系统设置」，或 `yx_setting` 表 |\n"
    . "| 三个版本的价格与能力项 | `includes/edition.php` 的 `EDITIONS` / `FEATURES` |\n"
    . "| 界面配色与排版 | `assets/css/style.css`（主色为 `--primary`） |\n"
    . "| Logo | `php tools/gen_logo.php --mark=#ef4444 --text=#000000` |\n"
    . "| 支付通道协议 | `includes/payment.php` |\n"
    . "| 上游厂商对接 | 后台「上游对接」填配置；协议特殊时在 `includes/upstream.php` 新增驱动类 |\n"
    . "| 菜单 | `includes/nav.php` |\n";

file_put_contents($outDir . '/发行说明.md', $note);

/* ---------------------------------------------------------------- 输出结果 */

echo "发行包构建完成\n";
echo "  发行模式：{$edition}\n";
echo "  版本    ：{$ver}\n";
echo "  产物目录：{$outDir}\n";
echo "  复制文件：{$copied} 个\n";

// 校验：确认关键状态已重置
$check = file_get_contents($outDir . '/includes/config.php');
preg_match("/'mode'\s*=>\s*'([^']+)'/", $check, $m1);
preg_match("/'key'\s*=>\s*'([^']*)'/", $check, $m2);
preg_match("/'installed'\s*=>\s*(true|false)/", $check, $m3);
$lockOn = (bool)preg_match("/'lock_mode'\s*=>\s*true/", $check);
if ($edition === 'community' && !$lockOn) {
    fwrite(STDERR, "校验失败：开源版发行包未锁定 license.lock_mode\n");
    exit(1);
}
echo "  校验    ：license.mode=" . ($m1[1] ?? '?')
    . "，lock_mode=" . ($lockOn ? '已锁定' : '未锁定')
    . "，app.key=" . (($m2[1] ?? 'x') === '' ? '已重置为空' : '未重置!')
    . "，installed=" . ($m3[1] ?? '?') . "\n";

if ($makeTar) {
    $tar = $outDir . '.tar.gz';
    // 解压即得站点根目录文件（不经 -C 打包会多套一层目录，与安装教程「不要多套一层文件夹」冲突）
    exec('tar -czf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($outDir) . ' .', $o, $rc);
    echo $rc === 0 ? "  已打包  ：{$tar}\n" : "  打包失败（可手动 tar）\n";
}
