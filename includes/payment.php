<?php
/**
 * 优享财务系统 · 支付网关辅助
 *
 * 采用「易支付 / 聚合码 / 码支付」通用协议：参数排序拼接后追加商户密钥做 MD5 签名。
 * 下单与回调共用本文件，避免两处实现不一致导致的验签失败。
 *
 * 两种下单模式（由后台 pay_mode 决定，默认 mapi）：
 *  - mapi：POST {网关}/mapi.php 拿到 JSON，页面内直接渲染二维码，用户扫码付款
 *  - submit：POST 表单跳转 {网关}/submit.php 收银台，由网关选择支付方式
 * 通道不支持 mapi（返回 code=0）时自动降级为收银台跳转。
 */

/**
 * 生成/校验网关签名
 *
 * 实测（梦码支付 / 易支付）：sign_type 不参与签名，仅 sign 本身与空值排除。
 */
function yx_pay_sign(array $params, string $key): string
{
    unset($params['sign'], $params['sign_type'], $params['_csrf']);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return md5(implode('&', $pairs) . $key);
}

class YxPayment
{
    /** 下单模式：mapi（默认，扫码）/ submit（跳转收银台） */
    public static function mode(): string
    {
        $mode = strtolower(trim((string)setting('pay_mode', 'mapi')));
        return in_array($mode, ['mapi', 'submit'], true) ? $mode : 'mapi';
    }

    /**
     * 页面内展示模式：仅扫码需要站点自己渲染二维码
     */
    public static function isNative(): bool
    {
        return self::mode() === 'mapi';
    }

    /** 支付通道是否已配置 */
    public static function configured(): bool
    {
        return trim((string)setting('pay_gateway_url', '')) !== ''
            && trim((string)setting('pay_pid', '')) !== ''
            && trim((string)setting('pay_key', '')) !== '';
    }

    /** 网关基地址（去掉尾部斜杠） */
    private static function gateway(): string
    {
        return rtrim(trim((string)setting('pay_gateway_url', '')), '/');
    }

    /** 支付方式显示名 */
    public static function typeName(string $type): string
    {
        return $type === 'wxpay' ? '微信支付' : '支付宝';
    }

    /**
     * 通道是否支持页面内就地展示二维码（仅支付宝的 mapi 返回可直接渲染的二维码图片地址；
     * 微信通道多数网关只给收银台，需要跳转，故走「跳转到微信收银台」）
     */
    public static function supportsNativeQr(string $type): bool
    {
        return $type === 'alipay';
    }

    /**
     * 直接跳转收银台的地址（无法内嵌二维码时使用）
     * 各网关submit.php 若支持 GET 会直接进入对应支付方式页
     */
    public static function cashierUrl(array $order, string $type = 'alipay', string $subject = null, string $returnUrl = null): string
    {
        return self::gateway() . '/submit.php?' . http_build_query(self::buildParams($order, $type, $subject, $returnUrl));
    }

    /**
     * 组装下单参数（下单与验签共用同一份参数，保证签名一致）
     * @param string|null $subject   订单标题（默认「账户充值」）
     * @param string|null $returnUrl 支付完成回跳地址（默认充值页）
     */
    public static function buildParams(array $order, string $type = 'alipay', string $subject = null, string $returnUrl = null): array
    {
        $params = [
            'pid'          => trim((string)setting('pay_pid', '')),
            'type'         => $type,
            'out_trade_no' => (string)$order['sn'],
            'notify_url'   => self::absolute('api/pay_notify.php'),
            'return_url'   => self::absolute($returnUrl ?: url('user/recharge.php?sn=' . urlencode((string)$order['sn']))),
            'name'         => $subject ?: '账户充值',
            'money'        => number_format((float)$order['total'], 2, '.', ''),
            'sign_type'    => 'MD5',
        ];
        $params['sign'] = yx_pay_sign($params, trim((string)setting('pay_key', '')));
        return $params;
    }

