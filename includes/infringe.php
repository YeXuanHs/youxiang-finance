<?php
/**
 * 优享财务系统 · 版权侵权检测与处理
 *
 * 自动检测 = 本地规则库（关键词/黑名单/正则）+ 可选外部版权检测 API（可插拔）。
 * 规则命中累计评分 → 风险等级：0=未发现 / 1-2=低 / 3-7=中 / ≥8=高。
 */

class YxInfringe
{
    const STATUS = [
        'pending'  => '待处理',
        'accepted' => '已受理',
        'rejected' => '已驳回',
        'takedown' => '已下架',
        'closed'   => '已归档',
    ];

    public static function statusName(string $s): string
    {
        return self::STATUS[$s] ?? $s;
    }

    public static function riskName(string $r): string
    {
        return ['none' => '未发现', 'low' => '低', 'mid' => '中', 'high' => '高'][$r] ?? $r;
    }

    public static function riskFromScore(int $score): string
    {
        if ($score <= 0) {
            return 'none';
        }
        if ($score >= 8) {
            return 'high';
        }
        return $score >= 3 ? 'mid' : 'low';
    }

    public static function riskRank(string $r): int
    {
        return ['none' => 0, 'low' => 1, 'mid' => 2, 'high' => 3][$r] ?? 0;
    }

    /* ------------------------------ 规则库 ------------------------------ */

    public static function rules(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM ' . YxDb::table('infringe_rule');
        if ($onlyActive) {
            $sql .= ' WHERE status = 1';
        }
        return YxDb::all($sql . ' ORDER BY weight DESC, id DESC');
    }

    /** 预置规则（幂等，仅当规则库为空时写入） */
    public static function seedDefaults(): int
    {
        if ((int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('infringe_rule')) > 0) {
            return 0;
        }
        $rows = [
            ['keyword', '破解/去授权标识', '破解', 5, 'high'],
            ['keyword', '解密版本标识', '解密', 5, 'high'],
            ['keyword', '去授权', '去授权', 5, 'high'],
            ['keyword', '绕过授权', '绕过授权', 5, 'high'],
            ['keyword', 'nulled', 'nulled', 5, 'high'],
            ['keyword', 'cracked', 'cracked', 5, 'high'],
            ['keyword', '未授权版本', '未授权版', 4, 'high'],
            ['keyword', '需扩展绕过', 'php扩展', 2, 'mid'],
            ['blacklist', '已知风险开发者', '未知未来未完成', 3, 'mid'],
            ['regex', '混淆型后门特征', '/base64_decode\\s*\\(\\s*[\'"]?(eval|assert|gzinflate)/i', 5, 'high'],
        ];
        $n = 0;
        foreach ($rows as $r) {
            YxDb::insert('infringe_rule', [
                'type' => $r[0], 'name' => $r[1], 'pattern' => $r[2], 'weight' => $r[3], 'risk' => $r[4],
                'status' => 1, 'remark' => '系统预置', 'created_at' => now(),
            ]);
            $n++;
        }
        return $n;
    }

    /* ------------------------------ 检测 ------------------------------ */

    public static function extEnabled(): bool
    {
        return (string)setting('infringe_ext_enabled', '0') === '1'
            && trim((string)setting('infringe_ext_endpoint', '')) !== '';
    }

    /**
     * 综合检测
     * @return array ['score'=>int,'risk'=>string,'hits'=>array,'external'=>array]
     */
    public static function detect(string $text, bool $withExternal = true): array
    {
        $text  = (string)$text;
        $hits  = [];
        $score = 0;

        foreach (self::rules() as $r) {
            $ok = false;
            try {
                if ($r['type'] === 'regex') {
                    $ok = @preg_match((string)$r['pattern'], $text) === 1;
                } else {
                    $ok = ($r['pattern'] !== '' && mb_stripos($text, (string)$r['pattern']) !== false);
                }
            } catch (Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $score += (int)$r['weight'];
                $hits[] = [
                    'name' => $r['name'] ?: $r['pattern'], 'type' => $r['type'],
                    'pattern' => $r['pattern'], 'risk' => $r['risk'], 'weight' => (int)$r['weight'],
                ];
            }
        }

        $ext = ['enabled' => self::extEnabled(), 'ok' => false, 'risk' => '', 'score' => 0, 'hits' => [], 'msg' => ''];
        if ($withExternal && $ext['enabled']) {
            $ext = array_merge($ext, self::extDetect($text));
            if (!empty($ext['ok'])) {
                $score += (int)($ext['score'] ?? 0);
                foreach ((array)($ext['hits'] ?? []) as $h) {
                    $hits[] = [
                        'name' => (string)($h['name'] ?? '外部检测命中'), 'type' => 'external',
                        'pattern' => '', 'risk' => (string)($h['risk'] ?? ''), 'weight' => (int)($h['weight'] ?? 0),
                    ];
                }
            }
        }

        $risk = self::riskFromScore($score);
        foreach ($hits as $h) {
            if (self::riskRank((string)$h['risk']) > self::riskRank($risk)) {
                $risk = (string)$h['risk'];
            }
        }

        return ['score' => $score, 'risk' => $risk, 'hits' => $hits, 'external' => $ext];
    }

