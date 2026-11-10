<?php
/**
 * 优享财务系统 · 账号绑定
 *
 * 手机与邮箱的绑定 / 换绑 / 解绑。换绑与解绑都要求验证码校验，避免
 * 账号被盗后把找回通道直接换走。
 *
 * 说明：本站客户表暂无第三方登录（微信 / QQ）字段，因此不在此伪造「已绑定」
 * 状态；未接入的渠道统一显示为「未开通」，等真正接入后再开放。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];

$phoneBound = trim((string)($customer['phone'] ?? ''));
$emailBound = trim((string)($customer['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $code   = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

    /* ---------------- 手机：绑定 / 换绑 ---------------- */
    if ($action === 'phone') {
        $phone = YxSms::normalizePhone((string)($_POST['phone'] ?? ''));
        if ($phone === '') {
            flash('请输入正确的 11 位手机号', 'error');
            redirect(url('user/binding.php'));
        }
        if ($phone === $phoneBound) {
            flash('该手机号已是当前绑定号码', 'error');
            redirect(url('user/binding.php'));
        }
        if (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE phone = ? AND id <> ?', [$phone, $cid])) {
            flash('该手机号已被其他账号绑定', 'error');
            redirect(url('user/binding.php'));
        }
        $ver = YxSms::verify($phone, $code);
        if (empty($ver['ok'])) {
            flash((string)($ver['msg'] ?? '验证码校验失败'), 'error');
            redirect(url('user/binding.php'));
        }
        YxDb::update('customer', ['phone' => $phone], 'id = :id', ['id' => $cid]);
        log_action('customer.bind_phone', $customer['username'], ['phone' => YxSms::maskPhone($phone)]);
        flash($phoneBound === '' ? '手机号绑定成功' : '手机号已更换');
        redirect(url('user/binding.php'));
    }

    /* ---------------- 手机：解绑 ---------------- */
    if ($action === 'phone_unbind') {
        if ($phoneBound === '') {
            flash('当前未绑定手机号', 'error');
            redirect(url('user/binding.php'));
        }
        if ($emailBound === '') {
            flash('请先绑定邮箱，避免同时失去两种找回方式', 'error');
            redirect(url('user/binding.php'));
        }
        $ver = YxSms::verify($phoneBound, $code);
        if (empty($ver['ok'])) {
            flash((string)($ver['msg'] ?? '验证码校验失败'), 'error');
            redirect(url('user/binding.php'));
        }
        YxDb::update('customer', ['phone' => null], 'id = :id', ['id' => $cid]);
        log_action('customer.unbind_phone', $customer['username']);
        flash('手机号已解绑');
        redirect(url('user/binding.php'));
    }

    /* ---------------- 邮箱：绑定 / 换绑 ---------------- */
    if ($action === 'email') {
        $email = YxMail::normalizeEmail((string)($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('请输入正确的邮箱地址', 'error');
            redirect(url('user/binding.php'));
        }
        if ($email === strtolower($emailBound)) {
            flash('该邮箱已是当前绑定邮箱', 'error');
            redirect(url('user/binding.php'));
        }
        if (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE email = ? AND id <> ?', [$email, $cid])) {
            flash('该邮箱已被其他账号绑定', 'error');
            redirect(url('user/binding.php'));
        }
        $ver = YxMail::verify($email, $code);
        if (empty($ver['ok'])) {
            flash((string)($ver['msg'] ?? '验证码校验失败'), 'error');
            redirect(url('user/binding.php'));
        }
        YxDb::update('customer', ['email' => $email], 'id = :id', ['id' => $cid]);
        log_action('customer.bind_email', $customer['username'], ['email' => YxMail::maskEmail($email)]);
        flash($emailBound === '' ? '邮箱绑定成功' : '邮箱已更换');
        redirect(url('user/binding.php'));
    }

    /* ---------------- 邮箱：解绑 ---------------- */
    if ($action === 'email_unbind') {
        if ($emailBound === '') {
            flash('当前未绑定邮箱', 'error');
            redirect(url('user/binding.php'));
        }
        if ($phoneBound === '') {
            flash('请先绑定手机号，避免同时失去两种找回方式', 'error');
            redirect(url('user/binding.php'));
        }
        $ver = YxMail::verify($emailBound, $code);
        if (empty($ver['ok'])) {
            flash((string)($ver['msg'] ?? '验证码校验失败'), 'error');
            redirect(url('user/binding.php'));
        }
        // 邮箱字段是 NOT NULL，解绑时清空为空串而不是 null
        YxDb::update('customer', ['email' => ''], 'id = :id', ['id' => $cid]);
        log_action('customer.unbind_email', $customer['username']);
        flash('邮箱已解绑');
        redirect(url('user/binding.php'));
    }
}

$userTitle  = '账号绑定';
$userActive = 'binding';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="alert alert-warn">
    绑定手机与邮箱后，可用于找回密码与接收安全提醒。换绑与解绑都需要验证码校验；
    <b>手机与邮箱至少保留一项</b>，否则将无法自助找回账号。
</div>

