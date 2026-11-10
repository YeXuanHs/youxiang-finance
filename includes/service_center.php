<?php
/**
 * 优享财务系统 · 服务中心（可购买的服务项目）
 *
 * 设计约定：
 *   - 服务项目存 yx_service_item，一条记录 = 一个可购买的服务
 *   - 价格由 bill_type + price 决定，可选加价规格存 specs（JSON）
 *   - 下单生成 type=service 订单，支付成功后写入 yx_service_order 交付记录
 *   - 报价型项目（bill_type=quote）不走在线支付，只登记需求
 */

class YxServiceCenter
{
    const BILL_TYPES = [
        'free'  => '免费',
        'once'  => '一次性',
        'month' => '按月',
        'year'  => '按年',
        'quote' => '人工报价',
    ];

    const BILL_DAYS = [
        'free' => 0, 'once' => 0, 'month' => 30, 'year' => 365, 'quote' => 0,
    ];

    const GROUPS = [
        'ops'     => ['name' => '运维托管', 'icon' => '🛠'],
        'safe'    => ['name' => '安全服务', 'icon' => '🛡'],
        'build'   => ['name' => '建站开发', 'icon' => '🧩'],
        'support' => ['name' => '技术支持', 'icon' => '🎧'],
        'train'   => ['name' => '培训咨询', 'icon' => '📘'],
    ];

    const STATUS = [
        'pending'   => '待支付',
        'delivered' => '已开通',
        'quoted'    => '待报价',
        'closed'    => '已关闭',
        'cancelled' => '已取消',
    ];

    public static function billName(string $t): string
    {
        return self::BILL_TYPES[$t] ?? $t;
    }

    public static function groupName(string $key): string
    {
        return self::GROUPS[$key]['name'] ?? '其它服务';
    }

    public static function groupIcon(string $key): string
    {
        return self::GROUPS[$key]['icon'] ?? '◆';
    }

    /** 计费方式后缀，如「/年」 */
    public static function billSuffix(string $t): string
    {
        return ['year' => '/年', 'month' => '/月'][$t] ?? '';
    }

    public static function statusName(string $s): string
    {
        return self::STATUS[$s] ?? $s;
    }

    public static function statusBadge(string $s): string
    {
        return [
            'pending'   => 'badge-warn',
            'delivered' => 'badge-ok',
            'quoted'    => 'badge-info',
            'closed'    => 'badge-mute',
            'cancelled' => 'badge-mute',
        ][$s] ?? 'badge-mute';
    }

    /* ------------------------------ 规格与价格 ------------------------------ */

