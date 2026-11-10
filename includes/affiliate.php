<?php
/**
 * 优享财务系统 · 代理中心扩展（用户中心「代理中心」下拉框内的 API 管理 / 推广计划）
 *
 * 两部分能力：
 *  1. API 管理（下游对接开关）
 *     - 按客户维度开关：customer.api_enabled，同意协议时间记 api_agree_at；
 *     - 开关开启后客户可自助创建 AppID / AppSecret（user/api.php 的同一套数据），
 *       并可填写自己的上游地址，实现「上游进货 → 本站 → 下游分销」的两级对接。
 *  2. 推广计划（Affiliates）
 *     - 按订单实付金额计提佣金，比例取后台设置 affiliate_rate（百分比，默认 10%）；
 *     - 订单首次支付成功时写入 publisher_id 与快照佣金（affiliate_commission / affiliate_settled_at）；
 *     - 支持后台批量结算：写入 yx_transaction 支出流水，同时更新客户累计结款。
 *
 * 佣金一律以「元」为单位、按分四舍五入；比例以小数存放（0.1000 = 10%）。
 */

class YxAffiliate
{
    /* ------------------------------------------------------------------ 设置键 */

    const SET_RATE     = 'affiliate_rate';         // 推广计划佣金比例（%）
    const SET_ENABLED  = 'affiliate_enabled';      // 推广计划总开关 1/0
    const SET_MINPAY   = 'affiliate_min_pay';      // 申请结款门槛（元）

    /* ------------------------------------------------------------------ API 对接开关 */

    /** 客户当前是否已开启 API 对接（库内为准，会话数据可能过期） */
    public static function apiOn(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        return (int)YxDb::value(
            'SELECT api_enabled FROM ' . YxDb::table('customer') . ' WHERE id = ?',
            [$customerId]
        ) === 1;
    }

    /** 开启时间 / 同意协议时间 */
    public static function apiAgreeAt(int $customerId): string
    {
        return (string)(YxDb::value(
            'SELECT api_agree_at FROM ' . YxDb::table('customer') . ' WHERE id = ?',
            [$customerId]
        ) ?: '');
    }

