<?php
/**
 * 优享财务系统 · 文档插图生成器（安装向导界面示意图）
 *
 * 用 GD 按 install/install.php 的真实字段与版式绘制「安装向导」界面示意图，
 * 输出到 uploads/doc/ 供 docs/安装教程.md 引用。
 *
 * 注意：这不是浏览器截图，而是按本系统安装向导字段绘制的示意图，
 *       因此图片底部与文档图注都会明确标注「界面示意图」。
 *
 * 用法：
 *   /www/server/php/82/bin/php tools/make_doc_images.php
 *   /www/server/php/82/bin/php tools/make_doc_images.php --font=/path/to/CJK.ttf
 *
 * 需要 GD（含 FreeType）与一份中文字体（系统无中文字体时用 --font 指定）。
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

/** 定位中文字体 */
function doc_font(array $args): string
{
    $candidates = [];
    if (!empty($args['font'])) {
        $candidates[] = (string)$args['font'];
    }
    $candidates = array_merge($candidates, [
        '/tmp/NotoSansCJKsc-Regular.otf',
        '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
        '/usr/share/fonts/truetype/arphic/uming.ttc',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    ]);
    foreach ($candidates as $f) {
        if ($f !== '' && is_file($f) && is_readable($f)) {
            return $f;
        }
    }
    fwrite(STDERR, "未找到可用中文字体，请用 --font=/path/to/font.ttf 指定\n");
    exit(1);
}

$FONT = doc_font($args);
$OUT  = $root . '/uploads/doc';
if (!is_dir($OUT) && !@mkdir($OUT, 0755, true) && !is_dir($OUT)) {
    fwrite(STDERR, "无法创建输出目录：{$OUT}\n");
    exit(1);
}

/* ---------------------------------------------------------------- 绘图助手 */

const C_BG      = [0xF5, 0xF7, 0xFB];
const C_PANEL   = [0xFF, 0xFF, 0xFF];
const C_INK     = [0x10, 0x18, 0x28];
const C_INK2    = [0x47, 0x54, 0x67];
const C_MUTED   = [0x66, 0x70, 0x85];
const C_LINE    = [0xE9, 0xED, 0xF5];
const C_PRIMARY = [0x3B, 0x5B, 0xFD];
const C_OK      = [0x12, 0xB7, 0x6A];
const C_OKSOFT  = [0xEA, 0xF9, 0xF1];
const C_BAD     = [0xB0, 0x23, 0x23];
const C_BADSOFT = [0xFD, 0xEC, 0xEC];
const C_FIELD   = [0xFD, 0xFE, 0xFF];

function col($img, array $rgb)
{
    return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
}

function tw(string $text, float $size, string $font): float
{
    $box = imagettfbbox($size, 0, $font, $text);
    return (float)(max($box[2], $box[0]) - min($box[2], $box[0]));
}

function text($img, float $x, float $y, float $size, array $rgb, string $text, string $font): float
{
    imagettftext($img, $size, 0, (int)round($x), (int)round($y), col($img, $rgb), $font, $text);
    return $x + tw($text, $size, $font);
}

/** 右对齐 / 居中输出 */
function text_right($img, float $right, float $y, float $size, array $rgb, string $text, string $font): void
{
    imagettftext($img, $size, 0, (int)round($right - tw($text, $size, $font)), (int)round($y), col($img, $rgb), $font, $text);
}

function rect($img, float $x, float $y, float $w, float $h, array $fill, ?array $border = null, int $r = 0): void
{
    // 圆角矩形：用四条边 + 四个扇形拼出，避免引入外部依赖
    if ($r <= 0) {
        imagefilledrectangle($img, (int)$x, (int)$y, (int)($x + $w), (int)($y + $h), col($img, $fill));
        if ($border) {
            imagerectangle($img, (int)$x, (int)$y, (int)($x + $w), (int)($y + $h), col($img, $border));
        }
        return;
    }
    $c  = col($img, $fill);
    $d  = $r * 2;
    imagefilledrectangle($img, (int)($x + $r), (int)$y, (int)($x + $w - $r), (int)($y + $h), $c);
    imagefilledrectangle($img, (int)$x, (int)($y + $r), (int)($x + $w), (int)($y + $h - $r), $c);
    imagefilledellipse($img, (int)($x + $r), (int)($y + $r), $d, $d, $c);
    imagefilledellipse($img, (int)($x + $w - $r), (int)($y + $r), $d, $d, $c);
    imagefilledellipse($img, (int)($x + $r), (int)($y + $h - $r), $d, $d, $c);
    imagefilledellipse($img, (int)($x + $w - $r), (int)($y + $h - $r), $d, $d, $c);
    if ($border) {
        $b = col($img, $border);
        imageline($img, (int)($x + $r), (int)$y, (int)($x + $w - $r), (int)$y, $b);
        imageline($img, (int)($x + $r), (int)($y + $h), (int)($x + $w - $r), (int)($y + $h), $b);
        imageline($img, (int)$x, (int)($y + $r), (int)$x, (int)($y + $h - $r), $b);
        imageline($img, (int)($x + $w), (int)($y + $r), (int)($x + $w), (int)($y + $h - $r), $b);
    }
}

