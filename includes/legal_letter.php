<?php
/**
 * 优享财务系统 · 律师函（人工起草 / AI 辅助生成 / 法务复核 / 电子签章签发）
 *
 * 职责边界（与 YxInfringeLegal 一致，任何改动不得突破）：
 *   1) 本类不包含任何对外发送能力。生成、复核、签发都只写库，不投递、不发信、不调用外部接口；
 *   2) AI 只负责生成/润色「草稿正文」，结果一律写入 content 并标注来源（content_source / ai_model / ai_note），
 *      页面必须如实展示内容由哪个引擎产出；AI 未配置或调用失败时，自动降级为内置模板引擎，
 *      保证在 ai_provider=mock、无外部 API 的环境下功能完整可用；
 *   3) 状态机单向推进：draft → pending → approved → issued，pending 可驳回回 rejected，
 *      rejected 允许修改后重新提交；只有 approved 才能签发，签发生成 seal_hash 供事后核验；
 *   4) 每一步都写 yx_law_letter_log 留痕，并同步调用 log_action 进管理端审计。
 */
class YxLegalLetter
{
    /* 状态 */
    public const ST_DRAFT    = 'draft';
    public const ST_PENDING  = 'pending';
    public const ST_APPROVED = 'approved';
    public const ST_REJECTED = 'rejected';
    public const ST_ISSUED   = 'issued';
    public const ST_VOID     = 'void';

    /* 正文来源 */
    public const SRC_MANUAL    = 'manual';
    public const SRC_AI        = 'ai';
    public const SRC_AI_POLISH = 'ai_polish';
    public const SRC_TEMPLATE  = 'template';

    public static function statusName(string $s): string
    {
        $map = [
            self::ST_DRAFT    => '草稿',
            self::ST_PENDING  => '待法务复核',
            self::ST_APPROVED => '复核通过（待签发）',
            self::ST_REJECTED => '复核驳回',
            self::ST_ISSUED   => '已签发',
            self::ST_VOID     => '已作废',
        ];
        return $map[$s] ?? '未知状态';
    }

    public static function statusClass(string $s): string
    {
        $map = [
            self::ST_DRAFT    => 'badge-mute',
            self::ST_PENDING  => 'badge-warn',
            self::ST_APPROVED => 'badge-info',
            self::ST_REJECTED => 'badge-bad',
            self::ST_ISSUED   => 'badge-ok',
            self::ST_VOID     => 'badge-mute',
        ];
        return $map[$s] ?? 'badge-mute';
    }

    public static function sourceName(string $s): string
    {
        $map = [
            self::SRC_MANUAL    => '人工撰写',
            self::SRC_AI        => 'AI 生成',
            self::SRC_AI_POLISH => 'AI 润色',
            self::SRC_TEMPLATE  => '内置模板生成',
        ];
        return $map[$s] ?? '人工撰写';
    }

    /** 当前系统可用的 AI 引擎描述（用于页面如实展示） */
    public static function aiEngineLabel(): string
    {
        $provider = class_exists('YxAi') ? YxAi::provider() : 'mock';
        $enabled  = class_exists('YxAi') && YxAi::enabled();
        if (!$enabled) {
            return 'AI 未开启（当前使用内置模板引擎）';
        }
        if ($provider === 'openai') {
            $model = trim((string)setting('ai_model', ''));
            return '外部大模型接口' . ($model !== '' ? '（' . $model . '）' : '') . '，失败时自动降级为内置模板引擎';
        }
        return '内置免密钥引擎（规则 + 模板），无需外部 API';
    }

    /* ------------------------------ 读取 ------------------------------ */

