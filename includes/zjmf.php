<?php
/**
 * 优享财务系统 · 魔方财务（ZJMF）兼容对接
 *
 * 本站作为「上游 / 被对接方」对外提供能力，对接方（魔方财务或其分销端）
 * 只需配置三项即可对接：
 *   api_name  接口名称（自定义，双方约定）
 *   api_url   接口地址（默认指向本站 /api/zjmf.php）
 *   api_key   API 密钥（后台生成，加密存储）
 *
 * 对外通道：
 *   /cart/prolist   公开商品树（无需密钥，受「API 总开关」约束）
 *   /api/zjmf.php   鉴权接口：商品 / 产品详情 / 下单 / 订单查询
 *   /api/v1.php     既有开放接口（同样受总开关约束）
 *
 * 三个开关（yx_setting）：
 *   api_enabled           API 总开关，关闭后所有对外接口返回 503
 *   api_require_realname  下单时强制客户已实名认证
 *   api_require_phone     下单时强制客户已绑定手机号
 */

class YxZjmf
{
    /** 总开关（复用既有设置键，历史值 1 = 开启） */
    const SET_ENABLED  = 'api_enabled';
    /** 下单实名校验 */
    const SET_REALNAME = 'api_require_realname';
    /** 下单手机校验 */
    const SET_PHONE    = 'api_require_phone';

    const SET_NAME     = 'zjmf_api_name';
    const SET_URL      = 'zjmf_api_url';
    const SET_KEY      = 'zjmf_api_key';

    /** 开放接口日志里使用的应用标识 */
    const APPID = 'zjmf';

    /** 开关清单（后台渲染与批量校验共用） */
    const SWITCHES = [
        self::SET_ENABLED => [
            'label' => 'API 总开关',
            'desc'  => '关闭后 /api/v1.php、/api/zjmf.php、/cart/prolist 全部返回 503，下游无法取数与下单',
        ],
        self::SET_REALNAME => [
            'label' => '实名认证开关',
            'desc'  => '开启后，接口下单的客户必须已完成实名认证（realname_status = verified）',
        ],
        self::SET_PHONE => [
            'label' => '绑定手机号开关',
            'desc'  => '开启后，接口下单的客户必须已绑定手机号（customer.phone 非空）',
        ],
    ];

    /* ------------------------------------------------------------------ 开关 */

    /** 读取开关：api_enabled 历史默认 1，其余默认 0（不改变既有行为） */
    public static function switchOn(string $key): bool
    {
        $default = $key === self::SET_ENABLED ? '1' : '0';
        return trim((string)setting($key, $default)) === '1';
    }

    /** 写开关（仅接受已知键，避免误写任意设置） */
    public static function setSwitch(string $key, bool $on): void
    {
        if (!isset(self::SWITCHES[$key])) {
            return;
        }
        set_setting($key, $on ? '1' : '0');
    }

