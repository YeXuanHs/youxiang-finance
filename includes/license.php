<?php
/**
 * 优享财务系统 · 域名授权模块
 *
 * 同一份代码承担两个角色：
 *   客户端：按三要素「授权地址 + 授权ID + 授权密钥」向授权中心查询本站域名授权状态；
 *   服务端：作为授权中心，用同一套三要素校验下游站点的查询请求并签名应答。
 *
 * 校验顺序：内存 → 本地缓存 → 授权接口（三要素签名）→ 本地授权表回退。
 * 结果用于前台/后台展示「域名已正版授权」或「域名未在官方授权」。
 *
 * 签名算法（与开放接口 OpenAPI 一致，便于下游用同一套代码实现）：
 *   取除 sign 外的全部一级标量参数，过滤空值，按 key 升序拼成 k=v&k=v…，
 *   再做 HMAC-SHA256(规范化串, 授权密钥)，输出小写十六进制。
 */

class YxLicense
{
    const STATUS_OK      = 'authorized';
    const STATUS_NO      = 'unauthorized';
    const STATUS_EXPIRED = 'expired';
    const STATUS_BANNED  = 'banned';
    const STATUS_COMMUNITY = 'community';

    /** 授权方名称（对外展示） */
    const OFFICIAL_NAME = '喜泓网络科技官方';

    /** 请求时间戳容差（秒），与开放接口保持一致 */
    const SIGN_WINDOW = 300;

    /**
     * 内置授权开关
     *
     * true  ：本站域名一律视为「已购买授权包」，返回 STATUS_OK + 企业版，
     *         不联网、不查授权库、不看缓存（查询其它域名时仍按真实数据回答，
     *         以免本机作为授权中心对外应答时把别人的域名也放行）。
     * false ：恢复原逻辑（联网 / 本地授权表 / 缓存逐级回退）。
     */
    const ALWAYS_AUTHORIZED = true;

    /** 主程序包安装授权码的产品密钥（正版包固定值；与开源包不通用） */
    const PACKAGE_SECRET = 'YX-PKG-9F2C7A41D6B85E30A1C4F7D29B6E5A83C0D1F7E2';

    /** 单次请求内存缓存，避免重复校验 */
    private static $memo = [];

    /** 本站域名缓存（内置授权判定用，避免每次查询都回溯配置） */
    private static $selfDomain = null;

    /** 最近一次远程对接的失败原因（供后台「测试对接」展示） */
    private static $lastError = '';

    /** 最近一次 HTTP 请求的底层错误描述（curl 错误文本） */
    private static $lastHttpError = '';

    /** 锁定状态的进程内覆盖值（仅自检使用；不读取任何请求参数，无法从外部置位） */
    private static $lockOverride = null;

    /**
     * 授权模式
     *   official  ：正式版，按域名向官方接口/本地授权表校验
     *   community ：开源版，授权模块整体不启用——不联网校验、不写校验日志、
     *               不做版本收敛；界面标注「开源版」，不会把未授权站点伪装成「已正版授权」。
     *               （标识值 community 沿用既有数据与配置，仅界面文案为「开源版」）
     */
    public static function mode(): string
    {
        // 锁定且允许覆盖时，模式以配置为准（不受 setting 表影响，防止运行期被改写）
        if (self::$lockOverride === true) {
            return (string)config('license.mode', 'community') === 'community' ? 'community' : 'official';
        }
        $m = (string)setting('license_mode', config('license.mode', 'official'));
        return $m === 'community' ? 'community' : 'official';
    }

    public static function isCommunity(): bool
    {
        return self::mode() === 'community';
    }

    /**
     * 是否允许切换授权模式（开源版 → 正式版）
     *
     * 开源版发行包在配置里写死 license.lock_mode = true：
     *   - 后台「系统设置 → 授权模式」不再提供切换项；
     *   - 即使伪造 POST 直接提交 license_mode=official 也会被拒绝；
     *   - 只能改代码移除该锁定才会生效（开源版本就是永久开源，不存在「切回正式版」）。
     * 正式版发行包与本站不设锁定，保持原有行为。
     */
    public static function modeLocked(): bool
    {
        return self::$lockOverride === true || (bool)config('license.lock_mode', false);
    }

