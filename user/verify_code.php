<?php
/**
 * 优享财务系统 · 获取注册验证码（AJAX）
 *
 * POST _csrf + type(sms|email) + phone|email，返回 JSON：{ok, msg, wait?, demo_code?}
 * demo_code 仅在后台开启「演示模式」时返回，便于未接网关时联调。
 *
 * 兼容旧调用：不传 type 时按手机号短信处理（等价于 user/sms_code.php）。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'msg' => '请使用 POST 请求'], 405);
}

// 手动校验 CSRF，失败时返回 JSON 而不是纯文本
$token = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], $token)) {
    json_out(['ok' => false, 'msg' => '页面已过期，请刷新后重试'], 419);
}

$type = (string)($_POST['type'] ?? 'sms');

if ($type === 'email') {
    if (!YxMail::enabled()) {
        json_out(['ok' => false, 'msg' => '当前未开启邮箱验证']);
    }

    $email = YxMail::normalizeEmail((string)($_POST['email'] ?? ''));
    if ($email === '') {
        json_out(['ok' => false, 'msg' => '请输入正确的邮箱地址']);
    }

    // 已注册的邮箱不再发信，避免打扰与浪费额度
    $taken = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE email = ?', [$email]);
    if ($taken) {
        json_out(['ok' => false, 'msg' => '该邮箱已被注册，请直接登录或更换邮箱']);
    }

    $res = YxMail::issue($email, client_ip());
    if (!empty($res['ok'])) {
        log_action('customer.email_code', YxMail::maskEmail($email));
    }
    json_out($res);
}

if (!YxSms::enabled()) {
    json_out(['ok' => false, 'msg' => '当前未开启手机号验证']);
}

$phone = YxSms::normalizePhone((string)($_POST['phone'] ?? ''));
if ($phone === '') {
    json_out(['ok' => false, 'msg' => '请输入正确的 11 位手机号']);
}

// 已被注册的手机号不再发码，避免浪费短信额度
$taken = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE phone = ?', [$phone]);
if ($taken) {
    json_out(['ok' => false, 'msg' => '该手机号已被注册，请直接登录或更换手机号']);
}

$res = YxSms::issue($phone, client_ip());
if (!empty($res['ok'])) {
    log_action('customer.sms_code', YxSms::maskPhone($phone));
}

json_out($res);
