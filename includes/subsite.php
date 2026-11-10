<?php
/**
 * 优享财务系统 · 分站（多级分销）
 *
 * 分站按「分站套餐」开通，可继续发展下级分站（层级不限，parent_id + 物化 path）。
 * 下级分站开通、以及分站名下客户下单时，沿上级链按各分站佣金比例逐级结算佣金。
 */

class YxSubsite
{
    const STATUS_PENDING = 0;   // 待激活（已下单未支付）或已停用
    const STATUS_ACTIVE  = 1;

    public static function statusName(int $status): string
    {
        return $status === self::STATUS_ACTIVE ? '正常' : '待激活 / 停用';
    }

    /** 分站套餐 */
    public static function plans(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM ' . YxDb::table('subsite_plan');
        if ($onlyActive) {
            $sql .= ' WHERE status = 1';
        }
        return YxDb::all($sql . ' ORDER BY sort ASC, id ASC');
    }

    public static function plan(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('subsite_plan') . ' WHERE id = ?', [$id]);
        return $r ?: null;
    }

    public static function byId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('subsite') . ' WHERE id = ?', [$id]);
        return $r ?: null;
    }

    public static function byUsername(string $username): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('subsite') . ' WHERE username = ? LIMIT 1', [trim($username)]);
        return $r ?: null;
    }

    public static function byCustomer(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('subsite') . ' WHERE customer_id = ? ORDER BY id ASC LIMIT 1', [$customerId]);
        return $r ?: null;
    }

    /** 上级链（由近及远，不含自身） */
    public static function ancestors(array $subsite, bool $includeSelf = false): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($subsite['path'] ?? '')))));
        if (!$includeSelf) {
            $ids = array_values(array_diff($ids, [(int)$subsite['id']]));
        }
        if (!$ids) {
            return [];
        }
        $ids = array_reverse($ids); // 最近的在最前
        $rows = YxDb::all(
            'SELECT * FROM ' . YxDb::table('subsite') . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = $r;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $out[] = $map[$id];
            }
        }
        return $out;
    }

    public static function children(int $id): array
    {
        return YxDb::all(
            'SELECT s.*, p.name AS plan_name FROM ' . YxDb::table('subsite') . ' s
             LEFT JOIN ' . YxDb::table('subsite_plan') . ' p ON p.id = s.plan_id
             WHERE s.parent_id = ? ORDER BY s.id DESC',
            [$id]
        );
    }

    public static function descendants(int $id): array
    {
        return YxDb::all(
            'SELECT s.* FROM ' . YxDb::table('subsite') . ' s
             WHERE s.path LIKE ? AND s.id <> ? ORDER BY s.level ASC, s.id ASC',
            ['%,' . $id . ',%', $id]
        );
    }

    public static function childCount(int $id): int
    {
        return (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('subsite') . ' WHERE parent_id = ?', [$id]);
    }

    /** 是否可继续发展下级（套餐 max_children，0 表示不限） */
    public static function canCreateChild(array $subsite): array
    {
        if ((int)$subsite['status'] !== self::STATUS_ACTIVE) {
            return ['ok' => false, 'msg' => '分站未激活或已停用，无法发展下级'];
        }
        if (self::isExpired($subsite)) {
            return ['ok' => false, 'msg' => '分站已到期，请先续费'];
        }
        $max = (int)($subsite['max_children'] ?? 0);
        if ($max > 0 && self::childCount((int)$subsite['id']) >= $max) {
            return ['ok' => false, 'msg' => '当前套餐最多发展 ' . $max . ' 个下级分站，请升级套餐'];
        }
        return ['ok' => true, 'msg' => ''];
    }

    public static function isExpired(array $subsite): bool
    {
        $expire = (string)($subsite['expire_at'] ?? '');
        return $expire !== '' && $expire < date('Y-m-d');
    }

    /** 分站是否可用（激活 + 未到期） */
    public static function isUsable(array $subsite): bool
    {
        return (int)$subsite['status'] === self::STATUS_ACTIVE && !self::isExpired($subsite);
    }

    /** 确保分站拥有可用于下单/付款的客户账号，返回 customer_id */
    public static function ensureCustomer(array $subsite): int
    {
        $cid = (int)($subsite['customer_id'] ?? 0);
        if ($cid > 0 && YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid])) {
            return $cid;
        }
        $base = (string)$subsite['username'];
        $uname = $base;
        $i = 0;
        while (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE username = ?', [$uname])) {
            $i++;
            $uname = $base . '-ss' . $i;
            if ($i > 100) {
                break;
            }
        }
        $cid = customer_insert([
            'username'   => $uname,
            'email'      => '',
            'phone'      => null,
            'password'   => (string)$subsite['password'], // 复用分站密码哈希
            'company'    => (string)$subsite['name'],
            'balance'    => 0,
            'credit'     => 0,
            'points'     => 0,
            'level'      => 'subsite',
            'agent_id'   => (int)($subsite['agent_id'] ?? 0),
            'subsite_id' => (int)$subsite['id'],
            'status'     => 1,
            'reg_ip'     => client_ip(),
            'created_at' => now(),
        ]);
        YxDb::update('subsite', ['customer_id' => $cid], 'id = :id', ['id' => (int)$subsite['id']]);
        return $cid;
    }

    /**
     * 创建下级分站（或由管理员创建顶级分站）
     * @param array $data parent_id, plan_id, name, username, password, domain, customer_id(管理员可选)
     * @return array ['ok','msg','subsite_id','order_id','free']
     */
    public static function createChild(array $data): array
    {
        $parentId = (int)($data['parent_id'] ?? 0);
        $planId   = (int)($data['plan_id'] ?? 0);
        $name     = trim((string)($data['name'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $domain   = normalize_domain((string)($data['domain'] ?? ''));

        if ($name === '' || $username === '') {
            return ['ok' => false, 'msg' => '请填写分站名称与登录账号'];
        }
        if (!preg_match('/^[A-Za-z0-9_@.\-]{3,50}$/', $username)) {
            return ['ok' => false, 'msg' => '登录账号需为 3-50 位字母、数字或 _ @ . -'];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'msg' => '登录密码至少 6 位'];
        }
        $plan = self::plan($planId);
        if (!$plan || (int)$plan['status'] !== 1) {
            return ['ok' => false, 'msg' => '分站套餐不存在或已下架'];
        }

        $parent = null;
        if ($parentId > 0) {
            $parent = self::byId($parentId);
            if (!$parent) {
                return ['ok' => false, 'msg' => '上级分站不存在'];
            }
            $chk = self::canCreateChild($parent);
            if (!$chk['ok']) {
                return $chk;
            }
        }

        if (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('subsite') . ' WHERE username = ?', [$username])) {
            return ['ok' => false, 'msg' => '该分站账号已被占用'];
        }

        $path  = (string)($parent['path'] ?? ',');
        $level = $parent ? (int)$parent['level'] + 1 : 1;

        $id = YxDb::insert('subsite', [
            'parent_id'       => $parentId,
            'path'            => ',',
            'level'           => $level,
            'name'            => $name,
            'username'        => $username,
            'password'        => YxAuth::hash($password),
            'domain'          => $domain !== '' ? $domain : null,
            'plan_id'         => (int)$plan['id'],
            'customer_id'     => 0,
            'agent_id'        => $parent ? (int)$parent['agent_id'] : (int)($data['agent_id'] ?? 0),
            'commission_rate' => round_money($plan['commission_rate']),
            'balance'         => 0,
            'max_children'    => (int)$plan['max_children'],
            'expire_at'       => null,
            'status'          => self::STATUS_PENDING,
            'remark'          => $data['remark'] ?? null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        YxDb::update('subsite', ['path' => $path . $id . ','], 'id = :id', ['id' => $id]);

        $price = round_money($plan['price']);
        if ($price <= 0) {
            self::activate($id);
            return ['ok' => true, 'msg' => '分站已创建并开通', 'subsite_id' => $id, 'order_id' => 0, 'free' => true];
        }

        $customerId = $parent
            ? self::ensureCustomer($parent)
            : (int)($data['customer_id'] ?? 0);
        if ($customerId <= 0) {
            YxDb::delete('subsite', 'id = :id', ['id' => $id]);
            return ['ok' => false, 'msg' => '缺少付款账号，无法生成分站开通订单'];
        }

        $created = YxBusiness::createOrder([
            'customer_id' => $customerId,
            'type'        => 'subsite',
            'subsite_id'  => $parentId,
            'agent_id'    => $parent ? (int)$parent['agent_id'] : (int)($data['agent_id'] ?? 0),
            'name'        => '分站开通：' . $name . '（' . $plan['name'] . '）',
            'amount'      => $price,
            'note'        => json_encode([
                'plan_id'   => (int)$plan['id'],
                'child_id'  => $id,
                'parent_id' => $parentId,
                'domain'    => $domain,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        if (empty($created['ok'])) {
            YxDb::delete('subsite', 'id = :id', ['id' => $id]);
            return ['ok' => false, 'msg' => $created['msg'] ?? '订单创建失败'];
        }

        return [
            'ok'         => true,
            'msg'        => '分站已创建，请支付开通费用',
            'subsite_id' => $id,
            'order_id'   => (int)$created['order_id'],
            'total'      => (float)$created['total'],
            'free'       => false,
        ];
    }

    /** 开通 / 续费：按套餐时长延长到期日 */
    public static function activate(int $id, int $days = null): array
    {
        $subsite = self::byId($id);
        if (!$subsite) {
            return ['ok' => false, 'msg' => '分站不存在'];
        }
        $plan = self::plan((int)$subsite['plan_id']);
        $days = $days === null ? (int)($plan['duration_days'] ?? 365) : $days;
        $days = max(1, $days);

        $today  = date('Y-m-d');
        $base   = (!empty($subsite['expire_at']) && $subsite['expire_at'] > $today) ? $subsite['expire_at'] : $today;
        $expire = date('Y-m-d', strtotime($base . ' +' . $days . ' day'));

        YxDb::update('subsite', [
            'status'     => self::STATUS_ACTIVE,
            'expire_at'  => $expire,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);

        return ['ok' => true, 'msg' => '分站已开通，有效期至 ' . $expire, 'expire_at' => $expire];
    }

    /**
     * 续费 / 升级套餐：生成待支付订单（0 元直接生效）
     * @return array ['ok','msg','order_id','total']
     */
    public static function renew(int $subsiteId, int $planId = 0, int $payerCustomerId = 0): array
    {
        $subsite = self::byId($subsiteId);
        if (!$subsite) {
            return ['ok' => false, 'msg' => '分站不存在'];
        }
        $planId = $planId > 0 ? $planId : (int)$subsite['plan_id'];
        $plan   = self::plan($planId);
        if (!$plan || (int)$plan['status'] !== 1) {
            return ['ok' => false, 'msg' => '分站套餐不存在或已下架'];
        }

        $price = round_money($plan['price']);
        if ($price <= 0) {
            YxDb::update('subsite', [
                'plan_id'         => (int)$plan['id'],
                'commission_rate' => round_money($plan['commission_rate']),
                'max_children'    => (int)$plan['max_children'],
            ], 'id = :id', ['id' => $subsiteId]);
            $res = self::activate($subsiteId, (int)$plan['duration_days']);
            return ['ok' => true, 'msg' => $res['msg'], 'order_id' => 0, 'total' => 0.0];
        }

        $customerId = $payerCustomerId > 0 ? $payerCustomerId : (int)($subsite['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '缺少付款账号，无法生成续费订单'];
        }

        $created = YxBusiness::createOrder([
            'customer_id' => $customerId,
            'type'        => 'subsite',
            'subsite_id'  => (int)$subsite['parent_id'],
            'agent_id'    => (int)$subsite['agent_id'],
            'name'        => '分站续费：' . $subsite['name'] . '（' . $plan['name'] . '）',
            'amount'      => $price,
            'note'        => json_encode([
                'plan_id'   => (int)$plan['id'],
                'child_id'  => (int)$subsite['id'],
                'parent_id' => (int)$subsite['parent_id'],
                'renew'     => 1,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        if (empty($created['ok'])) {
            return ['ok' => false, 'msg' => $created['msg'] ?? '订单创建失败'];
        }

        return ['ok' => true, 'msg' => '续费订单已创建，请支付', 'order_id' => (int)$created['order_id'], 'total' => (float)$created['total']];
    }

    /** 修改登录密码 */
    public static function setPassword(int $id, string $password): array
    {
        if (strlen($password) < 6) {
            return ['ok' => false, 'msg' => '登录密码至少 6 位'];
        }
        YxDb::update('subsite', [
            'password'   => YxAuth::hash($password),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
        return ['ok' => true, 'msg' => '密码已更新'];
    }

    /** 修改分站资料（名称 / 域名 / 备注） */
    public static function updateProfile(int $id, array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'msg' => '分站名称不能为空'];
        }
        $domain = normalize_domain((string)($data['domain'] ?? ''));
        YxDb::update('subsite', [
            'name'       => $name,
            'domain'     => $domain !== '' ? $domain : null,
            'remark'     => $data['remark'] ?? null,
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
        return ['ok' => true, 'msg' => '资料已保存'];
    }

    /**
     * 订单支付后按分站链结算佣金
     * @param bool $includeSelf 是否包含订单归属分站自身（客户消费订单为 true，分站开通订单为 false）
     *                          包含自身时 level=0 表示本级，1 起为上级层级
     */
    public static function settleCommission(array $order, int $fromSubsiteId, bool $includeSelf = false): void
    {
        $fromSubsiteId = (int)$fromSubsiteId;
        if ($fromSubsiteId <= 0) {
            return;
        }
        $from = self::byId($fromSubsiteId);
        if (!$from) {
            return;
        }
        // 分站用自身账号下单时不返佣给自身（避免自我返利套利），上级链照常结算
        if ($includeSelf && (int)($from['customer_id'] ?? 0) > 0
            && (int)($order['customer_id'] ?? 0) === (int)$from['customer_id']) {
            $includeSelf = false;
        }
        $total = round_money($order['total'] ?? 0);
        if ($total <= 0) {
            return;
        }

        $orderId = (int)($order['id'] ?? 0);
        $remaining = $total;
        $level = $includeSelf ? -1 : 0;
        foreach (self::ancestors($from, $includeSelf) as $anc) {
            $level++;
            if ((int)$anc['status'] !== self::STATUS_ACTIVE || self::isExpired($anc)) {
                continue;
            }
            $rate = (float)$anc['commission_rate'];
            if ($rate <= 0 || $remaining <= 0) {
                continue;
            }
            $amount = round_money(min($remaining, $total * $rate / 100));
            if ($amount <= 0) {
                continue;
            }
            // 幂等：同一订单同一受益分站只结算一次
            if ($orderId > 0 && YxDb::value(
                'SELECT COUNT(*) FROM ' . YxDb::table('subsite_commission') . ' WHERE order_id = ? AND beneficiary_id = ?',
                [$orderId, (int)$anc['id']]
            )) {
                continue;
            }
            self::creditCommission($anc, $order, $amount, $rate, $level, $fromSubsiteId, $total);
            $remaining = round_money($remaining - $amount);
        }
    }

    private static function creditCommission(array $beneficiary, array $order, float $amount, float $rate, int $level, int $fromId, float $base): void
    {
        YxDb::exec(
            'UPDATE ' . YxDb::table('subsite') . ' SET balance = balance + ?, updated_at = ? WHERE id = ?',
            [$amount, now(), (int)$beneficiary['id']]
        );
        $balance = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('subsite') . ' WHERE id = ?', [(int)$beneficiary['id']]);

        YxDb::insert('subsite_commission', [
            'order_id'       => (int)($order['id'] ?? 0),
            'order_sn'       => (string)($order['sn'] ?? ''),
            'beneficiary_id' => (int)$beneficiary['id'],
            'from_id'        => $fromId,
            'level'          => $level,
            'rate'           => $rate,
            'base_amount'    => round_money($base),
            'amount'         => $amount,
            'status'         => 'settled',
            'remark'         => '订单 ' . ($order['sn'] ?? '') . ' 分站佣金（' . ($level > 0 ? '第 ' . $level . ' 级上级' : '本级客户订单') . '）',
            'created_at'     => now(),
        ]);

        YxDb::insert('transaction', [
            'sn'            => make_sn('TXS', 'transaction'),
            'customer_id'   => 0,
            'direction'     => 'expense',
            'category'      => 'subsite_commission',
            'amount'        => $amount,
            'balance_after' => $balance,
            'method'        => 'internal',
            'ref_type'      => 'subsite',
            'ref_id'        => (int)$beneficiary['id'],
            'operator'      => 'system',
            'remark'        => '分站佣金（订单 ' . ($order['sn'] ?? '') . '）',
            'created_at'    => now(),
        ]);
    }

    public static function myOrders(int $subsiteId): array
    {
        if ($subsiteId <= 0) {
            return [];
        }
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('order') . ' WHERE subsite_id = ? ORDER BY id DESC LIMIT 200',
            [$subsiteId]
        );
    }

    public static function myCustomers(int $subsiteId): array
    {
        if ($subsiteId <= 0) {
            return [];
        }
        return YxDb::all(
            'SELECT id, username, email, phone, balance, level, status, created_at
             FROM ' . YxDb::table('customer') . ' WHERE subsite_id = ? ORDER BY id DESC LIMIT 200',
            [$subsiteId]
        );
    }

    public static function commissions(int $subsiteId, int $limit = 200): array
    {
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('subsite_commission') . ' WHERE beneficiary_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$subsiteId]
        );
    }

    /** 到期分站自动停用（幂等，供定时任务调用） */
    public static function expireDue(): int
    {
        return YxDb::exec(
            'UPDATE ' . YxDb::table('subsite') . " SET status = ?, updated_at = ? WHERE status = ? AND expire_at IS NOT NULL AND expire_at <> '' AND expire_at < ?",
            [self::STATUS_PENDING, now(), self::STATUS_ACTIVE, date('Y-m-d')]
        );
    }
}
