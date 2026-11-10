<?php
/**
 * 优享财务系统 · 推广商（分销）
 *
 * 业务模型：
 *  - 推广商在用户中心申请入驻，上传支付宝 / 微信收款码；
 *  - 平台为推广商的产品定价（产品名称 / 产品金额 / 佣金比例），推广商可自行维护产品；
 *  - 用户在推广页挑选产品下单，页面展示推广商收款码，用户直接付款给推广商；
 *  - 推广商在后台确认收款（回填流水号 / 凭证），平台按「金额 × 佣金比例」抽取佣金，
 *    结算金额 = 金额 − 佣金，记站方收入（bookIncome），不触碰任何客户钱包余额。
 *
 * 金额一律以「元」为单位、按分四舍五入；比例以小数存放（0.1500 = 15%）。
 */

class YxPromoter
{
    /** 功能总开关（后台设置） */
    public static function enabled(): bool
    {
        return (string)setting('promoter_enabled', '0') === '1';
    }

    /** 新入驻默认佣金比例（后台设置，百分比） */
    public static function defaultRate(): float
    {
        $v = (float)setting('promoter_default_rate', '10');
        return self::clampRate($v / 100);
    }

    /** 比例收敛到 [0, 1) */
    public static function clampRate($rate): float
    {
        $r = (float)$rate;
        if ($r < 0) {
            $r = 0;
        }
        if ($r >= 1) {
            $r = 0.9999;
        }
        return round($r, 4);
    }

    /** 比例转展示文本（0.15 → 15%） */
    public static function rateText($rate): string
    {
        $p = round((float)$rate * 100, 2);
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';
    }

    /** 结算金额 = 金额 × (1 − 佣金比例) */
    public static function settleOf(float $amount, float $rate): float
    {
        return round_money($amount * (1 - self::clampRate($rate)));
    }

    /** 平台佣金 = 金额 − 结算金额（用差值保证两者相加等于订单金额） */
    public static function commissionOf(float $amount, float $rate): float
    {
        return round_money($amount - self::settleOf($amount, $rate));
    }

    public static function statusName(string $s): string
    {
        $map = [
            'pending'   => '待审核',
            'active'    => '已启用',
            'disabled'  => '已停用',
        ];
        return $map[$s] ?? $s;
    }

    public static function orderStatusName(string $s): string
    {
        $map = [
            'pending'   => '待付款',
            'submitted' => '待结算',
            'settled'   => '已结算',
            'rejected'  => '已驳回',
        ];
        return $map[$s] ?? $s;
    }

    public static function payMethodName(string $m): string
    {
        $map = ['alipay' => '支付宝', 'wechat' => '微信'];
        return $map[$m] ?? $m;
    }

    /* ------------------------------------------------------------ 推广商档案 */

    /** 按客户账号取推广商档案 */
    public static function profile(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        $r = YxDb::one(
            'SELECT * FROM ' . YxDb::table('promoter') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 1',
            [$customerId]
        );
        return $r ?: null;
    }

