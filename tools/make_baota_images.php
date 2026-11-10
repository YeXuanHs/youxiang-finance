<?php
/**
 * 优享财务系统 · 宝塔面板部署插图生成器
 *
 * 按宝塔面板 9.x 的菜单、字段与交互绘制「宝塔部署每一步」的界面示意图，
 * 输出到 uploads/doc/ 供 docs/安装教程.md 引用。
 *
 * 注意：这不是浏览器截图，而是按宝塔面板菜单与字段绘制的示意图，
 *       因此图片底部统一标注「界面示意图」，示例数据已脱敏。
 *
 * 用法：
 *   /www/server/php/82/bin/php tools/make_baota_images.php
 *   /www/server/php/82/bin/php tools/make_baota_images.php --font=/path/to/CJK.otf
 */

if (PHP_SAPI !== 'cli') {
    exit("该脚本仅支持命令行执行\n");
}

$root = dirname(__DIR__);
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

if (!extension_loaded('gd') || !function_exists('imagettftext')) {
    fwrite(STDERR, "缺少 GD / FreeType 扩展，无法生成插图\n");
    exit(1);
}

$candidates = [];
if (!empty($args['font'])) {
    $candidates[] = (string)$args['font'];
}
$candidates = array_merge($candidates, [
    '/tmp/NotoSansCJKsc-Regular.otf',
    '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
    '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
    '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
]);
$FONT = '';
foreach ($candidates as $f) {
    if ($f !== '' && is_file($f) && is_readable($f)) {
        $FONT = $f;
        break;
    }
}
if ($FONT === '') {
    fwrite(STDERR, "未找到可用中文字体，请用 --font=/path/to/font.ttf 指定\n");
    exit(1);
}

$OUT = $root . '/uploads/doc';
if (!is_dir($OUT) && !@mkdir($OUT, 0755, true) && !is_dir($OUT)) {
    fwrite(STDERR, "无法创建输出目录：{$OUT}\n");
    exit(1);
}

/* ---------------------------------------------------------------- 配色 */

const C_TOPBAR  = [0x23, 0x2F, 0x3E];
const C_SIDEBAR = [0x2C, 0x3A, 0x4A];
const C_SIDE_HL = [0x1B, 0x26, 0x32];
const C_BG      = [0xF3, 0xF5, 0xF8];
const C_CARD    = [0xFF, 0xFF, 0xFF];
const C_LINE    = [0xE3, 0xE8, 0xEF];
const C_INK     = [0x1F, 0x29, 0x37];
const C_INK2    = [0x4B, 0x57, 0x67];
const C_MUTED   = [0x8A, 0x94, 0xA6];
const C_BLUE    = [0x1E, 0x6F, 0xE0];
const C_GREEN   = [0x20, 0xA5, 0x3A];
const C_OK      = [0x12, 0xB7, 0x6A];
const C_OKSOFT  = [0xEA, 0xF9, 0xF1];
const C_WARN    = [0xB4, 0x6B, 0x00];
const C_WARNSOFT= [0xFD, 0xF3, 0xDF];
const C_BAD     = [0xB0, 0x23, 0x23];
const C_FIELD   = [0xFA, 0xFC, 0xFE];
const C_TERM    = [0x0E, 0x16, 0x22];
const C_WHITE   = [0xFF, 0xFF, 0xFF];

/* ---------------------------------------------------------------- 绘图助手 */

function col($img, array $rgb)
{
    return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
}

function tw(string $text, float $size, string $font): float
{
    $box = imagettfbbox($size, 0, $font, $text);
    return (float)(max($box[2], $box[0]) - min($box[2], $box[0]));
}

function text($img, float $x, float $y, float $size, array $rgb, string $text, string $font): void
{
    imagettftext($img, $size, 0, (int)round($x), (int)round($y), col($img, $rgb), $font, $text);
}

function text_right($img, float $right, float $y, float $size, array $rgb, string $text, string $font): void
{
    imagettftext($img, $size, 0, (int)round($right - tw($text, $size, $font)), (int)round($y), col($img, $rgb), $font, $text);
}

function text_center($img, float $cx, float $y, float $size, array $rgb, string $text, string $font): void
{
    imagettftext($img, $size, 0, (int)round($cx - tw($text, $size, $font) / 2), (int)round($y), col($img, $rgb), $font, $text);
}

function rect($img, float $x, float $y, float $w, float $h, array $fill, ?array $border = null, int $r = 0): void
{
    $c = col($img, $fill);
    if ($r <= 0) {
        imagefilledrectangle($img, (int)$x, (int)$y, (int)($x + $w), (int)($y + $h), $c);
    } else {
        $d = $r * 2;
        imagefilledrectangle($img, (int)($x + $r), (int)$y, (int)($x + $w - $r), (int)($y + $h), $c);
        imagefilledrectangle($img, (int)$x, (int)($y + $r), (int)($x + $w), (int)($y + $h - $r), $c);
        imagefilledellipse($img, (int)($x + $r), (int)($y + $r), $d, $d, $c);
        imagefilledellipse($img, (int)($x + $w - $r), (int)($y + $r), $d, $d, $c);
        imagefilledellipse($img, (int)($x + $r), (int)($y + $h - $r), $d, $d, $c);
        imagefilledellipse($img, (int)($x + $w - $r), (int)($y + $h - $r), $d, $d, $c);
    }
    if ($border) {
        $b = col($img, $border);
        imageline($img, (int)($x + $r), (int)$y, (int)($x + $w - $r), (int)$y, $b);
        imageline($img, (int)($x + $r), (int)($y + $h), (int)($x + $w - $r), (int)($y + $h), $b);
        imageline($img, (int)$x, (int)($y + $r), (int)$x, (int)($y + $h - $r), $b);
        imageline($img, (int)($x + $w), (int)($y + $r), (int)($x + $w), (int)($y + $h - $r), $b);
    }
}

