<?php
/**
 * 优享财务系统 · 插件中心业务逻辑
 *
 * 定位：把原本分散在「应用商店(store)」「商店管理(admin/store)」「开发者中心」的
 * 插件相关能力收敛为一个统一入口，提供「市场」与「管理」双视图。
 *
 * 设计约束（重要）：
 *  - 不新建数据表。插件记录继续存放于 `app` 表（type=plugin），安装/授权记录继续
 *    存放于 `app_install`，开发者信息继续存放于 `developer`。
 *  - 不另起计费。下单 / 支付 / 授权签发 / 开发者分成 一律复用
 *    YxBusiness::createOrder / payOrder / grantResource / installApp 与
 *    developer_settlement，避免出现两套互相打架的价格与权益逻辑。
 *  - 本类只做「聚合读取 + 状态归并 + 展示辅助」，不写业务状态。
 */

class YxPluginCenter
{
    /** 插件类型（app.type 中归入插件中心的取值） */
    const TYPES = ['plugin' => '插件', 'theme' => '主题', 'template' => '模板', 'app' => '应用'];

    /** 市场侧筛选：全部 / 官方 / 第三方 */
    const AUTHOR_FILTERS = [
        ''            => '全部',
        'official'    => '官方',
        'thirdparty'  => '第三方',
    ];

    /** 价格筛选 */
    const BILL_FILTERS = [
        ''       => '全部',
        'free'   => '免费',
        'once'   => '一次性',
        'year'   => '按年',
        'points' => '积分',
    ];

    /** 排序方式 → ORDER BY 片段（仅白名单，禁止拼接用户输入） */
    const SORTS = [
        'default' => 'featured DESC, sales DESC, id DESC',
        'new'     => 'id DESC',
        'sales'   => 'sales DESC, id DESC',
        'price'   => 'price ASC, id DESC',
        'hot'     => 'downloads DESC, sales DESC, id DESC',
    ];

    public static function typeName(string $t): string
    {
        return self::TYPES[$t] ?? $t;
    }

    public static function authorName(string $t): string
    {
        return $t === 'thirdparty' ? '第三方' : '官方';
    }

    /** 归一化排序键 */
    public static function sortKey(string $k): string
    {
        return isset(self::SORTS[$k]) ? $k : 'default';
    }

    /** 归一化作者筛选 */
    public static function authorKey(string $k): string
    {
        return isset(self::AUTHOR_FILTERS[$k]) ? $k : '';
    }

    /** 归一化计费筛选 */
    public static function billKey(string $k): string
    {
        return isset(self::BILL_FILTERS[$k]) ? $k : '';
    }

    /**
     * 市场视图：构建查询条件
     *
     * @return array{0:string,1:array} [WHERE 片段, 绑定参数]
     */
    public static function marketWhere(string $kw, string $cat, string $author, string $bill): array
    {
        $where  = " WHERE status = 'on'";
        $bind   = [];

        if ($cat !== '') {
            $where .= ' AND category_key = ?';
            $bind[] = $cat;
        }
        if ($author === 'official') {
            $where .= " AND COALESCE(author_type, 'official') <> 'thirdparty'";
        } elseif ($author === 'thirdparty') {
            $where .= " AND author_type = 'thirdparty'";
        }
        if ($bill !== '') {
            $where .= ' AND bill_type = ?';
            $bind[] = $bill;
        }
        if ($kw !== '') {
            $where .= ' AND (name LIKE ? OR `key` LIKE ? OR description LIKE ?)';
            $like   = '%' . $kw . '%';
            array_push($bind, $like, $like, $like);
        }
        return [$where, $bind];
    }