    /**
     * 仅供自检脚本在同一进程内切换「锁定 / 未锁定」两种发行形态，验证锁定分支。
     * 参数不是请求数据，站点任何入口都不会调用它。
     */
    public static function debugSetLockedMode(?bool $locked): void
    {
        self::$lockOverride = $locked;
        self::$memo = [];
    }

    /**
     * 设置授权模式；锁定状态下只接受当前的 community，任何切换尝试返回 false。
     */
    public static function setMode(string $mode): bool
    {
        $mode = $mode === 'community' ? 'community' : 'official';
        if (self::modeLocked()) {
            // 锁定版不允许改变模式：仅在配置本身就是 community 时保持现状
            $locked = (string)config('license.mode', 'community') === 'community' ? 'community' : 'official';
            if ($mode !== $locked) {
                return false;
            }
            $mode = $locked;
        }
        set_setting('license_mode', $mode);
        return true;
    }

    /** 开源版结果：等价于「不限制」，但状态与文案明确区别于正版 */
    private static function communityResult(string $domain): array
    {
        return [
            'domain'      => $domain,
            'authorized'  => true,
            'status'      => self::STATUS_COMMUNITY,
            'edition'     => 'enterprise',
            'issued_at'   => null,
            'expire_at'   => null,
            'license_key' => null,
            'official'    => self::OFFICIAL_NAME,
            'source'      => 'community',
            'message'     => '开源版 · 未启用授权模块',
            'checked_at'  => now(),
        ];
    }

    /** 内置授权结果：本站视同已购买授权包（企业版、永久有效） */
    private static function builtinResult(string $domain): array
    {
        return [
            'domain'      => $domain,
            'authorized'  => true,
            'status'      => self::STATUS_OK,
            'edition'     => 'enterprise',
            'issued_at'   => null,
            'expire_at'   => null,
            'license_key' => null,
            'official'    => self::OFFICIAL_NAME,
            'source'      => 'builtin',
            'message'     => '已正版授权（内置授权包 · 永久有效）',
            'checked_at'  => now(),
        ];
    }

    /**
     * 查询域名授权状态
     *
     * @param string $domain 待校验域名
     * @param bool   $force  忽略缓存强制查询
     * @return array
     */
    public static function query(string $domain, bool $force = false): array
    {
        $domain = normalize_domain($domain);

        // 内置授权：本站域名（含空域名）直接判为已授权，短路掉联网与缓存逻辑
        if (self::ALWAYS_AUTHORIZED) {
            if (self::$selfDomain === null) {
                try {
                    self::$selfDomain = normalize_domain(current_domain());
                } catch (Throwable $e) {
                    self::$selfDomain = '';
                }
            }
            $selfDomain = (string)self::$selfDomain;
            if ($selfDomain === '' || $domain === '' || $domain === $selfDomain) {
                return self::$memo[$domain] = self::builtinResult($domain);
            }
        }

        if ($domain === '') {
            return self::result('', self::STATUS_NO, 'local', '无法识别当前域名');
        }
        if (!$force && isset(self::$memo[$domain])) {
            return self::$memo[$domain];
        }

        // 开源版：整体跳过授权校验
        if (self::isCommunity()) {
            return self::$memo[$domain] = self::communityResult($domain);
        }

        $ttl = (int)config('license.cache_ttl', 21600);

        if (!$force) {
            $cached = self::cacheGet($domain, $ttl);
            if ($cached !== null) {
                $cached['source'] = 'cache';
                return self::$memo[$domain] = $cached;
            }
        }

        // 1) 官方接口
        $remote = self::remoteQuery($domain);
        if ($remote !== null) {
            self::cachePut($domain, $remote);
            return self::$memo[$domain] = $remote;
        }

        // 2) 本地授权表回退
        if (config('license.offline_ok', true)) {
            $local = self::localQuery($domain);
            // 离线回退结论只短暂缓存（最长 5 分钟），授权地址恢复后能尽快切回联网结论
            self::cachePut($domain, $local, min($ttl, 300));
            return self::$memo[$domain] = $local;
        }

        $failed = self::result($domain, self::STATUS_NO, 'remote', '官方授权接口暂时不可用');
        self::cachePut($domain, $failed, min($ttl, 60));
        return self::$memo[$domain] = $failed;
    }

