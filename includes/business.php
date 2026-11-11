<?php
/**
 * 优享财务系统 · 业务核心（产品 / 业务实例 / 订单 / 续费 / 工单 / 应用安装）
 */

class YxBusiness
{
    /** 计费周期 */
    const CYCLES = [
        'month'    => ['name' => '月付',  'months' => 1],
        'quarter'  => ['name' => '季付',  'months' => 3],
        'semiyear' => ['name' => '半年付', 'months' => 6],
        'year'     => ['name' => '年付',  'months' => 12],
    ];

    /**
     * 产品分类（product.type）
     *
     * 用于「订购产品」的下拉筛选。新增分类时只在此处追加，前台/后台自动生效；
     * 历史数据中的 host / vps / other 保持原值可读，不做数据迁移。
     */
    const PRODUCT_TYPES = [
        'cloud'     => ['name' => '云服务器',   'icon' => '☁',  'desc' => '弹性计算 · 按需升降配'],
        'dedicated' => ['name' => '独立服务器', 'icon' => '▤',  'desc' => '独享资源 · 高性能'],
        'physical'  => ['name' => '物理服务器', 'icon' => '⬢',  'desc' => '裸金属 · 整机独享'],
        'host'      => ['name' => '虚拟主机',   'icon' => '▦',  'desc' => '建站托管 · 开箱即用'],
        'cdn'       => ['name' => 'CDN',        'icon' => '⇄',  'desc' => '内容分发 · 加速'],
        'vps'       => ['name' => 'VPS',        'icon' => '▣',  'desc' => '虚拟专用服务器'],
        'other'     => ['name' => '其他',       'icon' => '◦',  'desc' => '其他产品'],
    ];

    /** 「订购产品」下拉中展示的主分类顺序 */
    const ORDER_TYPES = ['cloud', 'dedicated', 'physical', 'host', 'cdn'];

    public static function productTypeName(string $t): string
    {
        return self::PRODUCT_TYPES[$t]['name'] ?? $t;
    }

    public static function productTypeIcon(string $t): string
    {
        return self::PRODUCT_TYPES[$t]['icon'] ?? '◦';
    }

    /** 业务实例状态 */
    const SERVICE_STATUS = [
        'pending'    => '待开通',
        'active'     => '正常',
        'suspended'  => '已暂停',
        'terminated' => '已终止',
        'expired'    => '已到期',
    ];

    const ORDER_STATUS = [
        'unpaid'    => '待支付',
        'awaiting'  => '待确认转账',
        'paid'      => '已支付',
        'cancelled' => '已取消',
        'refunded'  => '已退款',
    ];

    /** 状态 → 徽章样式（前台 / 后台共用，避免各处散落三元表达式） */
    public static function orderBadgeClass(string $s): string
    {
        return [
            'paid'      => 'badge-ok',
            'unpaid'    => 'badge-warn',
            'awaiting'  => 'badge-info',
            'cancelled' => 'badge-mute',
            'refunded'  => 'badge-bad',
        ][$s] ?? 'badge-mute';
    }

    /** 未完成、仍可继续付款 / 可取消的状态 */
    public static function orderIsOpen(string $s): bool
    {
        return in_array($s, ['unpaid', 'awaiting'], true);
    }

    /**
     * 参与分站佣金结算的订单类型（客户消费类）
     * 充值 / 保证金 / 分站开通续费 / 0 元订单不参与，避免充值返佣套利与重复归属
     */
    const SUBSITE_SETTLE_TYPES = ['new', 'renew', 'app', 'edition'];

    /** 主程序包商品标记：授权码需在空站点上离线复核，走固定产品密钥签发 */
    const PACKAGE_APP_KEYS = ['youxiang-finance-official', 'youxiang-finance-open'];

    public static function cycleName(string $cycle): string
    {
        return self::CYCLES[$cycle]['name'] ?? $cycle;
    }

    public static function cycleMonths(string $cycle): int
    {
        return self::CYCLES[$cycle]['months'] ?? 1;
    }

    public static function serviceStatusName(string $s): string
    {
        return self::SERVICE_STATUS[$s] ?? $s;
    }

    public static function orderStatusName(string $s): string
    {
        return self::ORDER_STATUS[$s] ?? $s;
    }

    /** 按周期计算价格（年付 9 折、半年付 95 折） */
    public static function cyclePrice(array $product, string $cycle): float
    {
        $months = self::cycleMonths($cycle);
        $base   = (float)$product['price'];
        $total  = $base * $months;
        if ($months >= 12) {
            $total *= 0.9;
        } elseif ($months >= 6) {
            $total *= 0.95;
        }
        return round_money($total);
    }

    /**
     * 产品允许下单的周期
     *
     * cycles 为空表示不限制（全部周期可选）；写入合法周期后只保留这些周期，
     * 且按 CYCLES 的定义顺序（月 → 季 → 半年 → 年）返回，便于前台下拉稳定展示。
     * 非法值一律忽略，避免脏数据让产品变成「无可选周期」。
     */
    public static function allowedCycles(array $product): array
    {
        $raw = trim((string)($product['cycles'] ?? ''));
        if ($raw === '') {
            return array_keys(self::CYCLES);
        }
        $want = [];
        foreach (preg_split('/[\s,，]+/u', $raw) as $k) {
            $k = trim($k);
            if ($k !== '' && isset(self::CYCLES[$k])) {
                $want[$k] = true;
            }
        }
        if (!$want) {
            return array_keys(self::CYCLES);
        }
        return array_values(array_filter(array_keys(self::CYCLES), static function ($k) use ($want) {
            return isset($want[$k]);
        }));
    }

    /** 该周期是否允许下单 */
    public static function cycleAllowed(array $product, string $cycle): bool
    {
        return in_array($cycle, self::allowedCycles($product), true);
    }

    /** 产品默认下单周期：原周期可选则用它，否则取允许的第一个周期 */
    public static function defaultCycle(array $product): string
    {
        $cycle = (string)($product['cycle'] ?? 'month');
        return self::cycleAllowed($product, $cycle) ? $cycle : self::allowedCycles($product)[0];
    }

    /** 允许周期的中文名（如「仅年付」「月付 / 年付」），空/未限制时返回空串 */
    public static function cycleLabel(array $product): string
    {
        if (trim((string)($product['cycles'] ?? '')) === '') {
            return '';
        }
        $names = [];
        foreach (self::allowedCycles($product) as $k) {
            $names[] = self::cycleName($k);
        }
        return count($names) === 1 ? '仅' . $names[0] : implode(' / ', $names);
    }

    /** 计算下一个到期日 */
    public static function nextDue(string $cycle, string $from = null): string
    {
        $from   = $from ?: date('Y-m-d');
        $months = self::cycleMonths($cycle);
        $ts     = strtotime($from . ' +' . $months . ' month');
        return date('Y-m-d', $ts ?: time());
    }

    /* ------------------------------ 业务实例 ------------------------------ */