    public static function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('law_letter') . ' WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        $row['facts_arr'] = self::factsDecode((string)($row['facts'] ?? ''));
        return $row;
    }

    public static function getBySn(string $sn): ?array
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('law_letter') . ' WHERE sn = ?', [$sn]);
        if (!$row) {
            return null;
        }
        $row['facts_arr'] = self::factsDecode((string)($row['facts'] ?? ''));
        return $row;
    }

    /**
     * 列表查询
     *
     * @param array $f status 状态 | kw 关键字（编号/收函方/标题）| page 页码
     */
    public static function search(array $f = []): array
    {
        $where = [];
        $bind  = [];
        $status = (string)($f['status'] ?? '');
        if ($status !== '' && array_key_exists($status, [self::ST_DRAFT => 1, self::ST_PENDING => 1, self::ST_APPROVED => 1, self::ST_REJECTED => 1, self::ST_ISSUED => 1, self::ST_VOID => 1])) {
            $where[] = 'status = ?';
            $bind[]  = $status;
        }
        $kw = trim((string)($f['kw'] ?? ''));
        if ($kw !== '') {
            $where[] = '(sn LIKE ? OR recipient LIKE ? OR title LIKE ? OR report_sn LIKE ?)';
            $like    = '%' . $kw . '%';
            array_push($bind, $like, $like, $like, $like);
        }
        $sql = 'SELECT * FROM ' . YxDb::table('law_letter')
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC';
        $total = (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('law_letter') . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $bind
        );
        $p = paginate($total, (int)($f['per_page'] ?? 20));
        $rows = YxDb::all($sql . ' LIMIT ' . (int)$p['per_page'] . ' OFFSET ' . (int)$p['offset'], $bind);
        return ['rows' => $rows, 'pager' => $p, 'total' => $total];
    }

    /** 待复核队列（法务复核页使用） */
    public static function pendingQueue(int $limit = 100): array
    {
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('law_letter') . ' WHERE status = ? ORDER BY id ASC LIMIT ' . (int)$limit,
            [self::ST_PENDING]
        );
    }

    /** 流程日志 */
    public static function logs(int $letterId, int $limit = 100): array
    {
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('law_letter_log') . ' WHERE letter_id = ? ORDER BY id DESC LIMIT ' . (int)$limit,
            [$letterId]
        );
    }

    public static function stats(): array
    {
        $rows = YxDb::all('SELECT status, COUNT(*) AS c FROM ' . YxDb::table('law_letter') . ' GROUP BY status');
        $out  = ['total' => 0];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
        return $out;
    }

    /* ------------------------------ 写入 ------------------------------ */

    /**
     * 从待审件（yx_infringe_report）提取事实要素，供 AI / 模板共用
     */
    public static function factsFromReport(int $reportId): array
    {
        if ($reportId <= 0) {
            return [];
        }
        $rep = YxDb::one('SELECT * FROM ' . YxDb::table('infringe_report') . ' WHERE id = ?', [$reportId]);
        if (!$rep) {
            return [];
        }
        $evidence = [];
        if (!empty($rep['evidence'])) {
            $decoded = json_decode((string)$rep['evidence'], true);
            if (is_array($decoded)) {
                $evidence = $decoded;
            }
        }
        return [
            'report_sn'     => (string)($rep['sn'] ?? ''),
            'target'        => (string)($rep['target_text'] ?? ''),
            'target_url'    => (string)($rep['target_url'] ?? ''),
            'domain'        => normalize_domain((string)($rep['target_text'] ?? '')),
            'work_title'    => (string)($rep['work_title'] ?? ''),
            'verdict'       => YxInfringeLegal::verdictName((string)($rep['review_status'] ?? '')),
            'demand'        => (string)($rep['demand'] ?? ''),
            'evidence'      => $evidence,
            'detect_result' => (string)($rep['detect_result'] ?? ''),
        ];
    }

    /**
     * 新建草稿
     *
     * @param array $in title|recipient|recipient_domain|subject|facts|content|report_id|drafter
     * @return array{ok:bool,id:int,sn:string,msg:string}
     */
    public static function create(array $in, array $admin = []): array
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'id' => 0, 'sn' => '', 'msg' => '请填写内部标题'];
        }
        $reportId = (int)($in['report_id'] ?? 0);
        $facts    = $in['facts'] ?? [];
        $reportSn = '';
        if ($reportId > 0) {
            $fromRep = self::factsFromReport($reportId);
            if ($fromRep) {
                $facts    = array_merge($fromRep, is_array($facts) ? $facts : []);
                $reportSn = (string)($fromRep['report_sn'] ?? '');
            }
        }
        $sn = self::nextSn();
        $now = now();
        $id = YxDb::insert('law_letter', [
            'sn'               => $sn,
            'report_id'        => $reportId,
            'report_sn'        => $reportSn !== '' ? $reportSn : null,
            'title'            => mb_substr($title, 0, 180),
            'recipient'        => self::strOrNull($in['recipient'] ?? '', 180),
            'recipient_domain' => self::strOrNull($in['recipient_domain'] ?? '', 120),
            'subject'          => self::strOrNull($in['subject'] ?? '', 255),
            'facts'            => $facts ? json_encode($facts, JSON_UNESCAPED_UNICODE) : null,
            'content'          => (string)($in['content'] ?? ''),
            'content_source'   => self::SRC_MANUAL,
            'status'           => self::ST_DRAFT,
            'drafter'          => self::strOrNull($in['drafter'] ?? ($admin['nickname'] ?? $admin['username'] ?? ''), 80),
            'created_by'       => (int)($admin['id'] ?? ($_SESSION['admin_id'] ?? 0)),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        self::log($id, $sn, 'create', '新建律师函草稿', $admin, ['report_id' => $reportId]);
        return ['ok' => true, 'id' => $id, 'sn' => $sn, 'msg' => '草稿已创建'];
    }

    /**
     * 保存编辑（人工填写或 AI 生成结果落库）
     *
     * @param array $in 允许字段：title recipient recipient_domain subject facts content content_source drafter
     */
    public static function save(int $id, array $in, array $admin = []): array
    {
        $row = self::get($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '律师函不存在'];
        }
        if (in_array((string)$row['status'], [self::ST_ISSUED, self::ST_VOID], true)) {
            return ['ok' => false, 'msg' => '已签发/已作废的律师函不可再编辑'];
        }
        $data = [];
        if (array_key_exists('title', $in)) {
            $title = trim((string)$in['title']);
            if ($title === '') {
                return ['ok' => false, 'msg' => '内部标题不能为空'];
            }
            $data['title'] = mb_substr($title, 0, 180);
        }
        if (array_key_exists('recipient', $in)) {
            $data['recipient'] = self::strOrNull($in['recipient'], 180);
        }
        if (array_key_exists('recipient_domain', $in)) {
            $data['recipient_domain'] = self::strOrNull($in['recipient_domain'], 120);
        }
        if (array_key_exists('subject', $in)) {
            $data['subject'] = self::strOrNull($in['subject'], 255);
        }
        if (array_key_exists('facts', $in)) {
            $facts = $in['facts'];
            if (is_array($facts)) {
                $data['facts'] = $facts ? json_encode($facts, JSON_UNESCAPED_UNICODE) : null;
            } else {
                $data['facts'] = trim((string)$facts) !== '' ? (string)$facts : null;
            }
        }
        if (array_key_exists('content', $in)) {
            $data['content'] = (string)$in['content'];
        }
        if (array_key_exists('content_source', $in)) {
            $src = (string)$in['content_source'];
            $data['content_source'] = in_array($src, [self::SRC_MANUAL, self::SRC_AI, self::SRC_AI_POLISH, self::SRC_TEMPLATE], true) ? $src : self::SRC_MANUAL;
        }
        if (array_key_exists('drafter', $in)) {
            $data['drafter'] = self::strOrNull($in['drafter'], 80);
        }
        if (!$data) {
            return ['ok' => false, 'msg' => '没有需要保存的内容'];
        }
        $data['updated_at'] = now();
        // 驳回后的稿件一旦修改，视为回到草稿，须重新提交复核
        if ((string)$row['status'] === self::ST_REJECTED) {
            $data['status'] = self::ST_DRAFT;
        }
        YxDb::update('law_letter', $data, 'id = :id', ['id' => $id]);
        self::log($id, (string)$row['sn'], 'save', '保存内容（' . self::sourceName((string)($data['content_source'] ?? $row['content_source'])) . '）', $admin);
        return ['ok' => true, 'msg' => '已保存'];
    }

    public static function delete(int $id, array $admin = []): array
    {
        $row = self::get($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '律师函不存在'];
        }
        if ((string)$row['status'] === self::ST_ISSUED) {
            return ['ok' => false, 'msg' => '已签发的律师函不允许删除，只能作废'];
        }
        YxDb::delete('law_letter', 'id = :id', ['id' => $id]);
        self::log($id, (string)$row['sn'], 'delete', '删除草稿', $admin);
        return ['ok' => true, 'msg' => '已删除'];
    }

    /* ------------------------------ AI / 模板生成 ------------------------------ */

    /**
     * 生成正文
     *
     * 先尝试已配置的 AI 引擎；不可用、未开启或调用失败时降级到内置模板引擎。
     * 返回体始终包含 engine（实际产出引擎）与 note（页面展示说明）。
     *
     * @param array  $facts 事实要素
     * @param string $mode  full=整篇生成 | polish=在 existing 基础上润色
     * @param string $existing 待润色正文
     */
    public static function generate(array $facts, string $mode = 'full', string $existing = ''): array
    {
        $mode  = $mode === 'polish' ? 'polish' : 'full';
        $facts = self::normalizeFacts($facts);

        $aiTry = self::tryAi($facts, $mode, $existing);
        if (!empty($aiTry['ok'])) {
            return [
                'ok'      => true,
                'content' => (string)$aiTry['content'],
                'engine'  => (string)$aiTry['engine'],
                'source'  => $mode === 'polish' ? self::SRC_AI_POLISH : self::SRC_AI,
                'note'    => (string)$aiTry['note'],
            ];
        }

        $content = self::templateGenerate($facts);
        $note = '内置模板引擎生成（未调用外部 AI';
        if (!empty($aiTry['msg'])) {
            $note .= '，原因：' . mb_substr((string)$aiTry['msg'], 0, 100);
        }
        $note .= '）';
        return [
            'ok'      => true,
            'content' => $content,
            'engine'  => 'builtin-template',
            'source'  => self::SRC_TEMPLATE,
            'note'    => $note,
        ];
    }

    /** 尝试外部 AI；任何异常都返回 ok=false，不抛出 */
    private static function tryAi(array $facts, string $mode, string $existing): array
    {
        if (!class_exists('YxAi') || !YxAi::enabled()) {
            return ['ok' => false, 'msg' => 'AI 功能未开启'];
        }
        if (YxAi::provider() !== 'openai') {
            return ['ok' => false, 'msg' => '当前为内置免密钥引擎，模板引擎效果更稳定'];
        }
        if ($mode === 'polish' && trim($existing) === '') {
            return ['ok' => false, 'msg' => '没有可润色的正文'];
        }
        $company = YxLegalSeal::companyName();
        $prompt  = "你是{$company}的法务助理，负责起草中国法律语境下的《律师函》。\n"
            . "要求：使用正式书面语，分条列明（一、二、三…），不得编造具体法条条号，不得虚构事实与证据。\n"
            . "只输出函件正文，不要输出解释、标题或 Markdown 标记。\n\n"
            . "【事实要素】\n" . self::factsText($facts) . "\n";
        if ($mode === 'polish') {
            $prompt .= "\n【待润色的正文】\n" . $existing . "\n\n请在不改变事实与要求的前提下润色语句、统一格式。";
        }
        try {
            $res = YxAi::driver()->chat([
                ['role' => 'system', 'content' => '你是严谨的中文法务文书写作助手，只输出函件正文。'],
                ['role' => 'user', 'content' => $prompt],
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => 'AI 调用异常：' . $e->getMessage()];
        }
        if (empty($res['ok']) || trim((string)($res['content'] ?? '')) === '') {
            return ['ok' => false, 'msg' => (string)($res['msg'] ?? 'AI 未返回内容')];
        }
        $engine = trim((string)setting('ai_model', ''));
        return [
            'ok'      => true,
            'content' => trim((string)$res['content']),
            'engine'  => 'ai:' . ($engine !== '' ? $engine : 'openai-compatible'),
            'note'    => '由外部大模型接口生成，须人工逐条核对事实、称谓与法律依据后使用',
        ];
    }

    /** 内置模板引擎：标准律师函结构，完全确定、可离线运行 */
    public static function templateGenerate(array $facts): string
    {
        $facts   = self::normalizeFacts($facts);
        $company = YxLegalSeal::companyName();
        $date    = date('Y年n月j日');
        $site    = (string)setting('site_name', $company);

        $recipient = $facts['recipient'] !== '' ? $facts['recipient'] : '相关网络服务提供者 / 实际使用方';
        $subject   = $facts['subject'] !== '' ? $facts['subject'] : '关于未经授权使用「' . $site . '」软件的函告';
        $domain    = $facts['domain'] !== '' ? $facts['domain'] : $facts['target'];

        $lines = [];
        $lines[] = '律 师 函';
        $lines[] = '';
        $lines[] = '致：' . $recipient . ($domain !== '' ? '（' . $domain . '）' : '');
        $lines[] = '事由：' . $subject;
        $lines[] = '';
        $lines[] = '一、权利基础';
        $lines[] = '「' . $site . '」相关软件的著作权及复制、发行、信息网络传播等权利，依法由权利人'
            . $company . '享有。任何单位或个人未经权利人许可，不得复制、修改、发行、通过信息网络传播该软件，'
            . '亦不得提供规避技术措施的手段或服务。';
        $lines[] = '';
        $lines[] = '二、核查事实';
        $factItems = [];
        if ($domain !== '') {
            $factItems[] = '经对授权库登记信息进行比对，' . $domain . ' 未查询到有效的正版授权登记记录；';
        }
        if ($facts['target_url'] !== '') {
            $factItems[] = '已对相关分发页面（' . $facts['target_url'] . '）进行访问，页面存在发行或提供下载的行为；';
        }
        if ($facts['work_title'] !== '') {
            $factItems[] = '涉及作品/产品名称：' . $facts['work_title'] . '；';
        }
        if ($facts['report_sn'] !== '') {
            $factItems[] = '上述情形已由权利人内部立项核查，核查单号为 ' . $facts['report_sn'] . '；';
        }
        if ($facts['matter'] !== '') {
            $factItems[] = $facts['matter'] . '；';
        }
        if (!$factItems) {
            $factItems[] = '经权利人核查，发现贵方存在未经许可使用、复制或向公众提供权利人软件的行为；';
        }
        $factItems[] = '相关网页快照、下载与签发日志、发行包指纹校验记录等证据材料均已固定留档。';
        $lines[] = '经核查，现确认以下事实：';
        foreach ($factItems as $i => $it) {
            $lines[] = '（' . self::cnNum($i + 1) . '）' . $it;
        }
        $lines[] = '';
        $lines[] = '三、法律依据';
        $lines[] = '《中华人民共和国民法典》关于民事主体依法享有知识产权的规定，《中华人民共和国著作权法》'
            . '关于著作权人享有复制权、发行权、信息网络传播权及侵权责任的规定，'
            . '《计算机软件保护条例》关于软件著作权保护的规定，以及《中华人民共和国刑法》第二百一十七条'
            . '关于侵犯著作权罪的规定。上述行为已构成对权利人合法权益的侵害。';
        $lines[] = '';
        $lines[] = '四、函告要求';
        $lines[] = '现函告贵方，自收到本函之日起：';
        $lines[] = '（一）立即停止未经许可的复制、发行、信息网络传播及相关宣传行为；';
        $lines[] = '（二）立即删除、下架全部侵权内容与下载链接，并停止提供规避技术措施的手段；';
        $lines[] = '（三）就已有侵权行为与权利人联系，补办正版授权并协商赔偿事宜；';
        $lines[] = '（四）自收到本函之日起七个工作日内，以书面形式向本函落款方回复处理结果。';
        if ($facts['demand'] === YxInfringeLegal::DEMAND_PURCHASE) {
            $lines[] = '如贵方希望继续使用，可与权利人联系办理正版授权，权利人将依规提供授权文件与技术支持。';
        }
        $lines[] = '';
        $lines[] = '五、法律后果';
        $lines[] = '如贵方未在上述期限内停止侵权并作出回应，权利人将依法采取包括但不限于向网络服务提供者发送通知、'
            . '向著作权行政管理部门投诉、向人民法院提起民事诉讼、向公安机关报案等维权措施。'
            . '由此产生的全部损失、维权合理支出（含律师费、公证费、诉讼费）及相应法律责任，概由侵权方承担。';
        $lines[] = '';
        $lines[] = '本函为权利人正式函告文件，不构成对任何权利的放弃。';
        $lines[] = '';
        $lines[] = $company;
        $lines[] = $date;
        $lines[] = '（此处加盖电子印章）';

        return implode("\n", $lines);
    }

    /* ------------------------------ 流程 ------------------------------ */

    /** 提交法务复核：draft|rejected → pending */
    public static function submitForReview(int $id, array $in, array $admin = []): array
    {
        $row = self::get($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '律师函不存在'];
        }
        $st = (string)$row['status'];
        if (!in_array($st, [self::ST_DRAFT, self::ST_REJECTED], true)) {
            return ['ok' => false, 'msg' => '当前状态（' . self::statusName($st) . '）不能提交复核'];
        }
        if (trim((string)$row['content']) === '') {
            return ['ok' => false, 'msg' => '正文为空，不能提交复核'];
        }
        $drafter = trim((string)($in['drafter'] ?? $row['drafter'] ?? ''));
        if ($drafter === '') {
            return ['ok' => false, 'msg' => '请填写起草人姓名'];
        }
        $recipient = trim((string)($in['recipient'] ?? $row['recipient'] ?? ''));
        if ($recipient === '') {
            return ['ok' => false, 'msg' => '请填写收函单位，否则函件无法送达'];
        }
        YxDb::update('law_letter', [
            'status'     => self::ST_PENDING,
            'drafter'    => mb_substr($drafter, 0, 80),
            'recipient'  => mb_substr($recipient, 0, 180),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
        self::log($id, (string)$row['sn'], 'submit', '提交法务复核', $admin);
        return ['ok' => true, 'msg' => '已提交法务复核'];
    }

    /** 法务复核：pending → approved | rejected */
    public static function review(int $id, string $decision, string $reviewer, string $note = '', array $admin = []): array
    {
        $row = self::get($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '律师函不存在'];
        }
        if ((string)$row['status'] !== self::ST_PENDING) {
            return ['ok' => false, 'msg' => '只有「待法务复核」的律师函可以复核（当前：' . self::statusName((string)$row['status']) . '）'];
        }
        if (!in_array($decision, [self::ST_APPROVED, self::ST_REJECTED], true)) {
            return ['ok' => false, 'msg' => '复核结论只能是通过或驳回'];
        }
        $reviewer = trim($reviewer);
        if ($reviewer === '') {
            return ['ok' => false, 'msg' => '必须由复核人署名，不能留空'];
        }
        if ($decision === self::ST_REJECTED && trim($note) === '') {
            return ['ok' => false, 'msg' => '驳回必须填写驳回理由'];
        }
        YxDb::update('law_letter', [
            'status'      => $decision,
            'reviewer'    => mb_substr($reviewer, 0, 80),
            'review_note' => self::strOrNull($note, 255),
            'reviewed_at' => now(),
            'updated_at'  => now(),
        ], 'id = :id', ['id' => $id]);
        self::log($id, (string)$row['sn'], 'review', ($decision === self::ST_APPROVED ? '复核通过' : '复核驳回') . ($note !== '' ? '：' . $note : ''), $admin);
        return ['ok' => true, 'msg' => $decision === self::ST_APPROVED ? '复核通过，可进入签发' : '已驳回，待起草人修改'];
    }

    /**
     * 签发（加盖电子印章）
     *
     * 只有 approved 可签发；生成 seal_hash = sha256(编号|单位|日期|正文)，供事后核验正文未被篡改。
     */
    public static function issue(int $id, array $in, array $admin = []): array
    {
        $row = self::get($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '律师函不存在'];
        }
        if ((string)$row['status'] !== self::ST_APPROVED) {
            return ['ok' => false, 'msg' => '只有法务复核通过的律师函可以签发（当前：' . self::statusName((string)$row['status']) . '）'];
        }
        $issuer = trim((string)($in['issuer'] ?? '')) !== '' ? trim((string)$in['issuer']) : YxLegalSeal::companyName();
        $sealDate = trim((string)($in['seal_date'] ?? ''));
        if ($sealDate === '') {
            $sealDate = date('Y-m-d');
        }
        if (strtotime($sealDate) === false) {
            return ['ok' => false, 'msg' => '印章日期格式不正确'];
        }
        $hash = self::sealHash([
            'sn'      => (string)$row['sn'],
            'issuer'  => $issuer,
            'date'    => $sealDate,
            'content' => (string)$row['content'],
        ]);
        YxDb::update('law_letter', [
            'status'    => self::ST_ISSUED,
            'issuer'    => mb_substr($issuer, 0, 120),
            'seal_date' => date('Y-m-d', strtotime($sealDate)),
            'seal_hash' => $hash,
            'issued_at' => now(),
            'issued_by' => (int)($admin['id'] ?? ($_SESSION['admin_id'] ?? 0)),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
        self::log($id, (string)$row['sn'], 'issue', '签发并加盖电子印章，印章日期 ' . $sealDate, $admin);
        return ['ok' => true, 'msg' => '已签发并加盖电子印章', 'seal_hash' => $hash];
    }

    /** 作废（已签发的只能作废，不删除） */
    public static function void(int $id, string $note = '', array $admin = []): array
    {
        $row = self::get($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '律师函不存在'];
        }
        if ((string)$row['status'] === self::ST_VOID) {
            return ['ok' => false, 'msg' => '该律师函已作废'];
        }
        YxDb::update('law_letter', [
            'status'     => self::ST_VOID,
            'review_note' => self::strOrNull($note, 255),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
        self::log($id, (string)$row['sn'], 'void', '作废' . ($note !== '' ? '：' . $note : ''), $admin);
        return ['ok' => true, 'msg' => '已作废'];
    }

    /** 签发内容摘要 */
    public static function sealHash(array $parts): string
    {
        return hash('sha256', implode("\n", [
            (string)($parts['sn'] ?? ''),
            (string)($parts['issuer'] ?? ''),
            (string)($parts['date'] ?? ''),
            trim((string)($parts['content'] ?? '')),
        ]));
    }

    /** 核验已签发函件正文是否与签发时一致 */
    public static function verify(array $row): bool
    {
        if (empty($row['seal_hash'])) {
            return false;
        }
        return hash_equals((string)$row['seal_hash'], self::sealHash([
            'sn'      => (string)$row['sn'],
            'issuer'  => (string)$row['issuer'],
            'date'    => (string)$row['seal_date'],
            'content' => (string)$row['content'],
        ]));
    }

    /* ------------------------------ 工具 ------------------------------ */

    public static function nextSn(): string
    {
        $prefix = 'LS-' . date('Y') . '-';
        for ($i = 0; $i < 8; $i++) {
            $candidate = $prefix . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            if (!YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('law_letter') . ' WHERE sn = ?', [$candidate])) {
                return $candidate;
            }
        }
        return $prefix . strtoupper(random_key(4));
    }

    /** 事实要素标准化，保证模板/AI 输入稳定 */
    public static function normalizeFacts(array $facts): array
    {
        $get = function (string $k) use ($facts): string {
            $v = $facts[$k] ?? '';
            return is_scalar($v) ? trim((string)$v) : '';
        };
        $domain = $get('domain');
        if ($domain === '') {
            $domain = normalize_domain($get('target'));
        }
        return [
            'recipient'  => $get('recipient'),
            'subject'    => $get('subject'),
            'target'     => $get('target'),
            'target_url' => $get('target_url'),
            'domain'     => $domain,
            'work_title' => $get('work_title'),
            'report_sn'  => $get('report_sn'),
            'demand'     => $get('demand'),
            'matter'     => $get('matter'),
            'deadline'   => $get('deadline') !== '' ? $get('deadline') : '七个工作日',
        ];
    }

    /** 事实要素转纯文本（供 AI 提示词与页面复核使用） */
    public static function factsText(array $facts): string
    {
        $facts = self::normalizeFacts($facts);
        $labels = [
            'recipient'  => '收函方',
            'subject'    => '事由',
            'target'     => '涉及对象',
            'target_url' => '侵权页面',
            'domain'     => '涉及域名',
            'work_title' => '作品/产品名称',
            'report_sn'  => '核查单号',
            'demand'     => '处置诉求',
            'matter'     => '补充说明',
            'deadline'   => '回复期限',
        ];
        $out = [];
        foreach ($labels as $k => $lab) {
            if (($facts[$k] ?? '') !== '') {
                $out[] = $lab . '：' . $facts[$k];
            }
        }
        $out[] = '发函单位：' . YxLegalSeal::companyName();
        return implode("\n", $out);
    }

    public static function factsDecode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    /** 把正文按行渲染为 HTML（仅做换行与转义，不解析 HTML） */
    public static function renderText(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $html = '';
        foreach (explode("\n", $content) as $line) {
            $t = trim($line);
            if ($t === '') {
                $html .= '<div class="ll-blank"></div>';
                continue;
            }
            $cls = 'll-line';
            if (preg_match('/^[一二三四五六七八九十]+、/u', $t)) {
                $cls .= ' ll-h';
            } elseif (preg_match('/^律\s*师\s*函$/u', $t)) {
                $cls .= ' ll-title';
            } elseif (preg_match('/^（[一二三四五六七八九十]+）/u', $t)) {
                $cls .= ' ll-item';
            }
            $html .= '<div class="' . $cls . '">' . e($line) . '</div>';
        }
        return $html;
    }

    private static function cnNum(int $n): string
    {
        $d = ['〇', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        if ($n < 10) {
            return $d[$n];
        }
        if ($n === 10) {
            return '十';
        }
        if ($n < 20) {
            return '十' . $d[$n % 10];
        }
        $t = $d[intdiv($n, 10)] . '十';
        return $n % 10 === 0 ? $t : $t . $d[$n % 10];
    }

    private static function strOrNull($v, int $max): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }

    private static function log(int $letterId, string $sn, string $action, string $note, array $admin = [], array $extra = []): void
    {
        $operator = trim((string)($admin['nickname'] ?? ''));
        if ($operator === '') {
            $operator = trim((string)($admin['username'] ?? ''));
        }
        try {
            YxDb::insert('law_letter_log', [
                'letter_id'  => $letterId,
                'letter_sn'  => $sn !== '' ? $sn : null,
                'action'     => $action,
                'operator'   => self::strOrNull($operator, 80),
                'admin_id'   => (int)($admin['id'] ?? ($_SESSION['admin_id'] ?? 0)),
                'note'       => self::strOrNull($note, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // 留痕失败不阻断主流程
        }
        log_action('legal_letter.' . $action, $sn, array_merge(['note' => $note], $extra));
    }
}
