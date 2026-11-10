<?php
/**
 * 优享财务系统 · 代理中心业务逻辑
 *
 * 客户在用户中心提交「代理申请」，管理员在后台审核。审核通过后由管理员在
 * 「渠道/代理」中开通代理账号（agent 表），本模块只负责申请单的提交与状态查询，
 * 不直接创建 agent 账号——避免绕过密码设置与权限审查。
 *
 * 前置条件：申请前必须完成实名认证（customer.realname_status = verified）。
 */

class YxAgentApply
{
    const STATUS = [
        'pending'  => '待审核',
        'approved' => '已通过',
        'rejected' => '已驳回',
    ];

    const APPLY_TYPES = [
        'personal' => '个人代理',
        'company'  => '企业代理',
    ];

    public static function statusName(string $s): string
    {
        return self::STATUS[$s] ?? $s;
    }

    public static function statusClass(string $s): string
    {
        return ['pending' => 'badge-warn', 'approved' => 'badge-ok', 'rejected' => 'badge-bad'][$s] ?? 'badge-mute';
    }

    public static function typeName(string $t): string
    {
        return self::APPLY_TYPES[$t] ?? $t;
    }

    /** 当前客户最近一条申请（无则 null） */
    public static function latest(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        $r = YxDb::one(
            'SELECT * FROM ' . YxDb::table('agent_apply') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 1',
            [$customerId]
        );
        return $r ?: null;
    }

    /** 当前客户的全部申请记录 */
    public static function rows(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('agent_apply') . ' WHERE customer_id = ? ORDER BY id DESC',
            [$customerId]
        );
    }

    /** 是否已有进行中（待审核）的申请，用于阻止重复提交 */
    public static function hasPending(int $customerId): bool
    {
        $r = self::latest($customerId);
        return $r !== null && (string)$r['status'] === 'pending';
    }

    /** 是否已被批准（批准后不再允许重复申请） */
    public static function isApproved(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        return (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('agent_apply') . " WHERE customer_id = ? AND status = 'approved'",
            [$customerId]
        ) > 0;
    }

    /** 该客户已关联的代理账号（agent.customer_id） */
    public static function agentOf(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        $r = YxDb::one(
            'SELECT * FROM ' . YxDb::table('agent') . " WHERE customer_id = ? AND status = 1 ORDER BY id DESC LIMIT 1",
            [$customerId]
        );
        return $r ?: null;
    }

    /**
     * 提交代理申请
     *
     * @return array{ok:bool,msg:string,id?:int}
     */
    public static function submit(int $customerId, array $in): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '请先登录'];
        }

        // 前置条件：实名认证
        $cust = YxDb::one('SELECT id, realname_status, company FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);
        if (!$cust) {
            return ['ok' => false, 'msg' => '账号不存在'];
        }
        if (!yx_customer_verified($cust)) {
            return ['ok' => false, 'msg' => '当前账号尚未完成实名认证，请先在用户中心完成认证'];
        }

        if (self::isApproved($customerId)) {
            return ['ok' => false, 'msg' => '你的代理申请已通过，无需重复提交'];
        }
        if (self::hasPending($customerId)) {
            return ['ok' => false, 'msg' => '你已有一条待审核的申请，请耐心等待审核结果'];
        }

        $type  = ($in['apply_type'] ?? '') === 'company' ? 'company' : 'personal';
        $name  = trim((string)($in['subject_name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'msg' => $type === 'company' ? '请填写企业名称' : '请填写申请人姓名'];
        }

        $phone = trim((string)($in['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return ['ok' => false, 'msg' => '手机号格式不正确'];
        }

        $email = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '邮箱格式不正确'];
        }

        $license = trim((string)($in['license_no'] ?? ''));
        if ($type === 'company' && $license === '') {
            return ['ok' => false, 'msg' => '企业代理需填写统一社会信用代码'];
        }

        $domain = normalize_domain((string)($in['domain'] ?? ''));

        $id = YxDb::insert('agent_apply', [
            'customer_id'   => $customerId,
            'apply_type'    => $type,
            'subject_name'  => mb_substr($name, 0, 120),
            'contact_name'  => mb_substr(trim((string)($in['contact_name'] ?? '')), 0, 60) ?: null,
            'phone'         => $phone !== '' ? $phone : null,
            'email'         => $email !== '' ? $email : null,
            'company'       => mb_substr(trim((string)($in['company'] ?? '')), 0, 160) ?: null,
            'license_no'    => $license !== '' ? $license : null,
            'domain'        => $domain !== '' ? $domain : null,
            'channel'       => mb_substr(trim((string)($in['channel'] ?? '')), 0, 120) ?: null,
            'expect_sales'  => round_money($in['expect_sales'] ?? 0),
            'remark'        => trim((string)($in['remark'] ?? '')) ?: null,
            'status'        => 'pending',
            'realname_at'   => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        log_action('agent.apply', 'agent_apply#' . $id, ['type' => $type, 'name' => $name]);

        return ['ok' => true, 'msg' => '代理申请已提交，请等待管理员审核', 'id' => $id];
    }

    /** 后台：申请列表 */
    public static function adminRows(string $status = '', int $limit = 200): array
    {
        $w = ' WHERE 1=1';
        $b = [];
        if ($status !== '' && isset(self::STATUS[$status])) {
            $w .= ' AND a.status = ?';
            $b[] = $status;
        }
        return YxDb::all(
            'SELECT a.*, c.username, c.realname_status AS cust_realname_status
               FROM ' . YxDb::table('agent_apply') . ' a
               LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = a.customer_id'
            . $w . ' ORDER BY a.id DESC LIMIT ' . max(1, $limit),
            $b
        );
    }

    /** 后台：审核 */
    public static function review(int $applyId, bool $approve, string $note, int $reviewerId): array
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('agent_apply') . ' WHERE id = ?', [$applyId]);
        if (!$row) {
            return ['ok' => false, 'msg' => '申请不存在'];
        }
        if ((string)$row['status'] !== 'pending') {
            return ['ok' => false, 'msg' => '该申请已审核过：' . self::statusName((string)$row['status'])];
        }

        YxDb::update('agent_apply', [
            'status'      => $approve ? 'approved' : 'rejected',
            'admin_remark' => $note !== '' ? mb_substr($note, 0, 255) : null,
            'reviewed_at' => now(),
            'reviewer_id' => $reviewerId,
            'updated_at'  => now(),
        ], 'id = :id', ['id' => $applyId]);

        log_action('agent.review', 'agent_apply#' . $applyId, ['approve' => $approve, 'note' => $note]);

        return ['ok' => true, 'msg' => $approve ? '已通过。请到「渠道/代理」中为其开通代理账号。' : '已驳回'];
    }
}
