<?php
/**
 * 优享财务系统 · 客户端注册
 *
 * 后台「系统设置 → 短信 / 邮箱验证」中的两个开关相互独立：
 *   - 开启短信验证：手机号必填，必须通过短信验证码校验
 *   - 开启邮箱验证：邮箱必填，必须通过邮箱验证码校验
 * 任一开启时都会校验对应字段的重复注册。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

if (YxAuth::customer()) {
    redirect(url('user/index.php'));
}

$smsOn   = YxSms::enabled();
$mailOn  = YxMail::enabled();
$error   = '';
$form    = ['username' => '', 'email' => '', 'phone' => '', 'company' => ''];

// 注册方式：手机注册 / 邮箱注册。只决定验证码通道与手机号、邮箱的必填关系，
// 数据库字段与账号体系保持「账号 + 邮箱 + 手机」三者并存。
$mode = yx_auth_mode($_POST['mode'] ?? ($_GET['mode'] ?? 'phone'));
$byPhone = $mode === 'phone';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['username'] = trim((string)($_POST['username'] ?? ''));
    $form['email']    = trim((string)($_POST['email'] ?? ''));
    $form['phone']    = trim((string)($_POST['phone'] ?? ''));
    $form['company']  = trim((string)($_POST['company'] ?? ''));
    $smsCode  = trim((string)($_POST['sms_code'] ?? ''));
    $mailCode = trim((string)($_POST['email_code'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    $rawPhone = $form['phone'];
    // 规范化后入库，避免带空格 / +86 的号码注册后无法按手机号登录
    $phone = YxSms::normalizePhone($rawPhone);
    if ($phone !== '') {
        $form['phone'] = $phone;
    }
    // 邮箱统一小写，避免 Foo@x.com 与 foo@x.com 重复注册
    $email = YxMail::normalizeEmail($form['email']);
    if ($email !== '') {
        $form['email'] = $email;
    }

    // 验证码按所选方式收取：手机注册只校验短信码，邮箱注册只校验邮箱码；
    // 未选中的方式仅作为可选联系方式留存（填了才校验格式与重复）。
    $needMailCode = $byPhone ? ($mailOn && $form['email'] !== '') : $mailOn;
    $needSmsCode  = $byPhone ? $smsOn : false;

    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $form['username'])) {
        $error = '账号需 3-20 位，支持中英文、数字与下划线';
    } elseif (!$byPhone && $form['email'] === '') {
        $error = '请输入邮箱地址';
    } elseif ($rawPhone !== '' && $phone === '') {
        $error = '手机号格式不正确，请填写 11 位手机号或留空';
    } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = '请输入正确的邮箱地址';
    } elseif ($needMailCode && $mailCode === '') {
        $error = '请输入邮箱验证码';
    } elseif ($needSmsCode && $phone === '') {
        $error = '请输入正确的 11 位手机号';
    } elseif ($needSmsCode && $smsCode === '') {
        $error = '请输入短信验证码';
    } elseif (strlen($password) < 8) {
        $error = '密码至少 8 位';
    } elseif ($password !== $password2) {
        $error = '两次输入的密码不一致';
    } elseif (empty($_POST['agree'])) {
        $error = '请阅读并同意服务条款';
    } else {
        $dup = YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE username = ?',
            [$form['username']]
        );
        if ($dup) {
            $error = '该账号已被注册';
        } elseif ($form['email'] !== '' && YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE email = ?',
            [$form['email']]
        )) {
            $error = '该邮箱已被注册，请直接登录或更换邮箱';
        } elseif ($form['phone'] !== '' && YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE phone = ?',
            [$form['phone']]
        )) {
            $error = '该手机号已被注册，请直接登录或更换手机号';
        } else {
            // 先校验邮箱、再校验短信：任一失败都不消耗另一方的有效期
            if ($needMailCode && $error === '') {
                $chk = YxMail::verify($form['email'], $mailCode);
                if (empty($chk['ok'])) {
                    $error = $chk['msg'];
                }
            }
            if ($needSmsCode && $error === '') {
                // 校验失败时验证码不消耗有效期，仅计入尝试次数（超限后需重新获取）
                $chk = YxSms::verify($phone, $smsCode);
                if (empty($chk['ok'])) {
                    $error = $chk['msg'];
                }
            }
        }

        if ($error === '') {
            // 推广来源：优先表单隐藏域（由推广链接落地时写入），其次 cookie
            $affCode = trim((string)($_POST['aff'] ?? ''));
            if ($affCode === '') {
                $affCode = trim((string)($_COOKIE['yx_aff'] ?? ''));
            }
            $publisher = $affCode !== '' ? YxAffiliate::customerIdFromCode($affCode) : 0;

            $id = customer_insert([
                'username'     => $form['username'],
                'email'        => $form['email'],
                'phone'        => $form['phone'],
                'company'      => $form['company'],
                'password'     => YxAuth::hash($password),
                'balance'      => 0,
                'credit'       => 0,
                'points'       => 0,
                'level'        => 'normal',
                'agent_id'     => 0,
                'publisher_id' => $publisher,   // 0 = 无推广来源
                'status'       => 1,
                'reg_ip'       => client_ip(),
                'created_at'   => now(),
            ]);
            // 推广码无效或绑定失败不影响注册结果
            if ($publisher > 0) {
                YxAffiliate::bindPublisher($id, $affCode);
                log_action('affiliate.referral', 'customer#' . $id, ['publisher' => $publisher]);
            }
            if (!empty($_COOKIE['yx_aff'])) {
                setcookie('yx_aff', '', time() - 3600, '/');
            }
            $_SESSION['cust_id']   = $id;
            $_SESSION['cust_name'] = $form['username'];
            $_SESSION['cust_time'] = time();
            flash('注册成功，欢迎加入！');
            redirect(url('user/index.php'));
        }
    }
}

$pageTitle = '免费注册';
$siteName  = setting('site_name', config('app.name'));

// 推广来源：?aff= 已由 YxAffiliate::captureVisitor() 在校验有效后写入 cookie，
// 这里只回读，并在 cookie 失效时退回 URL 参数（保留直接访问注册页的场景）。
$affCode = trim((string)($_COOKIE['yx_aff'] ?? ''));
if ($affCode === '' && isset($_GET['aff'])) {
    $affCode = trim((string)$_GET['aff']);
}
if ($affCode === '' || YxAffiliate::customerIdFromCode($affCode) <= 0) {
    $affCode = '';
}
// 后台开启验证但通道不可用时，前台给出明确原因，而不是让用户反复点「获取验证码」失败
$smsIssue  = $smsOn ? YxSms::configIssue() : '';
$mailIssue = $mailOn ? YxMail::configIssue() : '';
$smsDemo   = $smsOn && YxSms::mock();
$mailDemo  = $mailOn && YxMail::demo();
$codeUrl   = url('user/verify_code.php');
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e($siteName) ?></title>
<link rel="icon" href="<?= e(url('assets/logo/favicon.png')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/logo/favicon.png') ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/auth.css') ?>">
</head>
<body class="yx-auth-body">
<div class="yx-auth">

    <aside class="yx-auth-hero">
        <span class="yx-auth-art" aria-hidden="true">
            <i class="art-orb"></i>
            <i class="art-ring"></i>
            <i class="art-line-1"></i>
            <i class="art-line-2"></i>
        </span>
        <div class="yx-auth-hero-inner">
            <a class="yx-auth-brand" href="<?= e(url('index.php')) ?>">
                <img src="<?= e(yx_logo_url()) ?>" alt="<?= e($siteName) ?>" onerror="this.style.display='none'">
                <span><?= e($siteName) ?></span>
            </a>

            <div class="yx-auth-hero-body">
                <h1>注册账户<br>把交付与对账交给系统</h1>
                <p>开通账户即可自助下单与续费，订单、账单与收支流水自动归档；需要更多能力时，还能申请成为开发者，把自己的插件与主题上架到应用商店。</p>
                <div class="yx-auth-actions">
                    <a class="yx-auth-action is-solid" href="<?= e(url('index.php')) ?>">返回首页</a>
                    <a class="yx-auth-action is-ghost" href="<?= e(url('index.php#products')) ?>">了解产品</a>
                </div>
            </div>

            <ul class="yx-auth-points">
                <li>产品自助下单，支付后自动开通</li>
                <li>每日签到达标可兑换应用</li>
                <li>开发者入驻上架商品获得分成</li>
            </ul>
        </div>
    </aside>

    <main class="yx-auth-panel">
        <div class="yx-auth-box">

            <div class="yx-auth-logo">
                <a href="<?= e(url('index.php')) ?>">
                    <img src="<?= e(yx_logo_url()) ?>" alt="<?= e($siteName) ?>" onerror="this.style.display='none'">
                </a>
            </div>

            <div class="yx-auth-head">
                <em><?= e($siteName) ?></em>
                <h1>注册</h1>
                <p>填写以下信息创建您的账户</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" id="formError"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($smsIssue !== '' || $mailIssue !== ''): ?>
                <div class="alert alert-error">
                    <?= $smsIssue !== '' ? '短信验证码服务暂时不可用（' . e($smsIssue) . '），请联系客服后再注册。' : '' ?>
                    <?= $mailIssue !== '' ? ($smsIssue !== '' ? '<br>' : '') . '邮箱验证码服务暂时不可用（' . e($mailIssue) . '），请联系客服后再注册。' : '' ?>
                </div>
            <?php endif; ?>

            <form method="post" id="regForm" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="<?= e($mode) ?>">
                <?php if ($affCode !== ''): ?>
                    <input type="hidden" name="aff" value="<?= e($affCode) ?>">
                <?php endif; ?>

                <div class="yx-auth-field">
                    <label for="regUsername">登录账号</label>
                    <input type="text" id="regUsername" name="username" required autocomplete="username"
                           placeholder="3-20 位，支持中英文、数字与下划线" value="<?= e($form['username']) ?>">
                </div>

                <div class="yx-auth-field">
                    <label for="regEmail">邮箱</label>
                    <input type="email" name="email" id="regEmail" required autocomplete="email"
                           placeholder="<?= $byPhone ? '用于接收账单与账户通知' : '用于接收验证码与账户通知' ?>"
                           value="<?= e($form['email']) ?>">
                </div>

                <?php if ($mailOn && !$byPhone): ?>
                    <div class="yx-auth-field">
                        <label for="regEmailCode">邮箱验证码</label>
                        <div class="yx-auth-row">
                            <input type="text" name="email_code" id="regEmailCode" required inputmode="numeric"
                                   maxlength="6" autocomplete="one-time-code" placeholder="6 位数字验证码">
                            <button class="yx-auth-code-btn" type="button" id="emailSendBtn"<?= $mailIssue !== '' ? ' disabled' : '' ?>
                                    data-type="email" data-input="regEmail"
                                    data-url="<?= e($codeUrl) ?>" data-token="<?= e($csrfToken) ?>">获取验证码</button>
                        </div>
                        <p class="yx-auth-code-tip" id="emailTip">
                            <?php if ($mailDemo): ?>
                                演示模式：验证码会直接显示在此处，同时记录到 runtime/mail.log。
                            <?php elseif ($mailIssue !== ''): ?>
                                邮件服务尚未配置（<?= e($mailIssue) ?>），暂时无法发送验证码。
                            <?php else: ?>
                                验证码 10 分钟内有效，同一邮箱 60 秒内只能获取一次，请留意垃圾邮件箱。
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="yx-auth-field">
                    <label for="regPhone">手机号<?= $byPhone ? '' : '（选填）' ?></label>
                    <input type="tel" name="phone" id="regPhone"<?= $byPhone ? ' required' : '' ?> inputmode="numeric"
                           <?= $byPhone ? 'maxlength="11" ' : '' ?>autocomplete="tel"
                           placeholder="<?= $byPhone ? '请输入 11 位手机号' : '便于接收到期与续费提醒' ?>"
                           value="<?= e($form['phone']) ?>">
                </div>

                <?php if ($smsOn && $byPhone): ?>
                    <div class="yx-auth-field">
                        <label for="regSmsCode">短信验证码</label>
                        <div class="yx-auth-row">
                            <input type="text" name="sms_code" id="regSmsCode" required inputmode="numeric"
                                   maxlength="6" autocomplete="one-time-code" placeholder="6 位数字验证码">
                            <button class="yx-auth-code-btn" type="button" id="smsSendBtn"<?= $smsIssue !== '' ? ' disabled' : '' ?>
                                    data-type="sms" data-input="regPhone"
                                    data-url="<?= e($codeUrl) ?>" data-token="<?= e($csrfToken) ?>">获取验证码</button>
                        </div>
                        <p class="yx-auth-code-tip" id="smsTip">
                            <?php if ($smsDemo): ?>
                                演示模式：验证码会直接显示在此处，同时记录到 runtime/sms.log。
                            <?php elseif ($smsIssue !== ''): ?>
                                短信通道尚未配置（<?= e($smsIssue) ?>），暂时无法发送验证码。
                            <?php else: ?>
                                验证码 5 分钟内有效，同一手机号 60 秒内只能获取一次。
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="yx-auth-field">
                    <label for="regCompany">公司名称（选填）</label>
                    <input type="text" id="regCompany" name="company" placeholder="用于开具发票与合同签署"
                           value="<?= e($form['company']) ?>">
                </div>

                <div class="yx-auth-field">
                    <label for="regPassword">登录密码（≥8 位）</label>
                    <input type="password" id="regPassword" name="password" required autocomplete="new-password"
                           placeholder="至少 8 位，建议包含字母与数字">
                </div>

                <div class="yx-auth-field">
                    <label for="regPassword2">确认密码</label>
                    <input type="password" id="regPassword2" name="password2" required autocomplete="new-password"
                           placeholder="请再次输入登录密码">
                </div>

                <label class="yx-auth-legal" for="regAgree">
                    <input type="checkbox" id="regAgree" name="agree" value="1" <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
                    <span>我已阅读并同意 <a href="<?= e(url('about.php')) ?>" target="_blank">服务条款</a> 与 <a href="<?= e(url('about.php')) ?>" target="_blank">隐私政策</a></span>
                </label>

                <button class="yx-auth-submit" type="submit">注 册</button>
            </form>

            <p class="yx-auth-switch">已有账户？<a href="<?= e(url('user/login.php')) ?>">立即登录</a></p>

            <div class="yx-auth-modes">
                <a class="yx-auth-mode<?= $byPhone ? ' is-active' : '' ?>" href="<?= e(url('user/register.php?mode=phone')) ?>"
                   <?= $byPhone ? 'aria-current="true"' : '' ?>>手机注册</a>
                <a class="yx-auth-mode<?= $byPhone ? '' : ' is-active' ?>" href="<?= e(url('user/register.php?mode=email')) ?>"
                   <?= $byPhone ? '' : 'aria-current="true"' ?>>邮箱注册</a>
            </div>

            <div class="yx-auth-foot">
                <a href="<?= e(url('index.php')) ?>">← 返回首页</a>
                <a href="<?= e(url('user/login.php')) ?>">已有账号登录</a>
            </div>

            <?php require dirname(__DIR__) . '/includes/logo_wall.php'; ?>
        </div>
    </main>
</div>
<?php if ($smsOn || $mailOn): ?>
<script>
(() => {
    // 读响应：优先 JSON；会话过期等非 JSON 响应给出兜底提示
    function readJson(res) {
        return res.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                return { ok: false, msg: res.ok ? '服务器返回异常，请稍后重试' : '请求失败（HTTP ' + res.status + '），请刷新页面重试' };
            }
        });
    }

    /**
     * 绑定「获取验证码」按钮：短信与邮箱共用同一套逻辑，只是取值 / 校验方式不同
     * cfg: {btn, tip, input, code, type, check(val)->bool, bad, hint}
     */
    function bindCode(cfg) {
        var btn = cfg.btn, tip = cfg.tip, input = cfg.input, code = cfg.code;
        if (!btn || !tip || !input) { return; }

        var timer = null;
        var left  = 0;

        function setTip(msg, kind) {
            tip.className = 'yx-auth-code-tip' + (kind ? ' is-' + kind : '');
            tip.textContent = msg;
        }

        function tick() {
            if (left <= 0) {
                clearInterval(timer);
                timer = null;
                btn.disabled = false;
                btn.classList.remove('is-waiting');
                btn.textContent = '重新获取';
                return;
            }
            btn.textContent = left + ' 秒后重发';
            left--;
        }

        function countdown(sec) {
            left = sec;
            btn.disabled = true;
            btn.classList.add('is-waiting');
            tick();
            timer = setInterval(tick, 1000);
        }

        function reset() {
            clearInterval(timer);
            timer = null;
            btn.disabled = false;
            btn.classList.remove('is-waiting');
            btn.textContent = '获取验证码';
        }

        btn.addEventListener('click', function () {
            // 用户在倒计时说明已有验证码，避免重复点击覆盖
            if (timer) { return; }

            var val = (input.value || '').trim();
            if (!cfg.check(val)) {
                setTip(cfg.bad, 'err');
                input.focus();
                return;
            }

            btn.disabled = true;
            btn.textContent = '发送中…';
            setTip('正在发送验证码…', 'warn');

            var body = new URLSearchParams();
            body.append('_csrf', btn.getAttribute('data-token'));
            body.append('type', cfg.type);
            body.append(cfg.type === 'email' ? 'email' : 'phone', val);

            fetch(btn.getAttribute('data-url'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(readJson).then(function (data) {
                if (data && data.ok) {
                    setTip(data.demo_code
                        ? '演示模式验证码：' + data.demo_code + '（' + cfg.hint + '）'
                        : (data.msg || '验证码已发送，请注意查收'), 'ok');
                    countdown(60);
                    if (code) { code.focus(); }
                    return;
                }
                setTip((data && data.msg) || '发送失败，请稍后重试', 'err');
                var wait = data && data.wait ? parseInt(data.wait, 10) : 0;
                if (wait > 0) { countdown(wait); } else { reset(); }
            }).catch(function () {
                setTip('网络异常，请检查网络后重试', 'err');
                reset();
            });
        });

        if (code) {
            code.addEventListener('input', function () {
                var v = code.value.replace(/\D/g, '');
                if (v !== code.value) { code.value = v; }
            });
        }
    }

    var phone = document.getElementById('regPhone');

    bindCode({
        btn: document.getElementById('smsSendBtn'),
        tip: document.getElementById('smsTip'),
        input: phone,
        code: document.getElementById('regSmsCode'),
        type: 'sms',
        check: function (val) {
            return /^1[3-9]\d{9}$/.test(val.replace(/[\s\-()（）]/g, '').replace(/^\+?86/, ''));
        },
        bad: '请输入正确的 11 位手机号',
        hint: '5 分钟内有效'
    });

    bindCode({
        btn: document.getElementById('emailSendBtn'),
        tip: document.getElementById('emailTip'),
        input: document.getElementById('regEmail'),
        code: document.getElementById('regEmailCode'),
        type: 'email',
        check: function (val) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val);
        },
        bad: '请输入正确的邮箱地址',
        hint: '10 分钟内有效'
    });

    // 只在数字输入框中拦截明显非法字符，粘贴的 +86 / 空格由后端归一化
    if (phone) {
        phone.addEventListener('input', function () {
            var v = phone.value.replace(/[^\d+\-\s()（）]/g, '');
            if (v !== phone.value) { phone.value = v; }
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