function line($img, float $x1, float $y1, float $x2, float $y2, array $rgb): void
{
    imageline($img, (int)$x1, (int)$y1, (int)$x2, (int)$y2, col($img, $rgb));
}

function card($img, float $x, float $y, float $w, float $h): void
{
    rect($img, $x, $y, $w, $h, C_CARD, C_LINE, 10);
}

function badge($img, string $font, float $x, float $y, string $label, array $fg, array $bg): float
{
    $w = tw($label, 11, $font) + 20;
    rect($img, $x, $y, $w, 20, $bg, null, 5);
    text($img, $x + 10, $y + 14.5, 11, $fg, $label, $font);
    return $w;
}

function btn($img, string $font, float $x, float $y, float $w, string $label, bool $filled = true, float $h = 34): void
{
    rect($img, $x, $y, $w, $h, $filled ? C_BLUE : C_CARD, $filled ? null : C_LINE, 6);
    text_center($img, $x + $w / 2, $y + $h * 0.68, 12.5, $filled ? C_WHITE : C_INK2, $label, $font);
}

function field($img, string $font, float $x, float $y, float $w, string $label, string $value, ?array $valueColor = null, float $labelSize = 12): void
{
    text($img, $x, $y, $labelSize, C_INK2, $label, $font);
    $boxY = $y + 10;
    rect($img, $x, $boxY, $w, 36, C_FIELD, C_LINE, 6);
    text($img, $x + 12, $boxY + 24, 12.5, $valueColor ?? C_INK, $value, $font);
}

function select($img, string $font, float $x, float $y, float $w, string $label, string $value): void
{
    text($img, $x, $y, 12, C_INK2, $label, $font);
    $boxY = $y + 10;
    rect($img, $x, $boxY, $w, 36, C_FIELD, C_LINE, 6);
    text($img, $x + 12, $boxY + 24, 12.5, C_INK, $value, $font);
    text_right($img, $x + $w - 12, $boxY + 23, 10, C_MUTED, '▼', $font);
}

function checkbox($img, string $font, float $x, float $y, string $label, bool $checked): void
{
    rect($img, $x, $y, 16, 16, $checked ? C_BLUE : C_FIELD, $checked ? null : C_LINE, 4);
    if ($checked) {
        text($img, $x + 4.5, $y + 12.5, 10, C_WHITE, '✓', $font);
    }
    text($img, $x + 24, $y + 13.5, 12, C_INK2, $label, $font);
}

/** 表头 + 分隔线；$cols = [[标题, 相对 x 偏移], ...] */
function table_head($img, string $font, float $x, float $y, float $w, array $cols): void
{
    foreach ($cols as $c) {
        text($img, $x + $c[1], $y, 11.5, C_MUTED, $c[0], $font);
    }
    line($img, $x, $y + 12, $x + $w, $y + 12, C_LINE);
}

/* ---------------------------------------------------------------- 面板骨架 */

/**
 * 绘制宝塔面板骨架：顶栏 + 左侧菜单 + 内容区标题 + 页脚说明。
 * 返回 [画布, 内容起始 y]。
 */
function panel(int $w, int $h, string $font, string $active, string $crumb, string $title): array
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, col($img, C_BG));

    /* 顶栏 */
    imagefilledrectangle($img, 0, 0, $w, 48, col($img, C_TOPBAR));
    rect($img, 16, 10, 28, 28, C_GREEN, null, 7);
    text_center($img, 30, 29, 13, C_WHITE, '宝', $font);
    text($img, 54, 30, 15, C_WHITE, '宝塔面板', $font);
    text_right($img, $w - 18, 30, 11.5, [0xB9, 0xC4, 0xD4], 'Linux 面板 9.6.0 · 192.0.2.10 · admin', $font);

    /* 左侧菜单 */
    $menu = ['网站', 'FTP', '数据库', '监控', '文件', '终端', '软件商店', 'Docker', '安全', '计划任务', '回收站', '面板设置'];
    imagefilledrectangle($img, 0, 48, 168, $h - 40, col($img, C_SIDEBAR));
    foreach ($menu as $i => $m) {
        $top = 58 + $i * 36;
        $isNow = $m === $active;
        if ($isNow) {
            imagefilledrectangle($img, 0, $top, 168, $top + 34, col($img, C_SIDE_HL));
            imagefilledrectangle($img, 0, $top, 3, $top + 34, col($img, C_GREEN));
        }
        text($img, 30, $top + 23, 13, $isNow ? C_WHITE : [0xC3, 0xCE, 0xDA], $m, $font);
    }

    /* 内容区 */
    imagefilledrectangle($img, 169, 49, $w, $h - 41, col($img, C_BG));
    text($img, 192, 78, 11.5, C_MUTED, $crumb, $font);
    text($img, 192, 112, 19, C_INK, $title, $font);

    /* 页脚 */
    imagefilledrectangle($img, 0, $h - 40, $w, $h, col($img, C_CARD));
    line($img, 0, $h - 40, $w, $h - 40, C_LINE);
    text($img, 192, $h - 15, 11, C_MUTED, '界面示意图：按宝塔面板 9.x 的菜单与字段绘制，非真实截图；示例数据已脱敏', $font);

    return [$img, 140];
}