    /** 清洗规格数组（后台表单 / JSON 输入共用） */
    public static function cleanSpecs($raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $s) {
            if (!is_array($s)) {
                continue;
            }
            $name = trim((string)($s['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'  => mb_substr($name, 0, 60),
                'price' => round_money(max(0, (float)($s['price'] ?? 0))),
                'desc'  => mb_substr(trim((string)($s['desc'] ?? '')), 0, 120),
            ];
        }
        return array_slice($out, 0, 12);
    }

    public static function specs(array $item): array
    {
        return self::cleanSpecs($item['specs'] ?? '');
    }

    /** 服务亮点 / 功能清单 */
    public static function features(array $item): array
    {
        $raw = $item['features'] ?? '';
        if (is_string($raw)) {
            $raw = json_decode(trim($raw), true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $f) {
            $f = trim((string)$f);
            if ($f !== '') {
                $out[] = $f;
            }
        }
        return $out;
    }

    /* ------------------------------ 价格计算 ------------------------------ */

    /**
     * 计算某规格下的实际金额
     * 规则：base = item.price；specs 中的 price 为「加价」（覆盖规格价时以传入覆盖值为准）
     * 返回 ['amount'=>float, 'spec'=>array|null]
     */
    public static function quote(array $item, $spec = null): array
    {
        $base   = round_money((float)($item['price'] ?? 0));
        $bill   = (string)($item['bill_type'] ?? 'once');
        $list   = self::specs($item);
        $picked = null;

        if ($list) {
            $key = is_array($spec) ? (string)($spec['name'] ?? '') : (string)$spec;
            foreach ($list as $s) {
                if ($s['name'] === $key) {
                    $picked = $s;
                    break;
                }
            }
            // 未指定或未匹配 → 默认第一档，避免出现「0 元可选」
            if (!$picked) {
                $picked = $list[0];
            }
        }

        $amount = $base;
        if ($picked) {
            $pickedPrice = round_money((float)($picked['price'] ?? 0));
            // 规格价为 0 视为「包含在基础价内」
            if ($pickedPrice > 0) {
                $amount = $pickedPrice;
            }
        }

        if ($bill === 'free' || $bill === 'quote') {
            $amount = 0.0;
        }

        return ['amount' => round_money($amount), 'spec' => $picked];
    }

    /** 规格文字，用于订单 / 记录展示 */
    public static function specText($spec): string
    {
        if (is_array($spec)) {
            return trim((string)($spec['name'] ?? ''));
        }
        return trim((string)$spec);
    }

    /** 价格展示，如「¥299 /年」「面议」 */
    public static function priceLabel(array $item, bool $symbol = false): string
    {
        $bill = (string)($item['bill_type'] ?? 'once');
        if ($bill === 'quote') {
            return '面议';
        }
        $price = round_money((float)($item['price'] ?? 0));
        if ($bill === 'free' || $price <= 0) {
            return '免费';
        }
        return money($price, $symbol) . self::billSuffix($bill);
    }

    /** 按计费方式推算到期日 */
    public static function expireAt(string $bill, string $from = null): ?string
    {
        $days = self::BILL_DAYS[$bill] ?? 0;
        if ($days <= 0) {
            return null;
        }
        $from = $from ?: date('Y-m-d');
        $ts   = strtotime($from . ' +' . $days . ' day');
        return date('Y-m-d', $ts ?: time());
    }

    /* ------------------------------ 应用设置（yx_app.specs） ------------------------------ */

    /**
     * 解析应用的可选「应用设置」
     * 存于 yx_app.specs，结构同服务规格：[{name,price,desc}]
     * price 为该项的加价（0 表示无额外费用）；用于在购买时叠加到基础价。
     */
    public static function appSpecs(array $app): array
    {
        return self::cleanSpecs($app['specs'] ?? '');
    }

    /**
     * 应用购买价格 = 基础价 + 所选设置加价
     * 返回 ['amount'=>float, 'spec'=>array|null, 'extra'=>float]
     */
    public static function appQuote(array $app, $specName = null): array
    {
        $base = round_money((float)($app['price'] ?? 0));
        $list = self::appSpecs($app);
        $picked = null;
        $name = is_array($specName) ? (string)($specName['name'] ?? '') : trim((string)$specName);
        if ($list && $name !== '') {
            foreach ($list as $s) {
                if ($s['name'] === $name) {
                    $picked = $s;
                    break;
                }
            }
        }
        $extra  = $picked ? round_money(max(0, (float)$picked['price'])) : 0.0;
        return ['amount' => round_money($base + $extra), 'spec' => $picked, 'extra' => $extra];
    }

    /* ------------------------------ 数据读取 ------------------------------ */

    public static function all(bool $onlyOn = false): array
    {
        $sql = 'SELECT * FROM ' . YxDb::table('service_item');
        if ($onlyOn) {
            $sql .= ' WHERE status = 1';
        }
        $sql .= ' ORDER BY sort DESC, id ASC';
        return YxDb::all($sql) ?: [];
    }

    public static function find(int $id): ?array
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('service_item') . ' WHERE id = ?', [$id]);
        return $row ?: null;
    }

    /** 按分组归类，供目录页渲染 */
    public static function catalog(bool $onlyOn = true): array
    {
        $out = [];
        foreach (self::all($onlyOn) as $item) {
            $g = (string)($item['group_key'] ?: 'ops');
            if (!isset($out[$g])) {
                $out[$g] = ['key' => $g, 'name' => self::groupName($g), 'icon' => self::groupIcon($g), 'items' => []];
            }
            $out[$g]['items'][] = $item;
        }
        return $out;
    }

    /* ------------------------------ 下单 ------------------------------ */

