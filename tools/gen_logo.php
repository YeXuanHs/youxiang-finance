<?php
/**
 * 优享财务系统 · Logo 生成工具
 *
 * 用法（在项目根目录执行）：
 *   php tools/gen_logo.php
 *   php tools/gen_logo.php --font=/path/to/cjk.ttf --name="优享财务系统"
 *
 * 产出：
 *   assets/logo/logo-mark.png      图标（64×64，透明背景，纯几何图形，无需字体）
 *   assets/logo/logo-mark@2x.png   图标 2 倍图（128×128）
 *   assets/logo/favicon.png        站点图标（32×32）
 *   assets/logo/logo.svg           矢量 Logo（含中英文名称，字体由浏览器渲染）
 *   assets/logo/logo-main.png      横版 Logo（需要中文字体；未找到字体时自动跳过）
 */

if (PHP_SAPI !== 'cli') {
    exit('该脚本仅支持命令行执行');
}

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    // 注意：选项名允许含数字（如 --mark2），早期写成 [a-z\-]+ 会导致 --mark2 被静默忽略
    if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}

$root    = dirname(__DIR__);
$logoDir = $root . '/assets/logo';
$name    = $args['name'] ?? '优享财务系统';
$sub     = $args['sub'] ?? 'YOUXIANG FINANCE';

// 颜色可通过命令行覆盖（默认保持原有蓝色）
//   --mark=#rrggbb    图标渐变起始色
//   --mark2=#rrggbb   图标渐变结束色
//   --glyph=#rrggbb   图标内图形颜色
//   --text=#rrggbb    中文名称颜色
//   --subcolor=#rrggbb 英文副标题颜色
$markFrom = yx_hex2rgb($args['mark']     ?? '#4dabf7', [77, 171, 247]);
$markTo   = yx_hex2rgb($args['mark2']    ?? '#3b5bdb', [59, 91, 219]);
$glyphRgb = yx_hex2rgb($args['glyph']    ?? '#ffffff', [255, 255, 255]);
$textRgb  = yx_hex2rgb($args['text']     ?? '#1f2733', [31, 39, 51]);
$subRgb   = yx_hex2rgb($args['subcolor'] ?? '#7d8799', [125, 135, 153]);

if (!is_dir($logoDir) && !mkdir($logoDir, 0755, true)) {
    fwrite(STDERR, "无法创建目录：{$logoDir}\n");
    exit(1);
}

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD 扩展未启用，无法生成 PNG。SVG 仍会正常生成。\n");
}

/* ---------------------------------------------------------------- 绘图基础 */

/** 判断像素是否落在圆角矩形内 */
function yx_in_round_rect(int $x, int $y, int $w, int $h, int $r): bool
{
    if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
        return false;
    }
    $cx = null;
    $cy = null;
    if ($x < $r && $y < $r) {
        $cx = $r; $cy = $r;
    } elseif ($x >= $w - $r && $y < $r) {
        $cx = $w - $r - 1; $cy = $r;
    } elseif ($x < $r && $y >= $h - $r) {
        $cx = $r; $cy = $h - $r - 1;
    } elseif ($x >= $w - $r && $y >= $h - $r) {
        $cx = $w - $r - 1; $cy = $h - $r - 1;
    }
    if ($cx === null) {
        return true;
    }
    return (($x - $cx) ** 2 + ($y - $cy) ** 2) <= $r ** 2;
}

function yx_lerp(int $a, int $b, float $t): int
{
    return (int)round($a + ($b - $a) * $t);
}

/** #rrggbb → [r,g,b]，非法值回落默认 */
function yx_hex2rgb(string $hex, array $default): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return $default;
    }
    return [
        (int)hexdec(substr($hex, 0, 2)),
        (int)hexdec(substr($hex, 2, 2)),
        (int)hexdec(substr($hex, 4, 2)),
    ];
}

/** [r,g,b] → #rrggbb */
function yx_rgb2hex(array $rgb): string
{
    return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
}

/**
 * 生成圆角渐变图标
 * $size 画布边长，$glyph 是否绘制图形符号，$from/$to 渐变起止色，$glyphColor 图形颜色
 */
