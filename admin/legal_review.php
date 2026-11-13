<?php
/**
 * 优享财务系统 · 法务复核（前端复核视图 + 后端复核流转）
 *
 * 复核人可在本页：查看待复核律师函全文、关联核查单的事实与证据、印章预览，
 * 直接修改正文（复核即编辑定稿），给出「通过 / 驳回」结论并署名。
 * 复核通过的函件进入待签发状态，由本页或律师函页完成签发与签章。
 * 本页不做任何对外发送。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$tab  = (string)($_GET['tab'] ?? 'pending');
$me   = (string)($admin['nickname'] ?: $admin['username']);

/* ------------------------------ 提交处理 ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $postId = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $goto   = url('admin/legal_review.php?id=' . $postId);

    if ($action === 'review') {
        // 复核人可先定稿正文，再给结论；保存失败则不给结论
        $edit = [];
        foreach (['title', 'recipient', 'recipient_domain', 'subject', 'content', 'drafter'] as $k) {
            if (array_key_exists($k, $_POST)) {
                $edit[$k] = (string)$_POST[$k];
            }
        }
        if ($edit) {
            $save = YxLegalLetter::save($postId, $edit, $admin);
            if (!$save['ok']) {
                flash('正文定稿保存失败：' . $save['msg'], 'error');
                redirect($goto);
            }
        }
        $res = YxLegalLetter::review(
            $postId,
            (string)($_POST['decision'] ?? ''),
            (string)($_POST['reviewer'] ?? ''),
            (string)($_POST['note'] ?? ''),
            $admin
        );
        $goto = url('admin/legal_review.php' . ($res['ok'] ? '' : '?id=' . $postId));
    } elseif ($action === 'issue') {
        $res = YxLegalLetter::issue($postId, [
            'issuer'    => (string)($_POST['issuer'] ?? ''),
            'seal_date' => (string)($_POST['seal_date'] ?? ''),
        ], $admin);
        $goto = url('admin/legal_review.php?tab=approved');
    } elseif ($action === 'void') {
        $res  = YxLegalLetter::void($postId, (string)($_POST['note'] ?? ''), $admin);
        $goto = url('admin/legal_review.php?tab=issued');
    }

    flash($res['msg'], $res['ok'] ? 'success' : 'error');
    redirect($goto);
}

/* ------------------------------ 数据 ------------------------------ */
$pending  = YxLegalLetter::pendingQueue();
$approved = YxLegalLetter::search(['status' => YxLegalLetter::ST_APPROVED, 'per_page' => 50])['rows'];
$issued   = YxLegalLetter::search(['status' => YxLegalLetter::ST_ISSUED, 'per_page' => 20])['rows'];
$rejected = YxLegalLetter::search(['status' => YxLegalLetter::ST_REJECTED, 'per_page' => 20])['rows'];

$letter = $id > 0 ? YxLegalLetter::get($id) : null;
if (!$letter && $pending) {
    $letter = YxLegalLetter::get((int)$pending[0]['id']);
}
if ($letter) {
    $tab = (string)$letter['status'];
}

$logs = $letter ? YxLegalLetter::logs((int)$letter['id']) : [];

// 关联核查单事实与证据
$report = null;
if ($letter && (int)$letter['report_id'] > 0) {
    $report = YxDb::one('SELECT * FROM ' . YxDb::table('infringe_report') . ' WHERE id = ?', [(int)$letter['report_id']]);
}
$evidence = [];
if ($report && !empty($report['evidence'])) {
    $dec = json_decode((string)$report['evidence'], true);
    if (is_array($dec)) {
        $evidence = $dec;
    }
}

$status   = $letter ? (string)$letter['status'] : '';
$sealDate = $letter && !empty($letter['seal_date']) ? (string)$letter['seal_date'] : date('Y-m-d');
$sealCompany = YxLegalSeal::companyName();