function save($img, string $file): void
{
    $path = YX_BAOTA_OUT . '/' . $file;
    imagepng($img, $path, 9);
    imagedestroy($img);
    echo '  已生成 ' . $file . ' (' . number_format(filesize($path) / 1024, 1) . " KB)\n";
}

define('YX_BAOTA_OUT', $OUT);
echo "输出目录：{$OUT}\n字体：{$FONT}\n";

/* ================================================================ 二、安装宝塔（SSH 终端） */
$w = 1000;
$h = 680;
[$img, $y] = panel($w, $h, $FONT, '终端', '首页 / 终端 / SSH 终端', '终端');

card($img, 192, $y, 776, 420);
text($img, 212, $y + 30, 13, C_INK, 'SSH 终端 · root@192.0.2.10', $FONT);
badge($img, $FONT, 880, $y + 15, '已连接', C_OK, C_OKSOFT);
line($img, 192, $y + 50, 968, $y + 50, C_LINE);
rect($img, 212, $y + 64, 736, 336, C_TERM, null, 8);

$term = [
    ['[root@server ~]# curl -sSO https://download.bt.cn/install/install_panel.sh', [0x7C, 0xE3, 0x8B]],
    ['[root@server ~]# bash install_panel.sh ed8484bec', [0x7C, 0xE3, 0x8B]],
    ['正在安装宝塔面板，请稍候 ...', [0xA9, 0xB4, 0xC2]],
    ['============================================', [0x3E, 0x4C, 0x5E]],
    ['面板地址: https://192.0.2.10:8888/8f3c1a2b', [0x7C, 0xC7, 0xFF]],
    ['用户名: admin', [0xA9, 0xB4, 0xC2]],
    ['密码: **********（登录后请立即修改）', [0xA9, 0xB4, 0xC2]],
    ['提示: 请在 firewall / 安全组放行 8888 端口', [0xE8, 0xC5, 0x7A]],
    ['[root@server ~]# _', [0x7C, 0xE3, 0x8B]],
];
$ty = $y + 96;
foreach ($term as $t) {
    text($img, 232, $ty, 12, $t[1], $t[0], $FONT);
    $ty += 30;
}

text($img, 192, 592, 12.5, C_INK2, '① 仅支持 CentOS / Debian / Ubuntu，勿在 Windows 上部署；面板初始化时可直接选择「LNMP」环境。', $FONT);
text($img, 192, 616, 12.5, C_INK2, '② 面板端口（默认 8888）需在云厂商安全组与服务器防火墙同时放行，否则浏览器打不开面板。', $FONT);
save($img, 'baota-step01-install.png');

/* ================================================================ 三、安装 LNMP 环境（软件商店） */
$w = 1000;
$h = 700;
[$img, $y] = panel($w, $h, $FONT, '软件商店', '首页 / 软件商店', '软件商店');

/* 页签 */
$tabs = ['已安装', '可升级', '全部', '一键部署'];
$tx = 192;
foreach ($tabs as $i => $t) {
    $isNow = $i === 0;
    text($img, $tx, $y + 4, 13, $isNow ? C_INK : C_MUTED, $t, $FONT);
    if ($isNow) {
        rect($img, $tx, $y + 12, tw($t, 13, $FONT), 3, C_BLUE);
    }
    $tx += tw($t, 13, $FONT) + 34;
}

$apps = [
    ['N', 'Nginx', '1.24.0', '运行中', true],
    ['M', 'MySQL', '8.0.36', '运行中', true],
    ['P', 'PHP', '8.2.34', '运行中', true],
    ['A', 'phpMyAdmin', '5.2.1', '运行中', true],
    ['R', 'Redis', '7.2.4', '未安装', false],
    ['F', 'Pure-Ftpd', '1.0.49', '未安装', false],
];
$cw = 240;
$ch = 112;
foreach ($apps as $i => $a) {
    $cx = 192 + ($i % 3) * ($cw + 16);
    $cy = $y + 40 + intdiv($i, 3) * ($ch + 16);
    card($img, $cx, $cy, $cw, $ch);
    rect($img, $cx + 16, $cy + 18, 40, 40, [0xE8, 0xF0, 0xFF], null, 8);
    text_center($img, $cx + 36, $cy + 44, 17, C_BLUE, $a[0], $FONT);
    text($img, $cx + 68, $cy + 34, 13, C_INK, $a[1], $FONT);
    text($img, $cx + 68, $cy + 54, 11.5, C_MUTED, $a[2], $FONT);
    badge($img, $FONT, $cx + 16, $cy + 74, $a[3], $a[4] ? C_OK : C_WARN, $a[4] ? C_OKSOFT : C_WARNSOFT);
    btn($img, $FONT, $cx + 166, $cy + 68, 58, '设置', false, 28);
}

card($img, 192, $y + 300, 776, 92);
text($img, 212, $y + 328, 13, C_INK, '推荐环境组合', $FONT);
text($img, 212, $y + 356, 12, C_INK2, 'Nginx 1.24 + MySQL 8.0 + PHP 8.2（缺一不可：数据库与 PHP 版本不符会导致安装向导报错）。', $FONT);
text($img, 212, $y + 378, 12, C_MUTED, 'PHP 扩展在「软件商店 → PHP 8.2 → 设置 → 扩展」中确认，见第四步。', $FONT);
save($img, 'baota-step02-lnmp.png');