    /** 外部版权/内容检测 API（可插拔、全可配，配置于后台） */
    public static function extDetect(string $text): array
    {
        $base = trim((string)setting('infringe_ext_endpoint', ''));
        if ($base === '') {
            return ['ok' => false, 'msg' => '外部检测未配置'];
        }
        $path   = (string)setting('infringe_ext_path', '/detect');
        $url    = rtrim($base, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
        $method = strtoupper((string)setting('infringe_ext_method', 'POST_JSON'));
        $timeout = max(3, (int)setting('infringe_ext_timeout', 15));

        $params = [
            (string)setting('infringe_ext_appid_field', 'appid') => (string)setting('infringe_ext_appid', ''),
            (string)setting('infringe_ext_key_field', 'key')     => (string)setting('infringe_ext_key', ''),
            (string)setting('infringe_ext_text_field', 'text')   => $text,
        ];
        $params = self::extSign($params, (string)setting('infringe_ext_sign', 'none'), (string)setting('infringe_ext_key', ''), (string)setting('infringe_ext_sign_field', 'sign'));

        if ($method === 'GET') {
            $body = http_get($url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params), $timeout);
            $ok   = $body !== null;
        } elseif ($method === 'POST_FORM') {
            [$ok, , $body] = http_post_form($url, $params, $timeout);
        } else {
            [$ok, , $body] = http_post_json($url, $params, [], $timeout);
        }
        if (!$ok) {
            return ['ok' => false, 'msg' => '外部检测不可用：' . (string)$body, 'raw' => null];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => '返回非 JSON：' . mb_substr((string)$body, 0, 200), 'raw' => $body];
        }

        // 业务成功判定（ok_path 为空则跳过）
        $okPath = (string)setting('infringe_ext_ok_path', 'code');
        if ($okPath !== '') {
            $code = array_dig($json, $okPath);
            if ($code !== null && (string)$code !== (string)setting('infringe_ext_ok_value', '0')) {
                $msg = (string)array_dig($json, (string)setting('infringe_ext_msg_path', 'msg'), '');
                return ['ok' => false, 'msg' => '业务失败：' . $msg, 'raw' => $json];
            }
        }

        return [
            'ok'    => true,
            'risk'  => (string)array_dig($json, (string)setting('infringe_ext_risk_path', 'data.risk'), ''),
            'score' => (int)array_dig($json, (string)setting('infringe_ext_score_path', 'data.score'), 0),
            'hits'  => (array)array_dig($json, (string)setting('infringe_ext_hits_path', 'data.hits'), []),
            'msg'   => '',
            'raw'   => $json,
        ];
    }

    /** 按配置对参数签名 */
    private static function extSign(array $params, string $algo, string $secret, string $signField): array
    {
        if ($algo === 'none' || $secret === '' || $signField === '') {
            return $params;
        }
        unset($params[$signField]);
        $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k . '=' . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v);
        }
        $str = implode('&', $pairs);
        switch ($algo) {
            case 'append_secret': $sig = md5($str . $secret); break;
            case 'hmac_sha256':   $sig = hash_hmac('sha256', $str, $secret); break;
            case 'hmac_md5':      $sig = hash_hmac('md5', $str, $secret); break;
            case 'md5':           $sig = md5($str); break;
            default:              $sig = md5($str . $secret);
        }
        $params[$signField] = $sig;
        return $params;
    }

    /** 批量扫描商店第三方资源（默认仅用本地规则，避免外部 API 频繁调用） */
    public static function scanApps(bool $withExternal = false): array
    {
        $apps = YxDb::all('SELECT id,`key`,name,author,description FROM ' . YxDb::table('app') . " WHERE author_type = 'thirdparty' ORDER BY id");
        $sus  = [];
        foreach ($apps as $a) {
            $text = $a['name'] . "\n" . $a['author'] . "\n" . (string)$a['description'];
            $d    = self::detect($text, $withExternal);
            if ($d['score'] > 0) {
                $sus[] = [
                    'id' => (int)$a['id'], 'key' => $a['key'], 'name' => $a['name'],
                    'author' => $a['author'], 'risk' => $d['risk'], 'score' => $d['score'],
                    'hit_names' => implode('、', array_slice(array_column($d['hits'], 'name'), 0, 3)),
                ];
            }
        }
        return $sus;
    }

    /** 依据文本自动检测并生成举报单数据 */
    public static function buildReportSn(): string
    {
        return make_sn('IPR', 'infringe_report');
    }
}
