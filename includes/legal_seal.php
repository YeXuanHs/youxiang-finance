<?php
/**
 * 优享财务系统 · 电子印章（红色圆形公章）
 *
 * 用途：律师函签发时在落款处加盖「AnchorFlow团队」电子印章，
 *       印章内含单位名称（上弧排字）、五角星与签发日期。
 *
 * 设计要点：
 *   1) 以 SVG 为主输出：矢量、无字体依赖（客户端按字体栈回退渲染），适合页面预览与打印；
 *   2) 需要位图（下载 / 归档）时用 GD + FreeType 现场绘制 PNG，
 *      字体从项目 assets/fonts 与系统常见路径中探测，找不到 CJK 字体时如实降级（不画错字）；
 *   3) 印章文字全部来自系统设置与函件数据，不写死、不联网。
 */
class YxLegalSeal
{
    /** 印章红色（国标印章红近似值） */
    public const RED = '#c8102e';

    /** 落款单位：优先系统设置，其次默认主体 */
    public static function companyName(): string
    {
        $name = trim((string)setting('about_company', ''));
        if ($name === '') {
            $name = 'AnchorFlow团队';
        }
        return $name;
    }

    /** 印章日期文本（默认阿拉伯数字年月日，可切换为汉字大写） */
    public static function dateText(string $date = '', bool $cn = false): string
    {
        $date = trim($date);
        if ($date === '') {
            $date = date('Y-m-d');
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        $y = (int)date('Y', $ts);
        $m = (int)date('n', $ts);
        $d = (int)date('j', $ts);
        if (!$cn) {
            return $y . '年' . $m . '月' . $d . '日';
        }

        $digit = ['〇', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        $cnYear = '';
        foreach (str_split((string)$y) as $ch) {
            $cnYear .= $digit[(int)$ch];
        }
        $cnNum = function (int $n) use ($digit): string {
            if ($n < 10) {
                return $digit[$n];
            }
            if ($n === 10) {
                return '十';
            }
            if ($n < 20) {
                return '十' . $digit[$n % 10];
            }
            $tens = $digit[intdiv($n, 10)] . '十';
            return $n % 10 === 0 ? $tens : $tens . $digit[$n % 10];
        };
        return $cnYear . '年' . $cnNum($m) . '月' . $cnNum($d) . '日';
    }

    /**
     * SVG 印章
     *
     * @param array $opt company 单位名称 | date 日期(Y-m-d) | sn 函件编号 | size 输出边长(px) |
     *                   cn_date 是否汉字日期 | title 无障碍标题
     */
    public static function svg(array $opt = []): string
    {
        $company = self::trimText((string)($opt['company'] ?? self::companyName()), 26);
        $date    = self::dateText((string)($opt['date'] ?? ''), !empty($opt['cn_date']));
        $sn      = trim((string)($opt['sn'] ?? ''));
        $size    = max(80, min(600, (int)($opt['size'] ?? 220)));
        $red     = self::RED;

        $cx = 150.0;
        $cy = 150.0;
        $chars  = self::chars($company);
        $n      = count($chars);
        $radius = 101.0;
        $font   = $n > 15 ? 18.0 : ($n > 11 ? 21.0 : 24.0);
        $step   = ($font * 1.0) / $radius * 180 / M_PI;   // 每字占用的圆心角（度）
        $step   = min($step, 18.0);

        $arc = '';
        foreach ($chars as $i => $ch) {
            $angle = ($i - ($n - 1) / 2) * $step;
            $rad   = deg2rad($angle);
            $x     = $cx + $radius * sin($rad);
            $y     = $cy - $radius * cos($rad);
            $arc  .= sprintf(
                '<text x="%.2F" y="%.2F" transform="rotate(%.2F %.2F %.2F)" font-size="%.1F"'
                . ' font-family="Songti SC,SimSun,Noto Serif CJK SC,Noto Sans CJK SC,Microsoft YaHei,serif"'
                . ' font-weight="700" fill="%s" text-anchor="middle" dominant-baseline="central">%s</text>',
                $x, $y, $angle, $x, $y, $font, $red, self::xml($ch)
            );
        }

        $star = self::starPoints($cx, $cy, 34.0, 13.6);

        $dateY = $cy + 64;
        $dateFont = 20.0;
        if (mb_strlen($date) > 12) {
            $dateFont = 17.0;
        }

        $micro = '';
        if ($sn !== '') {
            $micro = sprintf(
                '<text x="%.1F" y="%.1F" font-size="8.2" letter-spacing="1.2" fill="%s" opacity="0.85"'
                . ' font-family="Songti SC,SimSun,Noto Sans CJK SC,Microsoft YaHei,serif"'
                . ' text-anchor="middle">%s</text>',
                $cx, $cy + 112, $red, self::xml('电子签章 ' . self::trimText($sn, 22))
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 300" width="%1$d" height="%1$d" role="img">'
            . '<title>%2$s</title><desc>%3$s</desc>'
            . '<circle cx="150" cy="150" r="139" fill="none" stroke="%4$s" stroke-width="5"/>'
            . '<circle cx="150" cy="150" r="130.5" fill="none" stroke="%4$s" stroke-width="1.6"/>'
            . '%5$s'
            . '<polygon points="%6$s" fill="%4$s"/>'
            . '<text x="150" y="%7$.1F" font-size="%8$.1F" letter-spacing="1.5" fill="%4$s" text-anchor="middle"'
            . ' font-family="Songti SC,SimSun,Noto Serif CJK SC,Noto Sans CJK SC,Microsoft YaHei,serif">%9$s</text>'
            . '%10$s'
            . '</svg>',
            $size,
            self::xml($company . ' 电子印章'),
            self::xml('单位名称：' . $company . '；签发日期：' . $date . ($sn !== '' ? '；文号：' . $sn : '')),
            $red,
            $arc,
            $star,
            $dateY,
            $dateFont,
            self::xml($date),
            $micro
        );
    }

    /** SVG 以 data URI 内联（页面 <img> 直接显示，无需额外请求） */
    public static function svgDataUri(array $opt = []): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($opt));
    }

    /**
     * PNG 印章（透明底，边长默认 600）
     *
     * @return string|null 二进制 PNG；无可用 CJK 字体或 GD 不可用时返回 null（调用方降级到 SVG）
     */
    public static function png(array $opt = []): ?string
    {
        if (!function_exists('imagettftext')) {
            return null;
        }
        $font = self::pngFont();
        if ($font === null) {
            return null;
        }

        $company = self::trimText((string)($opt['company'] ?? self::companyName()), 26);
        $date    = self::dateText((string)($opt['date'] ?? ''), !empty($opt['cn_date']));
        $sn      = trim((string)($opt['sn'] ?? ''));

        $scale = 4;                 // 先放大绘制再缩小，得到平滑边缘
        $base  = 300 * $scale;      // 1200
        $out   = max(200, min(1200, (int)($opt['size'] ?? 600)));

        $im = imagecreatetruecolor($base, $base);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        $transparent = imagecolorallocatealpha($im, 255, 255, 255, 127);
        imagefilledrectangle($im, 0, 0, $base - 1, $base - 1, $transparent);
        imagealphablending($im, true);

        $red = imagecolorallocatealpha($im, 200, 16, 46, 0);

        $cx = $base / 2;
        $cy = $base / 2;
        $k  = $base / 300;          // SVG 坐标系 → 位图坐标系

        // 双圈：用加粗的椭圆描边模拟环形
        imagesetthickness($im, (int)round(5 * $k));
        imageellipse($im, (int)$cx, (int)$cy, (int)(278 * $k), (int)(278 * $k), $red);
        imagesetthickness($im, (int)max(2, round(1.6 * $k)));
        imageellipse($im, (int)$cx, (int)$cy, (int)(261 * $k), (int)(261 * $k), $red);

        // 上弧排字
        $chars = self::chars($company);
        $n     = count($chars);
        $fontSize = ($n > 15 ? 18 : ($n > 11 ? 21 : 24)) * $k;
        $radius   = 101 * $k;
        $step     = min(deg2rad(18), ($fontSize * 1.0) / $radius);
        $tile     = (int)ceil($fontSize * 2.1);
        foreach ($chars as $i => $ch) {
            $angle = ($i - ($n - 1) / 2) * $step;
            $x     = $cx + $radius * sin($angle);
            $y     = $cy - $radius * cos($angle);
            $glyph = self::glyphTile($ch, $font, $fontSize, $red, $tile);
            if ($glyph === null) {
                continue;
            }
            // GD 的旋转角为逆时针，SVG 的 rotate 为顺时针，取负号对齐
            $rot = imagerotate($glyph, -rad2deg($angle), imagecolorallocatealpha($glyph, 255, 255, 255, 127));
            if ($rot !== false) {
                imagedestroy($glyph);
                $glyph = $rot;
            }
            $gw = imagesx($glyph);
            $gh = imagesy($glyph);
            imagecopy($im, $glyph, (int)round($x - $gw / 2), (int)round($y - $gh / 2), 0, 0, $gw, $gh);
            imagedestroy($glyph);
        }

        // 五角星
        $pts = self::starPoints($cx, $cy, 34 * $k, 13.6 * $k, false);
        $poly = [];
        foreach ($pts as [$px, $py]) {
            $poly[] = (int)round($px);
            $poly[] = (int)round($py);
        }
        // PHP 8.1+ 签名为 imagefilledpolygon($image, array $points, int $num_points, int $color)，
        // 第三参数若省略会被当成 $num_points（取 0 则画不出图形），故显式传顶点数
        imagefilledpolygon($im, $poly, (int)(count($poly) / 2), $red);

        // 日期
        $dateFont = ($n > 0 && mb_strlen($date) > 12 ? 17 : 20) * $k;
        self::drawCentered($im, $font, $dateFont, (int)$cx, (int)round($cy + 64 * $k), $date, $red);

        // 底部微缩文号
        if ($sn !== '') {
            self::drawCentered($im, $font, 8.6 * $k, (int)$cx, (int)round($cy + 112 * $k), '电子签章 ' . self::trimText($sn, 22), $red);
        }

        if ($out !== $base) {
            $scaled = imagescale($im, $out, $out, IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($im);
                $im = $scaled;
                imagesavealpha($im, true);
            }
        }

        ob_start();
        imagepng($im);
        $bin = (string)ob_get_clean();
        imagedestroy($im);
        return $bin === '' ? null : $bin;
    }

    /** 可用的位图字体（只接受真实含中文的字体文件） */
    public static function pngFont(): ?string
    {
        $candidates = [
            dirname(__DIR__) . '/assets/fonts/NotoSansSC-Bold.otf',
            dirname(__DIR__) . '/assets/fonts/NotoSansCJKsc-Regular.otf',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKsc-Regular.otf',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/truetype/arphic/uming.ttc',
            '/System/Library/Fonts/PingFang.ttc',
        ];
        foreach ($candidates as $f) {
            if (is_file($f) && is_readable($f) && filesize($f) > 20000) {
                return $f;
            }
        }
        return null;
    }

    /* ------------------------------ 内部工具 ------------------------------ */

    /** 按字符拆分（中文按字排弧，英文/数字连同相邻字符成组避免断词） */
    private static function chars(string $text): array
    {
        $out = [];
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if ($ch === ' ') {
                continue;
            }
            $out[] = $ch;
        }
        return $out;
    }

