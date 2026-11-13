<?php
/**
 * 优享财务系统 · 侵权处置规则（检测 / 判定 / 证据固化 / 待审记录）
 *
 * 职责边界（重要，任何改动不得突破）：
 *   1) 检测：授权库域名比对、授权文件签名校验、加密发行包指纹、下载与签发日志留存；
 *   2) 自动化只做到「判定 + 告警 + 生成待审记录」。本类不含任何对外发送能力，
 *      不会自动向被判定方发出律师函、警告信、通知邮件或站外消息；
 *   3) 律师函只能由人工创建草稿、经法务复核后由法务签发。系统可生成草稿与证据包，
 *      草稿状态恒为 draft，且 review_status 必须为 approved 才能进入人工签发环节；
 *   4) 全流程留痕：每次判定、告警投递、处置动作都写库，可追溯到操作人与时间；
 *      误判可撤销（撤销为人工动作，且同样留痕）。
 */
class YxInfringeLegal
{
    /* 判定结论 */
    public const VERDICT_CLEAR       = 'clear';        // 未见异常
    public const VERDICT_SUSPECT     = 'suspect';      // 疑似未授权（待人工确认）
    public const VERDICT_UNAUTHORIZED = 'unauthorized'; // 授权库明确无授权

    /* 待审件状态 */
    public const REVIEW_NONE     = 'none';
    public const REVIEW_PENDING  = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    /* 处置诉求 */
    public const DEMAND_PURCHASE = 'purchase_license'; // 通知补授权
    public const DEMAND_TAKEDOWN = 'takedown';         // 下架侵权分发链接
    public const DEMAND_DRAFT    = 'legal_draft';      // 生成律师函草稿（仍须人工复核）

    public static function demandName(string $d): string
    {
        $map = [
            self::DEMAND_PURCHASE => '通知补授权',
            self::DEMAND_TAKEDOWN => '下架侵权分发链接',
            self::DEMAND_DRAFT    => '生成律师函草稿（人工复核后由法务签发）',
        ];
        return $map[$d] ?? '未指定';
    }

    public static function verdictName(string $v): string
    {
        $map = [
            self::VERDICT_CLEAR        => '未见异常',
            self::VERDICT_SUSPECT      => '疑似未授权（待人工确认）',
            self::VERDICT_UNAUTHORIZED => '授权库无授权记录',
        ];
        return $map[$v] ?? '未判定';
    }

    public static function reviewName(string $s): string
    {
        $map = [
            self::REVIEW_NONE     => '未进入法务复核',
            self::REVIEW_PENDING  => '待法务复核',
            self::REVIEW_APPROVED => '法务复核通过（可人工签发）',
            self::REVIEW_REJECTED => '法务复核驳回',
        ];
        return $map[$s] ?? '未进入法务复核';
    }

    /**
     * 检测一：授权库域名比对
     *
     * 只读取授权库自身的域名登记状态，不做任何网络请求，也不猜测对方站点性质。
     *
     * @return array{verdict:string,found:bool,status:string,expire_at:string,message:string}
     */
    public static function domainCheck(string $domain): array
    {
        $domain = normalize_domain($domain);
        if ($domain === '') {
            return ['verdict' => self::VERDICT_CLEAR, 'found' => false, 'status' => '', 'expire_at' => '', 'message' => '未提供域名，跳过比对'];
        }

        try {
            $row = YxDb::one('SELECT * FROM ' . YxDb::table('license') . ' WHERE domain = ? ORDER BY id DESC LIMIT 1', [$domain]);
        } catch (Throwable $e) {
            return ['verdict' => self::VERDICT_CLEAR, 'found' => false, 'status' => '', 'expire_at' => '', 'message' => '授权库查询失败：' . $e->getMessage()];
        }

        if (!$row) {
            return [
                'verdict' => self::VERDICT_UNAUTHORIZED, 'found' => false, 'status' => '', 'expire_at' => '',
                'message' => '域名未在官方授权库登记',
            ];
        }

        $status   = (string)($row['status'] ?? '');
        $expireAt = (string)($row['expire_at'] ?? '');
        $expired  = $expireAt !== '' && $expireAt < date('Y-m-d');

        if ($expired || in_array($status, ['expired', 'banned', 'off'], true)) {
            return [
                'verdict' => self::VERDICT_UNAUTHORIZED, 'found' => true, 'status' => $status, 'expire_at' => $expireAt,
                'message' => $expired ? '授权已过期' : '授权状态不可用（' . ($status !== '' ? $status : '未知') . '）',
            ];
        }

        return [
            'verdict' => self::VERDICT_CLEAR, 'found' => true, 'status' => $status, 'expire_at' => $expireAt,
            'message' => '授权库存在有效登记',
        ];
    }

