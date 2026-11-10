<?php
/**
 * 优享财务系统 · 下游开放接口（OpenAPI）
 *
 * 下游 = 接入我们的代理商 / 分销系统。我方以 appid + secret 鉴权，按动作开放能力。
 *
 * 鉴权要素：
 *   appid   应用标识
 *   ts      Unix 时间戳（±300 秒内有效）
 *   nonce   随机串（同 appid 内一次性，防重放）
 *   action  动作名
 *   sign    HMAC-SHA256(规范化参数串, secret)
 *           规范化：取除 sign 外的所有请求参数（仅一级标量），过滤空值，按 key 升序，
 *                   拼成 k=v&k=v… 后做 HMAC-SHA256，输出小写十六进制
 *
 * 安全：验签 → 时效 → 防重放 → IP 白名单 → 动作白名单 → 频次限制 → 数据范围隔离 → 全量调用日志
 */

class YxApi
{
    /* 返回码 */
    const OK            = 0;
    const ERR_PARAM     = 400;
    const ERR_SIGN      = 401;
    const ERR_FORBIDDEN = 403;
    const ERR_NOTFOUND  = 404;
    const ERR_RATELIMIT = 429;
    const ERR_SERVER    = 500;

    /** 时间戳容差（秒） */
    const TS_WINDOW = 300;

    /** 动作清单（含所需参数说明，供文档与后台勾选用） */
    public static function actions(): array
    {
        return [
            'ping'            => ['name' => '连通性测试', 'params' => ''],
            'product.list'    => ['name' => '产品列表', 'params' => 'group_id?、keyword?'],
            'product.detail'  => ['name' => '产品详情', 'params' => 'product_id 或 product_code'],
            'order.create'    => ['name' => '创建订单', 'params' => 'product_id/code、cycle、external_user_id、external_sn?'],
            'order.pay'       => ['name' => '支付订单', 'params' => 'order_sn、method=balance|manual'],
            'order.query'     => ['name' => '查询订单', 'params' => 'order_sn'],
            'order.list'      => ['name' => '订单列表', 'params' => 'external_user_id?、status?、page?、limit?'],
            'service.list'    => ['name' => '业务列表', 'params' => 'external_user_id?、status?、page?、limit?'],
            'service.detail'  => ['name' => '业务详情', 'params' => 'service_sn'],
            'service.renew'   => ['name' => '业务续费', 'params' => 'service_sn、cycle?'],
            'service.operate' => ['name' => '业务操作', 'params' => 'service_sn、op=start|stop|reboot|reinstall|suspend|unsuspend|destroy|sync'],
            'service.sync'    => ['name' => '同步上游状态', 'params' => 'service_sn'],
            'balance.query'   => ['name' => '余额查询', 'params' => 'external_user_id?'],
            'license.query'   => ['name' => '域名授权查询', 'params' => 'domain'],
        ];
    }

    public static function isKnownAction(string $action): bool
    {
        return isset(self::actions()[$action]);
    }

    /* ------------------------------------------------------------------ 签名 */

    /**
     * 生成签名（下单/回调/上游共用同一算法，便于下游实现一致）
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign'], $params['_csrf']);
        $flat = [];
        foreach ($params as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;   // 只对一级标量签名
            }
            if ($v === null || $v === '') {
                continue;
            }
            $flat[(string)$k] = (string)$v;
        }
        ksort($flat);
        $pairs = [];
        foreach ($flat as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    /* ------------------------------------------------------------------ 鉴权 */

