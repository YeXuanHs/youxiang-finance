<?php
/**
 * 优享财务系统 · 用户中心 · 实名认证
 *
 * 个人实名：姓名 + 身份证号 → 生成二维码 → 手机扫码人脸活体核验
 * 企业认证：上传营业执照 → OCR 回填企业信息（未接入识别服务时手动填写）→ 生成二维码 → 扫码核验
 * 核验结果经 api/realname_notify.php 回调或本页轮询落库，联动 customer / developer 档案。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

$act = (string)($_GET['act'] ?? '');

/* ---------------- 二维码 / 轮询 / 识别（保持会话，不进入完整布局） ---------------- */

if ($act === 'poll') {
    $requestId = (string)($_GET['request_id'] ?? '');
    $owned = YxDb::value(
        'SELECT COUNT(*) FROM ' . YxDb::table('developer_realname') . ' WHERE request_id = ? AND customer_id = ?',
        [$requestId, $cid]
    );
    if (!$owned) {
        json_out(['ok' => false, 'status' => 'pending', 'msg' => '认证单不存在']);
    }
    $res = YxRealname::poll($requestId);
    json_out([
        'ok'     => !empty($res['ok']),
        'status' => (string)($res['status'] ?? 'pending'),
        'msg'    => (string)($res['msg'] ?? ''),
    ]);
}

if ($act === 'license_ocr') {
    csrf_check();
    // 前端先把营业执照上传到 uploads/realname，再调用识别服务回填企业信息
    $up = YxRealname::storeLicenseImage($_FILES['license_image'] ?? []);
    if (empty($up['ok'])) {
        json_out(['ok' => false, 'msg' => (string)($up['msg'] ?? '营业执照上传失败')]);
    }
    $path = (string)$up['path'];
    $ocr  = YxRealname::ocrLicense($path);
    $ocr['ok']   = true;               // 上传成功即可继续；识别失败由前端提示手动填写
    $ocr['path'] = $path;
    $ocr['recognized'] = !empty($ocr['company']) || !empty($ocr['license_no']);
    if (empty($ocr['company']) && empty($ocr['license_no']) && empty($ocr['msg'])) {
        $ocr['msg'] = '未识别到营业执照信息，请手动填写企业信息';
    }
    json_out($ocr);
}

