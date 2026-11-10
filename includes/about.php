<?php
/**
 * 优享财务系统 · 「关于我们」内容管理
 *
 * 设计原则：
 *   - 标量字段存 yx_setting（前缀 about_），可重复条目存 yx_about_item（按 section 分组）
 *   - 前台只渲染「已填写」的内容，空字段与空分组自动跳过，避免出现半成品页面
 *   - 涉及公司事实（成立时间、客户数量、合作伙伴名单等）默认**留空**，由后台按实际情况填写，
 *     不预置任何虚构数据
 */

class YxAbout
{
    /** 标量字段：key => [标签, 类型, 说明] */
    const SCALARS = [
        'about_page_title'  => ['页面标题', 'text', '浏览器标题与页面主标题'],
        'about_slogan'      => ['一句话定位', 'text', '显示在页面顶部的大标题副文案'],
        'about_intro'       => ['公司简介', 'textarea', '支持多段，空行分段'],
        'about_who_title'   => ['「我们是谁」标题', 'text', ''],
        'about_who_text'    => ['「我们是谁」正文', 'textarea', '支持多段'],
        'about_brand_text'  => ['品牌释义', 'textarea', '例如产品名/品牌名的含义'],
        'about_company'     => ['公司全称', 'text', '留空则显示系统名称'],
        'about_join_title'  => ['「加入我们」标题', 'text', '留空则整个板块不显示'],
        'about_join_text'   => ['「加入我们」正文', 'textarea', ''],
        'about_join_link'   => ['「加入我们」链接', 'text', '招聘页或简历投递地址，可留空'],
        'about_contact_phone'   => ['联系电话', 'text', ''],
        'about_contact_email'   => ['联系邮箱', 'text', ''],
        'about_contact_qq'      => ['客服 QQ', 'text', ''],
        'about_contact_wechat'  => ['微信 / 公众号', 'text', ''],
        'about_contact_addr'    => ['公司地址', 'text', ''],
        'about_contact_hours'   => ['服务时间', 'text', '例如 7×24 小时'],
        'about_footer_note'     => ['页脚补充说明', 'text', '可留空'],
    ];

    /** 可重复条目分组：section => [名称, 字段列表, 填写提示] */
    const SECTIONS = [
        'capability' => ['核心能力', ['icon', 'title', 'content'], '建议 3-6 条。显示为卡片宫格；图标可填一个字符或 emoji。'],
        'stat'       => ['实力数据', ['title', 'subtitle'], '建议 2-4 条。标题填数值（如「10 年+」「5000+」），副标题填说明。留空则整个板块不显示。'],
        'value'      => ['我们的理念', ['title', 'content'], '建议 3-4 条。'],
        'milestone'  => ['发展历程', ['subtitle', 'title', 'content'], '按时间正序。副标题填年份（如「2021」），标题填节点名，正文填说明。'],
        'partner'    => ['合作伙伴', ['title', 'link'], '标题填伙伴名称，链接填官网地址（可留空）。'],
        'review'     => ['客户评价', ['icon', 'title', 'subtitle', 'content', 'link'], '建议 3-9 条，展示已购买客户的真实评价。标题填客户 / 公司名；副标题填「评分|购买版本」，如「5|专业版」「4|普惠版」；正文填评价内容；图标填头像文字或 emoji；链接可留空。'],
        'benefit'    => ['团队福利', ['title', 'content'], '配合「加入我们」使用，可留空。'],
    ];

    /**
     * 写入默认内容（幂等：已存在的不覆盖）
     *
     * 只预置能确定的产品能力描述；公司事实类字段（成立时间、客户数量、合作伙伴、
     * 联系方式、招聘信息）一律留空，由运营方按实际情况填写，不预置任何虚构数据。
     */
    public static function seedDefaults(): void
    {
        $defaults = [
            'about_show'       => '1',
            'about_page_title' => '关于我们',
            'about_slogan'     => '让 IDC 业务与财务在同一套系统里跑通',
            'about_intro'      => "优享财务系统是一套面向 IDC、云服务与虚拟主机服务商搭建的业务与财务一体化管理系统，"
                . "把产品与套餐、订单与账单、客户与渠道、工单与续费、上游开通与下游开放接口串在同一条流程上。\n\n"
                . "系统提供普惠版、专业版、企业版三个版本档位，可按业务规模灵活启用；"
                . "上游对接、支付通道与应用商店均以可配置的方式扩展，便于随业务逐步演进。",
        ];
        foreach ($defaults as $k => $v) {
            if (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('setting') . ' WHERE k = ?', [$k]) == 0) {
                YxDb::insert('setting', ['k' => $k, 'v' => $v, 'updated_at' => now()]);
            }
        }

