<?php
/**
 * 优享财务系统 · 小优助手（在线客服）业务逻辑
 *
 * 会话状态机：ai（AI 自动值守解答）→ waiting（等待人工）→ serving（人工服务中）→ closed（已结束）
 * AI 回答不了的问题自动转人工；商务合作 / 售后咨询可留下联系方式与需求，落库并自动建工单。
 */

class YxChat
{
    const MAX_LEN = 500;

    const STATUS = [
        'ai'      => 'AI 解答',
        'waiting' => '等待人工',
        'serving' => '人工服务中',
        'closed'  => '已结束',
    ];

    /** 留言类型 */
    const TOPICS = [
        'business'  => '商务合作',
        'aftersale' => '售后咨询',
    ];

    /** 留言类型对应的工单部门 */
    const TOPIC_DEPT = [
        'business'  => 'sales',
        'aftersale' => 'tech',
    ];

    public static function enabled(): bool
    {
        return (string)setting('chat_enabled', '1') === '1';
    }

    public static function statusName(string $s): string
    {
        return self::STATUS[$s] ?? $s;
    }

    public static function welcome(): string
    {
        $custom = trim((string)setting('chat_welcome', ''));
        if ($custom !== '') {
            return $custom;
        }
        return '您好，我是小优助手，7×24 小时在线为您解答产品、续费、授权、发票等问题。商务合作或售后问题可点击「商务合作 / 售后咨询」留言；我答不上来的问题，会自动为您转接人工客服。';
    }

    public static function ratePerMin(): int
    {
        return max(1, (int)setting('chat_rate_per_min', 10));
    }

    public static function transferDept(): string
    {
        $d = (string)setting('chat_ticket_dept', 'sales');
        return in_array($d, ['tech', 'billing', 'sales', 'abuse'], true) ? $d : 'sales';
    }

    /* ------------------------------ 会话 ------------------------------ */

