<?php
/**
 * 优享财务系统 · 正版购买凭证
 *
 * 客户购买官方正版授权（付费的应用 / 授权包 / 授权版本订单）支付成功后，
 * 自动生成一份《正版软件购买证明》，凭证底部加盖签发方（AnchorFlow团队）
 * 电子公章，客户可在用户中心查看、打印或下载 TTF 版 PDF。
 *
 * 凭证只做「已发生的事实」的记录：单据金额、付款时间、授权码均来自订单与授权记录，
 * 不参与授权校验（授权有效期以 yx_license / yx_app_install 为准），因此可以放心对外出示。
 */

class YxCertificate
{
    /** 关联合法的订单类型：付费应用、授权版本 */
    const ORDER_TYPES = ['app', 'edition'];

    /** 凭证默认签发方（后台「关于我们 → 公司全称」留空时使用） */
    const DEFAULT_ISSUER = 'AnchorFlow团队';

    /** 购买方信息最多展示长度 */
    const NAME_MAX = 120;

    /** 支付方式展示名（未配置中文名时原样展示网关标识） */
    const PAY_LABELS = [
        'balance'      => '账户余额',
        'test_gateway' => '在线支付',
        'alipay'       => '支付宝',
        'wxpay'        => '微信支付',
        'wechat'       => '微信支付',
        'offline'      => '银联转账',
        'manual'       => '人工收款',
        'admin'        => '后台代付',
    ];

    /** 签发方：公司全称优先，其次站点名称 */
    public static function issuer(): string
    {
        $company = trim((string)setting('about_company', ''));
        if ($company === '') {
            $company = trim((string)setting('about_company_name', ''));
        }
        return $company !== '' ? $company : self::DEFAULT_ISSUER;
    }

    /** 该订单是否存在正版购买凭证（订单列表 / 授权页据此显示入口） */
    public static function hasForOrder(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        return (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('certificate') . ' WHERE order_id = ?',
            [$orderId]
        ) > 0;
    }