    /**
     * 校验请求
     * @return array ['ok'=>bool,'code'=>int,'msg'=>string,'app'=>?array]
     */
    public static function auth(array $req): array
    {
        $appid  = trim((string)($req['appid'] ?? ''));
        $action = trim((string)($req['action'] ?? ''));
        $ts     = trim((string)($req['ts'] ?? ''));
        $nonce  = trim((string)($req['nonce'] ?? ''));
        $sign   = trim((string)($req['sign'] ?? ''));

        if ($appid === '' || $action === '' || $ts === '' || $nonce === '' || $sign === '') {
            return ['ok' => false, 'code' => self::ERR_PARAM, 'msg' => '缺少必要参数：appid / action / ts / nonce / sign'];
        }
        if (!preg_match('/^\d{1,12}$/', $ts)) {
            return ['ok' => false, 'code' => self::ERR_PARAM, 'msg' => 'ts 必须为 Unix 时间戳'];
        }
        if (abs(time() - (int)$ts) > self::TS_WINDOW) {
            return ['ok' => false, 'code' => self::ERR_SIGN, 'msg' => '请求时间戳超出允许范围（±' . self::TS_WINDOW . ' 秒），请校准服务器时间'];
        }
        if (strlen($nonce) < 8 || strlen($nonce) > 64) {
            return ['ok' => false, 'code' => self::ERR_PARAM, 'msg' => 'nonce 长度需为 8-64 个字符'];
        }
        if (!self::isKnownAction($action)) {
            return ['ok' => false, 'code' => self::ERR_PARAM, 'msg' => '未知动作：' . $action];
        }

        $app = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ? LIMIT 1', [$appid]);
        if (!$app) {
            return ['ok' => false, 'code' => self::ERR_SIGN, 'msg' => 'appid 不存在'];
        }
        if ((int)$app['status'] !== 1) {
            return ['ok' => false, 'code' => self::ERR_FORBIDDEN, 'msg' => '该应用已被禁用', 'app' => $app];
        }

        $secret = yx_decrypt((string)$app['secret']);
        if ($secret === '' || !hash_equals(self::sign($req, $secret), $sign)) {
            return ['ok' => false, 'code' => self::ERR_SIGN, 'msg' => '签名校验失败', 'app' => $app];
        }

        // IP 白名单
        if (!self::ipAllowed((string)$app['ip_whitelist'], client_ip())) {
            return ['ok' => false, 'code' => self::ERR_FORBIDDEN, 'msg' => '当前 IP 不在白名单内：' . client_ip(), 'app' => $app];
        }

        // 动作白名单
        $allow = trim((string)$app['allow_actions']);
        if ($allow !== '') {
            $list = array_filter(array_map('trim', explode(',', $allow)));
            if (!in_array($action, $list, true) && $action !== 'ping') {
                return ['ok' => false, 'code' => self::ERR_FORBIDDEN, 'msg' => '该应用未授权调用动作：' . $action, 'app' => $app];
            }
        }

        // 频次限制
        $limit = (int)$app['rate_limit'];
        if ($limit > 0) {
            $used = (int)YxDb::value(
                'SELECT COUNT(*) FROM ' . YxDb::table('api_log') . ' WHERE appid = ? AND created_at >= ?',
                [$appid, date('Y-m-d H:i:s', time() - 60)]
            );
            if ($used >= $limit) {
                return ['ok' => false, 'code' => self::ERR_RATELIMIT, 'msg' => '调用过于频繁，每分钟上限 ' . $limit . ' 次', 'app' => $app];
            }
        }

        // 防重放
        if (self::nonceSeen($appid, $nonce)) {
            return ['ok' => false, 'code' => self::ERR_SIGN, 'msg' => 'nonce 已被使用（疑似重放请求）', 'app' => $app];
        }
        self::rememberNonce($appid, $nonce);

        return ['ok' => true, 'code' => self::OK, 'msg' => 'ok', 'app' => $app];
    }

