<?php
/**
 * 优享财务系统 · 上游对接框架
 *
 * 上游 = 为我们提供资源的供应商/云平台。框架提供：
 *   - YxUpstreamDriver  ：驱动接口（连通性测试、产品同步、开通、续费、开关机、重装、销毁、状态查询）
 *   - YxUpstreamMock    ：本地模拟驱动，无需真实上游即可联调与自检
 *   - YxUpstreamHttp    ：通用 HTTP/JSON 驱动，配合 HMAC 签名对接任意标准上游
 *   - YxUpstream        ：供应商配置与驱动解析
 *   - YxProvision       ：业务实例 ↔ 上游实例的操作执行器（由任务队列调用）
 *
 * 新增一个上游厂商：实现 YxUpstreamDriver 接口并在 YxUpstream::drivers() 中登记即可，
 * 业务层无需改动。
 */

interface YxUpstreamDriver
{
    /** 驱动标识 */
    public function code(): string;

    /** 连通性测试 */
    public function test(): array;

    /** 拉取上游可售产品 */
    public function products(): array;

    /** 开通 */
    public function create(array $params): array;

    /** 查询实例状态 */
    public function status(string $remoteId): array;

    /** 续费 */
    public function renew(string $remoteId, string $cycle, array $opt = []): array;

    /** 电源/开关机操作：start | stop | reboot */
    public function power(string $remoteId, string $action): array;

    /** 重装系统 */
    public function reinstall(string $remoteId, array $opt = []): array;

    /** 销毁 */
    public function destroy(string $remoteId): array;
}

abstract class YxUpstreamBase implements YxUpstreamDriver
{
    /** @var array 上游配置行 */
    protected $conf;

    public function __construct(array $conf)
    {
        $this->conf = $conf;
    }

    protected function id(): int
    {
        return (int)($this->conf['id'] ?? 0);
    }

    protected function timeout(): int
    {
        $t = (int)($this->conf['timeout'] ?? 10);
        return $t > 0 ? min($t, 60) : 10;
    }

    /** 密钥解密（数据库中为 AES 密文） */
    protected function secret(): string
    {
        return yx_decrypt((string)($this->conf['secret'] ?? ''));
    }

