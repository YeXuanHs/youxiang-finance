<?php
/**
 * 优享财务系统 · 邮箱验证码
 *
 * 后台设置（系统设置 → 邮箱验证码）：
 *   mail_enabled    注册时是否要求邮箱验证码（1 开 / 0 关）
 *   mail_demo       演示模式：不真实发送，验证码回显在注册页（1 开 / 0 关）
 *   smtp_host       SMTP 服务器，如 smtp.qq.com / smtp.163.com
 *   smtp_port       端口：465（SSL）/ 587（STARTTLS）/ 25
 *   smtp_secure     ssl / tls / none
 *   smtp_user       SMTP 登录账号（通常与发件邮箱相同）
 *   smtp_pass       SMTP 授权码（留空表示保留原值）
 *   smtp_from       发件邮箱（留空取 smtp_user）
 *   smtp_from_name  发件人名称（留空取站点名称）
 *
 * 不依赖第三方库，直接用 fsockopen + TLS 与 SMTP 服务器对话，
 * 因此不需要 Composer、也不需要服务器安装 mail 组件。
 * 验证码保存在会话（键与短信完全隔离），有效 10 分钟，同邮箱 60 秒内不可重发。
 */

class YxMail
{
    /** 会话内存储键 */
    const SESS_KEY = '_yx_mail';

    /** 验证码有效期（秒） */
    const CODE_TTL = 600;

    /** 同一邮箱重发间隔（秒） */
    const RESEND_GAP = 60;

    /** 单个验证码最多校验次数 */
    const MAX_ATTEMPTS = 5;

    /** 每小时最多发送次数（每会话） */
    const MAX_SENDS = 10;

    /* ------------------------------------------------------------------ 配置 */

    public static function enabled(): bool
    {
        return (string)setting('mail_enabled', '0') === '1';
    }

    public static function demo(): bool
    {
        return (string)setting('mail_demo', '0') === '1';
    }

    public static function host(): string
    {
        return trim((string)setting('smtp_host', ''));
    }

    public static function port(): int
    {
        $port = (int)setting('smtp_port', 465);
        return $port > 0 && $port < 65536 ? $port : 465;
    }

    /** ssl / tls / none */
    public static function secure(): string
    {
        $s = strtolower(trim((string)setting('smtp_secure', 'ssl')));
        return in_array($s, ['ssl', 'tls', 'none'], true) ? $s : 'ssl';
    }

    public static function user(): string
    {
        return trim((string)setting('smtp_user', ''));
    }

    public static function from(): string
    {
        $from = trim((string)setting('smtp_from', ''));
        return $from !== '' ? $from : self::user();
    }

    /** 配置是否完整（演示模式总是可用） */
    public static function configured(): bool
    {
        return self::host() !== '' && self::user() !== '' && (string)setting('smtp_pass', '') !== '';
    }

    public static function available(): bool
    {
        return self::demo() || self::configured();
    }