    /** 市场列表 */
    public static function marketList(string $where, array $bind, string $sort, int $limit, int $offset): array
    {
        $order = self::SORTS[self::sortKey($sort)];
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('app') . $where . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bind
        );
    }

    public static function marketCount(string $where, array $bind): int
    {
        return (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . $where, $bind);
    }

    /** 分类（含每类在售数量），供市场侧边栏 */
    public static function categories(): array
    {
        $cats = YxDb::all('SELECT * FROM ' . YxDb::table('app_category') . ' WHERE status = 1 ORDER BY sort ASC, id ASC');
        $cnt  = [];
        foreach (YxDb::all("SELECT category_key, COUNT(*) AS c FROM " . YxDb::table('app') . " WHERE status = 'on' GROUP BY category_key") as $r) {
            $cnt[(string)$r['category_key']] = (int)$r['c'];
        }
        foreach ($cats as $i => $c) {
            $cats[$i]['count'] = $cnt[(string)$c['key']] ?? 0;
        }
        return $cats;
    }

    /** 计费方式统计，供市场侧边栏 */
    public static function billStats(): array
    {
        $out = array_fill_keys(['free', 'once', 'year', 'points'], 0);
        foreach (YxDb::all("SELECT bill_type, COUNT(*) AS c FROM " . YxDb::table('app') . " WHERE status = 'on' GROUP BY bill_type") as $r) {
            $k = (string)$r['bill_type'];
            if (array_key_exists($k, $out)) {
                $out[$k] = (int)$r['c'];
            }
        }
        return $out;
    }

    /** 市场概览指标 */
    public static function marketStats(): array
    {
        $t  = YxDb::table('app');
        $on = (int)YxDb::value("SELECT COUNT(*) FROM {$t} WHERE status = 'on'");
        return [
            'on'         => $on,
            'official'   => (int)YxDb::value("SELECT COUNT(*) FROM {$t} WHERE status = 'on' AND COALESCE(author_type,'official') <> 'thirdparty'"),
            'thirdparty' => (int)YxDb::value("SELECT COUNT(*) FROM {$t} WHERE status = 'on' AND author_type = 'thirdparty'"),
            'sales'      => (int)YxDb::value("SELECT COALESCE(SUM(sales),0) FROM {$t}"),
        ];
    }

    /**
     * 已购买的插件（客户视角）
     *
     * 以 app_install 为准，关联 app 取名称/版本；过期与停用单独标记，不做过滤，
     * 便于用户在列表里看到「已过期，可续期」而不是凭空消失。
     */
    public static function myPlugins(int $customerId, string $filter = ''): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $t  = YxDb::table('app_install');
        $a  = YxDb::table('app');
        $w  = ' WHERE i.customer_id = ?';
        $b  = [$customerId];
        if ($filter === 'active') {
            $w .= " AND i.status = 'enabled' AND (i.expire_at IS NULL OR i.expire_at = '' OR i.expire_at >= ?)";
            $b[] = date('Y-m-d');
        } elseif ($filter === 'expired') {
            $w .= " AND (i.status <> 'enabled' OR (i.expire_at IS NOT NULL AND i.expire_at <> '' AND i.expire_at < ?))";
            $b[] = date('Y-m-d');
        }
        return YxDb::all(
            "SELECT i.*, a.name AS app_name, a.version, a.cover, a.bill_type, a.price, a.key AS app_key2
               FROM {$t} i LEFT JOIN {$a} a ON a.id = i.app_id"
            . $w . ' ORDER BY i.id DESC LIMIT 500',
            $b
        );
    }

    /** 权益状态归并：enabled / expired / disabled */
    public static function installState(array $row): array
    {
        $exp  = trim((string)($row['expire_at'] ?? ''));
        $st   = (string)($row['status'] ?? '');
        if ($st !== 'enabled') {
            return ['key' => 'disabled', 'name' => '已停用', 'class' => 'badge-warn'];
        }
        if ($exp !== '' && $exp < date('Y-m-d')) {
            return ['key' => 'expired', 'name' => '已过期', 'class' => 'badge-warn'];
        }
        return ['key' => 'enabled', 'name' => '生效中', 'class' => 'badge-ok'];
    }

    /** 我的统计 */
    public static function myStats(int $customerId): array
    {
        if ($customerId <= 0) {
            return ['total' => 0, 'active' => 0, 'expired' => 0];
        }
        $rows = self::myPlugins($customerId);
        $out  = ['total' => count($rows), 'active' => 0, 'expired' => 0];
        foreach ($rows as $r) {
            $k = self::installState($r)['key'];
            if ($k === 'enabled') {
                $out['active']++;
            } else {
                $out['expired']++;
            }
        }
        return $out;
    }

    /**
     * 管理视图：待审核插件（开发者提交，status=pending）
     */
    public static function pendingApps(int $limit = 100): array
    {
        return YxDb::all(
            'SELECT a.*, d.company AS dev_company, d.real_name AS dev_realname, d.status AS dev_status
               FROM ' . YxDb::table('app') . ' a
               LEFT JOIN ' . YxDb::table('developer') . ' d ON d.id = a.developer_id
              WHERE a.status = ? OR a.status = ?
              ORDER BY a.id DESC LIMIT ' . max(1, $limit),
            ['pending', 'draft']
        );
    }

    /** 管理视图：按类型分布 */
    public static function typeStats(): array
    {
        $out = array_fill_keys(array_keys(self::TYPES), 0);
        foreach (YxDb::all("SELECT type, COUNT(*) AS c FROM " . YxDb::table('app') . " GROUP BY type") as $r) {
            $k = (string)$r['type'];
            if (array_key_exists($k, $out)) {
                $out[$k] = (int)$r['c'];
            }
        }
        return $out;
    }

    /** 管理视图：按状态分布 */
    public static function statusStats(): array
    {
        $out = ['draft' => 0, 'pending' => 0, 'on' => 0, 'off' => 0, 'rejected' => 0];
        foreach (YxDb::all("SELECT status, COUNT(*) AS c FROM " . YxDb::table('app') . " GROUP BY status") as $r) {
            $k = (string)$r['status'];
            if (array_key_exists($k, $out)) {
                $out[$k] = (int)$r['c'];
            }
        }
        return $out;
    }

    /**
     * 插件是否对当前站点「已启用」
     *
     * 与 YxBusiness::entitlement 保持一致语义，用于上游加价等自带功能位的插件。
     */
    public static function isEnabled(string $appKey, int $customerId = 0): bool
    {
        $appKey = trim($appKey);
        if ($appKey === '') {
            return false;
        }
        $app = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE `key` = ?', [$appKey]);
        if (!$app) {
            return false;
        }
        $bill = YxBusiness::billType($app);
        if ($bill === 'free' || (float)($app['price'] ?? 0) <= 0 && $bill !== 'points') {
            return true;
        }
        if ($customerId <= 0) {
            return false;
        }
        return YxBusiness::entitlement((int)$app['id'], $customerId) !== null;
    }
}
