<?php
/**
 * 优享财务系统 · 首页横幅（Banner）内容管理
 *
 * 设计约定：
 *   - 横幅存 yx_banner，一条记录 = 一张幻灯（图片或渐变底 + 文案 + 最多两个按钮）
 *   - 前台只展示 status = 1 的记录，按 sort 升序、id 升序
 *   - 图片仅接受 uploads/ 下的相对路径，拒绝协议、绝对路径与目录穿越
 *   - 后台可上传 / 更换 / 删除图片；未设置图片时使用预设渐变底色
 */

class YxBanner
{
    /** 预设渐变底色：key => [中文名, CSS]（只允许白名单值，避免后台写入任意 CSS） */
    const PALETTES = [
        'blue'   => ['蓝色', 'linear-gradient(120deg,#16215c 0%,#3b5bfd 58%,#7c9bff 100%)'],
        'green'  => ['青绿', 'linear-gradient(120deg,#0b3527 0%,#0F6E56 58%,#14B8A6 100%)'],
        'purple' => ['紫色', 'linear-gradient(120deg,#3b1a78 0%,#7048e8 58%,#a78bfa 100%)'],
        'dark'   => ['深灰', 'linear-gradient(120deg,#0f172a 0%,#334155 58%,#64748b 100%)'],
        'orange' => ['橙色', 'linear-gradient(120deg,#7c2d12 0%,#ea580c 58%,#fb923c 100%)'],
        'rose'   => ['玫红', 'linear-gradient(120deg,#4c0519 0%,#be123c 58%,#fb7185 100%)'],
    ];

    public static function isKnownPalette(string $key): bool
    {
        return isset(self::PALETTES[$key]);
    }

    public static function paletteCss(string $key): string
    {
        return (self::PALETTES[$key] ?? self::PALETTES['blue'])[1];
    }

    public static function paletteName(string $key): string
    {
        return (self::PALETTES[$key] ?? self::PALETTES['blue'])[0];
    }

    /**
     * 清洗图片相对路径（相对 uploads/）。非法路径返回空串。
     */
    public static function cleanImage(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strpos($path, '..') !== false || strpos($path, '\\') !== false) {
            return '';
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $path)) {
            return '';
        }
        return ltrim($path, '/');
    }

    /** 横幅图片可访问 URL；未设置或路径非法时返回空串 */
    public static function imageUrl(array $row): string
    {
        $img = self::cleanImage((string)($row['image'] ?? ''));
        return $img === '' ? '' : yx_upload_url($img);
    }

    /** 删除横幅图片文件（仅限 uploads/ 目录内，避免误删其它文件） */
    public static function removeImageFile(string $image): void
    {
        $rel = self::cleanImage($image);
        if ($rel === '') {
            return;
        }
        $base = realpath(YX_ROOT . '/uploads');
        $real = realpath(YX_ROOT . '/uploads/' . $rel);
        if ($base !== false && $real !== false && is_file($real)
            && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
            @unlink($real);
        }
    }

    /** 安全跳转地址：仅允许 http(s) 外链、站内相对路径与页内锚点 */
    public static function href(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return ''; // javascript: / data: 等一律拒绝
        }
        if (strpos($url, '//') === 0) {
            return '';
        }
        if ($url[0] === '#') {
            return $url;
        }
        return url($url);
    }

    /**
     * 归一化按钮列表：仅返回「有文字且有可用链接」的按钮
     * @return array<int,array{text:string,href:string}>
     */
    public static function buttons(array $row): array
    {
        $out = [];
        foreach ([1, 2] as $n) {
            $text = trim((string)($row['btn' . $n . '_text'] ?? ''));
            $href = self::href((string)($row['btn' . $n . '_url'] ?? ''));
            if ($text !== '' && $href !== '') {
                $out[] = ['text' => $text, 'href' => $href];
            }
        }
        return $out;
    }

    /** 管理端全部横幅 */
    public static function all(): array
    {
        try {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('banner') . ' ORDER BY sort ASC, id ASC'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 前台展示的横幅（已启用） */
    public static function active(): array
    {
        try {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('banner') . ' WHERE status = 1 ORDER BY sort ASC, id ASC'
            );
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
            return YxDb::one('SELECT * FROM ' . YxDb::table('banner') . ' WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 兜底横幅：表为空或全部停用时保持首页版式完整 */
    public static function fallback(): array
    {
        return [[
            'id'        => 0,
            'tag'       => YxEdition::name() . ' · 正版授权可查',
            'title'     => '业务与财务，一套系统跑通',
            'subtitle'  => setting('site_sub', '业务与财务一体化管理平台')
                . '：产品目录、订单、客户、账单、工单、续费与经营分析，支持普惠版 / 专业版 / 企业版按需启用。',
            'image'     => '',
            'bg'        => 'blue',
            'btn1_text' => '查看产品服务',
            'btn1_url'  => '#products',
            'btn2_text' => '进入应用商店',
            'btn2_url'  => 'store/index.php',
        ]];
    }

    /**
     * 写入默认横幅（幂等：表中已有数据时跳过，不覆盖人工修改）
     * 默认内容与改造前首页硬编码的三张幻灯保持一致，升级后视觉不变。
     */
    public static function seedDefaults(): void
    {
        try {
            if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('banner')) > 0) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }

        $rows = [
            [
                'tag'       => YxEdition::name() . ' · 正版授权可查',
                'title'     => '业务与财务，一套系统跑通',
                'subtitle'  => setting('site_sub', '业务与财务一体化管理平台')
                    . '：产品目录、订单、客户、账单、工单、续费与经营分析，支持普惠版 / 专业版 / 企业版按需启用。',
                'bg'        => 'blue',
                'btn1_text' => '查看产品服务',
                'btn1_url'  => '#products',
                'btn2_text' => '进入应用商店',
                'btn2_url'  => 'store/index.php',
            ],
            [
                'tag'       => '官方授权 · 按域名签发',
                'title'     => '域名授权，正版可查',
                'subtitle'  => '授权以域名为单位签发，命中官方授权库即提示「域名已正版授权」。输入域名即可在线查验，开发者也可通过开放接口查询。',
                'bg'        => 'green',
                'btn1_text' => '立即查询授权',
                'btn1_url'  => '#license',
            ],
            [
                'tag'       => '应用商店 · 装完即用',
                'title'     => '支付、实名、云对接，一站装齐',
                'subtitle'  => '支付通道、实名认证、云服务对接、系统增强与运维工具，按域名签发授权，购买后一键安装。',
                'bg'        => 'purple',
                'btn1_text' => '逛逛应用商店',
                'btn1_url'  => 'store/index.php',
            ],
        ];

        $sort = 10;
        foreach ($rows as $r) {
            YxDb::insert('banner', [
                'tag'        => $r['tag'],
                'title'      => $r['title'],
                'subtitle'   => $r['subtitle'],
                'image'      => '',
                'bg'         => $r['bg'],
                'btn1_text'  => $r['btn1_text'] ?? '',
                'btn1_url'   => $r['btn1_url'] ?? '',
                'btn2_text'  => $r['btn2_text'] ?? '',
                'btn2_url'   => $r['btn2_url'] ?? '',
                'sort'       => $sort,
                'status'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sort += 10;
        }
    }
}