    /** 上游配置（JSON），解析失败返回空数组 */
    protected function confArray(): array
    {
        $raw = (string)($this->conf['config'] ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * 统一产品条目结构：code / name / price / stock / spec
     * stock 为整数表示上游给出的剩余数量，null 表示上游未提供库存字段。
     */
    public static function normalizeItems(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = (string)($item['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $out[] = [
                'code'  => $code,
                'name'  => (string)($item['name'] ?? ''),
                'price' => (float)($item['price'] ?? 0),
                'stock' => self::stockValue($item['stock'] ?? null),
                'spec'  => is_array($item['spec'] ?? null) ? $item['spec'] : [],
            ];
        }
        return $out;
    }

    /**
     * 库存字段规范化：数值（含数字字符串）转为整数，其余一律视为「上游未提供」
     *
     * 与本地产品的库存约定保持一致：>=0 为剩余数量（0 = 售罄），-1 = 不限量。
     * 非数字文案（如「充足」「无货」）不能直接转成 0，否则会被误判成售罄。
     */
    public static function stockValue($v): ?int
    {
        if ($v === null || is_bool($v) || is_array($v)) {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int)round($v);
        }
        $s = trim((string)$v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (int)round((float)$s);
    }

    /**
     * 传输层：把数据发到指定地址
     *
     * @param string $url     完整请求地址（由驱动按动作解析得出）
     * @param string $method  POST_JSON | POST_FORM | GET
     * @param array  $headers 附加请求头
     * @return array [ok(bool), httpCode(int), body(string), error(string)]
     */
    protected function transport(string $url, string $method, array $data, array $headers = []): array
    {
        if ($url === '') {
            return [false, 0, '', '未配置上游接口地址'];
        }
        $timeout = $this->timeout();

        if ($method === 'GET') {
            $full = $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
            $body = http_get($full, $timeout, $headers);
            return $body === null ? [false, 0, '', '请求上游失败（GET）'] : [true, 200, $body, ''];
        }

        if ($method === 'POST_FORM') {
            [$ok, $code, $body] = http_post_form($url, $data, $timeout, $headers);
            return [$ok, $code, (string)$body, $ok ? '' : (string)$body];
        }

        [$ok, $code, $body] = http_post_json($url, $data, $headers, $timeout);
        return [$ok, $code, (string)$body, $ok ? '' : (string)$body];
    }
}

/* ------------------------------------------------------------------ 模拟驱动 */

class YxUpstreamMock extends YxUpstreamBase
{
    public function code(): string
    {
        return 'mock';
    }

    public function test(): array
    {
        return ['ok' => true, 'msg' => '模拟驱动连接正常（本地）', 'raw' => ['driver' => 'mock']];
    }

    public function products(): array
    {
        return $this->productsInfo()['list'];
    }

    /** 产品列表与库存（stock：整数为剩余数量，null 表示上游未提供库存数） */
    public function productsInfo(): array
    {
        return [
            'ok'   => true,
            'msg'  => '模拟驱动已返回产品列表',
            'list' => [
                ['code' => 'mock-hk-2c2g', 'name' => '模拟·香港轻量 2核2G', 'price' => 20.00, 'stock' => 8, 'spec' => ['cpu' => 2, 'mem' => 2048, 'disk' => 40, 'bw' => 5, 'region' => 'hk']],
                ['code' => 'mock-hk-4c8g', 'name' => '模拟·香港精品 4核8G', 'price' => 60.00, 'stock' => 3, 'spec' => ['cpu' => 4, 'mem' => 8192, 'disk' => 80, 'bw' => 10, 'region' => 'hk']],
                ['code' => 'mock-us-2c2g', 'name' => '模拟·美国 CN2 2核2G', 'price' => 18.00, 'stock' => 0, 'spec' => ['cpu' => 2, 'mem' => 2048, 'disk' => 30, 'bw' => 5, 'region' => 'us']],
            ],
        ];
    }

    public function create(array $params): array
    {
        $seq = strtoupper(substr(md5(random_nonce(8)), 0, 8));
        return [
            'ok'        => true,
            'msg'       => '模拟开通成功',
            'remote_id' => 'MOCK-' . $seq,
            'ip'        => '203.0.113.' . random_int(2, 250),
            'username'  => 'root',
            'password'  => random_nonce(10),
            'status'    => 'running',
            'raw'       => ['params' => $params],
        ];
    }

    public function status(string $remoteId): array
    {
        if ($remoteId === '') {
            return ['ok' => false, 'msg' => '缺少上游实例编号', 'status' => 'unknown'];
        }
        return ['ok' => true, 'msg' => 'ok', 'status' => 'running', 'ip' => null, 'raw' => ['remote_id' => $remoteId]];
    }

    public function renew(string $remoteId, string $cycle, array $opt = []): array
    {
        return ['ok' => true, 'msg' => '模拟续费成功', 'raw' => ['remote_id' => $remoteId, 'cycle' => $cycle]];
    }

    public function power(string $remoteId, string $action): array
    {
        return ['ok' => true, 'msg' => '模拟电源操作成功：' . $action, 'raw' => ['remote_id' => $remoteId, 'action' => $action]];
    }

    public function reinstall(string $remoteId, array $opt = []): array
    {
        return ['ok' => true, 'msg' => '模拟重装成功', 'raw' => ['remote_id' => $remoteId, 'os' => $opt['os'] ?? '']];
    }

    public function destroy(string $remoteId): array
    {
        return ['ok' => true, 'msg' => '模拟销毁成功', 'raw' => ['remote_id' => $remoteId]];
    }
}

/* ------------------------------------------------------------------ 通用 HTTP 驱动 */

/**
 * 通用 HTTP/JSON 上游驱动
 *
 * 请求：POST {endpoint}
 *   {"action":"create","appid":"..","ts":1710000000,"nonce":"..","sign":"..","params":{...}}
 * 签名：sign = HMAC-SHA256("action=..&appid=..&nonce=..&params=<JSON>&ts=..", secret)
 * 响应：{"code":0,"msg":"ok","data":{"remote_id":"..","ip":"..","username":"..","password":"..","status":"running"}}
 */
class YxUpstreamHttp extends YxUpstreamBase
{
    public function code(): string
    {
        return 'http';
    }

    /** 构造规范化签名串 */
    public function signPayload(array $data, string $secret): string
    {
        $canonical = [
            'action' => (string)($data['action'] ?? ''),
            'appid'  => (string)($data['appid'] ?? ''),
            'nonce'  => (string)($data['nonce'] ?? ''),
            'params' => json_encode($data['params'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ts'     => (string)($data['ts'] ?? ''),
        ];
        ksort($canonical);
        $pairs = [];
        foreach ($canonical as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    /** 调用上游接口 */
    protected function call(string $action, array $params = []): array
    {
        $endpoint = trim((string)($this->conf['endpoint'] ?? ''));
        if ($endpoint === '') {
            return ['ok' => false, 'msg' => '未配置上游接口地址'];
        }
        $secret = $this->secret();
        if ($secret === '') {
            return ['ok' => false, 'msg' => '未配置上游密钥'];
        }

        $payload = [
            'action' => $action,
            'appid'  => (string)($this->conf['appid'] ?? ''),
            'ts'     => time(),
            'nonce'  => random_nonce(16),
            'params' => $params,
        ];
        $payload['sign'] = $this->signPayload($payload, $secret);

        [$ok, $httpCode, $body] = http_post_json($endpoint, $payload, [], $this->timeout());
        if (!$ok) {
            return ['ok' => false, 'msg' => '请求上游失败：' . $body];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => '上游返回非 JSON（HTTP ' . $httpCode . '）', 'raw' => mb_substr((string)$body, 0, 500)];
        }
        if ((int)($json['code'] ?? -1) !== 0) {
            return ['ok' => false, 'msg' => (string)($json['msg'] ?? '上游返回失败'), 'raw' => $json];
        }
        $data = $json['data'] ?? [];
        return [
            'ok'        => true,
            'msg'       => (string)($json['msg'] ?? 'ok'),
            'remote_id' => $data['remote_id'] ?? null,
            'ip'        => $data['ip'] ?? null,
            'username'  => $data['username'] ?? null,
            'password'  => $data['password'] ?? null,
            'status'    => $data['status'] ?? null,
            'expire_at' => $data['expire_at'] ?? null,
            'list'      => $data['list'] ?? null,
            'raw'       => $json,
        ];
    }

    public function test(): array
    {
        $r = $this->call('ping');
        return ['ok' => !empty($r['ok']), 'msg' => $r['msg'], 'raw' => $r['raw'] ?? null];
    }

    public function products(): array
    {
        return $this->productsInfo()['list'];
    }

    /** 产品列表与库存 */
    public function productsInfo(): array
    {
        $r = $this->call('products');
        if (empty($r['ok']) || !is_array($r['list'] ?? null)) {
            return ['ok' => false, 'msg' => (string)($r['msg'] ?? '上游未返回产品列表'), 'list' => []];
        }
        return ['ok' => true, 'msg' => (string)($r['msg'] ?? 'ok'), 'list' => self::normalizeItems($r['list'])];
    }

    public function create(array $params): array
    {
        return $this->call('create', $params);
    }

    public function status(string $remoteId): array
    {
        $r = $this->call('status', ['remote_id' => $remoteId]);
        return ['ok' => !empty($r['ok']), 'msg' => $r['msg'], 'status' => (string)($r['status'] ?? 'unknown'), 'ip' => $r['ip'] ?? null, 'raw' => $r['raw'] ?? null];
    }

    public function renew(string $remoteId, string $cycle, array $opt = []): array
    {
        return $this->call('renew', ['remote_id' => $remoteId, 'cycle' => $cycle] + $opt);
    }

    public function power(string $remoteId, string $action): array
    {
        return $this->call('power', ['remote_id' => $remoteId, 'action' => $action]);
    }

    public function reinstall(string $remoteId, array $opt = []): array
    {
        return $this->call('reinstall', ['remote_id' => $remoteId] + $opt);
    }

    public function destroy(string $remoteId): array
    {
        return $this->call('destroy', ['remote_id' => $remoteId]);
    }
}

/* ------------------------------------------------------------------ 可配置驱动 */

/**
 * 自定义 HTTP 驱动：接口地址、请求方式、签名算法、动作名、字段名、响应结构全部后台可配置。
 *
 * 用途：对接协议不固定的第三方系统（如各类商业 IDC 计费系统）。只要对方是
 * HTTP + 表单/JSON + 某种签名，就能通过填配置完成对接，无需改代码。
 *
 * 配置示例（后台「上游对接 → 编辑供应商 → 接口协议配置」）：
 * {
 *   "method": "POST_FORM",
 *   "auth": "append_secret",
 *   "sign_field": "sign",
 *   "drop_empty": true,
 *   "params_json_unescaped": true,
 *   "appid_field": "user",
 *   "ts_field": "timestamp",
 *   "nonce_field": "",
 *   "action_field": "act",
 *   "params_wrapper": "",
 *   "actions": { "test": "ping", "products": "product_list", "create": "host_create",
 *                "status": "host_info", "renew": "host_renew", "power": "host_power",
 *                "reinstall": "host_rebuild", "destroy": "host_delete" },
 *   "fields": { "product_code": "product_code", "service_sn": "out_trade_no",
 *               "hostname": "hostname", "cycle": "billing_cycle", "os": "os",
 *               "remote_id": "host_id", "power_action": "op" },
 *   "response": { "code_path": "status", "success_value": "1", "msg_path": "msg",
 *                 "data_path": "data", "list_path": "data.list",
 *                 "map": { "remote_id": "host_id", "ip": "ip", "username": "username",
 *                          "password": "password", "status": "state", "expire_at": "due_date" },
 *                 "item_map": { "code": "code", "name": "name", "price": "price", "spec": "config" } }
 * }
 */
class YxUpstreamCustom extends YxUpstreamBase
{
    public function code(): string
    {
        return 'custom';
    }

    /** 默认配置：不带任何假设，仅给出常用骨架 */
    public static function defaultConfig(): array
    {
        return [
            // 传输
            'method'                 => 'POST_JSON',   // POST_JSON | POST_FORM | GET
            // 附加请求头（可含 {appid} {secret} {ts} {nonce} 占位符）
            'headers'                => [],
            // 签名
            'auth'                   => 'hmac_sha256', // append_secret | append_secret_upper | secret_prefix | hmac_sha256 | hmac_md5 | basic | none
            'sign_field'             => 'sign',
            'drop_empty'             => true,          // 签名时是否排除空值
            'params_json_unescaped'  => true,          // 数组参数序列化为签名串时是否用 UNESCAPED_UNICODE
            // 公共字段名
            'appid_field'            => 'appid',
            'ts_field'               => 'ts',
            'nonce_field'            => 'nonce',
            'action_field'           => 'action',
            'params_wrapper'         => 'params',      // 空字符串 = 业务参数平铺在顶层
            // 动作名映射（我方动作 => 对方动作）
            'actions'                => [
                'test'      => 'ping',
                'products'  => 'products',
                'create'    => 'create',
                'status'    => 'status',
                'renew'     => 'renew',
                'power'     => 'power',
                'reinstall' => 'reinstall',
                'destroy'   => 'destroy',
            ],
            // 业务字段名映射（我方字段 => 对方字段）
            'fields'                 => [
                'product_code' => 'product_code',
                'service_sn'   => 'service_sn',
                'hostname'     => 'hostname',
                'cycle'        => 'cycle',
                'os'           => 'os',
                'remote_id'    => 'remote_id',
                'power_action' => 'action',
            ],
            // 响应解析
            'response'               => [
                'format'        => 'json',          // json | xml
                'code_path'     => 'code',
                'success_value' => '0',
                'msg_path'      => 'msg',
                'data_path'     => 'data',
                'list_path'     => 'data.list',
                'map'           => [
                    'remote_id' => 'remote_id',
                    'ip'        => 'ip',
                    'username'  => 'username',
                    'password'  => 'password',
                    'status'    => 'status',
                    'expire_at' => 'expire_at',
                ],
                'item_map'      => [
                    'code'  => 'code',
                    'name'  => 'name',
                    'price' => 'price',
                    'stock' => 'stock',
                    'spec'  => 'spec',
                ],
            ],
        ];
    }

    /** 生效配置 = 默认值 深度合并 用户配置 */
    public function config(): array
    {
        return self::merge(self::defaultConfig(), $this->confArray());
    }

    /** 递归合并（用户配置覆盖默认值，嵌套数组逐键合并） */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !self::isList($v)) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function isList(array $a): bool
    {
        return array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * 是否为「数组的数组」（列表），空数组不算
     *
     * 供各驱动判断上游返回的产品列表形态。魔方驱动按单条产品还是列表解析时也用它，
     * 所以必须是 public 且可静态调用。
     */
    public static function isListPublic($a): bool
    {
        if (!is_array($a) || !$a || !self::isList($a)) {
            return false;
        }
        foreach ($a as $v) {
            if (!is_array($v)) {
                return false;
            }
        }
        return true;
    }

    /** isListPublic 的完整名，保留旧调用点 */
    public static function isListArray($a): bool
    {
        return self::isListPublic($a);
    }

    /* ------------------------------------------------------ 请求构造 */

    /** 按映射构造请求体 */
    public function buildPayload(string $action, array $params = []): array
    {
        $c = $this->config();

        $remoteAction = $this->actionName($action, $c);

        // 业务字段名映射
        $mapped = [];
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $key = $c['fields'][$k] ?? $k;
            $mapped[$key] = $v;
        }

        $body = [];
        if ((string)$c['appid_field'] !== '') {
            $body[$c['appid_field']] = (string)($this->conf['appid'] ?? '');
        }
        if ((string)$c['ts_field'] !== '') {
            $body[$c['ts_field']] = time();
        }
        if ((string)$c['nonce_field'] !== '') {
            $body[$c['nonce_field']] = random_nonce(16);
        }
        if ((string)$c['action_field'] !== '' && $remoteAction !== '') {
            $body[$c['action_field']] = $remoteAction;
        }

        if ((string)$c['params_wrapper'] === '') {
            $body = array_merge($body, $mapped);
        } else {
            $body[$c['params_wrapper']] = $mapped;
        }

        return $body;
    }

    /**
     * 动作名：若映射值本身是 URL/路径（多路径型接口），则动作名回退为原始键名
     */
    public function actionName(string $action, array $c): string
    {
        $mapped = (string)($c['actions'][$action] ?? $action);
        if (self::isEndpointValue($mapped)) {
            return $action;   // 路径型接口：请求体内仍用我方动作键名（可用 action_field="" 关闭）
        }
        return $mapped;
    }

    /** 判断动作映射值是否为「路径/URL」（用于多路径型接口） */
    public static function isEndpointValue(string $v): bool
    {
        return (bool)preg_match('#^(https?://|/)#i', $v);
    }

    /**
     * 解析该动作要请求的地址：
     *   - 映射值为完整 URL → 直接使用
     *   - 映射值为以 / 开头的路径 → 拼到配置地址的 scheme+host 上
     *   - 否则使用配置的地址（单入口型接口）
     */
    public function endpointFor(string $action, array $c): string
    {
        $base   = trim((string)($this->conf['endpoint'] ?? ''));
        $mapped = (string)($c['actions'][$action] ?? $action);

        if (preg_match('#^https?://#i', $mapped)) {
            return $mapped;
        }
        if (strpos($mapped, '/') === 0) {
            $p = parse_url($base);
            if (!empty($p['scheme']) && !empty($p['host'])) {
                $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
                return $root . $mapped;
            }
            return $mapped;
        }
        return $base;
    }

    /** 构造附加请求头（支持 {appid} {secret} {ts} {nonce} 占位符） */
    public function buildHeaders(array $c): array
    {
        $headers = [];
        $map = [
            '{appid}'  => (string)($this->conf['appid'] ?? ''),
            '{secret}' => $this->secret(),
            '{ts}'     => (string)time(),
            '{nonce}'  => random_nonce(16),
        ];

        foreach ((array)($c['headers'] ?? []) as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $headers[] = $name . ': ' . str_replace(array_keys($map), array_values($map), (string)$value);
        }

        if (($c['auth'] ?? '') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode(($this->conf['appid'] ?? '') . ':' . $this->secret());
        }

        return $headers;
    }

    /** 构造规范化签名串 */
    public function canonical(array $body, array $c): string
    {
        $signField = (string)$c['sign_field'];
        $flat = [];
        foreach ($body as $k => $v) {
            if ($k === $signField) {
                continue;
            }
            $str = is_array($v)
                ? json_encode($v, $c['params_json_unescaped'] ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0)
                : (string)$v;
            if (!empty($c['drop_empty']) && ($str === '' || $str === '[]' || $str === '{}')) {
                continue;
            }
            $flat[(string)$k] = $str;
        }
        ksort($flat);
        $pairs = [];
        foreach ($flat as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return implode('&', $pairs);
    }

    /** 生成签名值 */
    public function buildSign(array $body, array $c): string
    {
        $secret    = $this->secret();
        $canonical = $this->canonical($body, $c);

        switch ($c['auth']) {
            case 'none':
            case 'basic':   // basic 认证走 Authorization 头，不产生请求体签名
                return '';
            case 'append_secret':
                return md5($canonical . $secret);
            case 'append_secret_upper':
                return strtoupper(md5($canonical . $secret));
            case 'secret_prefix':
                return md5($secret . $canonical);
            case 'hmac_md5':
                return hash_hmac('md5', $canonical, $secret);
            case 'hmac_sha256':
            default:
                return hash_hmac('sha256', $canonical, $secret);
        }
    }

    /* ------------------------------------------------------ 响应解析 */

    /** 解析响应为统一结构 */
    public function extract(string $rawBody): array
    {
        $c = $this->config();
        $r = $c['response'];

        $format = (string)($r['format'] ?? 'json');
        if ($format === 'xml') {
            $json = $this->xmlToArray($rawBody);
            if ($json === null) {
                return ['ok' => false, 'msg' => '上游返回 XML 解析失败', 'raw' => mb_substr($rawBody, 0, 500)];
            }
        } else {
            $json = json_decode($rawBody, true);
            if (!is_array($json)) {
                return ['ok' => false, 'msg' => '上游返回非 JSON', 'raw' => mb_substr($rawBody, 0, 500)];
            }
        }

        $code = array_dig($json, (string)$r['code_path']);
        $ok   = (string)$code === (string)$r['success_value'];
        $msg  = (string)(array_dig($json, (string)$r['msg_path']) ?? '');

        if (!$ok) {
            return ['ok' => false, 'msg' => $msg !== '' ? $msg : ('上游返回失败（' . $r['code_path'] . '=' . var_export($code, true) . '）'), 'raw' => $json];
        }

        $dataPath = (string)$r['data_path'];
        $data     = $dataPath === '' ? $json : array_dig($json, $dataPath);
        if (!is_array($data)) {
            $data = [];
        }

        $out = ['ok' => true, 'msg' => $msg !== '' ? $msg : 'ok', 'raw' => $json];
        foreach ((array)$r['map'] as $ourKey => $theirPath) {
            if ((string)$theirPath === '') {
                continue;
            }
            $val = array_dig($data, (string)$theirPath);
            if ($val === null) {
                $val = array_dig($json, (string)$theirPath);
            }
            $out[$ourKey] = $val;
        }

        // 产品列表
        $listPath = (string)$r['list_path'];
        $list     = $listPath === '' ? null : array_dig($json, $listPath);
        if (!is_array($list) && $dataPath !== '' && trim((string)($r['item_map']['code'] ?? '')) !== '' && self::isListArray($data)) {
            // 单页列表型接口：数据根本身就是产品列表（响应形如 "data":[{...}]），
            // 此时把 list_path 也填成 data 不会命中，这里直接按数据根解析。
            $list = $data;
        }
        // XML 的坑：列表节点只有一个子元素时，转数组后是单个对象而不是列表。
        // 若该对象里能取到 Code 字段，说明它就是一条产品，包成单元素列表。
        if (is_array($list) && $list && !self::isList($list)) {
            $codePath = trim((string)($r['item_map']['code'] ?? ''));
            if ($codePath !== '' && array_dig($list, $codePath) !== null) {
                $list = [$list];
            }
        }
        if (is_array($list)) {
            $items = [];
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $one = [];
                foreach ((array)$r['item_map'] as $ourKey => $theirPath) {
                    $one[$ourKey] = array_dig($item, (string)$theirPath);
                }
                $items[] = $one;
            }
            $out['list'] = $items;
        }

        return $out;
    }

    /* ------------------------------------------------------ 请求执行 */

    /** XML 响应转数组（字段值与 JSON 版保持一致） */
    protected function xmlToArray(string $xml): ?array
    {
        if (!function_exists('simplexml_load_string')) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $obj  = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($obj === false) {
            return null;
        }
        $arr = json_decode(json_encode($obj), true);
        return is_array($arr) ? $arr : null;
    }

    public function request(string $action, array $params = []): array
    {
        $c    = $this->config();
        $body = $this->buildPayload($action, $params);
        $url  = $this->endpointFor($action, $c);

        // basic 认证走请求头，不参与请求体签名
        $signField = (string)$c['sign_field'];
        if ($c['auth'] !== 'none' && $c['auth'] !== 'basic' && $signField !== '') {
            $body[$signField] = $this->buildSign($body, $c);
        }

        [$ok, $httpCode, $rawBody, $err] = $this->transport($url, (string)$c['method'], $body, $this->buildHeaders($c));
        if (!$ok) {
            return ['ok' => false, 'msg' => '请求上游失败：' . $err . '（' . $url . '）', 'raw' => null];
        }
        if ($httpCode >= 400) {
            return ['ok' => false, 'msg' => '上游返回 HTTP ' . $httpCode . '（' . $url . '）', 'raw' => mb_substr($rawBody, 0, 500)];
        }

        return $this->extract($rawBody);
    }

    /* ------------------------------------------------------ 接口实现 */

    public function test(): array
    {
        $c = $this->config();
        if ((string)($this->conf['endpoint'] ?? '') === '') {
            return ['ok' => false, 'msg' => '未配置接口地址'];
        }
        $r = $this->request('test');
        return ['ok' => !empty($r['ok']), 'msg' => $r['msg'], 'raw' => $r['raw'] ?? null];
    }

    public function products(): array
    {
        return $this->productsInfo()['list'];
    }

    /** 产品列表与库存（item_map 里配置的 stock 字段会一并解析；未配置则为 null） */
    public function productsInfo(): array
    {
        $r = $this->request('products');
        if (empty($r['ok'])) {
            return ['ok' => false, 'msg' => (string)($r['msg'] ?? '上游请求失败'), 'list' => []];
        }
        if (!is_array($r['list'] ?? null)) {
            return ['ok' => false, 'msg' => '上游响应中没有解析到产品列表，请检查「响应解析 → list_path」配置（当前：' . (string)($this->config()['response']['list_path'] ?? '') . '）', 'list' => []];
        }
        return ['ok' => true, 'msg' => (string)($r['msg'] ?? 'ok'), 'list' => self::normalizeItems($r['list'])];
    }

    public function create(array $params): array
    {
        return $this->request('create', $params);
    }

    public function status(string $remoteId): array
    {
        $r = $this->request('status', ['remote_id' => $remoteId]);
        return [
            'ok'     => !empty($r['ok']),
            'msg'    => $r['msg'],
            'status' => (string)($r['status'] ?? 'unknown'),
            'ip'     => $r['ip'] ?? null,
            'raw'    => $r['raw'] ?? null,
        ];
    }

    public function renew(string $remoteId, string $cycle, array $opt = []): array
    {
        return $this->request('renew', array_merge(['remote_id' => $remoteId, 'cycle' => $cycle], $opt));
    }

    public function power(string $remoteId, string $action): array
    {
        return $this->request('power', ['remote_id' => $remoteId, 'power_action' => $action]);
    }

    public function reinstall(string $remoteId, array $opt = []): array
    {
        return $this->request('reinstall', array_merge(['remote_id' => $remoteId], $opt));
    }

    public function destroy(string $remoteId): array
    {
        return $this->request('destroy', ['remote_id' => $remoteId]);
    }
}

/* ------------------------------------------------------------------ 魔方财务原生驱动 */

/**
 * 魔方财务（idcsmart）原生驱动
 *
 * 与「自定义 HTTP」驱动的区别：本驱动内置魔方开放接口的**协议骨架**（鉴权、动作路径、
 * 关键字段），只需填接口根地址 + 账户 ID + 密钥即可对接，不需要逐项配置 JSON。
 * 协议细节仍可在 config 里覆盖（深合并），对方发行版字段有差异时只改差异项。
 *
 * 目标系统版本见 config.api_version（默认 v1，魔方开放接口的实际形态）：
 *   - v1：经典 openapi。查询类动作（test/products/status）用 GET + 查询参数，
 *         写入类动作用 POST 表单；账户 ID + 密钥随请求发送，
 *         响应 {"status":200,"msg":"..","data":..}。
 *         例：GET {endpoint}/api/v1/product 返回 {"status":200,"data":{"list":[..]}}
 *   - v2：新版模块接口。先 POST {endpoint}/api/v2/login 用「账号 + 密码」换 JWT，
 *         之后每个请求带 Authorization: Bearer <token>（token 缓存在 runtime/，过期自动重新登录），
 *         响应 {"code":200,"msg":"..","data":..}。
 *         注意：并非所有魔方站点都提供 v2（多数只有 v1），v2 登录 404 时应把 api_version 改回 v1。
 *
 * 产品列表对应的上游动作无法靠猜（各家模块路由不同），默认落在 config.endpoints.products，
 * 单路径/根路径/列表路径都可改，不用改代码。
 */
class YxUpstreamZjmf extends YxUpstreamBase
{
    /** 登录 token 缓存目录（按供应商 ID + 接口地址区分） */
    const TOKEN_DIR = '/runtime/zjmf_token';

    /** v1 里走 GET + 查询串的动作（其余动作按 POST 表单提交） */
    const READ_ACTIONS = ['test', 'products', 'status'];

    public function code(): string
    {
        return 'zjmf';
    }

    public static function defaultConfig(): array
    {
        return [
            'api_version'    => 'v1',              // v1 | v2（实测魔方开放接口基本都是 v1）
            'language'       => 'zh-cn',
            'country_code'   => 'CN',
            // 产品列表取数通道：openapi | cart
            //   openapi：走 v1/v2 开放接口的 endpoints.products（需上游开通开放接口）
            //   cart   ：走前台购物车商品树 GET /cart/prolist（未开通开放接口时的唯一可用通道，
            //            只读商品/库存/价格，不依赖账户密钥；开通/续费类动作仍需开放接口）
            'products_via'   => 'openapi',
            'cart_products_path' => '/cart/prolist',
            // 价格单位：cent（分）/ yuan（元）
            //   实测魔方 v1 开放接口（GET /api/v1/product）的 price 已是元，如 "price":"599.00"，
            //   前台购物车接口（cart）同样是元，所以默认 yuan；仅当对方按分返回时才改成 cent。
            'price_unit'     => 'yuan',
            // 商品树层级（cart 通道专用）：数据根 -> 该路径为「分类」列表，
            // 每个分类的 group 字段是商品组列表，每个商品组的 products 字段是商品列表
            // （真实魔方 /cart/prolist 是复数 products，早期写成单数会导致解析不到商品）
            'cart_tree'      => ['fgs' => 'group', 'group' => 'products'],
            // v1：每个动作一个路径（相对根地址），{id} 会替换为上游实例 ID
            //   查询类（test/products/status）走 GET + 查询串，写入类走 POST 表单；
            //   连通性探测直接用公开的商品接口，魔方站点对不存在的路径会回「首页 HTML」而不是 404，
            //   探 /api/v1 本身只会拿到网页，无法证明接口可用。
            'endpoints'      => [
                'test'      => '/api/v1/product',
                'products'  => '/api/v1/product',
                'create'    => '/api/v1/host',
                'status'    => '/api/v1/host/{id}',
                'renew'     => '/api/v1/host/{id}/renew',
                'power'     => '/api/v1/host/{id}/status',
                'reinstall' => '/api/v1/host/{id}/reinstall',
                'destroy'   => '/api/v1/host/{id}',
            ],
            // 业务参数名（可覆盖）
            'fields'         => [
                'product_code' => 'product_code',
                'service_sn'   => 'out_trade_no',
                'hostname'     => 'hostname',
                'cycle'        => 'billing_cycle',
                'os'           => 'os',
                'remote_id'    => 'id',
                'power_action' => 'status',
            ],
            // 电源动作取值（我方动作 => 上游取值）
            'power_actions'  => ['start' => 'on', 'stop' => 'off', 'reboot' => 'reboot'],
            // v2：登录接口与令牌所在字段
            'login_path'     => '/api/v2/login',
            'login_fields'   => ['account' => 'username', 'password' => 'password'],
            'token_path'     => 'data.jwt',
            // 成功判定：状态码所在路径与成功值（任一命中即成功）
            'success'        => [
                'code_paths'     => ['code', 'status'],
                'success_values' => ['200'],
            ],
            // 响应字段映射（数据根内）
            'map'            => [
                'remote_id' => 'id',
                'ip'        => 'ip',
                'username'  => 'username',
                'password'  => 'password',
                'status'    => 'status',
                'expire_at' => 'due_time',
            ],
            // 产品列表解析：数据根本身或数据根下的列表路径
            'list_path'      => '',
            'item_map'       => [
                'code'  => 'id',
                'name'  => 'name',
                'price' => 'price',
                'stock' => 'qty',
                'spec'  => 'config',
            ],
        ];
    }

    /**
     * 生效配置（含本模板不认识的键，仅用于后台展示）
     *
     * 协议执行一律走 configOf()：老部署里 zjmf 供应商的 config 是早期后台自动灌入的
     * 通用 HTTP 模板（method/auth/actions/response/map…），那些键与魔方协议无关。
     */
    public function config(): array
    {
        return YxUpstreamCustom::merge(self::defaultConfig(), $this->confArray());
    }

    /**
     * 协议执行用的配置：按本模板认识的键过滤后再深度合并
     *
     * 只保留 defaultConfig() 里存在的键，用户在新界面上填的差异项照常生效；
     * 早期通用模板的残留键被丢弃，避免污染协议行为。
     */
    protected function configOf(): array
    {
        $def = self::defaultConfig();
        $raw = $this->confArray();
        $use = [];
        foreach ($raw as $k => $v) {
            if (array_key_exists($k, $def)) {
                $use[$k] = $v;
            }
        }
        // 老部署里可能残留 openapi=分 时期的 price_unit=cent，那是显式配置，照旧生效；
        // 未配置时用模板默认值（yuan），不再按取数通道推导。
        return YxUpstreamCustom::merge($def, $use);
    }

    /** 版本：v1 | v2 */
    public function apiVersion(): string
    {
        $v = strtolower((string)$this->configOf()['api_version']);
        return $v === 'v1' ? 'v1' : 'v2';
    }

    /** 动作路径（相对根地址） */
    public function endpointFor(string $action): string
    {
        $c    = $this->configOf();
        $path = (string)($c['endpoints'][$action] ?? '');
        if ($path === '') {
            return '';
        }
        return $path;
    }

    /** 拼完整地址；路径已带 scheme 时原样返回 */
    protected function url(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = rtrim(trim((string)($this->conf['endpoint'] ?? '')), '/');
        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }

    /* ------------------------------------------------------ 鉴权 */

    /**
     * 取访问令牌（仅 v2 用）
     *
     * 优先读缓存文件，其次用「账户 ID + 密钥」调登录接口换取；并发/多进程安全由
     * 「写临时文件再 rename」保证，读取方永远拿到完整 JSON。缓存不可写时直接每次登录。
     *
     * @return array ok,token,msg
     */
    public function token(bool $refresh = false): array
    {
        $c    = $this->configOf();
        $file = $this->tokenFile();
        if (!$refresh && $file !== '' && is_file($file)) {
            $cached = json_decode((string)@file_get_contents($file), true);
            $token  = is_array($cached) ? (string)($cached['token'] ?? '') : '';
            $exp    = is_array($cached) ? (int)($cached['expire_at'] ?? 0) : 0;
            if ($token !== '' && $exp > time() + 60) {
                return ['ok' => true, 'token' => $token, 'msg' => 'ok'];
            }
        }

        $lf = (array)$c['login_fields'];
        $body = [
            (string)($lf['account'] ?? 'username')  => (string)($this->conf['appid'] ?? ''),
            (string)($lf['password'] ?? 'password') => $this->secret(),
        ];
        [$ok, $code, $raw] = http_post_json($this->url((string)$c['login_path']), $body, ['Accept: application/json'], $this->timeout());
        if (!$ok) {
            return ['ok' => false, 'token' => '', 'msg' => '登录上游失败：' . $raw];
        }
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            $hint = (int)$code === 404
                ? '（该路径不存在：请核对 config.login_path，以及根地址是否指向魔方站点本身而非其 API 域名）'
                : '（响应不是 JSON：该站点多半没有 v2 接口，请把 config.api_version 改为 v1，v1 不需要登录）';
            return ['ok' => false, 'token' => '', 'msg' => '登录响应非 JSON（HTTP ' . $code . '）' . $hint];
        }
        if (!$this->isSuccess($json, $c)) {
            return ['ok' => false, 'token' => '', 'msg' => '登录失败：' . $this->messageOf($json, $c)];
        }
        $token = (string)(array_dig($json, (string)$c['token_path']) ?? '');
        if ($token === '') {
            return ['ok' => false, 'token' => '', 'msg' => '登录成功但响应中没有令牌（token_path=' . (string)$c['token_path'] . '）'];
        }

        // 令牌有效期未知，按 20 分钟缓存，到期重新登录
        if ($file !== '') {
            $tmp = $file . '.' . getmypid() . '.tmp';
            @file_put_contents($tmp, json_encode(['token' => $token, 'expire_at' => time() + 1200]));
            @rename($tmp, $file);
        }
        return ['ok' => true, 'token' => $token, 'msg' => 'ok'];
    }

    /** 令牌缓存文件路径（不可写时返回空串） */
    protected function tokenFile(): string
    {
        $dir = YX_ROOT . self::TOKEN_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return '';
        }
        $key = sha1($this->id() . '|' . (string)($this->conf['endpoint'] ?? '') . '|' . (string)($this->conf['appid'] ?? ''));
        return $dir . '/' . $key . '.json';
    }

    /* ------------------------------------------------------ 响应解析 */

    /** 成功判定：code_paths 中任一字段命中 success_values 即成功 */
    public function isSuccess(array $json, array $c = null): bool
    {
        $c = $c ?? $this->configOf();
        $vals = array_map('strval', (array)$c['success']['success_values']);
        foreach ((array)$c['success']['code_paths'] as $path) {
            $v = array_dig($json, (string)$path);
            if ($v !== null && in_array((string)$v, $vals, true)) {
                return true;
            }
        }
        return false;
    }

    /** 错误信息：优先 msg，其次 message / error */
    public function messageOf(array $json, array $c = null): string
    {
        $c = $c ?? $this->configOf();
        foreach (['msg', 'message', 'error', 'errmsg'] as $k) {
            $v = array_dig($json, $k);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        foreach ((array)$c['success']['code_paths'] as $path) {
            $v = array_dig($json, (string)$path);
            if ($v !== null) {
                return '上游返回失败（' . $path . '=' . (is_scalar($v) ? (string)$v : json_encode($v)) . '）';
            }
        }
        return '上游返回失败';
    }

    /** 业务数据根：data；没有 data 时用整个响应 */
    protected function dataOf(array $json): array
    {
        $data = array_dig($json, 'data');
        return is_array($data) ? $data : $json;
    }

    /** 解析为统一结构 */
    public function extract(string $rawBody): array
    {
        $c    = $this->configOf();
        $json = json_decode($rawBody, true);
        if (!is_array($json)) {
            // 魔方站点对不存在/未开通的路径不会回 404，而是回站点首页 HTML（HTTP 200），
            // 必须和「返回了别的 JSON」区分开，否则报错信息会把排查方向带偏。
            $head = ltrim($rawBody);
            $html = $head !== '' && ($head[0] === '<' || stripos(mb_substr($head, 0, 200), '<!doctype') !== false);
            return [
                'ok'   => false,
                'msg'  => $html
                    ? '上游返回的是网页而不是接口 JSON：该接口路径不存在或未开通开放接口'
                    : '上游返回非 JSON',
                'html' => $html,
                'raw'  => mb_substr($rawBody, 0, 500),
            ];
        }
        if (!$this->isSuccess($json, $c)) {
            return ['ok' => false, 'msg' => $this->messageOf($json, $c), 'raw' => $json];
        }

        $data = $this->dataOf($json);
        $out  = ['ok' => true, 'msg' => $this->messageOf($json, $c), 'raw' => $json];
        if ($out['msg'] === '上游返回失败') {
            $out['msg'] = 'ok';
        }
        foreach ((array)$c['map'] as $ours => $theirPath) {
            if ((string)$theirPath === '') {
                continue;
            }
            $val = array_dig($data, (string)$theirPath);
            if ($val === null) {
                $val = array_dig($json, (string)$theirPath);
            }
            $out[$ours] = $val;
        }

        // 产品列表：list_path 为空时把数据根当列表
        $listPath = (string)$c['list_path'];
        $list     = $listPath === '' ? $data : array_dig($json, $listPath);

        $items = $this->listItems($list, $c);
        if ($items === null && $listPath !== '') {
            // list_path 填了但没命中，而数据根本身就是产品列表：按数据根再试一次
            $items = $this->listItems($data, $c);
            if (is_array($items)) {
                $list = $data;
            }
        }
        if (is_array($items) && $items) {
            // 解析后的统一条目（code/name/price/stock/spec）
            $out['list'] = $items;
            // 解析前的原始列表节点：上架到产品中心要按原始字段（cycle/qty/stock_control）折算
            $out['raw_list'] = is_array($list) && YxUpstreamCustom::isListPublic($list) ? array_values($list) : [];
        }
        return $out;
    }

    /**
     * 把候选节点解析成产品列表
     *
     * 兼容三种返回形态：
     *   1. 列表 [{...}, {...}]
     *   2. 单条产品 {...}（能取到 item_map.code 指向的字段）
     *   3. 分页容器 {list:[...], total:12}（魔方/多数系统都把产品放在 list 键下）
     * 都不是时返回 null。
     */
    protected function listItems($node, array $c): ?array
    {
        if (!is_array($node) || !$node) {
            return null;
        }
        if (YxUpstreamCustom::isListPublic($node)) {
            $items = [];
            foreach ($node as $one) {
                if (is_array($one)) {
                    $items[] = $this->mapItem($one, $c);
                }
            }
            return $items;
        }
        if (array_is_list($node)) {
            return null;   // 普通数组（非对象列表），不是产品
        }
        if ($this->itemsLike($node, $c)) {
            return [$this->mapItem($node, $c)];
        }
        // 分页容器：取第一个「看起来像产品列表」的子节点
        foreach (['list', 'data', 'items', 'records', 'rows'] as $k) {
            $sub = $node[$k] ?? null;
            if (is_array($sub) && $sub && YxUpstreamCustom::isListPublic($sub)) {
                return $this->listItems($sub, $c);
            }
        }
        // 商品树容器（cart 通道）：分类 -> 商品组 -> 商品
        $tree = $this->treeItems($node, $c);
        if ($tree !== null) {
            return $tree;
        }
        return null;
    }

    /**
     * 展开「分类 -> 商品组 -> 商品」的嵌套商品树
     *
     * 魔方前台购物车接口 /cart/prolist 返回的是这种三层结构：
     *   data.fgs[]          分类（id/name/group）
     *   data.fgs[].group[]  商品组（id/name/headline/tagline/products）
     *   ...group[].products[] 商品（id/name/qty/product_price/billingcycle…）
     * 与开放接口的扁平列表不同，不能靠 list_path 取值，只能按层级逐层展开。
     *
     * 层级键取 config.cart_tree：['fgs' => 'group', 'group' => 'products']，
     * 上游改字段名时只改配置即可。识别不到该结构时返回 null，交回原有逻辑。
     */
    protected function treeItems(array $root, array $c): ?array
    {
        $tree = $c['cart_tree'] ?? null;
        if (!is_array($tree) || !$tree) {
            return null;
        }
        $levelKey = (string)reset($tree);      // group：商品组列表所在的键
        $itemKey  = (string)end($tree);        // products：商品列表所在的键
        if ($levelKey === '' || $itemKey === '' || $levelKey === $itemKey) {
            return null;
        }
        // 老配置写成单数 product（真实魔方是 products）：两个键都接受，
        // 否则升级后旧配置的部署会一直解析不到商品
        $itemKeys = array_values(array_unique([$itemKey, 'products', 'product']));

        // 找到第一层「分类列表」：root 本身是列表，或 root 下某个键是列表
        $fgs = null;
        if (YxUpstreamCustom::isListPublic($root)) {
            $fgs = $root;
        } else {
            foreach ((array)array_keys($tree) as $topKey) {
                $sub = $root[(string)$topKey] ?? null;
                if (is_array($sub) && $sub && YxUpstreamCustom::isListPublic($sub)) {
                    $fgs = $sub;
                    break;
                }
            }
        }
        if (!is_array($fgs)) {
            return null;
        }

        // 逐层展开到商品：分类 -> 组 -> 商品
        $items = [];
        foreach ($fgs as $fg) {
            if (!is_array($fg) || !isset($fg[$levelKey]) || !is_array($fg[$levelKey])) {
                continue;
            }
            foreach ($fg[$levelKey] as $grp) {
                if (!is_array($grp)) {
                    continue;
                }
                // 商品列表键取第一个命中的（products 优先）
                $prods = null;
                foreach ($itemKeys as $ik) {
                    if (isset($grp[$ik]) && is_array($grp[$ik])) {
                        $prods = $grp[$ik];
                        break;
                    }
                }
                if ($prods === null) {
                    continue;
                }
                foreach ($prods as $prod) {
                    if (is_array($prod)) {
                        $items[] = $this->mapItem($prod, $c);
                    }
                }
            }
        }
        // 结构对上了但没有商品（分类全空）也算识别成功，避免退回分页容器误判
        return $items;
    }

    /**
     * 该对象是否像一条产品
     *
     * 只看 code 不够：主机详情同样是 {id, ip, status} 这种键值对象，也有 id 字段。
     * 产品还必须带名称或价格，借此与实例对象区分，避免把主机当产品上架。
     */
    protected function itemsLike(array $one, array $c): bool
    {
        $codePath = trim((string)($c['item_map']['code'] ?? ''));
        if ($codePath === '' || array_dig($one, $codePath) === null) {
            return false;
        }
        $cands = ['name', 'price'];
        foreach ($cands as $k) {
            $p = trim((string)($c['item_map'][$k] ?? ''));
            if ($p !== '' && array_dig($one, $p) !== null) {
                return true;
            }
        }
        // 魔方多语言站点把中文名放在 name_zh / name_cn
        foreach (['name_zh', 'name_cn'] as $k) {
            $v = array_dig($one, $k);
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }
        return false;
    }

    protected function mapItem(array $one, array $c): array
    {
        $item = [];
        foreach ((array)$c['item_map'] as $ours => $theirPath) {
            $val = (string)$theirPath === '' ? null : array_dig($one, (string)$theirPath);
            // 魔方返回的价格是分（price），我方统一用元；其他字段保持原值。
            if ($ours === 'price' && $val !== null && is_numeric($val)) {
                $val = round((float)$val / $this->priceDivisor($c), 2);
            }
            $item[$ours] = $val;
        }
        // 商品名称：魔方多语言站点只在 name_zh / name_cn 里给中文名
        if (empty($item['name'])) {
            foreach (['name_zh', 'name_cn'] as $k) {
                $v = array_dig($one, $k);
                if (is_string($v) && trim($v) !== '') {
                    $item['name'] = $v;
                    break;
                }
            }
        }
        // 库存统一走 stockValue：数字转整数，非数字文案（如「充足」）记为「上游未提供」，
        // 不能原样透传，否则下游按整数比较时会被当成有货。
        $qty = $item['stock'] ?? null;
        if ($qty === null) {
            $qty = array_dig($one, 'qty');
        }
        $item['stock'] = YxUpstreamBase::stockValue($qty);
        return $item;
    }

    /**
     * 价格换算除数
     *
     * 默认 1（上游给的就是元，实测魔方 v1 /api/v1/product 与 /cart/prolist 都是元）；
     * 对方按分返回时把 config.price_unit 设为 cent，除数为 100。
     */
    protected function priceDivisor(array $c): float
    {
        return strtolower(trim((string)($c['price_unit'] ?? ''))) === 'cent' ? 100.0 : 1.0;
    }

    /** 产品列表取数通道：openapi | cart */
    protected function productsVia(): string
    {
        return (string)($this->configOf()['products_via'] ?? 'openapi') === 'cart' ? 'cart' : 'openapi';
    }

    /* ------------------------------------------------------ 请求执行 */

    /**
     * 调上游一个动作
     *
     * @param string $action 我方动作
     * @param array  $params 业务参数（已按 fields 映射）
     * @param string $remoteId 上游实例 ID，用于替换路径里的 {id}
     */
    public function request(string $action, array $params = [], string $remoteId = ''): array
    {
        if (trim((string)($this->conf['endpoint'] ?? '')) === '') {
            return ['ok' => false, 'msg' => '未配置上游接口地址'];
        }
        $c    = $this->configOf();
        $path = $this->endpointFor($action);
        if ($path === '') {
            return ['ok' => false, 'msg' => '未配置动作「' . $action . '」的接口路径（config.endpoints.' . $action . '）'];
        }
        $path = str_replace('{id}', rawurlencode($remoteId), $path);

        if ($this->apiVersion() === 'v1') {
            // v1：账户 ID 与密钥随请求发送。查询类动作必须用 GET + 查询串
            // （魔方 v1 只给读接口注册了 GET 路由，POST 同一路径会被路由到站点首页并回 404/HTML）；
            // 写入类动作仍按 POST 表单提交。
            $body = ['username' => (string)($this->conf['appid'] ?? ''), 'password' => $this->secret()];
            $body += $params;
            if (in_array($action, self::READ_ACTIONS, true)) {
                [$ok, $code, $raw] = $this->sendGet($this->url($path), $body);
            } else {
                [$ok, $code, $raw] = $this->sendPostForm($this->url($path), $body);
            }
        } else {
            $tok = $this->token();
            if (empty($tok['ok'])) {
                return ['ok' => false, 'msg' => $tok['msg']];
            }
            $headers = ['Accept: application/json', 'Authorization: Bearer ' . $tok['token']];
            [$ok, $code, $raw] = http_post_json($this->url($path), $params, $headers, $this->timeout());

            // 令牌过期：重新登录后重试一次
            if ($ok && (int)$code === 401) {
                $tok = $this->token(true);
                if (empty($tok['ok'])) {
                    return ['ok' => false, 'msg' => $tok['msg']];
                }
                $headers[1] = 'Authorization: Bearer ' . $tok['token'];
                [$ok, $code, $raw] = http_post_json($this->url($path), $params, $headers, $this->timeout());
            }
        }

        if (!$ok) {
            return ['ok' => false, 'msg' => '请求上游失败：' . $raw . '（' . $this->url($path) . '）'];
        }
        if ((int)$code >= 400) {
            return ['ok' => false, 'msg' => '上游返回 HTTP ' . $code . '（' . $this->url($path) . '）', 'raw' => mb_substr((string)$raw, 0, 500)];
        }
        $r = $this->extract((string)$raw);
        if (empty($r['ok']) && !empty($r['html'])) {
            // 拿到网页说明路径没落到接口上，把实际请求地址带上，避免只报「非 JSON」无从核对
            $r['msg'] = (string)$r['msg'] . '（请求地址：' . $this->url($path) . '）';
        }
        return $r;
    }

    /**
     * v1 查询类动作：GET + 查询串
     *
     * 单独抽成方法便于自检替换成桩，不必发真实网络请求。
     *
     * @return array [ok(bool), httpCode(int), body(string)]
     */
    protected function sendGet(string $url, array $query): array
    {
        $sep  = strpos($url, '?') === false ? '?' : '&';
        $full = $url . $sep . http_build_query($query);
        return http_get_status($full, $this->timeout(), ['Accept: application/json']);
    }

    /**
     * v1 写入类动作：POST 表单
     *
     * @return array [ok(bool), httpCode(int), body(string)]
     */
    protected function sendPostForm(string $url, array $data): array
    {
        return http_post_form($url, $data, $this->timeout(), ['Accept: application/json']);
    }

    /** 按 fields 映射业务字段名，丢弃空值 */
    protected function mapParams(array $params, array $c): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out[(string)($c['fields'][$k] ?? $k)] = $v;
        }
        return $out;
    }

    /* ------------------------------------------------------ 接口实现 */

    public function test(): array
    {
        if (trim((string)($this->conf['endpoint'] ?? '')) === '') {
            return ['ok' => false, 'msg' => '未配置接口地址'];
        }
        // v2：连通性即登录；v1：请求配置的探测路径
        if ($this->apiVersion() === 'v2') {
            $tok = $this->token(true);
            return [
                'ok'  => !empty($tok['ok']),
                'msg' => !empty($tok['ok']) ? '魔方 v2 接口登录成功' : (string)$tok['msg'],
            ];
        }
        $r    = $this->request('test');
        $path = $this->endpointFor('test');
        return [
            'ok'  => !empty($r['ok']),
            'msg' => !empty($r['ok'])
                ? '魔方 v1 接口连通成功（GET ' . $path . '）'
                : (string)($r['msg'] ?? '上游请求失败'),
            'raw' => $r['raw'] ?? null,
        ];
    }

    public function products(): array
    {
        return $this->productsInfo()['list'];
    }

    /** 产品列表与库存 */
    public function productsInfo(): array
    {
        if ($this->productsVia() === 'cart') {
            return $this->productsViaCart();
        }
        $r = $this->request('products');
        if (empty($r['ok'])) {
            return ['ok' => false, 'msg' => (string)($r['msg'] ?? '上游请求失败'), 'list' => []];
        }
        if (!is_array($r['list'] ?? null)) {
            return [
                'ok'   => false,
                'msg'  => '上游响应中没有解析到产品列表，请检查 config.endpoints.products 与 item_map（当前路径：'
                    . $this->endpointFor('products') . '）',
                'list' => [],
            ];
        }
        return ['ok' => true, 'msg' => (string)($r['msg'] ?? 'ok'), 'list' => YxUpstreamBase::normalizeItems($r['list'])];
    }

    /**
     * 拉取「上架到产品中心」用的原始条目（带失败原因）
     *
     * 统一结构（normalizeItems）只保留 code/name/price/stock/spec，丢掉了 cycle 与
     * stock_control，导致上架时周期一律按 1 个月折算。这里直接取 extract() 解析出的
     * raw_list（原始商品对象），并把统一结构里的编码回填为 id，保证勾选匹配用
     * id ?? code 都能命中；price_unit=cent 时把原始价折算成元，避免上架价被放大 100 倍。
     * 购物车通道（products_via=cart）的条目来自前台商品树，本身没有 cycle 字段。
     */
    public function productsRaw(): array
    {
        if ($this->productsVia() === 'cart') {
            return $this->productsInfo();
        }
        $r = $this->request('products');
        if (empty($r['ok'])) {
            return ['ok' => false, 'msg' => (string)($r['msg'] ?? '上游请求失败'), 'list' => []];
        }
        if (!is_array($r['list'] ?? null)) {
            return [
                'ok'   => false,
                'msg'  => '上游响应中没有解析到产品列表，请检查 config.endpoints.products 与 item_map（当前路径：'
                    . $this->endpointFor('products') . '）',
                'list' => [],
            ];
        }
        $norm = YxUpstreamBase::normalizeItems($r['list']);
        $raw  = is_array($r['raw_list'] ?? null) ? $r['raw_list'] : [];
        if (!$raw) {
            // 响应里没有可复用的原始节点（例如被配置裁剪过）：退回统一结构
            return ['ok' => true, 'msg' => (string)($r['msg'] ?? 'ok'), 'list' => $norm];
        }

        $div = $this->priceDivisor($this->configOf());
        $out = [];
        // listItems() 建统一结构时会跳过非数组节点，这里用独立计数保持一一对应，
        // 否则原始列表里混入空节点后编码会整体错位，商品可能挂错上游编码。
        $ni = 0;
        foreach (array_values($raw) as $one) {
            if (!is_array($one)) {
                continue;
            }
            // 编码以统一结构为准（item_map 可能配了带层级的路径），取不到再退原始 id
            $code = trim((string)($norm[$ni]['code'] ?? ''));
            $ni++;
            if ($code === '') {
                $code = trim((string)($one['id'] ?? ''));
            }
            if ($code !== '') {
                $one['id'] = $code;
            }
            if ($div > 1 && isset($one['price']) && is_numeric($one['price'])) {
                $one['price'] = round((float)$one['price'] / $div, 2);
            }
            $out[] = $one;
        }
        return ['ok' => true, 'msg' => (string)($r['msg'] ?? 'ok'), 'list' => $out];
    }

    /**
     * 走前台购物车接口取产品（products_via = cart）
     *
     * 未开通开放接口的上游只有这一个可用通道：GET /cart/prolist，无需账户密钥，
     * 返回「分类 -> 商品组 -> 商品」三层商品树，由 extract() 展开。
     */
    protected function productsViaCart(): array
    {
        $c    = $this->configOf();
        $path = trim((string)($c['cart_products_path'] ?? ''));
        if ($path === '') {
            return ['ok' => false, 'msg' => '未配置购物车商品接口路径（config.cart_products_path）', 'list' => []];
        }
        $url  = $this->url($path);
        $body = http_get($url, $this->timeout(), ['Accept: application/json']);
        if (!is_string($body) || trim($body) === '') {
            return ['ok' => false, 'msg' => '请求上游购物车接口失败：' . $url, 'list' => []];
        }
        $r = $this->extract($body);
        if (empty($r['ok'])) {
            return ['ok' => false, 'msg' => (string)($r['msg'] ?? '上游请求失败'), 'list' => []];
        }
        if (!is_array($r['list'] ?? null) || !$r['list']) {
            return [
                'ok'   => false,
                'msg'  => '购物车接口没有解析到商品树，请检查 config.cart_tree 与 config.item_map（当前路径：' . $path . '）',
                'list' => [],
            ];
        }
        return ['ok' => true, 'msg' => (string)($r['msg'] ?? 'ok'), 'list' => YxUpstreamBase::normalizeItems($r['list'])];
    }

    public function create(array $params): array
    {
        $c = $this->configOf();
        return $this->request('create', $this->mapParams($params, $c));
    }

    public function status(string $remoteId): array
    {
        $r = $this->request('status', [], $remoteId);
        return [
            'ok'     => !empty($r['ok']),
            'msg'    => $r['msg'],
            'status' => (string)($r['status'] ?? 'unknown'),
            'ip'     => $r['ip'] ?? null,
            'raw'    => $r['raw'] ?? null,
        ];
    }

    public function renew(string $remoteId, string $cycle, array $opt = []): array
    {
        $c = $this->configOf();
        return $this->request('renew', $this->mapParams(array_merge(['cycle' => $cycle], $opt), $c), $remoteId);
    }

    public function power(string $remoteId, string $action): array
    {
        $c     = $this->configOf();
        $value = (string)($c['power_actions'][$action] ?? $action);
        return $this->request('power', $this->mapParams(['power_action' => $value], $c), $remoteId);
    }

    public function reinstall(string $remoteId, array $opt = []): array
    {
        $c = $this->configOf();
        return $this->request('reinstall', $this->mapParams($opt, $c), $remoteId);
    }

    public function destroy(string $remoteId): array
    {
        return $this->request('destroy', [], $remoteId);
    }
}

/* ------------------------------------------------------------------ 供应商管理器 */

class YxUpstream
{
    /** 库存快照里「上游未提供库存」的特征串：stockCheck 生成提示与后台判定状态共用 */
    const STOCK_UNKNOWN_HINT = '未解析到库存字段';

