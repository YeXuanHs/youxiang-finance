<?php
/**
 * 优享财务系统 · 开发者中心业务逻辑
 *
 * 入驻流程：提交资料 → 个人/企业实名扫码核验 → 缴纳保证金 → 管理员审核通过 → 上传商品
 * 商品经审核后写入 `app` 表（author_type=developer）即「经优享商店售卖」，复用现有
 * 下单 / 支付 / 安装 / 授权闭环；付费商品成交后写 developer_settlement 分成。
 */

class YxDeveloper
{
    /** 商品类型 */
    const TYPES = ['plugin' => '插件', 'theme' => '主题', 'template' => '模板', 'app' => '应用'];

    /** 商品状态（app.status） */
    const APP_STATUS = [
        'draft'    => '草稿',
        'pending'  => '待审核',
        'on'       => '已上架',
        'off'      => '已下架',
        'rejected' => '已驳回',
    ];

    /** 开发者状态 */
    const DEV_STATUS = [
        'applying'       => '资料待完善',
        'pending_review' => '待审核',
        'approved'       => '已通过',
        'rejected'       => '已驳回',
        'disabled'       => '已停用',
    ];

    const REALNAME_STATUS = [
        'none'     => '未认证',
        'pending'  => '审核中',
        'verified' => '已认证',
        'rejected' => '未通过',
        'expired'  => '已过期',
    ];

    const DEPOSIT_STATUS = [
        'none'     => '未缴纳',
        'unpaid'   => '待缴纳',
        'paid'     => '已缴纳',
        'refunded' => '已退还',
        'deducted' => '已扣除',
    ];

    public static function typeName(string $t): string
    {
        return self::TYPES[$t] ?? $t;
    }

    public static function appStatusName(string $s): string
    {
        return self::APP_STATUS[$s] ?? $s;
    }

    public static function devStatusName(string $s): string
    {
        return self::DEV_STATUS[$s] ?? $s;
    }

    public static function realnameStatusName(string $s): string
    {
        return self::REALNAME_STATUS[$s] ?? $s;
    }

    public static function depositStatusName(string $s): string
    {
        return self::DEPOSIT_STATUS[$s] ?? $s;
    }

    /**
     * 免缴保证金的商品类型
     *
     * 模板属于「替换外观」的低风险商品，平台不收取保证金（也无需审核前的信用担保）；
     * 插件 / 主题 / 应用会直接运行在客户站点上，按年收取保证金。
     */
    const DEPOSIT_FREE_TYPES = ['template'];

    /** 保证金金额（后台可配置，默认 600 元/年） */
    public static function depositAmount(): float
    {
        $v = (float)setting('developer_deposit_amount', 600);
        return $v > 0 ? round_money($v) : 600.0;
    }

    /** 该商品类型是否需要缴纳保证金 */
    public static function depositRequired(string $type): bool
    {
        return !in_array($type, self::DEPOSIT_FREE_TYPES, true);
    }

    /** 指定商品类型应缴的保证金金额（免缴类型返回 0） */
    public static function depositAmountForType(string $type): float
    {
        return self::depositRequired($type) ? self::depositAmount() : 0.0;
    }

    /**
     * 是否需要为某个类型补缴保证金
     *
     * 已缴纳过保证金（>= 该类型应缴额）的开发者不再重复收取；模板类永远不需要。
     */
    public static function needsDepositFor(string $type, ?array $dev): bool
    {
        if (!self::depositRequired($type)) {
            return false;
        }
        if (!$dev) {
            return true;
        }
        if ((string)($dev['deposit_status'] ?? '') === 'paid') {
            return false;
        }
        return (float)($dev['deposit_amount'] ?? 0) < self::depositAmount();
    }

