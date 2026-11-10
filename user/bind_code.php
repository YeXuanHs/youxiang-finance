<?php
/**
 * 优享财务系统 · 账号绑定发码（AJAX）
 *
 * 与 user/verify_code.php（注册场景）的区别只在「占用校验方向」：
 *   注册：号码已被占用 → 拒绝发码
 *   绑定：号码已被其他账号占用 → 拒绝发码；未被占用 → 允许发码
 * 频率限制、有效期、尝试次数全部复用 YxSms / YxMail 的实现，本文件不重复实现。
 *
 * POST _csrf + type(sms|email) + phone|email
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'msg' => '请使用 POST 请求'], 405);
}

$token = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], $token)) {
    json_out(['ok' => false, 'msg' => '页面已过期，请刷新后重试'], 419);
}

$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];
$type = (string)($_POST['type'] ?? 'sms');

if ($type === 'email') {
    if (!YxMail::enabled()) {
        json_out(['ok' => false, 'msg' => '当前未开启邮箱验证']);
    }
    $email = YxMail::normalizeEmail((string)($_POST['email'] ?? ''));
    if ($email === '') {
        json_out(['ok' => false, 'msg' => '请输入正确的邮箱地址']);
    }
    $taken = (int)YxDb::value(
        'SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE email = ? AND id <> ?',
        [$email, $cid]
    );
    if ($taken) {
        json_out(['ok' => false, 'msg' => '该邮箱已被其他账号绑定']);
    }
    $res = YxMail::issue($email, client_ip());
    if (!empty($res['ok'])) {
        log_action('customer.bind_email_code', YxMail::maskEmail($email));
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
$taken = (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE phone = ? AND id <> ?',
    [$phone, $cid]
);
if ($taken) {
    json_out(['ok' => false, 'msg' => '该手机号已被其他账号绑定']);
}

$res = YxSms::issue($phone, client_ip());
if (!empty($res['ok'])) {
    log_action('customer.bind_sms_code', YxSms::maskPhone($phone));
}
json_out($res);