    public static function ipAllowed(string $whitelist, string $ip): bool
    {
        $whitelist = trim($whitelist);
        if ($whitelist === '') {
            return true;    // 未配置即不限制
        }
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;   // 取不到合法来源 IP 时不放行
        }
        foreach (explode(',', $whitelist) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if ($rule === $ip) {
                return true;
            }
            // 支持 1.2.3.* / 1.2.* 这类前缀通配：按「段」匹配，避免 1.2.3.* 命中 1.2.30.4
            if (strpos($rule, '*') !== false) {
                $prefix = rtrim($rule, '*');
                if ($prefix === '') {
                    return true;
                }
                $prefix = rtrim($prefix, '.');
                if (strpos($ip, $prefix . '.') === 0) {
                    return true;
                }
                // 通配落在段中间（如 1.2.*.4）时退化为按段逐项比对
                if (strpos($prefix, '*') !== false && self::wildcardMatch($rule, $ip)) {
                    return true;
                }
            }
            // 支持 a.b.c.d/nn 网段（IPv4 / IPv6 均可）
            if (strpos($rule, '/') !== false) {
                if (self::cidrMatch($rule, $ip)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 通配符按「段」匹配：* 只匹配一个或多个完整的段 */
    private static function wildcardMatch(string $rule, string $ip): bool
    {
        $rs = explode('.', $rule);
        $is = explode('.', $ip);
        if (count($rs) !== count($is)) {
            return false;
        }
        foreach ($rs as $i => $seg) {
            if ($seg === '*') {
                continue;
            }
            if ($seg !== $is[$i]) {
                return false;
            }
        }
        return true;
    }

    /**
     * CIDR 匹配（IPv4 / IPv6）
     *
     * 早期实现用 ip2long 处理，IPv6 会得到 false → 两侧都为 0 → 整段放行，
     * 等于白名单被绕过；这里统一用二进制前缀比较，并按地址族确定最大位宽。
     */
    private static function cidrMatch(string $rule, string $ip): bool
    {
        [$net, $bits] = array_pad(explode('/', $rule, 2), 2, '');
        $net  = trim($net);
        $bits = trim($bits);
        if (!filter_var($net, FILTER_VALIDATE_IP) || !filter_var($ip, FILTER_VALIDATE_IP) || !ctype_digit($bits)) {
            return false;
        }
        $netBin = inet_pton($net);
        $ipBin  = inet_pton($ip);
        if ($netBin === false || $ipBin === false || strlen($netBin) !== strlen($ipBin)) {
            return false;   // 地址族不同（v4 规则不匹配 v6 地址，反之亦然）
        }
        $max  = strlen($netBin) * 8;
        $bits = (int)$bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($netBin, 0, $full) !== substr($ipBin, 0, $full)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($netBin[$full]) & $mask) === (ord($ipBin[$full]) & $mask);
    }

    private static function nonceSeen(string $appid, string $nonce): bool
    {
        return (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('api_nonce') . ' WHERE appid = ? AND nonce = ?',
            [$appid, $nonce]
        ) > 0;
    }

    private static function rememberNonce(string $appid, string $nonce): void
    {
        YxDb::insert('api_nonce', ['appid' => $appid, 'nonce' => $nonce, 'created_at' => now()]);
    }

    /** 清理过期 nonce 与调用日志 */
    public static function purge(): array
    {
        $n = YxDb::exec('DELETE FROM ' . YxDb::table('api_nonce') . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - self::TS_WINDOW * 4)]);
        $l = YxDb::exec('DELETE FROM ' . YxDb::table('api_log') . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
        return ['nonce' => $n, 'log' => $l];
    }

    /* ------------------------------------------------------------------ 数据范围 */

    /** 该应用可访问的客户 ID；null 表示不限制（admin 型应用） */
    public static function scopeCustomerIds(array $app): ?array
    {
        $type = (string)$app['owner_type'];
        $oid  = (int)$app['owner_id'];
        if ($type === 'admin') {
            return null;
        }
        if ($type === 'agent') {
            $ids = [];
            foreach (YxDb::all('SELECT id FROM ' . YxDb::table('customer') . ' WHERE agent_id = ?', [$oid]) as $r) {
                $ids[] = (int)$r['id'];
            }
            return $ids;
        }
        return [$oid > 0 ? $oid : -1];
    }

    /** 判断客户是否在应用范围内 */
    public static function inScope(array $app, int $customerId): bool
    {
        $scope = self::scopeCustomerIds($app);
        return $scope === null || in_array($customerId, $scope, true);
    }

    /**
     * 解析下游终端用户 → 我们的客户 ID
     * agent / admin 型应用按 (appid, external_id) 建立映射，首次调用自动建档
     */
    public static function resolveCustomer(array $app, string $externalId, array $extra = []): ?int
    {
        $externalId = trim($externalId);
        $appid      = (string)$app['appid'];
        $type       = (string)$app['owner_type'];

        // 客户自用型应用：external_id 可省略，直接落在自己名下
        if ($type === 'customer') {
            if ($externalId !== '') {
                $map = YxDb::one(
                    'SELECT customer_id FROM ' . YxDb::table('api_customer_map') . ' WHERE appid = ? AND external_id = ?',
                    [$appid, $externalId]
                );
                if ($map) {
                    return (int)$map['customer_id'];
                }
            }
            return (int)$app['owner_id'];
        }

        if ($externalId === '') {
            return null;
        }

        $map = YxDb::one(
            'SELECT customer_id FROM ' . YxDb::table('api_customer_map') . ' WHERE appid = ? AND external_id = ?',
            [$appid, $externalId]
        );
        if ($map && $map['customer_id'] > 0) {
            return (int)$map['customer_id'];
        }

        // 自动建档
        $safe = preg_replace('/[^A-Za-z0-9_\x{4e00}-\x{9fa5}]/u', '_', $externalId);
        $safe = mb_substr($safe, 0, 20);
        $username = 'api_' . substr($appid, 0, 6) . '_' . $safe;
        $suffix = 0;
        while (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE username = ?', [$username])) {
            $suffix++;
            $username = 'api_' . substr($appid, 0, 6) . '_' . $safe . '_' . $suffix;
            if ($suffix > 50) {
                $username = 'api_' . substr($appid, 0, 6) . '_' . substr(md5($externalId . microtime()), 0, 10);
                break;
            }
        }

        $cid = customer_insert([
            'username'   => $username,
            'email'      => (string)($extra['email'] ?? ($safe . '@api.local')),
            'phone'      => (string)($extra['phone'] ?? ''),
            'company'    => (string)($extra['company'] ?? ''),
            'password'   => YxAuth::hash(random_nonce(16)),
            'balance'    => 0,
            'credit'     => 0,
            'points'     => 0,
            'level'      => 'normal',
            'agent_id'   => $type === 'agent' ? (int)$app['owner_id'] : 0,
            'status'     => 1,
            'reg_ip'     => client_ip(),
            'remark'     => '由开放接口自动建档（appid ' . $appid . '）',
            'created_at' => now(),
        ]);

        YxDb::insert('api_customer_map', [
            'appid'       => $appid,
            'external_id' => $externalId,
            'customer_id' => $cid,
            'created_at'  => now(),
        ]);

        return $cid;
    }

    /* ------------------------------------------------------------------ 日志与输出 */

    public static function log(string $appid, string $action, int $code, string $msg, int $costMs, array $params = []): void
    {
        $safe = $params;
        unset($safe['sign'], $safe['secret']);
        try {
            YxDb::insert('api_log', [
                'appid'      => $appid ?: null,
                'action'     => $action ?: null,
                'ip'         => client_ip(),
                'code'       => $code,
                'msg'        => mb_substr($msg, 0, 255),
                'cost_ms'    => $costMs,
                'params'     => $safe ? json_encode($safe, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
            if ($appid !== '') {
                YxDb::exec(
                    'UPDATE ' . YxDb::table('api_app') . ' SET call_count = call_count + 1, last_call_at = ? WHERE appid = ?',
                    [now(), $appid]
                );
            }
        } catch (Throwable $e) {
            // 日志失败不影响业务
        }
    }

    /** 统一响应体；$data 允许为 null（鉴权失败、无返回体的动作），调用方据此免去强转 */
    public static function respond(?array $data = null, int $code = self::OK, string $msg = 'ok'): array
    {
        return ['code' => $code, 'msg' => $msg, 'data' => $data, 'ts' => time()];
    }

    /* ------------------------------------------------------------------ 回调通知 */

    /** 向下游推送事件（入队，由任务队列负责重试） */
    public static function notify(string $appid, string $event, array $data): void
    {
        $app = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ?', [$appid]);
        if (!$app || trim((string)$app['notify_url']) === '') {
            return;
        }
        YxTask::push('downstream.notify', [
            'ref_type' => 'api_app',
            'ref_id'   => (int)$app['id'],
            'payload'  => ['appid' => $appid, 'event' => $event, 'data' => $data],
            'unique'   => false,     // 事件通知不去重，每次都推
        ]);
    }
}

/**
 * 下游回调投递器
 */
class YxApiNotify
{
    public static function send(array $payload): array
    {
        $appid = (string)($payload['appid'] ?? '');
        $event = (string)($payload['event'] ?? '');
        $data  = $payload['data'] ?? [];

        $app = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ?', [$appid]);
        if (!$app) {
            return ['ok' => false, 'msg' => '应用不存在：' . $appid];
        }
        $url = trim((string)$app['notify_url']);
        if ($url === '') {
            return ['ok' => true, 'msg' => '未配置回调地址，跳过'];
        }
        if (stripos($url, 'http') !== 0) {
            return ['ok' => false, 'msg' => '回调地址不合法：' . $url];
        }

        $secret = yx_decrypt((string)$app['secret']);
        $body = [
            'appid' => $appid,
            'event' => $event,
            'ts'    => time(),
            'nonce' => random_nonce(16),
            'data'  => $data,
        ];

        // 签名：取 appid/event/ts/nonce + data 的 JSON 串
        $flat = [
            'appid' => $body['appid'],
            'event' => $body['event'],
            'ts'    => (string)$body['ts'],
            'nonce' => $body['nonce'],
            'data'  => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        ksort($flat);
        $pairs = [];
        foreach ($flat as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $body['sign'] = hash_hmac('sha256', implode('&', $pairs), $secret);

        [$ok, $httpCode, $resp] = http_post_json($url, $body, ['X-YX-Event: ' . $event], 8);
        if (!$ok) {
            return ['ok' => false, 'msg' => '推送失败：' . $resp];
        }
        if ($httpCode >= 400) {
            return ['ok' => false, 'msg' => '下游返回 HTTP ' . $httpCode];
        }
        // 约定下游返回 "success" 或以 2xx 表示成功
        return ['ok' => true, 'msg' => '推送成功（HTTP ' . $httpCode . '）', 'raw' => mb_substr((string)$resp, 0, 200)];
    }
}
