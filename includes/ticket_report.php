<?php
/**
 * 优享财务系统 · 工单举报
 *
 * 与「工单」互补：工单是客户向平台提出的服务请求，举报单用于投诉违规/欺诈/滥用，
 * 可由客户、分站或游客提交，后台统一受理并回复，处理状态对提交方可见。
 */

class YxTicketReport
{
    const CATEGORIES = [
        'abuse'   => '滥用 / 违规使用',
        'fraud'   => '欺诈 / 诈骗',
        'content' => '违规内容',
        'service' => '服务投诉',
        'other'   => '其他',
    ];

    const PRIORITIES = [
        'low'    => '低',
        'normal' => '中',
        'high'   => '高',
        'urgent' => '紧急',
    ];

    const STATUSES = [
        'pending'    => '待处理',
        'accepted'   => '已受理',
        'processing' => '处理中',
        'rejected'   => '已驳回',
        'closed'     => '已关闭',
    ];

    /** 未结束状态（列表默认过滤） */
    const OPEN_STATUSES = ['pending', 'accepted', 'processing'];

    public static function categoryName(string $c): string
    {
        return self::CATEGORIES[$c] ?? $c;
    }

    public static function priorityName(string $p): string
    {
        return self::PRIORITIES[$p] ?? $p;
    }

    public static function statusName(string $s): string
    {
        return self::STATUSES[$s] ?? $s;
    }

    public static function statusBadge(string $s): string
    {
        switch ($s) {
            case 'pending':    return 'badge-warn';
            case 'accepted':   return 'badge-info';
            case 'processing': return 'badge-info';
            case 'rejected':   return 'badge-bad';
            case 'closed':     return 'badge-mute';
            default:           return 'badge-mute';
        }
    }

    public static function priorityBadge(string $p): string
    {
        switch ($p) {
            case 'urgent': return 'badge-bad';
            case 'high':   return 'badge-warn';
            case 'low':    return 'badge-mute';
            default:       return 'badge-info';
        }
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }

    /* ------------------------------ 查询 ------------------------------ */