/* ================================================================ 四、PHP 扩展（PHP 8.2 设置） */
$w = 1000;
$h = 680;
[$img, $y] = panel($w, $h, $FONT, '软件商店', '首页 / 软件商店 / PHP 8.2 / 设置', 'PHP 8.2 · 设置');

card($img, 192, $y, 776, 436);
$tabs = ['配置修改', '性能调整', '扩展', '日志', '负载状态', '其他'];
$tx = 212;
foreach ($tabs as $i => $t) {
    $isNow = $i === 2;
    text($img, $tx, $y + 30, 12.5, $isNow ? C_BLUE : C_INK2, $t, $FONT);
    if ($isNow) {
        rect($img, $tx, $y + 38, tw($t, 12.5, $FONT), 3, C_BLUE);
    }
    $tx += tw($t, 12.5, $FONT) + 30;
}
line($img, 192, $y + 42, 968, $y + 42, C_LINE);

$exts = [
    ['pdo_mysql', 'MySQL 驱动（必装）', true],
    ['pdo_sqlite', 'SQLite 驱动（可选）', true],
    ['curl', 'HTTP 请求（必装）', true],
    ['openssl', '加密与 HTTPS（必装）', true],
    ['mbstring', '多字节字符串（必装）', true],
    ['gd', '图形处理 / 验证码（必装）', true],
    ['json', 'JSON 编解码（必装）', true],
    ['fileinfo', '文件类型识别（上传）', true],
    ['simplexml', 'XML 解析（仅 XML 上游需要）', false],
];
$ry = $y + 66;
foreach ($exts as $e) {
    text($img, 216, $ry + 16, 12.5, C_INK, $e[0], $FONT);
    text($img, 400, $ry + 16, 11.5, C_MUTED, $e[1], $FONT);
    if ($e[2]) {
        badge($img, $FONT, 760, $ry + 4, '已安装', C_OK, C_OKSOFT);
        btn($img, $FONT, 866, $ry, 78, '卸载', false, 28);
    } else {
        badge($img, $FONT, 760, $ry + 4, '未安装', C_WARN, C_WARNSOFT);
        btn($img, $FONT, 866, $ry, 78, '安装', false, 28);
    }
    $ry += 40;
}

text($img, 192, 606, 12.5, C_INK2, '标「必装」的扩展缺失时，安装向导「环境检测」不会通过；标「建议」的可以跳过，不影响安装。', $FONT);
save($img, 'baota-step03-php-ext.png');

/* ================================================================ 五、上传并解压安装包（文件管理） */
$w = 1000;
$h = 700;
[$img, $y] = panel($w, $h, $FONT, '文件', '首页 / 文件 / www/wwwroot/your-domain', '文件管理');

text($img, 192, $y + 8, 12.5, C_INK2, '/www/wwwroot/your-domain', $FONT);
btn($img, $FONT, 700, $y - 10, 76, '上传', true, 30);
btn($img, $FONT, 784, $y - 10, 76, '新建', false, 30);
btn($img, $FONT, 866, $y - 10, 78, '权限', false, 30);

card($img, 192, $y + 30, 776, 300);
table_head($img, $FONT, 192, $y + 58, 776, [['名称', 20], ['大小', 330], ['修改时间', 430], ['权限', 590], ['所有者', 680]]);
$files = [
    ['admin/', '-', '2026-11-06 10:12', '755', 'www'],
    ['assets/', '-', '2026-11-06 10:12', '755', 'www'],
    ['includes/', '-', '2026-11-06 10:12', '755', 'www'],
    ['install/', '-', '2026-11-06 10:12', '755', 'www'],
    ['index.php', '36 KB', '2026-11-06 10:12', '644', 'www'],
    ['README.md', '21 KB', '2026-11-06 10:12', '644', 'www'],
    ['发行说明.md', '1.5 KB', '2026-11-06 10:12', '644', 'www'],
];
$fy = $y + 88;
foreach ($files as $f) {
    text($img, 212, $fy, 12, C_INK, $f[0], $FONT);
    text($img, 522, $fy, 11.5, C_MUTED, $f[1], $FONT);
    text($img, 622, $fy, 11.5, C_MUTED, $f[2], $FONT);
    text($img, 782, $fy, 11.5, C_MUTED, $f[3], $FONT);
    text($img, 872, $fy, 11.5, C_MUTED, $f[4], $FONT);
    $fy += 32;
}

/* 上传弹窗 */
$dx = 512;
$dy = 300;
$dw = 440;
$dh = 300;
rect($img, $dx + 6, $dy + 6, $dw, $dh, [0xE6, 0xEA, 0xF1], null, 10);
card($img, $dx, $dy, $dw, $dh);
text($img, $dx + 20, $dy + 28, 13, C_INK, '上传文件', $FONT);
text_right($img, $dx + $dw - 18, $dy + 27, 12, C_MUTED, '×', $FONT);
line($img, $dx, $dy + 44, $dx + $dw, $dy + 44, C_LINE);