    /**
     * 安装包允许的最大字节数：取「后台配置」与「PHP 实际上限」的较小值，
     * 避免用户选择超限文件时被 PHP 静默拒绝（$_FILES 为空）。
     */
    public static function packageMaxBytes(): int
    {
        $mb = (int)setting('developer_package_max_mb', 50);
        if ($mb <= 0) {
            $mb = 50;
        }
        $bytes = $mb * 1024 * 1024;
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $lim = self::iniBytes((string)ini_get($k));
            if ($lim > 0 && $bytes > $lim) {
                $bytes = $lim;
            }
        }
        return $bytes;
    }

    /** 解析 php.ini 的容量写法（如 50M / 512K / 2G）为字节 */
    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $unit = strtolower(substr($v, -1));
        $num  = (int)$v;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return $num;
        }
    }

    /* ------------------------------ 档案 ------------------------------ */

    public static function profile(int $customerId): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('developer') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$customerId]);
        return $r ?: null;
    }

    public static function byId(int $id): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('developer') . ' WHERE id = ?', [$id]);
        return $r ?: null;
    }

    /** 提交 / 更新入驻资料 */
    public static function apply(int $customerId, array $in): array
    {
        $type = (($in['type'] ?? 'personal') === 'enterprise') ? 'enterprise' : 'personal';
        $realName = trim((string)($in['real_name'] ?? ''));
        $data = [
            'customer_id'  => $customerId,
            'type'         => $type,
            'contact'      => trim((string)($in['contact'] ?? '')),
            'real_name'    => $realName,
            'company'      => trim((string)($in['company'] ?? '')),
            'license_no'   => strtoupper(trim((string)($in['license_no'] ?? ''))),
            'legal_person' => trim((string)($in['legal_person'] ?? '')),
            'updated_at'   => now(),
        ];
        // 企业入驻：真实姓名栏填的是企业名称时，补写到企业名称，避免后续企业认证取不到
        if ($type === 'enterprise' && $data['company'] === '' && $realName !== '') {
            $data['company'] = $realName;
        }

        $dev = self::profile($customerId);
        if ($dev) {
            YxDb::update('developer', $data, 'id = :id', ['id' => (int)$dev['id']]);
            return ['ok' => true, 'msg' => '入驻资料已更新', 'developer_id' => (int)$dev['id']];
        }

        $data += [
            'realname_status' => 'none',
            'deposit_status'  => 'none',
            'can_publish'     => 0,
            'status'          => 'applying',
            'applied_at'      => now(),
            'created_at'      => now(),
        ];
        $id = YxDb::insert('developer', $data);
        return ['ok' => true, 'msg' => '入驻申请已提交，请继续完成实名认证', 'developer_id' => $id];
    }

    /**
     * 开发者自助维护对外联系资料（详情页开发者卡片展示的内容）
     * 只允许改联系类字段，企业/实名信息仍走 apply 与实名认证流程。
     */
    public static function saveContact(int $devId, array $in): array
    {
        $dev = self::byId($devId);
        if (!$dev) {
            return ['ok' => false, 'msg' => '开发者档案不存在'];
        }
        $url = trim((string)($in['website'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $email = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '电子邮箱格式不正确'];
        }
        $qq = trim((string)($in['qq'] ?? ''));
        if ($qq !== '' && !preg_match('/^\d{5,12}$/', $qq)) {
            return ['ok' => false, 'msg' => '咨询 QQ 应为 5-12 位数字'];
        }
        YxDb::update('developer', [
            'website'    => mb_substr($url, 0, 200),
            'email'      => mb_substr($email, 0, 120),
            'qq'         => mb_substr($qq, 0, 20),
            'wechat'     => mb_substr(trim((string)($in['wechat'] ?? '')), 0, 60),
            'intro'      => mb_substr(trim((string)($in['intro'] ?? '')), 0, 500),
            'contact'    => mb_substr(trim((string)($in['contact'] ?? ($dev['contact'] ?? ''))), 0, 120),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $devId]);
        return ['ok' => true, 'msg' => '联系资料已保存'];
    }

    /** 实名通过后联动档案 */
    public static function markRealnameVerified(int $devId, array $rn): void
    {
        $patch = [
            'realname_status' => 'verified',
            'type'            => (string)($rn['dev_type'] ?? 'personal'),
            'verified_at'     => now(),
            'updated_at'      => now(),
        ];
        foreach (['name' => 'real_name', 'id_card' => 'id_card', 'company' => 'company', 'license_no' => 'license_no', 'legal_person' => 'legal_person'] as $src => $dst) {
            if (!empty($rn[$src])) {
                $patch[$dst] = $rn[$src]; // id_card 已是密文
            }
        }
        YxDb::update('developer', $patch, 'id = :id', ['id' => $devId]);
    }

    /* ------------------------------ 保证金 ------------------------------ */

    /** 创建保证金订单（复用统一订单 + 支付闭环，type=deposit） */
    public static function createDeposit(array $dev, string $forType = 'plugin'): array
    {
        if (($dev['realname_status'] ?? '') !== 'verified') {
            return ['ok' => false, 'msg' => '请先完成实名认证再缴纳保证金'];
        }
        if (!self::depositRequired($forType)) {
            return ['ok' => false, 'msg' => self::typeName($forType) . '类商品免缴保证金，无需缴纳'];
        }
        if (($dev['deposit_status'] ?? '') === 'paid') {
            return ['ok' => false, 'msg' => '保证金已缴纳，无需重复缴纳'];
        }

        $amount  = self::depositAmountForType($forType);
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '保证金金额未配置'];
        }
        $created = YxBusiness::createOrder([
            'customer_id' => (int)$dev['customer_id'],
            'type'        => 'deposit',
            'name'        => '开发者入驻保证金（' . self::typeName($forType) . '类）',
            'amount'      => $amount,
            'note'        => '开发者保证金 · ' . self::typeName($forType) . '类',
        ]);
        if (empty($created['ok'])) {
            return $created;
        }

        $orderId = (int)$created['order_id'];
        $order   = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]);

        YxDb::insert('developer_deposit', [
            'developer_id' => (int)$dev['id'],
            'customer_id'  => (int)$dev['customer_id'],
            'order_id'     => $orderId,
            'sn'           => $order['sn'],
            'amount'       => $amount,
            'status'       => 'unpaid',
            'created_at'   => now(),
        ]);
        YxDb::update('developer', ['deposit_status' => 'unpaid', 'deposit_amount' => $amount, 'updated_at' => now()], 'id = :id', ['id' => (int)$dev['id']]);

        return ['ok' => true, 'msg' => '保证金单据已生成', 'order_id' => $orderId, 'sn' => $order['sn'], 'amount' => $amount];
    }

    /** 保证金支付完成（由订单交付流程调用） */
    public static function markDepositPaid(int $orderId, string $tradeNo = '', string $method = 'gateway'): array
    {
        $dep = YxDb::one('SELECT * FROM ' . YxDb::table('developer_deposit') . ' WHERE order_id = ?', [$orderId]);
        if (!$dep) {
            return ['ok' => false, 'msg' => '保证金单据不存在'];
        }
        if ($dep['status'] === 'paid') {
            return ['ok' => true, 'msg' => '保证金已缴纳'];
        }

        YxDb::update('developer_deposit', [
            'status'   => 'paid',
            'trade_no' => $tradeNo !== '' ? $tradeNo : null,
            'paid_at'  => now(),
        ], 'id = :id', ['id' => (int)$dep['id']]);

        $dev = self::byId((int)$dep['developer_id']);
        if ($dev) {
            $patch = [
                'deposit_status' => 'paid',
                'deposit_amount' => (float)$dep['amount'],
                'updated_at'     => now(),
            ];
            if ((string)setting('developer_auto_approve', '0') === '1' && $dev['realname_status'] === 'verified') {
                $patch['status']      = 'approved';
                $patch['can_publish'] = 1;
                $patch['approved_at'] = now();
            } elseif (in_array($dev['status'], ['applying', 'rejected'], true)) {
                $patch['status'] = 'pending_review';
            }
            YxDb::update('developer', $patch, 'id = :id', ['id' => (int)$dev['id']]);
        }
        return ['ok' => true, 'msg' => '保证金已确认'];
    }

    /* ------------------------------ 审核 ------------------------------ */

    public static function approve(int $devId): array
    {
        $dev = self::byId($devId);
        if (!$dev) {
            return ['ok' => false, 'msg' => '开发者不存在'];
        }
        if ($dev['realname_status'] !== 'verified') {
            return ['ok' => false, 'msg' => '该开发者尚未通过实名认证'];
        }
        // 只做模板的开发者不收保证金，其余类型（插件/主题/应用）仍需缴纳
        if (self::depositRequired('plugin') && $dev['deposit_status'] !== 'paid' && !self::templateOnly($dev)) {
            return ['ok' => false, 'msg' => '该开发者尚未缴纳保证金（' . money(self::depositAmount(), true) . '/年）'];
        }
        YxDb::update('developer', [
            'status'      => 'approved',
            'can_publish' => 1,
            'approved_at' => now(),
            'updated_at'  => now(),
        ], 'id = :id', ['id' => $devId]);
        return ['ok' => true, 'msg' => '开发者已通过审核，可上传商品'];
    }

    public static function reject(int $devId, string $reason): array
    {
        YxDb::update('developer', [
            'status'      => 'rejected',
            'can_publish' => 0,
            'remark'      => $reason,
            'updated_at'  => now(),
        ], 'id = :id', ['id' => $devId]);
        return ['ok' => true, 'msg' => '已驳回入驻申请'];
    }

    public static function canPublish(?array $dev): bool
    {
        return $dev && (int)$dev['can_publish'] === 1 && $dev['status'] === 'approved';
    }

    /**
     * 该开发者是否只发布免缴保证金的商品类型（模板）
     *
     * 用于审核与上传时的放宽：只做模板的开发者未缴纳保证金也允许上传模板商品，
     * 但一旦选择插件 / 主题 / 应用仍需先补缴保证金。
     */
    public static function templateOnly(array $dev): bool
    {
        $rows = YxDb::all(
            'SELECT DISTINCT type FROM ' . YxDb::table('app') . ' WHERE developer_id = ?',
            [(int)$dev['id']]
        );
        if (!$rows) {
            return true;
        }
        foreach ($rows as $r) {
            if (self::depositRequired((string)$r['type'])) {
                return false;
            }
        }
        return true;
    }

    /** 当前开发者是否已可上传指定类型的商品（模板类免保证金） */
    public static function canPublishType(?array $dev, string $type): bool
    {
        if (self::canPublish($dev)) {
            return true;
        }
        if (!$dev || self::depositRequired($type)) {
            return false;
        }
        // 模板类：已实名 + 审核通过即可，不要求保证金
        return (string)$dev['realname_status'] === 'verified' && (string)$dev['status'] === 'approved';
    }

    /* ------------------------------ 商品 ------------------------------ */

    public static function myApps(int $devId): array
    {
        return YxDb::all('SELECT * FROM ' . YxDb::table('app') . ' WHERE developer_id = ? ORDER BY id DESC', [$devId]);
    }

    public static function appOf(int $devId, int $appId): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ? AND developer_id = ?', [$appId, $devId]);
        return $r ?: null;
    }

    public static function appVersions(int $appId): array
    {
        return YxDb::all('SELECT * FROM ' . YxDb::table('dev_app_version') . ' WHERE app_id = ? ORDER BY id DESC', [$appId]);
    }

    /**
     * 开发者上传商品（进入待审核）
     * @param array $dev   开发者档案
     * @param array $data  表单数据
     * @param array $files $_FILES
     */
    public static function publish(array $dev, array $data, array $files): array
    {
        $type  = array_key_exists((string)($data['type'] ?? ''), self::TYPES) ? (string)$data['type'] : 'plugin';
        if (!self::canPublishType($dev, $type)) {
            if (!self::depositRequired($type)) {
                return ['ok' => false, 'msg' => '入驻审核尚未通过，暂不可上传商品'];
            }
            return ['ok' => false, 'msg' => '保证金未缴纳或审核未通过，暂不可上传' . self::typeName($type) . '类商品（保证金 ' . money(self::depositAmount(), true) . '/年，模板类免缴）'];
        }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'msg' => '请填写商品名称'];
        }
        $bill  = in_array($data['bill_type'] ?? '', ['free', 'once', 'year'], true) ? (string)$data['bill_type'] : 'free';
        $price = round_money($data['price'] ?? 0);
        if ($bill !== 'free' && $price <= 0) {
            return ['ok' => false, 'msg' => '付费商品价格需大于 0'];
        }
        if ($bill === 'free') {
            $price = 0.0;
        }

        // 安装包（必填）
        $pkg = $files['package'] ?? null;
        if (!$pkg || (int)($pkg['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'msg' => '请上传安装包（.zip）'];
        }
        $up = yx_upload($pkg, ['sub' => 'devapp', 'prefix' => 'pkg', 'exts' => ['zip'], 'max_size' => self::packageMaxBytes(), 'mimes' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream']]);
        if (empty($up['ok'])) {
            return $up;
        }

        // 封面（可选）
        $cover = null;
        if (!empty($files['cover']) && (int)($files['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $im = yx_upload($files['cover'], ['sub' => 'devapp', 'prefix' => 'cover', 'exts' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'max_size' => 3 * 1024 * 1024]);
            if (!empty($im['ok'])) {
                $cover = $im['path'];
            }
        }

        // 截图（可选，多张）
        $shots = [];
        foreach (yx_files_array('screenshots') as $f) {
            if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $im = yx_upload($f, ['sub' => 'devapp', 'prefix' => 'shot', 'exts' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'max_size' => 3 * 1024 * 1024]);
            if (!empty($im['ok'])) {
                $shots[] = $im['path'];
            }
        }

        $version = trim((string)($data['version'] ?? '')) ?: '1.0.0';
        $key     = 'dev-' . (int)$dev['id'] . '-' . bin2hex(random_bytes(4));
        $author  = $dev['company'] ?: ($dev['real_name'] ?: ('开发者#' . $dev['id']));

        $appId = YxDb::insert('app', [
            'category_key' => (string)($data['category_key'] ?? 'other'),
            'key'          => $key,
            'name'         => $title,
            'version'      => $version,
            'author'       => $author,
            'price'        => $price,
            'bill_type'    => $bill,
            'description'  => trim((string)($data['description'] ?? '')),
            'changelog'    => trim((string)($data['changelog'] ?? '')),
            'edition_min'  => isset(YxEdition::EDITIONS[$data['edition_min'] ?? '']) ? (string)$data['edition_min'] : 'basic',
            'compatible'   => trim((string)($data['compatible'] ?? '')),
            'developer_id' => (int)$dev['id'],
            'author_type'  => 'developer',
            'type'         => $type,
            'cover'        => $cover,
            'screenshots'  => $shots ? json_encode($shots, JSON_UNESCAPED_UNICODE) : null,
            'package_path' => $up['path'],
            'package_size' => (int)$up['size'],
            'source'       => '',
            'status'       => 'pending',
            'downloads'    => 0,
            'sales'        => 0,
            'featured'     => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        YxDb::insert('dev_app_version', [
            'app_id'       => $appId,
            'developer_id' => (int)$dev['id'],
            'version'      => $version,
            'package_path' => $up['path'],
            'package_size' => (int)$up['size'],
            'changelog'    => trim((string)($data['changelog'] ?? '')),
            'created_at'   => now(),
        ]);

        return ['ok' => true, 'msg' => '商品已提交，等待管理员审核', 'app_id' => $appId];
    }

    /** 发布新版本 */
    public static function addVersion(array $dev, int $appId, array $files, string $version, string $changelog): array
    {
        if (!self::canPublish($dev)) {
            return ['ok' => false, 'msg' => '暂无发布权限'];
        }
        $app = self::appOf((int)$dev['id'], $appId);
        if (!$app) {
            return ['ok' => false, 'msg' => '商品不存在'];
        }
        $pkg = $files['package'] ?? null;
        if (!$pkg || (int)($pkg['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'msg' => '请上传安装包（.zip）'];
        }
        $up = yx_upload($pkg, ['sub' => 'devapp', 'prefix' => 'pkg', 'exts' => ['zip'], 'max_size' => self::packageMaxBytes(), 'mimes' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream']]);
        if (empty($up['ok'])) {
            return $up;
        }
        $version = trim($version) ?: '1.0.1';

        YxDb::insert('dev_app_version', [
            'app_id'       => $appId,
            'developer_id' => (int)$dev['id'],
            'version'      => $version,
            'package_path' => $up['path'],
            'package_size' => (int)$up['size'],
            'changelog'    => $changelog,
            'created_at'   => now(),
        ]);

        $patch = [
            'version'      => $version,
            'package_path' => $up['path'],
            'package_size' => (int)$up['size'],
            'updated_at'   => now(),
        ];
        if ($changelog !== '') {
            $patch['changelog'] = $changelog;
        }
        // 发布新版本后重新进入待审核，避免未审核的内容直接对外
        if (in_array($app['status'], ['on', 'off'], true)) {
            $patch['status'] = 'pending';
        }
        YxDb::update('app', $patch, 'id = :id', ['id' => $appId]);

        return ['ok' => true, 'msg' => '新版本已提交，等待审核'];
    }

    /** 开发者下架 / 重新上架自己的商品 */
    public static function toggleListing(int $devId, int $appId): array
    {
        $app = self::appOf($devId, $appId);
        if (!$app) {
            return ['ok' => false, 'msg' => '商品不存在'];
        }
        if (!in_array($app['status'], ['on', 'off'], true)) {
            return ['ok' => false, 'msg' => '该商品当前状态不可切换'];
        }
        $to = $app['status'] === 'on' ? 'off' : 'on';
        YxDb::update('app', ['status' => $to, 'updated_at' => now()], 'id = :id', ['id' => $appId]);
        return ['ok' => true, 'msg' => $to === 'on' ? '已重新上架' : '已下架'];
    }

    /* ------------------------------ 分成 ------------------------------ */

    /** 付费商品成交后写入分成（幂等） */
    public static function creditSale(array $app, int $orderId, float $gross): void
    {
        $devId = (int)($app['developer_id'] ?? 0);
        if ($devId <= 0 || $gross <= 0) {
            return;
        }
        $exists = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('developer_settlement') . ' WHERE order_id = ?', [$orderId]);
        if ($exists) {
            return;
        }
        $rate       = (float)config('store.commission', 20);
        $commission = round_money($gross * $rate / 100);
        $net        = round_money($gross - $commission);

        YxDb::insert('developer_settlement', [
            'developer_id' => $devId,
            'app_id'       => (int)$app['id'],
            'order_id'     => $orderId,
            'gross'        => $gross,
            'commission'   => $commission,
            'net'          => $net,
            'status'       => 'pending',
            'created_at'   => now(),
        ]);
    }
}