    private static function trimText(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') : $text;
    }

    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** 五角星顶点（SVG 用字符串，位图用数组） */
    private static function starPoints(float $cx, float $cy, float $rOut, float $rIn, bool $asString = true)
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $r = $i % 2 === 0 ? $rOut : $rIn;
            $a = -M_PI / 2 + $i * M_PI / 5;
            $points[] = [$cx + $r * cos($a), $cy + $r * sin($a)];
        }
        if (!$asString) {
            return $points;
        }
        $out = [];
        foreach ($points as [$x, $y]) {
            $out[] = sprintf('%.2F,%.2F', $x, $y);
        }
        return implode(' ', $out);
    }

    /** 把单个字符渲染到透明小方块（居中），供旋转贴图使用 */
    private static function glyphTile(string $ch, string $font, float $fontSize, $color, int $tile)
    {
        $im = imagecreatetruecolor($tile, $tile);
        imagesavealpha($im, true);
        $bg = imagecolorallocatealpha($im, 255, 255, 255, 127);
        imagefilledrectangle($im, 0, 0, $tile - 1, $tile - 1, $bg);
        imagealphablending($im, true);

        $box = @imagettfbbox($fontSize, 0, $font, $ch);
        if (!is_array($box)) {
            imagedestroy($im);
            return null;
        }
        $w = max($box[2], $box[4]) - min($box[0], $box[6]);
        $h = max($box[1], $box[3]) - min($box[5], $box[7]);
        $x = ($tile - $w) / 2 - min($box[0], $box[6]);
        $y = ($tile - $h) / 2 - min($box[5], $box[7]);
        @imagettftext($im, $fontSize, 0, (int)round($x), (int)round($y), $color, $font, $ch);
        return $im;
    }

    /** 以给定中心点绘制水平居中文字 */
    private static function drawCentered($im, string $font, float $fontSize, int $cx, int $cy, string $text, $color): void
    {
        $box = @imagettfbbox($fontSize, 0, $font, $text);
        if (!is_array($box)) {
            return;
        }
        $w = max($box[2], $box[4]) - min($box[0], $box[6]);
        $h = max($box[1], $box[3]) - min($box[5], $box[7]);
        $x = $cx - $w / 2 - min($box[0], $box[6]);
        $y = $cy - $h / 2 - min($box[5], $box[7]);
        @imagettftext($im, $fontSize, 0, (int)round($x), (int)round($y), $color, $font, $text);
    }
}