    /**
     * 创建服务订单
     * $extra 支持 contact / remark / spec(名称或数组) / note
     * 返回 ['ok','msg','order_id','sn','amount','unpaid']
     */
    public static function createOrder(array $item, int $customerId, $spec = null, float $amount = null, array $extra = []): array
    {
        if (empty($item['id'])) {
            return ['ok' => false, 'msg' => '服务项目不存在'];
        }
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '请先登录后再购买'];
        }
        if ((int)($item['status'] ?? 1) !== 1) {
            return ['ok' => false, 'msg' => '该服务已下架'];
        }
        $stock = (int)($item['stock'] ?? -1);
        if ($stock === 0) {
            return ['ok' => false, 'msg' => '该服务已售完'];
        }

        $bill  = (string)($item['bill_type'] ?? 'once');
        $q     = self::quote($item, $spec);
        $total = $amount === null ? $q['amount'] : round_money($amount);
        $specT = self::specText($spec ?: (isset($q['spec']['name']) ? $q['spec']['name'] : ''));
        $name  = (string)$item['name'] . ($specT !== '' ? ' · ' . $specT : '');
        $contact = trim((string)($extra['contact'] ?? ''));
        $remark  = trim((string)($extra['remark'] ?? ''));

        // 报价型：不生成支付订单，仅登记需求
        if ($bill === 'quote') {
            $id = YxDb::insert('service_order', [
                'sn'          => make_sn('SVCQ', 'service_order'),
                'customer_id' => $customerId,
                'item_id'     => (int)$item['id'],
                'name'        => $name,
                'spec_text'   => $specT ?: null,
                'amount'      => 0,
                'bill_type'   => $bill,
                'contact'     => $contact ?: null,
                'remark'      => $remark ?: null,
                'status'      => 'quoted',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            return ['ok' => true, 'msg' => '需求已提交，我们会尽快联系你报价', 'order_id' => 0, 'record_id' => $id, 'unpaid' => false];
        }

        $note = json_encode([
            'service_item_id' => (int)$item['id'],
            'spec'            => $specT,
            'contact'         => $contact,
            'remark'          => $remark,
        ], JSON_UNESCAPED_UNICODE);

        $res = YxBusiness::createOrder([
            'customer_id' => $customerId,
            'type'        => 'service',
            'name'        => $name,
            'amount'      => $total,
            'note'        => $note,
        ]);
        if (empty($res['ok'])) {
            return $res;
        }

        $recordId = YxDb::insert('service_order', [
            'sn'          => make_sn('SVC', 'service_order'),
            'customer_id' => $customerId,
            'item_id'     => (int)$item['id'],
            'order_id'    => (int)$res['order_id'],
            'name'        => $name,
            'spec_text'   => $specT ?: null,
            'amount'      => $total,
            'bill_type'   => $bill,
            'contact'     => $contact ?: null,
            'remark'      => $remark ?: null,
            'status'      => 'pending',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return [
            'ok'       => true,
            'msg'      => $total > 0 ? '订单已创建，请完成支付' : '订单已创建',
            'order_id' => (int)$res['order_id'],
            'record_id'=> $recordId,
            'amount'   => $total,
            'unpaid'   => $total > 0,
        ];
    }

    /**
     * 订单支付成功后的交付（由 YxBusiness::deliverOrder 在事务内调用）
     * 幂等：同一 order_id 已开通则直接返回成功
     */
    public static function deliverByOrder(array $order): array
    {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0) {
            return ['ok' => true];
        }

        $rec = YxDb::one('SELECT * FROM ' . YxDb::table('service_order') . ' WHERE order_id = ?', [$orderId]);
        if (!$rec) {
            // 兼容：老订单未写 service_order，用 order.note 里的 item_id 补建
            $meta   = json_decode((string)($order['note'] ?? ''), true) ?: [];
            $itemId = (int)($meta['service_item_id'] ?? 0);
            $item   = $itemId > 0 ? self::find($itemId) : null;
            if (!$item) {
                return ['ok' => true];
            }
            $specT = (string)($meta['spec'] ?? '');
            $recId = YxDb::insert('service_order', [
                'sn'          => make_sn('SVC', 'service_order'),
                'customer_id' => (int)($order['customer_id'] ?? 0),
                'item_id'     => $itemId,
                'order_id'    => $orderId,
                'name'        => (string)$item['name'] . ($specT !== '' ? ' · ' . $specT : ''),
                'spec_text'   => $specT ?: null,
                'amount'      => round_money($order['total'] ?? 0),
                'bill_type'   => (string)($item['bill_type'] ?? 'once'),
                'contact'     => (string)($meta['contact'] ?? '') ?: null,
                'remark'      => (string)($meta['remark'] ?? '') ?: null,
                'status'      => 'pending',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $rec = YxDb::one('SELECT * FROM ' . YxDb::table('service_order') . ' WHERE id = ?', [$recId]);
            if (!$rec) {
                return ['ok' => true];
            }
        }

        if ((string)$rec['status'] === 'delivered') {
            return ['ok' => true];
        }

        $item     = self::find((int)$rec['item_id']);
        $bill     = (string)($rec['bill_type'] ?? ($item['bill_type'] ?? 'once'));
        $expire   = self::expireAt($bill);
        $customer = (int)$rec['customer_id'];

        // 生成正式业务实例，便于在「我的业务」中统一续费 / 展示
        $serviceId = 0;
        try {
            $serviceId = YxBusiness::createService([
                'customer_id'   => $customer,
                'product_id'    => 0,
                'name'          => (string)$rec['name'],
                'billing_cycle' => in_array($bill, ['month', 'year'], true) ? ($bill === 'year' ? 'year' : 'month') : 'month',
                'price'         => round_money($rec['amount']),
                'status'        => 'active',
                'next_due_date' => $expire,
                'remark'        => '服务中心：' . ($rec['spec_text'] ?: $rec['name']),
            ]);
        } catch (Throwable $e) {
            $serviceId = 0;
        }

        YxDb::update('service_order', [
            'service_id' => $serviceId,
            'status'     => 'delivered',
            'expire_at'  => $expire,
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int)$rec['id']]);

        // 计数：销量 + 库存
        if ($item) {
            YxDb::exec(
                'UPDATE ' . YxDb::table('service_item') . ' SET sales = sales + 1, updated_at = ? WHERE id = ?',
                [now(), (int)$item['id']]
            );
            if ((int)($item['stock'] ?? -1) > 0) {
                YxDb::exec(
                    'UPDATE ' . YxDb::table('service_item') . ' SET stock = stock - 1 WHERE id = ? AND stock > 0',
                    [(int)$item['id']]
                );
            }
        }

        return ['ok' => true, 'service_id' => $serviceId];
    }

    /** 前台「提交需求」入口：无订单，仅登记（报价 / 免费型亦可走此路） */
    public static function requestQuote(array $item, int $customerId, array $extra = []): array
    {
        if (empty($item['id'])) {
            return ['ok' => false, 'msg' => '服务项目不存在'];
        }
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '请先登录'];
        }
        $specT = self::specText($extra['spec'] ?? '');
        $id = YxDb::insert('service_order', [
            'sn'          => make_sn('SVCQ', 'service_order'),
            'customer_id' => $customerId,
            'item_id'     => (int)$item['id'],
            'name'        => (string)$item['name'] . ($specT !== '' ? ' · ' . $specT : ''),
            'spec_text'   => $specT ?: null,
            'amount'      => 0,
            'bill_type'   => (string)($item['bill_type'] ?? 'quote'),
            'contact'     => trim((string)($extra['contact'] ?? '')) ?: null,
            'remark'      => trim((string)($extra['remark'] ?? '')) ?: null,
            'status'      => 'quoted',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return ['ok' => true, 'msg' => '需求已提交，我们会尽快与你联系', 'record_id' => $id];
    }

    /* ------------------------------ 种子数据 ------------------------------ */

    /** 首次安装写入示例服务（已存在则跳过） */
    public static function seedDefaults(): int
    {
        $exists = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service_item'));
        if ($exists > 0) {
            return 0;
        }

        $rows = [
            ['ops', '网站数据迁移', '整站搬迁零停机，含数据库与附件', '🚚', 199, 'once', '次', null,
                ['源站环境勘查', '数据库完整导出/导入', '附件与配置同步', '迁移后 24 小时护航'],
                [['name' => '基础站（≤5GB）', 'price' => 199], ['name' => '中型站（≤30GB）', 'price' => 399], ['name' => '大型站（>30GB）', 'price' => 899]], '热销', 1, 90],
            ['ops', '服务器运维托管', '7×24 监控告警，故障 30 分钟响应', '🖥', 299, 'month', '月', null,
                ['系统安全巡检与加固', '监控告警配置', '定期备份校验', '紧急故障处理'],
                [['name' => '标准版（1 台）', 'price' => 299], ['name' => '专业版（≤5 台）', 'price' => 999]], '推荐', 1, 80],
            ['ops', '网站性能优化', '从 TTFB 到首屏，逐项压缩耗时', '⚡', 599, 'once', '次', null,
                ['慢查询分析与索引优化', '缓存与 CDN 策略', '静态资源压缩', '优化前后对比报告'],
                [], '提升', 0, 70],
            ['safe', '网站安全加固', '按等保思路收敛攻击面', '🛡', 399, 'once', '次', null,
                ['漏洞扫描与修复', '权限与目录加固', 'WAF 规则调优', '安全基线报告'],
                [], null, 1, 85],
            ['safe', '木马后门查杀', '深度查杀并溯源入侵路径', '🦠', 299, 'once', '次', null,
                ['全站文件特征扫描', '后门与 Webshell 清除', '入侵途径排查', '修补并复检'],
                [['name' => '单站点', 'price' => 299], ['name' => '整台服务器', 'price' => 599]], '急修', 1, 88],
            ['safe', '渗透测试', '模拟真实攻击，输出修复清单', '🎯', 1500, 'once', '次', null,
                ['信息收集与端口扫描', '手工漏洞验证', '风险分级与修复建议', '复测报告'],
                [['name' => '单站基础版', 'price' => 1500], ['name' => '多站深度版', 'price' => 3000]], null, 0, 60],
            ['build', '企业官网建站', '响应式设计，含备案协助', '🧩', 1800, 'once', '套', null,
                ['定制化页面设计', '移动端适配', 'SEO 基础优化', '一年免费维护'],
                [['name' => '标准版（5 页）', 'price' => 1800], ['name' => '增强版（10 页）', 'price' => 3200]], '热门', 1, 75],
            ['build', '系统二次开发', '按需定制功能，先评估后报价', '🔧', 0, 'quote', null, null,
                ['需求梳理与方案设计', '开发排期与里程碑', '源码交付与文档', '上线后质保'],
                [], null, 0, 50],
            ['support', '环境部署', 'LNMP / Java / Node 一键就绪', '🧰', 99, 'once', '次', null,
                ['运行环境安装配置', '数据库初始化', 'HTTPS 证书部署', '基础安全检查'],
                [], null, 0, 65],
            ['support', '一对一技术支持', '专属工程师，随时解答', '🎧', 59, 'month', '月', null,
                ['工作日在线响应', '不限次数提问', '远程协助排障'],
                [['name' => '月度', 'price' => 59], ['name' => '季度', 'price' => 159], ['name' => '年度', 'price' => 499]], null, 1, 78],
            ['train', '系统使用培训', '线上直播 + 录播回放', '📘', 299, 'once', '场', null,
                ['控制面板实操', '订单与财务流程', '常见问题处理', '培训资料包'],
                [], null, 0, 40],
        ];

        $n = 0;
        foreach ($rows as $r) {
            YxDb::insert('service_item', [
                'group_key'   => $r[0],
                'name'        => $r[1],
                'subtitle'    => $r[2],
                'icon'        => $r[3],
                'price'       => $r[4],
                'bill_type'   => $r[5],
                'unit'        => $r[6],
                'stock'       => -1,
                'delivery'    => $r[7],
                'description' => $r[2],
                'features'    => json_encode($r[8], JSON_UNESCAPED_UNICODE),
                'specs'       => $r[9] ? json_encode($r[9], JSON_UNESCAPED_UNICODE) : null,
                'badge'       => $r[10],
                'featured'    => $r[11],
                'sales'       => $r[12],
                'sort'        => 100 - $n,
                'status'      => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $n++;
        }
        return $n;
    }
}