    /** 可选驱动 */
    public static function drivers(): array
    {
        return [
            'mock'    => ['name' => '本地模拟（联调/演示）', 'desc' => '不依赖真实上游，用于打通下单到开通的全流程'],
            'http'    => ['name' => '标准 HTTP/JSON', 'desc' => '对接符合本项目文档协议（action + params + HMAC 签名）的上游'],
            'custom'  => ['name' => '自定义 HTTP（字段/签名/响应全可配）', 'desc' => '对接协议不固定的第三方系统：请求方式、签名算法、动作名、字段名、响应结构均可在后台配置'],
            'zjmf'    => ['name' => '魔方财务（idcsmart）对接', 'desc' => '内置魔方开放接口协议：填接口根地址 + 账户 ID + 密钥即可，v1/v2 可选，字段差异可在 config 覆盖'],
            'lezhong' => ['name' => '乐众系统对接', 'desc' => '预置乐众系统对接模板，字段/签名/响应可在后台按对方文档校准'],
        ];
    }

    /**
     * 驱动的默认协议配置模板（用于后台「填入默认模板」）
     *
     * zjmf 返回内置魔方协议骨架；custom/lezhong 返回通用可配置模板。
     */
    public static function defaultConfigFor(string $driver): array
    {
        if ($driver === 'zjmf') {
            return YxUpstreamZjmf::defaultConfig();
        }
        if (in_array($driver, ['custom', 'lezhong'], true)) {
            $cfg = YxUpstreamCustom::defaultConfig();
            if ($driver === 'lezhong') {
                $cfg['_note'] = '【乐众系统】模板：请按对方接口文档校准 actions（动作名，或写成 /api/xxx 路径）、fields（业务字段名）、auth/sign_field（签名）、response（code_path/success_value/data_path/map/item_map）。';
            }
            return $cfg;
        }
        return [];
    }