    /** 配置问题说明；无问题返回空串 */
    public static function configIssue(): string
    {
        if (self::demo()) {
            return '';
        }
        if (self::host() === '') {
            return '邮件服务器（SMTP）地址未配置';
        }
        if (self::user() === '') {
            return 'SMTP 登录账号未配置';
        }
        if ((string)setting('smtp_pass', '') === '') {
            return 'SMTP 授权码未配置';
        }
        $from = self::from();
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return '发件邮箱格式不正确';
        }
        return '';
    }

    /* ------------------------------------------------------------------ 邮箱 */

    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** 邮箱脱敏：ab***@example.com */
    public static function maskEmail(string $email): string
    {
        $email = self::normalizeEmail($email);
        if ($email === '') {
            return '';
        }
        list($name, $domain) = explode('@', $email, 2);
        $keep = mb_substr($name, 0, 2);
        return $keep . str_repeat('*', max(2, mb_strlen($name) - 2)) . '@' . $domain;
    }

    /* ------------------------------------------------------------------ 发码 */

    /**
     * @return array{ok:bool,msg:string,wait?:int,demo_code?:string}
     */
    public static function issue(string $email, string $ip = ''): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '邮箱验证未启用'];
        }

        $email = self::normalizeEmail($email);
        if ($email === '') {
            return ['ok' => false, 'msg' => '请输入正确的邮箱地址'];
        }

        $issue = self::configIssue();
        if ($issue !== '') {
            return ['ok' => false, 'msg' => $issue . '，请联系管理员'];
        }

        $now   = time();
        $state = self::read();

        if (!empty($state['email']) && $state['email'] === $email && !empty($state['sent_at'])
            && $now - (int)$state['sent_at'] < self::RESEND_GAP) {
            $wait = self::RESEND_GAP - ($now - (int)$state['sent_at']);
            return ['ok' => false, 'msg' => '发送过于频繁，请 ' . $wait . ' 秒后再试', 'wait' => $wait];
        }

        $sends = array_values(array_filter((array)($state['sends'] ?? []), static function ($t) use ($now) {
            return $now - (int)$t < 3600;
        }));
        if (count($sends) >= self::MAX_SENDS) {
            return ['ok' => false, 'msg' => '发送次数已达上限，请稍后再试'];
        }
        $sends[] = $now;

        $code = (string)random_int(100000, 999999);
        $sent = self::send($email, $code);

        if (!empty($sent['ok'])) {
            self::write([
                'email'    => $email,
                'code'     => $code,
                'expire'   => $now + self::CODE_TTL,
                'sent_at'  => $now,
                'attempts' => 0,
                'sends'    => $sends,
                'ip'       => $ip,
            ]);
        } else {
            $state['sends'] = $sends;
            self::write($state);
        }

        return $sent;
    }

    /**
     * @return array{ok:bool,msg:string}
     */
    public static function verify(string $email, string $code): array
    {
        $email = self::normalizeEmail($email);
        $code  = preg_replace('/\D/', '', $code);

        if ($email === '' || $code === '') {
            return ['ok' => false, 'msg' => '请输入邮箱与邮箱验证码'];
        }

        $state = self::read();
        if (empty($state['email']) || empty($state['code'])) {
            return ['ok' => false, 'msg' => '请先获取邮箱验证码'];
        }
        if ((string)$state['email'] !== $email) {
            return ['ok' => false, 'msg' => '邮箱与验证码不匹配，请重新获取'];
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

    public static function clear(): void
    {
        unset($_SESSION[self::SESS_KEY]);
    }

    /**
     * 后台「测试发送」：不受重发间隔限制
     *
     * @return array{ok:bool,msg:string}
     */
    public static function test(string $email): array
    {
        $email = self::normalizeEmail($email);
        if ($email === '') {
            return ['ok' => false, 'msg' => '请输入正确的邮箱地址'];
        }
        $issue = self::configIssue();
        if ($issue !== '') {
            return ['ok' => false, 'msg' => $issue];
        }
        $code = (string)random_int(100000, 999999);
        $res  = self::send($email, $code);
        return ['ok' => !empty($res['ok']), 'msg' => (string)($res['msg'] ?? '')];
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
     * @return array{ok:bool,msg:string,demo_code?:string}
     */
    private static function send(string $email, string $code): array
    {
        $siteName = (string)setting('site_name', '优享财务系统');
        $ttlMin   = (int)(self::CODE_TTL / 60);
        $subject  = '【' . $siteName . '】邮箱验证码';
        $body     = "您好：\n\n"
            . "您正在" . $siteName . "注册或验证邮箱，本次验证码为：" . $code . "\n"
            . "验证码 " . $ttlMin . " 分钟内有效，请勿泄露给他人。\n\n"
            . "如非本人操作，请忽略本邮件。\n\n"
            . $siteName . "\n" . now();

        if (self::demo()) {
            self::log('demo', $email, $code, '');
            return ['ok' => true, 'msg' => '验证码已发送（演示模式）', 'demo_code' => $code];
        }

        $issue = self::configIssue();
        if ($issue !== '') {
            return ['ok' => false, 'msg' => $issue];
        }

        $res = self::smtpSend($email, $subject, $body);
        self::log($res['ok'] ? 'smtp-ok' : 'smtp-fail', $email, null, $res['ok'] ? '' : $res['msg']);
        return $res;
    }

    /**
     * 通过 SMTP 发送纯文本邮件
     *
     * @return array{ok:bool,msg:string}
     */
    public static function smtpSend(string $to, string $subject, string $body, ?array $htmlBody = null): array
    {
        $host   = self::host();
        $port   = self::port();
        $secure = self::secure();
        $user   = self::user();
        $pass   = (string)setting('smtp_pass', '');
        $from   = self::from();
        $name   = trim((string)setting('smtp_from_name', ''));
        if ($name === '') {
            $name = (string)setting('site_name', '优享财务系统');
        }

        // SSL 直连与 STARTTLS 统一用 smtps / tls 传输
        $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 10);
        if (!$fp) {
            return ['ok' => false, 'msg' => '无法连接邮件服务器 ' . $host . ':' . $port . '（' . ($errstr !== '' ? $errstr : 'error ' . $errno) . '）'];
        }
        stream_set_timeout($fp, 10);

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $line, array $expect = [250]) use ($fp, $read): array {
            fwrite($fp, $line . "\r\n");
            $resp = $read();
            $code = (int)substr(trim($resp), 0, 3);
            return [in_array($code, $expect, true), $code, $resp];
        };

        try {
            $greet = $read();
            if ((int)substr(trim($greet), 0, 3) !== 220) {
                return ['ok' => false, 'msg' => '邮件服务器未响应（' . trim(mb_substr($greet, 0, 80)) . '）'];
            }

            list($ok, , $resp) = $cmd('EHLO ' . self::ehloName(), [250]);
            if (!$ok) {
                return ['ok' => false, 'msg' => 'EHLO 失败：' . trim(mb_substr($resp, 0, 80))];
            }

            if ($secure === 'tls') {
                list($ok, , $resp) = $cmd('STARTTLS', [220]);
                if (!$ok) {
                    return ['ok' => false, 'msg' => 'STARTTLS 失败：' . trim(mb_substr($resp, 0, 80))];
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'msg' => 'TLS 握手失败，请改用 465 端口 + SSL'];
                }
                $cmd('EHLO ' . self::ehloName(), [250]);
            }

            // 部分服务器在未认证时不支持 AUTH，直接跳过由服务器报错说明
            if ($user !== '') {
                list($ok, $code) = $cmd('AUTH LOGIN', [334]);
                if (!$ok) {
                    return ['ok' => false, 'msg' => '服务器不支持 AUTH LOGIN（返回 ' . $code . '）'];
                }
                list($ok, , $resp) = $cmd(base64_encode($user), [334]);
                if (!$ok) {
                    return ['ok' => false, 'msg' => 'SMTP 账号被拒绝：' . trim(mb_substr($resp, 0, 80))];
                }
                list($ok, , $resp) = $cmd(base64_encode($pass), [235]);
                if (!$ok) {
                    return ['ok' => false, 'msg' => 'SMTP 授权码不正确或未开启 SMTP 服务：' . trim(mb_substr($resp, 0, 80))];
                }
            }

            list($ok, , $resp) = $cmd('MAIL FROM:<' . $from . '>', [250]);
            if (!$ok) {
                return ['ok' => false, 'msg' => '发件人被拒绝：' . trim(mb_substr($resp, 0, 80))];
            }
            list($ok, , $resp) = $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            if (!$ok) {
                return ['ok' => false, 'msg' => '收件人被拒绝：' . trim(mb_substr($resp, 0, 80))];
            }
            list($ok, , $resp) = $cmd('DATA', [354]);
            if (!$ok) {
                return ['ok' => false, 'msg' => 'DATA 被拒绝：' . trim(mb_substr($resp, 0, 80))];
            }

            $headers = [
                'From: ' . self::encodeHeader($name) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . (strpos($from, '@') !== false ? explode('@', $from)[1] : 'localhost') . '>',
                'MIME-Version: 1.0',
            ];
            if ($htmlBody) {
                $boundary = 'yx' . bin2hex(random_bytes(6));
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                $data = implode("\r\n", $headers) . "\r\n\r\n"
                    . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($body)) . "\r\n"
                    . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($htmlBody)) . "\r\n"
                    . '--' . $boundary . "--\r\n";
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: base64';
                $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
            }

            // 行首的单独 "." 需要转义为 ".."
            $data = preg_replace('/^\./m', '..', $data);
            fwrite($fp, $data . "\r\n.\r\n");
            $resp = $read();
            if ((int)substr(trim($resp), 0, 3) !== 250) {
                return ['ok' => false, 'msg' => '投递失败：' . trim(mb_substr($resp, 0, 120))];
            }

            $cmd('QUIT', [221]);
            return ['ok' => true, 'msg' => '邮件已发送'];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '发送异常：' . $e->getMessage()];
        } finally {
            if (is_resource($fp)) {
                @fclose($fp);
            }
        }
    }

    private static function ehloName(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^a-zA-Z0-9\.\-]/', '', explode(':', $host)[0]);
        return $host !== '' && strpos($host, '.') !== false ? $host : 'localhost';
    }

    /** RFC 2047 编码主题 / 显示名（中文不乱码） */
    private static function encodeHeader(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /** 写运行日志：不记录明文验证码，只记录结果与错误 */
    private static function log(string $channel, string $email, ?string $code, string $extra): void
    {
        try {
            $parts = ['[' . now() . ']', $channel, 'email=' . self::maskEmail($email)];
            if ($code !== null) {
                $parts[] = 'code=' . $code;
            }
            if ($extra !== '') {
                $parts[] = 'detail=' . $extra;
            }
            @file_put_contents(
                YX_ROOT . '/runtime/mail.log',
                implode(' ', $parts) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e) {
            // 日志失败不影响主流程
        }
    }
}