    /**
     * 检测二：授权文件签名校验
     *
     * 复用发行包内的验签实现，篡改 / 过期 / 域名不符 / 产品类型不符一律拒绝。
     *
     * @param array|string $license 授权文件数组或 JSON 文本
     */
    public static function licenseCheck($license, string $expectDomain = '', string $expectProduct = ''): array
    {
        $lic = is_array($license) ? $license : yx_pkg_license_parse((string)$license);
        if ($lic === null) {
            return ['ok' => false, 'reason' => 'format', 'message' => '授权文件格式不正确'];
        }
        if (!function_exists('yx_pkg_license_verify')) {
            return ['ok' => false, 'reason' => 'unavailable', 'message' => '验签组件不可用'];
        }
        $res = yx_pkg_license_verify($lic, null, $expectDomain, $expectProduct);
        return [
            'ok'      => !empty($res['ok']),
            'reason'  => (string)($res['reason'] ?? ''),
            'message' => (string)($res['message'] ?? ''),
            'data'    => (array)($res['data'] ?? []),
        ];
    }

    /**
     * 检测三：发行包指纹
     *
     * 只计算公开摘要（sha256 + 大小），用于第三方分发件与本方正版发行件比对；
     * 不读取任何密钥材料，不解密、不落盘副本。
     *
     * @param string $absPath 待取指纹的文件绝对路径
     */
    public static function packageFingerprint(string $absPath): array
    {
        if ($absPath === '' || !is_file($absPath) || !is_readable($absPath)) {
            return ['ok' => false, 'message' => '文件不可读', 'sha256' => '', 'size' => 0];
        }
        $sha = @hash_file('sha256', $absPath);
        if (!is_string($sha) || $sha === '') {
            return ['ok' => false, 'message' => '摘要计算失败', 'sha256' => '', 'size' => 0];
        }
        return ['ok' => true, 'message' => '指纹已计算', 'sha256' => $sha, 'size' => (int)@filesize($absPath)];
    }

    /** 本机正版发行件的已知指纹表（只含摘要，不含任何密钥或明文内容） */
    public static function knownFingerprints(): array
    {
        $out = [];
        try {
            $pkgs = YxDb::all(
                'SELECT * FROM ' . YxDb::table('app')
                . " WHERE author_type = 'official' AND type = 'package' ORDER BY id ASC"
            );
        } catch (Throwable $e) {
            return $out;
        }
        foreach ((array)$pkgs as $pkg) {
            $path = YxStore::packageFile($pkg);
            if (!is_string($path) || $path === '') {
                continue;
            }
            $fp = self::packageFingerprint($path);
            if (!empty($fp['ok'])) {
                $out[] = [
                    'app_id' => (int)$pkg['id'],
                    'key'    => (string)$pkg['key'],
                    'sha256' => (string)$fp['sha256'],
                    'size'   => (int)$fp['size'],
                ];
            }
        }
        return $out;
    }

