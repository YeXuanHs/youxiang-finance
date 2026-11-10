<?php
/**
 * 优享财务系统 · 正版购买证明（购买凭证）
 *
 * 客户购买官方正版授权支付成功后由系统自动签发，凭证底部加盖签发方电子公章。
 * 支持：
 *   - 在线查看（网页版，可直接浏览器打印 / 另存为 PDF）
 *   - 下载 PDF（服务端生成的矢量 PDF，内嵌电子公章）
 *
 * 安全边界：
 *   - 仅登录客户可访问，且只能查看自己名下的凭证（customer_id 强制匹配）
 *   - 下载走登录态 + 同一归属校验，不暴露可枚举的静态文件
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

$id     = (int)($_GET['id'] ?? 0);
$action = (string)($_GET['a'] ?? 'view');

// 支持按订单号跳转（订单列表的「购买证明」入口）
if ($id <= 0 && (int)($_GET['order'] ?? 0) > 0) {
    $byOrder = YxDb::one(
        'SELECT id FROM ' . YxDb::table('certificate') . ' WHERE order_id = ? AND customer_id = ? ORDER BY id DESC LIMIT 1',
        [(int)$_GET['order'], $cid]
    );
    if ($byOrder) {
        $id = (int)$byOrder['id'];
    } else {
        flash('该订单暂无购买凭证（仅官方正版授权订单自动签发）', 'error');
        redirect(url('user/orders.php'));
    }
}

if ($id <= 0) {
    // 未指定凭证：列出该客户全部购买凭证
    $certs = YxCertificate::forCustomer($cid);
    $userTitle  = '购买证明';
    $userActive = 'certificates';
    require dirname(__DIR__) . '/includes/user_header.php';
    ?>
    <div class="card">
        <div class="section-head">
            <h2>正版购买证明（<?= count($certs) ?> 份）</h2>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('user/orders.php')) ?>">我的订单</a>
        </div>
        <p class="muted small">
            购买官方正版授权并完成付款后，系统自动签发《正版软件购买证明》，凭证底部加盖
            <?= e(YxCertificate::issuer()) ?> 电子公章，可用于报销、备案、客户出示等场景。
        </p>
        <?php if (!$certs): ?>
            <div class="empty">
                还没有购买凭证。前往
                <a href="<?= e(url('user/licenses.php')) ?>">我的授权</a>
                购买官方授权包，付款成功后将自动生成购买证明。
            </div>
        <?php else: ?>
        <div class="table-wrap"><table class="data mobile-cards">
            <thead><tr><th>凭证编号</th><th>授权产品</th><th>授权域名</th><th>金额</th><th>签发时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($certs as $c): ?>
                <tr>
                    <td data-label="凭证编号" class="mono small"><?= e($c['sn']) ?></td>
                    <td data-label="授权产品" class="wrap"><?= e($c['app_name']) ?></td>
                    <td data-label="授权域名" class="mono small"><?= e($c['domain'] ?: '-') ?></td>
                    <td data-label="金额"><?= e(money($c['amount'], true)) ?></td>
                    <td data-label="签发时间" class="small muted"><?= e($c['paid_at'] ?: $c['created_at']) ?></td>
                    <td data-label="操作">
                        <div class="row-actions">
                            <a class="btn btn-sm btn-primary" href="<?= e(url('user/certificate.php?id=' . (int)$c['id'])) ?>">查看</a>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('user/certificate.php?id=' . (int)$c['id'] . '&a=pdf')) ?>">下载 PDF</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <?php
    require dirname(__DIR__) . '/includes/user_footer.php';
    exit;
}

$cert = YxCertificate::find($id, $cid);
if (!$cert) {
    http_response_code(404);
    exit('凭证不存在或不属于当前账号');
}

if ($action === 'pdf') {
    YxCertificate::sendPdf($cert);
}

// 网页版：一次性读取站点信息
$rows   = YxCertificate::rows($cert);
$issuer = (string)$cert['issuer'];
$siteName = setting('site_name', config('app.name'));
$verified = YxCertificate::verify($cert);
$userTitle  = '购买证明';
$userActive = 'certificates';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<style>
.yx-cert-toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
.yx-cert {
    background: #fff; color: #1b2333; border: 1px solid #e3e8f0; border-radius: 10px;
    box-shadow: 0 10px 30px rgba(16, 32, 64, .08); max-width: 820px; margin: 0 auto; overflow: hidden;
}
.yx-cert-head { background: linear-gradient(135deg, #1759dc, #2f7bfa); color: #fff; padding: 26px 36px; }
.yx-cert-head h1 { margin: 0 0 6px; font-size: 24px; letter-spacing: 2px; color: #fff; }
.yx-cert-head .en { font-size: 11px; letter-spacing: 2px; color: rgba(255, 255, 255, .78); }
.yx-cert-head .meta { float: right; text-align: right; font-size: 12px; line-height: 1.9; color: rgba(255, 255, 255, .92); }
.yx-cert-body { padding: 30px 36px 8px; position: relative; }
.yx-cert-lead { font-size: 14px; line-height: 2; color: #39415a; margin: 0 0 18px; }
.yx-cert-lead strong { color: #10203f; }
.yx-cert table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.yx-cert table th, .yx-cert table td { border-bottom: 1px solid #eaeef5; padding: 11px 12px; text-align: left; vertical-align: top; }
.yx-cert table th { width: 120px; color: #7b8499; font-weight: 400; background: #f8faff; }
.yx-cert table td { color: #1b2333; word-break: break-all; }
.yx-cert table td.mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
.yx-cert-notes { margin: 18px 0 0; padding-left: 18px; color: #7b8499; font-size: 12px; line-height: 1.9; }
.yx-cert-foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; padding: 26px 36px 34px; }
.yx-cert-sign { font-size: 13px; line-height: 2; color: #39415a; }
.yx-cert-sign .company { font-size: 16px; font-weight: 700; color: #10203f; }
/* 电子公章：SVG 绘制，环绕公司名 + 五角星 + 合同专用 */
.yx-seal { width: 150px; height: 150px; flex: 0 0 150px; transform: rotate(-8deg); opacity: .92; }
.yx-cert-tip { max-width: 820px; margin: 14px auto 0; color: #7b8499; font-size: 12px; line-height: 1.8; }
@media print {
    .sidebar, .yx-topbar, .yx-crumb, .main-foot, .yx-cert-toolbar, .yx-cert-tip, #yxChatWidget { display: none !important; }
    .admin-main, .main-content { margin: 0 !important; padding: 0 !important; }
    .yx-cert { border: none; box-shadow: none; max-width: none; }
    body { background: #fff; }
}
</style>

<div class="yx-cert-toolbar">
    <a class="btn btn-sm btn-ghost" href="<?= e(url('user/certificate.php')) ?>">← 返回凭证列表</a>
    <button class="btn btn-sm btn-primary" type="button" onclick="window.print()">打印 / 另存为 PDF</button>
    <a class="btn btn-sm btn-ghost" href="<?= e(url('user/certificate.php?id=' . (int)$cert['id'] . '&a=pdf')) ?>">下载 PDF 原件</a>
    <span class="badge <?= $verified ? 'badge-ok' : 'badge-bad' ?>"><?= $verified ? '✔ 凭证摘要校验通过' : '✖ 凭证摘要异常' ?></span>
    <span class="badge badge-mute"><?= $cert['status'] === 'valid' ? '有效凭证' : '已作废' ?></span>
</div>

<div class="yx-cert" id="yxCert">
    <div class="yx-cert-head">
        <div class="meta">
            <div>凭证编号：<?= e($cert['sn']) ?></div>
            <div>签发日期：<?= e(substr((string)$cert['paid_at'], 0, 10)) ?></div>
        </div>
        <h1>正版软件购买证明</h1>
        <div class="en">CERTIFICATE OF GENUINE SOFTWARE PURCHASE</div>
    </div>

    <div class="yx-cert-body">
        <p class="yx-cert-lead">
            兹证明，<strong><?= e($cert['buyer_name']) ?></strong>
            已通过 <strong><?= e($siteName) ?></strong> 完成下列正版软件的付费购买，付款已完成，
            授权自购买之日起对所列域名生效。
        </p>

        <table>
            <?php foreach ($rows as $label => $value): ?>
                <?php if ($label === '购买方') { continue; } ?>
                <tr>
                    <th><?= e($label) ?></th>
                    <td class="<?= in_array($label, ['授权域名', '授权码', '订单编号'], true) ? 'mono' : '' ?>"><?= e($value) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <ol class="yx-cert-notes">
            <li>本证明用于证明上述订单的正版软件购买事实，授权有效期以「我的授权」页面显示的到期时间为准。</li>
            <li>授权码与绑定域名一一对应，更换绑定域名后授权码会重新签发，旧授权码同时失效。</li>
            <li>如需核验凭证真伪，请提供凭证编号与签发方核对。</li>
        </ol>
    </div>

    <div class="yx-cert-foot">
        <div class="yx-cert-sign">
            <div>签发单位（盖章）：</div>
            <div class="company"><?= e($issuer) ?></div>
            <div>签发日期：<?= e(substr((string)$cert['paid_at'], 0, 10)) ?></div>
            <div class="muted small">电子公章 · 与纸质盖章具有同等效力</div>
        </div>

        <!-- 电子公章：外圈公司名环绕 + 五角星 + 合同专用 -->
        <?php
        $issuerChars = preg_split('//u', mb_substr($issuer, 0, 16), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $issuerCount = max(1, count($issuerChars));
        ?>
        <svg class="yx-seal" viewBox="0 0 200 200" role="img" aria-label="<?= e($issuer) ?> 电子公章">
            <circle cx="100" cy="100" r="94" fill="none" stroke="#cc1220" stroke-width="4.5"/>
            <circle cx="100" cy="100" r="86" fill="none" stroke="#cc1220" stroke-width="1.6"/>
            <?php
            // 公司名沿外圈上半周排布：每字按切线旋转，字头朝外
            $arcSpan = min(252, 21 * $issuerCount);
            for ($i = 0; $i < $issuerCount; $i++) {
                $deg = -90 - $arcSpan / 2 + ($issuerCount > 1 ? $arcSpan / ($issuerCount - 1) : 0) * $i;
                $rad = deg2rad($deg);
                $x = 100 + cos($rad) * 71;
                $y = 100 + sin($rad) * 71;
                printf(
                    '<text x="%.2f" y="%.2f" font-size="15" fill="#cc1220" text-anchor="middle" dominant-baseline="central"'
                    . ' font-family="\'Songti SC\',\'SimSun\',serif" transform="rotate(%.2f %.2f %.2f)">%s</text>',
                    $x, $y, $deg + 90, $x, $y, e($issuerChars[$i])
                );
            }
            ?>
            <?php
            // 五角星
            $pts = [];
            for ($k = 0; $k < 10; $k++) {
                $ang = -M_PI / 2 + $k * M_PI / 5;
                $rr  = $k % 2 === 0 ? 21 : 8.5;
                $pts[] = sprintf('%.2f,%.2f', 100 + cos($ang) * $rr, 100 + sin($ang) * $rr);
            }
            ?>
            <polygon points="<?= implode(' ', $pts) ?>" fill="#cc1220"/>
            <text x="100" y="152" font-size="12.5" fill="#cc1220" text-anchor="middle"
                  font-family="'PingFang SC','Microsoft YaHei',sans-serif" style="letter-spacing:2px">合同专用</text>
        </svg>
    </div>
</div>

<p class="yx-cert-tip">
    凭证由系统在订单支付成功后自动签发并落库，凭证摘要：
    <span class="mono"><?= e(substr((string)$cert['sign_hash'], 0, 48)) ?>…</span><br>
    官方授权查询：<a href="<?= e(YxCertificate::officialUrl()) ?>" target="_blank" rel="noopener"><?= e(YxCertificate::officialUrl()) ?></a>
</p>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