    /** 三个开关的当前状态，供后台展示 */
    public static function switchStates(): array
    {
        $out = [];
        foreach (self::SWITCHES as $key => $meta) {
            $out[$key] = ['on' => self::switchOn($key), 'label' => $meta['label'], 'desc' => $meta['desc']];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ 对接配置 */

    /** 接口名称：后台未配置时回落到站点名 */
    public static function apiName(): string
    {
        $name = trim((string)setting(self::SET_NAME, ''));
        return $name !== '' ? $name : (string)setting('site_name', config('app.name', '优享财务'));
    }

    /** 接口地址：后台未配置时回落到本站 /api/zjmf.php */
    public static function apiUrl(): string
    {
        $url = trim((string)setting(self::SET_URL, ''));
        if ($url !== '') {
            return $url;
        }
        return rtrim(base_url(), '/') . '/api/zjmf.php';
    }

    /** API 密钥（解密后明文）；$create 为 true 且密钥不存在时自动生成 */
    public static function apiKey(bool $create = false): string
    {
        $cipher = trim((string)setting(self::SET_KEY, ''));
        $plain  = $cipher === '' ? '' : yx_decrypt($cipher);
        if ($plain === '' && $create) {
            $plain = random_nonce(32);
            set_setting(self::SET_KEY, yx_encrypt($plain));
        }
        return $plain;
    }

    /** 重置密钥：返回新明文，旧密钥立即失效 */
    public static function rotateKey(): string
    {
        $plain = random_nonce(32);
        set_setting(self::SET_KEY, yx_encrypt($plain));
        return $plain;
    }

    /** 脱敏展示：仅保留前 4 与后 4 位 */
    public static function keyMasked(): string
    {
        $k = self::apiKey();
        if ($k === '') {
            return '未生成';
        }
        if (strlen($k) <= 12) {
            return str_repeat('•', strlen($k));
        }
        return substr($k, 0, 4) . str_repeat('•', 8) . substr($k, -4);
    }

    /* ------------------------------------------------------------------ 鉴权 */

    /**
     * 校验 api_name / api_key
     * @return array ['ok'=>bool,'code'=>int,'msg'=>string]
     */
    public static function auth(array $req): array
    {
        if (!self::switchOn(self::SET_ENABLED)) {
            return ['ok' => false, 'code' => 503, 'msg' => '接口已关闭'];
        }
        $name = trim((string)($req['api_name'] ?? $req['name'] ?? ''));
        $key  = trim((string)($req['api_key'] ?? $req['key'] ?? ''));
        if ($name === '' || $key === '') {
            return ['ok' => false, 'code' => 400, 'msg' => '缺少必要参数：api_name / api_key'];
        }
        $expect = self::apiKey();
        if ($expect === '') {
            return ['ok' => false, 'code' => 503, 'msg' => '接口密钥未配置，请在后台「接口对接」中生成'];
        }
        if (!hash_equals(self::apiName(), $name) || !hash_equals($expect, $key)) {
            return ['ok' => false, 'code' => 401, 'msg' => '接口名称或 API 密钥不正确'];
        }
        return ['ok' => true, 'code' => 200, 'msg' => 'ok'];
    }

    /* ------------------------------------------------------------------ 商品树 */

    /**
     * 魔方前台购物车式商品树：分类 -> 商品组 -> 商品
     *
     * 与真实魔方 /cart/prolist 的 data.fgs[].group[].products[] 结构一致；
     * 本站产品未分类（group_id = 0）时归入「默认分类 / 默认商品组」，
     * 保证下游拿到的永远是三层结构，不需要判空分支。
     */
    public static function productTree(): array
    {
        $groups = [];
        foreach (YxDb::all('SELECT * FROM ' . YxDb::table('product_group') . ' WHERE status = 1 ORDER BY sort ASC, id ASC') as $g) {
            $groups[(int)$g['id']] = $g;
        }

        $byGroup = [];
        foreach (YxDb::all('SELECT * FROM ' . YxDb::table('product') . ' WHERE status = 1 ORDER BY sort ASC, id ASC') as $p) {
            $gid = (int)$p['group_id'];
            if (!isset($groups[$gid])) {
                $gid = 0;      // 未分类 / 分类已下架：归入默认分类
            }
            $byGroup[$gid][] = $p;
        }

        $fgs = [];
        foreach ($groups as $gid => $g) {
            if (empty($byGroup[$gid])) {
                continue;
            }
            $fgs[] = [
                'id'             => (int)$g['id'],
                'name'           => (string)$g['name'],
                'headline'       => '',
                'tagline'        => '',
                'order'          => (int)$g['sort'],
                'gid'            => (int)$g['id'],
                'order_frm_tpl'  => '',
                'tpl_type'       => '',
                'products'       => self::productRows($byGroup[$gid]),
            ];
        }
        if (!empty($byGroup[0])) {
            $fgs[] = [
                'id'             => 0,
                'name'           => '默认分类',
                'headline'       => '',
                'tagline'        => '',
                'order'          => 0,
                'gid'            => 0,
                'order_frm_tpl'  => '',
                'tpl_type'       => '',
                'products'       => self::productRows($byGroup[0]),
            ];
        }
        return ['fgs' => $fgs];
    }

    /** 商品行：键名对齐魔方购物车，另附 code 与全周期价格便于下游展示 */
    private static function productRows(array $products): array
    {
        $rows = [];
        foreach ($products as $p) {
            // 只输出该产品真正可以下单的周期：下游据此生成购买选项，避免买到越权周期
            $allowed = YxBusiness::allowedCycles($p);
            $cycles  = [];
            foreach (YxBusiness::CYCLES as $k => $c) {
                if (!in_array($k, $allowed, true)) {
                    continue;
                }
                $cycles[] = [
                    'cycle'     => $k,
                    'name'      => $c['name'],
                    'months'    => $c['months'],
                    'price'     => YxBusiness::cyclePrice($p, $k),
                    'setup_fee' => (float)$p['setup_fee'],
                ];
            }
            $defaultCycle = YxBusiness::defaultCycle($p);
            $rows[] = [
                'id'                => (int)$p['id'],
                'type'              => (string)$p['type'],
                'gid'               => (int)$p['group_id'],
                'name'              => (string)$p['name'],
                'description'       => (string)($p['description'] ?? ''),
                'stock_control'     => (int)$p['stock'] >= 0 ? 1 : 0,
                'qty'               => (int)$p['stock'],   // -1 = 不限量，0 = 售罄
                'ontrial'           => 0,
                'product_price'     => (float)$p['price'],
                'setup_fee'         => (float)$p['setup_fee'],
                'billingcycle'      => $defaultCycle,
                'billingcycle_zh'   => YxBusiness::cycleName($defaultCycle),
                'code'              => trim((string)($p['code'] ?? '')) !== '' ? (string)$p['code'] : (string)$p['id'],
                // cycle_limit 只在收窄过周期时出现：空表示四周期全开，下游按老逻辑处理即可
                'cycle_limit'       => count($allowed) < count(YxBusiness::CYCLES) ? implode(',', $allowed) : '',
                'billingcycles'     => $cycles,
            ];
        }
        return $rows;
    }

    /** 扁平产品列表（鉴权接口用） */
    public static function productList(): array
    {
        $list = [];
        foreach (self::productTree()['fgs'] as $fg) {
            foreach ($fg['products'] as $p) {
                $list[] = [
                    'product_id'  => $p['id'],
                    'code'        => $p['code'],
                    'name'        => $p['name'],
                    'type'        => $p['type'],
                    'group'       => $fg['name'],
                    'price'       => $p['product_price'],
                    'setup_fee'   => $p['setup_fee'],
                    'stock'       => $p['qty'],
                    'description' => $p['description'],
                ];
            }
        }
        return $list;
    }

    private static function resolveProduct(array $req): ?array
    {
        $id   = (int)($req['product_id'] ?? $req['id'] ?? 0);
        $code = trim((string)($req['product_code'] ?? $req['code'] ?? ''));
        if ($id > 0) {
            return YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ? AND status = 1', [$id]);
        }
        if ($code !== '') {
            $row = YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE code = ? AND status = 1', [$code]);
            if (!$row && ctype_digit($code)) {
                $row = YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ? AND status = 1', [(int)$code]);
            }
            return $row;
        }
        return null;
    }

    /* ------------------------------------------------------------------ 客户与下单校验 */

    /** 实名 / 手机开关校验 */
    public static function checkCustomer(array $customer): array
    {
        if (self::switchOn(self::SET_REALNAME) && !yx_customer_verified($customer)) {
            return ['ok' => false, 'msg' => '该客户尚未完成实名认证，无法下单'];
        }
        if (self::switchOn(self::SET_PHONE) && trim((string)($customer['phone'] ?? '')) === '') {
            return ['ok' => false, 'msg' => '该客户未绑定手机号，无法下单'];
        }
        return ['ok' => true, 'msg' => 'ok'];
    }

    /**
     * 解析下单终端用户：customer_id / external_user_id / email 三选一
     * email 命中不到客户时自动建档（下游首次下单常见场景）
     */
    public static function resolveCustomer(array $req): array
    {
        $cid   = (int)($req['customer_id'] ?? 0);
        $ext   = trim((string)($req['external_user_id'] ?? $req['client_id'] ?? ''));
        $email = trim((string)($req['email'] ?? ''));
        $phone = trim((string)($req['phone'] ?? ''));

        if ($cid > 0) {
            $c = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
            return $c ? ['ok' => true, 'customer' => $c] : ['ok' => false, 'msg' => '客户不存在：' . $cid];
        }

        if ($ext !== '') {
            $map = YxDb::one(
                'SELECT * FROM ' . YxDb::table('api_customer_map') . ' WHERE appid = ? AND external_id = ?',
                [self::APPID, $ext]
            );
            if ($map && (int)$map['customer_id'] > 0) {
                $c = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?', [(int)$map['customer_id']]);
                return $c ? ['ok' => true, 'customer' => $c] : ['ok' => false, 'msg' => '映射客户不存在：' . $ext];
            }
        }

        if ($email !== '') {
            $c = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE email = ? LIMIT 1', [$email]);
            if (!$c) {
                $created = self::createCustomer($email, $phone, $req);
                if (!$created['ok']) {
                    return $created;
                }
                $c = $created['customer'];
            }
            return ['ok' => true, 'customer' => $c];
        }

        return ['ok' => false, 'msg' => '缺少终端用户标识：请提供 email 或 external_user_id'];
    }

    /** 自动建档（下游终端用户首次下单） */
    private static function createCustomer(string $email, string $phone, array $req): array
    {
        $username = 'zjmf_' . substr(md5($email . microtime()), 0, 12);
        $suffix   = 0;
        while (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE username = ?', [$username])) {
            $suffix++;
            $username = 'zjmf_' . substr(md5($email . microtime() . $suffix), 0, 12);
            if ($suffix > 50) {
                return ['ok' => false, 'msg' => '客户名生成失败，请提供 customer_id'];
            }
        }
        $cid = customer_insert([
            'username' => $username,
            'email'    => $email,
            'phone'    => $phone,
            'company'  => trim((string)($req['company'] ?? '')),
            'password' => YxAuth::hash(random_nonce(16)),
            'balance'  => 0,
            'credit'   => 0,
            'points'   => 0,
            'level'    => 'normal',
            'status'   => 1,
            'reg_ip'   => client_ip(),
            'remark'   => '由魔方财务接口自动建档（' . self::APPID . '）',
            'created_at' => now(),
        ]);
        $c = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
        return $c ? ['ok' => true, 'customer' => $c] : ['ok' => false, 'msg' => '客户建档失败'];
    }

    /** 创建订单（魔方财务下单入口） */
    public static function createOrder(array $req): array
    {
        if (!self::switchOn(self::SET_ENABLED)) {
            return ['status' => 503, 'msg' => '接口已关闭', 'data' => null];
        }
        $product = self::resolveProduct($req);
        if (!$product) {
            return ['status' => 404, 'msg' => '产品不存在或已下架', 'data' => null];
        }
        if ((int)$product['stock'] === 0) {
            return ['status' => 403, 'msg' => '产品库存不足', 'data' => null];
        }

        $cust = self::resolveCustomer($req);
        if (!$cust['ok']) {
            return ['status' => 400, 'msg' => $cust['msg'], 'data' => null];
        }
        $customer = $cust['customer'];

        $check = self::checkCustomer($customer);
        if (!$check['ok']) {
            return ['status' => 403, 'msg' => $check['msg'], 'data' => null];
        }

        $cycle = (string)($req['cycle'] ?? $product['cycle']);
        if (!isset(YxBusiness::CYCLES[$cycle])) {
            $cycle = $product['cycle'];
        }
        if (!YxBusiness::cycleAllowed($product, $cycle)) {
            return ['status' => 400, 'msg' => '该产品不支持「' . YxBusiness::cycleName($cycle) . '」计费周期', 'data' => null];
        }

        $externalSn = trim((string)($req['external_sn'] ?? $req['out_trade_no'] ?? ''));
        if ($externalSn !== '') {
            $exist = YxDb::one(
                'SELECT * FROM ' . YxDb::table('order') . ' WHERE appid = ? AND external_sn = ? LIMIT 1',
                [self::APPID, $externalSn]
            );
            if ($exist) {
                return ['status' => 200, 'msg' => '订单已存在（按 external_sn 幂等返回）', 'data' => self::orderData($exist)];
            }
        }

        $amount = YxBusiness::cyclePrice($product, $cycle) + (float)$product['setup_fee'];
        if ($amount <= 0) {
            return ['status' => 400, 'msg' => '该产品为 0 元商品，请在后台人工报价后开放', 'data' => null];
        }

        $created = YxBusiness::createOrder([
            'customer_id' => (int)$customer['id'],
            'type'        => 'new',
            'product_id'  => (int)$product['id'],
            'name'        => $product['name'] . '（' . YxBusiness::cycleName($cycle) . '）',
            'amount'      => $amount,
            'billing_cycle' => $cycle,
            'note'        => '魔方财务接口下单',
            'remote_code' => trim((string)($req['remote_code'] ?? '')),
        ]);
        if (empty($created['ok'])) {
            return ['status' => 500, 'msg' => $created['msg'] ?? '下单失败', 'data' => null];
        }

        YxDb::update('order', [
            'source'      => self::APPID,
            'appid'       => self::APPID,
            'external_sn' => $externalSn !== '' ? $externalSn : null,
        ], 'id = :id', ['id' => (int)$created['order_id']]);

        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$created['order_id']]);
        return ['status' => 200, 'msg' => '下单成功', 'data' => self::orderData($order)];
    }