function canvas(int $w, int $h)
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, col($img, C_BG));
    return $img;
}

/** 页头：品牌块 + 系统名 + 副标题 */
function head($img, string $font, float $x, float $y): void
{
    $size = 30;
    rect($img, $x, $y, 44, 44, C_PRIMARY, null, 10);
    $label = '优';
    $w = tw($label, 22, $font);
    text($img, $x + (44 - $w) / 2, $y + 32, 22, C_PANEL, $label, $font);
    text($img, $x + 58, $y + 20, 18, C_INK, '优享财务系统', $font);
    text($img, $x + 58, $y + 42, 12.5, C_MUTED, '业务与财务一体化管理平台 · 安装向导', $font);
}

/** 卡片：白底 + 边框 + 内边距，返回内容起始 y */
function card($img, float $x, float $y, float $w, float $h): void
{
    rect($img, $x, $y, $w, $h, C_PANEL, C_LINE, 12);
}

/** 表单字段：标签 + 输入框（editable=false 时用浅灰底模拟只读） */
function field($img, string $font, float $x, float $y, float $w, string $label, string $value, bool $password = false, float $labelSize = 12.5): void
{
    text($img, $x, $y, $labelSize, C_INK2, $label, $font);
    $boxY = $y + 10;
    rect($img, $x, $boxY, $w, 36, C_FIELD, C_LINE, 7);
    $shown = $password ? $value : $value;
    text($img, $x + 12, $boxY + 24, 13, $password ? C_MUTED : C_INK, $shown, $font);
}

/** 下拉框：右侧画一个 ▼ */
function select($img, string $font, float $x, float $y, float $w, string $label, string $value, float $labelSize = 12.5): void
{
    text($img, $x, $y, $labelSize, C_INK2, $label, $font);
    $boxY = $y + 10;
    rect($img, $x, $boxY, $w, 36, C_FIELD, C_LINE, 7);
    text($img, $x + 12, $boxY + 24, 13, C_INK, $value, $font);
    text_right($img, $x + $w - 12, $boxY + 23, 11, C_MUTED, '▼', $font);
}

/** 复选框（checked 决定是否勾选） */
function checkbox($img, string $font, float $x, float $y, string $label, bool $checked): void
{
    rect($img, $x, $y, 16, 16, $checked ? C_PRIMARY : C_FIELD, $checked ? null : C_LINE, 4);
    if ($checked) {
        text($img, $x + 5, $y + 12.5, 10, C_PANEL, '✓', $font);
    }
    text($img, $x + 24, $y + 13.5, 12.5, C_INK2, $label, $font);
}

function button($img, string $font, float $x, float $y, float $w, string $label, array $fill = C_PRIMARY, array $color = C_PANEL): void
{
    rect($img, $x, $y, $w, 40, $fill, null, 8);
    $t = tw($label, 14, $font);
    text($img, $x + ($w - $t) / 2, $y + 26, 14, $color, $label, $font);
}

/** 统一的底部说明：明确这是示意图而非截图 */
function footnote($img, string $font, float $right, float $bottom): void
{
    $note = '界面示意图：字段与本系统安装向导一致，示例数据已脱敏';
    text_right($img, $right - 24, $bottom - 18, 11.5, C_MUTED, $note, $font);
}

/** 安装向导顶部步骤条（与 install/install.php 的 wizard_steps() 一致） */
function stepper($img, string $font, float $x, float $y, int $current): void
{
    $steps = [1 => '许可协议', 2 => '环境检测', 3 => '数据库与站点', 4 => '创始人信息', 5 => '安装完成'];
    $w     = 172;
    $gap   = 10;
    $i     = 0;
    foreach ($steps as $n => $label) {
        $sx = $x + $i * ($w + $gap);
        $done = $n < $current;
        $now  = $n === $current;
        rect($img, $sx, $y, $w, 32, $now ? C_PRIMARY : ($done ? C_OKSOFT : C_PANEL), $now ? null : C_LINE, 8);
        $t = $n . ' ' . $label;
        $tt = tw($t, 12, $font);
        text($img, $sx + ($w - $tt) / 2, $y + 21, 12, $now ? C_PANEL : ($done ? C_OK : C_MUTED), $t, $font);
        $i++;
    }
}