        if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('about_item')) > 0) {
            return;
        }

        $items = [
            ['capability', 10, '业', '业务与订单管理', '产品套餐、订单、账单、续费与工单在同一条流程里流转，业务状态随时可查。'],
            ['capability', 20, '财', '财务记账与对账', '余额、收支流水、账单、退款与经营分析，每笔资金变动全程留痕。'],
            ['capability', 30, '⇧', '上游自动开通', '对接资源供应商，客户付款后自动申请开通并回写实例编号、IP 与登录信息。'],
            ['capability', 40, '⇄', '下游开放接口', '以 AppID / 密钥开放下单、开通、续费与查询能力，支持签名校验与事件回调。'],
            ['value', 10, '', '从真实业务出发', '功能围绕下单、交付、收款、售后这条真实链路设计，不做用不上的功能。'],
            ['value', 20, '', '开放与可扩展', '上游、支付、商店均以配置或驱动方式扩展，不写死任何单一厂商。'],
            ['value', 30, '', '数据安全优先', '预处理语句、CSRF 校验、密钥加密存储，关键操作全程审计。'],
        ];
        foreach ($items as $it) {
            YxDb::insert('about_item', [
                'section'    => $it[0],
                'sort'       => $it[1],
                'icon'       => $it[2],
                'title'      => $it[3],
                'content'    => $it[4],
                'status'     => 1,
                'created_at' => now(),
            ]);
        }
    }

    /** 分组中文名
     * 注意：SECTIONS 的值是位置数组 [名称, 字段列表, 填写提示]，索引 0 为名称
     */
    public static function sectionName(string $section): string
    {
        return self::SECTIONS[$section][0] ?? $section;
    }

    public static function isKnownSection(string $section): bool
    {
        return isset(self::SECTIONS[$section]);
    }

    /** 读取标量字段，带默认值 */
    public static function get(string $key, string $default = ''): string
    {
        return (string)setting($key, $default);
    }

    /** 是否启用「关于我们」页面 */
    public static function enabled(): bool
    {
        return setting('about_show', '1') === '1';
    }

    /**
     * 取某分组下已启用的条目
     * @return array<int,array>
     */
    public static function items(string $section): array
    {
        if (!self::isKnownSection($section)) {
            return [];
        }
        try {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('about_item') . ' WHERE section = ? AND status = 1 ORDER BY sort ASC, id ASC',
                [$section]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 某分组在管理端可见的全部条目（含停用） */
    public static function adminItems(string $section): array
    {
        try {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('about_item') . ' WHERE section = ? ORDER BY sort ASC, id ASC',
                [$section]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 文本按空行拆段 */
    public static function paragraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return [];
        }
        $out = [];
        foreach (preg_split("/\n\s*\n/", $text) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * 解析客户评价条目：副标题约定为「评分|购买版本」，如「5|专业版」。
     * 兼容只填版本（无评分）或只填评分的情况，评分收敛到 1-5，缺省 5。
     *
     * @return array{rating:int,version:string}
     */
    public static function reviewMeta(array $item): array
    {
        $sub     = trim((string)($item['subtitle'] ?? ''));
        $rating  = 5;
        $version = $sub;
        if (strpos($sub, '|') !== false) {
            [$r, $v] = array_pad(explode('|', $sub, 2), 2, '');
            $version = trim($v);
            $r = trim($r);
            if ($r !== '' && is_numeric($r)) {
                $rating = (int)$r;
            }
        } elseif ($sub !== '' && is_numeric($sub)) {
            $rating  = (int)$sub;
            $version = '';
        }
        $rating = max(1, min(5, $rating));
        return ['rating' => $rating, 'version' => $version];
    }

    /** 是否有任何可展示内容（决定导航是否显示入口） */
    public static function hasContent(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        foreach (array_keys(self::SCALARS) as $k) {
            if ($k !== 'about_page_title' && trim(self::get($k)) !== '') {
                return true;
            }
        }
        foreach (array_keys(self::SECTIONS) as $s) {
            if (self::items($s)) {
                return true;
            }
        }
        return false;
    }

    /** 联系方式汇总（仅返回已填写的；未单独填写时回退到系统设置里的全局联系方式） */
    public static function contacts(): array
    {
        $out = [];
        $map = [
            'about_contact_phone'  => ['联系电话', ''],
            'about_contact_email'  => ['联系邮箱', 'contact_email'],
            'about_contact_qq'     => ['客服 QQ', 'contact_qq'],
            'about_contact_wechat' => ['微信 / 公众号', ''],
            'about_contact_addr'   => ['公司地址', ''],
            'about_contact_hours'  => ['服务时间', ''],
        ];
        foreach ($map as $k => $meta) {
            $v = trim(self::get($k));
            if ($v === '' && $meta[1] !== '') {
                $v = trim((string)setting($meta[1], ''));
            }
            if ($v !== '') {
                $out[$meta[0]] = $v;
            }
        }
        return $out;
    }
}