    public static function driverName(string $code): string
    {
        return self::drivers()[$code]['name'] ?? $code;
    }

    public static function row(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return YxDb::one('SELECT * FROM ' . YxDb::table('upstream') . ' WHERE id = ?', [$id]);
    }

    /** 解析驱动实例 */
    public static function driver(array $row): YxUpstreamDriver
    {
        switch ($row['driver'] ?? 'mock') {
            case 'http':
                return new YxUpstreamHttp($row);
            // 兼容旧部署：target=custom 的供应商（含历史 zjmf/lezhong）保持通用驱动，
            // 它们库里已按 custom 模板保存过协议配置，切换驱动会改变其请求行为。
            case 'custom':
            case 'zjmf_custom':
            case 'lezhong':
                return new YxUpstreamCustom($row);
            case 'zjmf':
                return new YxUpstreamZjmf($row);
            case 'mock':
            default:
                return new YxUpstreamMock($row);
        }
    }

    /**
     * 拉取产品列表（带失败原因）
     *
     * 后台「拉取产品」按钮用它给出具体报错，而不是笼统的「0 个产品」。
     * 其余驱动没有独立的失败信息，沿用 products()。
     */
    public static function productsOf(array $row): array
    {
        $drv = self::driver($row);
        if ($drv instanceof YxUpstreamCustom || $drv instanceof YxUpstreamZjmf) {
            return $drv->productsInfo();
        }
        return ['ok' => true, 'msg' => 'ok', 'list' => $drv->products()];
    }