/** 只读信息块（如「绑定域名」），样式接近只读输入框 */
function readonlyBar($img, string $font, float $x, float $y, float $w, string $label, string $value): void
{
    text($img, $x, $y, 12.5, C_INK2, $label, $font);
    rect($img, $x, $y + 10, $w, 34, C_OKSOFT, [0xC9, 0xEE, 0xDB], 7);
    text($img, $x + 12, $y + 33, 13, C_INK, $value, $font);
}

function save($img, string $path): void
{
    imagepng($img, $path, 9);
    imagedestroy($img);
    echo '  已生成 ' . str_replace(YX_DOC_OUT, '', $path) . ' (' . number_format(filesize($path) / 1024, 1) . " KB)\n";
}

define('YX_DOC_OUT', $OUT . '/');
echo "输出目录：{$OUT}\n字体：{$FONT}\n";

/* ================================================================ 图 1：环境检测（第 2 步） */
$w = 1000;
$h = 620;
$img = canvas($w, $h);
head($img, $FONT, 40, 26);
stepper($img, $FONT, 40, 78, 2);

card($img, 40, 126, 920, 338);
text($img, 68, 166, 15.5, C_INK, '环境与权限检测', $FONT);
text($img, 68, 188, 12, C_MUTED, '带「必检」标记的项目未通过时无法进入下一步；标记为「建议」的项目不影响安装。', $FONT);
imageline($img, 68, 202, 932, 202, col($img, C_LINE));

/** 一行检测项：状态图标 + 名称/标记 + 检测值 */
$row = function (float $yy, bool $ok, string $name, string $tag, string $val) use ($img, $FONT) {
    text($img, 68, $yy, 13, $ok ? C_OK : C_BAD, $ok ? '✓' : '✕', $FONT);
    text($img, 90, $yy, 13, C_INK2, $name, $FONT);
    $tagX = 90 + tw($name, 13, $FONT) + 8;
    rect($img, $tagX, $yy - 12, tw($tag, 10.5, $FONT) + 12, 17, $ok ? C_OKSOFT : C_BADSOFT, null, 4);
    text($img, $tagX + 6, $yy, 10.5, $ok ? C_OK : C_BAD, $tag, $FONT);
    text_right($img, 930, $yy, 12.5, C_INK, $val, $FONT);
};

$y = 228;
$row($y, true, '操作系统', '建议', 'Linux 6.8 x86_64');
$row($y + 30, true, 'PHP 版本（≥ 8.0）', '必检', '8.2.26');
$row($y + 60, true, 'PDO 扩展', '必检', '已启用');

text($img, 68, $y + 100, 13, C_INK, 'PHP 模块', $FONT);
$mods = ['pdo_mysql ✓', 'pdo_sqlite ✓', 'mbstring ✓', 'openssl ✓', 'curl ✓', 'json ✓', 'gd ✓', 'simplexml !'];
$mx = 68;
foreach ($mods as $m) {
    $mw = tw($m, 11.5, $FONT) + 18;
    $warn = substr($m, -1) === '!';
    rect($img, $mx, $y + 112, $mw, 24, $warn ? C_BADSOFT : C_FIELD, $warn ? [0xF3, 0xC9, 0xC9] : C_LINE, 6);
    text($img, $mx + 9, $y + 129, 11.5, $warn ? C_BAD : C_INK2, $m, $FONT);
    $mx += $mw + 8;
}

text($img, 68, $y + 172, 13, C_INK, '目录权限', $FONT);
$row($y + 198, true, 'includes/ 可写（写入 config.php）', '必检', '可写');
$row($y + 222, true, 'runtime/ · uploads/ 可写', '必检', '可写');

rect($img, 40, 476, 920, 44, C_PANEL, C_LINE, 10);
text($img, 68, 503, 12.5, C_MUTED, '通过 13 项', $FONT);
text($img, 160, 503, 12.5, C_MUTED, '建议处理 1 项', $FONT);
text($img, 262, 503, 12.5, C_MUTED, '未通过 0 项', $FONT);
button($img, $FONT, 700, 478, 110, '重新检测', C_FIELD, C_INK2);
button($img, $FONT, 822, 478, 110, '下一步');

footnote($img, $FONT, $w, $h);
save($img, YX_DOC_OUT . 'install-step1-env.png');

/* ================================================================ 图 2：数据库与站点信息（第 3 步） */
$w = 1000;
$h = 740;
$img = canvas($w, $h);
head($img, $FONT, 40, 26);
stepper($img, $FONT, 40, 78, 3);

card($img, 40, 126, 920, 520);
text($img, 68, 166, 15.5, C_INK, '站点信息', $FONT);
imageline($img, 68, 180, 932, 180, col($img, C_LINE));

$colW = 420;
$l1 = 68;
$l2 = 512;

