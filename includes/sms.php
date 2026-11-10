<?php
/**
 * 优享财务系统 · 手机短信验证码
 *
 * 后台设置（系统设置 → 手机短信验证码）：
 *   sms_enabled    是否启用手机号短信验证（1 开 / 0 关）
 *   sms_mock       演示模式：不真实发送，验证码写入 runtime/sms.log（1 开 / 0 关）
 *   sms_driver     generic（通用 JSON 网关）/ aliyun（阿里云短信）/ tencent（腾讯云短信）
 *   sms_api_url    通用驱动的网关接口地址（POST JSON，返回 JSON）
 *   sms_account    网关账号 / AppKey（阿里云为 AccessKeyId，腾讯云为 SecretId）
 *   sms_password   网关密钥 / AppSecret（阿里云为 AccessKeySecret，腾讯云为 SecretKey）
 *   sms_sign       短信签名，如「优享财务」
 *   sms_template   短信内容模板，{code} 会被替换为验证码
 *   sms_aliyun_template_code / sms_tencent_appid / sms_tencent_sdk_appid / sms_tencent_template_id
 *                  云厂商模板报备制：阿里云、腾讯云必须使用已审核通过的模板 ID
 *
 * 验证码保存在当前会话中，包含有效期、重发间隔、校验次数与每小时发送上限，
 * 因此不依赖数据库迁移，安装后即可使用。
 *
 * 判定原则（重要）：只有网关明确返回成功标记时才视为发送成功。
 * 早期版本把「任意 HTTP 2xx + 非 JSON / 无法识别的 JSON」都当成成功，
 * 于是网关地址填成普通网页（如 https://cn.aliyun.com/）时前台会提示
 * 「验证码已发送」，但实际上根本没有请求到任何短信接口。
 */

class YxSms
{
    /** 会话内存储键 */
    const SESS_KEY = '_yx_sms';

    /** 验证码有效期（秒） */
    const CODE_TTL = 300;

    /** 同一手机号重发间隔（秒） */
    const RESEND_GAP = 60;

    /** 单个验证码最多校验次数 */
    const MAX_ATTEMPTS = 5;

    /** 每小时最多发送次数（每会话） */
    const MAX_SENDS = 10;

    /* ------------------------------------------------------------------ 配置 */

    /**
     * 是否启用手机号短信验证。
     * 未写入过设置时按「开启」处理（与旧版行为一致），可显式关闭。
     */
    public static function enabled(): bool
    {
        return (string)setting('sms_enabled', '1') === '1';
    }

    /**
     * 演示模式（兼容旧键 sms_mock 与新键 sms_demo，任一开启即生效）
     */
    public static function mock(): bool
    {
        return (string)setting('sms_mock', '0') === '1'
            || (string)setting('sms_demo', '0') === '1';
    }

    /** 网关是否已填写（地址 + 账号） */
    public static function configured(): bool
    {
        if (self::driver() !== 'generic') {
            return trim((string)setting('sms_account', '')) !== ''
                && trim((string)setting('sms_password', '')) !== '';
        }
        return trim((string)setting('sms_api_url', '')) !== ''
            && trim((string)setting('sms_account', '')) !== '';
    }

    /**
     * 短信通道驱动
     *   generic  通用 JSON 网关（自建 / 第三方聚合网关）
     *   aliyun   阿里云短信服务 Dysmsapi（RPC 签名）
     *   tencent  腾讯云短信 SMS（TC3 签名）
     */
    public static function driver(): string
    {
        $d = (string)setting('sms_driver', 'generic');
        return in_array($d, ['generic', 'aliyun', 'tencent'], true) ? $d : 'generic';
    }

    /** 驱动中文名，用于后台展示与提示 */
    public static function driverLabel(string $driver = null): string
    {
        $driver = $driver ?? self::driver();
        $map = [
            'generic' => '通用 JSON 网关',
            'aliyun'  => '阿里云短信',
            'tencent' => '腾讯云短信',
        ];
        return $map[$driver] ?? $map['generic'];
    }

    /**
     * 当前是否具备真实发送能力（非演示模式且网关已配置）
     */
    public static function available(): bool
    {
        return self::mock() || self::configured();
    }