if ($act === 'qr') {
    $rid = (int)($_GET['id'] ?? 0);
    $rn  = YxDb::one(
        'SELECT * FROM ' . YxDb::table('developer_realname') . ' WHERE id = ? AND customer_id = ?',
        [$rid, $cid]
    );
    if (!$rn) {
        http_response_code(404);
        exit('认证单不存在');
    }
    // AI 自动监测 / 已出结果的认证单没有二维码可扫，直接给出结论页
    if ((string)$rn['status'] !== 'pending') {
        $passed = (string)$rn['status'] === 'verified';
        $msg    = (string)($rn['message'] ?: ($passed ? 'AI 智能检测通过' : 'AI 智能检测未通过'));
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= $passed ? '实名认证已通过' : '实名认证未通过' ?></title>
        <style>
            body{font-family:-apple-system,"Microsoft YaHei",sans-serif;background:#f4f6fb;margin:0;padding:40px 16px;color:#1f2733}
            .box{max-width:460px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(20,40,80,.08);padding:28px;text-align:center}
            h1{font-size:18px;margin:0 0 10px}
            .ok{color:#2f9e44}.warn{color:#e8590c}
            p{font-size:14px;line-height:1.8}
            a.btn{display:inline-block;margin-top:14px;padding:9px 18px;background:#4dabf7;color:#fff;border-radius:8px;text-decoration:none;font-size:14px}
        </style></head>
        <body><div class="box">
            <h1 class="<?= $passed ? 'ok' : 'warn' ?>"><?= $passed ? '实名认证已通过' : '实名认证未通过' ?></h1>
            <p><?= e($msg) ?></p>
            <a class="btn" href="<?= e(url('user/realname.php')) ?>">返回实名认证</a>
        </div></body></html>
        <?php
        exit;
    }
    $rid = (string)$rn['request_id'];
    $expire = (string)($rn['expire_at'] ?? '');
    $typeName = YxRealname::typeName((string)$rn['dev_type']);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($typeName) ?> · 扫码核验</title>
        <style>
            body{font-family:-apple-system,"Microsoft YaHei",sans-serif;background:#f4f6fb;margin:0;padding:40px 16px;color:#1f2733}
            .box{max-width:420px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(20,40,80,.08);padding:28px;text-align:center}
            h1{font-size:18px;margin:0 0 6px}
            .muted{color:#7d8799;font-size:13px}
            #qrcode{margin:18px auto;width:220px;height:220px;display:flex;align-items:center;justify-content:center;overflow:hidden}
            #qrcode img,#qrcode canvas{width:220px!important;height:220px!important}
            .status{margin-top:10px;font-size:14px}
            .small{font-size:12px}
            a.btn{display:inline-block;margin-top:14px;padding:9px 18px;background:#4dabf7;color:#fff;border-radius:8px;text-decoration:none;font-size:14px}
            .ok{color:#2f9e44}.warn{color:#e8590c}
            .tip{background:#f1f8ff;border-radius:10px;padding:12px;text-align:left;font-size:13px;line-height:1.9;color:#495057;margin-top:14px}
        </style>
    </head>
    <body>
    <div class="box">
        <h1><?= e($typeName) ?> · 扫码人脸核验</h1>
        <p class="muted">请使用手机扫描下方二维码，按提示完成<?= $rn['dev_type'] === 'enterprise' ? '法定代表人' : '本人' ?>人脸活体核验</p>
        <div id="qrcode" data-qr="<?= e((string)$rn['qr_url']) ?>" data-qr-src="<?= e(url('assets/js/qrcode.min.js')) ?>">
            <a id="qrFallback" href="<?= e((string)$rn['qr_url']) ?>" target="_blank" rel="noopener">打开核验链接</a>
        </div>
        <p class="status" id="st" data-base="<?= e(url('user/realname.php')) ?>">等待扫码…</p>
        <p class="muted small">二维码有效期至 <?= e($expire !== '' ? $expire : '-') ?>，过期后可重新发起</p>
        <div class="tip">核验时请保持光线充足、五官清晰；企业实名需由法定代表人或经办人完成人脸核验，并确保营业执照在有效期内。</div>
        <a class="btn" href="<?= e(url('user/realname.php')) ?>">返回实名认证</a>
    </div>
    <script src="<?= e(url('assets/js/portal.js')) ?>" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('qrcode');
        var st = document.getElementById('st');
        var text = el.getAttribute('data-qr');

        function loadQr() {
            if (typeof window.QRCode === 'function') {
                el.innerHTML = '';
                try {
                    new window.QRCode(el, { text: text, width: 220, height: 220 });
                } catch (e) {
                    el.innerHTML = '<a href="' + text + '" target="_blank">打开核验链接</a>';
                }
                return;
            }
            var s = document.createElement('script');
            s.src = el.getAttribute('data-qr-src');
            s.onload = loadQr;
            s.onerror = function () { el.innerHTML = '<a href="' + text + '" target="_blank">打开核验链接</a>'; };
            document.body.appendChild(s);
        }
        loadQr();

        var requestId = <?= json_encode($rid) ?>;
        var back = st.getAttribute('data-base');
        var timer = setInterval(function () {
            fetch(back + '?act=poll&request_id=' + encodeURIComponent(requestId), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) return;
                    if (d.status === 'verified') {
                        clearInterval(timer);
                        st.className = 'status ok';
                        st.textContent = '实名核验通过，正在返回…';
                        setTimeout(function () { location.href = back; }, 800);
                    } else if (d.status === 'failed' || d.status === 'expired') {
                        clearInterval(timer);
                        st.className = 'status warn';
                        st.textContent = '核验未通过：' + (d.msg || '请重新发起认证');
                    }
                }).catch(function () {});
        }, 2500);
    });
    </script>
    </body>
    </html>
    <?php
    exit;
}

/* ---------------- 提交 ---------------- */

$dev       = YxDeveloper::profile($cid);
$latestRn  = YxRealname::latestForCustomer($cid);
$pendingRn = YxRealname::pendingForCustomer($cid);
$custStatus = (string)($customer['realname_status'] ?? 'none');
$realnameOk = $custStatus === 'verified';
// 开发者档案已定类型时锁定认证类型，避免个人/企业反复切换造成状态错乱
$lockedType = ($dev && in_array((string)$dev['type'], ['personal', 'enterprise'], true)) ? (string)$dev['type'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $goto   = '';

    if ($action === 'realname_start') {
        $r = YxRealname::startForCustomer($cid, $_POST, $lockedType, $_FILES);
        // AI 自动监测：提交即出结论，不再生成二维码
        if (!empty($r['ok']) && empty($r['auto'])) {
            $goto = url('user/realname.php?act=qr&id=' . (int)$r['id']);
        }
        $res = $r;
    }

    $success = !empty($res['ok']) && (empty($res['auto']) || !empty($res['verified']));
    log_action('user.realname.' . $action, 'customer#' . $cid, [
        'ok'       => $success,
        'auto'     => !empty($res['auto']),
        'decision' => (string)($res['decision'] ?? ''),
    ]);
    if ($goto !== '') {
        redirect($goto);
    }
    flash((string)($res['msg'] ?? ''), $success ? 'success' : 'error');
    redirect(url('user/realname.php'));
}

/* ---------------- 展示 ---------------- */

$userTitle  = '实名认证';
$userActive = 'realname';

$type = $lockedType !== '' ? $lockedType : ($latestRn ? (string)$latestRn['dev_type'] : 'personal');
$defCompany = (string)($customer['company'] ?? '');
$defLegal   = (string)($customer['legal_person'] ?? '');
$defLicense = (string)($customer['license_no'] ?? '');
$defName    = (string)($customer['realname'] ?? '');

require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2>实名认证</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('user/developer.php')) ?>">开发者中心 →</a>
    </div>
    <?php if ($realnameOk): ?>
        <p class="muted">当前账号已完成<?= e(YxRealname::typeName((string)($customer['realname_type'] ?? 'personal'))) ?>，实名信息不可自行修改。</p>
        <table class="data mt-3">
            <tr><th>认证类型</th><td><?= e(YxRealname::typeName((string)($customer['realname_type'] ?? 'personal'))) ?></td></tr>
            <?php if (($customer['realname_type'] ?? '') === 'enterprise'): ?>
                <tr><th>企业名称</th><td><?= e((string)$customer['company']) ?></td></tr>
                <tr><th>统一社会信用代码</th><td class="mono"><?= e((string)$customer['license_no']) ?></td></tr>
                <tr><th>法定代表人</th><td><?= e((string)$customer['legal_person']) ?></td></tr>
            <?php else: ?>
                <tr><th>真实姓名</th><td><?= e((string)$customer['realname']) ?></td></tr>
            <?php endif; ?>
            <tr><th>认证时间</th><td><?= e((string)($customer['verified_at'] ?? '-')) ?></td></tr>
        </table>
    <?php else: ?>
        <p class="muted"><?= YxRealname::isAuto()
            ? '提交后由 AI 智能自动监测实时审核资料：证件号校验位、行政区划、出生日期、姓名规范、企业信用代码与营业执照图片等全部合规才予通过；未满 18 周岁一律不通过。'
            : '个人实名需扫码完成人脸活体核验；企业认证需上传营业执照并经扫码核验。核验通过后才会写入账号实名状态。' ?></p>

        <?php if ($pendingRn): ?>
            <div class="alert alert-warn mt-3">
                当前有一笔待完成的<?= e(YxRealname::typeName((string)$pendingRn['dev_type'])) ?>（有效期至 <?= e((string)($pendingRn['expire_at'] ?? '-')) ?>）
                <a class="btn btn-sm btn-primary" href="<?= e(url('user/realname.php?act=qr&id=' . (int)$pendingRn['id'])) ?>">继续扫码</a>
            </div>
        <?php elseif ($latestRn && (string)$latestRn['status'] !== 'verified'): ?>
            <div class="alert alert-error mt-3">上一次核验未通过：<?= e((string)($latestRn['message'] ?: '请重新发起')) ?></div>
            <?php
            // AI 审核明细：逐项列出不通过的检查项，方便用户针对性修正
            $auditRaw = json_decode((string)($latestRn['result_raw'] ?? ''), true);
            $auditRaw = is_array($auditRaw) ? $auditRaw : [];
            $checks   = (array)($auditRaw['checks'] ?? ($auditRaw['audit']['checks'] ?? []));
            $failList = [];
            foreach ($checks as $c) {
                if (empty($c['ok']) && (string)($c['level'] ?? 'error') === 'error') {
                    $failList[] = $c;
                }
            }
            ?>
            <?php if ($failList): ?>
                <table class="data mt-2">
                    <thead><tr><th>检测项</th><th>结论</th></tr></thead>
                    <tbody>
                    <?php foreach ($failList as $c): ?>
                        <tr><td><?= e((string)($c['label'] ?? '')) ?></td><td class="warn"><?= e((string)($c['note'] ?? '')) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$pendingRn): ?>
        <form method="post" enctype="multipart/form-data" id="rnForm" class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="realname_start">
            <label>认证类型
                <select name="dev_type" id="devType" <?= $lockedType !== '' ? 'disabled' : '' ?>>
                    <option value="personal" <?= $type === 'personal' ? 'selected' : '' ?>>个人实名（扫码人脸核验）</option>
                    <option value="enterprise" <?= $type === 'enterprise' ? 'selected' : '' ?>>企业认证（营业执照 + 扫码核验）</option>
                </select>
            </label>
            <?php if ($lockedType !== ''): ?>
                <input type="hidden" name="dev_type" value="<?= e($lockedType) ?>">
                <p class="muted small">认证类型已按开发者入驻档案锁定为「<?= e(YxRealname::typeName($lockedType)) ?>」。</p>
            <?php endif; ?>

            <div id="personalBox">
                <div class="grid-2">
                    <label>真实姓名 <input type="text" name="name" value="<?= e($defName) ?>" autocomplete="name"></label>
                    <label>身份证号 <input type="text" name="id_card" maxlength="18" placeholder="18 位身份证号" autocomplete="off"></label>
                </div>
            </div>

            <div id="enterpriseBox">
                <div class="grid-2">
                    <label>营业执照照片（jpg / png / webp，≤5MB）
                        <input type="file" name="license_image" id="licenseFile" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <label>识别状态
                        <input type="text" id="ocrStatus" value="上传后自动识别，也可直接手动填写" readonly>
                    </label>
                </div>
                <input type="hidden" name="license_image_path" id="licensePath" value="">
                <img id="licensePreview" alt="营业执照预览" style="display:none;max-width:260px;border-radius:10px;border:1px solid #e6ebf2;margin:8px 0">
                <div class="grid-2">
                    <label>企业名称 <input type="text" name="company" id="fCompany" value="<?= e($defCompany) ?>"></label>
                    <label>统一社会信用代码 <input type="text" name="license_no" id="fLicense" maxlength="18" value="<?= e($defLicense) ?>"></label>
                    <label>法定代表人 <input type="text" name="legal_person" id="fLegal" value="<?= e($defLegal) ?>"></label>
                    <label>法定代表人身份证号 <input type="text" name="id_card" id="fIdCard" maxlength="18" autocomplete="off"></label>
                </div>
                <input type="hidden" name="license_ocr" id="licenseOcr" value="">
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit" id="rnSubmit">生成核验二维码</button>
            </div>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!$realnameOk && !$pendingRn): ?>
<script>
(function () {
    var form = document.getElementById('rnForm');
    if (!form) return;
    var sel = document.getElementById('devType');
    var locked = <?= $lockedType !== '' ? 'true' : 'false' ?>;
    var pBox = document.getElementById('personalBox');
    var eBox = document.getElementById('enterpriseBox');
    var file = document.getElementById('licenseFile');
    var status = document.getElementById('ocrStatus');
    var path = document.getElementById('licensePath');
    var preview = document.getElementById('licensePreview');
    var ocrField = document.getElementById('licenseOcr');
    var csrf = form.querySelector('input[name="_csrf"]').value;
    var ocrUrl = <?= json_encode(url('user/realname.php?act=license_ocr')) ?>;

    function toggle(box, disabled) {
        box.style.display = disabled ? 'none' : '';
        box.querySelectorAll('input,select,textarea').forEach(function (el) {
            if (el.id === 'licenseFile') return; // 文件框可见性由括号控制，单独禁用
            el.disabled = disabled;
        });
        if (box === eBox) file.disabled = disabled;
    }
    function sync() {
        var ent = locked ? (sel.value === 'enterprise') : (sel.value === 'enterprise');
        toggle(pBox, ent);
        toggle(eBox, !ent);
    }
    if (sel) sel.addEventListener('change', function () {
        if (sel.disabled) { sel.disabled = false; sel.dispatchEvent(new Event('change')); sel.disabled = true; }
        sync();
    });
    sync();

    // 先上传图片拿到路径，再调用识别服务回填企业信息（未接入识别服务时保留手填）
    file && file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) return;
        if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
            status.value = '仅支持 jpg / png / webp';
            file.value = '';
            return;
        }
        if (f.size > 5 * 1024 * 1024) {
            status.value = '图片超过 5MB，请压缩后重试';
            file.value = '';
            return;
        }
        preview.src = URL.createObjectURL(f);
        preview.style.display = '';
        status.value = '正在识别营业执照…';
        var fd = new FormData();
        fd.append('action', 'ocr');
        fd.append('_csrf', csrf);
        fd.append('license_image', f);

        fetch(location.pathname + '?act=license_ocr', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    status.value = (d && d.msg) || '识别失败，请手动填写企业信息';
                    return;
                }
                if (d.company) document.getElementById('fCompany').value = d.company;
                if (d.license_no) document.getElementById('fLicense').value = d.license_no;
                if (d.legal_person) document.getElementById('fLegal').value = d.legal_person;
                if (d.path) path.value = d.path;
                if (d.company || d.license_no) {
                    ocrField.value = JSON.stringify(d);
                    status.value = '识别成功，请核对后提交';
                } else {
                    status.value = (d.msg || '未识别到信息') + '，请手动填写';
                }
            })
            .catch(function () { status.value = '识别请求失败，请手动填写企业信息'; });
    });
})();
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
