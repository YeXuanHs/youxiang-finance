<?php
/**
 * 优享财务系统 · 版本（授权版本）与功能模块开关
 *
 * 三个版本：普惠版(basic) / 专业版(pro) / 企业版(enterprise)
 * 版本可在后台「版本与模块」中切换，切换后受版本限制的菜单与接口自动收敛。
 */

class YxEdition
{
    /** 版本定义与排序权重 */
    const EDITIONS = [
        'basic' => [
            'name'  => '普惠版',
            'rank'  => 1,
            'tag'   => '起步即用',
            'desc'  => '面向初创与小规模 IDC，覆盖客户、产品、订单与基础财务记账。',
            'color' => '#2f9e44',
            'price' => '10 元 · 永久授权',
            'items' => [
                '客户与账号管理',
                '产品与套餐管理',
                '业务实例（开通/暂停/终止）',
                '订单与账单中心',
                '收支流水记账',
                '可购买官方免费与付费插件',
                '域名授权状态展示',
            ],
        ],
        'pro' => [
            'name'  => '专业版',
            'rank'  => 2,
            'tag'   => '增长首选',
            'desc'  => '在普惠版基础上补齐工单、续费、优惠券与渠道分销能力。',
            'color' => '#1c7ed6',
            'price' => '1600 元 / 年',
            'items' => [
                '普惠版全部能力',
                '工单协同与售后',
                '自动续费与到期提醒',
                '优惠券与促销',
                '渠道 / 代理商与佣金',
                '商店应用购买与一键安装',
                '业务与应用授权绑定',
            ],
        ],
        'enterprise' => [
            'name'  => '企业版',
            'rank'  => 3,
            'tag'   => '规模化运营',
            'desc'  => '面向规模化运营，提供多节点资源、分站、开放接口与经营分析。',
            'color' => '#7048e8',
            'price' => '3600 元 / 年',
            'items' => [
                '专业版全部能力',
                '多节点资源同步',
                '分站 / 多站点管理',
                '开放 API 与回调',
                '经营分析与财务报表',
                '授权中心（域名授权签发）',
                '批量导出与对账',
            ],
        ],
    ];

    /** 功能 → 所需最低版本 */
    const FEATURES = [
        'finance.cashflow' => 'basic',
        'finance.invoice'  => 'basic',
        'business.service' => 'basic',
        'business.node'    => 'enterprise',
        'business.renew'   => 'pro',
        'ticket'           => 'pro',
        'coupon'           => 'pro',
        'agent'            => 'pro',
        'store.buy'        => 'basic',
        'upstream'         => 'pro',
        'upstream.markup'  => 'basic',   // 插件：上游对接代理等级加价（购买后启用，普惠版可用）
        'report'           => 'enterprise',
        'subsite'          => 'enterprise',
        'openapi'          => 'enterprise',
        'license.center'   => 'enterprise',
        'export'           => 'enterprise',
    ];

    /** 当前版本 key */
    public static function current(): string
    {
        $cur = (string)setting('edition', config('edition.current', 'basic'));
        return isset(self::EDITIONS[$cur]) ? $cur : 'basic';
    }

    public static function info(string $key = null): array
    {
        return self::EDITIONS[$key ?? self::current()] ?? self::EDITIONS['basic'];
    }

    public static function name(string $key = null): string
    {
        return self::info($key)['name'];
    }

    public static function rank(string $key = null): int
    {
        return self::info($key)['rank'];
    }

    /** 是否允许切换到目标版本 */
    public static function canSwitch(string $target): bool
    {
        if (!config('edition.allow_switch', true)) {
            return false;
        }
        if (!isset(self::EDITIONS[$target])) {
            return false;
        }
        // 开源版锁定为开源发行：只允许开源版内部的版本档位，不允许借切换脱离开源形态
        if (YxLicense::modeLocked() && YxLicense::mode() !== (string)config('license.mode', 'community')) {
            return false;
        }
        // 存在正版授权时，授权版本是上限；未授权时仅允许在本地上限内调整
        $max = YxLicense::maxEdition();
        return self::EDITIONS[$target]['rank'] <= self::EDITIONS[$max]['rank'];
    }

    /** 当前版本是否具备某功能 */
    public static function can(string $feature): bool
    {
        $need = self::FEATURES[$feature] ?? 'basic';
        return self::rank() >= self::rank($need);
    }

    /** 页面级别拦截 */
    public static function requireFeature(string $feature, string $redirectTo = null): void
    {
        if (self::can($feature)) {
            return;
        }
        $need = self::FEATURES[$feature] ?? 'basic';
        $msg  = sprintf('当前为「%s」，该功能需要「%s」及以上版本。', self::name(), self::name($need));
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            json_out(['code' => 403, 'msg' => $msg], 403);
        }
        http_response_code(403);
        flash($msg, 'error');
        redirect($redirectTo ?: url('admin/index.php'));
    }

    /** 版本对比矩阵，供后台展示 */
    public static function matrix(): array
    {
        $rows = [];
        foreach (self::FEATURES as $feature => $min) {
            $rows[$feature] = [
                'min'  => $min,
                'basic' => self::rank('basic') >= self::rank($min),
                'pro'   => self::rank('pro') >= self::rank($min),
                'enterprise' => self::rank('enterprise') >= self::rank($min),
            ];
        }
        return $rows;
    }

    /** 功能中文名 */
    public static function featureName(string $feature): string
    {
        $map = [
            'finance.cashflow' => '收支流水记账',
            'finance.invoice'  => '账单 / 发票中心',
            'business.service' => '业务实例管理',
            'business.node'    => '多节点资源同步',
            'business.renew'   => '自动续费',
            'ticket'           => '工单协同',
            'coupon'           => '优惠券与促销',
            'agent'            => '渠道 / 代理商',
            'store.buy'        => '商店购买与安装',
            'upstream'         => '上游对接与自动开通',
            'upstream.markup'  => '上游代理等级加价（插件）',
            'report'           => '经营分析报表',
            'subsite'          => '分站管理',
            'openapi'          => '开放 API',
            'license.center'   => '授权中心',
            'export'           => '批量导出',
        ];
        return $map[$feature] ?? $feature;
    }
}