    /**
     * 拉取用于「上架到产品中心」的条目（带失败原因）
     *
     * 与 productsOf() 的区别只在魔方驱动：上架要做周期折算与库存判定，
     * 必须用原始条目里的 cycle / qty / stock_control。统一后的结构没有 cycle，
     * 折算会一律按 1 个月处理，年付商品的价格与周期上限都会算错。
     * 原始结构同样带 id（= 商品编码），勾选匹配不受影响。
     */
    public static function productsRaw(array $row): array
    {
        $drv = self::driver($row);
        if ($drv instanceof YxUpstreamZjmf) {
            return $drv->productsRaw();
        }
        return self::productsOf($row);
    }

    /** 供应商连通性测试，并回写结果 */
    public static function test(int $id): array
    {
        $row = self::row($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '供应商不存在'];
        }
        try {
            $r = self::driver($row)->test();
        } catch (Throwable $e) {
            $r = ['ok' => false, 'msg' => '异常：' . $e->getMessage()];
        }
        YxDb::update('upstream', [
            'last_test_at' => now(),
            'last_test_ok' => !empty($r['ok']) ? 1 : 0,
            'last_test_msg' => mb_substr((string)($r['msg'] ?? ''), 0, 255),
        ], 'id = :id', ['id' => $id]);
        return $r;
    }