    public static function byId(int $id): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('chat_session') . ' WHERE id = ?', [$id]);
        return $r ?: null;
    }

    public static function byToken(string $token): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('chat_session') . ' WHERE token = ?', [$token]);
        return $r ?: null;
    }

    /** 取或建会话 */
    public static function ensureSession(string $token, ?array $customer, string $ip, string $sourceUrl = ''): array
    {
        $s = self::byToken($token);
        if ($s) {
            if ((int)$s['customer_id'] === 0 && $customer) {
                YxDb::update('chat_session', ['customer_id' => (int)$customer['id'], 'visitor_name' => (string)$customer['username']], 'id = :id', ['id' => (int)$s['id']]);
                $s['customer_id']   = (int)$customer['id'];
                $s['visitor_name']  = $customer['username'];
            }
            return $s;
        }
        $id = YxDb::insert('chat_session', [
            'token'          => $token,
            'customer_id'    => $customer ? (int)$customer['id'] : 0,
            'visitor_name'   => $customer ? (string)$customer['username'] : null,
            'visitor_ip'     => $ip,
            'source_url'     => $sourceUrl !== '' ? mb_substr($sourceUrl, 0, 255) : null,
            'status'         => 'ai',
            'created_at'     => now(),
            'updated_at'     => now(),
            'last_msg_at'    => now(),
        ]);
        self::addMessage($id, 'ai', self::welcome());
        return self::byId($id);
    }

    public static function messages(int $sessionId, int $afterId = 0, int $limit = 200): array
    {
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('chat_message') . ' WHERE session_id = ? AND id > ? ORDER BY id ASC LIMIT ' . max(1, $limit),
            [$sessionId, $afterId]
        );
    }

    public static function addMessage(int $sessionId, string $role, string $content): int
    {
        $id = YxDb::insert('chat_message', [
            'session_id' => $sessionId,
            'role'       => $role,
            'content'    => $content,
            'created_at' => now(),
        ]);
        YxDb::exec(
            'UPDATE ' . YxDb::table('chat_session') . ' SET message_count = message_count + 1, last_msg_at = ?, updated_at = ? WHERE id = ?',
            [now(), now(), $sessionId]
        );
        if ($role === 'visitor') {
            YxDb::exec('UPDATE ' . YxDb::table('chat_session') . ' SET unread_admin = unread_admin + 1 WHERE id = ?', [$sessionId]);
        } elseif (in_array($role, ['ai', 'agent', 'system'], true)) {
            YxDb::exec('UPDATE ' . YxDb::table('chat_session') . ' SET unread_visitor = unread_visitor + 1 WHERE id = ?', [$sessionId]);
        }
        return $id;
    }

    /* ------------------------------ 访客侧 ------------------------------ */

    /** 访客发消息，AI 阶段自动解答 */
    public static function visitorSend(array $session, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'msg' => '请输入内容'];
        }
        if (mb_strlen($content) > self::MAX_LEN) {
            return ['ok' => false, 'msg' => '消息过长（最多 ' . self::MAX_LEN . ' 字）'];
        }
        if ($session['status'] === 'closed') {
            return ['ok' => false, 'msg' => '会话已结束，请重新发起咨询'];
        }

        $sid     = (int)$session['id'];
        $history = self::aiHistory($sid); // 先取历史，避免与当前问题重复
        self::addMessage($sid, 'visitor', $content);

        // 报案 / 涉警类诉求：AI 不代答、不定性，直接给出导办口径并转人工
        if ($session['status'] === 'ai' && YxDispute::inspect($content)['hit']) {
            $n = YxDispute::NOTICE;
            self::addMessage($sid, 'system', $n['title'] . '：' . $n['body'] . ' ' . implode(' ', $n['steps']));
            self::transfer($session, '报案类诉求，仅人工办理');
            return ['ok' => true, 'msg' => '已发送', 'manual_only' => true];
        }

        if ($session['status'] === 'ai') {
            if (!YxAi::enabled()) {
                self::addMessage($sid, 'system', '小优助手暂未开启，请点击「转人工」联系客服。');
            } elseif (!self::aiQuotaOk($sid)) {
                self::addMessage($sid, 'system', '咨询较为频繁，请稍候片刻，或点击「转人工」。');
            } else {
                $res = YxAi::ask($content, $history);
                if (!empty($res['ok'])) {
                    self::addMessage($sid, 'ai', (string)$res['reply']);
                    YxDb::exec('UPDATE ' . YxDb::table('chat_session') . ' SET ai_count = ai_count + 1 WHERE id = ?', [$sid]);
                    /* AI 表示无法解答：自动转人工 */
                    if (!empty($res['handoff'])) {
                        self::transfer($session, '小优助手无法解答，自动转接人工');
                    }
                } else {
                    /* AI 服务异常：告知后自动转人工 */
                    self::addMessage($sid, 'system', '小优助手暂时无法回答（' . ($res['msg'] ?? '') . '）。');
                    self::transfer($session, 'AI 服务不可用，自动转接人工');
                }
            }
        }
        return ['ok' => true, 'msg' => '已发送'];
    }

    private static function aiQuotaOk(int $sessionId): bool
    {
        $n = (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('chat_message') . " WHERE session_id = ? AND role = 'ai' AND created_at >= ?",
            [$sessionId, date('Y-m-d H:i:s', time() - 60)]
        );
        return $n < self::ratePerMin();
    }

    private static function aiHistory(int $sessionId, int $limit = 8): array
    {
        $rows = YxDb::all(
            'SELECT role, content FROM ' . YxDb::table('chat_message') . " WHERE session_id = ? AND role IN ('visitor','ai') ORDER BY id DESC LIMIT " . max(1, $limit),
            [$sessionId]
        );
        $rows = array_reverse($rows);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = ['role' => $r['role'] === 'ai' ? 'assistant' : 'user', 'content' => (string)$r['content']];
        }
        return $out;
    }

    /** 转人工（自动建工单留档） */
    public static function transfer(array $session, string $reason = ''): array
    {
        if (in_array($session['status'], ['waiting', 'serving'], true)) {
            return ['ok' => true, 'msg' => '已为您转接人工，请稍候'];
        }
        if ($session['status'] === 'closed') {
            return ['ok' => false, 'msg' => '会话已结束'];
        }

        $sid   = (int)$session['id'];
        $patch = ['status' => 'waiting', 'updated_at' => now()];

        if ((int)$session['ticket_id'] === 0) {
            $ticketId = YxBusiness::createTicket([
                'customer_id' => (int)$session['customer_id'],
                'department'  => self::transferDept(),
                'subject'     => '小优助手转人工 · ' . $session['token'],
                'priority'    => 'normal',
            ]);
            $lines = [];
            foreach (self::messages($sid) as $m) {
                $who     = ['visitor' => '客户', 'ai' => 'AI', 'agent' => '客服', 'system' => '系统'][$m['role']] ?? $m['role'];
                $lines[] = '[' . $who . '] ' . $m['content'];
            }
            $lines[] = $reason !== '' ? ('—— ' . $reason) : '—— 客户请求转人工';
            YxBusiness::replyTicket($ticketId, 'customer', (int)$session['customer_id'], implode("\n", $lines));
            $patch['ticket_id'] = $ticketId;
            // 报案类诉求：工单建好后补标记「仅人工办理」，后台工单列表可见
            if (YxDispute::inspect($reason, implode("\n", $lines))['hit']) {
                YxDb::exec(
                    'UPDATE ' . YxDb::table('ticket') . ' SET manual_only = 1, manual_note = ?, priority = ?, updated_at = ? WHERE id = ?',
                    [mb_substr($reason !== '' ? $reason : YxDispute::TYPE_TITLE, 0, 255), 'urgent', now(), $ticketId]
                );
            }
        }

        YxDb::update('chat_session', $patch, 'id = :id', ['id' => $sid]);
        self::addMessage($sid, 'system', '已为您转接人工客服，请稍候…客服看到后会尽快接入，您也可以继续留言。');

        return ['ok' => true, 'msg' => '已转人工', 'ticket_id' => (int)$patch['ticket_id'] ?? (int)$session['ticket_id']];
    }

    /* ------------------------------ 商务合作 / 售后咨询留言 ------------------------------ */

    /**
     * 提交留言：落库 + 自动建工单（商务合作 → 售前，售后咨询 → 技术支持）
     */
    public static function leave(array $session, string $category, string $contact, string $content): array
    {
        $category = isset(self::TOPICS[$category]) ? $category : '';
        if ($category === '') {
            return ['ok' => false, 'msg' => '请选择留言类型'];
        }
        $contact = trim($contact);
        if (mb_strlen($contact) < 3 || mb_strlen($contact) > 120) {
            return ['ok' => false, 'msg' => '请填写有效的联系方式（手机 / 微信 / 邮箱，3-120 字）'];
        }
        $content = trim($content);
        if (mb_strlen($content) < 2) {
            return ['ok' => false, 'msg' => '请填写留言内容'];
        }
        if (mb_strlen($content) > self::MAX_LEN) {
            return ['ok' => false, 'msg' => '留言过长（最多 ' . self::MAX_LEN . ' 字）'];
        }
        if ($session['status'] === 'closed') {
            return ['ok' => false, 'msg' => '会话已结束，请刷新页面重新发起'];
        }

        $sid = (int)$session['id'];

        /* 简单频率限制：同一会话 10 分钟内最多 3 条 */
        $n = (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('chat_leave') . ' WHERE session_id = ? AND created_at >= ?',
            [$sid, date('Y-m-d H:i:s', time() - 600)]
        );
        if ($n >= 3) {
            return ['ok' => false, 'msg' => '留言过于频繁，请稍后再试或直接点击「转人工」'];
        }

        $label = self::TOPICS[$category];

        $ticketId = YxBusiness::createTicket([
            'customer_id' => (int)$session['customer_id'],
            'department'  => self::TOPIC_DEPT[$category],
            'subject'     => '【' . $label . '】小优助手留言 · ' . mb_substr($contact, 0, 60),
            'priority'    => $category === 'business' ? 'high' : 'normal',
        ]);
        if ($ticketId <= 0) {
            return ['ok' => false, 'msg' => '留言提交失败，请稍后重试'];
        }
        $sn = (string)YxDb::value('SELECT sn FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$ticketId]);
        $lines = [
            '留言类型：' . $label,
            '联系方式：' . $contact,
            '留言内容：',
            $content,
            '',
            '—— 来源：小优助手 · 会话 #' . $sid
            . ' · IP ' . ($session['visitor_ip'] ?: '-')
            . ' · 页面 ' . ($session['source_url'] ?: '-'),
        ];
        YxBusiness::replyTicket($ticketId, 'customer', (int)$session['customer_id'], implode("\n", $lines));

        $leaveId = YxDb::insert('chat_leave', [
            'session_id' => $sid,
            'category'   => $category,
            'contact'    => mb_substr($contact, 0, 120),
            'content'    => $content,
            'ticket_id'  => $ticketId,
            'status'     => 'new',
            'ip'         => (string)($session['visitor_ip'] ?? ''),
            'created_at' => now(),
        ]);

        self::addMessage($sid, 'visitor', '【' . $label . '】' . $content . "\n联系方式：" . $contact);
        self::addMessage($sid, 'system', '已收到您的' . $label . '留言（工单 ' . $sn . '），客服会尽快通过您留下的联系方式回复。如需即时沟通，可点击「转人工」。');

        return ['ok' => true, 'msg' => '留言已提交', 'leave_id' => $leaveId, 'ticket_id' => $ticketId, 'ticket_sn' => $sn];
    }

    public static function leaves(string $status = '', int $limit = 200): array
    {
        if (!YxDb::hasTable('chat_leave')) {
            return [];
        }
        $sql  = 'SELECT l.* FROM ' . YxDb::table('chat_leave') . ' l';
        $bind = [];
        if ($status !== '') {
            $sql .= ' WHERE l.status = ?';
            $bind[] = $status;
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ' . max(1, $limit);
        $rows = YxDb::all($sql, $bind);
        /* 关联会话客户名 */
        foreach ($rows as &$r) {
            $r['username'] = (string)YxDb::value(
                'SELECT COALESCE(NULLIF(c.username, ""), s.visitor_name, "") FROM ' . YxDb::table('chat_session') . ' s'
                . ' LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = s.customer_id WHERE s.id = ?',
                [(int)$r['session_id']]
            );
        }
        unset($r);
        return $rows;
    }

    public static function markLeaveHandled(int $leaveId): array
    {
        if ($leaveId <= 0) {
            return ['ok' => false, 'msg' => '留言不存在'];
        }
        YxDb::update('chat_leave', ['status' => 'handled'], 'id = :id', ['id' => $leaveId]);
        return ['ok' => true, 'msg' => '已标记为处理完成'];
    }

    public static function close(int $sessionId, string $by = 'agent'): array
    {
        YxDb::update('chat_session', ['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $sessionId]);
        self::addMessage($sessionId, 'system', $by === 'visitor' ? '客户已结束会话' : '客服已结束会话');
        return ['ok' => true, 'msg' => '会话已结束'];
    }

    public static function markVisitorRead(int $sessionId): void
    {
        YxDb::exec('UPDATE ' . YxDb::table('chat_session') . ' SET unread_visitor = 0 WHERE id = ?', [$sessionId]);
    }

    public static function markAdminRead(int $sessionId): void
    {
        YxDb::exec('UPDATE ' . YxDb::table('chat_session') . ' SET unread_admin = 0 WHERE id = ?', [$sessionId]);
    }

    /* ------------------------------ 客服侧 ------------------------------ */

    public static function claim(int $sessionId, int $adminId): array
    {
        $s = self::byId($sessionId);
        if (!$s) {
            return ['ok' => false, 'msg' => '会话不存在'];
        }
        if ($s['status'] === 'closed') {
            return ['ok' => false, 'msg' => '会话已结束'];
        }
        YxDb::update('chat_session', ['status' => 'serving', 'agent_id' => $adminId, 'unread_admin' => 0, 'updated_at' => now()], 'id = :id', ['id' => $sessionId]);
        return ['ok' => true, 'msg' => '已接单'];
    }

    public static function agentReply(int $sessionId, int $adminId, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'msg' => '回复内容不能为空'];
        }
        $s = self::byId($sessionId);
        if (!$s) {
            return ['ok' => false, 'msg' => '会话不存在'];
        }
        if ($s['status'] === 'closed') {
            return ['ok' => false, 'msg' => '会话已结束'];
        }
        YxDb::update('chat_session', ['status' => 'serving', 'agent_id' => $adminId, 'unread_admin' => 0, 'updated_at' => now()], 'id = :id', ['id' => $sessionId]);
        self::addMessage($sessionId, 'agent', $content);
        return ['ok' => true, 'msg' => '已回复'];
    }

    public static function adminSessions(string $status = ''): array
    {
        $sql = 'SELECT s.*, c.username FROM ' . YxDb::table('chat_session') . ' s LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = s.customer_id';
        $bind = [];
        if ($status !== '' && isset(self::STATUS[$status])) {
            $sql .= ' WHERE s.status = ?';
            $bind[] = $status;
        }
        $sql .= " ORDER BY CASE s.status WHEN 'waiting' THEN 0 WHEN 'serving' THEN 1 WHEN 'ai' THEN 2 ELSE 3 END, s.updated_at DESC LIMIT 200";
        return YxDb::all($sql, $bind);
    }

    public static function stats(): array
    {
        return [
            'waiting'   => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('chat_session') . " WHERE status = 'waiting'"),
            'serving'   => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('chat_session') . " WHERE status = 'serving'"),
            'today'     => (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('chat_session') . ' WHERE created_at >= ?', [date('Y-m-d') . ' 00:00:00']),
            'unread'    => (int)YxDb::value('SELECT COALESCE(SUM(unread_admin),0) FROM ' . YxDb::table('chat_session')),
            'leave_new' => YxDb::hasTable('chat_leave')
                ? (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('chat_leave') . " WHERE status = 'new'")
                : 0,
        ];
    }
}