function yx_make_mark(
    int $size,
    bool $glyph = true,
    bool $rounded = true,
    array $from = [77, 171, 247],
    array $to = [59, 91, 219],
    array $glyphColor = [255, 255, 255]
) {
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);

    $radius = $rounded ? (int)round($size * 0.24) : 0;
    [$c1, $c2] = [$from, $to];

    if ($rounded) {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!yx_in_round_rect($x, $y, $size, $size, $radius)) {
                    continue;
                }
                $t = (($x / max(1, $size - 1)) + ($y / max(1, $size - 1))) / 2;
                $col = imagecolorallocate($img, yx_lerp($c1[0], $c2[0], $t), yx_lerp($c1[1], $c2[1], $t), yx_lerp($c1[2], $c2[2], $t));
                imagesetpixel($img, $x, $y, $col);
            }
        }
    } else {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $t = (($x / max(1, $size - 1)) + ($y / max(1, $size - 1))) / 2;
                $col = imagecolorallocate($img, yx_lerp($c1[0], $c2[0], $t), yx_lerp($c1[1], $c2[1], $t), yx_lerp($c1[2], $c2[2], $t));
                imagesetpixel($img, $x, $y, $col);
            }
        }
    }

    if (!$glyph) {
        return $img;
    }

    // 三根上升柱状 + 顶部箭头，表达「财务 / 增长」
    $white = imagecolorallocate($img, $glyphColor[0], $glyphColor[1], $glyphColor[2]);
    $s = $size / 64; // 以 64 为基准缩放
    $bars = [
        [17, 40, 6, 12],
        [29, 31, 6, 21],
        [41, 22, 6, 30],
    ];
    foreach ($bars as $b) {
        imagefilledrectangle(
            $img,
            (int)round($b[0] * $s),
            (int)round($b[1] * $s),
            (int)round(($b[0] + $b[2]) * $s) - 1,
            (int)round(($b[1] + $b[3]) * $s) - 1,
            $white
        );
    }
    // 底边
    imagefilledrectangle($img, (int)round(14 * $s), (int)round(53 * $s), (int)round(50 * $s), (int)round(55 * $s), $white);

    return $img;
}

/**
 * 把 PNG 封装为 ICO（Vista+ 支持内嵌 PNG，浏览器可直接识别）
 * 避免站点根目录缺少 favicon.ico 时每次请求都产生 404 日志。
 */
function yx_write_ico(string $pngFile, string $icoFile, int $size): bool
{
    $png = @file_get_contents($pngFile);
    if ($png === false || $png === '') {
        return false;
    }
    $dim   = $size > 255 ? 0 : $size;   // 0 表示 256
    $header = pack('vvv', 0, 1, 1);     // reserved, type=icon, count=1
    $entry  = pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($png), 22);
    return (bool)file_put_contents($icoFile, $header . $entry . $png, LOCK_EX);
}

/* ---------------------------------------------------------------- 输出文件 */
$written = [];
$failed  = [];

if (function_exists('imagecreatetruecolor')) {
    foreach ([['logo-mark.png', 64], ['logo-mark@2x.png', 128], ['favicon.png', 32]] as [$file, $size]) {
        $img = yx_make_mark($size, true, $size >= 32, $markFrom, $markTo, $glyphRgb);
        $path = $logoDir . '/' . $file;
        if (imagepng($img, $path)) {
            $written[] = $file;
        } else {
            $failed[] = $file;
        }
        imagedestroy($img);
    }

    // ICO 图标：assets/logo/favicon.ico 与站点根 favicon.ico（避免 /favicon.ico 404）
    if (is_file($logoDir . '/favicon.png')) {
        if (yx_write_ico($logoDir . '/favicon.png', $logoDir . '/favicon.ico', 32)) {
            $written[] = 'favicon.ico';
            $rootIco = $root . '/favicon.ico';
            if (is_dir(dirname($rootIco)) && yx_write_ico($logoDir . '/favicon.png', $rootIco, 32)) {
                $written[] = '../favicon.ico（站点根）';
            }
        } else {
            $failed[] = 'favicon.ico';
        }
    }
}

/* ---- 矢量版 Logo ---- */
$svgName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$svgSub  = htmlspecialchars($sub, ENT_QUOTES, 'UTF-8');
$svgMarkFrom = yx_rgb2hex($markFrom);
$svgMarkTo   = yx_rgb2hex($markTo);
$svgGlyph    = yx_rgb2hex($glyphRgb);
$svgText     = yx_rgb2hex($textRgb);
$svgSubColor = yx_rgb2hex($subRgb);
$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="360" height="96" viewBox="0 0 360 96" role="img" aria-label="{$svgName}">
  <defs>
    <linearGradient id="yxgrad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$svgMarkFrom}"/>
      <stop offset="100%" stop-color="{$svgMarkTo}"/>
    </linearGradient>
  </defs>
  <rect x="8" y="16" width="64" height="64" rx="15" fill="url(#yxgrad)"/>
  <g fill="{$svgGlyph}">
    <rect x="25" y="56" width="6" height="12"/>
    <rect x="37" y="47" width="6" height="21"/>
    <rect x="49" y="38" width="6" height="30"/>
    <rect x="22" y="69" width="36" height="2"/>
  </g>
  <text x="88" y="52" font-family="'PingFang SC','Microsoft YaHei','Noto Sans SC',system-ui,sans-serif"
        font-size="27" font-weight="700" fill="{$svgText}">{$svgName}</text>
  <text x="88" y="73" font-family="system-ui,-apple-system,'Segoe UI',sans-serif"
        font-size="12" letter-spacing="2.2" fill="{$svgSubColor}">{$svgSub}</text>
