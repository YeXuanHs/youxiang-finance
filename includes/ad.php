<?php
/**
 * 优享财务系统 · 展示广告位（YxAd）
 *
 * 用途：在门户页面投放「优享财务系统」相关的展示素材。
 *   - 一个广告位（yx_ad_slot）= 一个投放区域：位置 position + 版式 layout
 *   - 广告位下的素材（yx_ad_item）按 sort 升序展示，前台只取 status = 1
 *   - layout = goods 时按「商品卡」渲染：参考商品列表页的分组页签 + 价格 + 购买按钮，
 *     group 由广告位承担（对应参考页 fpg_id），subgroup 由素材承担（对应 spg_id）
 *
 * 约定：素材价格仅用于展示，不参与下单；购买按钮指向站内的真实购买入口。
 */

class YxAd
{
    /** 投放位置：key => 中文名 */
    const POSITIONS = [
        'home'  => '首页',
        'goods' => '优享财务系统展示页',
    ];

    /** 版式：key => 中文名 */
    const LAYOUTS = [
        'banner' => '横幅广告',
        'goods'  => '商品卡',
    ];

    /** 计费周期：key => 中文名（forever 表示永久授权） */
    const CYCLES = [
        'forever'  => '永久',
        'month'    => '月付',
        'quarter'  => '季付',
        'semiyear' => '半年付',
        'year'     => '年付',
    ];

    public static function isKnownPosition(string $key): bool
    {
        return isset(self::POSITIONS[$key]);
    }

    public static function isKnownLayout(string $key): bool
    {
        return isset(self::LAYOUTS[$key]);
    }

    public static function positionName(string $key): string
    {
        return self::POSITIONS[$key] ?? $key;
    }

    public static function layoutName(string $key): string
    {
        return self::LAYOUTS[$key] ?? $key;
    }

    public static function cycleName(string $key): string
    {
        return self::CYCLES[$key] ?? $key;
    }

    /** 图片路径清洗与 URL 生成沿用横幅的规则，避免两处实现漂移 */
    public static function cleanImage(string $path): string
    {
        return YxBanner::cleanImage($path);
    }

    public static function imageUrl(array $row): string
    {
        $img = self::cleanImage((string)($row['image'] ?? ''));
        return $img === '' ? '' : yx_upload_url($img);
    }

    public static function href(string $url): string
    {
        return YxBanner::href($url);
    }

    public static function removeImageFile(string $image): void
    {
        YxBanner::removeImageFile($image);
    }

    /** 价格展示文案：0 元在商品卡上显示「免费」，横幅不展示价格 */
    public static function priceLabel(array $item): string
    {
        $price = (float)($item['price'] ?? 0);
        if ($price <= 0) {
            return '免费';
        }
        return money($price, true);
    }

    public static function cycleLabel(array $item): string
    {
        $cycle = trim((string)($item['cycle'] ?? 'forever'));
        // 「365d」这类写法表示按天数计费，直接显示真实天数，避免与购买页口径不一致
        if (preg_match('/^(\d+)d$/', $cycle, $m)) {
            return (int)$m[1] . ' 天';
        }
        return self::cycleName($cycle);
    }

    /** 版本授权的有效期设置 → 素材计费周期：0 天为永久，其余按真实天数记录 */
    public static function cycleFromDays(int $days): string
    {
        return $days > 0 ? $days . 'd' : 'forever';
    }

    /* ------------------------------------------------------------ 广告位 */