    /**
     * 实时库存检测：查询产品绑定上游的剩余数量
     *
     * 复用驱动的产品列表接口，按映射里的 remote_code 找到对应产品并取 stock。
     * 上游未返回 stock 字段时 ok=true、known=false，用于区分「对接失败」与「对方未提供库存」。
     * 每次检测都会把结论写回供应商行的 last_stock_* 字段，后台「产品映射」列表据此展示，
     * 换会话或清缓存后结论不会丢失。
     *
     * @return array ok(bool) 检测是否成功、known(bool) 是否拿到库存数、stock(int|null)、
     *               msg(string) 面向后台的提示、remote_code(string)、upstream(array|null)
     */
    public static function stockCheck(int $productId): array
    {
        $out = ['ok' => false, 'known' => false, 'stock' => null, 'msg' => '', 'remote_code' => '', 'upstream' => null];

        $bind = self::mapForProduct($productId);
        if (!$bind) {
            return array_merge($out, ['msg' => '产品未绑定上游，或绑定的供应商已停用']);
        }
        $up   = $bind['upstream'];
        $code = trim((string)($bind['map']['remote_code'] ?? ''));
        $out['upstream']    = $up;
        $out['remote_code'] = $code;

        $upstreamId = (int)($up['id'] ?? 0);
        $info       = ['ok' => false, 'msg' => '', 'list' => []];
        $done       = function (array $r) use ($out, $upstreamId, &$info): array {
            $res = array_merge($out, $r);
            self::saveStockSnapshot($upstreamId, $res, $info);
            return $res;
        };

        if ($code === '') {
            return $done(['msg' => '产品映射未填写上游产品编码，无法定位库存']);
        }

        try {
            $drv  = self::driver($up);
            // 有 productsInfo() 的驱动（custom / zjmf）必须走它，否则拿不到失败原因，
            // 上游对接失败会被误报成「未找到编码」。
            $info = method_exists($drv, 'productsInfo')
                ? $drv->productsInfo()
                : ['ok' => true, 'msg' => 'ok', 'list' => $drv->products()];
        } catch (Throwable $e) {
            return $done(['msg' => '库存检测异常：' . $e->getMessage()]);
        }
        if (empty($info['ok'])) {
            return $done(['msg' => '获取上游产品列表失败：' . (string)($info['msg'] ?? '未知原因')]);
        }

        $found = null;
        foreach ((array)($info['list'] ?? []) as $item) {
            if ((string)($item['code'] ?? '') === $code) {
                $found = $item;
                break;
            }
        }
        if ($found === null) {
            return $done(['msg' => '上游产品列表中未找到编码「' . $code . '」，请核对编码或确认对方是否已下架']);
        }

        $stock = $found['stock'] ?? null;
        if ($stock === null) {
            return $done([
                'ok'  => true,
                'msg' => '上游响应中' . self::STOCK_UNKNOWN_HINT . '，请检查「响应解析 → item_map.stock」',
            ]);
        }

        $stock = (int)$stock;
        if ($stock < 0) {
            return $done([
                'ok'    => true,
                'known' => true,
                'stock' => $stock,
                'msg'   => '库存对接成功，上游标注为不限量（' . $stock . '）',
            ]);
        }
        return $done([
            'ok'    => true,
            'known' => true,
            'stock' => $stock,
            'msg'   => $stock > 0 ? '库存对接成功，上游剩余 ' . $stock . ' 台' : '库存对接成功，上游已售罄（剩余 0 台）',
        ]);
    }