    /**
     * 开启 API 对接（必须勾选协议）
     * 幂等：已开启时直接返回成功，不覆盖首次同意时间。
     */
    public static function enableApi(int $customerId, bool $agree): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '请先登录'];
        }
        if (!$agree) {
            return ['ok' => false, 'msg' => '请先阅读并勾选同意《优享财务系统 API 对接服务协议》'];
        }
        if (self::apiOn($customerId)) {
            return ['ok' => true, 'msg' => 'API 对接功能已开启，无需重复操作'];
        }
        if (!YxEdition::can('openapi')) {
            return ['ok' => false, 'msg' => '当前站点版本未开放 API 对接，请联系平台升级'];
        }
        YxDb::update('customer', [
            'api_enabled'  => 1,
            'api_agree_at' => now(),
        ], 'id = :id', ['id' => $customerId]);
        log_action('customer.api_enable', 'customer#' . $customerId, ['agree' => true]);
        return ['ok' => true, 'msg' => 'API 对接功能已开启，可以创建应用并获取 AppID / AppSecret 了'];
    }

    /** 关闭 API 对接（已创建的应用会被一并停用） */
    public static function disableApi(int $customerId): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '请先登录'];
        }
        YxDb::update('customer', ['api_enabled' => 0], 'id = :id', ['id' => $customerId]);
        YxDb::exec(
            'UPDATE ' . YxDb::table('api_app') . " SET status = 0 WHERE owner_type = 'customer' AND owner_id = ?",
            [$customerId]
        );
        log_action('customer.api_disable', 'customer#' . $customerId);
        return ['ok' => true, 'msg' => 'API 对接功能已关闭，原有应用密钥已停用'];
    }

    /** 客户名下的接口应用（含调用统计） */
    public static function apiApps(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('api_app') . " WHERE owner_type = 'customer' AND owner_id = ? ORDER BY id DESC",
            [$customerId]
        );
    }

    /** 客户名下最近调用记录 */
    public static function apiLogs(int $customerId, int $limit = 15): array
    {
        $apps = self::apiApps($customerId);
        $ids  = array_column($apps, 'appid');
        if (!$ids) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('api_log') . " WHERE appid IN ($place) ORDER BY id DESC LIMIT " . max(1, $limit),
            $ids
        );
    }

    /* ------------------------------------------------------------------ 推广计划 */

    /** 推广计划是否开放（后台开关，默认开启以符合产品预期） */
    public static function enabled(): bool
    {
        return trim((string)setting(self::SET_ENABLED, '1')) === '1';
    }

    /** 佣金比例（小数），后台设置单位为百分比 */
    public static function rate(): float
    {
        $p = (float)setting(self::SET_RATE, 10);
        if ($p < 0) {
            $p = 0;
        }
        if ($p > 90) {
            $p = 90;
        }
        return round($p / 100, 4);
    }

    public static function rateText(?float $rate = null): string
    {
        $p = round(($rate === null ? self::rate() : $rate) * 100, 2);
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';
    }

    /** 结款门槛（元） */
    public static function minPay(): float
    {
        $v = (float)setting(self::SET_MINPAY, 100);
        return $v < 0 ? 0 : round_money($v);
    }

    /** 订单实付金额对应的佣金（按分四舍五入） */
    public static function commissionOf(float $amount, ?float $rate = null): float
    {
        return round_money($amount * ($rate === null ? self::rate() : $rate));
    }

    /**
     * 推广码：客户 ID + 校验位，避免被枚举
     * 与推广商（promoter）的 pageKey 相互独立，互不通用。
     */
    public static function code(array $customer): string
    {
        $no = (int)($customer['user_no'] ?? 0);
        $id = (int)$customer['id'];
        $key = $no > 0 ? (string)$no : (string)$id;
        $salt = (string)config('app.key', 'yx-affiliate');
        return $key . '-' . substr(hash_hmac('sha256', 'affiliate:' . $id, $salt), 0, 6);
    }

    /** 由推广码反解客户 ID，非法返回 0 */
    public static function customerIdFromCode(string $code): int
    {
        if (!preg_match('/^(\d{1,10})-([0-9a-f]{6})$/', trim($code), $m)) {
            return 0;
        }
        $col  = strlen($m[1]) === 5 ? 'user_no' : 'id';
        $row  = YxDb::one('SELECT id, user_no FROM ' . YxDb::table('customer') . " WHERE {$col} = ?", [(int)$m[1]]);
        if (!$row) {
            return 0;
        }
        $salt = (string)config('app.key', 'yx-affiliate');
        $want = substr(hash_hmac('sha256', 'affiliate:' . (int)$row['id'], $salt), 0, 6);
        return hash_equals($want, $m[2]) ? (int)$row['id'] : 0;
    }

    /** 邀请链接（带推广码，落地门户首页） */
    public static function inviteUrl(array $customer): string
    {
        return url('index.php?aff=' . rawurlencode(self::code($customer)));
    }

    /** 推广码 cookie 名与有效期（天） */
    const COOKIE = 'yx_aff';
    const COOKIE_DAYS = 30;

    /**
     * 全站推广落地：任意页面带 ?aff= 时记录推广码
     *
     * 邀请链接落地在门户首页，访客通常还要浏览产品页再点「免费注册」，
     * 而注册入口的链接不带 aff。只在注册页读取 ?aff= 会让推广关系丢失，
     * 所以由引导文件统一处理：校验有效后写入 cookie，供注册时归属推广人。
     * 已有有效 cookie 时不覆盖（首次来源优先）。
     */
    public static function captureVisitor(): void
    {
        if (!isset($_GET['aff'])) {
            return;
        }
        $code = trim((string)$_GET['aff']);
        if ($code === '') {
            return;
        }
        // 已记录过有效来源：保持首次归属，不做覆盖
        $cur = trim((string)($_COOKIE[self::COOKIE] ?? ''));
        if ($cur !== '' && self::customerIdFromCode($cur) > 0) {
            return;
        }
        if (self::customerIdFromCode($code) <= 0 || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $code, [
            'expires'  => time() + 86400 * self::COOKIE_DAYS,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $code;
    }

    /**
     * 记录访客来源：注册时写入 customer.publisher_id
     * 只接受有效推广码、非本人、且注册客户此前未被记录过（首次来源优先）。
     */
    public static function bindPublisher(int $customerId, string $code): bool
    {
        if ($customerId <= 0 || $code === '') {
            return false;
        }
        $publisher = self::customerIdFromCode($code);
        if ($publisher <= 0 || $publisher === $customerId) {
            return false;
        }
        $cur = YxDb::value('SELECT publisher_id FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);
        if ((int)$cur > 0) {
            return false;
        }
        YxDb::update('customer', ['publisher_id' => $publisher], 'id = :id', ['id' => $customerId]);
        log_action('affiliate.bind', 'customer#' . $customerId, ['publisher' => $publisher]);
        return true;
    }

    public static function publisherId(int $customerId): int
    {
        return (int)YxDb::value('SELECT publisher_id FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);
    }

    /** 推广统计 */
    public static function stats(int $customerId): array
    {
        $tC = YxDb::table('customer');
        $tO = YxDb::table('order');
        $referrals = (int)YxDb::value("SELECT COUNT(*) FROM {$tC} WHERE publisher_id = ?", [$customerId]);
        $agg = YxDb::one(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN affiliate_settled_at IS NULL THEN affiliate_commission ELSE 0 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN affiliate_settled_at IS NOT NULL THEN affiliate_commission ELSE 0 END), 0) AS settled,
                    COALESCE(SUM(total), 0) AS total
               FROM {$tO}
              WHERE publisher_id = ? AND affiliate_commission > 0",
            [$customerId]
        );
        $thisMonth = (float)YxDb::value(
            "SELECT COALESCE(SUM(affiliate_commission), 0) FROM {$tO}
              WHERE publisher_id = ? AND affiliate_commission > 0 AND paid_at >= ?",
            [$customerId, date('Y-m-01 00:00:00')]
        );
        return [
            'referrals' => $referrals,
            'orders'    => (int)($agg['cnt'] ?? 0),
            'gmv'       => round_money((float)($agg['total'] ?? 0)),
            'pending'   => round_money((float)($agg['pending'] ?? 0)),
            'settled'   => round_money((float)($agg['settled'] ?? 0)),
            'month'     => round_money($thisMonth),
            'earned'    => round_money((float)($agg['pending'] ?? 0) + (float)($agg['settled'] ?? 0)),
        ];
    }

    /** 佣金明细（只读展示，金额不与客户本人可见的隐私字段混用） */
    public static function commissions(int $customerId, int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE publisher_id = ? AND affiliate_commission > 0';
        $total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . $where, [$customerId]);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = YxDb::all(
            'SELECT id, sn, name, total, paid_at, affiliate_rate, affiliate_commission, affiliate_settled_at
               FROM ' . YxDb::table('order') . $where
            . ' ORDER BY id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . $offset,
            [$customerId]
        );
        return ['total' => $total, 'items' => $rows];
    }

    /** 推广客户列表（不含登录凭据） */
    public static function referrals(int $customerId, int $limit = 50): array
    {
        return YxDb::all(
            'SELECT id, user_no, username, created_at, realname_status
               FROM ' . YxDb::table('customer') . ' WHERE publisher_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$customerId]
        );
    }

    /**
     * 订单支付成功时计提佣金（由支付回调调用，必须幂等）
     *
     * 已有佣金的订单不重复计提；推广人与买家为同一账号时不计提。
     */
    public static function accrueOrder(int $orderId): array
    {
        $order = YxDb::one(
            'SELECT id, sn, total, status, paid_at, customer_id, publisher_id, affiliate_commission
               FROM ' . YxDb::table('order') . ' WHERE id = ?',
            [$orderId]
        );
        if (!$order) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if ((string)$order['status'] !== 'paid') {
            return ['ok' => false, 'msg' => '订单未支付，不计佣金'];
        }
        if ((float)$order['affiliate_commission'] > 0) {
            return ['ok' => true, 'msg' => '已计提，跳过', 'repeat' => true];
        }
        $publisher = (int)$order['publisher_id'];
        if ($publisher <= 0 || $publisher === (int)$order['customer_id']) {
            return ['ok' => false, 'msg' => '无有效推广人'];
        }
        $rate  = self::rate();
        $money = self::commissionOf((float)$order['total'], $rate);
        if ($money <= 0) {
            return ['ok' => false, 'msg' => '佣金为 0，跳过'];
        }
        $affected = YxDb::exec(
            'UPDATE ' . YxDb::table('order')
            . ' SET affiliate_rate = ?, affiliate_commission = ?'
            . ' WHERE id = ? AND (affiliate_commission IS NULL OR affiliate_commission = 0)',
            [$rate, $money, $orderId]
        );
        if ($affected < 1) {
            return ['ok' => true, 'msg' => '并发计提，已跳过', 'repeat' => true];
        }
        log_action('affiliate.accrue', 'order#' . $orderId, ['publisher' => $publisher, 'commission' => $money]);
        return ['ok' => true, 'msg' => '已计提佣金 ' . money($money, true), 'commission' => $money];
    }

    /**
     * 后台批量结款：把「已计提未结款」的订单标记为已结款并登记支出流水
     *
     * 支出从站方收入（bookIncome 的对侧）出账，不触碰客户钱包余额。
     */
    public static function settle(int $customerId, int $adminId = 0): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '推广人不存在'];
        }
        $tO = YxDb::table('order');
        $rows = YxDb::all(
            "SELECT id, sn, affiliate_commission FROM {$tO}
              WHERE publisher_id = ? AND affiliate_commission > 0 AND affiliate_settled_at IS NULL",
            [$customerId]
        );
        if (!$rows) {
            return ['ok' => false, 'msg' => '没有待结款的佣金'];
        }
        $sum = round_money(array_sum(array_map(function ($r) {
            return (float)$r['affiliate_commission'];
        }, $rows)));
        if ($sum <= 0) {
            return ['ok' => false, 'msg' => '没有待结款的佣金'];
        }

        // 支出登记：失败则整体回滚，避免订单已标记但账目未记
        if (!method_exists('YxFinance', 'bookExpense')) {
            return ['ok' => false, 'msg' => '财务模块缺少支出登记能力，无法结款'];
        }

        $owner = !YxDb::pdo()->inTransaction();
        try {
            if ($owner) {
                YxDb::begin();
            }
            $book = YxFinance::bookExpense(
                $sum,
                'affiliate_commission',
                '推广计划佣金结款：客户#' . $customerId . '（' . count($rows) . ' 笔订单）',
                'admin#' . $adminId
            );
            if (empty($book['ok'])) {
                if ($owner) {
                    YxDb::rollback();
                }
                return ['ok' => false, 'msg' => '支出登记失败：' . (string)($book['msg'] ?? '')];
            }
            $ids  = array_map(function ($r) {
                return (int)$r['id'];
            }, $rows);
            $place = implode(',', array_fill(0, count($ids), '?'));
            YxDb::exec(
                "UPDATE {$tO} SET affiliate_settled_at = ? WHERE id IN ({$place}) AND affiliate_settled_at IS NULL",
                array_merge([now()], $ids)
            );
            if ($owner) {
                YxDb::commit();
            }
        } catch (Throwable $e) {
            if ($owner) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '结款失败：' . $e->getMessage()];
        }
        log_action('affiliate.settle', 'customer#' . $customerId, ['rows' => count($rows), 'amount' => $sum]);
        return ['ok' => true, 'msg' => '已结款 ' . money($sum, true) . '（' . count($rows) . ' 笔订单）', 'amount' => $sum];
    }
}