    /** 批量查询：返回 [order_id => sn]，用于订单列表一次性取凭证号 */
    public static function mapByOrders(array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderIds), static function ($v) {
            return $v > 0;
        }));
        if (!$ids) {
            return [];
        }
        $rows = YxDb::all(
            'SELECT order_id, sn FROM ' . YxDb::table('certificate')
            . ' WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['order_id']] = (string)$r['sn'];
        }
        return $out;
    }

    /** 读取某客户名下的凭证（带权限边界：customer_id 必须匹配） */
    public static function find(int $id, int $customerId): ?array
    {
        if ($id <= 0 || $customerId <= 0) {
            return null;
        }
        $row = YxDb::one(
            'SELECT * FROM ' . YxDb::table('certificate') . ' WHERE id = ? AND customer_id = ?',
            [$id, $customerId]
        );
        return $row ?: null;
    }

    /** 客户的凭证列表 */
    public static function forCustomer(int $customerId, int $limit = 100): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('certificate') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$customerId]
        );
    }

    /**
     * 为已支付订单生成凭证（幂等）
     *
     * 支付回调可能重复触发、管理员也可能手动补发，这里按订单号唯一：
     * 已存在则刷新金额 / 授权码等可变字段，不重复插入。
     *
     * @return array{ok:bool,msg:string,id?:int,sn?:string,skip?:bool}
     */
    public static function issueForOrder(int $orderId, bool $force = false): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'msg' => '缺少订单'];
        }
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]);
        if (!$order) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        if ((string)$order['status'] !== 'paid') {
            return ['ok' => false, 'msg' => '订单未支付，不能签发购买凭证'];
        }
        if (!in_array((string)$order['type'], self::ORDER_TYPES, true)) {
            return ['ok' => false, 'msg' => '该订单类型不签发购买凭证', 'skip' => true];
        }

        $data = self::buildData($order);
        if ($data === null) {
            return ['ok' => false, 'msg' => '该订单不是官方正版商品，不签发购买凭证', 'skip' => true];
        }
        if (!$force && (string)$data['product'] !== 'official') {
            return ['ok' => false, 'msg' => '开源版无需购买凭证', 'skip' => true];
        }

        $existing = YxDb::one(
            'SELECT * FROM ' . YxDb::table('certificate') . ' WHERE order_id = ?',
            [$orderId]
        );
        if ($existing) {
            $data['updated_at'] = now();
            YxDb::update('certificate', $data, 'id = :id', ['id' => (int)$existing['id']]);
            return ['ok' => true, 'msg' => '购买凭证已更新', 'id' => (int)$existing['id'], 'sn' => (string)$existing['sn']];
        }

        $data['sn']         = self::nextSn();
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $data['status']     = 'valid';
        $data['sign_hash']  = self::signHash($data);
        $id = YxDb::insert('certificate', $data);

        try {
            log_action('certificate.issue', 'order#' . $orderId, ['sn' => $data['sn']]);
        } catch (Throwable $e) {
            // 审计失败不影响凭证签发
        }
        return ['ok' => true, 'msg' => '购买凭证已生成', 'id' => $id, 'sn' => $data['sn']];
    }

    /**
     * 汇总订单 → 凭证字段
     * 非官方正版（第三方插件 / 免费商品）返回 null，避免给任何交易都盖章。
     */
    public static function buildData(array $order): ?array
    {
        $customer = YxDb::one(
            'SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?',
            [(int)$order['customer_id']]
        );
        $buyer = self::buyerName($customer ?: []);
        $domain = normalize_domain((string)($order['name'] ?? ''));
        $paidAt = (string)($order['paid_at'] ?: now());
        $validFrom = substr($paidAt, 0, 10);

        $data = [
            'customer_id' => (int)$order['customer_id'],
            'order_id'    => (int)$order['id'],
            'order_sn'    => (string)$order['sn'],
            'app_id'      => (int)$order['app_id'],
            'app_key'     => null,
            'app_name'    => (string)($order['name'] ?: '正版授权'),
            'app_version' => null,
            'product'     => 'official',
            'domain'      => $domain !== '' ? $domain : null,
            'license_key' => null,
            'edition'     => null,
            'buyer_name'  => $buyer,
            'amount'      => round_money((float)$order['total']),
            'pay_method'  => self::payLabel((string)($order['pay_method'] ?? '')),
            'paid_at'     => $paidAt,
            'valid_from'  => $validFrom,
            'valid_to'    => null,
            'issuer'      => self::issuer(),
        ];

        if ((string)$order['type'] === 'app') {
            $app = (int)$order['app_id'] > 0
                ? YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [(int)$order['app_id']])
                : null;
            if (!$app || (string)($app['author_type'] ?? '') !== 'official') {
                return null;
            }
            $product = (string)($app['type'] ?? '') === 'package'
                ? YxLicense::packageProduct((string)$app['key'])
                : 'plugin';
            if ($product !== 'official') {
                // 开源版程序包免费分发，不签发购买凭证
                return null;
            }
            $data['app_key']     = (string)$app['key'];
            $data['app_name']    = (string)$app['name'];
            $data['app_version'] = (string)($app['version'] ?? '');
            $data['product']     = 'official';

            $inst = $domain !== ''
                ? YxDb::one(
                    'SELECT * FROM ' . YxDb::table('app_install') . ' WHERE app_id = ? AND domain = ? AND customer_id = ? ORDER BY id DESC LIMIT 1',
                    [(int)$app['id'], $domain, (int)$order['customer_id']]
                )
                : null;
            if (!$inst) {
                $inst = YxDb::one(
                    'SELECT * FROM ' . YxDb::table('app_install') . ' WHERE order_id = ? ORDER BY id DESC LIMIT 1',
                    [(int)$order['id']]
                );
            }
            if ($inst) {
                $data['domain']      = (string)$inst['domain'];
                $data['license_key'] = (string)$inst['license_key'];
                $data['valid_to']    = $inst['expire_at'] ?: null;
            }
            return $data;
        }

        // edition：授权版本购买，授权记录按订单备注中的域名反查
        $meta   = json_decode((string)($order['note'] ?? ''), true) ?: [];
        $d      = normalize_domain((string)($meta['domain'] ?? ''));
        $edition = in_array((string)($meta['edition'] ?? ''), ['basic', 'pro', 'enterprise'], true)
            ? (string)$meta['edition']
            : null;
        $lic = $d !== ''
            ? YxDb::one('SELECT * FROM ' . YxDb::table('license') . ' WHERE domain = ?', [$d])
            : null;
        $data['app_name']    = '优享财务系统 · 授权版本';
        $data['product']     = 'official';
        $data['domain']      = $d !== '' ? $d : $data['domain'];
        $data['edition']     = $lic['edition'] ?? $edition;
        $data['license_key'] = $lic['license_key'] ?? null;
        $data['valid_to']    = $lic['expire_at'] ?: null;
        return $data;
    }

    /** 购买方名称：企业名优先，其次实名，最后账号 */
    public static function buyerName(array $customer): string
    {
        foreach (['company', 'realname', 'username'] as $k) {
            $v = trim((string)($customer[$k] ?? ''));
            if ($v !== '') {
                return mb_substr($v, 0, self::NAME_MAX);
            }
        }
        return '客户';
    }

    public static function payLabel(string $method): string
    {
        if ($method === '') {
            return '—';
        }
        return self::PAY_LABELS[$method] ?? $method;
    }

    public static function editionName(?string $edition): string
    {
        $edition = (string)$edition;
        if ($edition === '') {
            return '—';
        }
        return class_exists('YxEdition') ? YxEdition::name($edition) : $edition;
    }

    /** 凭证编号：PZ + 年月日 + 5 位序号 */
    public static function nextSn(): string
    {
        $prefix = 'PZ' . date('ymd');
        for ($i = 0; $i < 5; $i++) {
            $sn = $prefix . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            if (!YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('certificate') . ' WHERE sn = ?', [$sn])) {
                return $sn;
            }
        }
        return $prefix . substr((string)microtime(true), -6);
    }

    /** 内容摘要：凭证关键字段 + 站点密钥的 HMAC，供客服/官网核验真伪 */
    public static function signHash(array $data): string
    {
        $raw = implode('|', [
            (string)($data['sn'] ?? ''),
            (string)($data['order_sn'] ?? ''),
            (string)($data['domain'] ?? ''),
            (string)($data['license_key'] ?? ''),
            number_format((float)($data['amount'] ?? 0), 2, '.', ''),
            (string)($data['paid_at'] ?? ''),
            (string)($data['issuer'] ?? ''),
        ]);
        return strtoupper(hash_hmac('sha256', $raw, (string)config('app.key', 'youxiang')));
    }

    /** 核验：重算摘要，与库中记录比对 */
    public static function verify(array $cert): bool
    {
        return hash_equals((string)$cert['sign_hash'], self::signHash($cert));
    }

    /** 凭证展示用行数据（键 → [标签, 值]） */
    public static function rows(array $cert): array
    {
        $rows = [
            '购买方'   => (string)$cert['buyer_name'],
            '授权产品' => (string)$cert['app_name'] . ((string)($cert['app_version'] ?? '') !== '' ? ' v' . $cert['app_version'] : ''),
            '产品版本' => self::editionName((string)($cert['edition'] ?? '')),
            '授权域名' => (string)($cert['domain'] ?? '') !== '' ? $cert['domain'] : '—',
            '授权码'   => (string)($cert['license_key'] ?? '') !== '' ? $cert['license_key'] : '—',
            '授权期间' => trim(((string)($cert['valid_from'] ?? '') ?: '-') . ' 至 ' . ((string)($cert['valid_to'] ?? '') ?: '长期')),
            '订单编号' => (string)$cert['order_sn'],
            '交易金额' => money((float)$cert['amount'], true),
            '支付方式' => (string)$cert['pay_method'],
            '支付时间' => (string)$cert['paid_at'],
        ];
        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* PDF 输出（纯 PHP，无需扩展；A4 尺寸，内嵌 STSong-Light 中文字体）       */
    /* ------------------------------------------------------------------ */

    /** 生成并输出 PDF（调用方负责已做权限校验） */
    public static function sendPdf(array $cert, string $filename = ''): void
    {
        $pdf = self::buildPdf($cert);
        if ($filename === '') {
            $filename = '购买证明-' . $cert['sn'] . '.pdf';
        }
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="certificate-' . $cert['sn'] . '.pdf"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, no-store');
        }
        echo $pdf;
        exit;
    }

    /** 组装 PDF 二进制 */
    public static function buildPdf(array $cert): string
    {
        // A4：595.28 x 841.89 pt
        $W = 595.28;
        $H = 841.89;
        $stream = [];

        $text = static function (float $x, float $y, float $size, string $s, string $font = 'F1', string $rgb = '0.13 0.16 0.22') use (&$stream) {
            $stream[] = sprintf("BT /%s %.2f Tf %s rg %.2f %.2f Td <%s> Tj ET", $font, $size, $rgb, $x, $y, self::hex($s));
        };
        $line = static function (float $x1, float $y1, float $x2, float $y2, float $w = 0.7, string $rgb = '0.80 0.84 0.90') use (&$stream) {
            $stream[] = sprintf("%s RG %.2f w %.2f %.2f m %.2f %.2f l S", $rgb, $w, $x1, $y1, $x2, $y2);
        };
        $rect = static function (float $x, float $y, float $w, float $h, string $fill, float $radius = 0) use (&$stream) {
            // PDF 无圆角矩形原语，用贝塞尔近似 4 个圆角
            $k = 0.5523 * $radius;
            if ($radius <= 0) {
                $stream[] = sprintf("%s rg %.2f %.2f %.2f %.2f re f", $fill, $x, $y, $w, $h);
                return;
            }
            $stream[] = sprintf(
                "%s rg %.2f %.2f m %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c "
                . "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c "
                . "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c "
                . "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c f",
                $fill,
                $x + $radius, $y,
                $x + $w - $radius, $y,
                $x + $w - $radius + $k, $y, $x + $w, $y + $radius - $k, $x + $w, $y + $radius,
                $x + $w, $y + $h - $radius,
                $x + $w, $y + $h - $radius + $k, $x + $w - $radius + $k, $y + $h, $x + $w - $radius, $y + $h,
                $x + $radius, $y + $h,
                $x + $radius - $k, $y + $h, $x, $y + $h - $radius + $k, $x, $y + $h - $radius,
                $x, $y + $radius,
                $x, $y + $radius - $k, $x + $radius - $k, $y, $x + $radius, $y
            );
        };

        // 页眉色带
        $rect(0, $H - 96, $W, 96, '0.09 0.36 0.86');
        $text(48, $H - 50, 19, '正版软件购买证明', 'F2', '1 1 1');
        $text(48, $H - 72, 9, 'CERTIFICATE OF GENUINE SOFTWARE PURCHASE', 'F1', '0.82 0.89 1');

        // 凭证编号 / 签发日期
        $text($W - 210, $H - 48, 9.5, '凭证编号：' . $cert['sn'], 'F1', '1 1 1');
        $text($W - 210, $H - 64, 9.5, '签发日期：' . substr((string)$cert['paid_at'], 0, 10), 'F1', '1 1 1');

        // 正文抬头
        $y = $H - 140;
        $text(48, $y, 12, '兹证明', 'F2');
        $y -= 26;
        $buyer = (string)$cert['buyer_name'];
        $text(48, $y, 11, '购买方 ' . $buyer . ' 已通过 ' . setting('site_name', config('app.name')) . ' 完成下列正版软件的付费购买，', 'F1', '0.25 0.29 0.36');
        $y -= 17;
        $text(48, $y, 11, '付款已完成，授权自购买之日起对所列域名生效：', 'F1', '0.25 0.29 0.36');

        // 明细表
        $y -= 30;
        $tableTop = $y;
        $rowH     = 26;
        $rows     = self::rows($cert);
        $labelW   = 118;
        $x0       = 48;
        $w        = $W - 96;
        $i        = 0;
        foreach ($rows as $label => $value) {
            $rowY = $tableTop - ($i + 1) * $rowH;
            $rect($x0, $rowY, $w, $rowH, $i % 2 === 0 ? '0.97 0.98 1' : '1 1 1');
            $line($x0, $rowY, $x0 + $w, $rowY);
            $text($x0 + 12, $rowY + 9, 9.5, $label, 'F1', '0.45 0.50 0.58');
            $text($x0 + $labelW, $rowY + 9, 10.5, self::clip($value, 62), 'F1', '0.13 0.16 0.22');
            $i++;
        }
        $line($x0, $tableTop - count($rows) * $rowH, $x0 + $w, $tableTop - count($rows) * $rowH, 0.7, '0.80 0.84 0.90');
        $line($x0 + $labelW - 12, $tableTop - count($rows) * $rowH, $x0 + $labelW - 12, $tableTop, 0.5, '0.88 0.91 0.95');
        $y = $tableTop - count($rows) * $rowH - 28;

        // 说明
        $notes = [
            '1. 本证明仅用于证明上述订单的正版软件购买事实，授权有效期以「我的授权」页面显示的到期时间为准。',
            '2. 授权码与绑定域名一一对应，更换绑定域名后授权码会重新签发，旧授权码同时失效。',
            '3. 凭证内容如需核验，可将凭证编号与签发方联系核对。',
        ];
        foreach ($notes as $n) {
            $text(48, $y, 8.8, $n, 'F1', '0.45 0.50 0.58');
            $y -= 15;
        }

        // 落款 + 电子公章
        $stampCx = $W - 150;
        $stampCy = $y - 66;
        $stampR  = 62;

        // 印章：外圈 + 内圈 + 五角星 + 环绕文字（沿圆周旋转）
        $stream[] = sprintf("%s RG 2.4 w %.2f %.2f %.2f 0 360 arc S", '0.80 0.10 0.14', $stampCx, $stampCy, $stampR);
        $stream[] = sprintf("%s RG 0.9 w %.2f %.2f %.2f 0 360 arc S", '0.80 0.10 0.14', $stampCx, $stampCy, $stampR - 6);

        // 五角星（实心）
        $starPts = [];
        for ($k = 0; $k < 10; $k++) {
            $ang = -M_PI / 2 + $k * M_PI / 5;
            $rr  = $k % 2 === 0 ? 13.5 : 5.6;
            $starPts[] = [$stampCx + cos($ang) * $rr, $stampCy + sin($ang) * $rr];
        }
        $sp = '';
        foreach ($starPts as $idx => $p) {
            $sp .= sprintf("%.2f %.2f %s ", $p[0], $p[1], $idx === 0 ? 'm' : 'l');
        }
        $stream[] = sprintf('%s rg %sh f', '0.80 0.10 0.14', $sp);

        // 印章文字：环绕公司名 + 中部「合同专用」
        // 文字沿外圈上半周排布，字头朝外（随切线旋转），与实物公章一致
        $ringText = self::clip((string)$cert['issuer'], 16);
        $chars    = self::chars($ringText, 16);
        $count    = count($chars);
        if ($count > 0) {
            $span      = min(2.6, 0.30 * $count);              // 总弧度，字数越多越铺开
            $start     = M_PI / 2 + $span / 2;                 // 从左侧起、顺时针到右侧
            $step      = $count > 1 ? $span / ($count - 1) : 0;
            $ringR     = $stampR - 14;
            $fontSize  = min(9.2, 210 / max(1, $count));       // 字多时自动缩小
            foreach ($chars as $idx => $ch) {
                $ang = $start - $idx * $step;
                $cx  = $stampCx + cos($ang) * $ringR;
                $cy  = $stampCy + sin($ang) * $ringR;
                $deg = rad2deg($ang) - 90;
                $stream[] = sprintf(
                    "BT /F3 %.2f Tf %s rg %.4f %.4f %.4f %.4f %.2f %.2f Tm <%s> Tj ET",
                    $fontSize,
                    '0.80 0.10 0.14',
                    cos(deg2rad($deg)), sin(deg2rad($deg)), -sin(deg2rad($deg)), cos(deg2rad($deg)),
                    $cx - $fontSize / 2, $cy - $fontSize * 0.35,
                    self::hex($ch)
                );
            }
        }
        // 中部横排「合同专用」
        $stream[] = sprintf(
            "BT /F3 8.4 Tf %s rg %.2f %.2f Td <%s> Tj ET",
            '0.80 0.10 0.14',
            $stampCx - 2 * 8.4,
            $stampCy - 30,
            self::hex('合同专用')
        );

        // 落款文字
        $signY = $y - 22;
        $text(48, $signY, 10, '签发单位（盖章）：', 'F1', '0.45 0.50 0.58');
        $text(48, $signY - 20, 12, (string)$cert['issuer'], 'F2');
        $text(48, $signY - 40, 9, '签发日期：' . substr((string)$cert['paid_at'], 0, 10), 'F1', '0.45 0.50 0.58');

        // 页脚
        $text(48, 54, 8, '本凭证由 ' . setting('site_name', config('app.name')) . ' 自动签发，凭证摘要 ' . substr((string)$cert['sign_hash'], 0, 32) . '…', 'F1', '0.58 0.62 0.70');
        $text(48, 40, 8, '官方授权查询：' . self::officialUrl(), 'F1', '0.58 0.62 0.70');

        $content = implode("\n", $stream);

        return self::assemblePdf($W, $H, $content);
    }

    /** 组装 PDF 文件（单页，对象 1-7） */
    private static function assemblePdf(float $W, float $H, string $content): string
    {
        $objects   = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 5 0 R /F2 6 0 R /F3 7 0 R >> >> /Contents 4 0 R >>",
            $W,
            $H
        );
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        // 中文字体：STSong-Light（PDF 非嵌入 CID 字体，主流阅读器均内置）
        $objects[] = "<< /Type /Font /Subtype /Type0 /BaseFont /STSong-Light /Encoding /UniGB-UCS2-H /DescendantFonts [8 0 R] >>";
        $objects[] = "<< /Type /Font /Subtype /Type0 /BaseFont /STSong-Light /Encoding /UniGB-UCS2-H /DescendantFonts [8 0 R] >>";
        $objects[] = "<< /Type /Font /Subtype /Type0 /BaseFont /STSong-Light /Encoding /UniGB-UCS2-H /DescendantFonts [8 0 R] >>";
        $objects[] = "<< /Type /Font /Subtype /CIDFontType0 /BaseFont /STSong-Light "
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (GB1) /Supplement 2 >> "
            . "/FontDescriptor 9 0 R /DW 1000 /W [1 95 500] >>";
        $objects[] = "<< /Type /FontDescriptor /FontName /STSong-Light /Flags 6 /FontBBox [-25 -254 1000 880] "
            . "/ItalicAngle 0 /Ascent 880 /Descent -120 /CapHeight 880 /StemV 93 >>";

        $out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i + 1] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($out);
        $count   = count($objects) + 1;
        $out .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $out;
    }

    /** UTF-8 字符串 → UCS-2 十六进制（PDF Type0 / UniGB-UCS2-H） */
    public static function hex(string $s): string
    {
        $utf16 = @iconv('UTF-8', 'UCS-2BE//IGNORE', $s);
        if ($utf16 === false) {
            return '';
        }
        return strtoupper(bin2hex($utf16));
    }

    /** 按字符切分（避免截断多字节字符） */
    public static function chars(string $s, int $max = 0): array
    {
        $arr = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($max > 0 && count($arr) > $max) {
            $arr = array_slice($arr, 0, $max);
        }
        return $arr;
    }

    /** 按字数截断（超出加省略号），中文按 1 字计 */
    public static function clip(string $s, int $max): string
    {
        $arr = self::chars($s);
        if (count($arr) <= $max) {
            return $s;
        }
        return implode('', array_slice($arr, 0, $max - 1)) . '…';
    }

    /** 官方授权查询地址（用于凭证页脚） */
    public static function officialUrl(): string
    {
        $u = trim((string)setting('site_url', ''));
        if ($u === '') {
            $u = trim((string)setting('license_official_api', ''));
        }
        if ($u === '') {
            $u = 'https://' . trim((string)setting('site_domain', 'www.example.com'));
        }
        return rtrim($u, '/');
    }
}