    /**
     * 把库存检测结论写回供应商行的 last_stock_* 字段
     *
     * total = 本次上游返回产品里「已知剩余数量」之和（不限量不计入）；
     * in/out = 相对上次检测同一供应商的增减，入库为正、出库为负，首次检测记为 0。
     * 本次没有拿到任何已知库存（对接失败、上游只回空列表）时只更新时间/结果/提示，
     * 保留上次的 total/in/out，避免把「没查成」误记成出库。
     * 写入失败不抛出，避免影响检测结果本身的返回。
     */
    private static function saveStockSnapshot(int $upstreamId, array $result, array $info): void
    {
        if ($upstreamId <= 0) {
            return;
        }
        try {
            $total = 0;
            $known = 0;
            foreach ((array)($info['list'] ?? []) as $item) {
                $s = YxUpstreamBase::stockValue($item['stock'] ?? null);
                if ($s !== null && $s >= 0) {
                    $total += $s;
                    $known++;
                }
            }
            $data = [
                'last_stock_at'  => now(),
                'last_stock_ok'  => !empty($result['ok']) ? 1 : 0,
                'last_stock_msg' => mb_substr((string)($result['msg'] ?? ''), 0, 250),
            ];
            if ($known > 0) {
                $prev    = self::row($upstreamId);
                $hasPrev = trim((string)($prev['last_stock_at'] ?? '')) !== '';
                $diff    = $hasPrev ? $total - (int)($prev['last_stock_total'] ?? 0) : 0;
                $data['last_stock_total'] = $total;
                $data['last_stock_in']    = $diff > 0 ? $diff : 0;
                $data['last_stock_out']   = $diff < 0 ? -$diff : 0;
            }
            YxDb::update('upstream', $data, 'id = :id', ['id' => $upstreamId]);
        } catch (Throwable $e) {
            // 快照只是展示用，写不进去不影响检测结论
        }
    }

    /**
     * 供应商维度的库存快照（读 last_stock_* 字段，供后台展示）
     *
     * @param array $row 供应商行（含 last_stock_* 字段）
     * @return array|null null = 从未检测过；否则 ok / state / total / in / out / msg / at
     *                    state：fail 对接失败、unknown 上游未提供库存、empty 上游无货、ok 有货
     */
    public static function stockMemo(array $row): ?array
    {
        $at = (string)($row['last_stock_at'] ?? '');
        if ($at === '') {
            return null;
        }
        $ok    = (int)($row['last_stock_ok'] ?? 0) === 1;
        $total = (int)($row['last_stock_total'] ?? 0);
        $msg   = (string)($row['last_stock_msg'] ?? '');
        if (!$ok) {
            $state = 'fail';
        } elseif (strpos($msg, self::STOCK_UNKNOWN_HINT) !== false) {
            $state = 'unknown';
        } elseif ($total > 0) {
            $state = 'ok';
        } else {
            $state = 'empty';
        }
        return [
            'ok'    => $ok,
            'state' => $state,
            'total' => $total,
            'in'    => (int)($row['last_stock_in'] ?? 0),
            'out'   => (int)($row['last_stock_out'] ?? 0),
            'msg'   => $msg,
            'at'    => $at,
        ];
    }

    /** 产品绑定到的上游映射 */
    public static function mapForProduct(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        $map = YxDb::one(
            'SELECT * FROM ' . YxDb::table('upstream_map') . ' WHERE product_id = ? AND status = 1 ORDER BY id ASC LIMIT 1',
            [$productId]
        );
        if (!$map) {
            return null;
        }
        $up = self::row((int)$map['upstream_id']);
        if (!$up || (int)$up['status'] !== 1) {
            return null;
        }
        return ['map' => $map, 'upstream' => $up];
    }

    /** 映射的规格参数 */
    public static function specOf(array $map): array
    {
        $spec = json_decode((string)($map['spec'] ?? ''), true);
        return is_array($spec) ? $spec : [];
    }
}

/* ------------------------------------------------------------------ 开通执行器 */

/**
 * 业务实例 ↔ 上游实例 的操作执行器（由任务队列调用，也可在后台手动立即执行）
 */