    public static function orderData(array $order): array
    {
        return [
            'order_id'    => (int)$order['id'],
            'order_sn'    => $order['sn'],
            'type'        => $order['type'],
            'name'        => $order['name'],
            'amount'      => (float)$order['amount'],
            'total'       => (float)$order['total'],
            'status'      => $order['status'],
            'status_text' => YxBusiness::orderStatusName($order['status']),
            'pay_method'  => $order['pay_method'],
            'service_id'  => (int)$order['service_id'],
            'external_sn' => $order['external_sn'],
            'customer_id' => (int)$order['customer_id'],
            'billing_cycle' => $order['billing_cycle'],
            'created_at'  => $order['created_at'],
            'paid_at'     => $order['paid_at'],
        ];
    }

    /** 订单查询：仅允许查询本接口创建的订单，或已映射终端用户的订单 */
    public static function findOrder(array $req): ?array
    {
        $sn = trim((string)($req['order_sn'] ?? $req['out_trade_no'] ?? ''));
        if ($sn === '') {
            return null;
        }
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE sn = ? LIMIT 1', [$sn]);
        if (!$order) {
            return null;
        }
        if ((string)$order['appid'] === self::APPID) {
            return $order;
        }
        $ext = trim((string)($req['external_user_id'] ?? ''));
        if ($ext !== '') {
            $map = YxDb::one(
                'SELECT customer_id FROM ' . YxDb::table('api_customer_map') . ' WHERE appid = ? AND external_id = ?',
                [self::APPID, $ext]
            );
            if ($map && (int)$map['customer_id'] === (int)$order['customer_id']) {
                return $order;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ 响应与日志 */

    /** 统一响应体：{"status":200,"msg":"ok","data":..,"ts":..} */
    public static function respond($data = null, int $status = 200, string $msg = 'ok'): array
    {
        return ['status' => $status, 'msg' => $msg, 'data' => $data, 'ts' => time()];
    }

    /** 落调用日志（失败不影响主流程） */
    public static function log(string $action, int $status, string $msg, array $params = []): void
    {
        try {
            YxDb::insert('api_log', [
                'appid'      => self::APPID,
                'action'     => 'zjmf.' . $action,
                'ip'         => client_ip(),
                'code'       => $status,
                'msg'        => mb_substr($msg, 0, 255),
                'cost_ms'    => 0,
                'params'     => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // 日志失败不影响业务
        }
    }
}