</svg>

SVG;
if (file_put_contents($logoDir . '/logo.svg', $svg) !== false) {
    $written[] = 'logo.svg';
} else {
    $failed[] = 'logo.svg';
}

/* ---- 横版位图（需要中文字体） ---- */
/**
 * 查找可用于渲染中文的字体。
 * 仅接受文件名/路径带有中文或 CJK 特征的字体，避免误用 DejaVu 等无中文字形的字体
 * （否则中文会渲染成空白或方框，生成“看起来正常但实为空白”的 Logo）。
 */
function yx_find_cjk_font(): ?string
{
    $candidates = [];
    $curated = [
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
        '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
        '/usr/share/fonts/wenquanyi/wqy-microhei/wqy-microhei.ttc',
        '/usr/share/fonts/chinese/TrueType/simhei.ttf',
        '/usr/share/fonts/truetype/arphic/uming.ttc',
        '/usr/share/fonts/truetype/arphic/ukai.ttc',
        '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
        '/System/Library/Fonts/PingFang.ttc',
        'C:/Windows/Fonts/msyh.ttc',
        'C:/Windows/Fonts/simhei.ttf',
    ];
    foreach ($curated as $p) {
        if (is_file($p)) {
            return $p;
        }
    }

    // 兜底：扫描字体目录，但只接受带中文字体特征的命名
    $hint = '/(noto[-_]?sans[-_]?cjk|noto[-_]?serif[-_]?cjk|source[-_]?han|wqy|wenquanyi|microhei|zenhei|'
        . 'simhei|simsun|msyh|yahei|pingfang|heiti|songti|kaiti|uming|ukai|cjk|droidsansfallback|arialuni)/i';
    foreach (['/usr/share/fonts', '/usr/local/share/fonts', '/usr/share/fonts/truetype'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!in_array(strtolower($file->getExtension()), ['ttf', 'ttc', 'otf'], true)) {
                    continue;
                }
                $path = $file->getPathname();
                if (preg_match($hint, $path)) {
                    $candidates[] = $path;
                }
            }
        } catch (Throwable $e) {
            // 忽略不可读目录
        }
    }
    sort($candidates);
    if (!$candidates) {
        return null;
    }
    // 优先 Regular 字重：否则按字母序可能先拿到 Bold，导致副标题过重
    foreach ($candidates as $p) {
        if (stripos($p, 'regular') !== false) {
            return $p;
        }
    }
    foreach ($candidates as $p) {
        if (stripos($p, 'bold') === false && stripos($p, 'heavy') === false) {
            return $p;
        }
    }
    return $candidates[0];
}

$font = $args['font'] ?? null;
if (!$font || !is_file($font)) {
    $font = yx_find_cjk_font();
}

if (function_exists('imagettftext') && $font) {
    $w = 360;
    $h = 96;
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 255, 255, 127));
    imagealphablending($img, true);

    $mark = yx_make_mark(64, true, true, $markFrom, $markTo, $glyphRgb);
    imagecopy($img, $mark, 8, 16, 0, 0, 64, 64);
    imagedestroy($mark);

    $ink   = imagecolorallocate($img, $textRgb[0], $textRgb[1], $textRgb[2]);
    $muted = imagecolorallocate($img, $subRgb[0], $subRgb[1], $subRgb[2]);
    imagettftext($img, 24, 0, 88, 54, $ink, $font, $name);
    imagettftext($img, 11, 0, 89, 76, $muted, $font, $sub);

    if (imagepng($img, $logoDir . '/logo-main.png')) {
        $written[] = 'logo-main.png';
    } else {
        $failed[] = 'logo-main.png';
    }
    imagedestroy($img);
} else {
    echo "提示：未找到可用的中文 TTF 字体，已跳过 logo-main.png。\n";
    echo "      如需生成横版位图，请指定字体：php tools/gen_logo.php --font=/usr/share/fonts/xxx.ttf\n";
}

echo "Logo 输出目录：{$logoDir}\n";
foreach ($written as $f) {
    echo "  [OK]      {$f}\n";
}
foreach ($failed as $f) {
    echo "  [FAILED]  {$f}\n";
}
exit($failed ? 1 : 0);