    /**
     * 配置层面的问题说明；无问题时返回空串。
     * 供注册页与后台直接展示，避免用户反复点「获取验证码」却不知道原因。
     */
    public static function configIssue(): string
    {
        if (self::mock()) {
            return '';
        }

        $driver  = self::driver();
        $account = trim((string)setting('sms_account', ''));
        $secret  = trim((string)setting('sms_password', ''));

        if ($driver === 'aliyun') {
            if ($account === '') {
                return '阿里云 AccessKeyId 未配置';
            }
            if (!preg_match('/^LTAI[A-Za-z0-9]{8,}$/', $account)) {
                return '阿里云 AccessKeyId 格式不正确（应以 LTAI 开头），请填写 AccessKey ID 而不是登录账号';
            }
            if ($secret === '') {
                return '阿里云 AccessKeySecret 未配置';
            }
            if (trim((string)setting('sms_sign', '')) === '') {
                return '短信签名未配置（需与阿里云控制台已审核通过的签名一致）';
            }
            if (trim((string)setting('sms_aliyun_template_code', '')) === '') {
                return '阿里云短信模板 CODE 未配置（如 SMS_123456789，需在阿里云控制台审核通过）';
            }
            return '';
        }

        if ($driver === 'tencent') {
            if ($account === '') {
                return '腾讯云 SecretId 未配置';
            }
            if ($secret === '') {
                return '腾讯云 SecretKey 未配置';
            }
            if (trim((string)setting('sms_tencent_sdk_appid', '')) === '') {
                return '腾讯云短信 SdkAppId 未配置（如 1400000000）';
            }
            if (trim((string)setting('sms_tencent_template_id', '')) === '') {
                return '腾讯云短信模板 ID 未配置';
            }
            if (trim((string)setting('sms_sign', '')) === '') {
                return '短信签名未配置（需与腾讯云控制台已审核通过的签名一致）';
            }
            return '';
        }

        // 通用 JSON 网关
        $url = trim((string)setting('sms_api_url', ''));
        if ($url === '') {
            return '短信网关地址未配置';
        }
        if (!preg_match('#^https?://#i', $url)) {
            return '短信网关地址格式不正确（需以 http:// 或 https:// 开头）';
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '' || strpos($host, '.') === false) {
            return '短信网关地址不正确，请填写短信服务商的接口地址';
        }
        if (trim((string)setting('sms_account', '')) === '') {
            return '短信网关账号未配置';
        }
        if (trim((string)setting('sms_password', '')) === '') {
            return '短信网关密钥未配置';
        }
        // 填的是云厂商 AccessKey 却选了通用网关：这是最常见的「配了也发不出去」
        if (preg_match('/^LTAI[A-Za-z0-9]{8,}$/', trim((string)setting('sms_account', '')))) {
            return '当前账号看起来是阿里云 AccessKey，但通道选择的是「通用 JSON 网关」；'
                . '请把通道切换为「阿里云短信」，否则请求缺少签名会被阿里云拒绝';
        }
        return '';
    }

    /* ------------------------------------------------------------------ 手机号 */

