<?php
/**
 * 优享财务系统 · 版权侵权处理（前台）
 *
 * 用户可：① 自助检测是否侵权（本地规则库 + 可选外部检测 API）② 提交侵权举报 ③ 查询处理进度
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$customer = YxAuth::customer();
$act      = (string)($_GET['act'] ?? 'detect');
$detect   = null;
$licCheck = null;
$report   = null;
$notice   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'detect') {
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text === '') {
            $notice = '请输入要检测的内容（名称 / 描述 / 链接 / 文本）';
        } else {
            $detect = YxInfringe::detect($text);
        }
    } elseif ($do === 'liccheck') {
        // 只读检测：输出判定结论，不生成对外文书、不发送任何通知
        $lcDomain = normalize_domain((string)($_POST['domain'] ?? ''));
        if ($lcDomain === '') {
            $notice = '请输入要检测的域名';
        } else {
            $lcRaw     = trim((string)($_POST['license'] ?? ''));
            $lcProduct = (string)($_POST['product'] ?? 'official') === 'community' ? 'community' : 'official';
            $licCheck  = [
                'domain'  => YxInfringeLegal::domainCheck($lcDomain),
                'license' => $lcRaw !== '' ? YxInfringeLegal::licenseCheck($lcRaw, $lcDomain, $lcProduct) : null,
            ];
            log_action('infringe.liccheck', $lcDomain, ['verdict' => $licCheck['domain']['verdict'], 'auto_letter' => 0]);
        }
    } elseif ($do === 'report') {
        $targetType = in_array($_POST['target_type'] ?? '', ['app', 'url', 'text'], true) ? $_POST['target_type'] : 'text';
        $appId      = (int)($_POST['target_app_id'] ?? 0);
        $targetUrl  = trim((string)($_POST['target_url'] ?? ''));
        $targetText = trim((string)($_POST['target_text'] ?? ''));
        $workTitle  = trim((string)($_POST['work_title'] ?? ''));
        $workOwner  = trim((string)($_POST['work_owner'] ?? ''));
        $proofUrl   = trim((string)($_POST['proof_url'] ?? ''));
        $desc       = trim((string)($_POST['description'] ?? ''));
        $reporter   = trim((string)($_POST['reporter'] ?? ($customer['username'] ?? '')));
        $contact    = trim((string)($_POST['contact'] ?? ($customer['email'] ?? '')));

        // 组装被检测对象文本
        $text = $targetText;
        $appKey = '';
        if ($targetType === 'app' && $appId > 0) {
            $app = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$appId]);
            if ($app) {
                $appKey = (string)$app['key'];
                $text   = $app['name'] . "\n" . $app['author'] . "\n" . (string)$app['description'];
            }
        } elseif ($targetType === 'url') {
            $text = $targetUrl;
        }

        if ($workTitle === '' || $desc === '' || ($text === '' && $appKey === '')) {
            $notice = '请填写作品名称、侵权说明，并指定被检测对象';
        } else {
            $d = YxInfringe::detect($text);
            // 报案 / 涉警类诉求：仍然登记，但标记「仅人工办理」，由人工核对后导办，不按自助检测结论处理
            $dispute = YxDispute::inspect($workTitle, $desc, $targetText);
            $sn = YxInfringe::buildReportSn();
            YxDb::insert('infringe_report', [
                'sn'            => $sn,
                'customer_id'   => $customer ? (int)$customer['id'] : 0,
                'reporter'      => $reporter,
                'contact'       => $contact,
                'target_type'   => $targetType,
                'target_app_id' => $targetType === 'app' ? $appId : 0,
                'target_url'    => $targetType === 'url' ? $targetUrl : null,
                'target_text'   => $targetText !== '' ? $targetText : null,
                'work_title'    => $workTitle,
                'work_owner'    => $workOwner,
                'proof_url'     => $proofUrl,
                'description'   => $desc,
                'risk'          => $d['risk'],
                'score'         => $d['score'],
                'detect_result' => json_encode($d, JSON_UNESCAPED_UNICODE),
                'manual_only'   => $dispute['hit'] ? 1 : 0,
                'manual_note'   => $dispute['hit'] ? mb_substr('命中：' . implode('、', $dispute['keywords']), 0, 255) : null,
                'status'        => 'pending',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            log_action('infringe.report', $sn, ['risk' => $d['risk'], 'score' => $d['score'], 'manual_only' => $dispute['hit'] ? 1 : 0]);
            flash(
                $dispute['hit']
                    ? '举报已提交，编号 ' . $sn . '。该诉求含报案 / 涉警内容，仅人工办理，请同时拨打 110 或到就近派出所报案'
                    : '举报已提交，编号 ' . $sn . '，我们会尽快处理'
            );
            redirect(url('infringe.php?act=query&sn=' . urlencode($sn)));
        }
    }
}

$sn = trim((string)($_GET['sn'] ?? ''));
if ($sn !== '') {
    $report = YxDb::one('SELECT * FROM ' . YxDb::table('infringe_report') . ' WHERE sn = ?', [$sn]);
}
$myReports = $customer
    ? YxDb::all('SELECT * FROM ' . YxDb::table('infringe_report') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 20', [(int)$customer['id']])
    : [];
$apps = YxDb::all('SELECT id, name, `key` FROM ' . YxDb::table('app') . " WHERE status = 'on' ORDER BY id DESC LIMIT 300");

$pageTitle = '版权侵权处理';
require __DIR__ . '/includes/header.php';
?>
<section class="section">
    <h1>版权侵权处理</h1>
    <p class="muted">自助检测涉嫌侵权内容、提交侵权举报并查询处理进度。检测为**辅助判定**（规则库 + 可选外部检测），最终以人工核查为准。</p>

    <div class="cat-bar">
        <a class="<?= $act === 'detect' ? 'active' : '' ?>" href="<?= e(url('infringe.php?act=detect')) ?>">侵权自助检测</a>
        <a class="<?= $act === 'report' ? 'active' : '' ?>" href="<?= e(url('infringe.php?act=report')) ?>">提交侵权举报</a>
        <a class="<?= $act === 'query' ? 'active' : '' ?>" href="<?= e(url('infringe.php?act=query')) ?>">查询进度</a>
    </div>

    <?php if ($notice !== ''): ?><div class="alert alert-error"><?= e($notice) ?></div><?php endif; ?>

    <?php if ($act === 'detect'): ?>
        <div class="card">
            <h2>侵权自助检测</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="detect">
                <label>待检测内容（应用名称 / 描述 / 链接 / 文本片段）
                    <textarea name="text" rows="4" placeholder="粘贴要检测的名称、描述或链接" required></textarea>
                </label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">开始检测</button></div>
            </form>

            <?php if ($detect): ?>
                <div class="mt-4">
                    <p>检测结果：<span class="badge <?= $detect['risk'] === 'high' ? 'badge-bad' : ($detect['risk'] === 'mid' ? 'badge-warn' : ($detect['risk'] === 'low' ? 'badge-info' : 'badge-ok')) ?>">
                        <?= e(YxInfringe::riskName($detect['risk'])) ?>风险（评分 <?= (int)$detect['score'] ?>）
                    </span></p>
                    <?php if ($detect['hits']): ?>
                        <table class="data">
                            <thead><tr><th>命中规则</th><th>类型</th><th>命中内容</th><th>风险</th><th>权重</th></tr></thead>
                            <tbody>
                            <?php foreach ($detect['hits'] as $h): ?>
                                <tr><td><?= e($h['name']) ?></td><td><?= e($h['type']) ?></td><td class="mono small"><?= e($h['pattern']) ?></td><td><?= e(YxInfringe::riskName((string)$h['risk'])) ?></td><td><?= (int)$h['weight'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty">未发现命中规则</div>
                    <?php endif; ?>
                    <?php if (!empty($detect['external']['enabled'])): ?>
                        <p class="muted small mt-2">外部检测：<?= !empty($detect['external']['ok']) ? '已启用（评分 ' . (int)$detect['external']['score'] . '）' : '启用但调用失败：' . e($detect['external']['msg']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>授权状态检测（域名比对 + 授权文件验签）</h2>
            <p class="muted small">按授权库比对域名登记状态，并对授权文件做签名校验；篡改、过期、域名不符、产品类型不符一律判为不通过。检测结论仅供参考，最终以人工核查为准。</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="liccheck">
                <label>待检测域名 <input type="text" name="domain" placeholder="例如 example.com" value="<?= e((string)($_POST['domain'] ?? '')) ?>" required></label>
                <label>授权文件（可选，粘贴授权文件 JSON 内容）
                    <textarea name="license" rows="3" placeholder="粘贴 .license 文件内容，留空则只做域名比对"></textarea>
                </label>
                <label>发行形态
                    <select name="product">
                        <option value="official">正版发行包</option>
                        <option value="community">开源发行包</option>
                    </select>
                </label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">检测授权状态</button></div>
            </form>

            <?php if ($licCheck): ?>
                <div class="mt-4">
                    <p>域名比对：
                        <span class="badge <?= $licCheck['domain']['verdict'] === YxInfringeLegal::VERDICT_CLEAR ? 'badge-ok' : ($licCheck['domain']['verdict'] === YxInfringeLegal::VERDICT_UNAUTHORIZED ? 'badge-bad' : 'badge-warn') ?>">
                            <?= e(YxInfringeLegal::verdictName((string)$licCheck['domain']['verdict'])) ?>
                        </span>
                        <span class="muted small"><?= e((string)$licCheck['domain']['message']) ?></span>
                    </p>
                    <?php if ($licCheck['license'] !== null): ?>
                        <p>授权文件验签：
                            <span class="badge <?= !empty($licCheck['license']['ok']) ? 'badge-ok' : 'badge-bad' ?>">
                                <?= !empty($licCheck['license']['ok']) ? '签名有效' : '不通过' ?>
                            </span>
                            <span class="muted small"><?= e((string)$licCheck['license']['message']) ?></span>
                        </p>
                    <?php endif; ?>
                    <p class="muted small">本检测只输出判定结论，不生成对外文书、不发送任何通知；如需处置请提交侵权举报，由人工核查后按流程办理。</p>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($act === 'report'): ?>
        <div class="card">
            <h2>提交侵权举报</h2>
            <?= YxDispute::noticeHtml() ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="report">
                <div class="grid-3">
                    <label>被举报对象
                        <select name="target_type" id="ttype">
                            <option value="app">商店应用</option>
                            <option value="url">外部链接</option>
                            <option value="text">文本内容</option>
                        </select>
                    </label>
                    <label id="appBox">选择应用
                        <select name="target_app_id">
                            <option value="0">请选择</option>
                            <?php foreach ($apps as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?>（<?= e($a['key']) ?>）</option><?php endforeach; ?>
                        </select>
                    </label>
                    <label id="urlBox" style="display:none">侵权链接 <input type="text" name="target_url" placeholder="https://..."></label>
                    <label id="textBox" style="display:none">侵权文本 <input type="text" name="target_text" placeholder="名称 / 描述片段"></label>
                </div>
                <div class="grid-2">
                    <label>权利作品名称 <input type="text" name="work_title" required></label>
                    <label>权利人 <input type="text" name="work_owner"></label>
                    <label>权属证明链接 <input type="text" name="proof_url" placeholder="著作权登记 / 官方产品页"></label>
                    <label>联系方式 <input type="text" name="contact" value="<?= e($customer['email'] ?? '') ?>"></label>
                </div>
                <label>举报人 <input type="text" name="reporter" value="<?= e($customer['username'] ?? '') ?>"></label>
                <label>侵权说明 <textarea name="description" rows="3" required placeholder="请说明侵权事实与依据"></textarea></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">提交举报</button></div>
            </form>
        </div>

    <?php else: ?>
        <div class="card">
            <h2>查询处理进度</h2>
            <form method="get" class="inline-form">
                <input type="hidden" name="act" value="query">
                <label class="grow">举报编号 <input type="text" name="sn" value="<?= e($sn) ?>" placeholder="IPR..."></label>
                <button class="btn btn-primary" type="submit">查询</button>
            </form>
            <?php if ($sn !== '' && !$report): ?>
                <div class="alert alert-warn mt-3">未查到编号 <?= e($sn) ?></div>
            <?php elseif ($report): ?>
                <table class="data mt-3">
                    <tr><th>编号</th><td class="mono"><?= e($report['sn']) ?></td><th>状态</th><td><span class="badge badge-warn"><?= e(YxInfringe::statusName((string)$report['status'])) ?></span></td></tr>
                    <tr><th>权利作品</th><td><?= e($report['work_title']) ?></td><th>检测结果</th><td><?= e(YxInfringe::riskName((string)$report['risk'])) ?>（<?= (int)$report['score'] ?>）</td></tr>
                    <tr><th>提交时间</th><td><?= e($report['created_at']) ?></td><th>处理时间</th><td><?= e($report['handled_at'] ?: '-') ?></td></tr>
                    <?php if ($report['handle_note']): ?><tr><th>处理说明</th><td colspan="3"><?= e($report['handle_note']) ?></td></tr><?php endif; ?>
                </table>
            <?php endif; ?>

            <?php if ($myReports): ?>
                <h2 class="mt-4">我的举报</h2>
                <div class="table-wrap"><table class="data">
                    <thead><tr><th>编号</th><th>作品</th><th>风险</th><th>状态</th><th>时间</th></tr></thead>
                    <tbody>
                    <?php foreach ($myReports as $r): ?>
                        <tr>
                            <td class="mono small"><?= e($r['sn']) ?></td>
                            <td><?= e($r['work_title']) ?></td>
                            <td><?= e(YxInfringe::riskName((string)$r['risk'])) ?></td>
                            <td><?= e(YxInfringe::statusName((string)$r['status'])) ?></td>
                            <td class="small muted"><?= e($r['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    var t = document.getElementById('ttype');
    if (!t) return;
    function sync() {
        var v = t.value;
        document.getElementById('appBox').style.display = v === 'app' ? '' : 'none';
        document.getElementById('urlBox').style.display = v === 'url' ? '' : 'none';
        document.getElementById('textBox').style.display = v === 'text' ? '' : 'none';
    }
    t.addEventListener('change', sync); sync();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