$pageTitle  = '法务复核';
$activeMenu = 'legal_review';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<style>
.lr-wrap{display:grid;grid-template-columns:300px minmax(0,1fr);gap:16px;align-items:start}
.lr-list{max-height:70vh;overflow:auto}
.lr-item{display:block;padding:10px 12px;border:1px solid var(--line,#e3e8f2);border-radius:8px;margin-bottom:8px;text-decoration:none;color:inherit;background:#fff}
.lr-item.active{border-color:#2563eb;background:#f4f8ff}
.lr-item b{font-size:13px}
.lr-item span{display:block;font-size:12px;color:#6b7488;margin-top:2px}
.lr-doc{background:#fff;border:1px solid var(--line,#e3e8f2);border-radius:10px;padding:26px 32px;
        font-family:"Songti SC","SimSun","Noto Serif CJK SC","Microsoft YaHei",serif;line-height:1.95;font-size:15px;max-height:60vh;overflow:auto}
.lr-doc .ll-title{text-align:center;font-size:23px;font-weight:700;letter-spacing:10px;margin-bottom:6px}
.lr-doc .ll-h{font-weight:700;margin-top:14px}
.lr-doc .ll-line,.lr-doc .ll-item{text-indent:2em}
.lr-doc .ll-blank{height:8px}
.lr-seal{text-align:center;border:1px solid var(--line,#e3e8f2);border-radius:10px;padding:10px;background:#fbfcff}
.lr-seal img{width:170px;height:170px}
.lr-facts{font-size:13px;line-height:1.9}
.lr-facts dt{font-weight:600;color:#48506a;margin-top:6px}
.lr-facts dd{margin:0;color:#1b2130;word-break:break-all}
.lr-ev{font-size:12px;background:#f6f8fc;border-radius:6px;padding:8px 10px;white-space:pre-wrap;word-break:break-all;max-height:180px;overflow:auto}
.lr-tabs{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
.lr-tabs a{padding:6px 14px;border-radius:999px;border:1px solid var(--line,#e3e8f2);text-decoration:none;color:#48506a;font-size:13px;background:#fff}
.lr-tabs a.on{background:#2563eb;color:#fff;border-color:#2563eb}
@media (max-width:1080px){.lr-wrap{grid-template-columns:minmax(0,1fr)}}
</style>

<div class="lr-tabs">
    <a href="<?= e(url('admin/legal_review.php?tab=pending')) ?>" class="<?= $tab === 'pending' ? 'on' : '' ?>">待复核（<?= count($pending) ?>）</a>
    <a href="<?= e(url('admin/legal_review.php?tab=approved')) ?>" class="<?= $tab === 'approved' ? 'on' : '' ?>">待签发（<?= count($approved) ?>）</a>
    <a href="<?= e(url('admin/legal_review.php?tab=issued')) ?>" class="<?= $tab === 'issued' ? 'on' : '' ?>">已签发（<?= count($issued) ?>）</a>
    <a href="<?= e(url('admin/legal_review.php?tab=rejected')) ?>" class="<?= $tab === 'rejected' ? 'on' : '' ?>">已驳回（<?= count($rejected) ?>）</a>
    <a href="<?= e(url('admin/legal_letter.php')) ?>">律师函工作台 ↗</a>
</div>

<div class="lr-wrap">
    <div class="card lr-list">
        <h3 style="margin-top:0">
            <?php
            $listMap = [
                'pending'  => ['待复核', $pending],
                'approved' => ['待签发', $approved],
                'issued'   => ['已签发', $issued],
                'rejected' => ['已驳回', $rejected],
            ];
            $cur = $listMap[$tab] ?? $listMap['pending'];
            ?>
            <?= e($cur[0]) ?>（<?= count($cur[1]) ?>）
        </h3>
        <?php foreach ($cur[1] as $it): ?>
            <a class="lr-item <?= $letter && (int)$letter['id'] === (int)$it['id'] ? 'active' : '' ?>"
               href="<?= e(url('admin/legal_review.php?id=' . (int)$it['id'])) ?>">
                <b><?= e((string)$it['sn']) ?></b>
                <span><?= e(mb_substr((string)$it['title'], 0, 30)) ?></span>
                <span><?= e((string)($it['recipient'] ?: '未填收函方')) ?> · <?= e(YxLegalLetter::sourceName((string)$it['content_source'])) ?></span>
                <span><?= e((string)$it['updated_at']) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (!$cur[1]): ?><p class="empty">该列表为空</p><?php endif; ?>
    </div>

    <div>
    <?php if (!$letter): ?>
        <div class="card"><p class="empty">暂无可复核的律师函。请先在<a href="<?= e(url('admin/legal_letter.php')) ?>">律师函工作台</a>起草并提交复核。</p></div>
    <?php else: ?>
        <div class="card">
            <div class="row-between">
                <h2 style="margin:0">
                    <?= e((string)$letter['sn']) ?>
                    <span class="badge <?= e(YxLegalLetter::statusClass($status)) ?>"><?= e(YxLegalLetter::statusName($status)) ?></span>
                </h2>
                <div>
                    <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/legal_letter.php?id=' . (int)$letter['id'])) ?>">在工作台打开</a>
                    <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/legal_letter.php?a=print&id=' . (int)$letter['id'])) ?>" target="_blank">打印预览</a>
                </div>
            </div>
            <div class="row-between" style="margin-top:8px;font-size:13px;color:#5b6478">
                <div>
                    内部标题：<b><?= e((string)$letter['title']) ?></b>
                    ｜ 收函方：<?= e((string)($letter['recipient'] ?: '—')) ?>
                    <?php if (!empty($letter['recipient_domain'])): ?>（<?= e((string)$letter['recipient_domain']) ?>）<?php endif; ?>
                    ｜ 起草人：<?= e((string)($letter['drafter'] ?: '—')) ?>
                </div>
                <div>正文来源：<?= e(YxLegalLetter::sourceName((string)$letter['content_source'])) ?></div>
            </div>

            <div style="display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:16px;margin-top:14px;align-items:start">
                <div>
                    <h3 style="margin:0 0 6px">函件正文<?= $status === YxLegalLetter::ST_ISSUED ? '（已签发，只读）' : '' ?></h3>
                    <?php if ($status === YxLegalLetter::ST_PENDING): ?>
                        <form method="post" id="lrForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$letter['id'] ?>">
                            <input type="hidden" name="action" value="review">
                            <div class="grid-2" style="margin-bottom:8px">
                                <label>内部标题<input type="text" name="title" value="<?= e((string)$letter['title']) ?>" maxlength="180"></label>
                                <label>收函单位<input type="text" name="recipient" value="<?= e((string)$letter['recipient']) ?>" maxlength="180"></label>
                                <label>收函方域名<input type="text" name="recipient_domain" value="<?= e((string)$letter['recipient_domain']) ?>" maxlength="120"></label>
                                <label>事由<input type="text" name="subject" value="<?= e((string)$letter['subject']) ?>" maxlength="255"></label>
                            </div>
                            <label style="font-size:13px">正文定稿（复核人可直接修改，以本框为准）
                                <textarea name="content" rows="18" style="font-family:'Songti SC','SimSun','Noto Serif CJK SC','Microsoft YaHei',serif;line-height:1.9"><?= e((string)$letter['content']) ?></textarea>
                            </label>
                            <div class="" style="margin-top:10px"></div>
                            <div class="grid-2">
                                <label>复核人署名 *<input type="text" name="reviewer" value="<?= e((string)($letter['reviewer'] ?: $me)) ?>" maxlength="80" required></label>
                                <label>复核意见 <?= '<span class="muted small">（驳回必填）</span>' ?><input type="text" name="note" value="<?= e((string)$letter['review_note']) ?>" maxlength="255"></label>
                            </div>
                            <div class="ll-actions" style="display:flex;gap:8px;margin-top:6px">
                                <button class="btn btn-primary" type="submit" name="decision" value="approved" onclick="return confirm('确认复核通过？通过后将进入待签发状态')">复核通过</button>
                                <button class="btn btn-bad" type="submit" name="decision" value="rejected" onclick="return confirm('确认驳回？请确保已填写复核意见')">驳回并退回修改</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="lr-doc"><?= YxLegalLetter::renderText((string)$letter['content']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="lr-seal">
                        <img src="data:image/svg+xml;base64,<?= base64_encode(YxLegalSeal::svg([
                            'company' => (string)($letter['issuer'] ?: $sealCompany),
                            'date'    => $status === YxLegalLetter::ST_ISSUED ? $sealDate : date('Y-m-d'),
                            'sn'      => (string)$letter['sn'],
                            'size'    => 170,
                        ])) ?>" alt="电子印章预览">
                        <div class="muted small" style="margin-top:4px">
                            落款：<?= e((string)($letter['issuer'] ?: $sealCompany)) ?><br>
                            <?= $status === YxLegalLetter::ST_ISSUED ? '签章日期：' . e($sealDate) : '签发时自动加盖签章日期' ?>
                        </div>
                    </div>

                    <div class="card" style="margin-top:12px">
                        <h4 style="margin-top:0">核查事实与证据</h4>
                        <?php if ($report): ?>
                            <dl class="lr-facts">
                                <dt>核查单号</dt><dd class="mono"><?= e((string)$report['sn']) ?></dd>
                                <dt>涉及对象</dt><dd><?= e((string)($report['target_text'] ?: '—')) ?></dd>
                                <?php if (!empty($report['target_url'])): ?><dt>页面地址</dt><dd class="mono small"><?= e((string)$report['target_url']) ?></dd><?php endif; ?>
                                <dt>核查结论</dt><dd><?= e(YxInfringeLegal::reviewName((string)$report['review_status'])) ?></dd>
                                <dt>处置诉求</dt><dd><?= e(YxInfringeLegal::demandName((string)($report['demand'] ?? ''))) ?></dd>
                            </dl>
                            <?php if ($evidence): ?>
                                <h4 style="margin-bottom:4px">证据材料</h4>
                                <div class="lr-ev"><?= e((string)json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></div>
                            <?php endif; ?>
                        <?php elseif ($letter['facts_arr']): ?>
                            <dl class="lr-facts">
                                <?php foreach (['recipient' => '收函方', 'target' => '涉及域名', 'work_title' => '作品/产品', 'report_sn' => '核查单号', 'matter' => '补充说明'] as $k => $lab): ?>
                                    <?php if (!empty($letter['facts_arr'][$k])): ?>
                                        <dt><?= e($lab) ?></dt><dd><?= e((string)$letter['facts_arr'][$k]) ?></dd>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                        <?php else: ?>
                            <p class="muted small">本函为独立起草，未关联侵权核查单。</p>
                        <?php endif; ?>
                    </div>

                    <div class="card" style="margin-top:12px">
                        <h4 style="margin-top:0">复核记录</h4>
                        <div class="muted small" style="line-height:1.9">
                            复核人：<?= e((string)($letter['reviewer'] ?: '—')) ?><br>
                            复核时间：<?= e((string)($letter['reviewed_at'] ?: '—')) ?><br>
                            <?php if (!empty($letter['review_note'])): ?>复核意见：<?= e((string)$letter['review_note']) ?><br><?php endif; ?>
                            <?php if (!empty($letter['ai_note'])): ?>生成说明：<?= e((string)$letter['ai_note']) ?><br><?php endif; ?>
                            <?php if (!empty($letter['seal_hash'])): ?>
                                内容摘要：<code class="mono" style="word-break:break-all"><?= e((string)$letter['seal_hash']) ?></code><br>
                                核验：<?= YxLegalLetter::verify($letter) ? '<span class="badge badge-ok">一致</span>' : '<span class="badge badge-bad">正文已变更</span>' ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($logs): ?>
                            <ul class="muted small" style="padding-left:16px;line-height:1.8;margin-top:8px">
                                <?php foreach ($logs as $lg): ?>
                                    <li><?= e((string)$lg['created_at']) ?> · <?= e((string)($lg['note'] ?: $lg['action'])) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($status === YxLegalLetter::ST_APPROVED): ?>
                <div class="card" style="margin-top:14px;background:#f7faff">
                    <h3 style="margin-top:0">签发并加盖电子印章</h3>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$letter['id'] ?>">
                        <input type="hidden" name="action" value="issue">
                        <label style="font-size:13px">签发主体<input type="text" name="issuer" value="<?= e((string)($letter['issuer'] ?: $sealCompany)) ?>" style="width:260px"></label>
                        <label style="font-size:13px">印章日期<input type="date" name="seal_date" value="<?= e($sealDate) ?>"></label>
                        <button class="btn btn-primary" type="submit" onclick="return confirm('确认签发？签发后正文锁定并记录内容摘要')">确认签发</button>
                    </form>
                </div>
            <?php elseif ($status === YxLegalLetter::ST_ISSUED): ?>
                <div class="card" style="margin-top:14px">
                    <h3 style="margin-top:0">签章信息</h3>
                    <div class="muted small" style="line-height:1.9">
                        签发主体：<?= e((string)($letter['issuer'] ?: $sealCompany)) ?><br>
                        印章日期：<?= e($sealDate) ?>（<?= e(YxLegalSeal::dateText($sealDate, true)) ?>）<br>
                        签发时间：<?= e((string)$letter['issued_at']) ?><br>
                        内容摘要 sha256：<code class="mono" style="word-break:break-all"><?= e((string)$letter['seal_hash']) ?></code><br>
                        正文核验：<?= YxLegalLetter::verify($letter) ? '<span class="badge badge-ok">与签发时一致</span>' : '<span class="badge badge-bad">已变更，请核查</span>' ?>
                    </div>
                    <form method="post" class="inline-form" style="margin-top:10px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$letter['id'] ?>">
                        <input type="hidden" name="action" value="void">
                        <input type="text" name="note" placeholder="作废原因" style="width:220px">
                        <button class="btn btn-sm btn-bad" type="submit" onclick="return confirm('确认作废？')">作废</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