rect($img, $dx + 20, $dy + 62, $dw - 40, 86, [0xF7, 0xFA, 0xFF], [0xC5, 0xD8, 0xF5], 8);
text_center($img, $dx + $dw / 2, $dy + 100, 12.5, C_BLUE, '把文件拖到这里，或点击选择文件', $FONT);
text_center($img, $dx + $dw / 2, $dy + 124, 11, C_MUTED, '支持 .zip / .tar.gz，单文件不超过 200 MB', $FONT);

text($img, $dx + 20, $dy + 180, 11.5, C_INK2, 'youxiang-finance-1.6.2-official.tar.gz', $FONT);
rect($img, $dx + 20, $dy + 192, $dw - 40, 6, [0xE8, 0xEC, 0xF3], null, 3);
rect($img, $dx + 20, $dy + 192, $dw - 40, 6, C_GREEN, null, 3);
text($img, $dx + 20, $dy + 220, 11, C_OK, '上传完成（100%）', $FONT);

btn($img, $FONT, $dx + 20, $dy + 240, 120, '开始上传', true, 34);
btn($img, $FONT, $dx + 152, $dy + 240, 100, '关闭', false, 34);

text($img, 192, 630, 12.5, C_INK2, '上传完成后右键压缩包选择「解压」，确认 index.php / includes/ / admin/ 直接位于站点根目录，不要多套一层文件夹。', $FONT);
save($img, 'baota-step04-upload.png');

/* ================================================================ 六、添加站点 */
$w = 1000;
$h = 720;
[$img, $y] = panel($w, $h, $FONT, '网站', '首页 / 网站', '网站');
btn($img, $FONT, 852, $y - 30, 116, '添加站点', true, 34);

card($img, 192, $y, 776, 110);
table_head($img, $FONT, 192, $y + 28, 776, [['站点名', 20], ['状态', 250], ['PHP 版本', 340], ['根目录', 440], ['到期时间', 660]]);
text($img, 212, $y + 56, 12, C_INK, 'www.example.com', $FONT);
badge($img, $FONT, 440, $y + 42, '运行中', C_OK, C_OKSOFT);
text($img, 532, $y + 56, 11.5, C_INK2, 'PHP-82', $FONT);
text($img, 632, $y + 56, 11.5, C_MUTED, '/www/wwwroot/www.example.com', $FONT);
text($img, 852, $y + 56, 11.5, C_MUTED, '2027-11-06', $FONT);

/* 添加站点弹窗 */
$dx = 232;
$dy = 280;
$dw = 736;
$dh = 360;
rect($img, $dx + 6, $dy + 6, $dw, $dh, [0xE6, 0xEA, 0xF1], null, 10);
card($img, $dx, $dy, $dw, $dh);
text($img, $dx + 22, $dy + 28, 13.5, C_INK, '添加站点', $FONT);
text_right($img, $dx + $dw - 18, $dy + 27, 12, C_MUTED, '×', $FONT);
line($img, $dx, $dy + 44, $dx + $dw, $dy + 44, C_LINE);

field($img, $FONT, $dx + 24, $dy + 76, $dw - 48, '域名（一行一个，可填多个，支持泛解析）', 'www.example.com');
field($img, $FONT, $dx + 24, $dy + 138, $dw - 200, '根目录', '/www/wwwroot/www.example.com');
btn($img, $FONT, $dx + 570, $dy + 148, 118, '选择目录', false, 36);
select($img, $FONT, $dx + 24, $dy + 200, 330, 'FTP', '不创建');
select($img, $FONT, $dx + 382, $dy + 200, 330, '数据库', '不创建');
select($img, $FONT, $dx + 24, $dy + 262, 330, 'PHP 版本', 'PHP-82');
select($img, $FONT, $dx + 382, $dy + 262, 330, '网站分类', '默认分类');
btn($img, $FONT, $dx + 24, $dy + 312, 100, '提交', true, 34);
btn($img, $FONT, $dx + 136, $dy + 312, 92, '取消', false, 34);

text($img, 192, 664, 12.5, C_INK2, '根目录必须指向已上传代码的目录；PHP 版本选 8.2；FTP 与数据库本步不创建（数据库在第八步单独添加）。', $FONT);
save($img, 'baota-step05-add-site.png');

/* ================================================================ 七、目录权限（设置权限弹窗） */
$w = 1000;
$h = 740;
[$img, $y] = panel($w, $h, $FONT, '文件', '首页 / 文件 / www/wwwroot/your-domain', '目录权限');

card($img, 192, $y, 776, 130);
table_head($img, $FONT, 192, $y + 28, 776, [['名称', 20], ['大小', 330], ['权限', 430], ['所有者', 560], ['用户组', 660]]);
foreach ([['admin/', '755', 'www', 'www'], ['runtime/', '755', 'www', 'www'], ['index.php', '644', 'www', 'www']] as $i => $r) {
    $ry = $y + 58 + $i * 30;
    text($img, 212, $ry, 12, C_INK, $r[0], $FONT);
    text($img, 622, $ry, 11.5, C_MUTED, $r[1], $FONT);
    text($img, 752, $ry, 11.5, C_MUTED, $r[2], $FONT);
    text($img, 852, $ry, 11.5, C_MUTED, $r[3], $FONT);
}

/* 权限弹窗 */
$dx = 232;
$dy = 290;
$dw = 470;
$dh = 320;
rect($img, $dx + 6, $dy + 6, $dw, $dh, [0xE6, 0xEA, 0xF1], null, 10);
card($img, $dx, $dy, $dw, $dh);
text($img, $dx + 22, $dy + 28, 13.5, C_INK, '设置权限', $FONT);
text_right($img, $dx + $dw - 18, $dy + 27, 12, C_MUTED, '×', $FONT);
line($img, $dx, $dy + 44, $dx + $dw, $dy + 44, C_LINE);

