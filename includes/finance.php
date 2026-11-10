<?php
/**
 * 优享财务系统 · 财务核心（余额 / 流水 / 账单 / 支付 / 报表）
 *
 * 所有余额变更均在同一数据库事务内完成，并通过带条件的 UPDATE 保证不会扣成负数。
 */

class YxFinance
{
    /**
     * 入账：增加客户余额（充值、退款、转入）
     */
    public static function credit(int $customerId, float $amount, string $category, array $opt = []): array
    {
        $amount = round_money($amount);
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '金额必须大于 0'];
        }

        // 已在事务中（如支付交付）时并入外层事务，避免内层 commit 提前提交半成品
        $owner = !YxDb::pdo()->inTransaction();
        try {
            if ($owner) {
                YxDb::begin();
            }
            YxDb::exec('UPDATE ' . YxDb::table('customer') . ' SET balance = balance + ? WHERE id = ?', [$amount, $customerId]);
            $balance = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);

            $sn = make_sn('TX', 'transaction');
            YxDb::insert('transaction', [
                'sn'            => $sn,
                'customer_id'   => $customerId,
                'direction'     => 'income',
                'category'      => $category,
                'amount'        => $amount,
                'balance_after' => $balance,
                'method'        => $opt['method'] ?? null,
                'ref_type'      => $opt['ref_type'] ?? null,
                'ref_id'        => (int)($opt['ref_id'] ?? 0),
                'operator'      => $opt['operator'] ?? 'system',
                'remark'        => $opt['remark'] ?? null,
                'created_at'    => now(),
            ]);
            if ($owner) {
                YxDb::commit();
            }
            return ['ok' => true, 'msg' => '入账成功', 'balance' => $balance, 'sn' => $sn];
        } catch (Throwable $e) {
            if ($owner) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '入账失败：' . $e->getMessage()];
        }
    }

    /**
     * 出账：扣减客户余额，余额不足直接失败
     */
    public static function debit(int $customerId, float $amount, string $category, array $opt = []): array
    {
        $amount = round_money($amount);
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '金额必须大于 0'];
        }

        $owner = !YxDb::pdo()->inTransaction();
        try {
            if ($owner) {
                YxDb::begin();
            }
            $affected = YxDb::exec(
                'UPDATE ' . YxDb::table('customer') . ' SET balance = balance - ? WHERE id = ? AND balance >= ?',
                [$amount, $customerId, $amount]
            );
            if ($affected < 1) {
                if ($owner) {
                    YxDb::rollback();
                }
                return ['ok' => false, 'msg' => '余额不足，请先充值'];
            }
            $balance = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);

            $sn = make_sn('TX', 'transaction');
            YxDb::insert('transaction', [
                'sn'            => $sn,
                'customer_id'   => $customerId,
                'direction'     => 'expense',
                'category'      => $category,
                'amount'        => $amount,
                'balance_after' => $balance,
                'method'        => $opt['method'] ?? 'balance',
                'ref_type'      => $opt['ref_type'] ?? null,
                'ref_id'        => (int)($opt['ref_id'] ?? 0),
                'operator'      => $opt['operator'] ?? 'system',
                'remark'        => $opt['remark'] ?? null,
                'created_at'    => now(),
            ]);
            if ($owner) {
                YxDb::commit();
            }
            return ['ok' => true, 'msg' => '扣款成功', 'balance' => $balance, 'sn' => $sn];
        } catch (Throwable $e) {
            if ($owner) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '扣款失败：' . $e->getMessage()];
        }
    }

    /**
     * 站方支出流水（不关联客户余额，如机房成本、分成支出）
     */
    public static function bookExpense(float $amount, string $category, string $remark = '', string $operator = 'admin'): array
    {
        $amount = round_money($amount);
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '金额必须大于 0'];
        }
        $owner = !YxDb::pdo()->inTransaction();
        try {
            if ($owner) {
                YxDb::begin();
            }
            $sn = make_sn('TXX', 'transaction');
            YxDb::insert('transaction', [
                'sn'            => $sn,
                'customer_id'   => 0,
                'direction'     => 'expense',
                'category'      => $category,
                'amount'        => $amount,
                'balance_after' => 0,
                'method'        => 'manual',
                'ref_type'      => 'manual',
                'ref_id'        => 0,
                'operator'      => $operator,
                'remark'        => $remark,
                'created_at'    => now(),
            ]);
            if ($owner) {
                YxDb::commit();
            }
            return ['ok' => true, 'msg' => '已登记支出', 'sn' => $sn];
        } catch (Throwable $e) {
            if ($owner) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '登记失败：' . $e->getMessage()];
        }
    }

    /**
     * 站方营业收入登记（买断/消费型订单交付时调用）
     *
     * 与 credit() 的区别：这里只记站方账（customer_id = 0），不触碰任何客户钱包余额，
     * 也不要求客户存在（站方收入与具体客户钱包无关）。
     */
    public static function bookIncome(float $amount, string $category, array $opt = []): array
    {
        $amount = round_money($amount);
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '金额必须大于 0'];
        }
        $owner = !YxDb::pdo()->inTransaction();
        try {
            if ($owner) {
                YxDb::begin();
            }
            $sn = make_sn('TX', 'transaction');
            YxDb::insert('transaction', [
                'sn'            => $sn,
                'customer_id'   => 0,
                'direction'     => 'income',
                'category'      => $category,
                'amount'        => $amount,
                'balance_after' => 0,
                'method'        => $opt['method'] ?? null,
                'ref_type'      => $opt['ref_type'] ?? null,
                'ref_id'        => (int)($opt['ref_id'] ?? 0),
                'operator'      => $opt['operator'] ?? 'system',
                'remark'        => $opt['remark'] ?? null,
                'created_at'    => now(),
            ]);
            if ($owner) {
                YxDb::commit();
            }
            return ['ok' => true, 'msg' => '已登记收入', 'sn' => $sn];
        } catch (Throwable $e) {
            if ($owner) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '登记失败：' . $e->getMessage()];
        }
    }

    /**
     * 为客户生成账单
     */
    public static function createInvoice(array $data): int
    {
        $sn = make_sn(config('finance.invoice_prefix', 'INV'), 'invoice');
        return YxDb::insert('invoice', [
            'sn'          => $sn,
            'customer_id' => (int)($data['customer_id'] ?? 0),
            'order_id'    => (int)($data['order_id'] ?? 0),
            'service_id'  => (int)($data['service_id'] ?? 0),
            'title'       => $data['title'] ?? '业务账单',
            'amount'      => round_money($data['amount'] ?? 0),
            'paid_amount' => round_money($data['paid_amount'] ?? 0),
            'type'        => $data['type'] ?? 'service',
            'status'      => $data['status'] ?? 'unpaid',
            'due_date'    => $data['due_date'] ?? date('Y-m-d', strtotime('+7 day')),
            'issued_at'   => now(),
            'remark'      => $data['remark'] ?? null,
        ]);
    }

    /**
     * 结清账单
     *
     * 用「带条件的原子累加」而不是先读后写：
     *   - 两个管理员同时点「结清」时只有一个能改到未结清状态，避免重复核销
     *   - paid_amount 被夹在 amount 以内，不会出现已收金额超过账单金额
     *
     * 返回是否真的发生了结清动作。
     */
    public static function payInvoice(int $invoiceId, float $amount): bool
    {
        $amount = round_money($amount);
        if ($invoiceId <= 0 || $amount <= 0) {
            return false;
        }
        $table = YxDb::table('invoice');

        // 累加后封顶到账单金额。所有 SET 表达式都读旧值，
        // 因此 status / paid_at 的判断可以直接用 paid_amount + ? 推演。
        $n = YxDb::exec(
            'UPDATE ' . $table . '
                SET paid_amount = CASE WHEN paid_amount + ? >= amount THEN amount ELSE paid_amount + ? END,
                    status      = CASE WHEN paid_amount + ? >= amount THEN ? ELSE ? END,
                    paid_at     = CASE WHEN paid_amount + ? >= amount THEN ? ELSE paid_at END
              WHERE id = ? AND status IN (?, ?)',
            [$amount, $amount, $amount, 'paid', 'partial', $amount, now(), $invoiceId, 'unpaid', 'partial']
        );
        return $n > 0;
    }

    /**
     * 记录支付流水（网关/余额）
     */
    public static function recordPayment(array $data): int
    {
        return YxDb::insert('payment', [
            'order_id'    => (int)($data['order_id'] ?? 0),
            'order_sn'    => $data['order_sn'] ?? null,
            'customer_id' => (int)($data['customer_id'] ?? 0),
            'gateway'     => $data['gateway'] ?? null,
            'trade_no'    => $data['trade_no'] ?? null,
            'amount'      => round_money($data['amount'] ?? 0),
            'status'      => $data['status'] ?? 'pending',
            'raw'         => isset($data['raw']) ? json_encode($data['raw'], JSON_UNESCAPED_UNICODE) : null,
            'created_at'  => now(),
            'paid_at'     => ($data['status'] ?? '') === 'paid' ? now() : null,
        ]);
    }

    /* ------------------------------ 报表 ------------------------------ */
    /*
     * 报表统计的是「站方账」，只取 customer_id = 0 的流水：
     *   - customer_id > 0 的是客户钱包账本（充值/退款入账、余额消费），
     *     把这些算进报表会让收入与支出同时虚增：充值被当成营收，客户消费被当成成本。
     *   - 客户钱包账本的余额口径由 walletTotals() 单独提供。
     */

    /** 期间收支汇总 */
    public static function summary(string $from, string $to): array
    {
        $row = YxDb::one(
            'SELECT
                COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) AS expense
             FROM ' . YxDb::table('transaction') . '
             WHERE customer_id = 0 AND created_at >= ? AND created_at <= ?',
            ['income', 'expense', $from . ' 00:00:00', $to . ' 23:59:59']
        );
        $income  = (float)($row['income'] ?? 0);
        $expense = (float)($row['expense'] ?? 0);
        return [
            'income'  => $income,
            'expense' => $expense,
            'profit'  => round_money($income - $expense),
        ];
    }

    /** 按分类汇总收入 */
    public static function incomeByCategory(string $from, string $to): array
    {
        return YxDb::all(
            'SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
             FROM ' . YxDb::table('transaction') . '
             WHERE customer_id = 0 AND direction = ? AND created_at >= ? AND created_at <= ?
             GROUP BY category ORDER BY total DESC',
            ['income', $from . ' 00:00:00', $to . ' 23:59:59']
        );
    }

    /** 近 N 日趋势 */
    public static function trend(int $days = 7): array
    {
        $days  = max(1, min(90, $days));
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
        $rows  = YxDb::all(
            'SELECT substr(created_at, 1, 10) AS d,
                    SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) AS expense
             FROM ' . YxDb::table('transaction') . '
             WHERE customer_id = 0 AND created_at >= ?
             GROUP BY d',
            ['income', 'expense', $start . ' 00:00:00']
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' day'));
            $out[] = [
                'date'    => $d,
                'label'   => date('m-d', strtotime($d)),
                'income'  => (float)($map[$d]['income'] ?? 0),
                'expense' => (float)($map[$d]['expense'] ?? 0),
            ];
        }
        return $out;
    }

    /** 客户钱包总览 */
    public static function walletTotals(): array
    {
        return [
            'balance' => (float)YxDb::value('SELECT COALESCE(SUM(balance),0) FROM ' . YxDb::table('customer') . ' WHERE status = 1'),
            'credit'  => (float)YxDb::value('SELECT COALESCE(SUM(credit),0) FROM ' . YxDb::table('customer') . ' WHERE status = 1'),
        ];
    }

    /** 未收款金额 */
    public static function receivable(): float
    {
        return (float)YxDb::value(
            'SELECT COALESCE(SUM(amount - paid_amount),0) FROM ' . YxDb::table('invoice') . " WHERE status IN ('unpaid','partial')"
        );
    }
}