    public static function bySn(string $sn): ?array
    {
        $sn = trim($sn);
        if ($sn === '') {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('ticket_report') . ' WHERE sn = ? LIMIT 1', [$sn]);
        return $r ?: null;
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('ticket_report') . ' WHERE id = ? LIMIT 1', [$id]);
        return $r ?: null;
    }

    /** 举报单 + 回复记录 */
    public static function detail(int $id): ?array
    {
        $report = self::find($id);
        if (!$report) {
            return null;
        }
        $report['replies'] = self::replies($id);
        return $report;
    }

    public static function replies(int $reportId): array
    {
        if ($reportId <= 0) {
            return [];
        }
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('ticket_report_reply') . ' WHERE report_id = ? ORDER BY id ASC',
            [$reportId]
        );
    }

    /** 我提交的举报（按客户或分站） */
    public static function myReports(int $customerId = 0, int $subsiteId = 0, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        if ($customerId > 0) {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('ticket_report') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT ' . $limit,
                [$customerId]
            );
        }
        if ($subsiteId > 0) {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('ticket_report') . ' WHERE subsite_id = ? ORDER BY id DESC LIMIT ' . $limit,
                [$subsiteId]
            );
        }
        return [];
    }

    /** 待处理统计：['pending'=>n,'open'=>n,'today'=>n,'total'=>n] */
    public static function stats(): array
    {
        $t = YxDb::table('ticket_report');
        return [
            'pending' => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . " WHERE status = 'pending'"),
            'open'    => (int)YxDb::value(
                'SELECT COUNT(*) FROM ' . $t . " WHERE status IN ('pending','accepted','processing')"
            ),
            'today'   => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . ' WHERE created_at >= ?', [date('Y-m-d') . ' 00:00:00']),
            'total'   => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t),
        ];
    }

    /* ------------------------------ 写入 ------------------------------ */

    /**
     * 提交举报
     * @param array $data customer_id,subsite_id,ticket_id,category,subject,content,contact,evidence,priority
     * @return array ['ok','msg','id','sn']
     */
    public static function create(array $data): array
    {
        $subject  = trim((string)($data['subject'] ?? ''));
        $content  = trim((string)($data['content'] ?? ''));
        $category = (string)($data['category'] ?? 'abuse');
        $priority = (string)($data['priority'] ?? 'normal');
        $contact  = trim((string)($data['contact'] ?? ''));

        if (mb_strlen($subject) < 2) {
            return ['ok' => false, 'msg' => '请填写举报标题（至少 2 个字）'];
        }
        if (mb_strlen($subject) > 200) {
            return ['ok' => false, 'msg' => '举报标题不能超过 200 个字'];
        }
        if ($content === '') {
            return ['ok' => false, 'msg' => '请填写举报说明'];
        }
        if (mb_strlen($content) > 5000) {
            return ['ok' => false, 'msg' => '举报说明不能超过 5000 个字'];
        }
        if (!isset(self::CATEGORIES[$category])) {
            $category = 'other';
        }
        if (!isset(self::PRIORITIES[$priority])) {
            $priority = 'normal';
        }

        $ticketId = (int)($data['ticket_id'] ?? 0);
        if ($ticketId > 0 && !YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$ticketId])) {
            $ticketId = 0;
        }

        // 报案 / 涉警类诉求：仍允许提交，但标记为「仅人工办理」并提升优先级，避免自助流程给出结论
        $dispute = YxDispute::inspect($subject, $content);
        if ($dispute['hit']) {
            $priority = 'urgent';
        }

        $sn = make_sn('RP', 'ticket_report');
        $id = YxDb::insert('ticket_report', [
            'sn'          => $sn,
            'customer_id' => (int)($data['customer_id'] ?? 0),
            'subsite_id'  => (int)($data['subsite_id'] ?? 0),
            'ticket_id'   => $ticketId,
            'category'    => $category,
            'subject'     => $subject,
            'content'     => $content,
            'contact'     => $contact !== '' ? $contact : null,
            'evidence'    => $data['evidence'] ?? null,
            'priority'    => $priority,
            'status'      => 'pending',
            'admin_id'    => 0,
            'handle_note' => null,
            'manual_only' => $dispute['hit'] ? 1 : 0,
            'manual_note' => $dispute['hit'] ? mb_substr('命中：' . implode('、', $dispute['keywords']), 0, 255) : null,
            'reply_count' => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
            'handled_at'  => null,
        ]);

        log_action('ticket_report.create', $sn, [
            'category'    => $category,
            'priority'    => $priority,
            'manual_only' => $dispute['hit'] ? 1 : 0,
        ]);

        return [
            'ok'          => true,
            'msg'         => '举报已提交，编号 ' . $sn,
            'id'          => $id,
            'sn'          => $sn,
            'manual_only' => $dispute['hit'],
        ];
    }

    /** 追加回复（customer|subsite|admin） */
    public static function addReply(int $reportId, string $authorType, int $authorId, string $content): array
    {
        $report = self::find($reportId);
        if (!$report) {
            return ['ok' => false, 'msg' => '举报单不存在'];
        }
        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'msg' => '回复内容不能为空'];
        }
        if (mb_strlen($content) > 3000) {
            return ['ok' => false, 'msg' => '回复内容不能超过 3000 个字'];
        }
        if (!in_array($authorType, ['customer', 'subsite', 'admin'], true)) {
            $authorType = 'customer';
        }
        if ($authorType !== 'admin' && !self::isOpen((string)$report['status'])) {
            return ['ok' => false, 'msg' => '举报单已结束，无法继续回复'];
        }

        YxDb::insert('ticket_report_reply', [
            'report_id'   => $reportId,
            'author_type' => $authorType,
            'author_id'   => $authorId,
            'content'     => $content,
            'created_at'  => now(),
        ]);

        $data = [
            'reply_count' => (int)$report['reply_count'] + 1,
            'updated_at'  => now(),
        ];
        // 提交方后续补充的内容若转为报案类诉求，同步标记为「仅人工办理」
        if ($authorType !== 'admin' && empty($report['manual_only'])) {
            $dispute = YxDispute::inspect($content);
            if ($dispute['hit']) {
                $data['manual_only'] = 1;
                $data['manual_note'] = mb_substr('命中：' . implode('、', $dispute['keywords']), 0, 255);
                $data['priority']    = 'urgent';
            }
        }
        // 后台回复后自动进入「已受理」，避免遗漏
        if ($authorType === 'admin' && (string)$report['status'] === 'pending') {
            $data['status'] = 'accepted';
        }
        YxDb::update('ticket_report', $data, 'id = :id', ['id' => $reportId]);

        return ['ok' => true, 'msg' => '回复成功'];
    }

    /** 变更状态（后台） */
    public static function setStatus(int $reportId, string $status, int $adminId = 0, string $note = ''): array
    {
        $report = self::find($reportId);
        if (!$report) {
            return ['ok' => false, 'msg' => '举报单不存在'];
        }
        if (!isset(self::STATUSES[$status])) {
            return ['ok' => false, 'msg' => '未知状态'];
        }
        $data = [
            'status'     => $status,
            'updated_at' => now(),
        ];
        if ($adminId > 0) {
            $data['admin_id'] = $adminId;
        }
        $note = trim($note);
        if ($note !== '') {
            $data['handle_note'] = mb_substr($note, 0, 255);
        }
        if (!self::isOpen($status)) {
            $data['handled_at'] = now();
        }

        YxDb::update('ticket_report', $data, 'id = :id', ['id' => $reportId]);
        log_action('ticket_report.status', (string)$report['sn'], ['status' => $status]);

        return ['ok' => true, 'msg' => '状态已更新为「' . self::statusName($status) . '」'];
    }

    /** 后台筛选列表 */
    public static function search(array $filter, int $limit = 20, int $offset = 0): array
    {
        [$where, $bind] = self::buildWhere($filter);
        $t = YxDb::table('ticket_report');
        return YxDb::all(
            'SELECT * FROM ' . $t . $where . ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bind
        );
    }

    public static function count(array $filter): int
    {
        [$where, $bind] = self::buildWhere($filter);
        return (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ticket_report') . $where, $bind);
    }

    private static function buildWhere(array $filter): array
    {
        $where = ' WHERE 1=1';
        $bind  = [];

        $status = (string)($filter['status'] ?? '');
        if ($status !== '' && isset(self::STATUSES[$status])) {
            $where .= ' AND status = ?';
            $bind[] = $status;
        } elseif ($status === 'open') {
            $where .= " AND status IN ('pending','accepted','processing')";
        }

        $category = (string)($filter['category'] ?? '');
        if ($category !== '' && isset(self::CATEGORIES[$category])) {
            $where .= ' AND category = ?';
            $bind[] = $category;
        }

        $priority = (string)($filter['priority'] ?? '');
        if ($priority !== '' && isset(self::PRIORITIES[$priority])) {
            $where .= ' AND priority = ?';
            $bind[] = $priority;
        }

        $kw = trim((string)($filter['kw'] ?? ''));
        if ($kw !== '') {
            $where .= ' AND (sn LIKE ? OR subject LIKE ? OR contact LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($bind, $like, $like, $like);
        }

        // 仅人工办理（报案 / 涉警类诉求）筛选
        $manual = (string)($filter['manual'] ?? '');
        if ($manual === '1') {
            $where .= ' AND manual_only = 1';
        } elseif ($manual === '0') {
            $where .= ' AND (manual_only = 0 OR manual_only IS NULL)';
        }

        return [$where, $bind];
    }
}