    public static function allSlots(): array
    {
        try {
            return YxDb::all('SELECT * FROM ' . YxDb::table('ad_slot') . ' ORDER BY sort ASC, id ASC');
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 前台启用的广告位，可按位置过滤 */
    public static function activeSlots(string $position = ''): array
    {
        try {
            if ($position !== '') {
                return YxDb::all(
                    'SELECT * FROM ' . YxDb::table('ad_slot') . ' WHERE status = 1 AND position = ? ORDER BY sort ASC, id ASC',
                    [$position]
                );
            }
            return YxDb::all('SELECT * FROM ' . YxDb::table('ad_slot') . ' WHERE status = 1 ORDER BY sort ASC, id ASC');
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function findSlot(int $id)
    {
        if ($id <= 0) {
            return null;
        }
        try {
            return YxDb::one('SELECT * FROM ' . YxDb::table('ad_slot') . ' WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------ 素材 */

    /** 指定广告位的素材；$onlyActive 为 false 时含停用项（后台使用） */
    public static function items(int $slotId, bool $onlyActive = true): array
    {
        if ($slotId <= 0) {
            return [];
        }
        $sql = 'SELECT * FROM ' . YxDb::table('ad_item') . ' WHERE slot_id = ?';
        if ($onlyActive) {
            $sql .= ' AND status = 1';
        }
        $sql .= ' ORDER BY sort ASC, id ASC';
        try {
            return YxDb::all($sql, [$slotId]);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 按位置（可再按版式）取全部启用素材，附带所属广告位信息 */
    public static function activeItems(string $position, string $layout = ''): array
    {
        $sql = 'SELECT i.*, s.name AS slot_name, s.position AS slot_position, s.layout AS slot_layout, s.subtitle AS slot_subtitle'
            . ' FROM ' . YxDb::table('ad_item') . ' i'
            . ' INNER JOIN ' . YxDb::table('ad_slot') . ' s ON s.id = i.slot_id'
            . ' WHERE i.status = 1 AND s.status = 1 AND s.position = ?';
        $params = [$position];
        if ($layout !== '') {
            $sql .= ' AND s.layout = ?';
            $params[] = $layout;
        }
        $sql .= ' ORDER BY s.sort ASC, s.id ASC, i.sort ASC, i.id ASC';
        try {
            return YxDb::all($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function findItem(int $id)
    {
        if ($id <= 0) {
            return null;
        }
        try {
            return YxDb::one('SELECT * FROM ' . YxDb::table('ad_item') . ' WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------ 渲染 */

    /**
     * 渲染指定位置的横幅广告（layout = banner）
     * 没有启用素材时返回空串，调用方直接不输出该区块。
     */
    public static function renderBanners(string $position): string
    {
        $items = self::activeItems($position, 'banner');
        if (!$items) {
            return '';
        }

        $html = '<div class="yx-ad-banner-grid">';
        foreach ($items as $item) {
            $img   = self::imageUrl($item);
            $bgCss = $img !== '' ? "url('" . e($img) . "')" : YxBanner::paletteCss((string)($item['bg'] ?? 'blue'));
            $title = trim((string)($item['title'] ?? ''));
            $sub   = trim((string)($item['subtitle'] ?? ''));
            $tag   = trim((string)($item['tag'] ?? ''));
            $href  = self::href((string)($item['btn_url'] ?? ''));
            $btn   = trim((string)($item['btn_text'] ?? ''));

            $html .= '<div class="yx-ad-banner" style="background-image:' . e($bgCss) . '">';
            $html .= '<div class="yx-ad-banner-body">';
            if ($tag !== '') {
                $html .= '<span class="yx-ad-tag">' . e($tag) . '</span>';
            }
            if ($title !== '') {
                $html .= '<h4>' . e($title) . '</h4>';
            }
            if ($sub !== '') {
                $html .= '<p>' . e($sub) . '</p>';
            }
            if ($btn !== '' && $href !== '') {
                $html .= '<a class="btn zd-btn-light btn-sm" href="' . e($href) . '">' . e($btn) . '</a>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 写入默认广告位与素材（幂等：已有广告位时跳过，不覆盖人工修改）
     * 价格取自「授权版本」的实际售价，避免展示价与购买页不一致。
     */
    public static function seedDefaults(): void
    {
        try {
            if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ad_slot')) > 0) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }

        $editionPrice = static function (string $key, float $fallback): float {
            $v = setting('edition_price_' . $key, '');
            return $v === '' || $v === null ? $fallback : (float)$v;
        };

        // 1) 展示页 · 授权版本（商品卡版式）
        $slotId = YxDb::insert('ad_slot', [
            'name'       => '优享财务系统 · 授权版本',
            'position'   => 'goods',
            'layout'     => 'goods',
            'subtitle'   => '按域名签发正版授权，购买后即可下载官方程序包并自助升级',
            'sort'       => 10,
            'status'     => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [
            [
                'basic' => [
                    'title'    => '普惠版',
                    'subtitle' => '覆盖客户、产品、订单与基础财务记账，起步即用',
                    'tag'      => YxEdition::info('basic')['tag'],
                    'subgroup' => '按版本授权',
                    'sort'     => 10,
                ],
            ],
            [
                'pro' => [
                    'title'    => '专业版',
                    'subtitle' => '补齐工单、自动续费、优惠券与渠道分销，增长首选',
                    'tag'      => YxEdition::info('pro')['tag'],
                    'subgroup' => '按版本授权',
                    'sort'     => 20,
                ],
            ],
            [
                'enterprise' => [
                    'title'    => '企业版',
                    'subtitle' => '多节点资源、分站、开放接口与经营分析，规模化运营',
                    'tag'      => YxEdition::info('enterprise')['tag'],
                    'subgroup' => '按版本授权',
                    'sort'     => 30,
                ],
            ],
        ];
        foreach ($rows as $row) {
            foreach ($row as $key => $r) {
                YxDb::insert('ad_item', [
                    'slot_id'    => $slotId,
                    'title'      => $r['title'] . '授权',
                    'subtitle'   => $r['subtitle'],
                    'tag'        => $r['tag'],
                    'subgroup'   => $r['subgroup'],
                    'price'      => $editionPrice($key, (float)['basic' => 10, 'pro' => 1600, 'enterprise' => 3600][$key]),
                    'cycle'      => self::cycleFromDays((int)setting('edition_days_' . $key, 0)),
                    'image'      => '',
                    'bg'         => $key === 'basic' ? 'green' : ($key === 'pro' ? 'blue' : 'purple'),
                    'btn_text'   => '立即购买',
                    'btn_url'    => 'panel/index.php?m=edition',
                    'sort'       => $r['sort'],
                    'status'     => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2) 首页 · 展示页入口（横幅版式）
        $homeSlot = YxDb::insert('ad_slot', [
            'name'       => '首页 · 优享财务系统推广位',
            'position'   => 'home',
            'layout'     => 'banner',
            'subtitle'   => '',
            'sort'       => 10,
            'status'     => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        YxDb::insert('ad_item', [
            'slot_id'    => $homeSlot,
            'title'      => '优享财务系统 · 正版授权可查',
            'subtitle'   => '业务与财务一套系统跑通：产品、订单、客户、账单、工单与经营分析，按域名签发正版授权。',
            'tag'        => '官方授权',
            'subgroup'   => '',
            'price'      => 0,
            'cycle'      => 'forever',
            'image'      => '',
            'bg'         => 'blue',
            'btn_text'   => '查看授权版本',
            'btn_url'    => 'goods.php',
            'sort'       => 10,
            'status'     => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