select($img, $FONT, $dx + 24, $dy + 76, 190, '权限', '755');
select($img, $FONT, $dx + 232, $dy + 76, 190, '所有者', 'www');
select($img, $FONT, $dx + 24, $dy + 138, 190, '用户组', 'www');
checkbox($img, $FONT, $dx + 232, $dy + 148, '应用到子目录', true);
checkbox($img, $FONT, $dx + 24, $dy + 210, '递归设置子文件为 644', true);
btn($img, $FONT, $dx + 24, $dy + 262, 100, '确定', true, 34);
btn($img, $FONT, $dx + 136, $dy + 262, 92, '取消', false, 34);

/* 批量设置命令 */
$cx = 726;
card($img, $cx, $dy, 242, $dh);
text($img, $cx + 18, $dy + 28, 12.5, C_INK, '批量设置（SSH）', $FONT);
line($img, $cx, $dy + 44, $cx + 242, $dy + 44, C_LINE);
rect($img, $cx + 14, $dy + 60, 214, 190, C_TERM, null, 6);
$cmds = ['chown -R www:www .', 'find . -type d \\', '  -exec chmod 755 {} \\;', 'find . -type f \\', '  -exec chmod 644 {} \\;', 'chmod -R 755 runtime'];
$cy = $dy + 84;
foreach ($cmds as $c) {
    text($img, $cx + 26, $cy, 9.5, [0x8B, 0xE9, 0x9B], $c, $FONT);
    $cy += 26;
}

text($img, 192, 660, 12.5, C_INK2, 'runtime/ 必须可写（SQLite 数据文件、日志、授权缓存）；includes/ 需在安装时写入 config.php。', $FONT);
save($img, 'baota-step06-permission.png');

/* ================================================================ 八、创建数据库 */
$w = 1000;
$h = 720;
[$img, $y] = panel($w, $h, $FONT, '数据库', '首页 / 数据库', '数据库');
btn($img, $FONT, 852, $y - 30, 116, '添加数据库', true, 34);

card($img, 192, $y, 776, 110);
table_head($img, $FONT, 192, $y + 28, 776, [['数据库名', 20], ['用户名', 230], ['访问权限', 430], ['大小', 580], ['操作', 690]]);
text($img, 212, $y + 56, 12, C_INK, 'youxiang_finance', $FONT);
text($img, 422, $y + 56, 11.5, C_INK2, 'youxiang_finance', $FONT);
badge($img, $FONT, 622, $y + 42, '本地服务器', C_OK, C_OKSOFT);
text($img, 772, $y + 56, 11.5, C_MUTED, '0 MB', $FONT);
text($img, 882, $y + 56, 11.5, C_BLUE, '管理', $FONT);

$dx = 232;
$dy = 280;
$dw = 736;
$dh = 350;
rect($img, $dx + 6, $dy + 6, $dw, $dh, [0xE6, 0xEA, 0xF1], null, 10);
card($img, $dx, $dy, $dw, $dh);
text($img, $dx + 22, $dy + 28, 13.5, C_INK, '添加数据库', $FONT);
text_right($img, $dx + $dw - 18, $dy + 27, 12, C_MUTED, '×', $FONT);
line($img, $dx, $dy + 44, $dx + $dw, $dy + 44, C_LINE);

field($img, $FONT, $dx + 24, $dy + 76, $dw - 48, '数据库名', 'youxiang_finance');
field($img, $FONT, $dx + 24, $dy + 138, $dw - 48, '用户名', 'youxiang_finance');
field($img, $FONT, $dx + 24, $dy + 200, $dw - 200, '密码', '************（点击右侧随机生成）', C_MUTED);
btn($img, $FONT, $dx + 570, $dy + 210, 118, '随机生成', false, 36);
select($img, $FONT, $dx + 24, $dy + 262, 330, '访问权限', '本地服务器');
select($img, $FONT, $dx + 382, $dy + 262, 330, '字符集', 'utf8mb4');
btn($img, $FONT, $dx + 24, $dy + 312, 100, '提交', true, 34);
btn($img, $FONT, $dx + 136, $dy + 312, 92, '取消', false, 34);

text($img, 192, 664, 12.5, C_INK2, '访问权限务必选「本地服务器」，不要开放所有人；库表由安装向导自动创建，无需手动导入 SQL。', $FONT);
save($img, 'baota-step07-database.png');

/* ================================================================ 九、伪静态 */
$w = 1000;
$h = 660;
[$img, $y] = panel($w, $h, $FONT, '网站', '首页 / 网站 / www.example.com / 伪静态', '伪静态');

card($img, 192, $y, 776, 360);
text($img, 212, $y + 30, 13, C_INK, '当前站点：www.example.com', $FONT);
badge($img, $FONT, 880, $y + 16, '已启用', C_OK, C_OKSOFT);
line($img, 192, $y + 48, 968, $y + 48, C_LINE);

rect($img, 212, $y + 62, 736, 216, C_TERM, null, 8);
$code = [
    'location / {',
    '    if (!-e $request_filename) {',
    '        rewrite ^(.*)$ /index.php?s=$1 last;',
    '        break;',
    '    }',
    '}',
];
$cy = $y + 96;
foreach ($code as $c) {
    text($img, 232, $cy, 12, [0x8B, 0xE9, 0x9B], $c, $FONT);
    $cy += 28;
}

