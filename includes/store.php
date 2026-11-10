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
     * 该商品可展示 / 可购买的报价清单
     *
     * 已配置版本报价时只返回这些版本；未配置时用商品自身的基础价构造单一报价，
     * 保证历史商品与新增商品走同一条渲染与下单路径。
     *
     * @return array<int,array{edition:string,label:string,price:float,bill_type:string,days:int,bill_name:string}>
     */
    public static function quotes(array $app): array
    {
        $opts = self::options($app);
        if (!$opts) {
            $bill  = YxBusiness::billType($app);
            $price = round_money((float)($app['price'] ?? 0));
            $opts  = [[
                'edition'   => (string)($app['edition_min'] ?? 'basic'),
                'label'     => '标准版',
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
     * 是否官方主程序包（商店「程序包」条目，非第三方资源）
     *
     * 官方程序包的口径：程序包本体免费下载（下载接口只校验登录），
     * 条目上的价格是「授权包」（按域名签发的安装授权码）的按次收费价格。
     */
    public static function isOfficialPackage(array $app): bool
    {
        return (string)($app['author_type'] ?? '') === 'official'
            && (string)($app['type'] ?? '') === 'package';
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