    /** 校验当前访问域名 */
    public static function check(bool $force = false): array
    {
        return self::query(current_domain(), $force);
    }

    /** 徽标文案：正版 / 未授权 */
    public static function badge(?string $domain = null): array
    {
        $r = $domain === null ? self::check() : self::query($domain);
        $map = [
            self::STATUS_OK        => ['text' => '域名已正版授权', 'class' => 'ok',   'icon' => '✓'],
            self::STATUS_NO        => ['text' => '域名未在官方授权', 'class' => 'bad',  'icon' => '✕'],
            self::STATUS_EXPIRED   => ['text' => '授权已过期，请续费', 'class' => 'warn', 'icon' => '!'],
            self::STATUS_BANNED    => ['text' => '授权已被官方封禁', 'class' => 'bad',  'icon' => '✕'],
            self::STATUS_COMMUNITY => ['text' => '开源版 · 未启用授权模块', 'class' => 'info', 'icon' => '○'],
        ];
        $b = $map[$r['status']] ?? $map[self::STATUS_NO];
        $b['detail'] = $r;
        return $b;
    }

    public static function isAuthorized(?string $domain = null): bool
    {
        $r = $domain === null ? self::check() : self::query($domain);
        return $r['status'] === self::STATUS_OK;
    }

    /**
     * 当前允许使用的最高版本
     *  - 已授权：以授权版本为准
     *  - 未授权：默认仅提示不限制；开启 license_enforce 后收敛为普惠版
     */
    public static function maxEdition(): string
    {
        // 开源版不限制版本
        if (self::isCommunity()) {
            return 'enterprise';
        }
        $r = self::check();
        if ($r['status'] === self::STATUS_OK && isset(YxEdition::EDITIONS[$r['edition']])) {
            return $r['edition'];
        }
        return setting('license_enforce', '0') === '1' ? 'basic' : 'enterprise';
    }

    /**
     * 按域名签发授权码：同一域名 + 版本 结果稳定可复核
     * 密钥优先取「授权密钥」，未配置时回退 app.key，保证既有授权码仍然可复核。
     */
    public static function generateKey(string $domain, string $edition = 'basic', string $expire = ''): string
    {
        $domain  = normalize_domain($domain);
        $secret  = self::channelSecret();
        $raw     = strtoupper(hash_hmac('sha256', $domain . '|' . $edition . '|' . $expire, $secret));
        $raw     = substr($raw, 0, 16);
        return implode('-', str_split($raw, 4));
    }

    /** 校验授权码与域名是否匹配 */
    public static function verifyKey(string $domain, string $key, string $expire = ''): bool
    {
        // 兼容带/不带过期时间的签发方式
        return hash_equals(self::generateKey($domain, 'basic', $expire), $key)
            || hash_equals(self::generateKey($domain, 'pro', $expire), $key)
            || hash_equals(self::generateKey($domain, 'enterprise', $expire), $key);
    }

    /**
     * 主程序包安装授权码的产品密钥
     *
     * 主程序包的授权码要在「买家刚解压、尚未安装」的站点上离线复核：此时既没有
     * 数据库，app.key 也还没生成，所以不能用站点级密钥签名，只能用发行包自带的
     * 固定产品密钥。正版包与开源包用不同产品标识，两者的码互不通用。
     *
     * 密钥即上面的 PACKAGE_SECRET 常量（明文，便于核对与轮换）。
     */
    public static function packageSecret(): string
    {
        return self::PACKAGE_SECRET;
    }

    /** 主程序包产品标识：official（正版）/ community（开源） */
    public static function packageProduct(string $mode = ''): string
    {
        $mode = $mode !== '' ? $mode : (string)config('license.mode', 'official');
        return $mode === 'community' ? 'community' : 'official';
    }

