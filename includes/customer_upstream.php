<?php
/**
 * 优享财务系统 · 客户自有上游对接（我的业务「购买，自动对接」）
 *
 * 与平台级「上游对接」（includes/upstream.php）区分：
 *   - 平台上游：管理员配置 + 产品映射，面向全站销售
 *   - 客户上游：客户自助填写 API 地址 / 账户 ID / 密钥，仅对该客户可见可用
 *
 * 复用 YxUpstream 驱动（标准 HTTP/JSON、魔方财务、乐众系统、自定义 HTTP、本地模拟）与 YxProvision 执行器。
 * 密钥始终以 yx_encrypt() 密文落库、永不回显；接口地址做 SSRF 校验（仅 http/https，
 * 拒绝账号密码、内网/保留地址，解析失败即拒绝）。
 */

class YxCustomerUpstream
{
    /** 每个客户最多可添加的对接数 */
    const MAX_PER_CUSTOMER = 10;

    /** 可选对接协议 */
    const DRIVERS = [
        'http'    => '标准 HTTP/JSON',
        'zjmf'    => '魔方财务（idcsmart）对接',
        'lezhong' => '乐众系统对接',
        'custom'  => '自定义 HTTP（字段/签名全可配）',
        'mock'    => '本地模拟（演示/联调）',
    ];

    /** 需要用 JSON 协议配置描述字段/签名的驱动 */
    const CONFIG_DRIVERS = ['custom', 'zjmf', 'lezhong'];

    public static function driverName(string $code): string
    {
        return self::DRIVERS[$code] ?? $code;
    }