class YxProvision
{
    /**
     * 解析业务实例对应的上游
     *
     * 业务可能挂平台上游（upstream_id），也可能挂客户自有上游（customer_upstream_id）。
     * 客户自有上游按「ID + 客户」双重校验，杜绝跨客户调用。
     *
     * @return array ok,msg,up(?array),conn(?array),customer(bool),missing(bool),disabled(bool)
     */
    public static function upstreamFor(array $svc): array
    {
        $connId = (int)($svc['customer_upstream_id'] ?? 0);
        if ($connId > 0) {
            $conn = YxDb::one(
                'SELECT * FROM ' . YxDb::table('customer_upstream') . ' WHERE id = ? AND customer_id = ?',
                [$connId, (int)$svc['customer_id']]
            );
            if (!$conn) {
                return ['ok' => false, 'msg' => '客户上游对接不存在或不属于该客户（可能已被删除）', 'customer' => true, 'missing' => true, 'disabled' => false, 'up' => null, 'conn' => null];
            }
            if ((int)$conn['status'] !== 1) {
                return ['ok' => false, 'msg' => '客户上游对接「' . $conn['name'] . '」已停用', 'customer' => true, 'missing' => false, 'disabled' => true, 'up' => null, 'conn' => $conn];
            }
            return ['ok' => true, 'msg' => 'ok', 'up' => YxCustomerUpstream::driverRow($conn), 'conn' => $conn, 'customer' => true, 'missing' => false, 'disabled' => false];
        }

        $up = YxUpstream::row((int)($svc['upstream_id'] ?? 0));
        if (!$up) {
            return ['ok' => false, 'msg' => '上游供应商已删除', 'customer' => false, 'missing' => true, 'disabled' => false, 'up' => null, 'conn' => null];
        }
        return ['ok' => true, 'msg' => 'ok', 'up' => $up, 'conn' => null, 'customer' => false, 'missing' => false, 'disabled' => false];
    }

    /** 开通：调用上游 create 并把结果写回业务实例 */
    public static function create(int $serviceId, array $opt = []): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc) {
            return ['ok' => false, 'msg' => '业务实例不存在'];
        }
        if ($svc['remote_id']) {
            return ['ok' => true, 'msg' => '该业务已在上游开通（' . $svc['remote_id'] . '）', 'remote_id' => $svc['remote_id']];
        }

        $isCustomer = (int)($svc['customer_upstream_id'] ?? 0) > 0;

        if ($isCustomer) {
            $ctx = self::upstreamFor($svc);
            if (empty($ctx['ok'])) {
                return ['ok' => false, 'msg' => $ctx['msg']];
            }
            $up   = $ctx['up'];
            $code = (string)($svc['remote_code'] ?? '');
            if ($code === '') {
                return ['ok' => false, 'msg' => '业务缺少上游产品编号，无法自动开通'];
            }
            $spec = json_decode((string)($svc['remote_spec'] ?? ''), true);
            if (!is_array($spec)) {
                $spec = [];
            }
            $params = array_merge($spec, [
                'product_code' => $code,
                'service_sn'   => $svc['sn'],
                'hostname'     => $svc['hostname'] ?: $svc['sn'],
                'cycle'        => $svc['billing_cycle'],
                'os'           => $opt['os'] ?? null,
                'external_id'  => $svc['external_id'],
            ]);
        } else {
            $bind = YxUpstream::mapForProduct((int)$svc['product_id']);
            if (!$bind) {
                return ['ok' => false, 'msg' => '产品未绑定上游（请在「上游对接」中配置产品映射）'];
            }
            $up  = $bind['upstream'];
            $map = $bind['map'];

            $params = array_merge(YxUpstream::specOf($map), [
                'product_code' => $map['remote_code'],
                'service_sn'   => $svc['sn'],
                'hostname'     => $svc['hostname'] ?: $svc['sn'],
                'cycle'        => $svc['billing_cycle'],
                'os'           => $opt['os'] ?? null,
                'external_id'  => $svc['external_id'],
            ]);
        }
        $params = array_filter($params, static fn($v) => $v !== null && $v !== '');

        try {
            $res = YxUpstream::driver($up)->create($params);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '调用上游异常：' . $e->getMessage()];
        }

        if (empty($res['ok'])) {
            YxDb::update('service', [
                'remote_status' => 'create_failed',
                'remote_raw'    => json_encode($res['raw'] ?? [], JSON_UNESCAPED_UNICODE),
                'updated_at'    => now(),
            ], 'id = :id', ['id' => $serviceId]);
            return ['ok' => false, 'msg' => '上游开通失败：' . ($res['msg'] ?? '未知错误')];
        }

        $update = [
            'upstream_id'   => $isCustomer ? (int)$svc['upstream_id'] : (int)$up['id'],
            'remote_id'     => (string)$res['remote_id'],
            'remote_status' => (string)($res['status'] ?: 'running'),
            'remote_raw'    => json_encode($res['raw'] ?? [], JSON_UNESCAPED_UNICODE),
            'status'        => 'active',
            'activated_at'  => $svc['activated_at'] ?: now(),
            'updated_at'    => now(),
        ];
        if (empty($svc['next_due_date'])) {
            $update['next_due_date'] = YxBusiness::nextDue($svc['billing_cycle']);
        }
        if (!empty($res['ip'])) {
            $update['ip'] = $res['ip'];
        }
        if (!empty($res['username'])) {
            $update['login_user'] = $res['username'];
        }
        if (!empty($res['password'])) {
            $update['login_pass'] = yx_encrypt((string)$res['password']);
        }
        YxDb::update('service', $update, 'id = :id', ['id' => $serviceId]);

        // 占用节点容量
        if ((int)$svc['node_id'] > 0) {
            YxDb::exec('UPDATE ' . YxDb::table('node') . ' SET used = used + 1 WHERE id = ?', [(int)$svc['node_id']]);
        }

        return ['ok' => true, 'msg' => '上游开通成功', 'remote_id' => $res['remote_id'], 'ip' => $res['ip'] ?? null];
    }

    /** 续费：通知上游顺延 */
    public static function renew(int $serviceId, string $cycle = ''): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc) {
            return ['ok' => false, 'msg' => '业务实例不存在'];
        }
        if (!$svc['remote_id']) {
            return ['ok' => true, 'msg' => '该业务无上游实例，仅本地顺延'];
        }
        $ctx = self::upstreamFor($svc);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'msg' => $ctx['msg']];
        }
        $up = $ctx['up'];
        try {
            $res = YxUpstream::driver($up)->renew((string)$svc['remote_id'], $cycle ?: (string)$svc['billing_cycle']);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '调用上游异常：' . $e->getMessage()];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => '上游续费失败：' . ($res['msg'] ?? '未知错误')];
        }
        return ['ok' => true, 'msg' => '上游续费成功', 'raw' => $res['raw'] ?? null];
    }

    /** 电源操作 */
    public static function power(int $serviceId, string $action): array
    {
        if (!in_array($action, ['start', 'stop', 'reboot'], true)) {
            return ['ok' => false, 'msg' => '不支持的电源操作'];
        }
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc || !$svc['remote_id']) {
            return ['ok' => false, 'msg' => '该业务没有上游实例，无法执行 ' . $action];
        }
        $ctx = self::upstreamFor($svc);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'msg' => $ctx['msg']];
        }
        $up = $ctx['up'];
        try {
            $res = YxUpstream::driver($up)->power((string)$svc['remote_id'], $action);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '调用上游异常：' . $e->getMessage()];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => '上游操作失败：' . ($res['msg'] ?? '未知错误')];
        }
        $map = ['start' => 'running', 'stop' => 'stopped', 'reboot' => 'running'];
        YxDb::update('service', [
            'remote_status' => $map[$action],
            'updated_at'    => now(),
        ], 'id = :id', ['id' => $serviceId]);
        return ['ok' => true, 'msg' => '上游电源操作成功：' . $action];
    }

    /** 重装系统 */
    public static function reinstall(int $serviceId, array $opt = []): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc || !$svc['remote_id']) {
            return ['ok' => false, 'msg' => '该业务没有上游实例，无法重装'];
        }
        $ctx = self::upstreamFor($svc);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'msg' => $ctx['msg']];
        }
        $up = $ctx['up'];
        try {
            $res = YxUpstream::driver($up)->reinstall((string)$svc['remote_id'], $opt);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '调用上游异常：' . $e->getMessage()];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => '上游重装失败：' . ($res['msg'] ?? '未知错误')];
        }
        return ['ok' => true, 'msg' => '已下发重装指令'];
    }

    /** 销毁 */
    public static function destroy(int $serviceId): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc) {
            return ['ok' => false, 'msg' => '业务实例不存在'];
        }
        if (!$svc['remote_id']) {
            return ['ok' => true, 'msg' => '该业务无上游实例，已直接终止'];
        }
        $ctx = self::upstreamFor($svc);
        if (empty($ctx['ok'])) {
            // 上游配置已删除：没有可销毁的远端实例，按已终止处理；
            // 上游被停用则报错，避免绕过停用限制继续操作。
            if (!empty($ctx['missing'])) {
                return ['ok' => true, 'msg' => '上游配置已删除，跳过销毁'];
            }
            return ['ok' => false, 'msg' => $ctx['msg']];
        }
        $up = $ctx['up'];
        try {
            $res = YxUpstream::driver($up)->destroy((string)$svc['remote_id']);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '调用上游异常：' . $e->getMessage()];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => '上游销毁失败：' . ($res['msg'] ?? '未知错误')];
        }
        // 注意：节点容量由 YxBusiness::terminateService 释放，此处不重复扣减
        YxDb::update('service', ['remote_status' => 'destroyed', 'updated_at' => now()], 'id = :id', ['id' => $serviceId]);
        return ['ok' => true, 'msg' => '上游实例已销毁'];
    }

    /** 同步上游状态 */
    public static function sync(int $serviceId): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc || !$svc['remote_id']) {
            return ['ok' => false, 'msg' => '该业务没有上游实例'];
        }
        $ctx = self::upstreamFor($svc);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'msg' => $ctx['msg']];
        }
        $up = $ctx['up'];
        try {
            $res = YxUpstream::driver($up)->status((string)$svc['remote_id']);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '调用上游异常：' . $e->getMessage()];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => '查询上游失败：' . ($res['msg'] ?? '未知错误')];
        }
        $update = ['remote_status' => (string)($res['status'] ?? 'unknown'), 'updated_at' => now()];
        if (!empty($res['ip'])) {
            $update['ip'] = $res['ip'];
        }
        YxDb::update('service', $update, 'id = :id', ['id' => $serviceId]);
        return ['ok' => true, 'msg' => '上游状态已同步：' . ($res['status'] ?? 'unknown'), 'status' => $res['status'] ?? null];
    }
}
