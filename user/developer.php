<?php
/**
 * 优享财务系统 · 开发者中心（用户中心）
 *
 * 入驻：提交资料 → 实名扫码核验（个人/企业）→ 缴纳保证金 → 管理员审核 → 上传商品（经商店售卖）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

$act = (string)($_GET['act'] ?? '');

/* ---------------- 实名扫码页（独立渲染） ---------------- */
if ($act === 'qr') {
    $rid = (int)($_GET['id'] ?? 0);
    $rn  = YxDb::one('SELECT * FROM ' . YxDb::table('developer_realname') . ' WHERE id = ? AND customer_id = ?', [$rid, $cid]);
    if (!$rn) {
        http_response_code(404);
        exit('认证单不存在');
    }
    // AI 自动监测或已出结果的认证单没有二维码：直接给出结论，避免出现空白二维码页
    if ((string)$rn['status'] !== 'pending') {
        $passed = (string)$rn['status'] === 'verified';
        flash((string)($rn['message'] ?: ($passed ? 'AI 智能检测通过' : 'AI 智能检测未通过')), $passed ? 'success' : 'error');
        redirect(url('user/developer.php'));
    }
    $qr = (string)$rn['qr_url'];
    // 历史数据 / 镜像站自动转义会把 & 存成 &amp;，会导致扫码链接参数解析失败（签名校验不通过），这里还原为原始链接
    $qr = str_replace(['&amp;', '&#038;'], '&', $qr);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>扫码实名核验</title>
        <style>
            body{font-family:-apple-system,"Microsoft YaHei",sans-serif;background:#f4f6fb;margin:0;padding:40px 16px;color:#1f2733}
            .box{max-width:420px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(20,40,80,.08);padding:28px;text-align:center}
            h1{font-size:18px;margin:0 0 6px}
            .muted{color:#7d8799;font-size:13px}
            #qrcode{margin:18px auto;width:220px;height:220px;display:flex;align-items:center;justify-content:center}
            #qrcode img,#qrcode canvas{width:220px!important;height:220px!important}
            .status{margin-top:10px;font-size:14px}
            a.btn{display:inline-block;margin-top:14px;padding:9px 18px;background:#4dabf7;color:#fff;border-radius:8px;text-decoration:none;font-size:14px}
            .ok{color:#2f9e44}.warn{color:#e8590c}
        </style>
    </head>
    <body>
    <div class="box">
        <h1><?= e(YxRealname::typeName((string)$rn['dev_type'])) ?> · 扫码核验</h1>
        <p class="muted">请使用手机扫描下方二维码完成实名核验</p>
        <div id="qrcode"></div>
        <p class="status" id="st">等待扫码…</p>
        <p class="muted">有效期至 <?= e((string)($rn['expire_at'] ?: '-')) ?>，超时后请重新发起认证</p>
        <p class="muted small" style="margin-top:16px">无法扫码？<a href="<?= e($qr) ?>" target="_blank">点此在浏览器打开核验链接</a>，或到 <a href="<?= e(url('user/realname.php')) ?>">实名认证</a> 页继续</p>
        <a class="btn" href="<?= e(url('user/developer.php')) ?>">返回开发者中心</a>
    </div>
    <script src="<?= e(url('assets/js/qrcode.min.js')) ?>"></script>
    <script>
    (function () {
        var text = <?= json_encode($qr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        try { new QRCode(document.getElementById('qrcode'), { text: text, width: 220, height: 220 }); }
        catch (e) { document.getElementById('qrcode').innerHTML = '<a href="' + text + '">打开核验链接</a>'; }
        var rid = <?= (int)$rn['id'] ?>;
        var st = document.getElementById('st');
        var timer = setInterval(function () {
            fetch('<?= e(url('user/developer.php?act=poll&id=' . (int)$rn['id'])) ?>', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) return;
                    if (d.status === 'verified') {
                        clearInterval(timer);
                        st.className = 'status ok';
                        st.textContent = '实名核验通过，正在返回…';
                        setTimeout(function () { location.href = '<?= e(url('user/developer.php')) ?>'; }, 800);
                    } else if (d.status === 'failed' || d.status === 'expired') {
                        clearInterval(timer);
                        st.className = 'status warn';
                        st.textContent = '核验未通过：' + (d.msg || '请重试');
                    }
                }).catch(function () {});
        }, 2500);
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

/* ---------------- 实名状态轮询（JSON） ---------------- */
if ($act === 'poll') {
    $rid = (int)($_GET['id'] ?? 0);
    $rn  = YxDb::one('SELECT * FROM ' . YxDb::table('developer_realname') . ' WHERE id = ? AND customer_id = ?', [$rid, $cid]);
    if (!$rn) {
        json_out(['ok' => false, 'msg' => '认证单不存在']);
    }
    $res = YxRealname::poll((string)$rn['request_id']);
    // 轮询失败必须如实返回 ok=false，否则前端会把「查询异常」当成正常状态继续等待
    json_out([
        'ok'     => !empty($res['ok']),
        'status' => (string)($res['status'] ?? $rn['status']),
        'msg'    => (string)($res['msg'] ?? ''),
    ]);
}

$dev = YxDeveloper::profile($cid);

/* ---------------- 表单提交 ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $gotoQr = 0;
    $okFlash = true;

    if ($action === 'apply') {
        $res = YxDeveloper::apply($cid, $_POST);
    } elseif ($action === 'profile_save') {
        $res = $dev
            ? YxDeveloper::saveContact((int)$dev['id'], $_POST)
            : ['ok' => false, 'msg' => '请先提交入驻资料'];
    } elseif ($action === 'realname_start') {
        if (!$dev) {
            $res = ['ok' => false, 'msg' => '请先提交入驻资料'];
        } else {
            $r = YxRealname::start($cid, (int)$dev['id'], $_POST, $_FILES);
            if (!empty($r['ok']) && empty($r['auto'])) {
                $gotoQr = (int)$r['id'];
            }
            // AI 自动监测：提交即出结论，审核未通过时用错误样式提示
            $okFlash = !empty($r['ok']) && (empty($r['auto']) || !empty($r['verified']));
            $res = $r;
        }
    } elseif ($action === 'deposit_create') {
        $forType = array_key_exists((string)($_POST['for_type'] ?? ''), YxDeveloper::TYPES) ? (string)$_POST['for_type'] : 'plugin';
        $res = $dev ? YxDeveloper::createDeposit($dev, $forType) : ['ok' => false, 'msg' => '请先提交入驻资料'];
    } elseif ($action === 'deposit_pay') {
        if (!$dev) {
            $res = ['ok' => false, 'msg' => '请先提交入驻资料'];
        } else {
            $dep = YxDb::one('SELECT * FROM ' . YxDb::table('developer_deposit') . " WHERE developer_id = ? AND status = 'unpaid' ORDER BY id DESC LIMIT 1", [(int)$dev['id']]);
            if (!$dep) {
                $res = ['ok' => false, 'msg' => '没有待缴纳的保证金单据'];
            } else {
                $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$dep['order_id']]);
                $bal   = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
                if (!$order || $order['status'] !== 'unpaid') {
                    $res = ['ok' => false, 'msg' => '订单状态不可支付'];
                } elseif ($bal < (float)$order['total']) {
                    $res = ['ok' => false, 'msg' => '余额不足，请先充值或使用在线支付'];
                } else {
                    $res = YxBusiness::payOrder((int)$order['id'], 'balance');
                }
            }
        }
    } elseif ($action === 'app_save') {
        $res = $dev ? YxDeveloper::publish($dev, $_POST, $_FILES) : ['ok' => false, 'msg' => '请先完成开发者入驻'];
    } elseif ($action === 'version_save') {
        $res = $dev
            ? YxDeveloper::addVersion($dev, (int)($_POST['app_id'] ?? 0), $_FILES, (string)($_POST['version'] ?? ''), trim((string)($_POST['changelog'] ?? '')))
            : ['ok' => false, 'msg' => '请先完成开发者入驻'];
    } elseif ($action === 'app_toggle') {
        $res = $dev ? YxDeveloper::toggleListing((int)$dev['id'], (int)($_POST['app_id'] ?? 0)) : ['ok' => false, 'msg' => '无权限'];
    } elseif ($action === 'app_delete') {
        if (!$dev) {
            $res = ['ok' => false, 'msg' => '无权限'];
        } else {
            $app = YxDeveloper::appOf((int)$dev['id'], (int)($_POST['app_id'] ?? 0));
            if (!$app) {
                $res = ['ok' => false, 'msg' => '商品不存在'];
            } elseif (!in_array($app['status'], ['pending', 'rejected', 'off', 'draft'], true) || (int)$app['sales'] > 0) {
                $res = ['ok' => false, 'msg' => '仅未产生销量的未上架商品可删除'];
            } else {
                YxDb::delete('app', 'id = :id', ['id' => (int)$app['id']]);
                YxDb::delete('dev_app_version', 'app_id = :id', ['id' => (int)$app['id']]);
                $res = ['ok' => true, 'msg' => '商品已删除'];
            }
        }
    }

    log_action('developer.' . $action, 'customer#' . $cid, ['ok' => !empty($res['ok']) && $okFlash]);
    if ($gotoQr > 0) {
        flash($res['msg'], 'success');
        redirect(url('user/developer.php?act=qr&id=' . $gotoQr));
    }
    flash($res['msg'], $okFlash ? 'success' : 'error');
    redirect(url('user/developer.php'));
}

/* ---------------- 展示数据 ---------------- */
$cats   = YxDb::all('SELECT * FROM ' . YxDb::table('app_category') . ' WHERE status = 1 ORDER BY sort ASC, id ASC');
$balance = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
$latestRn = $dev ? YxRealname::latestForCustomer($cid) : null; // 内部会把超时未完成的单自动置为 expired
$deposit = $dev
    ? YxDb::one('SELECT * FROM ' . YxDb::table('developer_deposit') . ' WHERE developer_id = ? ORDER BY id DESC LIMIT 1', [(int)$dev['id']])
    : null;
$myApps = $dev ? YxDeveloper::myApps((int)$dev['id']) : [];
$settleNet = $dev
    ? (float)YxDb::value('SELECT COALESCE(SUM(net),0) FROM ' . YxDb::table('developer_settlement') . ' WHERE developer_id = ?', [(int)$dev['id']])
    : 0.0;
$depositPayOrder = ($deposit && $deposit['status'] === 'unpaid')
    ? YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$deposit['order_id']])
    : null;

$realnameOk = $dev && $dev['realname_status'] === 'verified';
$depositOk  = $dev && $dev['deposit_status'] === 'paid';
// 模板类商品免缴保证金：只做模板的开发者不必缴保证金即可上传
$templateFree = YxDeveloper::depositAmountForType('template') <= 0;
$canPublish = YxDeveloper::canPublish($dev);
$canPublishTemplate = YxDeveloper::canPublishType($dev, 'template');
$depositRequired = YxDeveloper::depositAmount() > 0 && !$depositOk;

$userTitle  = '开发者中心';
$userActive = 'developer';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2>开发者中心</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('user/plugin_center.php')) ?>">前往插件中心 →</a>
    </div>
    <p class="muted">成为开发者：完成 <b>实名核验</b> 与 <b>保证金</b> 缴纳，审核通过后即可上传插件 / 主题 / 应用，经插件中心售卖。
        <b>模板类商品免缴保证金</b>，实名核验通过、入驻审核通过后即可上传模板。</p>

    <?php if (!$dev): ?>
        <div class="grid-3 mt-3">
            <div class="stat"><span>入驻状态</span><strong>未入驻</strong></div>
            <div class="stat"><span>实名核验</span><strong>—</strong></div>
            <div class="stat"><span>保证金</span><strong>—</strong></div>
        </div>
    <?php else: ?>
        <div class="grid-4 mt-3">
            <div class="stat"><span>入驻状态</span><strong><?= e(YxDeveloper::devStatusName((string)$dev['status'])) ?></strong></div>
            <div class="stat"><span>实名核验</span><strong><?= e(YxDeveloper::realnameStatusName((string)$dev['realname_status'])) ?></strong></div>
            <div class="stat"><span>保证金</span><strong><?= e(YxDeveloper::depositStatusName((string)$dev['deposit_status'])) ?></strong></div>
            <div class="stat accent"><span>累计分成</span><strong><?= e(money($settleNet, true)) ?></strong></div>
        </div>
    <?php endif; ?>
</div>

<?php if (!$dev): /* 入驻申请 */ ?>
    <div class="card">
        <h2>① 提交入驻资料</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <div class="grid-2">
                <label>入驻类型
                    <select name="type">
                        <option value="personal">个人开发者</option>
                        <option value="enterprise">企业开发者</option>
                    </select>
                </label>
                <label>联系方式 <input type="text" name="contact" placeholder="手机号 / QQ / 微信"></label>
            </div>
            <label>真实姓名 / 企业名称 <input type="text" name="real_name" placeholder="个人填姓名，企业填企业名称"></label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">提交并继续</button>
            </div>
        </form>
    </div>

<?php else: ?>

    <div class="card">
        <h2>店铺联系资料</h2>
        <p class="muted small">以下信息会展示在应用详情页的开发者卡片上，供客户了解作者与联系售后。</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile_save">
            <div class="grid-2">
                <label>作者网站 <input type="text" name="website" value="<?= e($dev['website'] ?? '') ?>" placeholder="https://www.example.com"></label>
                <label>电子邮箱 <input type="text" name="email" value="<?= e($dev['email'] ?? '') ?>" placeholder="dev@example.com"></label>
                <label>咨询 QQ <input type="text" name="qq" value="<?= e($dev['qq'] ?? '') ?>" placeholder="5-12 位数字"></label>
                <label>联系微信 <input type="text" name="wechat" value="<?= e($dev['wechat'] ?? '') ?>" placeholder="可选"></label>
            </div>
            <label>联系方式 <input type="text" name="contact" value="<?= e($dev['contact'] ?? '') ?>" placeholder="手机号 / QQ / 微信"></label>
            <label>开发者简介 <input type="text" name="intro" maxlength="255" value="<?= e($dev['intro'] ?? '') ?>" placeholder="一句话介绍你的团队或专长"></label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存联系资料</button>
            </div>
        </form>
    </div>

    <?php if (!$realnameOk): /* 实名认证 */ ?>
    <div class="card">
        <h2>② 实名认证<?= YxRealname::isAuto() ? '（AI 智能自动监测）' : '（扫码核验）' ?></h2>
        <p class="muted small"><?= YxRealname::isAuto()
            ? '提交后由 AI 自动审核资料合规性（证件号校验位、出生日期、姓名与企业信息规范、营业执照图片等），全部通过即完成实名；未满 18 周岁一律不通过。'
            : '企业实名与个人实名均需通过扫码完成核验，核验通过后方可缴纳保证金。' ?></p>

        <?php if ($latestRn && $latestRn['status'] === 'pending'): ?>
            <div class="alert alert-warn">
                当前有一笔待完成的<?= e(YxRealname::typeName((string)$latestRn['dev_type'])) ?>核验
                <a class="btn btn-sm btn-primary" href="<?= e(url('user/developer.php?act=qr&id=' . (int)$latestRn['id'])) ?>">继续扫码</a>
            </div>
        <?php elseif ($latestRn && $latestRn['status'] !== 'verified'): ?>
            <div class="alert alert-error">上一次核验未通过：<?= e($latestRn['message'] ?: '请重试') ?></div>
        <?php endif; ?>

        <?php if (!$latestRn || $latestRn['status'] !== 'pending'): ?>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="realname_start">
            <label>认证类型
                <select name="dev_type" id="devType">
                    <option value="personal" <?= $dev['type'] === 'personal' ? 'selected' : '' ?>>个人实名</option>
                    <option value="enterprise" <?= $dev['type'] === 'enterprise' ? 'selected' : '' ?>>企业实名</option>
                </select>
            </label>
            <div id="personalBox">
                <div class="grid-2">
                    <label>真实姓名 <input type="text" name="name" value="<?= e($dev['real_name']) ?>"></label>
                    <label>身份证号 <input type="text" name="id_card" maxlength="18" placeholder="18 位身份证号"></label>
                </div>
            </div>
            <div id="enterpriseBox">
                <div class="grid-2">
                    <label>企业名称 <input type="text" name="company" value="<?= e($dev['company']) ?>"></label>
                    <label>统一社会信用代码 <input type="text" name="license_no" maxlength="18" value="<?= e($dev['license_no']) ?>"></label>
                    <label>法定代表人 <input type="text" name="legal_person" value="<?= e($dev['legal_person']) ?>"></label>
                    <label>法人身份证号 <input type="text" name="id_card" maxlength="18" placeholder="18 位身份证号"></label>
                </div>
                <label>营业执照照片（必填，jpg / png / webp，≤5MB）
                    <input type="file" name="license_image" accept="image/*">
                </label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= YxRealname::isAuto() ? '提交 AI 自动审核' : '生成核验二维码' ?></button>
            </div>
        </form>
        <script>
        (function () {
            var sel = document.getElementById('devType');
            var pBox = document.getElementById('personalBox');
            var eBox = document.getElementById('enterpriseBox');
            function toggle(box, disabled) {
                box.style.display = disabled ? 'none' : '';
                box.querySelectorAll('input,select,textarea').forEach(function (el) { el.disabled = disabled; });
            }
            function sync() {
                var ent = sel.value === 'enterprise';
                toggle(pBox, ent);
                toggle(eBox, !ent);
            }
            sel.addEventListener('change', sync); sync();
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($realnameOk && $depositRequired): /* 保证金（模板类免缴） */ ?>
    <div class="card">
        <h2>③ 缴纳保证金</h2>
        <p class="muted small">保证金金额：<b><?= e(money(YxDeveloper::depositAmount(), true)) ?></b>（按年收取），用于插件 / 主题 / 应用类商品的开发者信用担保，可申请退还（需满足平台条件）。
            <br>模板类商品<b>不收取保证金</b>，无需缴纳即可上传。</p>

        <?php if (!$deposit || $deposit['status'] !== 'unpaid'): ?>
            <form method="post" class="form-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="deposit_create">
                <input type="hidden" name="for_type" value="plugin">
                <button class="btn btn-primary" type="submit">生成保证金缴纳单（<?= e(money(YxDeveloper::depositAmount(), true)) ?>/年）</button>
            </form>
        <?php else: ?>
            <table class="data">
                <tr><th>缴纳单号</th><td class="mono"><?= e($deposit['sn']) ?></td></tr>
                <tr><th>应缴金额</th><td><strong><?= e(money($deposit['amount'], true)) ?></strong></td></tr>
                <tr><th>账户余额</th><td><?= e(money($balance, true)) ?></td></tr>
                <tr><th>状态</th><td><span class="badge badge-warn">待缴纳</span></td></tr>
            </table>
            <div class="form-actions mt-3">
                <?php if ($balance >= (float)$deposit['amount']): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="deposit_pay">
                        <button class="btn btn-primary" type="submit">使用余额支付 <?= e(money($deposit['amount'], true)) ?></button>
                    </form>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e(url('user/recharge.php')) ?>">去充值</a>
            </div>
            <?php if (YxPayment::configured() && $depositPayOrder): ?>
                <div class="mt-3">
                    <p class="muted small">或使用在线支付：</p>
                    <?= YxPayment::renderCheckout($depositPayOrder, 'alipay', '开发者保证金', url('user/developer.php')) ?>
                </div>
            <?php else: ?>
                <p class="muted small mt-3">如无法在线支付，可联系管理员线下收款后人工确认。</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($dev && $realnameOk && !$canPublish && !$canPublishTemplate && ($depositOk || $templateFree)): ?>
        <div class="card">
            <h2>④ 等待审核</h2>
            <p class="muted"><?= $depositOk ? '实名与保证金已完成' : '实名已完成、模板类无需保证金' ?>，正在等待管理员审核入驻资格，请耐心等待。</p>
        </div>
    <?php endif; ?>

    <?php if ($canPublish || $canPublishTemplate): /* 上传商品 */ ?>
    <div class="card">
        <h2>上传商品（插件 / 主题 / 模板 / 应用）</h2>
        <?php if (!$canPublish): ?>
            <p class="muted small">当前可上传 <b>模板</b>（免缴保证金）；上传插件 / 主题 / 应用需先缴纳保证金 <?= e(money(YxDeveloper::depositAmount(), true)) ?>/年。</p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="app_save">
            <div class="grid-3">
                <label>商品名称 <input type="text" name="title" required></label>
                <label>类型
                    <select name="type">
                        <?php foreach (YxDeveloper::TYPES as $k => $v): ?>
                            <?php if (!$canPublish && YxDeveloper::depositRequired($k)) { continue; } ?>
                            <option value="<?= e($k) ?>"><?= e($v) ?><?= YxDeveloper::depositRequired($k) ? '' : '（免缴保证金）' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>分类
                    <select name="category_key">
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= e($c['key']) ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="other">其它</option>
                    </select>
                </label>
                <label>收费形式
                    <select name="bill_type" id="billing">
                        <option value="free">免费</option>
                        <option value="once">一次性买断</option>
                        <option value="year">按年（授权 1 年）</option>
                    </select>
                </label>
                <label>价格（元，付费填） <input type="number" step="0.01" min="0" name="price" value="0"></label>
                <label>版本号 <input type="text" name="version" value="1.0.0"></label>
                <label>最低适用版本
                    <select name="edition_min">
                        <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                            <option value="<?= e($k) ?>" <?= $k === 'basic' ? 'selected' : '' ?>><?= e($ed['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>兼容性说明 <input type="text" name="compatible" placeholder=">= 1.6.0"></label>
            </div>
            <label>商品简介 <textarea name="description" rows="3"></textarea></label>
            <label>更新日志 <textarea name="changelog" rows="2"></textarea></label>
            <div class="grid-3">
                <label>封面图（可选） <input type="file" name="cover" accept="image/*"></label>
                <label>效果截图（可选，多选） <input type="file" name="screenshots[]" accept="image/*" multiple></label>
                <label>安装包（.zip，必填） <input type="file" name="package" accept=".zip" required></label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">提交审核</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>我的商品（共 <?= count($myApps) ?> 个）</h2>
        <?php if (!$myApps): ?>
            <div class="empty">还没有上传商品</div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>商品</th><th>类型</th><th>价格</th><th>版本</th><th>状态</th><th>销量</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($myApps as $a): ?>
                    <tr>
                        <td class="wrap"><?= e($a['name']) ?><br><span class="muted small mono"><?= e($a['key']) ?></span></td>
                        <td><?= e(YxDeveloper::typeName((string)$a['type'])) ?></td>
                        <td><?= YxBusiness::billType($a) === 'free' ? '<span class="badge badge-ok">免费</span>' : e(YxBusiness::priceLabel($a)) ?></td>
                        <td><?= e($a['version']) ?></td>
                        <td>
                            <?php
                            $cls = ['on' => 'badge-ok', 'pending' => 'badge-warn', 'rejected' => 'badge-bad', 'off' => 'badge-mute', 'draft' => 'badge-mute'][$a['status']] ?? 'badge-mute';
                            ?>
                            <span class="badge <?= $cls ?>"><?= e(YxDeveloper::appStatusName((string)$a['status'])) ?></span>
                            <?php if ($a['status'] === 'rejected' && $a['review_note']): ?>
                                <br><span class="muted small">原因：<?= e($a['review_note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$a['sales'] ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if (in_array($a['status'], ['on', 'off'], true)): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="app_toggle">
                                    <input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= $a['status'] === 'on' ? '下架' : '上架' ?></button>
                                </form>
                                <?php endif; ?>
                                <?php if (in_array($a['status'], ['pending', 'rejected', 'off', 'draft'], true) && (int)$a['sales'] === 0): ?>
                                <form method="post" onsubmit="return confirm('确认删除该商品？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="app_delete">
                                    <input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-sm btn-bad" type="submit">删除</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="7" style="background:#fafbfe">
                            <details>
                                <summary style="cursor:pointer;color:var(--primary)">发布新版本</summary>
                                <form method="post" enctype="multipart/form-data" class="mt-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="version_save">
                                    <input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
                                    <div class="grid-3">
                                        <label>新版本号 <input type="text" name="version" value="<?= e($a['version']) ?>"></label>
                                        <label>安装包（.zip） <input type="file" name="package" accept=".zip" required></label>
                                        <label>更新说明 <input type="text" name="changelog"></label>
                                    </div>
                                    <button class="btn btn-sm btn-primary" type="submit">提交新版本</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

<script>
(function () {
    var b = document.getElementById('billing');
    if (!b) return;
    var price = document.querySelector('input[name="price"]');
    function sync() { if (price) price.disabled = (b.value === 'free'); }
    b.addEventListener('change', sync); sync();
})();
</script>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
