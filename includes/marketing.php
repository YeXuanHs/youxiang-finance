<?php
/**
 * 优享财务系统 · 售卖品主图 / 产品详情页图片制作
 *
 * 用途：
 *   - 为展示页（goods.php）里的售卖品生成「商品主图」与「产品详情页」长图，
 *     用于淘宝 / 闲鱼 / 朋友圈等外部渠道发布，封面、价格、功能清单一处维护。
 *   - 同时作为后台「主图制作」设计器的预览与下载出口（admin/marketing.php）。
 *
 * 设计约定：
 *   - 图片全部由 SVG 生成（服务器无中文字体也能正确渲染，不依赖 GD / 字体文件）；
 *     下载时以 image/svg+xml 输出，支持浏览器直接「另存为图片」或在设计器中导出 PNG。
 *   - 只有管理员能保存 / 删除素材；前台只读取「启用」的素材，且不暴露任何写入入口。
 *   - 文案、配色、版式全部走白名单校验，避免把后台输入直接拼进 SVG。
 */

class YxMarketing
{
    /** 画布规格：kind => [宽, 高]（主图 1:1，详情页为竖版长图） */
    const SIZES = [
        'main'   => [800, 800],
        'detail' => [750, 1200],
    ];

    const KIND_NAMES = [
        'main'   => '商品主图',
        'detail' => '产品详情页',
    ];

    /** 静态文件目录（相对 uploads/） */
    const SUBDIR = 'marketing';

    /** 渐变底色：key => [中文名, [起始色, 中间色, 结束色]] */
    const PALETTES = [
        'blue'   => ['蓝色', ['#16215c', '#3b5bfd', '#7c9bff']],
        'green'  => ['青绿', ['#0b3527', '#0F6E56', '#14B8A6']],
        'purple' => ['紫色', ['#3b1a78', '#7048e8', '#a78bfa']],
        'dark'   => ['深灰', ['#0f172a', '#334155', '#64748b']],
        'orange' => ['橙色', ['#7c2d12', '#ea580c', '#fb923c']],
        'rose'   => ['玫红', ['#4c0519', '#be123c', '#fb7185']],
    ];

    /** 版式：main 两种小版式，detail 为竖版长图 */
    const LAYOUTS = [
        'hero'  => '大标题',
        'price' => '价格突出',
        'poster' => '详情长图',
    ];

    /* ------------------------------------------------------------ 基础 */

    public static function kindName(string $kind): string
    {
        return self::KIND_NAMES[$kind] ?? '商品图片';
    }

    public static function isKnownKind(string $kind): bool
    {
        return isset(self::SIZES[$kind]);
    }

    public static function isKnownPalette(string $key): bool
    {
        return isset(self::PALETTES[$key]);
    }

    /** 主图版式跟随文件类型：detail 固定为 poster */
    public static function resolveLayout(string $kind, string $layout): string
    {
        if ($kind === 'detail') {
            return 'poster';
        }
        return in_array($layout, ['hero', 'price'], true) ? $layout : 'hero';
    }

    public static function size(string $kind): array
    {
        return self::SIZES[$kind] ?? self::SIZES['main'];
    }

    /* ------------------------------------------------------------ 落盘与地址 */

    /** 图片在 uploads 下的相对路径（供商品卡 / 外部渠道直接引用） */
    public static function fileRel(int $id): string
    {
        return 'marketing/' . $id . '.svg';
    }

    /** 公网可访问地址：/uploads/marketing/12.svg */
    public static function fileUrl(int $id): string
    {
        return $id > 0 ? yx_upload_url(self::fileRel($id)) : '';
    }

    /**
     * 前台取图地址：静态文件存在时返回 /uploads/marketing/<id>.svg（可被浏览器与 CDN 缓存）。
     * 文件缺失时返回空串，调用方应回退为内联 SVG ——
     * 绝不能回退到 admin/marketing.php?raw=（那是管理员出口，访客会被跳去登录页）。
     */
    public static function publicUrl(array $row): string
    {
        $id = (int)($row['id'] ?? 0);
        return self::fileExists($id) ? self::fileUrl($id) : '';
    }

    /**
     * 把渲染结果写成静态文件，便于前台商品卡与第三方平台直接引用
     * 失败不影响数据库记录（预览接口 ?raw= 仍可输出）
     */
    public static function writeFile(array $row): bool
    {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        $dir = yx_upload_dir(self::SUBDIR);
        if (!is_dir($dir)) {
            return false;
        }
        $dest = $dir . '/' . $id . '.svg';
        $ok   = @file_put_contents($dest, self::svg($row)) !== false;
        if ($ok) {
            @chmod($dest, 0644);
        }
        return $ok;
    }