    public static function byId(int $id): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('promoter') . ' WHERE id = ?', [$id]);
        return $r ?: null;
    }

    /** 推广页访问标识（推广商 ID + 校验位，避免遍历） */
    public static function pageKey(array $promoter): string
    {
        $id = (int)$promoter['id'];
        $salt = (string)config('app.key', 'yx-promoter');
        return $id . '-' . substr(hash_hmac('sha256', 'promoter:' . $id, $salt), 0, 8);
    }

    /** 解析推广页标识，非法返回 0 */
    public static function idFromKey(string $key): int
    {
        if (!preg_match('/^(\d+)-([0-9a-f]{8})$/', $key, $m)) {
            return 0;
        }
        $id = (int)$m[1];
        if ($id <= 0) {
            return 0;
        }
        $salt = (string)config('app.key', 'yx-promoter');
        $want = substr(hash_hmac('sha256', 'promoter:' . $id, $salt), 0, 8);
        return hash_equals($want, $m[2]) ? $id : 0;
    }

    /**
     * 提交 / 更新入驻申请
     * @param array $files $_FILES 形式的收款码（alipay_qr / wechat_qr）
     */
    public static function apply(int $customerId, array $in, array $files = []): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'msg' => '请先登录'];
        }
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '推广商功能暂未开放'];
        }

        $name  = trim((string)($in['name'] ?? ''));
        $phone = trim((string)($in['phone'] ?? ''));
        $contact = trim((string)($in['contact'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'msg' => '请填写推广人名称'];
        }
        if (mb_strlen($name) > 40) {
            return ['ok' => false, 'msg' => '推广人名称过长（最多 40 字）'];
        }
        if ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone) && !preg_match('/^0\d{2,3}-?\d{7,8}$/', $phone)) {
            return ['ok' => false, 'msg' => '手机号格式不正确'];
        }

        $row = self::profile($customerId);
        $data = [
            'name'       => $name,
            'phone'      => $phone !== '' ? $phone : null,
            'contact'    => $contact !== '' ? $contact : null,
            'updated_at' => now(),
        ];
        if (!$row) {
            $data += [
                'customer_id'     => $customerId,
                'commission_rate' => self::defaultRate(),
                'status'          => 'pending',
                'created_at'      => now(),
            ];
        }

        // 收款码：新上传则替换；未上传时沿用既有，不允许两个都为空
        $hasAlipay = !empty($row['alipay_qr']);
        $hasWechat = !empty($row['wechat_qr']);
        foreach (['alipay' => 'alipay_qr', 'wechat' => 'wechat_qr'] as $m => $field) {
            $file = $files[$field] ?? null;
            if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = self::storeQr($file, $m);
                if (empty($up['ok'])) {
                    return ['ok' => false, 'msg' => ($m === 'alipay' ? '支付宝' : '微信') . '收款码：' . (string)($up['msg'] ?? '上传失败')];
                }
                $data[$field] = (string)$up['path'];
                if ($m === 'alipay') {
                    $hasAlipay = true;
                } else {
                    $hasWechat = true;
                }
            }
            $who = trim((string)($in[$m . '_name'] ?? ''));
            if ($who !== '') {
                $data[$m . '_name'] = mb_substr($who, 0, 40);
            }
        }
        if (!$hasAlipay && !$hasWechat) {
            return ['ok' => false, 'msg' => '请至少上传一个收款码（支付宝或微信）'];
        }

        if ($row) {
            // 已停用的推广商修改资料后回到待审核，避免绕过停用
            if ((string)$row['status'] === 'disabled') {
                $data['status'] = 'pending';
            }
            YxDb::update('promoter', $data, 'id = :id', ['id' => (int)$row['id']]);
            $nowStatus = isset($data['status']) ? (string)$data['status'] : (string)$row['status'];
            return ['ok' => true, 'msg' => '资料已更新', 'id' => (int)$row['id'], 'status' => $nowStatus];
        }

        $id = YxDb::insert('promoter', $data);
        return ['ok' => true, 'msg' => '入驻申请已提交，等待平台审核', 'id' => $id, 'status' => 'pending'];
    }

    /** 收款码上传（png/jpg/webp，≤3MB，落盘 uploads/promoter/） */
    public static function storeQr($file, string $method): array
    {
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'msg' => '请选择图片'];
        }
        $res = yx_upload($file, [
            'sub'      => 'promoter',
            'prefix'   => 'qr-' . preg_replace('/[^a-z]/', '', strtolower($method)),
            'exts'     => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 3 * 1024 * 1024,
        ]);
        if (empty($res['ok'])) {
            return $res;
        }
        // 图片尺寸下限：太小的图大概率不是收款码
        $abs = YX_ROOT . '/uploads/' . (string)$res['path'];
        $info = @getimagesize($abs);
        if ($info && ((int)$info[0] < 120 || (int)$info[1] < 120)) {
            @unlink($abs);
            return ['ok' => false, 'msg' => '图片尺寸过小（至少 120×120）'];
        }
        return $res;
    }

    /** 审核通过 / 停用 / 待审 */
    public static function setStatus(int $id, string $status, string $remark = ''): array
    {
        $row = self::byId($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '推广商不存在'];
        }
        if (!in_array($status, ['pending', 'active', 'disabled'], true)) {
            return ['ok' => false, 'msg' => '状态不合法'];
        }
        if ($status === 'active' && empty($row['alipay_qr']) && empty($row['wechat_qr'])) {
            return ['ok' => false, 'msg' => '该推广商尚未上传收款码，不能启用'];
        }
        $patch = ['status' => $status, 'updated_at' => now()];
        if ($remark !== '') {
            $patch['remark'] = mb_substr($remark, 0, 120);
        }
        if ($status === 'active' && empty($row['approved_at'])) {
            $patch['approved_at'] = now();
        }
        YxDb::update('promoter', $patch, 'id = :id', ['id' => $id]);
        return ['ok' => true, 'msg' => '已' . ($status === 'active' ? '启用' : ($status === 'disabled' ? '停用' : '置为待审核'))];
    }

    /** 平台调整佣金比例（0~1） */
    public static function setRate(int $id, float $rate): array
    {
        $row = self::byId($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '推广商不存在'];
        }
        $rate = self::clampRate($rate);
        YxDb::update('promoter', ['commission_rate' => $rate, 'updated_at' => now()], 'id = :id', ['id' => $id]);

        // 已有产品跟随新比例重算结算金额（仅影响后续下单，已生成订单为快照不变）
        $products = YxDb::all('SELECT * FROM ' . YxDb::table('promoter_product') . ' WHERE promoter_id = ?', [$id]);
        foreach ($products as $p) {
            YxDb::update('promoter_product', [
                'commission_rate' => $rate,
                'settle_amount'   => self::settleOf((float)$p['amount'], $rate),
                'updated_at'      => now(),
            ], 'id = :id', ['id' => (int)$p['id']]);
        }
        return ['ok' => true, 'msg' => '佣金比例已更新为 ' . self::rateText($rate)];
    }

    /* ------------------------------------------------------------ 产品 */

    public static function products(int $promoterId, bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM ' . YxDb::table('promoter_product') . ' WHERE promoter_id = ?'
            . ($onlyActive ? " AND status = 'active'" : '')
            . ' ORDER BY sort ASC, id DESC';
        return YxDb::all($sql, [$promoterId]);
    }

    public static function productOf(int $productId, int $promoterId = 0): ?array
    {
        $sql = 'SELECT * FROM ' . YxDb::table('promoter_product') . ' WHERE id = ?';
        $bind = [$productId];
        if ($promoterId > 0) {
            $sql .= ' AND promoter_id = ?';
            $bind[] = $promoterId;
        }
        $r = YxDb::one($sql, $bind);
        return $r ?: null;
    }

    /**
     * 新增 / 修改产品
     * @param int $id 0 表示新增
     */
    public static function saveProduct(int $promoterId, array $in, int $id = 0): array
    {
        $promoter = self::byId($promoterId);
        if (!$promoter) {
            return ['ok' => false, 'msg' => '推广商不存在'];
        }
        $name   = trim((string)($in['name'] ?? ''));
        $amount = round_money((float)($in['amount'] ?? 0));
        if ($name === '') {
            return ['ok' => false, 'msg' => '请填写产品名称'];
        }
        if (mb_strlen($name) > 80) {
            return ['ok' => false, 'msg' => '产品名称过长（最多 80 字）'];
        }
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '产品金额必须大于 0'];
        }
        if ($amount > 9999999) {
            return ['ok' => false, 'msg' => '产品金额过大'];
        }

        // 佣金比例：推广商可填，但不得低于平台设定的默认比例（平台收益下限）
        $rateRaw = trim((string)($in['commission_rate'] ?? ''));
        $rate = $rateRaw === '' ? (float)$promoter['commission_rate'] : self::clampRate(((float)$rateRaw) / 100);
        if ($rate < (float)$promoter['commission_rate']) {
            $rate = (float)$promoter['commission_rate'];
        }

        $methods = [];
        foreach (['alipay', 'wechat'] as $m) {
            if (!empty($in['pay_' . $m])) {
                $methods[] = $m;
            }
        }
        if (!$methods) {
            return ['ok' => false, 'msg' => '请至少选择一种收款方式'];
        }
        // 只保留推广商确实上传了收款码的方式
        $methods = array_values(array_filter($methods, function ($m) use ($promoter) {
            return !empty($promoter[$m . '_qr']);
        }));
        if (!$methods) {
            return ['ok' => false, 'msg' => '所选收款方式尚未上传收款码，请先在入驻资料中上传'];
        }

        $data = [
            'promoter_id'     => $promoterId,
            'name'            => $name,
            'amount'          => $amount,
            'commission_rate' => $rate,
            'settle_amount'   => self::settleOf($amount, $rate),
            'pay_methods'     => implode(',', $methods),
            'status'          => (string)($in['status'] ?? 'active') === 'offline' ? 'offline' : 'active',
            'sort'            => (int)($in['sort'] ?? 0),
            'remark'          => trim((string)($in['remark'] ?? '')) !== '' ? mb_substr(trim((string)$in['remark']), 0, 120) : null,
            'updated_at'      => now(),
        ];

        if ($id > 0) {
            $old = self::productOf($id, $promoterId);
            if (!$old) {
                return ['ok' => false, 'msg' => '产品不存在或不属于该推广商'];
            }
            YxDb::update('promoter_product', $data, 'id = :id', ['id' => $id]);
            return ['ok' => true, 'msg' => '产品已更新', 'id' => $id];
        }

        $data['created_at'] = now();
        $newId = YxDb::insert('promoter_product', $data);
        return ['ok' => true, 'msg' => '产品已添加', 'id' => $newId];
    }

    public static function deleteProduct(int $promoterId, int $id): array
    {
        $p = self::productOf($id, $promoterId);
        if (!$p) {
            return ['ok' => false, 'msg' => '产品不存在'];
        }
        // 已有订单的产品只下架，保留历史数据可追溯
        $used = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('promoter_order') . ' WHERE product_id = ?', [$id]);
        if ($used > 0) {
            YxDb::update('promoter_product', ['status' => 'offline', 'updated_at' => now()], 'id = :id', ['id' => $id]);
            return ['ok' => true, 'msg' => '该产品已有订单，已改为下架'];
        }
        YxDb::delete('promoter_product', 'id = :id', ['id' => $id]);
        return ['ok' => true, 'msg' => '产品已删除'];
    }

    /* ------------------------------------------------------------ 订单 */

    /**
     * 用户下单（此时尚未付款，订单为待付款）
     * 推广商必须在启用状态、产品必须上架，金额取库内值，不信任表单。
     */
    public static function createOrder(int $promoterId, int $productId, array $in, int $customerId = 0, array $files = []): array
    {
        $promoter = self::byId($promoterId);
        if (!$promoter) {
            return ['ok' => false, 'msg' => '推广商不存在'];
        }
        if ((string)$promoter['status'] !== 'active') {
            return ['ok' => false, 'msg' => '该推广商暂不可下单'];
        }
        $product = self::productOf($productId, $promoterId);
        if (!$product || (string)$product['status'] !== 'active') {
            return ['ok' => false, 'msg' => '产品已下架'];
        }

        $buyer   = trim((string)($in['buyer'] ?? ''));
        $contact = trim((string)($in['contact'] ?? ''));
        $method  = (string)($in['pay_method'] ?? 'alipay');
        $allowed = array_filter(explode(',', (string)$product['pay_methods']));
        if ($buyer === '') {
            return ['ok' => false, 'msg' => '请填写您的称呼'];
        }
        if ($contact === '') {
            return ['ok' => false, 'msg' => '请填写联系方式（手机 / 微信 / 邮箱）'];
        }
        if (!in_array($method, $allowed, true)) {
            return ['ok' => false, 'msg' => '不支持的收款方式'];
        }
        if (empty($promoter[$method . '_qr'])) {
            return ['ok' => false, 'msg' => '推广商未配置该收款方式'];
        }

        $amount = round_money((float)$product['amount']);
        $rate   = self::clampRate((float)$product['commission_rate']);
        $commission = self::commissionOf($amount, $rate);
        $settle     = round_money($amount - $commission);

        $proof = null;
        $file  = $files['proof_image'] ?? null;
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = yx_upload($file, [
                'sub' => 'promoter', 'prefix' => 'proof',
                'exts' => ['jpg', 'jpeg', 'png', 'webp'], 'max_size' => 3 * 1024 * 1024,
            ]);
            if (empty($up['ok'])) {
                return ['ok' => false, 'msg' => '付款凭证上传失败：' . (string)($up['msg'] ?? '')];
            }
            $proof = (string)$up['path'];
        }

        $sn = make_sn('PM', 'promoter_order');
        $id = YxDb::insert('promoter_order', [
            'sn'                => $sn,
            'promoter_id'       => $promoterId,
            'product_id'        => $productId,
            'product_name'      => (string)$product['name'],
            'customer_id'       => $customerId,
            'buyer'             => mb_substr($buyer, 0, 30),
            'contact'           => mb_substr($contact, 0, 60),
            'amount'            => $amount,
            'commission_rate'   => $rate,
            'commission_amount' => $commission,
            'settle_amount'     => $settle,
            'pay_method'        => $method,
            'proof_image'       => $proof,
            'status'            => $proof ? 'submitted' : 'pending',
            'created_at'        => now(),
        ]);

        return [
            'ok'      => true,
            'msg'     => '订单已创建，请在 ' . self::payMethodName($method) . ' 中扫码付款',
            'id'      => $id,
            'sn'      => $sn,
            'amount'  => $amount,
            'promoter' => $promoter,
            'product' => $product,
            'method'  => $method,
            'qr'      => (string)$promoter[$method . '_qr'],
        ];
    }

    public static function orderBySn(string $sn): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('promoter_order') . ' WHERE sn = ?', [$sn]);
        return $r ?: null;
    }

    /** 推广商确认已收到用户款项 */
    public static function confirmPaid(int $orderId, int $promoterId, string $tradeNo = ''): array
    {
        $o = YxDb::one(
            'SELECT * FROM ' . YxDb::table('promoter_order') . ' WHERE id = ? AND promoter_id = ?',
            [$orderId, $promoterId]
        );
        if (!$o) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if ((string)$o['status'] !== 'pending') {
            return ['ok' => false, 'msg' => '该订单当前状态为「' . self::orderStatusName((string)$o['status']) . '」，无需确认收款'];
        }
        $patch = ['status' => 'submitted', 'confirmed_at' => now()];
        $tradeNo = trim($tradeNo);
        if ($tradeNo !== '') {
            $patch['trade_no'] = mb_substr($tradeNo, 0, 60);
        }
        YxDb::update('promoter_order', $patch, 'id = :id', ['id' => $orderId]);
        return ['ok' => true, 'msg' => '已确认收款，等待平台结算'];
    }

    /** 推广商驳回订单（用户未付款 / 金额不符等） */
    public static function rejectOrder(int $orderId, int $promoterId, string $remark = ''): array
    {
        $o = YxDb::one(
            'SELECT * FROM ' . YxDb::table('promoter_order') . ' WHERE id = ? AND promoter_id = ?',
            [$orderId, $promoterId]
        );
        if (!$o) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if ((string)$o['status'] !== 'pending') {
            return ['ok' => false, 'msg' => '仅待付款订单可驳回'];
        }
        YxDb::update('promoter_order', [
            'status'       => 'rejected',
            'admin_remark' => $remark !== '' ? mb_substr($remark, 0, 120) : '推广商未收到款项',
        ], 'id = :id', ['id' => $orderId]);
        return ['ok' => true, 'msg' => '订单已驳回'];
    }

    /**
     * 平台结算：把「待结算」订单标记为已结算，登记站方佣金收入，累计推广商业绩。
     * 幂等：已结算订单再次调用直接返回成功（不重复记账）。
     */
    public static function settle(int $orderId, string $remark = ''): array
    {
        $o = YxDb::one('SELECT * FROM ' . YxDb::table('promoter_order') . ' WHERE id = ?', [$orderId]);
        if (!$o) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if ((string)$o['status'] === 'settled') {
            return ['ok' => true, 'msg' => '该订单已结算', 'repeat' => true];
        }
        if ((string)$o['status'] !== 'submitted') {
            return ['ok' => false, 'msg' => '仅待结算订单可结算（当前：' . self::orderStatusName((string)$o['status']) . '）'];
        }

        $promoter = self::byId((int)$o['promoter_id']);
        if (!$promoter) {
            return ['ok' => false, 'msg' => '推广商不存在'];
        }

        $amount     = round_money((float)$o['amount']);
        $commission = round_money((float)$o['commission_amount']);
        $settleAmt  = round_money((float)$o['settle_amount']);
        // 历史数据兜底：金额与拆分明细不一致时以当前比例重算，保证分账守恒
        if (round_money($commission + $settleAmt) !== $amount) {
            $commission = self::commissionOf($amount, (float)$o['commission_rate']);
            $settleAmt  = round_money($amount - $commission);
        }

        $owner = !YxDb::pdo()->inTransaction();
        try {
            if ($owner) {
                YxDb::begin();
            }
            // 条件更新防并发重复结算
            $affected = YxDb::exec(
                'UPDATE ' . YxDb::table('promoter_order') . " SET status = 'settled', settled_at = ?"
                . ', settle_amount = ?, commission_amount = ?'
                . ($remark !== '' ? ', admin_remark = ?' : '')
                . " WHERE id = ? AND status = 'submitted'",
                $remark !== ''
                    ? [now(), $settleAmt, $commission, mb_substr($remark, 0, 120), $orderId]
                    : [now(), $settleAmt, $commission, $orderId]
            );
            if ($affected < 1) {
                if ($owner) {
                    YxDb::rollback();
                }
                return ['ok' => false, 'msg' => '订单状态已变化，请刷新后重试'];
            }

            YxDb::exec(
                'UPDATE ' . YxDb::table('promoter')
                . ' SET order_count = order_count + 1, order_amount = order_amount + ?'
                . ', settle_amount = settle_amount + ?, commission_total = commission_total + ?, updated_at = ?'
                . ' WHERE id = ?',
                [$amount, $settleAmt, $commission, now(), (int)$o['promoter_id']]
            );

            if ($commission > 0) {
                $book = YxFinance::bookIncome($commission, 'promoter_commission', [
                    'method'   => (string)$o['pay_method'],
                    'ref_type' => 'promoter_order',
                    'ref_id'   => $orderId,
                    'operator' => 'admin',
                    'remark'   => '推广订单 ' . (string)$o['sn'] . ' 平台佣金（' . self::rateText((float)$o['commission_rate']) . '）',
                ]);
                if (empty($book['ok'])) {
                    if ($owner) {
                        YxDb::rollback();
                    }
                    return ['ok' => false, 'msg' => '佣金登记失败：' . (string)($book['msg'] ?? '')];
                }
            }

            if ($owner) {
                YxDb::commit();
            }
        } catch (Throwable $e) {
            if ($owner) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '结算失败：' . $e->getMessage()];
        }

        return [
            'ok'         => true,
            'msg'        => '已结算：平台佣金 ' . money($commission, true) . '，推广商结款 ' . money($settleAmt, true),
            'commission' => $commission,
            'settle'     => $settleAmt,
        ];
    }

    /** 平台驳回待结算订单 */
    public static function reject(int $orderId, string $remark): array
    {
        $o = YxDb::one('SELECT * FROM ' . YxDb::table('promoter_order') . ' WHERE id = ?', [$orderId]);
        if (!$o) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if (!in_array((string)$o['status'], ['pending', 'submitted'], true)) {
            return ['ok' => false, 'msg' => '该订单已结算或已驳回，不能再驳回'];
        }
        YxDb::update('promoter_order', [
            'status'       => 'rejected',
            'admin_remark' => $remark !== '' ? mb_substr($remark, 0, 120) : '平台驳回',
        ], 'id = :id', ['id' => $orderId]);
        return ['ok' => true, 'msg' => '订单已驳回'];
    }

    /* ------------------------------------------------------------ 统计 */

    public static function stats(int $promoterId): array
    {
        $tOrder = YxDb::table('promoter_order');
        $all = YxDb::one(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN status = 'settled' THEN amount ELSE 0 END), 0) AS amount,
                    COALESCE(SUM(CASE WHEN status = 'settled' THEN settle_amount ELSE 0 END), 0) AS settle,
                    COALESCE(SUM(CASE WHEN status = 'settled' THEN commission_amount ELSE 0 END), 0) AS commission,
                    COALESCE(SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END), 0) AS pending_settle,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_pay
             FROM {$tOrder} WHERE promoter_id = ?",
            [$promoterId]
        );
        return [
            'orders'         => (int)($all['cnt'] ?? 0),
            'amount'         => round_money((float)($all['amount'] ?? 0)),
            'settle'         => round_money((float)($all['settle'] ?? 0)),
            'commission'     => round_money((float)($all['commission'] ?? 0)),
            'pending_settle' => (int)($all['pending_settle'] ?? 0),
            'pending_pay'    => (int)($all['pending_pay'] ?? 0),
            'products'       => (int)YxDb::value(
                'SELECT COUNT(*) FROM ' . YxDb::table('promoter_product') . " WHERE promoter_id = ? AND status = 'active'",
                [$promoterId]
            ),
        ];
    }

    /** 推广商订单列表（供用户中心 / 推广页） */
    public static function orders(int $promoterId, string $status = '', int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM ' . YxDb::table('promoter_order') . ' WHERE promoter_id = ?';
        $bind = [$promoterId];
        if ($status !== '' && in_array($status, ['pending', 'submitted', 'settled', 'rejected'], true)) {
            $sql .= ' AND status = ?';
            $bind[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        return YxDb::all($sql, $bind);
    }

    /** 订单计数（分页用） */
    public static function orderCount(int $promoterId, string $status = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . YxDb::table('promoter_order') . ' WHERE promoter_id = ?';
        $bind = [$promoterId];
        if ($status !== '' && in_array($status, ['pending', 'submitted', 'settled', 'rejected'], true)) {
            $sql .= ' AND status = ?';
            $bind[] = $status;
        }
        return (int)YxDb::value($sql, $bind);
    }
}
