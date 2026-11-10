<?php
/**
 * 优享财务系统 · 搜索引擎主动推送（百度 / 360 / 搜狗 / 神马 / 头条 + IndexNow）
 *
 * 设计要点（均来自实测结论，勿随意改动）：
 *   1. 不是所有平台都提供主动推送接口。当前真实可用的是：
 *        · 百度       data.zz.baidu.com/urls        校验失败返回 {"error":401,...}
 *        · 神马       data.zhanzhang.sm.cn/push      校验失败返回 {"returnCode":201,...}
 *        · IndexNow   www.bing.com/indexnow          通道本身免鉴权，202 = 已受理
 *      360、搜狗、头条的站长平台推送接口在实测中已不可用（404 / 登录跳转 / 500），
 *      因此统一走 sitemap.xml 被动收录，页面上如实标注，不伪装成"推送成功"。
 *   2. 各平台接口地址允许后台覆盖（vendors 会调整域名与路径），避免硬编码过期。
 *   3. 站点 URL 不能依赖 base_url()：CLI（定时任务）下它返回空串，必须从
 *      site_domain()/site_url 解析，否则定时推送会全部失败。
 *   4. 推送必须限流。首页每天推一次即可，重复推送会被平台判为作弊并封接口。
 */

class YxSeoPush
{
    /* ------------------------------ 设置键 ------------------------------ */

    const SET_ENABLED   = 'seo_push_enabled';    // 总开关 0/1
    const SET_SITE      = 'seo_push_site';       // 推送站点，留空取 site_domain()
    const SET_SCHEME    = 'seo_push_scheme';     // http / https
    const SET_DAILY     = 'seo_push_daily';      // 每日自动推送 0/1
    const SET_INTERVAL  = 'seo_push_interval';   // 自动推送间隔（小时）
    const SET_CHANNELS  = 'seo_push_channels';   // 启用的通道，逗号分隔
    const SET_SCOPE     = 'seo_push_scope';      // home / site（首页 or 首页+栏目页）
    const SET_INDEXNOW_KEY = 'seo_push_indexnow_key';

    /** 单通道每日推送上限（平台侧有配额，超了会被拒并影响权重） */
    const DAILY_LIMIT = 200;

    /** 通道配置键前缀 */
    const CFG_PREFIX = 'seo_push_cfg_';

    /* ------------------------------ 通道定义 ------------------------------ */

    /**
     * 通道清单
     *
     * type  push     有可用的主动推送接口
     *       sitemap  只能提交 sitemap 被动收录
     * auth  token=sitemap token 两段式（百度需要 site + token）
     */
    public static function channels(): array
    {
        return [
            'baidu' => [
                'name'    => '百度',
                'type'    => 'push',
                'endpoint' => 'http://data.zz.baidu.com/urls',
                'fields'  => [
                    'token' => ['label' => '准入密钥 token', 'hint' => '百度搜索资源平台 → 普通收录 → API 提交'],
                ],
                'note'    => '需 site 与 token 同时正确，缺一返回 401 token is not valid。',
            ],
            'shenma' => [
                'name'    => '神马',
                'type'    => 'push',
                'endpoint' => 'https://data.zhanzhang.sm.cn/push',
                'fields'  => [
                    'user_name' => ['label' => '账号 user_name', 'hint' => '神马站长平台 → 收录 → API 提交'],
                    'token'     => ['label' => '密钥 token', 'hint' => '与 user_name 配套'],
                    'resource'  => ['label' => '资源名 resource_name', 'hint' => '默认 mip_add，按平台后台实际值填写'],
                ],
                'note'    => '参数 site/user_name/resource_name/token 缺一不可，错误返回 returnCode 201。',
            ],
            'indexnow' => [
                'name'    => 'IndexNow（必应 / Yandex 等）',
                'type'    => 'push',
                'endpoint' => 'https://www.bing.com/indexnow',
                'fields'  => [
                    'key' => ['label' => 'IndexNow 密钥', 'hint' => '32~128 位十六进制串，需能通过 /{key}.txt 公开访问'],
                ],
                'note'    => '通道本身免鉴权，202 表示已受理，不代表立即收录。密钥文件由系统自动生成。',
            ],
            '360' => [
                'name'    => '360 搜索',
                'type'    => 'sitemap',
                'endpoint' => '',
                'fields'  => [],
                'note'    => '官方推送接口实测已下线（返回 404），请在 360 站长平台提交 sitemap.xml 完成收录。',
            ],
            'sogou' => [
                'name'    => '搜狗',
                'type'    => 'sitemap',
                'endpoint' => '',
                'fields'  => [],
                'note'    => '推送接口实测已跳转登录页，请在搜狗站长平台提交 sitemap.xml。',
            ],
            'toutiao' => [
                'name'    => '头条搜索',
                'type'    => 'sitemap',
                'endpoint' => '',
                'fields'  => [],
                'note'    => '推送接口实测返回 500，请在头条站长平台提交 sitemap.xml。',
            ],
        ];
    }

