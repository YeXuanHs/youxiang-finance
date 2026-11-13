<?php
/**
 * 优享财务系统 · 应用中心（商店）展示与报价
 *
 * 设计约束：
 *  - 不新建数据表。价格统一存 `app.price` + `app.bill_type`，按授权版本差异化的报价
 *    存 `app.price_options`（JSON），为空时全部回退到基础价，历史商品零迁移即可用。
 *  - 本类只做「读取 + 归一 + 展示辅助」，下单金额一律由 quote() 计算后交给
 *    YxBusiness::createOrder，避免前台展示价与实际扣款出现两套口径。
 *  - 开发者信息统一读 `developer` 表，认证标识依据入驻类型（个人/企业）与实名状态。
 */

class YxStore
{
    /** 计费方式（与 YxBusiness::billType 保持一致） */
    const BILLS = [
        'free'   => '免费',
        'once'   => '一次性',
        'year'   => '按年',
        'points' => '积分兑换',
    ];

    /** 商品类型 */
    const APP_TYPES = [
        'plugin'   => '插件',
        'theme'    => '主题',
        'template' => '模板',
        'app'      => '应用',
        'package'  => '程序包',
    ];

    /** 认证标识 */
    const CERTIFY = [
        'official'  => ['label' => '官方认证', 'icon' => '✓'],
        'enterprise' => ['label' => '企业认证', 'icon' => '企'],
        'personal'  => ['label' => '个人认证', 'icon' => '个'],
    ];

    /**
     * 分类 → 图标（24×24 线框路径）与主题渐变
     *
     * 插件 / 应用在目录页以「图标」形式展示，图标按商品所属分类取对应语义图形；
     * 配色与 tools/make_store_covers.php 的 THEMES 一一对应，同一商品的封面图与
     * 图标不会撞色。模板 / 主题走「图片」形式，不使用这里的图标。
     */
    const CATEGORY_ICONS = [
        'pay' => [
            'a' => '#1d4ed8', 'b' => '#3b82f6',
            'svg' => '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><path d="M6 15h4"/>',
        ],
        'cert' => [
            'a' => '#0b7285', 'b' => '#22b8cf',
            'svg' => '<path d="M12 3l7 2.6v5.7c0 4.2-2.9 7.4-7 8.7-4.1-1.3-7-4.5-7-8.7V5.6z"/><path d="M9 12l2.1 2.1L15 10.2"/>',
        ],
        'cloud' => [
            'a' => '#1e3a8a', 'b' => '#6366f1',
            'svg' => '<path d="M7.5 18.5h9.2a4.3 4.3 0 0 0 .6-8.56A5.8 5.8 0 0 0 5.9 9.1 4.2 4.2 0 0 0 7.5 18.5z"/>',
        ],
        'system' => [
            'a' => '#312e81', 'b' => '#7c3aed',
            'svg' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M18.5 5.5l-1.7 1.7M7.2 16.8l-1.7 1.7"/>',
        ],
        'notify' => [
            'a' => '#b45309', 'b' => '#f59e0b',
            'svg' => '<path d="M17.8 8.5a5.8 5.8 0 0 0-11.6 0c0 6.2-2.4 7.9-2.4 7.9h16.4s-2.4-1.7-2.4-7.9"/><path d="M10.2 19.6a2 2 0 0 0 3.6 0"/>',
        ],
        'theme' => [
            'a' => '#9d174d', 'b' => '#f472b6',
            'svg' => '<rect x="3" y="3.5" width="18" height="17" rx="2.5"/><circle cx="8.6" cy="9" r="1.6"/><path d="M21 15.2l-4.7-4.7L5.5 20.5"/>',
        ],
        'tool' => [
            'a' => '#16534a', 'b' => '#14b8a6',
            'svg' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        ],
        'zjmf' => [
            'a' => '#4c1d95', 'b' => '#7a5af8',
            'svg' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7L12 12l8.7-5"/><path d="M12 22V12"/>',
        ],
        'main' => [
            'a' => '#0f172a', 'b' => '#334155',
            'svg' => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9.5h18"/><path d="M9 20V9.5"/>',
        ],
        'default' => [
            'a' => '#1f2937', 'b' => '#5b6c8c',
            'svg' => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/>',
        ],
    ];

    /**
     * 是否以「图片」形式展示（模板 / 主题）
     *
     * 兼容两种口径：type 声明为 theme/template，或归到「前台主题」分类。
     * 现存数据里主题类商品 type 仍为 plugin、只体现在分类上，只按 type 判断会漏掉。
     */
    public static function isTemplate(array $app): bool
    {
        $type = strtolower(trim((string)($app['type'] ?? '')));
        if ($type === 'theme' || $type === 'template') {
            return true;
        }
        return strtolower(trim((string)($app['category_key'] ?? ''))) === 'theme';
    }