    /** 静态文件是否已生成 */
    public static function fileExists(int $id): bool
    {
        return $id > 0 && is_file(yx_upload_dir(self::SUBDIR) . '/' . $id . '.svg');
    }

    /** 删除记录时同步清理静态文件 */
    public static function removeFile(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $file = yx_upload_dir(self::SUBDIR) . '/' . $id . '.svg';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /* ------------------------------------------------------------ 数据访问 */

    public static function all(): array
    {
        try {
            return YxDb::all('SELECT * FROM ' . YxDb::table('marketing_image') . ' ORDER BY kind ASC, sort ASC, id ASC');
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function find(int $id)
    {
        if ($id <= 0) {
            return null;
        }
        try {
            return YxDb::one('SELECT * FROM ' . YxDb::table('marketing_image') . ' WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 按文件类型取素材；前台只取启用项 */
    public static function byKind(string $kind, bool $onlyActive = true): array
    {
        $out = [];
        foreach (self::all() as $row) {
            if ((string)$row['kind'] !== $kind) {
                continue;
            }
            if ($onlyActive && (int)$row['status'] !== 1) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 从展示页素材生成「预填数据」，减少手工录入
     * 价格与计费周期沿用 YxAd 的口径，确保与前台商品卡一致。
     */
    public static function draftFromItem(array $item): array
    {
        $title  = trim((string)($item['title'] ?? ''));
        $sub    = trim((string)($item['subtitle'] ?? ''));
        $tag    = trim((string)($item['tag'] ?? ''));
        $price  = YxAd::priceLabel($item);
        $cycle  = YxAd::cycleLabel($item);
        $purple = ((float)($item['price'] ?? 0)) >= 3000;

        return [
            'title'       => $title !== '' ? $title : '优享财务系统',
            'subtitle'    => $sub !== '' ? $sub : '业务与财务一体化管理平台',
            'tag'         => $tag,
            'price'       => $price === '免费' ? '0' : (string)(float)($item['price'] ?? 0),
            'price_note'  => $price === '免费' ? '免费使用' : ('/' . $cycle),
            'features'    => implode("\n", [
                '订单 · 业务 · 收支一体化记账',
                '多节点资源与自动开通',
                '工单协同与代理分销',
                '域名授权可查，正版可升级',
            ]),
            'cta'         => '扫码立即咨询',
            'bg'          => $purple ? 'purple' : 'blue',
        ];
    }

    /* ------------------------------------------------------------ 渲染 */

    /** 渐变 defs 的 id 带上 id，避免同页多张图共用 id 时串色 */
    private static function gradId(array $row): string
    {
        return 'yxmg' . (int)($row['id'] ?? 0);
    }

    /** 简单的 SVG 文本转义（内容为纯文本，禁止任何标签注入） */
    private static function t($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 按字符估算宽度截断，超长加省略号；中文按 1 个字符宽计 */
    private static function clip(string $text, int $maxChars): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, max(1, $maxChars - 1), 'UTF-8') . '…';
    }

    /** 功能清单：每行以 · / - / * 开头的都视为一项，最多 6 项 */
    private static function features(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\r\n]+/u', $raw) as $line) {
            $line = trim((string)preg_replace('/^\s*[·•\-*\x{2022}]\s*/u', '', $line));
            $line = self::clip($line, 30);
            if ($line !== '') {
                $out[] = $line;
            }
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    /** 价格文案：数字按金额格式化，其它（如「面议」）原样显示 */
    private static function priceText(array $row): string
    {
        $raw = trim((string)($row['price'] ?? ''));
        if ($raw === '') {
            return '';
        }
        $num = str_replace([',', '，', ' '], '', $raw);
        if (preg_match('/^\d+(\.\d+)?$/', $num)) {
            $val = (float)$num;
            return $val <= 0 ? '免费' : money($val, true);
        }
        return self::clip($raw, 12);
    }

    /** 生成 SVG 字符串 */
    public static function svg(array $row): string
    {
        $kind   = self::isKnownKind((string)($row['kind'] ?? '')) ? (string)$row['kind'] : 'main';
        $layout = self::resolveLayout($kind, (string)($row['layout'] ?? 'hero'));
        [$w, $h] = self::size($kind);

        $bg     = (string)($row['bg'] ?? 'blue');
        $palette = self::isKnownPalette($bg) ? $bg : 'blue';
        [$c1, $c2, $c3] = self::PALETTES[$palette][1];
        $gid  = self::gradId($row);
        $font = "-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei','Noto Sans CJK SC','Source Han Sans SC',sans-serif";

        $title = self::clip((string)($row['title'] ?? ''), 24);
        $sub   = self::clip((string)($row['subtitle'] ?? ''), $kind === 'main' ? 36 : 46);
        $tag   = self::clip((string)($row['tag'] ?? ''), 12);
        $price = self::priceText($row);
        $note  = self::clip((string)($row['price_note'] ?? ''), 16);
        $cta   = self::clip((string)($row['cta'] ?? ''), 18);
        $feats = self::features((string)($row['features'] ?? ''));
        $brand = self::clip((string)($row['brand'] ?? ''), 24);
        if ($brand === '') {
            $brand = (string)setting('site_name', config('app.name'));
            $brand = self::clip($brand, 24);
        }

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
        $svg .= '<defs>'
            . '<linearGradient id="' . $gid . '" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="' . $c1 . '"/><stop offset=".58" stop-color="' . $c2 . '"/>'
            . '<stop offset="1" stop-color="' . $c3 . '"/></linearGradient>'
            . '<linearGradient id="' . $gid . 's" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="#09101f" stop-opacity=".0"/><stop offset="1" stop-color="#09101f" stop-opacity=".55"/>'
            . '</linearGradient>'
            . '</defs>';
        $svg .= '<rect width="' . $w . '" height="' . $h . '" fill="url(#' . $gid . ')"/>';

        // 装饰：右下角同心圆 + 网格点，避免纯色大块
        $svg .= '<g opacity=".14" fill="none" stroke="#fff">'
            . '<circle cx="' . $w . '" cy="0" r="' . (int)($w * 0.62) . '" stroke-width="2"/>'
            . '<circle cx="' . $w . '" cy="0" r="' . (int)($w * 0.44) . '" stroke-width="2"/>'
            . '<circle cx="' . $w . '" cy="0" r="' . (int)($w * 0.26) . '" stroke-width="2"/>'
            . '</g>';
        $svg .= '<rect width="' . $w . '" height="' . $h . '" fill="url(#' . $gid . 's)" opacity=".55"/>';

        $svg .= $layout === 'poster'
            ? self::posterBody($row, $w, $h, $font, $title, $sub, $tag, $price, $note, $cta, $feats, $brand)
            : self::mainBody($layout, $w, $h, $font, $title, $sub, $tag, $price, $note, $cta, $brand);

        $svg .= '</svg>';
        return $svg;
    }

    /** 1:1 主图版式 */
    private static function mainBody(string $layout, int $w, int $h, string $font, string $title, string $sub, string $tag, string $price, string $note, string $cta, string $brand): string
    {
        $pad = 64;
        $s   = '';
        // 顶部品牌条
        $s .= '<text x="' . $pad . '" y="' . ($pad + 4) . '" font-family="' . $font . '" font-size="22" fill="#fff" fill-opacity=".82" letter-spacing="1">'
            . self::t($brand) . '</text>';
        if ($tag !== '') {
            $tw = mb_strlen($tag, 'UTF-8') * 22 + 44;
            $s .= '<rect x="' . ($w - $pad - $tw) . '" y="44" width="' . $tw . '" height="42" rx="21" fill="#fff" fill-opacity=".22"/>';
            $s .= '<text x="' . ($w - $pad - $tw / 2) . '" y="72" text-anchor="middle" font-family="' . $font . '" font-size="20" fill="#fff">'
                . self::t($tag) . '</text>';
        }

        if ($layout === 'price') {
            $s .= '<text x="' . $pad . '" y="330" font-family="' . $font . '" font-size="64" font-weight="700" fill="#fff">'
                . self::t($title) . '</text>';
            if ($sub !== '') {
                $s .= '<text x="' . $pad . '" y="392" font-family="' . $font . '" font-size="26" fill="#fff" fill-opacity=".86">'
                    . self::t($sub) . '</text>';
            }
            $s .= '<text x="' . $pad . '" y="560" font-family="' . $font . '" font-size="110" font-weight="800" fill="#ffe08a">'
                . self::t($price) . '</text>';
            if ($note !== '') {
                $s .= '<text x="' . $pad . '" y="612" font-family="' . $font . '" font-size="24" fill="#fff" fill-opacity=".86">'
                    . self::t($note) . '</text>';
            }
        } else {
            $s .= '<text x="' . $pad . '" y="300" font-family="' . $font . '" font-size="72" font-weight="800" fill="#fff">'
                . self::t($title) . '</text>';
            if ($sub !== '') {
                $s .= '<text x="' . $pad . '" y="356" font-family="' . $font . '" font-size="26" fill="#fff" fill-opacity=".86">'
                    . self::t($sub) . '</text>';
            }
            if ($price !== '') {
                $s .= '<text x="' . $pad . '" y="470" font-family="' . $font . '" font-size="68" font-weight="800" fill="#ffe08a">'
                    . self::t($price) . '</text>';
                if ($note !== '') {
                    $s .= '<text x="' . $pad . '" y="512" font-family="' . $font . '" font-size="22" fill="#fff" fill-opacity=".86">'
                        . self::t($note) . '</text>';
                }
            }
        }

        if ($cta !== '') {
            $cw = mb_strlen($cta, 'UTF-8') * 24 + 96;
            $s .= '<rect x="' . $pad . '" y="' . ($h - $pad - 78) . '" width="' . $cw . '" height="64" rx="32" fill="#fff"/>';
            $s .= '<text x="' . ($pad + $cw / 2) . '" y="' . ($h - $pad - 36) . '" text-anchor="middle" font-family="' . $font . '" font-size="24" font-weight="600" fill="#16215c">'
                . self::t($cta) . '</text>';
        }
        return $s;
    }

    /** 竖版详情长图：标题区 + 功能清单卡 + 价格 / 行动区 */
    private static function posterBody(array $row, int $w, int $h, string $font, string $title, string $sub, string $tag, string $price, string $note, string $cta, array $feats, string $brand): string
    {
        $pad = 56;
        $s   = '';
        $s .= '<text x="' . $pad . '" y="' . ($pad + 8) . '" font-family="' . $font . '" font-size="22" fill="#fff" fill-opacity=".82" letter-spacing="1">'
            . self::t($brand) . '</text>';
        if ($tag !== '') {
            $tw = mb_strlen($tag, 'UTF-8') * 22 + 44;
            $s .= '<rect x="' . ($w - $pad - $tw) . '" y="' . ($pad - 22) . '" width="' . $tw . '" height="42" rx="21" fill="#fff" fill-opacity=".22"/>';
            $s .= '<text x="' . ($w - $pad - $tw / 2) . '" y="' . ($pad + 6) . '" text-anchor="middle" font-family="' . $font . '" font-size="20" fill="#fff">'
                . self::t($tag) . '</text>';
        }

        $y = 240;
        $s .= '<text x="' . $pad . '" y="' . $y . '" font-family="' . $font . '" font-size="64" font-weight="800" fill="#fff">'
            . self::t($title) . '</text>';
        if ($sub !== '') {
            $y += 56;
            $s .= '<text x="' . $pad . '" y="' . $y . '" font-family="' . $font . '" font-size="26" fill="#fff" fill-opacity=".86">'
                . self::t($sub) . '</text>';
        }

        // 功能清单卡
        $cardX = 40;
        $cardW = $w - 80;
        $cardY = $y + 60;
        $lineH = 62;
        $cardH = 108 + max(1, count($feats)) * $lineH;
        $s .= '<rect x="' . $cardX . '" y="' . $cardY . '" width="' . $cardW . '" height="' . $cardH . '" rx="28" fill="#fff" fill-opacity=".96"/>';
        $s .= '<text x="' . ($cardX + 36) . '" y="' . ($cardY + 62) . '" font-family="' . $font . '" font-size="26" font-weight="700" fill="#101828">核心能力</text>';
        $fy = $cardY + 62;
        foreach ($feats as $i => $f) {
            $fy += $lineH;
            $s .= '<circle cx="' . ($cardX + 44) . '" cy="' . ($fy - 9) . '" r="9" fill="#3b5bfd"/>';
            $s .= '<text x="' . ($cardX + 70) . '" y="' . $fy . '" font-family="' . $font . '" font-size="26" fill="#344054">'
                . self::t($f) . '</text>';
            if ($i < count($feats) - 1) {
                $s .= '<line x1="' . ($cardX + 36) . '" y1="' . ($fy + 18) . '" x2="' . ($cardX + $cardW - 36) . '" y2="' . ($fy + 18) . '" stroke="#e9edf5" stroke-width="1"/>';
            }
        }

        // 底部价格 / 行动区
        $by = $cardY + $cardH + 110;
        if ($by > $h - 160) {
            $by = $h - 160;
        }
        if ($price !== '') {
            $s .= '<text x="' . $pad . '" y="' . $by . '" font-family="' . $font . '" font-size="88" font-weight="800" fill="#ffe08a">'
                . self::t($price) . '</text>';
            if ($note !== '') {
                $s .= '<text x="' . ($pad + 12) . '" y="' . ($by + 44) . '" font-family="' . $font . '" font-size="22" fill="#fff" fill-opacity=".86">'
                    . self::t($note) . '</text>';
            }
        }
        if ($cta !== '') {
            $cw = mb_strlen($cta, 'UTF-8') * 24 + 96;
            $s .= '<rect x="' . ($w - $pad - $cw) . '" y="' . ($h - $pad - 96) . '" width="' . $cw . '" height="64" rx="32" fill="#fff"/>';
            $s .= '<text x="' . ($w - $pad - $cw / 2) . '" y="' . ($h - $pad - 54) . '" text-anchor="middle" font-family="' . $font . '" font-size="24" font-weight="600" fill="#16215c">'
                . self::t($cta) . '</text>';
        }
        return $s;
    }
}