<div class="grid-2 mt-4">
    <div class="card">
        <h2>手机绑定</h2>
        <table class="data">
            <tr>
                <th>当前状态</th>
                <td>
                    <?php if ($phoneBound !== ''): ?>
                        <span class="badge badge-ok">已绑定</span>
                        <span class="mono"><?= e(YxSms::maskPhone($phoneBound)) ?></span>
                    <?php else: ?>
                        <span class="badge badge-mute">未绑定</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <form method="post" class="mt-4" data-bind-form="sms">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="phone">
            <label>手机号（11 位）
                <input type="text" name="phone" value="" placeholder="请输入要绑定的手机号" autocomplete="tel" required>
            </label>
            <label>短信验证码
                <span class="inline-code">
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="6 位验证码" required>
                    <button type="button" class="btn btn-ghost" data-code-btn="sms"
                            data-url="<?= e(url('user/bind_code.php')) ?>"
                            data-csrf="<?= e($_SESSION['_csrf'] ?? '') ?>">获取验证码</button>
                </span>
            </label>
            <button class="btn btn-primary" type="submit"><?= $phoneBound === '' ? '绑定手机' : '更换手机' ?></button>
            <p class="small muted">如未开启短信通道，请联系管理员；也可直接工单申请绑定。</p>
        </form>

        <?php if ($phoneBound !== ''): ?>
        <form method="post" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="phone_unbind">
            <label>解绑验证码（发送至 <?= e(YxSms::maskPhone($phoneBound)) ?>）
                <span class="inline-code">
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="6 位验证码" required>
                    <button type="button" class="btn btn-ghost" data-code-btn="sms"
                            data-url="<?= e(url('user/bind_code.php')) ?>"
                            data-csrf="<?= e($_SESSION['_csrf'] ?? '') ?>"
                            data-phone="<?= e($phoneBound) ?>">获取验证码</button>
                </span>
            </label>
            <button class="btn btn-ghost" type="submit">解绑手机</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>邮箱绑定</h2>
        <table class="data">
            <tr>
                <th>当前状态</th>
                <td>
                    <?php if ($emailBound !== ''): ?>
                        <span class="badge badge-ok">已绑定</span>
                        <span class="mono"><?= e(YxMail::maskEmail($emailBound)) ?></span>
                    <?php else: ?>
                        <span class="badge badge-mute">未绑定</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <form method="post" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="email">
            <label>邮箱地址
                <input type="email" name="email" value="" placeholder="请输入要绑定的邮箱" autocomplete="email" required>
            </label>
            <label>邮箱验证码
                <span class="inline-code">
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="6 位验证码" required>
                    <button type="button" class="btn btn-ghost" data-code-btn="email"
                            data-url="<?= e(url('user/bind_code.php')) ?>"
                            data-csrf="<?= e($_SESSION['_csrf'] ?? '') ?>">获取验证码</button>
                </span>
            </label>
            <button class="btn btn-primary" type="submit"><?= $emailBound === '' ? '绑定邮箱' : '更换邮箱' ?></button>
        </form>

        <?php if ($emailBound !== ''): ?>
        <form method="post" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="email_unbind">
            <label>解绑验证码（发送至 <?= e(YxMail::maskEmail($emailBound)) ?>）
                <span class="inline-code">
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="6 位验证码" required>
                    <button type="button" class="btn btn-ghost" data-code-btn="email"
                            data-url="<?= e(url('user/bind_code.php')) ?>"
                            data-csrf="<?= e($_SESSION['_csrf'] ?? '') ?>"
                            data-email="<?= e($emailBound) ?>">获取验证码</button>
                </span>
            </label>
            <button class="btn btn-ghost" type="submit">解绑邮箱</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-4">
    <h2>第三方账号</h2>
    <table class="data">
        <thead><tr><th>渠道</th><th>状态</th><th>说明</th></tr></thead>
        <tbody>
            <tr><td>微信</td><td><span class="badge badge-mute">未开通</span></td><td class="small muted">当前版本未接入微信登录</td></tr>
            <tr><td>QQ</td><td><span class="badge badge-mute">未开通</span></td><td class="small muted">当前版本未接入 QQ 登录</td></tr>
        </tbody>
    </table>
</div>

<script>
(function () {
    document.querySelectorAll('[data-code-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap = btn.closest('label') || document;
            var type = btn.getAttribute('data-code-btn');
            var form = btn.closest('form');
            var body = new FormData();
            body.append('_csrf', btn.getAttribute('data-csrf') || '');
            body.append('type', type);
            if (type === 'email') {
                var em = btn.getAttribute('data-email') || (form.querySelector('input[name=email]') || {}).value || '';
                body.append('email', em);
            } else {
                var ph = btn.getAttribute('data-phone') || (form.querySelector('input[name=phone]') || {}).value || '';
                body.append('phone', ph);
            }
            btn.disabled = true;
            var old = btn.textContent;
            btn.textContent = '发送中…';
            fetch(btn.getAttribute('data-url'), {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btn.textContent = old;
                    btn.disabled = false;
                    if (!d.ok) { alert(d.msg || '发送失败'); return; }
                    if (d.demo_code) { alert('演示验证码：' + d.demo_code); }
                    else { alert(d.msg || '验证码已发送'); }
                })
                .catch(function () {
                    btn.textContent = old;
                    btn.disabled = false;
                    alert('网络异常，请稍后重试');
                });
        });
    });
})();
</script>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