    /**
     * 归一化中国大陆手机号：去掉空白、连字符、括号与 +86 前缀。
     * 不合法时返回空串。
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-()（）]/u', '', trim($phone));
        $phone = preg_replace('/^\+?86/', '', (string)$phone);
        return preg_match('/^1[3-9]\d{9}$/', (string)$phone) ? (string)$phone : '';
    }

    /** 手机号脱敏，用于提示与日志（138****8888） */
    public static function maskPhone(string $phone): string
    {
        $phone = self::normalizePhone($phone);
        return $phone === '' ? '' : substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    /* ------------------------------------------------------------------ 发码 */

    /**
     * 生成并发送验证码
     *
     * @return array{ok:bool,msg:string,wait?:int,demo_code?:string}
     */
    public static function issue(string $phone, string $ip = ''): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '短信验证未启用'];
        }

        $phone = self::normalizePhone($phone);
        if ($phone === '') {
            return ['ok' => false, 'msg' => '请输入正确的手机号'];
        }

        // 配置不完整时直接返回具体原因，不要等到发码后才发现「已成功」
        $issue = self::configIssue();
        if ($issue !== '') {
            return ['ok' => false, 'msg' => $issue . '，请联系管理员'];
        }

        $now   = time();
        $state = self::read();

        // 同一手机号重发间隔
        if (!empty($state['phone']) && $state['phone'] === $phone && !empty($state['sent_at'])
            && $now - (int)$state['sent_at'] < self::RESEND_GAP) {
            $wait = self::RESEND_GAP - ($now - (int)$state['sent_at']);
            return ['ok' => false, 'msg' => '发送过于频繁，请 ' . $wait . ' 秒后再试', 'wait' => $wait];
        }

        // 每小时发送上限
        $sends = array_values(array_filter((array)($state['sends'] ?? []), static function ($t) use ($now) {
            return $now - (int)$t < 3600;
        }));
        if (count($sends) >= self::MAX_SENDS) {
            return ['ok' => false, 'msg' => '发送次数已达上限，请稍后再试'];
        }
        $sends[] = $now;

        $code = (string)random_int(100000, 999999);
        $sent = self::send($phone, $code);

        if (!empty($sent['ok'])) {
            self::write([
                'phone'    => $phone,
                'code'     => $code,
                'expire'   => $now + self::CODE_TTL,
                'sent_at'  => $now,
                'attempts' => 0,
                'sends'    => $sends,
                'ip'       => $ip,
            ]);
        } else {
            // 发送失败也计入频率限制，避免被反复调用
            $state['sends'] = $sends;
            self::write($state);
        }

        return $sent;
    }

    /* ------------------------------------------------------------------ 校验 */

    /**
     * 校验验证码，成功后立即失效
     *
     * @return array{ok:bool,msg:string}
     */
    public static function verify(string $phone, string $code): array
    {
        $phone = self::normalizePhone($phone);
        $code  = preg_replace('/\D/', '', $code);

        if ($phone === '' || $code === '') {
            return ['ok' => false, 'msg' => '请输入手机号与短信验证码'];
        }

        $state = self::read();
        if (empty($state['phone']) || empty($state['code'])) {
            return ['ok' => false, 'msg' => '请先获取短信验证码'];
        }
        if ((string)$state['phone'] !== $phone) {
            return ['ok' => false, 'msg' => '手机号与验证码不匹配，请重新获取'];
        }
        if ((int)($state['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            self::clear();
            return ['ok' => false, 'msg' => '验证码尝试次数过多，请重新获取'];
        }
        if ((int)($state['expire'] ?? 0) < time()) {
            self::clear();
            return ['ok' => false, 'msg' => '验证码已过期，请重新获取'];
        }
        if (!hash_equals((string)$state['code'], (string)$code)) {
            $state['attempts'] = (int)($state['attempts'] ?? 0) + 1;
            self::write($state);
            $left = self::MAX_ATTEMPTS - $state['attempts'];
            return ['ok' => false, 'msg' => '验证码不正确' . ($left > 0 ? '，还可尝试 ' . $left . ' 次' : '')];
        }

        self::clear();
        return ['ok' => true, 'msg' => '验证通过'];
    }

    /** 清理本会话的验证码状态 */
    public static function clear(): void
    {
        unset($_SESSION[self::SESS_KEY]);
    }

    /* ------------------------------------------------------------------ 测试 */

    /**
     * 后台「测试发送」：不受重发间隔 / 次数限制，直接走真实通道。
     *
     * @return array{ok:bool,msg:string}
     */
    public static function test(string $phone): array
    {
        $phone = self::normalizePhone($phone);
        if ($phone === '') {
            return ['ok' => false, 'msg' => '请输入正确的 11 位手机号'];
        }
        $issue = self::configIssue();
        if ($issue !== '') {
            return ['ok' => false, 'msg' => $issue];
        }
        $code = (string)random_int(100000, 999999);
        $res  = self::send($phone, $code);
        return ['ok' => !empty($res['ok']), 'msg' => (string)($res['msg'] ?? '')];
    }

    /** 演示模式下读取最近一次发送的验证码（仅用于后台排查） */
    public static function lastDemoCode(string $phone): string
    {
        $state = self::read();
        $phone = self::normalizePhone($phone);
        if ($phone === '' || empty($state['phone']) || (string)$state['phone'] !== $phone) {
            return '';
        }
        return (string)($state['code'] ?? '');
    }

    /* ------------------------------------------------------------------ 内部 */

    private static function read(): array
    {
        $s = $_SESSION[self::SESS_KEY] ?? [];
        return is_array($s) ? $s : [];
    }

    private static function write(array $state): void
    {
        $_SESSION[self::SESS_KEY] = $state;
    }

    /**
     * 实际发送
     * 演示模式：只写日志并回传验证码，便于未接入网关时联调；
     * 正式模式：按驱动调用真实通道，必须拿到明确的成功标记才算发送成功。
     */
    private static function send(string $phone, string $code): array
    {
        $template = trim((string)setting('sms_template', ''));
        if ($template === '') {
            $template = '您的验证码为 {code}，5 分钟内有效。如非本人操作请忽略。';
        }
        $content = str_replace('{code}', $code, $template);
        $sign    = trim((string)setting('sms_sign', ''));

        if (self::mock()) {
            self::log('mock', $phone, $code, '');
            return ['ok' => true, 'msg' => '验证码已发送（演示模式）', 'demo_code' => $code];
        }

        $driver = self::driver();
        if ($driver === 'aliyun') {
            return self::sendAliyun($phone, $code, $sign);
        }
        if ($driver === 'tencent') {
            return self::sendTencent($phone, $code, $sign);
        }
        return self::sendGeneric($phone, $code, $sign, $content);
    }

    /** 通用 JSON 网关（POST JSON，按 parseResult 判定） */
    private static function sendGeneric(string $phone, string $code, string $sign, string $content): array
    {
        $url = trim((string)setting('sms_api_url', ''));
        if ($url === '') {
            return ['ok' => false, 'msg' => '短信网关未配置，请联系管理员'];
        }

        $payload = [
            'account'  => (string)setting('sms_account', ''),
            'password' => (string)setting('sms_password', ''),
            'phone'    => $phone,
            'mobile'   => $phone,
            'sign'     => $sign,
            'content'  => $content,
            'code'     => $code,
        ];

        $res = function_exists('http_post_json')
            ? http_post_json($url, $payload, [], 8)
            : [false, 0, 'http_post_json 不可用'];

        list($ok, $status, $body) = $res;
        $bodyText = is_string($body) ? $body : '';

        if (!$ok) {
            self::log('api-fail', $phone, null, mb_substr($bodyText, 0, 200));
            return ['ok' => false, 'msg' => '短信发送失败，连接短信网关异常，请稍后重试'];
        }

        if ($status < 200 || $status >= 300) {
            self::log('api-http', $phone, null, 'HTTP ' . $status . ' ' . mb_substr($bodyText, 0, 160));
            return ['ok' => false, 'msg' => '短信网关返回错误（HTTP ' . $status . '）'];
        }

        $err = self::parseResult($bodyText);
        if ($err !== null) {
            self::log('api-err', $phone, null, mb_substr($err, 0, 160));
            return ['ok' => false, 'msg' => $err];
        }

        self::log('api-ok', $phone, null, '');
        return ['ok' => true, 'msg' => '验证码已发送'];
    }

    /**
     * 阿里云短信 Dysmsapi · SendSms（RPC 签名 V1，HMAC-SHA1）
     * 端点 https://dysmsapi.aliyuncs.com/，返回 Code == "OK" 才算成功。
     * 模板报备制：模板内容由控制台审核，短信中只传模板变量 JSON。
     */
    private static function sendAliyun(string $phone, string $code, string $sign): array
    {
        $ak   = trim((string)setting('sms_account', ''));
        $sk   = trim((string)setting('sms_password', ''));
        $tpl  = trim((string)setting('sms_aliyun_template_code', ''));
        if ($ak === '' || $sk === '' || $sign === '' || $tpl === '') {
            return ['ok' => false, 'msg' => '阿里云短信配置不完整：' . self::configIssue()];
        }

        $params = [
            'AccessKeyId'      => $ak,
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'PhoneNumbers'     => $phone,
            'RegionId'         => trim((string)setting('sms_aliyun_region', 'cn-hangzhou')) ?: 'cn-hangzhou',
            'SignName'         => $sign,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $tpl,
            'TemplateParam'    => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];
        $params['Signature'] = self::aliyunSign($params, $sk, 'POST');

        $res = function_exists('http_post_form')
            ? http_post_form('https://dysmsapi.aliyuncs.com/', $params, 8)
            : [false, 0, 'http_post_form 不可用'];

        list($ok, $status, $body) = $res;
        $text = is_string($body) ? trim($body) : '';
        $data = json_decode($text, true);

        if ($ok && is_array($data)) {
            $code_ = (string)($data['Code'] ?? '');
            if ($code_ === 'OK') {
                self::log('aliyun-ok', $phone, null, (string)($data['BizId'] ?? ''));
                return ['ok' => true, 'msg' => '验证码已发送'];
            }
            $msg = (string)($data['Message'] ?? '') ?: $code_;
            self::log('aliyun-err', $phone, null, mb_substr($code_ . ' ' . $msg, 0, 160));
            return ['ok' => false, 'msg' => '短信发送失败：' . $msg . ($code_ !== '' ? '（' . $code_ . '）' : '')];
        }

        self::log('aliyun-http', $phone, null, 'HTTP ' . (int)$status . ' ' . mb_substr($text, 0, 160));
        return ['ok' => false, 'msg' => '阿里云短信接口请求失败（HTTP ' . (int)$status . '），请检查服务器网络与 AccessKey'];
    }

    /** 阿里云 RPC 签名：percentEncode → 排序 → HMAC-SHA1 → Base64 */
    private static function aliyunSign(array $params, string $secret, string $method = 'POST'): string
    {
        $enc = static function (string $v): string {
            return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], urlencode($v));
        };
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $enc((string)$k) . '=' . $enc((string)$v);
        }
        $canonical = implode('&', $pairs);
        $toSign    = $method . '&' . $enc('/') . '&' . $enc($canonical);
        return base64_encode(hash_hmac('sha1', $toSign, $secret . '&', true));
    }

    /**
     * 腾讯云短信 · SendSms（TC3-HMAC-SHA256 签名）
     * 端点 sms.tencentcloudapi.com，返回 Response.Error 为空、且 SendStatusSet[0].Code == "Ok" 才算成功。
     */
    private static function sendTencent(string $phone, string $code, string $sign): array
    {
        $sid  = trim((string)setting('sms_account', ''));
        $skey = trim((string)setting('sms_password', ''));
        $appid = trim((string)setting('sms_tencent_sdk_appid', ''));
        $tpl   = trim((string)setting('sms_tencent_template_id', ''));
        if ($sid === '' || $skey === '' || $appid === '' || $tpl === '' || $sign === '') {
            return ['ok' => false, 'msg' => '腾讯云短信配置不完整：' . self::configIssue()];
        }

        $host    = 'sms.tencentcloudapi.com';
        $service = 'sms';
        $action  = 'SendSms';
        $version = '2021-01-11';
        $region  = trim((string)setting('sms_tencent_region', 'ap-guangzhou')) ?: 'ap-guangzhou';

        $payload = json_encode([
            'PhoneNumberSet'   => ['+86' . $phone],
            'SmsSdkAppId'      => $appid,
            'SignName'         => $sign,
            'TemplateId'       => $tpl,
            'TemplateParamSet' => [$code],
        ], JSON_UNESCAPED_UNICODE);

        $timestamp = time();
        $date      = gmdate('Y-m-d', $timestamp);
        $hashedReq = hash('sha256', $payload);
        $canonical = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:" . $host . "\n\n"
            . 'content-type;host' . "\n" . $hashedReq;
        $stringToSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $date . '/' . $service . '/tc3_request' . "\n"
            . hash('sha256', $canonical);
        $kDate    = hash_hmac('sha256', $date, 'TC3' . $skey, true);
        $kService = hash_hmac('sha256', $service, $kDate, true);
        $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $auth = 'TC3-HMAC-SHA256 Credential=' . $sid . '/' . $date . '/' . $service . '/tc3_request, '
            . 'SignedHeaders=content-type;host, Signature=' . $signature;

        $ch = curl_init('https://' . $host);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Host: ' . $host,
                'Authorization: ' . $auth,
                'X-TC-Action: ' . $action,
                'X-TC-Timestamp: ' . $timestamp,
                'X-TC-Version: ' . $version,
                'X-TC-Region: ' . $region,
            ],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            self::log('tencent-fail', $phone, null, mb_substr($cerr, 0, 160));
            return ['ok' => false, 'msg' => '腾讯云短信请求失败：' . $cerr];
        }

        $data = json_decode((string)$body, true);
        $resp = is_array($data) ? (array)($data['Response'] ?? []) : [];
        if (!empty($resp['Error'])) {
            $msg = (string)($resp['Error']['Message'] ?? '');
            $c   = (string)($resp['Error']['Code'] ?? '');
            self::log('tencent-err', $phone, null, mb_substr($c . ' ' . $msg, 0, 160));
            return ['ok' => false, 'msg' => '短信发送失败：' . $msg . ($c !== '' ? '（' . $c . '）' : '')];
        }
        $first = (array)($resp['SendStatusSet'][0] ?? []);
        if ((string)($first['Code'] ?? '') === 'Ok') {
            self::log('tencent-ok', $phone, null, (string)($first['SerialNo'] ?? ''));
            return ['ok' => true, 'msg' => '验证码已发送'];
        }
        $msg = (string)($first['Message'] ?? '接口未返回成功状态');
        self::log('tencent-err', $phone, null, mb_substr($msg, 0, 160));
        return ['ok' => false, 'msg' => '短信发送失败：' . $msg . '（HTTP ' . $status . '）'];
    }

    /** 判断响应是否为 HTML 页面（网关地址填成了普通网页） */
    private static function looksLikeHtml(string $body): bool
    {
        $head = ltrim(substr($body, 0, 512));
        return $head !== ''
            && (stripos($head, '<!DOCTYPE') === 0
                || stripos($head, '<html') === 0
                || stripos($head, '<?xml') === 0 && stripos($body, '<html') !== false);
    }

    /**
     * 解析网关响应：成功返回 null，失败返回错误文案。
     *
     * 与旧版的区别：非 JSON / 无成功标记的响应一律视为失败，
     * 避免「网页 200」被误判成短信发送成功。
     */
    private static function parseResult(string $body): ?string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '短信网关返回空响应，请检查网关地址';
        }

        if (self::looksLikeHtml($trimmed)) {
            return '短信网关地址无效（返回的是网页而不是接口结果），请检查后台短信网关设置';
        }

        // 纯文本 ok / success（部分网关的返回格式）
        if (preg_match('/^(ok|success|true)$/i', $trimmed)) {
            return null;
        }

        $data = json_decode($trimmed, true);
        if (!is_array($data)) {
            return '短信网关返回格式无法识别，请检查网关地址与接口协议';
        }

        $success = false;
        foreach (['code', 'errcode', 'errno', 'ret'] as $k) {
            if (isset($data[$k]) && in_array((string)$data[$k], ['0', '200'], true)) {
                $success = true;
            }
        }
        foreach (['success', 'ok'] as $k) {
            if (array_key_exists($k, $data) && ($data[$k] === true || $data[$k] === 1 || $data[$k] === '1' || $data[$k] === 'true')) {
                $success = true;
            }
        }
        if (isset($data['status']) && in_array((string)$data['status'], ['ok', 'success', '0'], true)) {
            $success = true;
        }
        if ($success) {
            return null;
        }

        // 取网关给出的失败原因；没有原因时说明「无成功标记」
        foreach (['msg', 'message', 'errmsg', 'error'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                return '短信发送失败：' . $data[$k];
            }
        }
        return '短信网关未返回成功标记，请检查网关配置与账户状态';
    }

    /** 写运行日志：仅演示模式记录明文验证码 */
    private static function log(string $channel, string $phone, ?string $code, string $extra): void
    {
        try {
            $parts = ['[' . now() . ']', $channel, 'phone=' . self::maskPhone($phone)];
            if ($code !== null) {
                $parts[] = 'code=' . $code;
            }
            if ($extra !== '') {
                $parts[] = 'detail=' . $extra;
            }
            @file_put_contents(
                YX_ROOT . '/runtime/sms.log',
                implode(' ', $parts) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e) {
            // 日志失败不影响主流程
        }
    }
}