field($img, $FONT, $l1, 204, $colW, '系统名称', '优享财务系统');
field($img, $FONT, $l2, 204, $colW, '网站域名（不要带协议与路径）', 'www.example.com');
field($img, $FONT, $l1, 274, $colW, '后台路径（默认 admin）', 'admin');
select($img, $FONT, $l2, 274, $colW, '初始版本', '企业版 · 规模化运营');
checkbox($img, $FONT, $l1 + 2, 356, '开启调试模式（生产环境请关闭）', false);

text($img, 68, 402, 15.5, C_INK, '数据库', $FONT);
imageline($img, 68, 416, 932, 416, col($img, C_LINE));

select($img, $FONT, $l1, 440, $colW, '驱动', 'MySQL（推荐生产使用）');
field($img, $FONT, $l2, 440, $colW, '表前缀', 'yx_');

field($img, $FONT, $l1, 510, $colW, '主机', '127.0.0.1');
field($img, $FONT, $l2, 510, $colW, '端口', '3306');

field($img, $FONT, $l1, 580, $colW, '数据库名', 'youxiang_finance');
field($img, $FONT, $l2, 580, $colW, '账号', 'youxiang_finance');

text($img, 68, 664, 12, C_MUTED, '密码与「库不存在时自动创建」在本图省略；单机小规模可切换为 SQLite，数据文件默认 runtime/youxiang.db。', $FONT);

footnote($img, $FONT, $w, $h);
save($img, YX_DOC_OUT . 'install-step2-database.png');

/* ================================================================ 图 3：创始人信息与授权码（第 4 步） */
$w = 1000;
$h = 620;
$img = canvas($w, $h);
head($img, $FONT, 40, 26);
stepper($img, $FONT, 40, 78, 4);

card($img, 40, 126, 920, 398);
text($img, 68, 166, 15.5, C_INK, '创始人信息与授权码', $FONT);
imageline($img, 68, 180, 932, 180, col($img, C_LINE));

field($img, $FONT, $l1, 204, $colW, '管理员账号', 'admin');
field($img, $FONT, $l2, 204, $colW, '管理员密码（至少 8 位）', '········', true);

field($img, $FONT, $l1, 274, $colW, '重复密码', '········', true);
field($img, $FONT, $l2, 274, $colW, '邮箱（接收系统通知）', 'admin@example.com');

field($img, $FONT, $l1, 344, 864, '授权码（必填，格式 XXXX-XXXX-XXXX-XXXX）', '8F41-****-****-9A76');

text($img, 68, 414, 12, C_MUTED, '绑定域名：www.example.com', $FONT);
button($img, $FONT, 68, 428, 118, '检测序列码', C_FIELD, C_INK2);
rect($img, 200, 428, 732, 40, C_OKSOFT, [0xC9, 0xEE, 0xDB], 8);
text($img, 216, 454, 12.5, C_OK, '校验通过：授权码与本站密钥复核一致（企业版），有效期至 2027-11-02。', $FONT);
text($img, 68, 496, 12, C_MUTED, '未填写授权码将无法继续安装：授权码属「授权包」，需在优享商店按次购买后取得。', $FONT);

button($img, $FONT, 68, 552, 110, '上一步', C_FIELD, C_INK2);
button($img, $FONT, 190, 552, 130, '开始安装');

footnote($img, $FONT, $w, $h);
save($img, YX_DOC_OUT . 'install-step3-founder.png');

/* ================================================================ 图 4：安装完成（第 5 步） */
$w = 1000;
$h = 540;
$img = canvas($w, $h);
head($img, $FONT, 40, 26);
stepper($img, $FONT, 40, 78, 5);

rect($img, 40, 126, 920, 78, C_OKSOFT, [0xC9, 0xEE, 0xDB], 10);
text($img, 68, 158, 14, C_OK, '安装完成', $FONT);
text($img, 68, 182, 12.5, C_INK2, '系统已安装完成，5 秒后自动跳转到后台登录页。', $FONT);

card($img, 40, 222, 920, 200);
text($img, 68, 264, 15.5, C_INK, '入口地址', $FONT);
imageline($img, 68, 282, 932, 282, col($img, C_LINE));

$rows = [
    ['后台入口', '/admin/login.php'],
    ['客户端入口', '/user/login.php'],
    ['门户首页', '/'],
    ['数据库', 'MySQL · youxiang_finance'],
];
$ry = 316;
foreach ($rows as $r) {
    text($img, 68, $ry, 12.5, C_MUTED, $r[0], $FONT);
    text($img, 188, $ry, 13, C_INK, $r[1], $FONT);
    $ry += 30;
}

text($img, 68, 460, 12, C_MUTED, '出于安全考虑，安装完成后请在服务器上限制 install/ 目录访问。', $FONT);
button($img, $FONT, 68, 474, 150, '立即进入后台');

footnote($img, $FONT, $w, $h);
save($img, YX_DOC_OUT . 'install-step4-done.png');

echo "\n完成。文档引用写法：![图注](../uploads/doc/install-step2-database.png)\n";