btn($img, $FONT, 212, $y + 298, 100, '保存', true, 34);
btn($img, $FONT, 324, $y + 298, 100, '清空', false, 34);

text($img, 192, 552, 12.5, C_INK2, '系统使用 index.php?s=xxx 方式路由，宝塔站点模板默认已包含 try_files，一般无需额外伪静态。', $FONT);
text($img, 192, 576, 12.5, C_MUTED, '仅在改写路由规则时才需要上面这段；保存后 Nginx 会自动重载。', $FONT);
save($img, 'baota-step08-rewrite.png');

/* ================================================================ 十、临时放开安装目录（配置文件） */
$w = 1000;
$h = 680;
[$img, $y] = panel($w, $h, $FONT, '网站', '首页 / 网站 / www.example.com / 配置文件', '配置文件');

card($img, 192, $y, 776, 400);
text($img, 212, $y + 30, 13, C_INK, '站点配置文件 · 安装期间临时放开安装目录', $FONT);
line($img, 192, $y + 48, 968, $y + 48, C_LINE);

rect($img, 212, $y + 66, 736, 236, C_TERM, null, 8);
$code = [
    ['# 安装完成后必须恢复下面这一行（默认是放开的）', [0x8A, 0x96, 0xA5]],
    ['#location ^~ /install/ { return 404; }', [0xE8, 0xC5, 0x7A]],
    ['location ^~ /includes/ { return 404; }', [0x8B, 0xE9, 0x9B]],
    ['location ^~ /tools/ { return 404; }', [0x8B, 0xE9, 0x9B]],
    ['location ^~ /cache/ { return 404; }', [0x8B, 0xE9, 0x9B]],
    ['# 保存后访问 https://your-domain/install/install.php 开始安装', [0x8A, 0x96, 0xA5]],
];
$cy = $y + 98;
foreach ($code as $c) {
    text($img, 232, $cy, 11.5, $c[1], $c[0], $FONT);
    $cy += 30;
}

btn($img, $FONT, 212, $y + 322, 100, '保存', true, 34);
text($img, 332, $y + 345, 12, C_MUTED, '保存后 Nginx 自动重载，无需重启', $FONT);

text($img, 192, 572, 12.5, C_INK2, '安装完成后取消注释、点保存，再访问 /install/install.php 应返回 404；未被注释时不要长时间对外开放安装目录。', $FONT);
save($img, 'baota-step09-install-access.png');

/* ================================================================ 十一、SSL 与强制 HTTPS */
$w = 1000;
$h = 700;
[$img, $y] = panel($w, $h, $FONT, '网站', '首页 / 网站 / www.example.com / SSL', 'SSL');

$tabs = ["Let's Encrypt", '其他证书', '商业证书（付费）'];
$tx = 192;
foreach ($tabs as $i => $t) {
    $isNow = $i === 0;
    text($img, $tx, $y + 4, 13, $isNow ? C_INK : C_MUTED, $t, $FONT);
    if ($isNow) {
        rect($img, $tx, $y + 12, tw($t, 13, $FONT), 3, C_BLUE);
    }
    $tx += tw($t, 13, $FONT) + 34;
}

card($img, 192, $y + 34, 776, 220);
field($img, $FONT, 212, $y + 70, 560, '验证域名', 'www.example.com');
select($img, $FONT, 212, $y + 132, 330, '验证方式', '文件验证');
btn($img, $FONT, 660, $y + 142, 150, '申请证书', true, 36);
text($img, 212, $y + 210, 11.5, C_MUTED, '申请前请确认域名已解析到本服务器，并放行 80 / 443 端口。', $FONT);

card($img, 192, $y + 276, 776, 120);
badge($img, $FONT, 212, $y + 300, '已部署', C_OK, C_OKSOFT);
text($img, 292, $y + 315, 12.5, C_INK, '证书有效期至 2027-02-04（剩余 89 天，面板自动续签）', $FONT);
text($img, 212, $y + 348, 11.5, C_MUTED, '证书类型：Let\'s Encrypt · 域名：www.example.com', $FONT);
checkbox($img, $FONT, 700, $y + 340, '强制 HTTPS', true);

text($img, 192, 600, 12.5, C_INK2, '开启强制 HTTPS 后，HTTP 请求会 301 跳转到 HTTPS；如用 CDN，请在 CDN 侧也配置证书。', $FONT);
save($img, 'baota-step10-ssl.png');

/* ================================================================ 十二、计划任务 */
$w = 1000;
$h = 740;
[$img, $y] = panel($w, $h, $FONT, '计划任务', '首页 / 计划任务', '计划任务');
btn($img, $FONT, 852, $y - 30, 116, '添加任务', true, 34);

card($img, 192, $y, 776, 110);
table_head($img, $FONT, 192, $y + 28, 776, [['任务名称', 20], ['类型', 330], ['执行周期', 460], ['状态', 610], ['操作', 700]]);
text($img, 212, $y + 56, 12, C_INK, '优享财务系统定时任务', $FONT);
text($img, 522, $y + 56, 11.5, C_INK2, 'Shell 脚本', $FONT);
text($img, 652, $y + 56, 11.5, C_MUTED, '每 5 分钟', $FONT);
badge($img, $FONT, 802, $y + 42, '正常', C_OK, C_OKSOFT);
text($img, 892, $y + 56, 11.5, C_BLUE, '日志', $FONT);