    public static function channelName(string $key): string
    {
        $c = self::channels();
        return isset($c[$key]) ? $c[$key]['name'] : $key;
    }

    /** 该通道是否支持主动推送 */
    public static function canPush(string $key): bool
    {
        $c = self::channels();
        return isset($c[$key]) && $c[$key]['type'] === 'push';
    }

    /* ------------------------------ 基础配置 ------------------------------ */

    public static function enabled(): bool
    {
        return trim((string)setting(self::SET_ENABLED, '0')) === '1';
    }

    /**
     * 推送目标站点根地址（不含末尾斜杠）
     *
     * 不能使用 base_url()：CLI 下它恒返回 ''，会让定时推送全部发到错误地址。
     */
    public static function siteRoot(): string
    {
        $site = trim((string)setting(self::SET_SITE, ''));
        if ($site === '') {
            $site = site_domain();
        }
        $domain = normalize_domain($site);
        if ($domain === '' || $domain === 'localhost') {
            return '';
        }
        $scheme = strtolower(trim((string)setting(self::SET_SCHEME, 'https')));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }
        return $scheme . '://' . $domain;
    }

    /** 启用的通道 key 列表 */
    public static function activeChannels(): array
    {
        $raw  = (string)setting(self::SET_CHANNELS, 'baidu,shenma,indexnow');
        $defs = self::channels();
        $out  = [];
        foreach (explode(',', $raw) as $k) {
            $k = strtolower(trim($k));
            if ($k !== '' && isset($defs[$k]) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** 通道配置（token 加密存储，此处返回明文供请求使用，不对外输出） */
    public static function channelConfig(string $channel): array
    {
        $out = [];
        $def = self::channels()[$channel] ?? null;
        if (!$def) {
            return $out;
        }
        foreach (array_keys($def['fields']) as $k) {
            $v = (string)setting(self::CFG_PREFIX . $channel . '_' . $k, '');
            $out[$k] = $v === '' ? '' : yx_decrypt($v);
        }
        // IndexNow 密钥由系统自动托管（唯一键 seo_push_indexnow_key），
        // 不与通用通道配置键混用，否则自动生成的密钥永远读不到。
        if ($channel === 'indexnow' && ($out['key'] ?? '') === '') {
            $out['key'] = self::indexNowKey();
        }
        return $out;
    }

    public static function setChannelConfig(string $channel, array $values): void
    {
        $def = self::channels()[$channel] ?? null;
        if (!$def) {
            return;
        }
        foreach (array_keys($def['fields']) as $k) {
            if (!array_key_exists($k, $values)) {
                continue;
            }
            $v = trim((string)$values[$k]);
            set_setting(self::CFG_PREFIX . $channel . '_' . $k, $v === '' ? '' : yx_encrypt($v));
        }
    }

    /** 通道是否配置完整（不足以调用接口时返回缺失字段名） */
    public static function missingFields(string $channel): array
    {
        $def = self::channels()[$channel] ?? null;
        if (!$def || $def['type'] !== 'push') {
            return [];
        }
        $cfg   = self::channelConfig($channel);
        $miss  = [];
        foreach (array_keys($def['fields']) as $k) {
            // resource_name 允许走默认值
            if ($k === 'resource' && ($cfg[$k] ?? '') === '') {
                continue;
            }
            if (($cfg[$k] ?? '') === '') {
                $miss[] = $def['fields'][$k]['label'];
            }
        }
        return $miss;
    }

    /** IndexNow 密钥：未配置则自动生成 32 位十六进制并落库，保证密钥文件可校验 */
    public static function indexNowKey(): string
    {
        $key = trim((string)setting(self::SET_INDEXNOW_KEY, ''));
        if (preg_match('/^[a-f0-9]{32,128}$/i', $key)) {
            self::ensureKeyFile($key);
            return $key;
        }
        $key = strtolower(bin2hex(random_bytes(16)));
        set_setting(self::SET_INDEXNOW_KEY, $key);
        self::ensureKeyFile($key);
        return $key;
    }

    /**
     * 保证 /{key}.txt 真实存在于站点根目录
     *
     * IndexNow 会先抓取 keyLocation 校验归属，文件不存在直接返回 403。
     * 密钥可能来自早期版本或手工配置，故不能只在生成时写一次，
     * 每次取用都校验：缺失则补写，内容不符则覆盖为当前密钥。
     *
     * 返回文件是否可读且内容正确
     */
    public static function ensureKeyFile(string $key): bool
    {
        if (PHP_SAPI !== 'cli' && YX_ROOT !== '') {
            $root = rtrim(YX_ROOT, '/');
        } else {
            $root = dirname(__DIR__);
        }
        if (!is_dir($root) || !is_writable($root)) {
            return false;
        }
        $file = $root . '/' . $key . '.txt';
        if (is_file($file) && trim((string)@file_get_contents($file)) === $key) {
            return true;
        }
        return @file_put_contents($file, $key) !== false;
    }

    /* ------------------------------ URL 清单 ------------------------------ */

    /**
     * 待推送 URL 清单
     *
     * @param string $scope home = 仅首页；site = 首页 + 公开栏目页 + 已发布文章
     * @param int    $limit 最多条数（受平台配额限制）
     */
    public static function urls(string $scope = null, int $limit = self::DAILY_LIMIT): array
    {
        $root = self::siteRoot();
        if ($root === '') {
            return [];
        }
        $scope = $scope !== null ? $scope : (string)setting(self::SET_SCOPE, 'home');
        $list  = [$root . '/'];

        if ($scope === 'site') {
            foreach (['about.php', 'products.php', 'news.php', 'career.php', 'goods.php', 'report.php', 'infringe.php'] as $p) {
                $list[] = $root . '/' . $p;
            }
            try {
                if (YxNews::isEnabled() && YxDb::hasTable('news')) {
                    foreach (YxNews::published([], min(100, max(0, $limit - count($list))), 0) as $n) {
                        $list[] = $root . '/news_detail.php?id=' . (int)$n['id'];
                    }
                }
            } catch (Throwable $e) {
                // 新闻表异常不影响首页推送
            }
        }

        $out = [];
        foreach ($list as $u) {
            $u = trim($u);
            if ($u !== '' && !in_array($u, $out, true)) {
                $out[] = $u;
            }
        }
        return array_slice($out, 0, max(1, $limit));
    }

    /** sitemap.xml 内容（公开访问，供各平台被动抓取） */
    public static function sitemapXml(): string
    {
        $urls = self::urls('site', self::DAILY_LIMIT);
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $today = date('Y-m-d');
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . e($u) . '</loc><lastmod>' . $today . '</lastmod>'
                . '<changefreq>' . ($u === self::siteRoot() . '/' ? 'daily' : 'weekly') . '</changefreq>'
                . '<priority>' . ($u === self::siteRoot() . '/' ? '1.0' : '0.8') . '</priority></url>' . "\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    /* ------------------------------ 实际推送 ------------------------------ */

    /**
     * 向单个通道推送
     *
     * @return array{ok:bool,msg:string,count:int,remain:?int}
     */
    public static function pushChannel(string $channel, array $urls = null, string $source = '手动推送'): array
    {
        $def = self::channels()[$channel] ?? null;
        if (!$def) {
            return ['ok' => false, 'msg' => '未知通道', 'count' => 0, 'remain' => null];
        }
        if ($def['type'] !== 'push') {
            return ['ok' => false, 'msg' => '该平台不提供主动推送接口，请提交 sitemap.xml', 'count' => 0, 'remain' => null];
        }
        $miss = self::missingFields($channel);
        if ($miss) {
            return ['ok' => false, 'msg' => '未配置：' . implode('、', $miss), 'count' => 0, 'remain' => null];
        }
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '推送总开关未开启', 'count' => 0, 'remain' => null];
        }

        $urls = $urls === null ? self::urls() : $urls;
        if (!$urls) {
            return ['ok' => false, 'msg' => '没有可推送的 URL（站点地址未配置）', 'count' => 0, 'remain' => null];
        }

        $cfg = self::channelConfig($channel);
        $res = self::callApi($channel, $def, $cfg, $urls);

        self::log($channel, $res['ok'], $res['http'], count($urls), $res['msg'], $source);
        return [
            'ok'     => $res['ok'],
            'msg'    => $res['msg'],
            'count'  => $res['ok'] ? count($urls) : 0,
            'remain' => $res['remain'],
        ];
    }

    /** 各平台协议差异集中在此，返回统一结构 */
    private static function callApi(string $channel, array $def, array $cfg, array $urls): array
    {
        $endpoint = trim((string)setting(self::CFG_PREFIX . $channel . '_endpoint', ''));
        if ($endpoint === '' || !preg_match('#^https?://#i', $endpoint)) {
            $endpoint = $def['endpoint'];
        }
        $root = self::siteRoot();
        $host = normalize_domain($root);

        if ($channel === 'baidu') {
            $url  = $endpoint . '?site=' . urlencode($root) . '&token=' . urlencode($cfg['token'] ?? '');
            $resp = self::request($url, implode("\n", $urls), ['Content-Type: text/plain']);
            return self::parseBaidu($resp);
        }

        if ($channel === 'shenma') {
            $url  = $endpoint . '?site=' . urlencode($host)
                . '&user_name=' . urlencode($cfg['user_name'] ?? '')
                . '&resource_name=' . urlencode(($cfg['resource'] ?? '') !== '' ? $cfg['resource'] : 'mip_add')
                . '&token=' . urlencode($cfg['token'] ?? '');
            $resp = self::request($url, implode("\n", $urls), ['Content-Type: text/plain']);
            return self::parseShenma($resp);
        }

        // IndexNow
        $key    = $cfg['key'] ?? '';
        $payload = [
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $root . '/' . $key . '.txt',
            'urlList'     => array_values($urls),
        ];
        $resp = self::request(
            $endpoint,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json; charset=utf-8']
        );
        return self::parseIndexNow($resp);
    }

    /** 原始 POST，返回 [ok, httpCode, body, err] */
    private static function request(string $url, string $body, array $headers): array
    {
        $ua = 'Mozilla/5.0 (compatible; YouxiangFinance/' . config('app.version', '1.0.0') . '; +seo-push)';
        $headers[] = 'User-Agent: ' . $ua;

        if (!function_exists('curl_init')) {
            // 无 curl 时退回流式请求。注意必须原样带上 $body：
            // 百度/神马要求请求体是换行分隔的 URL，IndexNow 是 JSON，
            // 用 http_post_form($url, []) 会把请求体整个丢掉导致推送必然失败。
            return self::streamPost($url, $body, $headers);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);

        return [$resp !== false, $code, (string)$resp, $err];
    }

    /**
     * 无 curl 环境的流式 POST 回退
     *
     * 与 http_post_* 系列不同，这里必须保留原始 $body 与真实 HTTP 状态码：
     * 各平台靠状态码/JSON 内容区分「鉴权失败」与「推送成功」。
     */
    private static function streamPost(string $url, string $body, array $headers): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return [false, 0, '', 'allow_url_fopen 已关闭且无 curl 扩展'];
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 12,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $lastErr = error_get_last();
            return [false, 0, '', $lastErr['message'] ?? '网络异常'];
        }
        $code = 200;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];   // 跟随重定向后取最后一段状态行
            }
        }
        return [true, $code, (string)$resp, ''];
    }

    private static function parseBaidu(array $r): array
    {
        $body = self::cleanBody($r[2]);
        if (!$r[0]) {
            return ['ok' => false, 'http' => 0, 'msg' => '网络请求失败：' . ($r[3] !== '' ? $r[3] : '未知错误'), 'remain' => null];
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return ['ok' => false, 'http' => $r[1], 'msg' => '返回非 JSON（HTTP ' . $r[1] . '）：' . mb_substr($body, 0, 120), 'remain' => null];
        }
        if (isset($j['error'])) {
            $fix = ['401' => 'token 无效或 site 与 token 不匹配', '403' => '无提交权限或配额已用尽', '404' => '接口地址错误'];
            return [
                'ok'     => false,
                'http'   => $r[1],
                'msg'    => '百度拒绝（' . $j['error'] . '）：' . ($j['message'] ?? '') . (isset($fix[(string)$j['error']]) ? '｜' . $fix[(string)$j['error']] : ''),
                'remain' => null,
            ];
        }
        $ok     = (int)($j['success'] ?? 0);
        $msg    = '成功 ' . $ok . ' 条，剩余配额 ' . (isset($j['remain']) ? $j['remain'] : '-');
        $detail = [];
        if (!empty($j['not_same_site'])) {
            $detail[] = '非本站 ' . count($j['not_same_site']);
        }
        if (!empty($j['not_valid'])) {
            $detail[] = '不合法 ' . count($j['not_valid']);
        }
        if ($detail) {
            $msg .= '（' . implode('，', $detail) . '）';
        }
        return [
            'ok'     => $ok > 0,
            'http'   => $r[1],
            'msg'    => $ok > 0 ? $msg : ('未推入任何 URL：' . $msg),
            'remain' => isset($j['remain']) ? (int)$j['remain'] : null,
        ];
    }

    private static function parseShenma(array $r): array
    {
        $body = self::cleanBody($r[2]);
        if (!$r[0]) {
            return ['ok' => false, 'http' => 0, 'msg' => '网络请求失败：' . ($r[3] !== '' ? $r[3] : '未知错误'), 'remain' => null];
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return ['ok' => false, 'http' => $r[1], 'msg' => '返回非 JSON（HTTP ' . $r[1] . '）：' . mb_substr($body, 0, 120), 'remain' => null];
        }
        $code = isset($j['returnCode']) ? (int)$j['returnCode'] : -1;
        if ($code === 0 || $code === 200) {
            $ok = isset($j['success']) ? (int)$j['success'] : count($j['urlList'] ?? [1]);
            return ['ok' => true, 'http' => $r[1], 'msg' => '推送成功 ' . $ok . ' 条', 'remain' => null];
        }
        $fix = ['201' => 'token 或 user_name 无效', '400' => '参数缺失', '403' => '无提交权限'];
        return [
            'ok'     => false,
            'http'   => $r[1],
            'msg'    => '神马拒绝（' . $code . '）：' . trim((string)($j['errorMsg'] ?? ''))
                . (isset($fix[(string)$code]) ? '｜' . $fix[(string)$code] : ''),
            'remain' => null,
        ];
    }

    private static function parseIndexNow(array $r): array
    {
        $code = (int)$r[1];
        $body = self::cleanBody($r[2]);
        if (!$r[0]) {
            return ['ok' => false, 'http' => 0, 'msg' => '网络请求失败：' . ($r[3] !== '' ? $r[3] : '未知错误'), 'remain' => null];
        }
        // 200 已提交 / 202 已受理，均视为通道接收成功
        if ($code === 200 || $code === 202) {
            return ['ok' => true, 'http' => $code, 'msg' => '已受理（HTTP ' . $code . '），收录结果以搜索引擎为准', 'remain' => null];
        }
        $fix = [
            400 => '请求格式错误', 403 => '密钥校验失败（检查 /{key}.txt 是否可访问）',
            422 => 'URL 不属于该 host 或密钥无效', 429 => '请求过于频繁，请降低频率',
        ];
        return [
            'ok'     => false,
            'http'   => $code,
            'msg'    => 'IndexNow 拒绝（HTTP ' . $code . '）' . (isset($fix[$code]) ? '：' . $fix[$code] : '')
                . ($body !== '' ? '｜' . mb_substr($body, 0, 120) : ''),
            'remain' => null,
        ];
    }

    /** 去掉接口偶发返回的 BOM 与空白，避免 json_decode 失败 */
    private static function cleanBody(string $body): string
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        return trim((string)$body);
    }

    /* ------------------------------ 日志 ------------------------------ */

    private static function log(string $channel, bool $ok, int $http, int $count, string $msg, string $source): void
    {
        try {
            if (!YxDb::hasTable('seo_push_log')) {
                return;
            }
            YxDb::insert('seo_push_log', [
                'channel'    => $channel,
                'ok'         => $ok ? 1 : 0,
                'http_code'  => $http,
                'url_count'  => $count,
                'message'    => mb_substr($msg, 0, 480),
                'source'     => mb_substr($source, 0, 20),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // 日志失败不能影响推送本身
        }
    }

    /** 按日统计某通道今日成功推送次数，用于限流 */
    public static function todayCount(string $channel): int
    {
        try {
            if (!YxDb::hasTable('seo_push_log')) {
                return 0;
            }
            return (int)YxDb::value(
                'SELECT COUNT(*) FROM ' . YxDb::table('seo_push_log')
                . ' WHERE channel = ? AND ok = 1 AND created_at >= ?',
                [$channel, date('Y-m-d') . ' 00:00:00']
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function purge(int $days = 90): int
    {
        if (!YxDb::hasTable('seo_push_log')) {
            return 0;
        }
        // days <= 0 表示清空全部（后台「清空记录」按钮）；否则按天数保留
        if ($days <= 0) {
            return YxDb::exec('DELETE FROM ' . YxDb::table('seo_push_log'));
        }
        $days = max(7, min(3650, $days));
        return YxDb::exec(
            'DELETE FROM ' . YxDb::table('seo_push_log') . ' WHERE created_at < ?',
            [date('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    /* ------------------------------ 自动推送 ------------------------------ */

    /**
     * 定时任务入口：按间隔对所有启用通道推送一次
     *
     * 限流规则（防止被平台判为作弊）：
     *   - 同一通道每日最多成功推送 1 次
     *   - 距上次执行不足 interval 小时则整体跳过
     */
    public static function runScheduled(): array
    {
        $lines = [];
        if (!self::enabled()) {
            return ['ok' => false, 'lines' => ['· 搜索引擎推送：已跳过（总开关未开启）']];
        }
        if (trim((string)setting(self::SET_DAILY, '1')) !== '1') {
            return ['ok' => false, 'lines' => ['· 搜索引擎推送：已跳过（未开启每日自动推送）']];
        }

        $interval = max(1, min(168, (int)setting(self::SET_INTERVAL, '24')));
        $last     = (string)setting('seo_push_last_run', '');
        if ($last !== '' && (time() - (int)strtotime($last)) < $interval * 3600) {
            return ['ok' => false, 'lines' => ['· 搜索引擎推送：未到间隔（' . $interval . ' 小时，上次 ' . $last . '）']];
        }

        $channels = self::activeChannels();
        if (!$channels) {
            return ['ok' => false, 'lines' => ['· 搜索引擎推送：未启用任何通道']];
        }

        $pushed = 0;
        foreach ($channels as $ch) {
            if (!self::canPush($ch)) {
                $lines[] = '    - ' . self::channelName($ch) . '：仅支持 sitemap 被动收录，已跳过';
                continue;
            }
            $miss = self::missingFields($ch);
            if ($miss) {
                $lines[] = '    - ' . self::channelName($ch) . '：未配置 ' . implode('、', $miss) . '，已跳过';
                continue;
            }
            if (self::todayCount($ch) > 0) {
                $lines[] = '    - ' . self::channelName($ch) . '：今日已推送过，跳过（避免重复提交被判作弊）';
                continue;
            }
            $r = self::pushChannel($ch, null, '定时任务');
            $lines[] = '    - ' . self::channelName($ch) . '：' . ($r['ok'] ? '✅ ' : '❌ ') . $r['msg'];
            if ($r['ok']) {
                $pushed++;
            }
        }

        // 只有确实推送成功才推进时间戳：否则一次配置错误就会让后续 interval 小时内不再重试
        if ($pushed > 0) {
            set_setting('seo_push_last_run', now());
        }
        $lines[] = '· 搜索引擎推送：成功 ' . $pushed . '/' . count($channels) . ' 个通道'
            . ($pushed > 0 ? '' : '（未成功，下次定时任务将继续尝试）');
        return ['ok' => $pushed > 0, 'lines' => $lines];
    }
}