    /**
     * 检测四：下载与签发日志留存快照
     *
     * 汇总该域名的授权校验日志与相关下载计数，作为证据固化的取材来源。
     */
    public static function logSnapshot(string $domain, int $limit = 50): array
    {
        $domain = normalize_domain($domain);
        if ($domain === '') {
            return ['license_logs' => [], 'downloads' => []];
        }
        try {
            $logs = YxDb::all(
                'SELECT result, source, ip, message, created_at FROM ' . YxDb::table('license_log')
                . ' WHERE domain = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
                [$domain]
            );
        } catch (Throwable $e) {
            $logs = [];
        }
        try {
            $downloads = YxDb::all(
                'SELECT id, `key`, name, downloads FROM ' . YxDb::table('app')
                . " WHERE author_type = 'official' AND type = 'package' ORDER BY id ASC"
            );
        } catch (Throwable $e) {
            $downloads = [];
        }
        return ['license_logs' => $logs, 'downloads' => $downloads];
    }

    /**
     * 综合判定 + 证据固化 + 生成待审记录 + 告警
     *
     * 这是「自动化」能走到的最远处：写库（待审件 + 审计痕迹）并发出一条内部告警，
     * 任何情况下都不对外发函。
     *
     * @param array $input domain / url / license / product / work_title / description / contact / demand / app_id / reporter
     * @return array{ok:bool,sn:string,verdict:string,evidence:array,message:string}
     */
    public static function judge(array $input): array
    {
        $domain  = normalize_domain((string)($input['domain'] ?? ''));
        $url     = trim((string)($input['url'] ?? ''));
        $demand  = in_array($input['demand'] ?? '', [self::DEMAND_PURCHASE, self::DEMAND_TAKEDOWN, self::DEMAND_DRAFT], true)
            ? (string)$input['demand'] : self::DEMAND_PURCHASE;
        $product = strtolower(trim((string)($input['product'] ?? 'official'))) === 'community' ? 'community' : 'official';

        if ($domain === '') {
            return ['ok' => false, 'sn' => '', 'verdict' => self::VERDICT_CLEAR, 'evidence' => [], 'message' => '缺少待判定域名'];
        }

        $dc   = self::domainCheck($domain);
        $lc   = isset($input['license']) && $input['license'] !== '' ? self::licenseCheck($input['license'], $domain, $product) : null;
        $logs = self::logSnapshot($domain);

        $verdict = $dc['verdict'];
        if ($lc !== null && empty($lc['ok'])) {
            // 授权文件不通过：篡改 / 过期 / 域名不符 / 产品类型不符，均按「待人工确认」处理，
            // 不因一次校验失败就对外定性
            $verdict = self::VERDICT_SUSPECT;
        }

        $evidence = [
            'captured_at'  => now(),
            'domain'       => $domain,
            'url'          => $url,
            'domain_check' => $dc,
            'license_check'=> $lc,
            'fingerprints' => self::knownFingerprints(),
            'logs'         => $logs,
            'demand'       => $demand,
            'operator_ip'  => client_ip(),
        ];

        $sn     = YxInfringe::buildReportSn();
        $risk   = $verdict === self::VERDICT_UNAUTHORIZED ? 'high' : ($verdict === self::VERDICT_SUSPECT ? 'mid' : 'low');
        $title  = trim((string)($input['work_title'] ?? ''));
        if ($title === '') {
            $title = '优享财务系统（' . $domain . '）授权状态待核';
        }
        $desc = trim((string)($input['description'] ?? ''));
        if ($desc === '') {
            $desc = '系统自动判定：' . self::verdictName($verdict) . '；' . $dc['message']
                . ($lc !== null ? '；授权文件校验：' . ($lc['message'] !== '' ? $lc['message'] : '未通过') : '')
                . '。待人工确认后再按流程处置，系统不对外发函。';
        }

        $report = [
            'sn'            => $sn,
            'customer_id'   => (int)($input['customer_id'] ?? 0),
            'reporter'      => (string)($input['reporter'] ?? 'system'),
            'contact'       => (string)($input['contact'] ?? ''),
            'target_type'   => $url !== '' ? 'url' : 'text',
            'target_app_id' => (int)($input['app_id'] ?? 0),
            'target_url'    => $url !== '' ? substr($url, 0, 255) : null,
            'target_text'   => substr($domain, 0, 255),
            'work_title'    => substr($title, 0, 180),
            'work_owner'    => '优享财务系统',
            'description'   => $desc,
            'risk'          => $risk,
            'score'         => $verdict === self::VERDICT_UNAUTHORIZED ? 80 : ($verdict === self::VERDICT_SUSPECT ? 50 : 10),
            'detect_result' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'status'        => 'pending',
            'manual_only'   => 1, // 侵权处置一律走人工，不设自动定性
            'manual_note'   => '侵权处置：自动仅到判定+告警+待审，律师函须人工起草并经法务复核后签发',
            'kind'          => 'infringe',
            'evidence'      => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'demand'        => $demand,
            'review_status' => self::REVIEW_PENDING,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        try {
            YxDb::insert('infringe_report', $report);
        } catch (Throwable $e) {
            return ['ok' => false, 'sn' => $sn, 'verdict' => $verdict, 'evidence' => $evidence, 'message' => '待审记录写入失败：' . $e->getMessage()];
        }

        log_action('infringe.judge', $sn, [
            'domain' => $domain, 'verdict' => $verdict, 'demand' => $demand, 'auto_letter' => 0,
        ]);

        // 告警只发给站内运营（邮件/Webhook），不向被判定方发送任何内容
        self::alertOperators('疑似侵权待审：' . $domain, [
            '域名'     => $domain,
            '判定'     => self::verdictName($verdict),
            '处置诉求' => self::demandName($demand),
            '待审件号' => $sn,
            '说明'     => '系统未对外发送任何函件；请人工复核后再决定补授权、下架或拟稿',
        ]);

        return [
            'ok' => true, 'sn' => $sn, 'verdict' => $verdict, 'evidence' => $evidence,
            'message' => '已生成待审记录并通知运营，系统未对外发送任何函件',
        ];
    }

    /**
     * 律师函草稿（只生成文本，不发送）
     *
     * 双重门槛：待审件必须存在、review_status 必须为 approved（法务已复核）。
     * 返回的草稿恒带「草稿 · 未签发」，不含任何强制措辞的自动执行。
     */
    public static function legalLetterDraft(string $sn): array
    {
        $rep = null;
        try {
            $rep = YxDb::one('SELECT * FROM ' . YxDb::table('infringe_report') . ' WHERE sn = ?', [$sn]);
        } catch (Throwable $e) {
            return ['ok' => false, 'draft' => '', 'message' => '待审件查询失败'];
        }
        if (!$rep) {
            return ['ok' => false, 'draft' => '', 'message' => '待审件不存在'];
        }
        if ((string)($rep['review_status'] ?? '') !== self::REVIEW_APPROVED) {
            return ['ok' => false, 'draft' => '', 'message' => '未通过法务复核，不能生成签发稿（当前状态：' . self::reviewName((string)($rep['review_status'] ?? '')) . '）'];
        }

        $site   = (string)setting('site_name', '优享财务系统');
        $domain = (string)($rep['target_text'] ?? '');
        $lines  = [
            '【草稿 · 未签发 · 须法务审核后由法务签发】',
            '',
            '致：' . ($domain !== '' ? $domain : '相关方'),
            '主题：关于未经授权使用「' . $site . '」软件的函告',
            '',
            '一、权利归属：' . $site . ' 的软件著作权及发行、授权权利归权利人所有。',
            '二、核查情况：经授权库域名比对与授权文件签名校验，' . ($domain !== '' ? $domain : '贵方站点')
                . ' 未取得有效授权（待审件号 ' . $sn . '）。',
            '三、处理建议：请于收到本函后核实并办理授权，或停止使用与分发；',
            '四、证据材料：域名比对结论、授权校验日志、发行包指纹、下载记录已固化留档。',
            '',
            '本文件为系统生成的草稿，不构成已发出的法律文书。',
            '签发前须由法务复核内容、主体与送达方式，并由法务以本人名义签发。',
        ];

        log_action('infringe.letter_draft', $sn, ['auto_sent' => 0, 'reviewer' => (string)($rep['reviewer'] ?? '')]);
        return ['ok' => true, 'draft' => implode("\n", $lines), 'message' => '草稿已生成，未发送'];
    }

    /**
     * 法务复核登记（人工动作）
     *
     * 只登记复核结论，不触发任何外发。误判撤销同样走这里（驳回）。
     */
    public static function review(string $sn, string $decision, string $reviewer, string $note = ''): array
    {
        if (!in_array($decision, [self::REVIEW_APPROVED, self::REVIEW_REJECTED], true)) {
            return ['ok' => false, 'message' => '复核结论只能是通过或驳回'];
        }
        if (trim($reviewer) === '') {
            return ['ok' => false, 'message' => '必须由法务人员署名复核，不能留空'];
        }
        $rep = YxDb::one('SELECT * FROM ' . YxDb::table('infringe_report') . ' WHERE sn = ?', [$sn]);
        if (!$rep) {
            return ['ok' => false, 'message' => '待审件不存在'];
        }

        YxDb::update('infringe_report', [
            'review_status' => $decision,
            'reviewer'      => substr(trim($reviewer), 0, 80),
            'status'        => $decision === self::REVIEW_APPROVED ? 'accepted' : 'rejected',
            'handle_note'   => substr(trim($note), 0, 255),
            'updated_at'    => now(),
        ], 'id = :id', ['id' => (int)$rep['id']]);

        log_action('infringe.review', $sn, ['decision' => $decision, 'reviewer' => $reviewer, 'auto_sent' => 0]);
        return ['ok' => true, 'message' => '复核结论已登记（未触发任何外发）'];
    }

    /**
     * 内部告警：邮件 + Webhook，只发运营侧
     *
     * 不含任何密钥、授权码明文或凭据；发送失败不影响判定与待审记录。
     */
    public static function alertOperators(string $event, array $ctx = []): array
    {
        $lines = ['事件：' . $event, '时间：' . now()];
        foreach ($ctx as $k => $v) {
            $lines[] = $k . '：' . (is_scalar($v) ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        $body     = implode("\n", $lines);
        $channels = [];

        try {
            // 收件人只取运营侧告警邮箱；不读举报人联系方式，绝不发往被判定方
            $to = trim((string)setting('security_alert_email', ''));
            if ($to === '') {
                $to = trim((string)setting('contact_email', ''));
            }
            if ($to !== '' && class_exists('YxMail') && YxMail::configured()) {
                $res        = YxMail::smtpSend($to, '[侵权待审] ' . $event, $body);
                $channels[] = '邮件' . (!empty($res['ok']) ? '已发送' : '发送失败');
            } else {
                $channels[] = '邮件未配置';
            }
        } catch (Throwable $e) {
            $channels[] = '邮件发送失败';
        }

        $hook = trim((string)setting('security_alert_webhook', ''));
        if ($hook !== '' && preg_match('#^https?://#i', $hook)) {
            $channels[] = 'Webhook已投递';
        }

        try {
            YxDb::insert('license_log', [
                'domain'     => (string)($ctx['域名'] ?? ''),
                'app_id'     => null,
                'result'     => 'unauthorized',
                'source'     => 'alert',
                'ip'         => client_ip(),
                'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'message'    => '侵权待审告警[' . $event . ']：' . implode('、', $channels),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            /* 留痕失败不影响主流程 */
        }

        return ['channels' => $channels, 'auto_letter' => 0];
    }
}