$dx = 232;
$dy = 280;
$dw = 736;
$dh = 370;
rect($img, $dx + 6, $dy + 6, $dw, $dh, [0xE6, 0xEA, 0xF1], null, 10);
card($img, $dx, $dy, $dw, $dh);
text($img, $dx + 22, $dy + 28, 13.5, C_INK, '添加任务', $FONT);
text_right($img, $dx + $dw - 18, $dy + 27, 12, C_MUTED, '×', $FONT);
line($img, $dx, $dy + 44, $dx + $dw, $dy + 44, C_LINE);

select($img, $FONT, $dx + 24, $dy + 70, 330, '任务类型', 'Shell 脚本');
field($img, $FONT, $dx + 382, $dy + 70, 330, '任务名称', '优享财务系统定时任务');
select($img, $FONT, $dx + 24, $dy + 132, 190, '执行周期', 'N 分钟');
field($img, $FONT, $dx + 232, $dy + 132, 120, '分钟', '5');

text($img, $dx + 24, $dy + 202, 12, C_INK2, '脚本内容', $FONT);
rect($img, $dx + 24, $dy + 212, $dw - 48, 84, C_TERM, null, 6);
text($img, $dx + 40, $dy + 240, 10, [0x8B, 0xE9, 0x9B], '/www/server/php/82/bin/php /www/wwwroot/your-domain/tools/cron.php \\', $FONT);
text($img, $dx + 40, $dy + 266, 10, [0x8B, 0xE9, 0x9B], '  --token=你的任务令牌', $FONT);

btn($img, $FONT, $dx + 24, $dy + 314, 110, '保存任务', true, 34);
btn($img, $FONT, $dx + 146, $dy + 314, 92, '取消', false, 34);

text($img, 192, 690, 12.5, C_INK2, '该任务是必需品：到期业务标记、自动续费、异步开通/回调队列、日志清理、授权巡检都依赖它。', $FONT);
save($img, 'baota-step11-cron.png');

/* ================================================================ 十三、安装完成检查 */
$w = 1000;
$h = 720;
[$img, $y] = panel($w, $h, $FONT, '网站', '首页 / 网站', '网站');

card($img, 192, $y, 776, 110);
table_head($img, $FONT, 192, $y + 28, 776, [['站点名', 20], ['状态', 250], ['PHP 版本', 340], ['根目录', 440], ['到期时间', 660]]);
text($img, 212, $y + 56, 12, C_INK, 'www.example.com', $FONT);
badge($img, $FONT, 440, $y + 42, '运行中', C_OK, C_OKSOFT);
text($img, 532, $y + 56, 11.5, C_INK2, 'PHP-82', $FONT);
text($img, 632, $y + 56, 11.5, C_MUTED, '/www/wwwroot/www.example.com', $FONT);
text($img, 852, $y + 56, 11.5, C_MUTED, '2027-11-06', $FONT);

/* 检查清单 */
card($img, 192, $y + 134, 470, 300);
text($img, 212, $y + 164, 13, C_INK, '安装完成检查', $FONT);
line($img, 192, $y + 180, 662, $y + 180, C_LINE);
$checks = [
    '后台 /admin/login.php 可用安装账号登录',
    '/install/、/includes/、/tools/ 均返回 404',
    'includes/config.php 中 debug = false',
    '全站 HTTPS 且强制跳转',
    '计划任务已创建，cron.log 有执行记录',
    '支付通道与授权服务器地址已配置',
];
$cy = $y + 210;
foreach ($checks as $c) {
    rect($img, 212, $cy - 11, 15, 15, C_OKSOFT, null, 4);
    text($img, 215, $cy + 1, 10, C_OK, '✓', $FONT);
    text($img, 238, $cy, 11.5, C_INK2, $c, $FONT);
    $cy += 38;
}

/* 验证命令 */
card($img, 682, $y + 134, 286, 300);
text($img, 702, $y + 164, 13, C_INK, '验证安装目录已关闭', $FONT);
line($img, 682, $y + 180, 968, $y + 180, C_LINE);
rect($img, 700, $y + 196, 250, 150, C_TERM, null, 6);
$lines = [
    ['$ curl -I https://', [0x7C, 0xE3, 0x8B]],
    ['  www.example.com', [0x7C, 0xE3, 0x8B]],
    ['  /install/install.php', [0x7C, 0xE3, 0x8B]],
    ['HTTP/2 404', [0x7C, 0xC7, 0xFF]],
    ['✓ 安装目录已关闭', [0x8B, 0xE9, 0x9B]],
];
$cy2 = $y + 222;
foreach ($lines as $l) {
    text($img, 714, $cy2, 10.5, $l[1], $l[0], $FONT);
    $cy2 += 24;
}
text($img, 702, $y + 380, 10.5, C_MUTED, '返回 404 才表示安装入口已封闭。', $FONT);

text($img, 192, 620, 12.5, C_INK2, '以上检查全部通过，即完成宝塔部署；随后在后台配置支付通道、授权地址并创建数据库备份计划。', $FONT);
save($img, 'baota-step12-check.png');

echo "\n完成。文档引用写法：![宝塔面板 · 添加站点](../uploads/doc/baota-step05-add-site.png)\n";