    /** 商品对应的图标配色与路径（分类缺失时回退默认图标） */
    public static function icon(array $app): array
    {
        $key = strtolower(trim((string)($app['category_key'] ?? '')));
        return self::CATEGORY_ICONS[$key] ?? self::CATEGORY_ICONS['default'];
    }

    public static function billName(string $t): string
    {
        return self::BILLS[$t] ?? $t;
    }

    public static function typeName($t): string
    {
        $t = (string)$t;
        return self::APP_TYPES[$t] ?? ($t !== '' ? $t : '应用');
    }

    /** 计费周期天数（0 表示长期 / 不限期） */
    public static function billDays(string $bill): int
    {
        return $bill === 'year' ? 365 : 0;
    }

    /* ---------------------------- 价格与版本报价 ---------------------------- */

    /**
     * 解析并清洗版本报价
     *
     * 输入：JSON 字符串或数组，格式
     *   [{"edition":"basic","price":10,"bill_type":"once","label":"普惠版"},
     *    {"edition":"pro",  "price":1600,"bill_type":"year"}]
     * - edition 仅接受 YxEdition 中定义的版本键；
     * - price  为 0 表示该版本免费，bill_type 归一为 free；
     * - label  可选，缺省取版本名。
     */
    public static function cleanOptions($raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $edition = (string)($row['edition'] ?? $row['key'] ?? '');
            if (!isset(YxEdition::EDITIONS[$edition]) || isset($seen[$edition])) {
                continue;
            }
            $seen[$edition] = true;
            $price = round_money(max(0, (float)($row['price'] ?? 0)));
            $bill  = (string)($row['bill_type'] ?? 'once');
            if (!isset(self::BILLS[$bill]) || $bill === 'points') {
                $bill = 'once';
            }
            if ($price <= 0) {
                $bill = 'free';
            }
            $label = trim((string)($row['label'] ?? ''));
            /* 标签取授权版本真名，与 quotes() 的兜底一致，不能写死「普惠版」 */
            $out[] = [
                'edition'   => $edition,
                'label'     => mb_substr($label !== '' ? $label : YxEdition::name($edition), 0, 40),
                'price'     => $price,
                'bill_type' => $bill,
                'days'      => self::billDays($bill),
            ];
        }
        // 按版本档位排序，保证前台展示稳定（普惠 → 专业 → 企业）
        usort($out, static function ($a, $b) {
            return YxEdition::rank($a['edition']) <=> YxEdition::rank($b['edition']);
        });
        return $out;
    }

    /** 已配置的版本报价 */
    public static function options(array $app): array
    {
        return self::cleanOptions($app['price_options'] ?? '');
    }

    /**
     * 校验后台提交的版本报价 JSON
     *
     * 不能只依赖 cleanOptions()：JSON 写错或 edition 拼错时它会静默返回空数组，
     * 保存后等于把原有分版本价格全部清空。这里区分「空」与「非法」，
     * 非法输入应由调用方拒绝保存。
     *
     * @return array{ok:bool,msg:string,options:array}
     */
    public static function validateOptionsInput($raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return ['ok' => true, 'msg' => '', 'options' => []];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'msg' => '版本报价不是合法的 JSON 数组', 'options' => []];
            }
        }
        $clean = self::cleanOptions($decoded);
        if (count($clean) !== count($decoded)) {
            return [
                'ok'      => false,
                'msg'     => '版本报价含无效或重复的 edition（仅支持 basic / pro / enterprise，且每个版本只能出现一次）',
                'options' => $clean,
            ];
        }
        return ['ok' => true, 'msg' => '', 'options' => $clean];
    }

    /**
     * 该商品可展示 / 可购买的报价清单
     *
     * 已配置版本报价时只返回这些版本；未配置时用商品自身的基础价构造单一报价，
     * 保证历史商品与新增商品走同一条渲染与下单路径。
     *
     * @return array<int,array{edition:string,label:string,price:float,bill_type:string,days:int,bill_name:string}>
     */
    public static function quotes(array $app): array
    {
        // 商品未配置分版本报价时，标签取实际授权版本名，不能写死「标准版」——
        // 否则历史商品在详情页会显示一个并不存在的档位。
        $min   = (string)($app['edition_min'] ?? 'basic');
        $opts  = self::options($app);
        if (!$opts) {
            $bill  = YxBusiness::billType($app);
            $price = round_money((float)($app['price'] ?? 0));
            $opts  = [[
                'edition'   => isset(YxEdition::EDITIONS[$min]) ? $min : 'basic',
                'label'     => YxEdition::name(isset(YxEdition::EDITIONS[$min]) ? $min : 'basic'),
                'price'     => $bill === 'free' ? 0.0 : $price,
                'bill_type' => $bill,
                'days'      => self::billDays($bill),
            ]];
        }
        foreach ($opts as &$o) {
            $o['bill_name'] = self::billName($o['bill_type']);
        }
        unset($o);
        return $opts;
    }

    /** 指定版本的报价；不存在时返回 null */
    public static function quote(array $app, $edition = null): ?array
    {
        $list = self::quotes($app);
        if (!$list) {
            return null;
        }
        $edition = (string)$edition;
        if ($edition !== '') {
            foreach ($list as $q) {
                if ($q['edition'] === $edition) {
                    return $q;
                }
            }
            return null;
        }
        // 未指定版本：取当前授权版本，其次取最低档
        foreach ($list as $q) {
            if ($q['edition'] === YxEdition::current()) {
                return $q;
            }
        }
        return $list[0];
    }

    /** 起价（用于列表卡片「¥xx 起」） */
    public static function fromPrice(array $app): ?float
    {
        $min = null;
        foreach (self::quotes($app) as $q) {
            if ($q['bill_type'] === 'free') {
                continue;
            }
            $min = $min === null ? (float)$q['price'] : min($min, (float)$q['price']);
        }
        return $min;
    }

    /** 是否所有报价均免费 */
    public static function allFree(array $app): bool
    {
        foreach (self::quotes($app) as $q) {
            if ($q['bill_type'] !== 'free') {
                return false;
            }
        }
        return true;
    }

    /** 是否存在多个版本报价（卡片价格后加「起」） */
    public static function hasMultiPrice(array $app): bool
    {
        return count(self::quotes($app)) > 1;
    }

    /**
     * 实际生效的计费方式
     *
     * 商品一旦配置了版本报价，计费方式必须跟随所选版本；否则「普惠版一次性、
     * 专业版按年」这类组合会在下单/到期时退回基础计费方式，造成展示价与
     * 扣款周期不一致。
     */
    public static function effectiveBill(array $app, $edition = null): string
    {
        $q = self::quote($app, $edition);
        return $q ? (string)$q['bill_type'] : YxBusiness::billType($app);
    }

    /** 积分报价（没有则返回 null） */
    public static function pointsQuote(array $app): ?array
    {
        foreach (self::quotes($app) as $q) {
            if ($q['bill_type'] === 'points') {
                return $q;
            }
        }
        return null;
    }

    /**
     * 列表/表格式的价格文案（区分单一价、分版本起价与免费）
     */
    public static function priceText(array $app): string
    {
        /* 官方程序包/开源版：price 字段的历史残留价不能出现在列表里 */
        if (self::isOfficialPackage($app) && self::packageFree($app)) {
            return '免费';
        }
        if (self::allFree($app)) {
            return '免费';
        }
        if (self::hasMultiPrice($app)) {
            $from = self::fromPrice($app);
            if ($from === null) {
                return '免费';
            }
            $bills = [];
            foreach (self::quotes($app) as $q) {
                if ($q['bill_type'] !== 'free') {
                    $bills[$q['bill_type']] = true;
                }
            }
            $unit = count($bills) === 1 && isset($bills['year']) ? '/年' : '';
            return money($from, true) . $unit . ' 起';
        }
        return YxBusiness::priceLabel($app);
    }

    /**
     * 统一下单 / 领取入口（应用与第三方资源共用）
     *
     * 前台两个入口（store 详情页 → user/app_redeem.php、产品页 → user/purchase.php）
     * 都走这里，避免两套结算规则在免费/积分/按年/升级场景下分叉。
     *
     * @param array $in domain、edition、spec
     * @return array{ok:bool,msg:string,order_id?:int,license_key?:string,expire_at?:string,edition?:string,bill?:string}
     */
    public static function checkout(array $app, int $customerId, array $in): array
    {
        $appId      = (int)($app['id'] ?? 0);
        $isResource = (string)($app['author_type'] ?? '') === 'thirdparty';
        if ($appId <= 0 || $customerId <= 0) {
            return ['ok' => false, 'msg' => '参数不完整，无法下单'];
        }
        if ($isResource && !self::packageReady($app)) {
            return ['ok' => false, 'msg' => '该资源暂未托管安装包，暂不可获取；请到应用详情页查看原始地址'];
        }
        if (YxEdition::rank() < YxEdition::rank((string)($app['edition_min'] ?? 'basic'))) {
            return ['ok' => false, 'msg' => '该应用需要「' . YxEdition::name((string)$app['edition_min']) . '」及以上版本'];
        }
        if (!$isResource && !YxEdition::can('store.buy')) {
            return ['ok' => false, 'msg' => '购买或安装应用需要「' . YxEdition::name('basic') . '」及以上版本'];
        }

        $want   = trim((string)($in['edition'] ?? ''));
        $quote  = self::quote($app, $want !== '' ? $want : null);
        if (!$quote) {
            return ['ok' => false, 'msg' => '所选授权版本不在该商品的报价范围内'];
        }
        $edition = (string)$quote['edition'];
        $bill    = (string)$quote['bill_type'];

        $domain = $isResource ? 'resource' : normalize_domain((string)($in['domain'] ?? ''));

        /*
         * 正版程序包 / 开源版只交付「下载权益」：安装授权码属于安装授权包 SKU，
         * 与域名无关，因此和第三方资源一样落到 domain=resource，不要求客户填域名。
         */
        $pkgKind      = $isResource ? '' : self::packageKind($app);
        $downloadOnly = $isResource || in_array($pkgKind, ['program', 'open'], true);
        if ($downloadOnly) {
            $domain = 'resource';
        }
        if (!$downloadOnly && $domain === '') {
            return ['ok' => false, 'msg' => '请填写要绑定的域名（授权码按域名签发）'];
        }
        /* 官方包没有可交付的文件时不能卖：否则下单成功却永远下载不到 */
        if (!$isResource && !self::packageReady($app)) {
            return ['ok' => false, 'msg' => '发行包暂未上传，暂不可购买；请联系客服开通'];
        }

        // 同一域名已持有：同档或更低档不允许重复购买；更高档视为升级放行
        $current = YxBusiness::entitlement($appId, $customerId, $domain);
        if ($current && $downloadOnly) {
            return ['ok' => false, 'msg' => $isResource
                ? '您已获取该资源，可直接在详情页下载'
                : '您已购买该程序包，可直接在详情页下载'];
        }
        if ($current) {
            $curEd = (string)($current['edition'] ?? 'basic');
            if (YxEdition::rank($edition) <= YxEdition::rank($curEd)) {
                return [
                    'ok'  => false,
                    'msg' => '域名 ' . $domain . ' 已拥有「' . YxEdition::name($curEd) . '」授权（有效期至 '
                        . ((string)($current['expire_at'] ?? '') ?: '长期') . '），如需更高档位请选择升级版本',
                ];
            }
        }

        /* ---------- 积分兑换 ---------- */
        if ($bill === 'points') {
            $need = (int)($app['points_price'] ?? 0);
            if ($need <= 0) {
                return ['ok' => false, 'msg' => '该商品未配置积分价格'];
            }
            $dec = YxDb::exec(
                'UPDATE ' . YxDb::table('customer') . ' SET points = points - ? WHERE id = ? AND points >= ?',
                [$need, $customerId, $need]
            );
            if (!$dec) {
                return ['ok' => false, 'msg' => '积分不足，需 ' . $need . ' 积分'];
            }
            $res = $downloadOnly
                ? YxBusiness::grantResource($app, $customerId, 0, $edition, 'points', 0)
                : YxBusiness::installApp([
                    'app_id' => $appId, 'customer_id' => $customerId, 'domain' => $domain,
                    'edition' => $edition, 'bill_type' => 'points', 'price' => 0,
                ]);
            if (empty($res['ok'])) {
                // 授予失败必须把积分退回，否则客户积分已扣、权益没有
                YxDb::exec('UPDATE ' . YxDb::table('customer') . ' SET points = points + ? WHERE id = ?', [$need, $customerId]);
                return ['ok' => false, 'msg' => '兑换失败：' . (string)($res['msg'] ?? '未知错误')];
            }
            log_action('app.redeem_points', 'app#' . $appId, ['customer' => $customerId, 'points' => $need, 'edition' => $edition, 'domain' => $domain]);
            return [
                'ok' => true, 'msg' => '兑换成功，已开通/解锁', 'edition' => $edition, 'bill' => 'points',
                'license_key' => (string)($res['license_key'] ?? ''), 'expire_at' => (string)($res['expire_at'] ?? ''),
            ];
        }

        /* ---------- 免费领取 ---------- */
        if ($bill === 'free' || ($quote['price'] <= 0 && $bill !== 'once' && $bill !== 'year')) {
            $res = $downloadOnly
                ? YxBusiness::grantResource($app, $customerId, 0, $edition, 'free', 0)
                : YxBusiness::installApp([
                    'app_id' => $appId, 'customer_id' => $customerId, 'domain' => $domain,
                    'edition' => $edition, 'bill_type' => 'free', 'price' => 0,
                ]);
            if (empty($res['ok'])) {
                return ['ok' => false, 'msg' => '领取失败：' . (string)($res['msg'] ?? '未知错误')];
            }
            log_action('app.install_free', $app['key'] ?? ('app#' . $appId), ['customer' => $customerId, 'domain' => $domain, 'edition' => $edition]);
            return [
                'ok' => true, 'msg' => $isResource ? '已获取，可在详情页下载' : '安装成功，授权已签发',
                'edition' => $edition, 'bill' => 'free',
                'license_key' => (string)($res['license_key'] ?? ''), 'expire_at' => (string)($res['expire_at'] ?? ''),
            ];
        }

        /* ---------- 一次性 / 按年：生成订单 ---------- */
        $pricing = self::priceOf($app, $edition, $in['spec'] ?? '');
        $amount  = round_money($pricing['amount']);
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '该商品未配置价格'];
        }
        $isUpgrade = $current && !$downloadOnly && YxEdition::rank($edition) > YxEdition::rank((string)($current['edition'] ?? 'basic'));
        $specTxt   = $pricing['spec'] ? (string)$pricing['spec']['name'] : '';
        $created   = YxBusiness::createOrder([
            'customer_id'    => $customerId,
            'type'           => 'app',
            'app_id'         => $appId,
            'app_edition'    => $edition,
            'app_bill_type'  => $bill,
            'name'           => $downloadOnly ? (string)$app['name'] : $domain,
            'amount'         => $amount,
            'note'           => ($isUpgrade ? '应用升级：' : '应用购买：') . (string)$app['name']
                . '（' . self::billName($bill) . ' · ' . $quote['label'] . '）'
                . ($specTxt !== '' ? ' · 设置：' . $specTxt : ''),
        ]);
        if (empty($created['ok'])) {
            return ['ok' => false, 'msg' => (string)($created['msg'] ?? '订单创建失败')];
        }
        log_action($isUpgrade ? 'app.upgrade_order' : 'app.create_order', 'app#' . $appId, [
            'order' => (int)$created['order_id'], 'edition' => $edition, 'bill' => $bill,
            'amount' => $amount, 'domain' => $domain,
        ]);
        return [
            'ok' => true, 'msg' => $isUpgrade ? '升级订单已创建，请完成支付' : '订单已创建，请完成支付',
            'order_id' => (int)$created['order_id'], 'edition' => $edition, 'bill' => $bill,
        ];
    }

    /**
     * 是否官方主程序包（商店「程序包」条目，非第三方资源）
     *
     * 官方主程序包分为三种 SKU：正版程序包（program）、安装授权包（license）、
     * 开源版（open）。正版程序包与安装授权包按次收费、购买后方可下载，
     * 只有开源版免费分发，具体判定见 packageKind() / packageFree()。
     */
    public static function isOfficialPackage(array $app): bool
    {
        return (string)($app['author_type'] ?? '') === 'official'
            && (string)($app['type'] ?? '') === 'package';
    }

    /**
     * 官方主程序包的 SKU 类型
     *
     * 以固定 key 为准，名称只作为历史数据兜底；返回值：
     *   program  正版程序包（付费下载，含程序本体，不含安装授权码）
     *   license  安装授权包（付费下载，含授权文件与安装授权码）
     *   open     开源版（免费下载）
     *   ''       非官方主程序包
     */
    public static function packageKind(array $app): string
    {
        if (!self::isOfficialPackage($app)) {
            return '';
        }
        $key  = strtolower(trim((string)($app['key'] ?? '')));
        $name = (string)($app['name'] ?? '');
        if (strpos($key, 'open') !== false || strpos($key, 'community') !== false || strpos($name, '开源') !== false) {
            return 'open';
        }
        if (strpos($key, 'license') !== false || strpos($name, '授权包') !== false) {
            return 'license';
        }
        return 'program';
    }

    /**
     * 官方主程序包是否「登录即可免费下载」
     *
     * 只有开源版（open）免费分发；正版程序包（program，88 元/次）与
     * 安装授权包（license）都要先购买才能下载，绝不能因为 price 残留
     * 或 SKU 判定把正版包放成免费下载。
     */
    public static function packageFree(array $app): bool
    {
        if (!self::isOfficialPackage($app)) {
            return false;
        }
        $kind = self::packageKind($app);
        if ($kind === 'open') {
            return true;
        }
        if ($kind === 'program') {
            return false; // 正版程序包一律付费，不受 price/bill_type 残留影响
        }
        return YxBusiness::billType($app) === 'free';
    }

    /**
     * 是否已购买某资源（下载权益或已支付订单）
     *
     * 程序包下载入口用它做订单校验：支付成功会写入 app_install 权益；
     * 历史订单若只有已支付记录、权益未落库，也不能把已付费客户挡在门外。
     */
    public static function hasPurchased(array $app, int $customerId): bool
    {
        $appId = (int)($app['id'] ?? 0);
        if ($appId <= 0 || $customerId <= 0) {
            return false;
        }
        if (YxBusiness::entitlement($appId, $customerId)) {
            return true;
        }
        return (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('order')
            . " WHERE app_id = ? AND customer_id = ? AND status = 'paid'",
            [$appId, $customerId]
        ) > 0;
    }

    /**
     * 已托管的资源文件绝对路径
     *
     * 商店条目可能只有目录与报价（例如同步自上游应用市场、安装包归上游分发），
     * 此时 package_path 为空或指向已删除的文件。下载入口必须先问这里，
     * 不能只看 package_path 是否为空，否则前台会给出必然 404 的下载按钮。
     *
     * 校验口径与 api/store_download.php 一致：必须落在 uploads/ 之内且是有效文件。
     */
    public static function packageFile(array $app): ?string
    {
        $rel = ltrim(trim((string)($app['package_path'] ?? '')), '/');
        if ($rel === '') {
            return null;
        }
        $base = realpath(YX_ROOT . '/uploads');
        $full = realpath(YX_ROOT . '/uploads/' . $rel);
        if (!$base || !$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return is_file($full) && (int)@filesize($full) > 0 ? $full : null;
    }

    /** 是否已有可下载的资源文件 */
    public static function packageReady(array $app): bool
    {
        return self::packageFile($app) !== null;
    }

    /**
     * 官方主程序包的受控发行文件（不在站点根目录、前台不提供直链）
     *
     * 发行包（含整包密文包）另有权威副本存放在 config('store.release_dir')（站点根目录
     * 之外，仅 root 可写），供后台「商店管理 → 发行包分发」经管理员鉴权后
     * 流式读出；前台用户下载走 packageFile() 的 uploads/ 副本，两条链路互不
     * 影响。文件名沿用 package_path 的基名（数据库清单），这里只做只读判定，
     * 绝不在网页上输出路径或密钥。
     *
     * 注意：release_dir 不在 open_basedir 白名单内时，任何 is_dir()/realpath()
     * 都会抛 open_basedir 警告，故先用 pathAllowed() 做纯字符串预判。
     */
    public static function releaseFile(array $app): ?string
    {
        if (!self::isOfficialPackage($app)) {
            return null;
        }
        $name = basename((string)($app['package_path'] ?? ''));
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        $dir = rtrim((string)config('store.release_dir', ''), '/');
        if ($dir === '' || !self::pathAllowed($dir)) {
            return null;
        }
        if (!is_dir($dir)) {
            return null;
        }
        $full = realpath($dir . '/' . $name);
        $base = realpath($dir);
        if (!$full || !$base || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return is_file($full) && (int)@filesize($full) > 0 ? $full : null;
    }

    /**
     * 绝对路径是否位于 PHP open_basedir 白名单之内
     *
     * 发行包目录被刻意放在站点根目录之外，而宝塔默认给站点写了
     * open_basedir=<站点目录>:/tmp:/run/yxsign，此时对 release_dir 的
     * is_dir()/realpath() 会抛 open_basedir restriction 警告并返回 false，
     * 使每个请求都往 error_log 里刷一条警告。这里先做纯字符串判定：
     * 不在白名单内就直接视为「无发行文件」，不再触发被限制的文件系统调用。
     */
    private static function pathAllowed(string $path): bool
    {
        $open = trim((string)ini_get('open_basedir'));
        if ($open === '') {
            return true; // 未启用限制
        }
        $target = rtrim($path, '/') . '/';
        foreach (explode(PATH_SEPARATOR, $open) as $root) {
            $root = trim($root);
            if ($root === '') {
                continue;
            }
            $root = rtrim($root, '/') . '/';
            if (strpos($target, $root) === 0) {
                return true;
            }
        }
        return false;
    }

    /** 官方主程序包的加密发行文件是否已就绪 */
    public static function releaseReady(array $app): bool
    {
        return self::releaseFile($app) !== null;
    }

    /**
     * 下单金额：基础价 + 版本报价 + 应用设置加价
     *
     * @return array{amount:float,quote:?array,spec:?array,extra:float,bill_type:string,days:int}
     */
    public static function priceOf(array $app, $edition = null, $specName = null): array
    {
        $quote = self::quote($app, $edition);
        $base  = $quote ? (float)$quote['price'] : round_money((float)($app['price'] ?? 0));

        $specs  = class_exists('YxServiceCenter') ? YxServiceCenter::appSpecs($app) : [];
        $picked = null;
        $name   = trim((string)$specName);
        if ($name !== '') {
            foreach ($specs as $s) {
                if ($s['name'] === $name) {
                    $picked = $s;
                    break;
                }
            }
        }
        $extra = $picked ? round_money(max(0, (float)$picked['price'])) : 0.0;

        return [
            'amount'    => round_money($base + $extra),
            'quote'     => $quote,
            'spec'      => $picked,
            'extra'     => $extra,
            'bill_type' => $quote ? (string)$quote['bill_type'] : YxBusiness::billType($app),
            'days'      => $quote ? (int)$quote['days'] : self::billDays(YxBusiness::billType($app)),
        ];
    }

    /* ------------------------------ 开发者档案 ------------------------------ */

    /** 商品所属开发者档案（非开发者商品返回 null） */
    public static function developer(array $app): ?array
    {
        $devId = (int)($app['developer_id'] ?? 0);
        if ($devId <= 0) {
            return null;
        }
        static $cache = [];
        if (array_key_exists($devId, $cache)) {
            return $cache[$devId];
        }
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('developer') . ' WHERE id = ?', [$devId]);
        return $cache[$devId] = ($row ?: null);
    }

    /**
     * 认证标识
     *
     * 官方商品 → 官方认证；开发者商品按入驻类型区分企业 / 个人，
     * 实名未通过时展示「未认证」，避免把待核验的资料当作认证主体。
     * 第三方条目没有入驻档案时只标「第三方」：站点没核验过主体类型，
     * 不能凭 author_type 推断成「个人认证」。
     *
     * @return array{key:string,label:string,icon:string,title:string}
     */
    public static function certify(array $app): array
    {
        $type = (string)($app['author_type'] ?? 'official');
        $dev  = self::developer($app);
        if ($type === 'thirdparty' && !$dev) {
            // 第三方条目没有入驻档案时，站点并未核验过主体类型，
            // 不能一律标成「个人认证」（上游企业供应商也会被标错）。
            return [
                'key'   => 'third',
                'label' => '第三方',
                'icon'  => '',
                'title' => '第三方资源 · 本站未核验开发者资质',
            ];
        }
        if (!$dev) {
            return ['key' => 'official', 'label' => '官方认证', 'icon' => '✓', 'title' => '官方自营商品'];
        }
        $devType = (string)($dev['type'] ?? 'personal');
        $isEnt   = $devType === 'enterprise';
        $meta    = $isEnt ? self::CERTIFY['enterprise'] : self::CERTIFY['personal'];
        $entity  = $isEnt
            ? ((string)($dev['company'] ?? '') ?: (string)($dev['real_name'] ?? ''))
            : (string)($dev['real_name'] ?? '');
        $verified = (string)($dev['realname_status'] ?? '') === 'verified';
        if (!$verified) {
            return [
                'key'   => $isEnt ? 'enterprise' : 'personal',
                'label' => $isEnt ? '企业未认证' : '个人未认证',
                'icon'  => $meta['icon'],
                'title' => '开发者实名资料待核验',
            ];
        }
        return [
            'key'   => $isEnt ? 'enterprise' : 'personal',
            'label' => $meta['label'],
            'icon'  => $meta['icon'],
            'title' => ($isEnt ? '企业开发者 · ' : '个人开发者 · ') . ($entity !== '' ? $entity : '已实名'),
        ];
    }

    /**
     * 认证标识对应的展示样式类（三处视图共用，避免各写一份三元表达式）
     */
    public static function certifyClass(array $app): string
    {
        $key = self::certify($app)['key'];
        if ($key === 'enterprise') {
            return 'is-company';
        }
        if ($key === 'personal') {
            return 'is-person';
        }
        return $key === 'third' ? 'is-third' : 'is-official';
    }

    /**
     * 开发者展示名
     *
     * 企业开发者优先用企业名称，个人开发者只用真实姓名：
     * 个人档案里残留的企业名称不应盖住实名主体，否则与认证标识不一致。
     */
    public static function authorName(array $app): string
    {
        $dev = self::developer($app);
        if ($dev) {
            $isEnt = (string)($dev['type'] ?? 'personal') === 'enterprise';
            $name  = $isEnt
                ? ((string)($dev['company'] ?? '') ?: (string)($dev['real_name'] ?? ''))
                : (string)($dev['real_name'] ?? '');
            if ($name !== '') {
                return $name;
            }
            $fallback = (string)($dev['company'] ?? '') ?: (string)($dev['real_name'] ?? '');
            if ($fallback !== '') {
                return $fallback;
            }
        }
        $author = trim((string)($app['author'] ?? ''));
        return $author !== '' ? $author : '官方应用中心';
    }

    /**
     * 开发者已上架商品数 / 累计销量
     *
     * $dev 为空表示官方自营：官方商品的 developer_id 为 0，
     * 因此按「未关联第三方开发者」的范围统计，否则官方卡片会一直显示 0。
     */
    public static function developerStats(?array $dev, ?array $app = null): array
    {
        $sql = 'SELECT COUNT(*) AS apps, COALESCE(SUM(sales),0) AS sales FROM ' . YxDb::table('app') . " WHERE status = 'on' AND ";
        if (!$dev) {
            $authorType = (string)($app['author_type'] ?? 'official');
            if ($authorType === 'thirdparty') {
                // 未建档的第三方商品只能统计自身
                $row = YxDb::one($sql . 'id = ?', [(int)($app['id'] ?? 0)]);
                return ['apps' => (int)($row['apps'] ?? 0), 'sales' => (int)($row['sales'] ?? 0)];
            }
            $row = YxDb::one($sql . 'developer_id = 0 AND author_type <> ?', ['thirdparty']);
            return ['apps' => (int)($row['apps'] ?? 0), 'sales' => (int)($row['sales'] ?? 0)];
        }
        $row = YxDb::one($sql . 'developer_id = ?', [(int)$dev['id']]);
        return ['apps' => (int)($row['apps'] ?? 0), 'sales' => (int)($row['sales'] ?? 0)];
    }

    /** 站点自己的开发者落地页地址（官方商品返回空） */
    public static function developerUrl(array $app): string
    {
        $dev = self::developer($app);
        if (!$dev || (string)($dev['status'] ?? '') !== 'approved') {
            return '';
        }
        return url('store/developer.php?id=' . (int)$dev['id']);
    }

    /* ------------------------------ 商品信息 ------------------------------ */

    /** 人类可读文件大小 */
    public static function fileSize(array $app): string
    {
        $bytes = (int)($app['package_size'] ?? 0);
        if ($bytes <= 0) {
            return '-';
        }
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 2, '.', ''), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
        }
        return $bytes . ' B';
    }

    /** 最近更新时间（上架时间兜底） */
    public static function updatedAt(array $app): string
    {
        $t = (string)($app['updated_at'] ?? '');
        if ($t === '') {
            $t = (string)($app['published_at'] ?? '');
        }
        return $t !== '' ? date('Y-m-d', strtotime($t)) : '-';
    }

    /** 支持语言 */
    public static function language(array $app): string
    {
        $v = trim((string)($app['language'] ?? ''));
        return $v !== '' ? $v : '简体中文';
    }

    /** 应用类别：一级分类 · 商品类型 */
    public static function categoryLabel(array $app, ?array $cat = null): string
    {
        $catName = $cat !== null
            ? (string)($cat['name'] ?? '')
            : (string)(YxDb::value('SELECT name FROM ' . YxDb::table('app_category') . ' WHERE `key` = ?', [(string)($app['category_key'] ?? '')]) ?: '未分类');
        return ($catName !== '' ? $catName : '未分类') . ' · ' . self::typeName($app['type'] ?? 'plugin');
    }

    /**
     * 商品信息表（顺序与参考站一致）
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function infoRows(array $app, ?array $cat = null): array
    {
        $rows = [
            ['应用类别', self::categoryLabel($app, $cat)],
            ['当前版本', 'v' . (trim((string)($app['version'] ?? '')) ?: '1.0.0')],
            ['最近更新', self::updatedAt($app)],
            ['文件大小', self::fileSize($app)],
            ['支持语言', self::language($app)],
        ];
        $compatible = trim((string)($app['compatible'] ?? ''));
        if ($compatible !== '') {
            $rows[] = ['兼容环境', $compatible];
        }
        return $rows;
    }

    /** 版本历史（有 dev_app_version 记录时展示） */
    public static function versions(int $appId): array
    {
        if ($appId <= 0) {
            return [];
        }
        return YxDb::all(
            'SELECT version, package_size, changelog, created_at FROM ' . YxDb::table('dev_app_version') . ' WHERE app_id = ? ORDER BY id DESC LIMIT 10',
            [$appId]
        ) ?: [];
    }
}