    public static function createService(array $data): int
    {
        $spec = $data['remote_spec'] ?? null;
        if (is_array($spec)) {
            $spec = $spec ? json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        } elseif (trim((string)$spec) === '') {
            $spec = null;
        }

        return YxDb::insert('service', [
            'sn'            => make_sn(config('finance.service_prefix', 'SVC'), 'service'),
            'customer_id'   => (int)($data['customer_id'] ?? 0),
            'product_id'    => (int)($data['product_id'] ?? 0),
            'node_id'       => (int)($data['node_id'] ?? 0),
            'customer_upstream_id' => (int)($data['customer_upstream_id'] ?? 0),
            'remote_code'   => $data['remote_code'] ?? null,
            'remote_spec'   => $spec,
            'name'          => $data['name'] ?? '未命名业务',
            'hostname'      => $data['hostname'] ?? null,
            'ip'            => $data['ip'] ?? null,
            'login_user'    => $data['login_user'] ?? null,
            'login_pass'    => isset($data['login_pass']) ? yx_encrypt((string)$data['login_pass']) : null,
            'billing_cycle' => $data['billing_cycle'] ?? 'month',
            'price'         => round_money($data['price'] ?? 0),
            'status'        => $data['status'] ?? 'pending',
            'next_due_date' => $data['next_due_date'] ?? null,
            'remark'        => $data['remark'] ?? null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /** 开通业务实例 */
    public static function activateService(int $serviceId, string $ip = '', array $extra = []): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc) {
            return ['ok' => false, 'msg' => '业务不存在'];
        }
        if ($svc['status'] === 'terminated') {
            return ['ok' => false, 'msg' => '已终止的业务无法重新开通'];
        }

        // 客户自有上游：直接按订单记录的产品编号向上游申请开通
        if ((int)($svc['customer_upstream_id'] ?? 0) > 0 && empty($svc['remote_id'])) {
            $taskId = YxTask::push('upstream.create', [
                'ref_type' => 'service',
                'ref_id'   => $serviceId,
                'payload'  => ['os' => $extra['os'] ?? null],
            ]);
            YxDb::update('service', [
                'status'        => 'pending',
                'remote_status' => 'creating',
                'updated_at'    => now(),
            ], 'id = :id', ['id' => $serviceId]);
            return [
                'ok'      => true,
                'msg'     => '已提交上游开通任务（#' . $taskId . '），上游返回成功后业务自动开通',
                'queued'  => true,
                'task_id' => $taskId,
            ];
        }

        // 产品已绑定上游：交由任务队列向上游申请开通，成功后自动置为「正常」
        $bind = YxUpstream::mapForProduct((int)$svc['product_id']);
        if ($bind && empty($svc['remote_id'])) {
            $taskId = YxTask::push('upstream.create', [
                'ref_type'    => 'service',
                'ref_id'      => $serviceId,
                'upstream_id' => (int)$bind['upstream']['id'],
                'payload'     => ['os' => $extra['os'] ?? null],
            ]);
            YxDb::update('service', [
                'status'        => 'pending',
                'upstream_id'   => (int)$bind['upstream']['id'],
                'remote_status' => 'creating',
                'updated_at'    => now(),
            ], 'id = :id', ['id' => $serviceId]);
            return [
                'ok'      => true,
                'msg'     => '已提交上游开通任务（#' . $taskId . '），上游返回成功后业务自动开通',
                'queued'  => true,
                'task_id' => $taskId,
            ];
        }

        $update = [
            'status'       => 'active',
            'activated_at' => $svc['activated_at'] ?: now(),
            'updated_at'   => now(),
        ];
        if ($ip !== '') {
            $update['ip'] = $ip;
        }
        if (empty($svc['next_due_date'])) {
            $update['next_due_date'] = self::nextDue($svc['billing_cycle']);
        }
        foreach (['hostname', 'login_user', 'login_pass'] as $k) {
            if (!empty($extra[$k])) {
                $update[$k] = $k === 'login_pass' ? yx_encrypt((string)$extra[$k]) : $extra[$k];
            }
        }
        YxDb::update('service', $update, 'id = :id', ['id' => $serviceId]);

        // 扣减节点容量
        if (!empty($svc['node_id'])) {
            YxDb::exec('UPDATE ' . YxDb::table('node') . ' SET used = used + 1 WHERE id = ?', [(int)$svc['node_id']]);
        }

        return ['ok' => true, 'msg' => '业务已开通'];
    }

    public static function suspendService(int $id, string $reason = ''): array
    {
        $n = YxDb::exec(
            'UPDATE ' . YxDb::table('service') . " SET status = 'suspended', remark = ?, updated_at = ? WHERE id = ? AND status IN ('active','pending')",
            [$reason ?: '暂停', now(), $id]
        );
        if (!$n) {
            return ['ok' => false, 'msg' => '当前状态不支持暂停'];
        }
        self::pushUpstreamOp($id, 'stop');
        return ['ok' => true, 'msg' => '业务已暂停'];
    }

    public static function unsuspendService(int $id): array
    {
        $n = YxDb::exec(
            'UPDATE ' . YxDb::table('service') . " SET status = 'active', updated_at = ? WHERE id = ? AND status = 'suspended'",
            [now(), $id]
        );
        if (!$n) {
            return ['ok' => false, 'msg' => '当前状态不支持恢复'];
        }
        self::pushUpstreamOp($id, 'start');
        return ['ok' => true, 'msg' => '业务已恢复'];
    }

    /** 有上游实例时，同步下发电源操作 */
    private static function pushUpstreamOp(int $serviceId, string $action): void
    {
        $svc = YxDb::one('SELECT id, remote_id, upstream_id, customer_upstream_id FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc || empty($svc['remote_id'])) {
            return;
        }
        if ((int)$svc['upstream_id'] <= 0 && (int)($svc['customer_upstream_id'] ?? 0) <= 0) {
            return;
        }
        YxTask::push('upstream.power', [
            'ref_type'    => 'service',
            'ref_id'      => $serviceId,
            'upstream_id' => (int)$svc['upstream_id'],
            'payload'     => ['action' => $action],
            'unique'      => false,
        ]);
    }

    public static function terminateService(int $id, string $reason = ''): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$id]);
        if (!$svc) {
            return ['ok' => false, 'msg' => '业务不存在'];
        }
        if ($svc['status'] === 'terminated') {
            return ['ok' => false, 'msg' => '业务已终止'];
        }
        YxDb::update('service', [
            'status'     => 'terminated',
            'remark'     => $reason ?: '终止',
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);

        if (!empty($svc['node_id']) && (int)$svc['node_id'] > 0) {
            YxDb::exec('UPDATE ' . YxDb::table('node') . ' SET used = CASE WHEN used > 0 THEN used - 1 ELSE 0 END WHERE id = ?', [(int)$svc['node_id']]);
        }

        // 有上游实例则下发销毁（节点容量已在此处释放，上游执行器不再重复扣减）
        if (!empty($svc['remote_id']) && ((int)$svc['upstream_id'] > 0 || (int)($svc['customer_upstream_id'] ?? 0) > 0)) {
            YxTask::push('upstream.destroy', [
                'ref_type'    => 'service',
                'ref_id'      => $id,
                'upstream_id' => (int)$svc['upstream_id'],
            ]);
        }
        return ['ok' => true, 'msg' => '业务已终止'];
    }

    /** 续费：延长到期时间 */
    public static function renewService(int $serviceId, string $cycle = null, float $price = 0, string $operator = 'admin'): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc) {
            return ['ok' => false, 'msg' => '业务不存在'];
        }
        if ($svc['status'] === 'terminated') {
            return ['ok' => false, 'msg' => '已终止的业务无法续费'];
        }
        $cycle = $cycle ?: $svc['billing_cycle'];
        if (!isset(self::CYCLES[$cycle])) {
            return ['ok' => false, 'msg' => '不支持的计费周期'];
        }

        // 已过期从今天顺延，未过期从到期日顺延
        $base = (!empty($svc['next_due_date']) && $svc['next_due_date'] > date('Y-m-d'))
            ? $svc['next_due_date']
            : date('Y-m-d');
        $newDue = self::nextDue($cycle, $base);

        YxDb::update('service', [
            'next_due_date' => $newDue,
            'billing_cycle' => $cycle,
            'price'         => $price > 0 ? round_money($price) : $svc['price'],
            'status'        => $svc['status'] === 'expired' ? 'active' : $svc['status'],
            'updated_at'    => now(),
        ], 'id = :id', ['id' => $serviceId]);

        // 有上游实例则同步通知上游顺延
        if (!empty($svc['remote_id']) && ((int)$svc['upstream_id'] > 0 || (int)($svc['customer_upstream_id'] ?? 0) > 0)) {
            YxTask::push('upstream.renew', [
                'ref_type'    => 'service',
                'ref_id'      => $serviceId,
                'upstream_id' => (int)$svc['upstream_id'],
                'payload'     => ['cycle' => $cycle],
            ]);
        }

        return ['ok' => true, 'msg' => '续费成功', 'next_due_date' => $newDue];
    }

    /**
     * 原子「抢占」一次续费。
     *
     * 并发防护依赖调用方传入的「快照」$expectDue：只有当库中到期日仍等于调用方观测到的值时才允许顺延。
     * 因此**不能**在方法内部重新读取到期日后再比对——那样第二次调用读到的已是顺延后的值，防护会失效。
     *
     * 用于自动续费场景防止并发（多个 cron 进程 / 手动触发叠加）导致：
     *   - 重复扣费（两次 debit 都成功）
     *   - 重复顺延（第二次在已顺延的日期上再加一个周期）
     *
     * 调用顺序必须是：claimRenew() → 扣费 → 扣费失败则 rollbackRenew()
     *
     * @param int         $serviceId 业务实例 ID
     * @param string|null $expectDue 调用方观测到的到期日（可为 null，表示观测到的是空）
     * @param string|null $cycle     计费周期，默认沿用业务当前周期
     *
     * @return array ['ok'=>bool,'claimed'=>bool,'msg'=>string,'prev_due'=>?string,'next_due_date'=>string]
     */
    public static function claimRenew(int $serviceId, ?string $expectDue, string $cycle = null): array
    {
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$serviceId]);
        if (!$svc) {
            return ['ok' => false, 'claimed' => false, 'msg' => '业务不存在'];
        }
        if ($svc['status'] === 'terminated') {
            return ['ok' => false, 'claimed' => false, 'msg' => '已终止的业务无法续费'];
        }
        $cycle = $cycle ?: (string)$svc['billing_cycle'];
        if (!isset(self::CYCLES[$cycle])) {
            return ['ok' => false, 'claimed' => false, 'msg' => '不支持的计费周期'];
        }

        // 前置校验：库中到期日必须仍是调用方观测到的那个值
        $current  = $svc['next_due_date'];
        $observed = ($expectDue === null || $expectDue === '') ? null : $expectDue;
        if ((string)($current ?? '') !== (string)($observed ?? '')) {
            return ['ok' => false, 'claimed' => false, 'msg' => '到期日已变化（可能已被其它进程续费），跳过'];
        }

        $base = ($current !== null && $current !== '' && $current > date('Y-m-d')) ? $current : date('Y-m-d');
        $new  = self::nextDue($cycle, $base);

        // CAS：到期日未变才允许抢占（抵御前置校验与写入之间的极小竞态）
        if ($current === null) {
            $affected = YxDb::exec(
                'UPDATE ' . YxDb::table('service') . " SET next_due_date = ?, billing_cycle = ?, status = 'active', updated_at = ? WHERE id = ? AND next_due_date IS NULL",
                [$new, $cycle, now(), $serviceId]
            );
        } else {
            $affected = YxDb::exec(
                'UPDATE ' . YxDb::table('service') . " SET next_due_date = ?, billing_cycle = ?, status = 'active', updated_at = ? WHERE id = ? AND next_due_date = ?",
                [$new, $cycle, now(), $serviceId, $current]
            );
        }

        if ($affected < 1) {
            return ['ok' => false, 'claimed' => false, 'msg' => '该业务已被其它进程处理，跳过'];
        }

        // 有上游实例则同步通知上游顺延
        if (!empty($svc['remote_id']) && ((int)$svc['upstream_id'] > 0 || (int)($svc['customer_upstream_id'] ?? 0) > 0)) {
            YxTask::push('upstream.renew', [
                'ref_type'    => 'service',
                'ref_id'      => $serviceId,
                'upstream_id' => (int)$svc['upstream_id'],
                'payload'     => ['cycle' => $cycle],
            ]);
        }

        return [
            'ok'            => true,
            'claimed'       => true,
            'msg'           => '期限已顺延',
            'prev_due'      => $current,
            'next_due_date' => $new,
        ];
    }

    /**
     * 归还期限：扣费失败时调用。
     * 只有当到期日仍是我们抢占时写入的值才回滚，避免误改其它流程的结果。
     */
    public static function rollbackRenew(int $serviceId, ?string $prevDue, string $claimedDue): void
    {
        if ($prevDue === null || $prevDue === '') {
            YxDb::exec(
                'UPDATE ' . YxDb::table('service') . ' SET next_due_date = NULL, updated_at = ? WHERE id = ? AND next_due_date = ?',
                [now(), $serviceId, $claimedDue]
            );
            return;
        }
        YxDb::exec(
            'UPDATE ' . YxDb::table('service') . ' SET next_due_date = ?, updated_at = ? WHERE id = ? AND next_due_date = ?',
            [$prevDue, now(), $serviceId, $claimedDue]
        );
    }

    /* ------------------------------ 自动续费 ------------------------------ */

    /**
     * 待自动续费的业务（cron 与自检共用同一段 SQL，避免两处漂移）
     *
     * @param int $aheadDays 提前天数：0 表示到期当天才处理
     */
    public static function autoRenewCandidates(int $aheadDays = 0, int $limit = 200): array
    {
        $ahead  = max(0, $aheadDays);
        $cutoff = date('Y-m-d', strtotime('+' . $ahead . ' day'));
        $limit  = max(1, min(1000, $limit));

        return YxDb::all(
            'SELECT s.*, c.balance FROM ' . YxDb::table('service') . ' s
             JOIN ' . YxDb::table('customer') . ' c ON c.id = s.customer_id
             WHERE s.status IN (\'active\',\'expired\')
               AND s.next_due_date IS NOT NULL
               AND s.next_due_date <= ?
             ORDER BY s.next_due_date ASC, s.id ASC
             LIMIT ' . $limit,
            [$cutoff]
        );
    }

    /**
     * 执行一轮自动续费（金额安全：先原子抢占期限，再扣费，失败归还期限）
     *
     * @return array ['charged'=>int,'skipped'=>int,'cutoff'=>string,'details'=>string[]]
     */
    public static function runAutoRenew(int $aheadDays = 0, int $limit = 200): array
    {
        $cutoff = date('Y-m-d', strtotime('+' . max(0, $aheadDays) . ' day'));
        $charged = 0;
        $skipped = 0;
        $details = [];

        foreach (self::autoRenewCandidates($aheadDays, $limit) as $s) {
            $price = round_money($s['price']);

            if ($price <= 0) {
                $skipped++;
                $details[] = $s['sn'] . ' 跳过：价格为 0（免费业务）';
                continue;
            }

            // 1) 原子抢占期限：并发下只有一个进程能拿到（必须传入候选快照的到期日）
            $claim = self::claimRenew(
                (int)$s['id'],
                $s['next_due_date'] === null ? null : (string)$s['next_due_date'],
                (string)$s['billing_cycle']
            );
            if (empty($claim['claimed'])) {
                $skipped++;
                $details[] = $s['sn'] . ' 跳过：' . $claim['msg'];
                continue;
            }

            // 2) 扣费（余额不足会失败）
            $paid = YxFinance::debit((int)$s['customer_id'], $price, 'order', [
                'method'   => 'balance',
                'ref_type' => 'service',
                'ref_id'   => (int)$s['id'],
                'remark'   => '自动续费 ' . $s['sn'],
                'operator' => 'cron',
            ]);
            if (empty($paid['ok'])) {
                // 3) 扣费失败 → 归还期限，下次继续尝试
                self::rollbackRenew((int)$s['id'], $claim['prev_due'], (string)$claim['next_due_date']);
                $skipped++;
                $details[] = sprintf(
                    '%s 跳过：余额不足（需 %s，当前 %s）',
                    $s['sn'],
                    $price,
                    round_money((float)$s['balance'])
                );
                continue;
            }

            // 4) 生成已结清账单
            try {
                YxFinance::createInvoice([
                    'customer_id' => (int)$s['customer_id'],
                    'service_id'  => (int)$s['id'],
                    'title'       => '自动续费 - ' . $s['name'],
                    'amount'      => $price,
                    'paid_amount' => $price,
                    'status'      => 'paid',
                    'type'        => 'service',
                ]);
            } catch (Throwable $e) {
                $details[] = $s['sn'] . ' 账单生成失败（已扣费，请人工补单）：' . $e->getMessage();
            }

            // 5) 营业收入登记：自动续费没有订单，必须单独记，否则这部分营收在报表里完全不可见
            YxFinance::bookIncome($price, 'order', [
                'method'   => 'balance',
                'ref_type' => 'service',
                'ref_id'   => (int)$s['id'],
                'remark'   => '续费收入 ' . $s['sn'],
                'operator' => 'cron',
            ]);

            $charged++;
            $details[] = sprintf('%s 已续费 %s，新到期日 %s', $s['sn'], $price, $claim['next_due_date']);
        }

        return ['charged' => $charged, 'skipped' => $skipped, 'cutoff' => $cutoff, 'details' => $details];
    }

    /* ------------------------------ 订单 ------------------------------ */

    /**
     * 创建订单
     * @param array $data customer_id,type,product_id,service_id,app_id,amount,discount,coupon_id,note
     */
    public static function createOrder(array $data): array
    {
        $amount   = round_money($data['amount'] ?? 0);
        $discount = round_money($data['discount'] ?? 0);
        $total    = round_money(max(0, $amount - $discount));

        if ($total <= 0 && ($data['type'] ?? '') !== 'free') {
            return ['ok' => false, 'msg' => '订单金额不能为 0'];
        }

        $customerId = (int)($data['customer_id'] ?? 0);
        $type       = (string)($data['type'] ?? 'new');
        $subsiteId  = (int)($data['subsite_id'] ?? 0);

        // 客户自有上游下单：产品编号与规格随订单落库，交付时据此向上游申请开通
        $remoteCode = trim((string)($data['remote_code'] ?? ''));
        $remoteSpec = $data['remote_spec'] ?? null;
        if (is_array($remoteSpec)) {
            $remoteSpec = $remoteSpec ? json_encode($remoteSpec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        } elseif (trim((string)$remoteSpec) === '') {
            $remoteSpec = null;
        }
        $billingCycle = (string)($data['billing_cycle'] ?? 'month');
        if (!isset(self::CYCLES[$billingCycle])) {
            $billingCycle = 'month';
        }

        // 客户消费订单自动归属客户所属分站（调用方显式传入时以传入值为准）
        if ($subsiteId <= 0 && $customerId > 0 && in_array($type, self::SUBSITE_SETTLE_TYPES, true)) {
            $subsiteId = (int)YxDb::value(
                'SELECT subsite_id FROM ' . YxDb::table('customer') . ' WHERE id = ?',
                [$customerId]
            );
        }

        // 推广来源快照：下单时锁定买家的推广人，避免后续改绑影响历史订单归属
        $publisherId = (int)($data['publisher_id'] ?? 0);
        if ($publisherId <= 0 && $customerId > 0) {
            $publisherId = (int)YxDb::value(
                'SELECT publisher_id FROM ' . YxDb::table('customer') . ' WHERE id = ?',
                [$customerId]
            );
        }
        // 自购不计佣金
        if ($publisherId === $customerId) {
            $publisherId = 0;
        }

        $id = YxDb::insert('order', [
            'sn'          => make_sn(config('finance.order_prefix', 'YX'), 'order'),
            'customer_id' => $customerId,
            'type'        => $type,
            'product_id'  => (int)($data['product_id'] ?? 0),
            'service_id'  => (int)($data['service_id'] ?? 0),
            'app_id'      => (int)($data['app_id'] ?? 0),
            'name'        => $data['name'] ?? null,
            'amount'      => $amount,
            'discount'    => $discount,
            'total'       => $total,
            'status'      => 'unpaid',
            'coupon_id'   => (int)($data['coupon_id'] ?? 0),
            'agent_id'    => (int)($data['agent_id'] ?? 0),
            'subsite_id'  => $subsiteId,
            'customer_upstream_id' => (int)($data['customer_upstream_id'] ?? 0),
            'remote_code' => $remoteCode !== '' ? $remoteCode : null,
            'remote_spec' => $remoteSpec,
            'billing_cycle' => $billingCycle,
            'upstream_markup' => trim((string)($data['upstream_markup'] ?? '')) !== '' ? (string)$data['upstream_markup'] : null,
            'publisher_id' => $publisherId,
            'note'        => $data['note'] ?? null,
            'created_at'  => now(),
        ]);

        return ['ok' => true, 'msg' => '订单已创建', 'order_id' => $id, 'total' => $total];
    }

    /**
     * 订单支付（余额支付会先扣减客户余额，随后进入统一交付流程）
     */
    public static function payOrder(int $orderId, string $method = 'balance', string $tradeNo = ''): array
    {
        $check = self::orderForPay($orderId);
        if (isset($check['error'])) {
            return $check['error'];
        }
        $order = $check['order'];

        // 充值订单不能用余额支付（否则等于自己给自己充值）
        if ($order['type'] === 'recharge' && $method === 'balance') {
            return ['ok' => false, 'msg' => '充值订单不支持使用账户余额支付'];
        }

        if ($method === 'balance') {
            $pay = YxFinance::debit((int)$order['customer_id'], round_money($order['total']), 'order', [
                'method'   => 'balance',
                'ref_type' => 'order',
                'ref_id'   => $orderId,
                'remark'   => '订单支付 ' . $order['sn'],
                'operator' => 'customer',
            ]);
            if (!$pay['ok']) {
                return $pay;
            }
        }

        $res = self::fulfillOrder($order, $method, $tradeNo);

        // 余额已扣但交付失败：订单会回滚到待支付，钱必须退回，否则客户钱货两空。
        // 这里用 refund 分类记账，备注写明自动退回，便于对账时区分人工退款。
        if (empty($res['ok']) && $method === 'balance' && empty($res['repeat'])) {
            $back = YxFinance::credit((int)$order['customer_id'], round_money($order['total']), 'refund', [
                'method'   => 'balance',
                'ref_type' => 'order',
                'ref_id'   => $orderId,
                'remark'   => '订单交付失败自动退回 ' . $order['sn'],
                'operator' => 'system',
            ]);
            if (empty($back['ok'])) {
                $res['msg'] = (string)($res['msg'] ?? '交付失败') . '；余额自动退回失败，请人工处理（订单 ' . $order['sn'] . '）';
            } else {
                $res['msg'] = (string)($res['msg'] ?? '交付失败') . '（已自动退回余额 ' . money($order['total'], true) . '）';
            }
        }

        return $res;
    }

    /**
     * 线下收款 / 第三方网关已收款：不扣余额，直接完成交付
     */
    public static function payOrderExternal(int $orderId, string $method = 'manual', string $tradeNo = ''): array
    {
        $check = self::orderForPay($orderId);
        if (isset($check['error'])) {
            return $check['error'];
        }
        return self::fulfillOrder($check['order'], $method, $tradeNo);
    }

    /**
     * 订单退款（管理员操作）
     *
     * - 仅「已支付」订单可退，用带条件的 UPDATE 抢占状态，双击 / 并发只会成功一次；
     * - 充值订单：退款是冲回当初入账的余额，用 debit 扣回，余额不足则整单回滚；
     * - 其他订单：把已付金额退回客户余额（credit）。
     */
    public static function refundOrder(int $orderId, string $operator = 'admin'): array
    {
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]);
        if (!$order) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if ((string)$order['status'] !== 'paid') {
            return ['ok' => false, 'msg' => '仅已支付订单可退款'];
        }

        $total      = round_money($order['total']);
        $isRecharge = (string)$order['type'] === 'recharge';
        $ownTx      = !YxDb::inTransaction();
        if ($ownTx) {
            YxDb::begin();
        }
        try {
            $n = YxDb::exec(
                'UPDATE ' . YxDb::table('order') . " SET status = 'refunded' WHERE id = ? AND status = 'paid'",
                [$orderId]
            );
            if ($n < 1) {
                if ($ownTx) {
                    YxDb::rollback();
                }
                return ['ok' => false, 'msg' => '订单状态已变化（可能已被退款），请刷新后重试'];
            }

            $opt = [
                'method'   => $isRecharge ? 'balance' : 'manual',
                'ref_type' => 'order',
                'ref_id'   => $orderId,
                'remark'   => ($isRecharge ? '充值订单退款冲回 ' : '订单退款 ') . $order['sn'],
                'operator' => $operator,
            ];
            $refund = $isRecharge
                ? YxFinance::debit((int)$order['customer_id'], $total, 'refund', $opt)
                : YxFinance::credit((int)$order['customer_id'], $total, 'refund', $opt);
            if (empty($refund['ok'])) {
                // 入账失败：把订单状态还原，避免「已退款但钱没退」
                if ($ownTx) {
                    YxDb::rollback();
                }
                return $isRecharge
                    ? ['ok' => false, 'msg' => '客户余额不足以冲回该笔充值（需 ' . money($total, true) . '），请先核对客户余额后再处理']
                    : $refund;
            }

            YxDb::exec('UPDATE ' . YxDb::table('invoice') . " SET status = 'void' WHERE order_id = ? AND status <> 'paid'", [$orderId]);
            if ($ownTx) {
                YxDb::commit();
            }
            return [
                'ok'  => true,
                'msg' => ($isRecharge ? '退款成功，已从客户余额冲回 ' : '退款成功，已退回客户余额 ') . money($total, true),
            ];
        } catch (Throwable $e) {
            if ($ownTx) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '退款失败：' . $e->getMessage()];
        }
    }

    /**
     * 用户选择「银联卡转账」并声明已转账：订单置为待确认，等待管理员核对到账。
     * 不做任何交付，也不改动财务流水，管理员确认后才走 payOrderExternal()。
     */
    public static function markAwaiting(int $orderId, string $customerName = ''): array
    {
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]);
        if (!$order) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if ($order['status'] === 'awaiting') {
            return ['ok' => true, 'msg' => '已提交，请等待管理员核对到账', 'repeat' => true];
        }
        if ($order['status'] !== 'unpaid') {
            return ['ok' => false, 'msg' => '订单状态为「' . self::orderStatusName($order['status']) . '」，无法提交转账'];
        }

        $note = trim((string)$order['note']);
        $tag  = '银联转账·已声明付款' . ($customerName !== '' ? '（' . $customerName . '）' : '') . ' ' . now();
        $n    = YxDb::exec(
            'UPDATE ' . YxDb::table('order') . " SET status = 'awaiting', pay_method = 'unionpay_transfer', note = ? WHERE id = ? AND status = 'unpaid'",
            [($note !== '' ? $note . ' | ' : '') . $tag, $orderId]
        );
        if ($n < 1) {
            return ['ok' => false, 'msg' => '订单状态已变化，请刷新后重试'];
        }
        return ['ok' => true, 'msg' => '已收到你的转账声明，管理员核对到账后即时开通'];
    }

    /** 支付前置校验：返回 ['order'=>array] 或 ['error'=>array] */
    private static function orderForPay(int $orderId): array
    {
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]);
        if (!$order) {
            return ['error' => ['ok' => false, 'msg' => '订单不存在']];
        }
        if ($order['status'] === 'paid') {
            return ['error' => ['ok' => true, 'msg' => '订单已支付', 'repeat' => true]];
        }
        if (!self::orderIsOpen((string)$order['status'])) {
            return ['error' => ['ok' => false, 'msg' => '订单状态为「' . self::orderStatusName($order['status']) . '」，无法支付']];
        }
        return ['order' => $order];
    }

    /** 支付成功后的统一交付逻辑 */
    private static function fulfillOrder(array $order, string $method, string $tradeNo): array
    {
        $orderId = (int)$order['id'];
        $total   = round_money($order['total']);

        // 幂等：订单可能被重复通知 / 并发回调触发两次交付
        if (YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('payment') . " WHERE order_id = ? AND status = 'paid'",
            [$orderId]
        ) > 0) {
            return ['ok' => true, 'msg' => '订单已支付', 'repeat' => true];
        }

        YxDb::begin();
        try {
            $affected = YxDb::exec(
                'UPDATE ' . YxDb::table('order') . " SET status = 'paid', pay_method = ?, trade_no = ?, paid_at = ? WHERE id = ? AND status IN ('unpaid','awaiting')",
                [$method, $tradeNo ?: null, now(), $orderId]
            );
            if ($affected < 1) {
                YxDb::rollback();
                return ['ok' => true, 'msg' => '订单已支付', 'repeat' => true];
            }

            YxFinance::recordPayment([
                'order_id'    => $orderId,
                'order_sn'    => $order['sn'],
                'customer_id' => (int)$order['customer_id'],
                'gateway'     => $method,
                'trade_no'    => $tradeNo,
                'amount'      => $total,
                'status'      => 'paid',
            ]);

            $res = self::deliverOrder($order, $method, $tradeNo, $total);
            if (empty($res['ok'])) {
                // 交付失败整单回滚，订单回到待支付，等待网关重试
                YxDb::rollback();
                return $res;
            }
            YxDb::commit();

            // 推广计划佣金：事务外计提，失败不影响已完成的交付
            if (class_exists('YxAffiliate') && $total > 0) {
                try {
                    YxAffiliate::accrueOrder($orderId);
                } catch (Throwable $e) {
                    log_action('affiliate.accrue_error', 'order#' . $orderId, ['error' => $e->getMessage()]);
                }
            }

            // 客户自有上游：事务提交后立即执行本单开通任务（队列未必有 cron 驱动）
            if ((string)$order['type'] === 'upstream') {
                $sid = (int)YxDb::value('SELECT service_id FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]);
                if ($sid > 0) {
                    return ['ok' => true, 'msg' => self::provisionUpstreamOrder($sid), 'service_id' => $sid];
                }
            }
            return ['ok' => true, 'msg' => '支付成功'];
        } catch (Throwable $e) {
            YxDb::rollback();
            return ['ok' => false, 'msg' => '交付异常：' . $e->getMessage()];
        }
    }

    /**
     * 立即执行某业务的「上游开通」任务，并给出面向客户的提示文案。
     * 失败不回滚订单（订单已支付、业务已建），保留任务以便重试。
     */
    private static function provisionUpstreamOrder(int $serviceId): string
    {
        try {
            YxTask::run(3, 'upstream.create', $serviceId);
        } catch (Throwable $e) {
            return '支付成功，已提交自动开通，稍后将自动完成';
        }

        $svc = YxDb::one(
            'SELECT status, remote_id FROM ' . YxDb::table('service') . ' WHERE id = ?',
            [$serviceId]
        );
        if ($svc && !empty($svc['remote_id'])) {
            return '支付成功，上游已自动开通';
        }

        $task = YxDb::one(
            'SELECT status, error FROM ' . YxDb::table('task') . " WHERE type = 'upstream.create' AND ref_id = ? ORDER BY id DESC LIMIT 1",
            [$serviceId]
        );
        if ($task && $task['status'] === 'failed') {
            return '支付成功，但上游开通失败：' . (string)($task['error'] ?: '未知错误') . '（可在业务详情中重试开通）';
        }
        return '支付成功，已提交自动开通，稍后将自动完成';
    }

    /** 按订单类型执行交付（在事务内调用） */
    private static function deliverOrder(array $order, string $method, string $tradeNo, float $total): array
    {
        $orderId = (int)$order['id'];

        // 按订单类型执行交付
        switch ($order['type']) {
            case 'recharge':
                // 充值到账：增加客户余额并写入收入流水
                $credit = YxFinance::credit((int)$order['customer_id'], $total, 'recharge', [
                    'method'   => $method,
                    'ref_type' => 'order',
                    'ref_id'   => $orderId,
                    'remark'   => '账户充值 ' . $order['sn'],
                    'operator' => 'system',
                ]);
                if (!$credit['ok']) {
                    return ['ok' => false, 'msg' => '充值入账失败：' . $credit['msg']];
                }
                break;

            case 'new':
                $product = $order['product_id']
                    ? YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ?', [(int)$order['product_id']])
                    : null;
                $serviceId = (int)$order['service_id'];
                if ($serviceId <= 0) {
                    $serviceId = self::createService([
                        'customer_id'   => (int)$order['customer_id'],
                        'product_id'    => (int)$order['product_id'],
                        'name'          => $order['name'] ?: ($product['name'] ?? '新购业务'),
                        'billing_cycle' => $product['cycle'] ?? 'month',
                        'price'         => $total,
                        'status'        => (int)($product['auto_setup'] ?? 0) === 1 ? 'pending' : 'pending',
                    ]);
                    YxDb::update('order', ['service_id' => $serviceId], 'id = :id', ['id' => $orderId]);
                }
                if (!empty($product['auto_setup'])) {
                    self::activateService($serviceId);
                }
                YxFinance::createInvoice([
                    'customer_id' => (int)$order['customer_id'],
                    'order_id'    => $orderId,
                    'service_id'  => $serviceId,
                    'title'       => '业务开通费用 - ' . ($order['name'] ?: '业务'),
                    'amount'      => $total,
                    'paid_amount' => $total,
                    'status'      => 'paid',
                    'type'        => 'service',
                ]);
                break;

            case 'upstream':
                // 客户自有上游采购：创建待开通业务 + 投递开通任务，付款即自动对接上游
                $connId = (int)($order['customer_upstream_id'] ?? 0);
                $code   = trim((string)($order['remote_code'] ?? ''));
                if ($connId <= 0 || $code === '') {
                    return ['ok' => false, 'msg' => '订单缺少上游对接信息，无法自动开通'];
                }
                $conn = YxDb::one(
                    'SELECT * FROM ' . YxDb::table('customer_upstream') . ' WHERE id = ? AND customer_id = ?',
                    [$connId, (int)$order['customer_id']]
                );
                if (!$conn) {
                    return ['ok' => false, 'msg' => '上游对接不存在或不属于当前账号，无法自动开通'];
                }
                if ((int)$conn['status'] !== 1) {
                    return ['ok' => false, 'msg' => '上游对接「' . $conn['name'] . '」已停用，无法自动开通'];
                }
                $serviceId = (int)$order['service_id'];
                if ($serviceId <= 0) {
                    $cycle     = (string)($order['billing_cycle'] ?? 'month');
                    $serviceId = self::createService([
                        'customer_id'          => (int)$order['customer_id'],
                        'customer_upstream_id' => $connId,
                        'remote_code'          => $code,
                        'remote_spec'          => $order['remote_spec'],
                        'name'                 => $order['name'] ?: ('上游业务 ' . $code),
                        'billing_cycle'        => isset(self::CYCLES[$cycle]) ? $cycle : 'month',
                        'price'                => $total,
                        'status'               => 'pending',
                    ]);
                    YxDb::update('order', ['service_id' => $serviceId], 'id = :id', ['id' => $orderId]);
                }
                YxTask::push('upstream.create', [
                    'ref_type' => 'service',
                    'ref_id'   => $serviceId,
                ]);
                YxFinance::createInvoice([
                    'customer_id' => (int)$order['customer_id'],
                    'order_id'    => $orderId,
                    'service_id'  => $serviceId,
                    'title'       => '业务开通费用 - ' . ($order['name'] ?: $code),
                    'amount'      => $total,
                    'paid_amount' => $total,
                    'status'      => 'paid',
                    'type'        => 'service',
                ]);
                break;

            case 'renew':
                // 交付成功后由统一的「营业收入登记段」记站方收入，这里只做交付
                if ((int)$order['service_id'] > 0) {
                    self::renewService((int)$order['service_id'], null, $total, 'customer');
                }
                YxFinance::createInvoice([
                    'customer_id' => (int)$order['customer_id'],
                    'order_id'    => $orderId,
                    'service_id'  => (int)$order['service_id'],
                    'title'       => '业务续费 - ' . ($order['name'] ?: '业务'),
                    'amount'      => $total,
                    'paid_amount' => $total,
                    'status'      => 'paid',
                    'type'        => 'service',
                ]);
                break;

            case 'service':
                // 服务中心：支付成功 → 写入服务交付记录并开通业务实例
                if (class_exists('YxServiceCenter')) {
                    $res = YxServiceCenter::deliverByOrder($order);
                    if (empty($res['ok'])) {
                        return $res;
                    }
                }
                break;

            case 'app':
                $appRow = (int)$order['app_id'] > 0
                    ? YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [(int)$order['app_id']])
                    : null;
                if ($appRow && (string)($appRow['author_type'] ?? '') === 'thirdparty') {
                    // 资源型：授予下载权益（不签发域名授权）
                    self::grantResource($appRow, (int)$order['customer_id'], $orderId);
                } else {
                    self::installApp([
                        'app_id'      => (int)$order['app_id'],
                        'customer_id' => (int)$order['customer_id'],
                        'order_id'    => $orderId,
                        'domain'      => $order['name'] ?: '',
                    ]);
                }
                // 开发者商品成交 → 写入分成（幂等）
                if ($appRow) {
                    YxDeveloper::creditSale($appRow, $orderId, $total);
                }
                break;

            case 'subsite':
                // 分站开通/续费到账 → 激活下级分站
                $meta    = json_decode((string)$order['note'], true) ?: [];
                $childId = (int)($meta['child_id'] ?? 0);
                if ($childId > 0) {
                    $plan  = YxSubsite::plan((int)($meta['plan_id'] ?? 0));
                    $days  = $plan ? (int)$plan['duration_days'] : 365;
                    YxSubsite::activate($childId, $days);
                }
                break;

            case 'deposit':
                // 开发者保证金到账
                YxDeveloper::markDepositPaid($orderId, $tradeNo, $method);
                break;

            case 'edition':
                // 授权版本购买到账 → 签发域名授权
                $meta    = json_decode((string)$order['note'], true) ?: [];
                $domain  = normalize_domain((string)($meta['domain'] ?? ''));
                $edition = in_array($meta['edition'] ?? '', ['basic', 'pro', 'enterprise'], true) ? $meta['edition'] : 'basic';
                $days    = (int)($meta['days'] ?? 0);
                $expire  = $days > 0 ? date('Y-m-d', strtotime('+' . $days . ' day')) : null;
                if ($domain !== '') {
                    $key    = YxLicense::generateKey($domain, $edition, (string)($expire ?? ''));
                    $exists = YxDb::one('SELECT * FROM ' . YxDb::table('license') . ' WHERE domain = ?', [$domain]);
                    $ldata  = [
                        'domain' => $domain, 'license_key' => $key, 'edition' => $edition,
                        'type' => 'official', 'status' => 'active', 'expire_at' => $expire, 'bound_at' => now(),
                    ];
                    if ($exists) {
                        YxDb::update('license', $ldata, 'id = :id', ['id' => (int)$exists['id']]);
                    } else {
                        $ldata['created_at'] = now();
                        YxDb::insert('license', $ldata);
                    }
                }
                break;
        }

        // 营业收入登记（与支付方式无关）：
        //   - 任意支付方式下，订单交付成功即收入实现，记一条站方收入（customer_id = 0）
        //   - 充值只形成预收（客户钱包入账，customer_id > 0 的 income），交付时不算收入
        //   - 保证金属于负债，退款时还要付出，不计入营业收入
        // 客户钱包流水（customer_id > 0）与站方账（customer_id = 0）是两个账本：
        // 报表只统计站方账，否则充值会被算成营收、客户消费会被算成成本。
        if (!in_array((string)$order['type'], ['recharge', 'deposit'], true)) {
            if (!self::bookOrderIncome($order, $total, $method)) {
                return ['ok' => false, 'msg' => '订单收入登记失败'];
            }
        }

        // 分站佣金
        $subsiteId = (int)($order['subsite_id'] ?? 0);
        if ((string)$order['type'] === 'subsite') {
            // 开通/续费订单：佣金应从「被开通的分站」向其上链结算，
            // order.subsite_id 记录的是付款方所属上级（用于分站后台展示），不能作为结算起点。
            $subsiteMeta = json_decode((string)$order['note'], true) ?: [];
            $subsiteId   = (int)($subsiteMeta['child_id'] ?? 0);
            if ($subsiteId > 0) {
                YxSubsite::settleCommission($order, $subsiteId);
            }
        } elseif ($subsiteId > 0 && in_array((string)$order['type'], self::SUBSITE_SETTLE_TYPES, true)) {
            // 客户消费订单：归属分站自身与其上级分站分别按各自比例结算
            YxSubsite::settleCommission($order, $subsiteId, true);
        }

        // 渠道佣金
        if ((int)$order['agent_id'] > 0) {
            self::payCommission((int)$order['agent_id'], $total, $orderId);
        }

        // 官方正版授权：签发《正版软件购买证明》（凭证底部加盖签发方电子公章）
        // 已含在事务内：凭证与订单交付同生共死；签发异常只记日志，不影响已完成的交付
        if (in_array((string)$order['type'], ['app', 'edition'], true) && class_exists('YxCertificate')) {
            try {
                YxCertificate::issueForOrder($orderId);
            } catch (Throwable $e) {
                log_action('certificate.issue_error', 'order#' . $orderId, ['error' => $e->getMessage()]);
            }
        }

        // 通知下游（订单来自开放接口时）
        if (!empty($order['appid'])) {
            $svc = (int)$order['service_id'] > 0
                ? YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [(int)$order['service_id']])
                : null;
            YxApi::notify((string)$order['appid'], 'order.paid', [
                'order_sn'       => $order['sn'],
                'total'          => (float)$order['total'],
                'status'         => 'paid',
                'service_sn'     => $svc['sn'] ?? null,
                'service_status' => $svc['status'] ?? null,
                'remote_id'      => $svc['remote_id'] ?? null,
                'external_sn'    => $order['external_sn'],
                'paid_at'        => now(),
            ]);
        }

        return ['ok' => true, 'msg' => '支付成功'];
    }

    /**
     * 登记订单营业收入流水（调用方需排除 recharge / deposit）
     *
     * 站方账口径：customer_id = 0，不动任何客户钱包余额。
     */
    private static function bookOrderIncome(array $order, float $total, string $method): bool
    {
        $total = round_money($total);
        if ($total <= 0) {
            return true;
        }
        $labels = [
            'new'      => '新购收入',
            'renew'    => '续费收入',
            'upstream' => '上游业务收入',
            'service'  => '服务收入',
            'app'      => '应用销售收入',
            'subsite'  => '分站收入',
            'edition'  => '授权版本收入',
        ];
        $label = $labels[(string)$order['type']] ?? '订单收入';
        $res   = YxFinance::bookIncome($total, 'order', [
            'method'   => $method,
            'ref_type' => 'order',
            'ref_id'   => (int)$order['id'],
            'remark'   => $label . ' ' . $order['sn'],
            'operator' => 'system',
        ]);
        return !empty($res['ok']);
    }

    /** 渠道佣金结算 */
    public static function payCommission(int $agentId, float $amount, int $orderId): void
    {
        $agent = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$agentId]);
        if (!$agent || (float)$agent['commission_rate'] <= 0) {
            return;
        }
        $commission = round_money($amount * (float)$agent['commission_rate'] / 100);
        if ($commission <= 0) {
            return;
        }
        YxDb::exec('UPDATE ' . YxDb::table('agent') . ' SET balance = balance + ? WHERE id = ?', [$commission, $agentId]);
        $balanceAfter = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$agentId]);
        YxDb::insert('transaction', [
            'sn'            => make_sn('TXC', 'transaction'),
            'customer_id'   => 0,
            'direction'     => 'expense',
            'category'      => 'agent_commission',
            'amount'        => $commission,
            'balance_after' => $balanceAfter,
            'method'        => 'internal',
            'ref_type'      => 'agent',
            'ref_id'        => $agentId,
            'operator'      => 'system',
            'remark'        => '渠道佣金（订单#' . $orderId . '）',
            'created_at'    => now(),
        ]);
    }

    /* ------------------------------ 应用安装 ------------------------------ */

    /**
     * 生成应用安装授权码（应用 + 域名绑定）
     *
     * 主程序包（正版 / 开源）例外：它的授权码要能在买家「尚未安装」的空站点上
     * 离线复核，那里没有数据库也没有 app.key，因此走发行包固定的产品密钥
     * （YxLicense::packageKey），与站点级密钥无关。其余应用仍按 app.key 签发。
     */
    public static function appLicenseKey(string $appKey, string $domain): string
    {
        if (in_array($appKey, self::PACKAGE_APP_KEYS, true)) {
            $product = $appKey === 'youxiang-finance-open' ? 'community' : 'official';
            return YxLicense::packageKey($domain, $product);
        }
        $secret = (string)config('app.key', 'youxiang');
        $raw    = strtoupper(hash_hmac('sha256', $appKey . '|' . normalize_domain($domain), $secret));
        return implode('-', str_split(substr($raw, 0, 16), 4));
    }

    /**
     * 授权码脱敏（保留首尾各 4 位）
     *
     * 只用于「非有效权益持有者」的展示场景：截图、分享页面、已过期/已停用记录
     * 都不该把完整授权码摊开，否则未付费用户也能从他人页面照抄。
     * 有效权益持有者在「我的授权」里仍看到完整码（需复制去安装）。
     */
    public static function maskLicenseKey(string $key): string
    {
        $key = strtoupper(preg_replace('/\s+/', '', trim($key)) ?? '');
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 9) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 4) . '-****-****-' . substr($key, -4);
    }

    /** 应用的计费方式（兼容旧数据：有价格即一次性） */
    public static function billType(array $app): string
    {
        $t = (string)($app['bill_type'] ?? '');
        if (in_array($t, ['once', 'year', 'points'], true)) {
            return $t;
        }
        return (float)($app['price'] ?? 0) > 0 ? 'once' : 'free';
    }

    public static function billName(string $t): string
    {
        return ['free' => '免费', 'once' => '一次性', 'year' => '按年', 'points' => '积分'][$t] ?? $t;
    }

    /** 定价标签（纯文本，模板自行转义） */
    public static function priceLabel(array $app): string
    {
        // 定价为 0 的应用（含免费赠送的按次 / 按年应用）统一显示「免费」，避免出现「¥0.00/年」
        if (self::billType($app) !== 'points' && (float)($app['price'] ?? 0) <= 0) {
            return '免费';
        }
        switch (self::billType($app)) {
            case 'once':   return money($app['price'], true) . ' 一次性';
            case 'year':   return money($app['price'], true) . '/年';
            case 'points': return (int)($app['points_price'] ?? 0) . ' 积分';
            default:       return '免费';
        }
    }

    /** 依据计费方式计算授权/权益到期日 */
    public static function appExpire(array $app): ?string
    {
        $bill = self::billType($app);
        // 一次性与积分 = 永久有效（到期日留空；NULL 即「永久」口径，代码里已统一按此处理）；
        // 按年与免费默认一年
        if ($bill === 'year' || $bill === 'free') {
            return date('Y-m-d', strtotime('+1 year'));
        }
        return null;
    }

    /**
     * 再次获取 / 续期时的到期日
     *
     * 同一域名重复购买（应用）或重复获取（资源）时：既不覆盖成「从今天起算」而
     * 白白丢掉剩余时长，也不能像旧逻辑那样完全不更新——那样权益过期后重新付费，
     * 到期日仍停留在过去，付款后依旧不可用。
     *
     * 规则：按年 / 免费的按周期在现有到期日上顺延；一次性 / 积分属长期授权，取更晚的一方。
     */
    public static function appExpireAfter(array $app, ?string $current): ?string
    {
        $bill  = self::billType($app);
        $fresh = self::appExpire($app);
        $keep  = trim((string)$current);
        if ($keep === '') {
            return $fresh;
        }
        if ($bill === 'year' || $bill === 'free') {
            // 仍在有效期内 → 在原到期日上顺延一个周期；已过期 → 从今天重新起算
            if ($keep >= date('Y-m-d')) {
                $next = date('Y-m-d', strtotime($keep . ' +1 year'));
                return $next > $fresh ? $next : $fresh;
            }
            return $fresh;
        }
        // 一次性 / 积分 = 永久：无论旧记录带什么到期日，统一写成永久
        if ($fresh === null) {
            return null;
        }
        return $keep > $fresh ? $keep : $fresh;
    }

    /** 客户对某应用/资源的有效权益（启用且未过期） */
    public static function entitlement(int $appId, int $customerId): ?array
    {
        if ($appId <= 0 || $customerId <= 0) {
            return null;
        }
        $row = YxDb::one(
            'SELECT * FROM ' . YxDb::table('app_install') . " WHERE app_id = ? AND customer_id = ? AND status = 'enabled' ORDER BY id DESC LIMIT 1",
            [$appId, $customerId]
        );
        if (!$row) {
            return null;
        }
        if (!empty($row['expire_at']) && $row['expire_at'] < date('Y-m-d')) {
            return null;
        }
        return $row;
    }

    /** 为资源型应用授予下载权益（domain=resource） */
    public static function grantResource(array $app, int $customerId, int $orderId = 0): array
    {
        $exists = YxDb::one(
            'SELECT * FROM ' . YxDb::table('app_install') . ' WHERE app_id = ? AND customer_id = ? AND domain = ? LIMIT 1',
            [(int)$app['id'], $customerId, 'resource']
        );
        if ($exists) {
            $expire = self::appExpireAfter($app, $exists['expire_at'] ?? null);
            YxDb::update('app_install', [
                'app_key'   => $app['key'],
                'status'    => 'enabled',
                'expire_at' => $expire,
                'order_id'  => $orderId > 0 ? $orderId : (int)$exists['order_id'],
            ], 'id = :id', ['id' => (int)$exists['id']]);
        } else {
            $expire = self::appExpire($app);
            YxDb::insert('app_install', [
                'app_id'       => (int)$app['id'],
                'app_key'      => $app['key'],
                'customer_id'  => $customerId,
                'domain'       => 'resource',
                'license_key'  => '',
                'order_id'     => $orderId,
                'status'       => 'enabled',
                'expire_at'    => $expire,
                'installed_at' => now(),
            ]);
            YxDb::exec('UPDATE ' . YxDb::table('app') . ' SET sales = sales + 1 WHERE id = ?', [(int)$app['id']]);
        }
        return ['ok' => true, 'msg' => '已获取', 'expire_at' => $expire];
    }

    public static function installApp(array $data): array
    {
        $app = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [(int)$data['app_id']]);
        if (!$app) {
            return ['ok' => false, 'msg' => '应用不存在'];
        }
        $domain = normalize_domain((string)($data['domain'] ?? ''));
        if ($domain === '') {
            $domain = 'local-' . ($data['customer_id'] ?? 0) . '.local';
        }

        // 归属必须带上客户：只按域名匹配会把别人名下的授权记录「接管」过来，
        // 付款方在自己的「我的授权」里反而看不到任何记录。
        $exists = YxDb::one(
            'SELECT * FROM ' . YxDb::table('app_install') . ' WHERE app_id = ? AND customer_id = ? AND domain = ? LIMIT 1',
            [(int)$app['id'], (int)($data['customer_id'] ?? 0), $domain]
        );
        $licenseKey = self::appLicenseKey((string)$app['key'], $domain);
        if ($exists) {
            // 同一域名再次购买（或权益过期后重新购买）：重新启用并顺延到期日，
            // 否则付费续期后到期日仍停在过去，客户付款却拿不到可用授权。
            $expire = self::appExpireAfter($app, $exists['expire_at'] ?? null);
            YxDb::update('app_install', [
                'app_key'     => $app['key'],
                'license_key' => $licenseKey,
                'order_id'    => (int)($data['order_id'] ?? 0) > 0 ? (int)$data['order_id'] : (int)$exists['order_id'],
                'status'      => 'enabled',
                'expire_at'   => $expire,
            ], 'id = :id', ['id' => (int)$exists['id']]);
            return ['ok' => true, 'msg' => '该域名已安装，授权已更新至 ' . $expire, 'license_key' => $licenseKey, 'expire_at' => $expire];
        }

        $expire = self::appExpire($app);
        YxDb::insert('app_install', [
            'app_id'       => (int)$app['id'],
            'app_key'      => $app['key'],
            'customer_id'  => (int)($data['customer_id'] ?? 0),
            'domain'       => $domain,
            'license_key'  => $licenseKey,
            'order_id'     => (int)($data['order_id'] ?? 0),
            'status'       => 'enabled',
            'expire_at'    => $expire,
            'installed_at' => now(),
        ]);

        YxDb::exec('UPDATE ' . YxDb::table('app') . ' SET sales = sales + 1, downloads = downloads + 1 WHERE id = ?', [(int)$app['id']]);

        return ['ok' => true, 'msg' => '应用安装成功', 'license_key' => $licenseKey, 'expire_at' => $expire];
    }

    /* ------------------------------ 工单 ------------------------------ */

    public static function createTicket(array $data): int
    {
        return YxDb::insert('ticket', [
            'sn'          => make_sn('TK', 'ticket'),
            'customer_id' => (int)($data['customer_id'] ?? 0),
            'service_id'  => (int)($data['service_id'] ?? 0),
            'department'  => $data['department'] ?? 'tech',
            'subject'     => $data['subject'] ?? '无标题',
            'priority'    => $data['priority'] ?? 'normal',
            'status'      => 'open',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public static function replyTicket(int $ticketId, string $authorType, int $authorId, string $content): array
    {
        $ticket = YxDb::one('SELECT * FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$ticketId]);
        if (!$ticket) {
            return ['ok' => false, 'msg' => '工单不存在'];
        }
        if ($ticket['status'] === 'closed' && $authorType === 'customer') {
            return ['ok' => false, 'msg' => '工单已关闭，请新建工单'];
        }
        YxDb::insert('ticket_reply', [
            'ticket_id'   => $ticketId,
            'author_type' => $authorType,
            'author_id'   => $authorId,
            'content'     => $content,
            'created_at'  => now(),
        ]);
        YxDb::exec(
            'UPDATE ' . YxDb::table('ticket') . ' SET reply_count = reply_count + 1, status = ?, admin_id = ?, updated_at = ? WHERE id = ?',
            [$authorType === 'admin' ? 'replied' : 'open', $authorType === 'admin' ? $authorId : 0, now(), $ticketId]
        );
        return ['ok' => true, 'msg' => '回复成功'];
    }

    public static function closeTicket(int $ticketId): array
    {
        $ticket = YxDb::one('SELECT id, status FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$ticketId]);
        if (!$ticket) {
            return ['ok' => false, 'msg' => '工单不存在'];
        }
        if ($ticket['status'] === 'closed') {
            return ['ok' => false, 'msg' => '工单已关闭'];
        }
        YxDb::update('ticket', ['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $ticketId]);
        return ['ok' => true, 'msg' => '工单已关闭'];
    }

    /** 到期提醒：返回 N 天内到期的业务 */
    public static function expiringServices(int $days = 7): array
    {
        return YxDb::all(
            'SELECT s.*, c.username, c.email
             FROM ' . YxDb::table('service') . ' s
             LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = s.customer_id
             WHERE s.status IN (\'active\',\'suspended\',\'expired\')
               AND s.next_due_date IS NOT NULL
               AND s.next_due_date <= ?
             ORDER BY s.next_due_date ASC',
            [date('Y-m-d', strtotime('+' . max(1, $days) . ' day'))]
        );
    }

    /** 把到期业务标记为已到期 */
    public static function markExpired(): int
    {
        return YxDb::exec(
            'UPDATE ' . YxDb::table('service') . " SET status = 'expired', updated_at = ? WHERE status = 'active' AND next_due_date IS NOT NULL AND next_due_date < ?",
            [now(), date('Y-m-d')]
        );
    }
}