    /** 某客户的全部对接（新→旧） */
    public static function rows(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('customer_upstream') . ' WHERE customer_id = ? ORDER BY id DESC',
            [$customerId]
        );
    }

    public static function count(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }
        return (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('customer_upstream') . ' WHERE customer_id = ?',
            [$customerId]
        );
    }

    /** 按「ID + 客户」读取，天然隔离他人数据 */
    public static function row(int $id, int $customerId): ?array
    {
        if ($id <= 0 || $customerId <= 0) {
            return null;
        }
        return YxDb::one(
            'SELECT * FROM ' . YxDb::table('customer_upstream') . ' WHERE id = ? AND customer_id = ?',
            [$id, $customerId]
        );
    }

    /**
     * 新增 / 编辑对接配置
     *
     * @param array $in name,driver,endpoint,appid,secret,config,timeout,status,id
     * @return array ok,id,msg,test_ok
     */
    public static function save(int $customerId, array $in): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '请先登录'];
        }

        $id       = (int)($in['id'] ?? 0);
        $existing = $id > 0 ? self::row($id, $customerId) : null;
        if ($id > 0 && !$existing) {
            return ['ok' => false, 'msg' => '对接配置不存在或不属于当前账号'];
        }
        if (!$existing && self::count($customerId) >= self::MAX_PER_CUSTOMER) {
            return ['ok' => false, 'msg' => '最多可添加 ' . self::MAX_PER_CUSTOMER . ' 个上游对接，请先删除不用的'];
        }

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            $name = '上游对接';
        }
        $name = mb_substr($name, 0, 80);

        $driver = (string)($in['driver'] ?? 'http');
        if (!isset(self::DRIVERS[$driver])) {
            $driver = 'http';
        }

        $endpoint = trim((string)($in['endpoint'] ?? ''));
        if ($driver !== 'mock' || $endpoint !== '') {
            $check = self::validateEndpoint($endpoint);
            if (!$check['ok']) {
                return $check;
            }
            $endpoint = $check['url'];
        }

        $appid   = mb_substr(trim((string)($in['appid'] ?? '')), 0, 80);
        $secret  = trim((string)($in['secret'] ?? ''));
        $timeout = (int)($in['timeout'] ?? 10);
        if ($timeout < 5 || $timeout > 60) {
            $timeout = 10;
        }
        $status = (int)($in['status'] ?? 1) === 1 ? 1 : 0;

        // 协议配置：魔方财务 / 乐众 / 自定义 HTTP 用 JSON 描述字段映射、签名与响应解析
        $configJson = '';
        if (in_array($driver, self::CONFIG_DRIVERS, true)) {
            $raw = $in['config'] ?? '';
            if (is_array($raw)) {
                $raw = $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            }
            $raw = trim((string)$raw);
            if ($raw !== '') {
                if (strlen($raw) > 20000) {
                    return ['ok' => false, 'msg' => '协议配置过长（最多 20000 字符）'];
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    return ['ok' => false, 'msg' => '协议配置必须是合法的 JSON，请检查括号、引号与逗号'];
                }
                $configJson = (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($existing && trim((string)($existing['config'] ?? '')) !== '') {
                $configJson = (string)$existing['config']; // 留空 = 沿用已保存的配置
            } else {
                // 未填写时使用内置模板，保证保存后即可测试连通性
                $configJson = (string)json_encode(
                    YxUpstream::defaultConfigFor($driver),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        if ($driver !== 'mock') {
            if ($appid === '') {
                return ['ok' => false, 'msg' => '请填写上游账户 ID'];
            }
            if ($secret === '' && !$existing) {
                return ['ok' => false, 'msg' => '请填写上游密钥'];
            }
        }

        $data = [
            'customer_id' => $customerId,
            'name'        => $name,
            'driver'      => $driver,
            'endpoint'    => $endpoint !== '' ? $endpoint : null,
            'appid'       => $appid !== '' ? $appid : null,
            'timeout'     => $timeout,
            'status'      => $status,
            'updated_at'  => now(),
        ];
        if (in_array($driver, self::CONFIG_DRIVERS, true)) {
            $data['config'] = $configJson !== '' ? $configJson : null;
        }
        // 密钥留空 = 保持原值，绝不覆盖为明文/空串
        if ($secret !== '') {
            $data['secret'] = yx_encrypt($secret);
        }

        if ($existing) {
            YxDb::update(
                'customer_upstream',
                $data,
                'id = :id AND customer_id = :cid',
                ['id' => $id, 'cid' => $customerId]
            );
            $savedId = $id;
        } else {
            $data['secret']    = $data['secret'] ?? '';
            $data['created_at'] = now();
            $savedId = YxDb::insert('customer_upstream', $data);
        }

        // 保存即自动对接：立刻做一次连通性测试并回写结果
        $test   = self::test($savedId, $customerId);
        $okTest = !empty($test['ok']);
        $detail = trim((string)($test['msg'] ?? ''));
        $tail   = $okTest
            ? '✅ 对接成功' . ($detail !== '' && $detail !== 'ok' ? '：' . $detail : '，连接正常')
            : '❌ 对接失败：' . ($detail !== '' ? $detail : '未知错误');

        return [
            'ok'      => true,
            'id'      => $savedId,
            'msg'     => '已保存。' . $tail,
            'type'    => $okTest ? 'success' : 'error',
            'test_ok' => $okTest,
        ];
    }

    /** 删除对接（有未终止业务引用时拒绝，避免业务失联） */
    public static function remove(int $id, int $customerId): array
    {
        $row = self::row($id, $customerId);
        if (!$row) {
            return ['ok' => false, 'msg' => '对接配置不存在或不属于当前账号'];
        }
        $used = (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('service') . " WHERE customer_upstream_id = ? AND customer_id = ? AND status <> 'terminated'",
            [$id, $customerId]
        );
        if ($used > 0) {
            return ['ok' => false, 'msg' => '还有 ' . $used . ' 个业务正在使用该上游，请先终止相关业务'];
        }
        YxDb::delete('customer_upstream', 'id = :id AND customer_id = :cid', ['id' => $id, 'cid' => $customerId]);
        return ['ok' => true, 'msg' => '已删除上游对接'];
    }

    /** 连通性测试并回写测试状态 */
    public static function test(int $id, int $customerId): array
    {
        $conn = self::row($id, $customerId);
        if (!$conn) {
            return ['ok' => false, 'msg' => '对接配置不存在或不属于当前账号'];
        }
        try {
            $res = YxUpstream::driver(self::driverRow($conn))->test();
        } catch (Throwable $e) {
            $res = ['ok' => false, 'msg' => '异常：' . $e->getMessage()];
        }
        YxDb::update('customer_upstream', [
            'last_test_at'  => now(),
            'last_test_ok'  => !empty($res['ok']) ? 1 : 0,
            'last_test_msg' => mb_substr((string)($res['msg'] ?? ''), 0, 255),
            'updated_at'    => now(),
        ], 'id = :id AND customer_id = :cid', ['id' => $id, 'cid' => $customerId]);

        return [
            'ok'   => !empty($res['ok']),
            'msg'  => (string)($res['msg'] ?? ''),
            'type' => !empty($res['ok']) ? 'success' : 'error',
        ];
    }

    /**
     * 管理端/定时任务使用：按 id 测试任意客户的对接（不做归属校验）
     * 仅供后台巡检与插件自动检测调用，页面侧请继续使用 test($id, $customerId)。
     */
    public static function adminTest(int $id): array
    {
        $conn = YxDb::one('SELECT * FROM ' . YxDb::table('customer_upstream') . ' WHERE id = ?', [$id]);
        if (!$conn) {
            return ['ok' => false, 'msg' => '对接配置不存在'];
        }
        try {
            $res = YxUpstream::driver(self::driverRow($conn))->test();
        } catch (Throwable $e) {
            $res = ['ok' => false, 'msg' => '异常：' . $e->getMessage()];
        }
        YxDb::update('customer_upstream', [
            'last_test_at'  => now(),
            'last_test_ok'  => !empty($res['ok']) ? 1 : 0,
            'last_test_msg' => mb_substr((string)($res['msg'] ?? ''), 0, 255),
            'updated_at'    => now(),
        ], 'id = :id', ['id' => $id]);

        return [
            'ok'   => !empty($res['ok']),
            'msg'  => (string)($res['msg'] ?? ''),
            'type' => !empty($res['ok']) ? 'success' : 'error',
        ];
    }

    /**
     * 拉取上游可售产品
     *
     * @return array ok,msg,items[{code,name,price,stock,spec}]
     */
    public static function products(int $id, int $customerId): array
    {
        $conn = self::row($id, $customerId);
        if (!$conn) {
            return ['ok' => false, 'msg' => '对接配置不存在或不属于当前账号', 'items' => []];
        }
        try {
            $driver = YxUpstream::driver(self::driverRow($conn));
            $items  = $driver->products();
            if (!is_array($items) || !$items) {
                $t = $driver->test();
                if (empty($t['ok'])) {
                    return ['ok' => false, 'msg' => '上游连接失败：' . (string)($t['msg'] ?? '未知错误'), 'items' => []];
                }
                return ['ok' => true, 'msg' => '上游未返回可售产品', 'items' => []];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '获取上游产品异常：' . $e->getMessage(), 'items' => []];
        }

        $out = [];
        foreach ($items as $it) {
            if (!is_array($it) || (string)($it['code'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'code'  => (string)$it['code'],
                'name'  => (string)($it['name'] ?? $it['code']),
                'price' => round_money($it['price'] ?? 0),
                'stock' => (isset($it['stock']) && $it['stock'] !== null) ? (int)$it['stock'] : null,
                'spec'  => is_array($it['spec'] ?? null) ? $it['spec'] : [],
            ];
        }
        return ['ok' => true, 'msg' => 'ok', 'items' => $out];
    }

    /**
     * 转成 YxUpstream::driver() 需要的配置行
     * id 固定为 0：客户上游不写入 service.upstream_id，避免与平台上游混淆。
     */
    public static function driverRow(array $conn): array
    {
        $driver = (string)($conn['driver'] ?? 'http');
        if (!isset(self::DRIVERS[$driver])) {
            $driver = 'http';
        }
        return [
            'id'       => 0,
            'name'     => (string)($conn['name'] ?? ''),
            'driver'   => $driver,
            'endpoint' => (string)($conn['endpoint'] ?? ''),
            'appid'    => (string)($conn['appid'] ?? ''),
            'secret'   => (string)($conn['secret'] ?? ''), // 数据库内为密文，由驱动解密
            'config'   => (string)($conn['config'] ?? ''),
            'timeout'  => (int)($conn['timeout'] ?? 10),
            'status'   => (int)($conn['status'] ?? 1),
        ];
    }

    /**
     * 校验上游接口地址（SSRF 防护）
     *
     * @return array ok,msg,url
     */
    public static function validateEndpoint(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'msg' => '请填写上游 API 地址'];
        }
        if (strlen($url) > 255) {
            return ['ok' => false, 'msg' => '上游 API 地址过长（最多 255 字符）'];
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return ['ok' => false, 'msg' => '上游 API 地址格式不正确，需形如 https://api.example.com/open'];
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'msg' => '上游 API 地址只支持 http / https'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'msg' => '上游 API 地址不能包含账号密码'];
        }

        $host = strtolower(trim((string)$parts['host'], '[]'));
        if ($host === '' || $host === 'localhost') {
            return ['ok' => false, 'msg' => '不允许使用本机地址'];
        }
        // 纯数字 / 十六进制整数形式（如 http://2130706433/）一律拒绝
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host)) {
            return ['ok' => false, 'msg' => '不允许使用 IP 整数形式'];
        }

        $ips = self::resolveHost($host);
        if (!$ips) {
            return ['ok' => false, 'msg' => '域名无法解析，请检查地址是否正确'];
        }
        foreach ($ips as $ip) {
            if (self::ipBlocked($ip)) {
                return ['ok' => false, 'msg' => '上游 API 地址不可指向内网 / 保留地址'];
            }
        }

        return ['ok' => true, 'msg' => 'ok', 'url' => $url];
    }

    /** 解析域名到 IP（字面 IP 直接返回） */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $v4  = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = (string)$rec['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /** 是否属于内网 / 保留 / 不可路由地址 */
    private static function ipBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        // IPv4 映射的 IPv6 地址按 IPv4 判断
        if (stripos($ip, '::ffff:') === 0) {
            $v4 = substr($ip, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return self::ipBlocked($v4);
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            // 运营商级 NAT、基准测试网段等不在 PHP 保留网段内，需单独拦截
            $blocked = [
                ['100.64.0.0', 10],
                ['198.18.0.0', 15],
            ];
            foreach ($blocked as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return true;
                }
            }
        }
        return false;
    }
}