    /**
     * 保证地址是 http(s) 绝对地址
     * 网关会拒收相对路径（「异步通知地址不合法」），而定时任务 / CLI 场景下 url() 可能没有 Host。
     */
    public static function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = rtrim((string)setting('site_url', ''), '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            $host = site_domain();
            $base = 'https://' . ($host !== '' ? $host : 'www.example.com');
        }
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * 兼容旧调用点：默认输出收银台跳转表单
     * 扫码模式请使用 createOrder() + 前台渲染，或 renderCheckout()
     */
    public static function buildSubmitForm(array $order, string $type = 'alipay', string $subject = null, string $returnUrl = null): string
    {
        if (!self::configured()) {
            return '';
        }
        return self::submitForm(self::buildParams($order, $type, $subject, $returnUrl));
    }

    /** 生成跳转网关收银台的自动提交表单 */
    private static function submitForm(array $params): string
    {
        $fields = '';
        foreach ($params as $k => $v) {
            $fields .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        $action = self::gateway() . '/submit.php';

        return '<form id="yxpay" method="post" action="' . e($action) . '">' . $fields . '</form>'
            . '<script>document.getElementById("yxpay").submit();</script>';
    }

    /**
     * 发起支付：扫码模式调网关 mapi.php 取二维码，失败自动降级为收银台跳转
     *
     * @return array{ok:bool,mode:string,html:string,msg:string,qrcode:string,urlscheme:string,trade_no:string}
     */
    public static function createOrder(array $order, string $type = 'alipay', string $subject = null, string $returnUrl = null): array
    {
        $fail = ['ok' => false, 'mode' => '', 'html' => '', 'msg' => '支付通道未配置', 'qrcode' => '', 'urlscheme' => '', 'trade_no' => ''];

        if (!self::configured()) {
            return $fail;
        }

        $params = self::buildParams($order, $type, $subject, $returnUrl);

        if (!self::isNative()) {
            return ['ok' => true, 'mode' => 'submit', 'html' => self::submitForm($params), 'msg' => '', 'qrcode' => '', 'urlscheme' => '', 'trade_no' => ''];
        }

        [$ok, $code, $body] = http_post_form(self::gateway() . '/mapi.php', $params, 15);
        $json = is_string($body) ? json_decode($body, true) : null;

        // code 兼容：1（易支付通用）/ 200（码支付通用）
        $gatewayOk = is_array($json)
            && in_array((string)($json['code'] ?? ''), ['1', '200'], true)
            && trim((string)($json['qrcode'] ?? '')) !== '';

        // 微信等通道在部分网关只出收银台、mapi 不返回二维码：此时降级为跳转收银台（按 type 直达微信/支付宝）
        $needCashier = is_array($json)
            && in_array((string)($json['code'] ?? ''), ['1', '200'], true)
            && stripos((string)($json['msg'] ?? $json['message'] ?? ''), '收银台') !== false;

        if ($gatewayOk) {
            return [
                'ok'        => true,
                'mode'      => 'qrcode',
                'html'      => '',
                'msg'       => '',
                'qrcode'    => trim((string)$json['qrcode']),
                'urlscheme' => (string)($json['urlscheme'] ?? ''),
                'trade_no'  => (string)($json['trade_no'] ?? ''),
            ];
        }

        if ($needCashier) {
            return ['ok' => true, 'mode' => 'submit', 'html' => self::submitForm($params), 'msg' => '', 'qrcode' => '', 'urlscheme' => '', 'trade_no' => (string)($json['trade_no'] ?? '')];
        }

        // 网关明确拒绝（验签、参数、额度等）：直接报错，避免用户跳转后无法支付
        $msg = '';
        if (is_array($json)) {
            $msg = (string)($json['msg'] ?? $json['message'] ?? '');
        }
        $unsupported = $code === 0 || stripos($msg, '必填pid') !== false;

        if (!$unsupported && $msg !== '') {
            return array_merge($fail, ['msg' => '支付下单失败：' . $msg]);
        }

        // 网关不支持 mapi：降级为收银台跳转
        return ['ok' => true, 'mode' => 'submit', 'html' => self::submitForm($params), 'msg' => '', 'qrcode' => '', 'urlscheme' => '', 'trade_no' => ''];
    }

    /**
     * 下单页/支付页默认选中的支付方式
     * 微信通道需跳转收银台，使用户先自主选择
     */
    public static function defaultType(): string
    {
        return 'alipay';
    }

    /**
     * 渲染支付方式选择条（微信 / 支付宝），data-type 由前端切换
     */
    public static function renderTypeTabs(string $active = 'alipay'): string
    {
        $html = '<div class="pay-methods mb-3">';
        foreach (['wxpay' => '微信支付', 'alipay' => '支付宝'] as $type => $label) {
            $html .= '<button type="button" class="btn ' . ($type === $active ? 'btn-primary' : 'btn-ghost') . ' btn-sm"'
                . ' data-yx-pay-type="' . e($type) . '">' . e($label) . '</button> ';
        }
        return $html . '</div>';
    }

    /**
     * 渲染「跳转收银台」按钮（微信支付在多数网关只能跳转，无法页面内出码）
     */
    public static function renderCashierButton(array $order, string $type = 'wxpay', string $subject = null, string $returnUrl = null, string $label = ''): string
    {
        if (!self::configured()) {
            return '<p class="muted">在线支付通道未配置，请联系管理员。</p>';
        }
        $label = $label !== '' ? $label : ('使用' . self::typeName($type) . '支付');
        return '<form method="post" action="' . e(self::gateway() . '/submit.php') . '" class="mt-2">'
            . implode('', array_map(
                static fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">',
                array_keys(self::buildParams($order, $type, $subject, $returnUrl)),
                array_values(self::buildParams($order, $type, $subject, $returnUrl))
            ))
            . '<button class="btn btn-primary btn-block" type="submit">' . e($label) . '</button>'
            . '</form>';
    }

    /**
     * 统一渲染支付区：扫码模式就地显示二维码并轮询到账，其余模式自动跳转收银台
     *
     * 微信通道：网关 mapi 不返回二维码时，直接给出「跳转微信收银台」按钮，页面内不再报错。
     *
     * @param array $order 订单记录（需含 sn / total）
     * @param string $pollUrl 到账轮询地址（返回 JSON：{"paid":true|false}）
     */
    public static function renderCheckout(array $order, string $type = 'alipay', string $subject = null, string $returnUrl = null, string $pollUrl = ''): string
    {
        if (!self::configured()) {
            return '<p class="muted">在线支付通道未配置，请联系管理员。</p>';
        }

        $poll = $pollUrl !== '' ? $pollUrl : url('api/pay_status.php?sn=' . urlencode((string)$order['sn']));
        $back = $returnUrl ?: url('user/recharge.php?sn=' . urlencode((string)$order['sn']));

        if ($type !== 'alipay') {
            // 微信：直接跳转收银台（页面内 isNative 也走跳转）
            return '<div class="yx-checkout yx-checkout-wx">'
                . '<div class="yx-qr-tip">请点击下方按钮前往<strong>' . e(self::typeName($type)) . '</strong>付款 <strong>' . e(money((float)$order['total'], true)) . '</strong></div>'
                . '<div class="yx-qr-tip muted small">订单号 <code>' . e((string)$order['sn']) . '</code></div>'
                . self::renderCashierButton($order, $type, $subject, $back, '前往' . self::typeName($type) . '扫码付款')
                . '</div>'
                . self::pollScript($poll, $back);
        }

        $res = self::createOrder($order, $type, $subject, $returnUrl);
        if (!$res['ok']) {
            return '<div class="alert alert-error">' . e($res['msg']) . '</div>';
        }

        if ($res['mode'] === 'submit') {
            return '<p class="muted small">正在跳转支付页面…</p>' . $res['html'];
        }

        $qrJson = json_encode($res['qrcode'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $schemeJson = json_encode($res['urlscheme'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $money = money((float)$order['total'], true);
        $sn = e((string)$order['sn']);

        return '<div class="yx-checkout" data-poll="' . e($poll) . '">'
            . '<div class="yx-qr" id="yxQrBox"></div>'
            . '<div class="yx-qr-tip">请使用手机 App 扫码支付 <strong>' . e($money) . '</strong></div>'
            . '<div class="yx-qr-tip muted small">订单号 <code>' . $sn . '</code></div>'
            . '<div id="yxUrlScheme" class="mt-2"></div>'
            . '<div class="yx-qr-status" id="yxQrStatus">等待支付…</div>'
            . '<script src="' . e(url('assets/js/qrcode.min.js')) . '"></script>'
            . '<script>(function(){'
            . 'var box=document.getElementById("yxQrBox");'
            . 'var text=' . $qrJson . ';'
            . 'var scheme=' . $schemeJson . ';'
            . 'try{new QRCode(box,{text:text,width:220,height:220});}catch(e){box.innerHTML="";var img=new Image();img.width=220;img.height=220;img.src=' . json_encode(url('api/pay_qr.php'), JSON_UNESCAPED_SLASHES) . '+"?text="+encodeURIComponent(text);box.appendChild(img);}'
            . 'if(scheme&&/android|iphone|ipad|mobile/i.test(navigator.userAgent)){var a=document.createElement("a");a.className="btn btn-primary mt-2";a.href=scheme;a.textContent="打开 App 支付";document.getElementById("yxUrlScheme").appendChild(a);}'
            . 'var st=document.getElementById("yxQrStatus");var n=0;'
            . 'var timer=setInterval(function(){n++;fetch(document.querySelector(".yx-checkout").getAttribute("data-poll"),{credentials:"same-origin",cache:"no-store"})'
            . '.then(function(r){return r.json();})'
            . '.then(function(j){if(j&&j.paid){clearInterval(timer);st.className="yx-qr-status ok";st.textContent="支付成功，正在入账…";setTimeout(function(){location.href=' . json_encode($back, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';},1200);}else if(n%5===0){st.textContent="等待支付…（已等待 "+(n*4)+" 秒）";}})'
            . '.catch(function(){});},4000);'
            . '})();</script>'
            . '</div>';
    }

    /** 轮询到账的独立脚本（跳转收银台的支付方式使用，回到本页后自动跳转到回跳地址） */
    private static function pollScript(string $poll, string $back): string
    {
        return '<script>(function(){var n=0;var timer=setInterval(function(){n++;'
            . 'fetch(' . json_encode($poll, JSON_UNESCAPED_SLASHES) . ',{credentials:"same-origin",cache:"no-store"})'
            . '.then(function(r){return r.json();})'
            . '.then(function(j){if(j&&j.paid){clearInterval(timer);location.href=' . json_encode($back, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';}})'
            . '.catch(function(){});},5000);})();</script>';
    }

    /**
     * 校验回调签名
     *
     * 不同网关（易支付 / 梦码码支付）对 sign_type 是否参与签名的处理不一致，
     * 且个别网关会附带空值或额外字段，因此逐个候选算法尝试，任一命中即通过（都需商户密钥）。
     */
    public static function verifyNotify(array $params): array
    {
        $key = trim((string)setting('pay_key', ''));
        $pid = trim((string)setting('pay_pid', ''));
        if ($key === '' || $pid === '') {
            return ['ok' => false, 'msg' => '支付通道未配置'];
        }
        $sign = (string)($params['sign'] ?? '');
        if ($sign === '') {
            return ['ok' => false, 'msg' => '缺少签名'];
        }
        if (!self::signMatches($params, $key, $sign)) {
            return ['ok' => false, 'msg' => '验签失败'];
        }
        if ((string)($params['pid'] ?? '') !== $pid) {
            return ['ok' => false, 'msg' => '商户号不匹配'];
        }
        return ['ok' => true, 'msg' => 'ok'];
    }

    /** 回调签名候选算法比对（大小写、sign_type 是否参与、空值是否过滤） */
    private static function signMatches(array $params, string $key, string $sign): bool
    {
        $sign = strtolower(trim($sign));
        $candidates = [];

        // 主算法：排除 sign / sign_type，过滤空值，ksort
        $candidates[] = yx_pay_sign($params, $key);

        // 变体 1：sign_type 参与签名
        $noSign = $params;
        unset($noSign['sign']);
        $noSign = array_filter($noSign, static fn($v) => $v !== '' && $v !== null);
        ksort($noSign);
        $pairs = [];
        foreach ($noSign as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $candidates[] = md5(implode('&', $pairs) . $key);

        // 变体 2：不过滤空值
        $raw = $params;
        unset($raw['sign'], $raw['sign_type']);
        ksort($raw);
        $pairs = [];
        foreach ($raw as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $candidates[] = md5(implode('&', $pairs) . $key);

        // 变体 3：不过滤空值且 sign_type 参与
        $raw2 = $params;
        unset($raw2['sign']);
        ksort($raw2);
        $pairs = [];
        foreach ($raw2 as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $candidates[] = md5(implode('&', $pairs) . $key);

        foreach ($candidates as $calc) {
            if (hash_equals($calc, $sign)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析回调参数：兼容 GET / POST 表单 / JSON / text 原文（部分网关以 JSON 推送）
     */
    public static function readNotifyParams(): array
    {
        $params = array_merge($_GET, $_POST);

        $raw = '';
        if (empty($params)) {
            $raw = (string)file_get_contents('php://input');
        } elseif (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
            $raw = (string)file_get_contents('php://input');
        } elseif (!empty($_POST) && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'form') === false) {
            $raw = (string)file_get_contents('php://input');
        }

        $raw = trim($raw);
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $params = array_merge($params, array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $json));
            } elseif (strpos($raw, '=') !== false) {
                $parsed = [];
                parse_str($raw, $parsed);
                if (is_array($parsed)) {
                    $params = array_merge($params, $parsed);
                }
            }
        }

        return $params;
    }

    /**
     * 回调业务状态是否成功
     * 易支付用 trade_status（TRADE_SUCCESS / TRADE_FINISHED），码支付等仅通知成功单，可能不带该字段
     */
    public static function isTradeSuccess(array $params): bool
    {
        $status = strtoupper(trim((string)($params['trade_status'] ?? '')));
        if ($status === '') {
            return true;
        }
        return in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS'], true);
    }

    /**
     * 主动查单（网关支持时）：返回 paid=true 表示网关确认已收款
     * 仅作为回调的兜底，不影响主流程；查单失败一律返回 paid=false（不自动入账）
     */
    public static function queryPaid(string $orderSn, string $gwTradeNo = '', float $expectMoney = 0): array
    {
        if (!self::configured() || $orderSn === '') {
            return ['ok' => false, 'paid' => false, 'msg' => '通道未配置'];
        }
        $params = [
            'pid'       => trim((string)setting('pay_pid', '')),
            'key'       => trim((string)setting('pay_key', '')),
            'act'       => 'query',
            'out_trade_no' => $orderSn,
        ];
        if ($gwTradeNo !== '') {
            $params['trade_no'] = $gwTradeNo;
        }
        $url = self::gateway() . '/api.php?' . http_build_query($params);
        $body = http_get($url, 10);
        $json = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($json)) {
            return ['ok' => false, 'paid' => false, 'msg' => '查单接口无响应'];
        }

        $paidFlag = $json['status'] ?? $json['trade_status'] ?? $json['paid'] ?? '';
        $isPaid = in_array((string)$paidFlag, ['1', 'TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS', 'paid'], true)
            || in_array((string)($json['code'] ?? ''), ['1', '200'], true) && (($json['trade_status'] ?? '') === 'TRADE_SUCCESS');

        $money = round_money($json['money'] ?? $json['amount'] ?? 0);
        if ($isPaid && $expectMoney > 0 && $money > 0 && abs($money - $expectMoney) > 0.01) {
            return ['ok' => false, 'paid' => false, 'msg' => '查单金额不一致'];
        }

        return ['ok' => true, 'paid' => (bool)$isPaid, 'msg' => (string)($json['msg'] ?? $json['message'] ?? '')];
    }
}