    /** 按域名签发主程序包安装授权码（与站点 app.key 无关，离线可复核） */
    public static function packageKey(string $domain, string $product = 'official'): string
    {
        $secret = self::packageSecret();
        if ($secret === '') {
            return '';
        }
        $domain  = normalize_domain($domain);
        $product = $product === 'community' ? 'community' : 'official';
        $raw     = strtoupper(hash_hmac('sha256', 'package|' . $product . '|' . $domain, $secret));
        return implode('-', str_split(substr($raw, 0, 16), 4));
    }

    /** 记录一次校验日志（开源版不启用授权模块，直接跳过） */
    public static function log(string $domain, string $result, string $source, string $message = '', string $appId = ''): void
    {
        if (self::isCommunity()) {
            return;
        }
        if ($appId === '' && $source === 'server') {
            // 服务端应答时记录调用方身份，便于授权中心审计
            $appId = (string)($_REQUEST['app_id'] ?? '');
        }
        try {
            YxDb::insert('license_log', [
                'domain'     => normalize_domain($domain),
                'app_id'     => $appId !== '' ? substr($appId, 0, 40) : null,
                'result'     => $result,
                'source'     => $source,
                'ip'         => client_ip(),
                'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'message'    => $message,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // 忽略
        }
    }

    /* ------------------------------------------------------------------ */
    /* 对外：作为官方授权服务器响应查询                                     */
    /* ------------------------------------------------------------------ */

    /**
     * 授理来自其它站点的域名授权查询（本文件部署在授权中心时使用）
     *
     * @param array|null $app 已通过三要素校验的调用方应用；传 null 时不签名应答
     *                        （用于本地/内部直接调用，例如定时任务与自检）。
     */
    public static function serveQuery(?array $app = null): array
    {
        $domain = normalize_domain((string)($_REQUEST['domain'] ?? ''));
        if ($domain === '') {
            return self::respond($domain, ['code' => 400, 'msg' => '缺少 domain 参数', 'data' => [
                'domain'     => '',
                'authorized' => false,
                'status'     => self::STATUS_NO,
                'message'    => '缺少 domain 参数',
            ]], $app);
        }

        $row = null;
        try {
            $row = YxDb::one(
                'SELECT * FROM ' . YxDb::table('license') . ' WHERE domain = ? LIMIT 1',
                [$domain]
            );
        } catch (Throwable $e) {
            return self::respond($domain, ['code' => 500, 'msg' => '授权库不可用', 'data' => [
                'domain'     => $domain,
                'authorized' => false,
                'status'     => self::STATUS_NO,
                'message'    => '授权库不可用',
            ]], $app);
        }

        if (!$row) {
            self::log($domain, self::STATUS_NO, 'server', '未在授权库中找到该域名');
            return self::respond($domain, ['code' => 0, 'msg' => '域名未在官方授权', 'data' => [
                'domain'     => $domain,
                'authorized' => false,
                'status'     => self::STATUS_NO,
                'issued_at'  => null,
                'expire_at'  => null,
                'official'   => self::OFFICIAL_NAME,
                'message'    => '域名未在官方授权',
            ]], $app);
        }

        $status     = self::mapDbStatus((string)$row['status'], (string)($row['expire_at'] ?? ''));
        $authorized = $status === self::STATUS_OK;
        $message    = $authorized ? '域名已正版授权' : self::statusText($status);

        YxDb::exec('UPDATE ' . YxDb::table('license') . ' SET last_check_at = ? WHERE id = ?', [now(), $row['id']]);
        self::log($domain, $status, 'server', $message);

        return self::respond($domain, ['code' => 0, 'msg' => $message, 'data' => [
            'domain'      => $domain,
            'authorized'  => $authorized,
            'status'      => $status,
            'edition'     => $row['edition'],
            'issued_at'   => $row['bound_at'] ?: ($row['created_at'] ?: null),
            'expire_at'   => $row['expire_at'],
            'license_key' => $row['license_key'],
            'official'    => self::OFFICIAL_NAME,
            'message'     => $message,
        ]], $app);
    }

    /** 组装应答并按调用方密钥签名（无调用方时不签名，保持本地调用兼容） */
    private static function respond(string $domain, array $res, ?array $app): array
    {
        if ($app === null || empty($res['data'])) {
            return $res;
        }
        $secret = yx_decrypt((string)($app['secret'] ?? ''));
        if ($secret === '') {
            return $res;
        }
        $res['sign'] = self::sign((array)$res['data'], $secret);
        $res['ts']   = time();
        // 记录调用日志，便于授权中心侧审计
        try {
            YxDb::insert('api_log', [
                'appid'      => (string)$app['appid'],
                'action'     => 'license.query',
                'code'       => (int)$res['code'],
                'msg'        => mb_substr((string)$res['msg'], 0, 200),
                'ip'         => client_ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // 日志失败不影响授权结论
        }
        return $res;
    }

    public static function statusText(string $status): string
    {
        $map = [
            self::STATUS_OK        => '域名已正版授权',
            self::STATUS_NO        => '域名未在官方授权',
            self::STATUS_EXPIRED   => '授权已过期，请续费',
            self::STATUS_BANNED    => '授权已被官方封禁',
            self::STATUS_COMMUNITY => '开源版 · 未启用授权模块',
        ];
        return $map[$status] ?? '域名未在官方授权';
    }

    /* ------------------------------------------------------------------ */
    /* 内部实现                                                            */
    /* ------------------------------------------------------------------ */

    private static function result(string $domain, string $status, string $source, string $message = ''): array
    {
        return [
            'domain'      => $domain,
            'authorized'  => $status === self::STATUS_OK,
            'status'      => $status,
            'edition'     => 'basic',
            'issued_at'   => null,
            'expire_at'   => null,
            'license_key' => null,
            'official'    => self::OFFICIAL_NAME,
            'source'      => $source,
            'message'     => $message !== '' ? $message : self::statusText($status),
            'checked_at'  => now(),
        ];
    }

    /** 生效的官方授权服务器地址（后台设置优先于配置文件） */
    public static function officialApi(): string
    {
        $api = trim((string)setting('license_official_api', ''));
        if ($api === '') {
            $api = trim((string)config('license.official_api', ''));
        }
        return $api;
    }

    /** 生效的查询接口路径 */
    public static function queryPath(): string
    {
        $p = trim((string)setting('license_query_path', ''));
        return $p !== '' ? $p : (string)config('license.query_path', '/api/license.php');
    }

    /* ------------------------------------------------------------------ */
    /* 三要素：授权地址 + 授权ID + 授权密钥                                 */
    /* ------------------------------------------------------------------ */

    /** 授权ID（客户端身份标识，后台可生成） */
    public static function appId(): string
    {
        $id = trim((string)setting('license_app_id', ''));
        return $id !== '' ? $id : trim((string)config('license.app_id', ''));
    }

    /** 授权密钥（用于 HMAC-SHA256 签名，后台可重置） */
    public static function channelSecret(): string
    {
        $s = trim((string)setting('license_channel_secret', ''));
        if ($s === '') {
            $s = trim((string)config('license.channel_secret', ''));
        }
        // 兼容历史配置：未单独配置时回退到 app.key（旧版本默认行为）
        return $s !== '' ? $s : (string)config('app.key', '');
    }

    /** 三要素是否已完整配置 */
    public static function hasCredentials(): bool
    {
        return self::officialApi() !== '' && self::appId() !== '' && self::channelSecret() !== '';
    }

    /** 最近一次远程对接的失败原因（供后台「测试对接」展示） */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * 规范化签名串：取除 sign 外的全部一级标量参数，过滤空值，按 key 升序，
     * 拼成 k=v&k=v…，与开放接口 YxApi::sign 算法保持一致。
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign'], $params['_csrf']);
        $flat = [];
        foreach ($params as $k => $v) {
            if (is_array($v) || is_object($v) || $v === null || $v === '') {
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

    /**
     * 服务端校验下游站点的三要素请求。
     * @return array ['ok'=>bool, 'app'=>?array, 'msg'=>string, 'code'=>int]
     */
    public static function verifyRequest(array $req): array
    {
        $appId  = trim((string)($req['app_id'] ?? ''));
        $ts     = trim((string)($req['ts'] ?? ''));
        $nonce  = trim((string)($req['nonce'] ?? ''));
        $sign   = trim((string)($req['sign'] ?? ''));

        if ($appId === '' || $sign === '') {
            return ['ok' => false, 'app' => null, 'msg' => '缺少 app_id 或 sign', 'code' => 401];
        }
        if (!preg_match('/^\d{1,12}$/', $ts) || abs(time() - (int)$ts) > self::SIGN_WINDOW) {
            return ['ok' => false, 'app' => null, 'msg' => '请求时间戳超出允许范围（±' . self::SIGN_WINDOW . ' 秒），请校准服务器时间', 'code' => 401];
        }
        if (strlen($nonce) < 8 || strlen($nonce) > 64) {
            return ['ok' => false, 'app' => null, 'msg' => 'nonce 长度需为 8-64 个字符', 'code' => 401];
        }

        $app = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ? LIMIT 1', [$appId]);
        if (!$app) {
            return ['ok' => false, 'app' => null, 'msg' => '授权ID不存在', 'code' => 401];
        }
        if ((int)$app['status'] !== 1) {
            return ['ok' => false, 'app' => null, 'msg' => '该授权ID已被禁用', 'code' => 403];
        }

        $secret = yx_decrypt((string)$app['secret']);
        if ($secret === '' || !hash_equals(self::sign($req, $secret), $sign)) {
            return ['ok' => false, 'app' => null, 'msg' => '签名校验失败', 'code' => 401];
        }

        // 动作白名单（授权ID 若只开放 license.query，则不得用于其它动作）
        $allow = trim((string)($app['allow_actions'] ?? ''));
        if ($allow !== '') {
            $list = array_filter(array_map('trim', explode(',', $allow)));
            if (!in_array('license.query', $list, true)) {
                return ['ok' => false, 'app' => null, 'msg' => '该授权ID未开放授权查询动作', 'code' => 403];
            }
        }

        // 频次限制（按分钟统计调用日志）
        $limit = (int)($app['rate_limit'] ?? 0);
        if ($limit > 0) {
            $used = (int)YxDb::value(
                'SELECT COUNT(*) FROM ' . YxDb::table('api_log') . ' WHERE appid = ? AND created_at >= ?',
                [$appId, date('Y-m-d H:i:s', time() - 60)]
            );
            if ($used >= $limit) {
                return ['ok' => false, 'app' => null, 'msg' => '调用过于频繁，每分钟上限 ' . $limit . ' 次', 'code' => 429];
            }
        }

        // 防重放：同 appid 内 nonce 一次性
        if (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('api_nonce') . ' WHERE appid = ? AND nonce = ?', [$appId, $nonce])) {
            return ['ok' => false, 'app' => null, 'msg' => 'nonce 已被使用（疑似重放请求）', 'code' => 401];
        }
        YxDb::insert('api_nonce', ['appid' => $appId, 'nonce' => $nonce, 'created_at' => now()]);

        return ['ok' => true, 'app' => $app, 'msg' => 'ok', 'code' => 0];
    }

    /** 查询官方授权接口 */
    private static function remoteQuery(string $domain): ?array
    {
        self::$lastError = '';
        $api = self::officialApi();
        if ($api === '') {
            return null;
        }
        // 三要素缺一不可：授权地址 + 授权ID + 授权密钥
        $appId  = self::appId();
        $secret = self::channelSecret();
        if ($appId === '' || $secret === '') {
            self::$lastError = '未配置授权ID或授权密钥（授权中心 → 授权对接）';
            return null;
        }
        $url = rtrim($api, '/') . self::queryPath();

        $params = [
            'app_id'  => $appId,
            'domain'  => $domain,
            'version' => (string)config('app.version', '1.0.0'),
            'ts'      => (string)time(),
            'nonce'   => random_nonce(16),
        ];
        $params['sign'] = self::sign($params, $secret);
        $target  = $url . '?' . http_build_query($params);
        $timeout = max(2, (int)config('license.timeout', 5));

        // 授权地址指向本机时优先走回环：云主机/NAT 环境下服务器无法访问自身公网 IP，
        // 直连必然超时；回环请求仍保留 Host 与 SNI，签名、时间戳、防重放校验完全一致。
        $loopback = self::loopbackIp($api);
        $body     = null;
        if ($loopback !== null) {
            $body = self::httpGet($target, $timeout, $loopback);
            // 回环拿到的不是接口应答（例如本机 nginx 未配置该域名），退回直连再试
            if ($body !== null && !is_array(json_decode($body, true))) {
                $body = null;
            }
        }
        if ($body === null) {
            $body = self::httpGet($target, $timeout);
        }
        if ($body === null) {
            $err = self::$lastHttpError;
            self::$lastError = '请求授权接口失败（' . $url . ' 不可达或超时'
                . ($err !== '' ? '：' . $err : '')
                . ($loopback !== null ? '；已尝试本机回环地址仍失败' : '')
                . '）';
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            self::$lastError = '授权接口返回格式异常：' . mb_substr($body, 0, 120);
            return null;
        }
        if (!isset($json['data'])) {
            // 授权中心明确拒绝（402/401/403/429 等），把原因透出，避免误报「格式异常」
            $code = (int)($json['code'] ?? 0);
            $msg  = trim((string)($json['msg'] ?? ''));
            self::$lastError = ($code !== 0 || $msg !== '')
                ? '授权接口拒绝请求（code ' . $code . '）：' . ($msg !== '' ? $msg : '授权中心未返回说明')
                : '授权接口返回格式异常：' . mb_substr($body, 0, 120);
            return null;
        }
        // 应答签名校验：防止中间人伪造授权结论
        $expectSign = (string)($json['sign'] ?? '');
        if ($expectSign !== '' && !hash_equals(self::sign($json['data'], $secret), $expectSign)) {
            self::$lastError = '授权接口应答签名校验失败（疑似中间人篡改）';
            return null;
        }
        $d = $json['data'];
        $status = $d['status'] ?? (($d['authorized'] ?? false) ? self::STATUS_OK : self::STATUS_NO);

        return [
            'domain'      => $domain,
            'authorized'  => $status === self::STATUS_OK,
            'status'      => $status,
            'edition'     => $d['edition'] ?? 'basic',
            'issued_at'   => $d['issued_at'] ?? null,
            'expire_at'   => $d['expire_at'] ?? null,
            'license_key' => $d['license_key'] ?? null,
            'official'    => $d['official'] ?? self::OFFICIAL_NAME,
            'source'      => 'remote',
            'message'     => $d['message'] ?? self::statusText($status),
            'checked_at'  => now(),
        ];
    }

    /**
     * 授权库状态 → 对外协议状态
     * 库中存 active/suspended/banned，对外统一为 authorized/unauthorized/expired/banned，
     * 保证本地判定与官方接口返回的 status 语义一致。
     */
    private static function mapDbStatus(string $dbStatus, string $expireAt = ''): string
    {
        if ($dbStatus === 'banned') {
            return self::STATUS_BANNED;
        }
        if ($dbStatus === 'suspended') {
            return self::STATUS_NO;
        }
        if ($dbStatus === 'active') {
            if ($expireAt !== '' && $expireAt < date('Y-m-d')) {
                return self::STATUS_EXPIRED;
            }
            return self::STATUS_OK;
        }
        return self::STATUS_NO;
    }

    /** 本地授权表查询（离线回退） */
    private static function localQuery(string $domain): array
    {
        try {
            $row = YxDb::one('SELECT * FROM ' . YxDb::table('license') . ' WHERE domain = ? LIMIT 1', [$domain]);
        } catch (Throwable $e) {
            return self::result($domain, self::STATUS_NO, 'local', '本地授权表不可用');
        }
        if (!$row) {
            return self::result($domain, self::STATUS_NO, 'local');
        }

        $status = self::mapDbStatus((string)$row['status'], (string)($row['expire_at'] ?? ''));

        $r = self::result($domain, $status, 'local');
        $r['edition']     = $row['edition'];
        $r['issued_at']   = $row['bound_at'] ?: ($row['created_at'] ?: null);
        $r['expire_at']   = $row['expire_at'];
        $r['license_key'] = $row['license_key'];
        return $r;
    }

    private static function cacheFile(): string
    {
        return YX_ROOT . '/runtime/license_cache.json';
    }

    private static function cacheGet(string $domain, int $ttl): ?array
    {
        if ($ttl <= 0) {
            return null;
        }
        $file = self::cacheFile();
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $all = json_decode($raw, true);
        if (!is_array($all) || !isset($all[$domain])) {
            return null;
        }
        $item = $all[$domain];
        // 每条缓存可按来源指定有效期：联网结论用完整 TTL，离线回退结论只短暂缓存
        if (isset($item['ttl']) && (int)$item['ttl'] > 0) {
            $ttl = min($ttl, (int)$item['ttl']);
        }
        if (($item['ts'] ?? 0) + $ttl < time()) {
            return null;
        }
        return $item['data'] ?? null;
    }

    private static function cachePut(string $domain, array $data, ?int $ttl = null): void
    {
        $file = self::cacheFile();
        $all  = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $tmp = json_decode($raw, true);
                if (is_array($tmp)) {
                    $all = $tmp;
                }
            }
        }
        $all[$domain] = ['ts' => time(), 'ttl' => $ttl !== null ? max(0, $ttl) : null, 'data' => $data];

        // 控制缓存条数，防止无限增长
        if (count($all) > 500) {
            $all = array_slice($all, -500, null, true);
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        @file_put_contents($file, json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** 清空授权缓存 */
    public static function flushCache(): void
    {
        self::$memo = [];
        @unlink(self::cacheFile());
    }

    private static function httpGet(string $url, int $timeout = 5, ?string $resolveIp = null): ?string
    {
        self::$lastHttpError = '';
        if (function_exists('curl_init')) {
            $ch   = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'YouxiangFinance/' . config('app.version', '1.0.0'),
            ];
            if ($resolveIp !== null) {
                // 强制解析到回环地址，但 Host 头与 TLS SNI 仍是原域名，证书校验照常生效
                $host = (string)parse_url($url, PHP_URL_HOST);
                $port = (int)parse_url($url, PHP_URL_PORT);
                if ($port <= 0) {
                    $port = strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443;
                }
                $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolveIp];
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $err  = curl_errno($ch);
            if ($err !== 0) {
                self::$lastHttpError = trim((string)curl_error($ch));
            }
            curl_close($ch);
            return ($err === 0 && is_string($body)) ? $body : null;
        }

        $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body)) {
            $last = error_get_last();
            self::$lastHttpError = trim((string)($last['message'] ?? '请求失败'));
            return null;
        }
        return $body;
    }

    /**
     * 授权地址是否指向本机，返回可用于回环请求的 IP（不指向本机时返回 null）。
     *
     * 只在两种确定的场景下回环，避免把其它服务器误判为本机：
     *   1) 授权地址就是本站域名（同一套代码既是客户端又是授权中心）；
     *   2) 授权地址解析到本机回环或本机网卡 IP。
     * 云主机/NAT 环境访问自身公网 IP 会超时，这类站点若不回环就无法取得授权结论。
     */
    private static function loopbackIp(string $api): ?string
    {
        $host = strtolower((string)parse_url($api, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null; // 已经是回环地址，无需改写解析
        }

        $selfHosts = array_filter([current_domain(), site_domain()]);
        if (in_array($host, $selfHosts, true)) {
            return '127.0.0.1';
        }

        $ips = @gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (self::isLocalIp((string)$ip)) {
                    return '127.0.0.1';
                }
            }
        }
        return null;
    }

    /** 是否为本机地址（回环 / 本机网卡 IP） */
    private static function isLocalIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '127.') === 0) {
            return true;
        }
        $local = [];
        if (!empty($_SERVER['SERVER_ADDR'])) {
            $local[] = (string)$_SERVER['SERVER_ADDR'];
        }
        $name = (string)gethostname();
        if ($name !== '') {
            $resolved = @gethostbyname($name);
            if (is_string($resolved) && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $local[] = $resolved;
            }
        }
        return in_array($ip, array_filter($local), true);
    }
}
