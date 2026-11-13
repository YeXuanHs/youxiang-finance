<?php
/**
 * 优享财务系统 · 律师函（人工填写 / 编辑 / AI 辅助生成）
 *
 * 无 JS 时：全部功能通过表单 POST 完成（保存、AI 生成、提交复核、签发、打印）。
 * 有 JS 时：调用 admin/legal_api.php 做局部刷新，体验更好，但业务校验完全在后端。
 * 本页不做任何对外发送。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$view = (string)($_GET['a'] ?? '');          // '' | print

/* ------------------------------ 表单提交 ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $goto   = null;
    $postId = (int)($_POST['id'] ?? 0);

    $letterIn = [
        'title'            => (string)($_POST['title'] ?? ''),
        'recipient'        => (string)($_POST['recipient'] ?? ''),
        'recipient_domain' => (string)($_POST['recipient_domain'] ?? ''),
        'subject'          => (string)($_POST['subject'] ?? ''),
        'content'          => (string)($_POST['content'] ?? ''),
        'drafter'          => (string)($_POST['drafter'] ?? ''),
        'content_source'   => (string)($_POST['content_source'] ?? YxLegalLetter::SRC_MANUAL),
        'facts'            => array_filter([
            'recipient'  => (string)($_POST['recipient'] ?? ''),
            'subject'    => (string)($_POST['subject'] ?? ''),
            'target'     => (string)($_POST['recipient_domain'] ?? ''),
            'work_title' => (string)($_POST['work_title'] ?? ''),
            'matter'     => (string)($_POST['matter'] ?? ''),
            'report_sn'  => (string)($_POST['report_sn'] ?? ''),
            'demand'     => (string)($_POST['demand'] ?? ''),
        ], function ($v) { return trim((string)$v) !== ''; }),
    ];

    switch ($action) {
        case 'create':
            $letterIn['report_id'] = (int)($_POST['report_id'] ?? 0);
            $res  = YxLegalLetter::create($letterIn, $admin);
            $goto = $res['ok'] ? url('admin/legal_letter.php?id=' . $res['id']) : null;
            break;

        case 'save':
            $res = YxLegalLetter::save($postId, $letterIn, $admin);
            break;

        case 'ai_generate':
        case 'ai_polish':
            // AI 结果先在服务端算好并直接落库/回填，避免无 JS 环境下拿不到结果
            $mode = $action === 'ai_polish' ? 'polish' : 'full';
            $gen  = YxLegalLetter::generate($letterIn['facts'], $mode, $letterIn['content']);
            $letterIn['content']        = $gen['content'];
            $letterIn['content_source'] = $gen['source'];
            $res = ['ok' => true, 'msg' => $gen['note']];
            if ($postId > 0) {
                $save = YxLegalLetter::save($postId, $letterIn, $admin);
                if ($save['ok']) {
                    YxDb::update('law_letter', [
                        'ai_model' => mb_substr((string)$gen['engine'], 0, 80),
                        'ai_note'  => mb_substr((string)$gen['note'], 0, 255),
                        'updated_at' => now(),
                    ], 'id = :id', ['id' => $postId]);
                }
                $res['ok']  = $save['ok'];
                $res['msg'] = $save['ok'] ? '正文已生成并保存：' . $gen['note'] : $save['msg'];
            } else {
                $_SESSION['_ll_draft'] = $letterIn;
                $_SESSION['_ll_gen_note'] = (string)$gen['note'];
                $res['msg'] = '正文已生成：' . $gen['note'] . '（请确认后创建草稿）';
            }
            break;

        case 'submit':
            // 提交前保存草稿；但本次未带正文（或正文为空）时不要覆盖已有正文，
            // 否则无 JS / 部分字段提交会把已生成或已录入的正文清空
            $saveIn = $letterIn;
            if (trim($saveIn['content']) === '') {
                unset($saveIn['content']);
            }
            $save = YxLegalLetter::save($postId, $saveIn, $admin);
            if (!$save['ok']) {
                $res = $save;
                break;
            }
            $res = YxLegalLetter::submitForReview($postId, [
                'drafter'   => (string)($_POST['drafter'] ?? ''),
                'recipient' => (string)($_POST['recipient'] ?? ''),
            ], $admin);
            break;

        case 'issue':
            $res = YxLegalLetter::issue($postId, [
                'issuer'    => (string)($_POST['issuer'] ?? ''),
                'seal_date' => (string)($_POST['seal_date'] ?? ''),
            ], $admin);
            break;

        case 'void':
            $res = YxLegalLetter::void($postId, (string)($_POST['note'] ?? ''), $admin);
            break;

        case 'delete':
            $res  = YxLegalLetter::delete($postId, $admin);
            $goto = $res['ok'] ? url('admin/legal_letter.php') : null;
            break;
    }

    if ($goto !== null) {
        flash($res['msg']);
        redirect($goto);
    }
    // 保存类动作留在当前页，用一次性提示反馈
    flash($res['msg'], $res['ok'] ? 'success' : 'error');
    if ($action !== 'ai_generate' && $action !== 'ai_polish') {
        redirect($postId > 0 ? url('admin/legal_letter.php?id=' . $postId) : url('admin/legal_letter.php'));
    }
    // AI 生成后回到页面展示结果（草稿暂存已在上面写入 session）
    if ($postId > 0) {
        redirect(url('admin/legal_letter.php?id=' . $postId));
    }
    redirect(url('admin/legal_letter.php?new=1'));
}

/* ------------------------------ 数据准备 ------------------------------ */
$letter  = $id > 0 ? YxLegalLetter::get($id) : null;
$prefill = $_SESSION['_ll_draft'] ?? null;
$genNote = (string)($_SESSION['_ll_gen_note'] ?? '');
unset($_SESSION['_ll_draft'], $_SESSION['_ll_gen_note']);

if ($id > 0 && !$letter) {
    flash('律师函不存在或已被删除', 'error');
    redirect(url('admin/legal_letter.php'));
}

$isPrint = $view === 'print' && $letter;
$listQ   = ['status' => (string)($_GET['status'] ?? ''), 'kw' => (string)($_GET['kw'] ?? '')];
$list    = $isPrint ? null : YxLegalLetter::search($listQ);
$stats   = YxLegalLetter::stats();
$logs    = $letter ? YxLegalLetter::logs((int)$letter['id']) : [];

// 关联待审件（侵权核查）下拉
$reports = [];
try {
    $reports = YxDb::all('SELECT id, sn, target_text, work_title, review_status FROM ' . YxDb::table('infringe_report') . ' ORDER BY id DESC LIMIT 100');
} catch (Throwable $e) {
    $reports = [];
}

$f = function (string $k, string $default = '') use ($letter, $prefill) {
    if ($letter) {
        $v = (string)($letter[$k] ?? '');
        if ($v !== '') {
            return $v;
        }
    }
    if (is_array($prefill)) {
        return (string)($prefill[$k] ?? $default);
    }
    return $default;
};
$facts = $letter ? $letter['facts_arr'] : (is_array($prefill) ? (array)($prefill['facts'] ?? []) : []);
$factVal = function (string $k, string $d = '') use ($facts): string {
    $v = $facts[$k] ?? '';
    return is_scalar($v) && trim((string)$v) !== '' ? (string)$v : $d;
};

$status = $letter ? (string)$letter['status'] : YxLegalLetter::ST_DRAFT;
$locked = in_array($status, [YxLegalLetter::ST_ISSUED, YxLegalLetter::ST_VOID], true);
$readonlyAttr = $locked ? ' readonly' : '';
$sealDate = $letter && !empty($letter['seal_date']) ? (string)$letter['seal_date'] : date('Y-m-d');
$sealCompany = YxLegalSeal::companyName();
$sealSn = $letter ? (string)$letter['sn'] : '';

$pageTitle  = $isPrint ? '律师函预览 - ' . ($letter['sn'] ?? '') : '律师函';
$activeMenu = 'legal_letter';

/* 打印视图直接输出独立页面 */
if ($isPrint):
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>律师函 <?= e((string)$letter['sn']) ?> - <?= e(setting('site_name', config('app.name'))) ?></title>
<style>
    *{box-sizing:border-box}
    body{margin:0;background:#eef1f6;font-family:"Songti SC","SimSun","Noto Serif CJK SC","Noto Sans CJK SC","Microsoft YaHei",serif;color:#1b2130}
    .ll-page{width:794px;min-height:1123px;margin:24px auto;background:#fff;padding:78px 84px 96px;position:relative;box-shadow:0 6px 28px rgba(15,25,55,.12)}
    .ll-title{text-align:center;font-size:30px;letter-spacing:14px;font-weight:700;margin:0 0 6px;padding-left:14px}
    .ll-sn{text-align:center;font-size:13px;color:#5b6478;margin-bottom:30px;letter-spacing:1px}
    .ll-head{font-size:16px;line-height:2;margin-bottom:10px}
    .ll-h{font-size:16px;font-weight:700;margin:22px 0 6px}
    .ll-line{font-size:16px;line-height:2;text-indent:2em}
    .ll-item{font-size:16px;line-height:2;text-indent:2em}
    .ll-blank{height:10px}
    .ll-sign{margin-top:52px;text-align:right;font-size:16px;line-height:2.1;padding-right:12px}
    .ll-sign .ll-org{font-weight:700}
    .ll-stamp{position:absolute;width:190px;height:190px;right:104px;top:calc(100% - 292px);pointer-events:none;opacity:.94}
    .ll-stamp img{width:100%;height:100%}
    .ll-hash{margin-top:40px;font-size:11px;color:#7b8496;border-top:1px dashed #ccd3e0;padding-top:8px;line-height:1.7;font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all}
    .ll-draft{position:absolute;top:26px;right:30px;font-size:12px;color:#b32;border:1px solid #e2b3bb;border-radius:4px;padding:3px 8px;letter-spacing:2px}
    .ll-toolbar{width:794px;margin:18px auto 0;display:flex;gap:10px;justify-content:flex-end;font-family:"Microsoft YaHei",sans-serif}
    .ll-toolbar button{padding:8px 18px;border:0;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;font-size:14px}
    .ll-toolbar .ghost{background:#fff;color:#2563eb;border:1px solid #c3d4f7}
    @media print{
        body{background:#fff}
        .ll-page{width:auto;min-height:auto;margin:0;box-shadow:none;padding:0 8mm}
        .ll-toolbar{display:none}
        .ll-stamp{position:absolute;right:30mm;top:auto;bottom:26mm}
    }
</style>
</head>
<body>
<div class="ll-toolbar">
    <button class="ghost" type="button" onclick="window.location.href='<?= e(url('admin/legal_letter.php?id=' . (int)$letter['id'])) ?>'">返回编辑</button>
    <button type="button" onclick="window.print()">打印 / 另存为 PDF</button>
</div>
<div class="ll-page">
    <?php if ($status !== YxLegalLetter::ST_ISSUED): ?>
        <div class="ll-draft"><?= e(YxLegalLetter::statusName($status)) ?> · 未签发</div>
    <?php endif; ?>
    <h1 class="ll-title">律师函</h1>
    <div class="ll-sn">编号：<?= e((string)$letter['sn']) ?></div>
    <?= YxLegalLetter::renderText((string)$letter['content']) ?>
    <div class="ll-sign">
        <div class="ll-org"><?= e((string)($letter['issuer'] ?: $sealCompany)) ?></div>
        <div><?= e(YxLegalSeal::dateText((string)$sealDate, false)) ?></div>
    </div>
    <?php if ($status === YxLegalLetter::ST_ISSUED): ?>
        <div class="ll-stamp">
            <img src="<?= e('data:image/svg+xml;base64,' . base64_encode(YxLegalSeal::svg([
                'company' => (string)($letter['issuer'] ?: $sealCompany),
                'date'    => (string)$sealDate,
                'sn'      => (string)$letter['sn'],
                'size'    => 380,
            ]))) ?>" alt="电子印章">
        </div>
        <div class="ll-hash">
            电子签章信息：<?= e((string)($letter['issuer'] ?: $sealCompany)) ?> ｜ 签章日期 <?= e((string)$sealDate) ?>
            ｜ 内容摘要 sha256 <?= e((string)$letter['seal_hash']) ?>
            ｜ 核验结果 <?= YxLegalLetter::verify($letter) ? '一致（正文与签发时相同）' : '不一致（正文已被修改，请立即核查）' ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
<?php
return;
endif;

require dirname(__DIR__) . '/includes/admin_header.php';
?>

<style>
.ll-work{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start}
.ll-editor textarea{width:100%;min-height:520px;font-family:"Songti SC","SimSun","Noto Serif CJK SC","Microsoft YaHei",serif;font-size:14px;line-height:1.9;padding:14px 16px}
.ll-meta label{display:block;margin-bottom:10px;font-size:13px;color:#48506a;font-weight:600}
.ll-meta input,.ll-meta select,.ll-meta textarea{width:100%;margin-top:4px;font-weight:400}
.ll-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.ll-source{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px;color:#5b6478;margin-bottom:10px}
.ll-seal{border:1px solid var(--line,#e3e8f2);border-radius:10px;padding:12px;text-align:center;background:#fbfcff}
.ll-seal img{width:200px;height:200px;display:block;margin:0 auto}
.ll-sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:8px;margin-bottom:12px}
.ll-sum .bx{border:1px solid var(--line,#e3e8f2);border-radius:8px;padding:8px 10px;background:#fff}
.ll-sum .bx b{display:block;font-size:19px;line-height:1.4}
.ll-sum .bx span{font-size:12px;color:#6b7488}
.ll-body{background:#fff;border:1px solid var(--line,#e3e8f2);border-radius:10px;padding:28px 34px;font-family:"Songti SC","SimSun","Noto Serif CJK SC","Microsoft YaHei",serif;line-height:1.95;font-size:15px;color:#1b2130;max-height:640px;overflow:auto}
.ll-body .ll-title{text-align:center;font-size:24px;font-weight:700;letter-spacing:10px;margin-bottom:8px}
.ll-body .ll-h{font-weight:700;margin-top:14px}
.ll-body .ll-line,.ll-body .ll-item{text-indent:2em}
.ll-body .ll-blank{height:8px}
.ll-log{font-size:12px;color:#5b6478;margin-top:10px;line-height:1.9}
.ll-log li{margin-bottom:2px}
.ll-hint{font-size:12px;color:#7b8496;line-height:1.7}
@media (max-width:1180px){.ll-work{grid-template-columns:minmax(0,1fr)}}
</style>

<?php if (!$letter): ?>
<div class="card">
    <h2>新建律师函</h2>
    <p class="ll-hint">填写事实要素后，可先「AI 生成正文」得到草稿（当前引擎：<?= e(YxLegalLetter::aiEngineLabel()) ?>），再逐条人工核对修改；
        正文以本页编辑框内容为准，提交后进入<a href="<?= e(url('admin/legal_review.php')) ?>">法务复核</a>，复核通过后才可签发并加盖电子印章。</p>
    <form method="post" class="ll-meta">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="grid-2">
            <label>内部标题 *<input type="text" name="title" required maxlength="180" placeholder="例：某站未经授权使用软件函告" value="<?= e($f('title')) ?>"></label>
            <label>收函单位 *<input type="text" name="recipient" maxlength="180" placeholder="被函告方名称" value="<?= e($f('recipient', $factVal('recipient'))) ?>"></label>
            <label>收函方域名<input type="text" name="recipient_domain" maxlength="120" placeholder="example.com" value="<?= e($f('recipient_domain', $factVal('target'))) ?>"></label>
            <label>事由 / 主题<input type="text" name="subject" maxlength="255" placeholder="关于未经授权使用「优享财务系统」软件的函告" value="<?= e($f('subject', $factVal('subject'))) ?>"></label>
            <label>涉及作品/产品<input type="text" name="work_title" maxlength="120" value="<?= e($factVal('work_title')) ?>"></label>
            <label>整理人 / 起草人<input type="text" name="drafter" maxlength="80" value="<?= e($f('drafter', (string)($admin['nickname'] ?: $admin['username']))) ?>"></label>
            <label>关联侵权核查单<select name="report_id">
                <option value="0">不关联（独立起草）</option>
                <?php foreach ($reports as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= e((string)$r['sn']) ?> · <?= e((string)($r['target_text'] ?: $r['work_title'])) ?>（<?= e(YxInfringeLegal::reviewName((string)$r['review_status'])) ?>）</option>
                <?php endforeach; ?>
            </select></label>
            <label>处置诉求<select name="demand">
                <?php foreach ([YxInfringeLegal::DEMAND_TAKEDOWN, YxInfringeLegal::DEMAND_PURCHASE, YxInfringeLegal::DEMAND_DRAFT] as $d): ?>
                    <option value="<?= e($d) ?>"><?= e(YxInfringeLegal::demandName($d)) ?></option>
                <?php endforeach; ?>
            </select></label>
        </div>
        <label>补充说明（人工填写的事实要点，会作为素材带入正文）<textarea name="matter" rows="3" placeholder="例：经查该站发行包未植入有效授权文件，下载日志 3 条"></textarea></label>
        <label>正文（可留空，稍后生成或人工撰写）<textarea name="content" rows="8"><?= e((string)($prefill['content'] ?? '')) ?></textarea></label>
        <?php if ($genNote !== ''): ?>
            <div class="alert alert-ok"><?= e($genNote) ?></div>
        <?php endif; ?>
        <div class="ll-actions">
            <button class="btn btn-primary" type="submit">创建草稿</button>
            <button class="btn btn-ghost" type="submit" name="action" value="ai_generate">AI 生成正文（先不建草稿）</button>
            <a class="btn btn-mute" href="<?= e(url('admin/legal_letter.php')) ?>">取消</a>
        </div>
    </form>
</div>
<?php else: ?>

<div class="ll-sum">
    <div class="bx"><b><?= (int)$stats['total'] ?></b><span>全部律师函</span></div>
    <div class="bx"><b><?= (int)($stats[YxLegalLetter::ST_DRAFT] ?? 0) ?></b><span>草稿</span></div>
    <div class="bx"><b><?= (int)($stats[YxLegalLetter::ST_PENDING] ?? 0) ?></b><span>待复核</span></div>
    <div class="bx"><b><?= (int)($stats[YxLegalLetter::ST_APPROVED] ?? 0) ?></b><span>待签发</span></div>
    <div class="bx"><b><?= (int)($stats[YxLegalLetter::ST_ISSUED] ?? 0) ?></b><span>已签发</span></div>
</div>

<div class="ll-work">
    <div class="card ll-editor">
        <div class="row-between">
            <h2 style="margin:0">
                <?= e((string)$letter['sn']) ?>
                <span class="badge <?= e(YxLegalLetter::statusClass($status)) ?>"><?= e(YxLegalLetter::statusName($status)) ?></span>
            </h2>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/legal_letter.php?a=print&id=' . (int)$letter['id'])) ?>" target="_blank">预览 / 打印</a>
        </div>

        <div class="ll-source" style="margin-top:10px">
            <span>正文来源：<b><?= e(YxLegalLetter::sourceName((string)$letter['content_source'])) ?></b></span>
            <?php if (!empty($letter['ai_model'])): ?><span>引擎：<code class="mono"><?= e((string)$letter['ai_model']) ?></code></span><?php endif; ?>
            <?php if (!empty($letter['ai_note'])): ?><span><?= e((string)$letter['ai_note']) ?></span><?php endif; ?>
            <span>当前可用：<?= e(YxLegalLetter::aiEngineLabel()) ?></span>
        </div>

        <form method="post" id="llForm" class="ll-meta">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$letter['id'] ?>">
            <input type="hidden" name="content_source" id="llSource" value="<?= e((string)$letter['content_source']) ?>">
            <div class="grid-2">
                <label>内部标题 *<input type="text" name="title" required maxlength="180" value="<?= e((string)$letter['title']) ?>"<?= $readonlyAttr ?>></label>
                <label>收函单位 *<input type="text" name="recipient" maxlength="180" value="<?= e((string)$letter['recipient']) ?>"<?= $readonlyAttr ?>></label>
                <label>收函方域名<input type="text" name="recipient_domain" maxlength="120" value="<?= e((string)$letter['recipient_domain']) ?>"<?= $readonlyAttr ?>></label>
                <label>事由 / 主题<input type="text" name="subject" maxlength="255" value="<?= e((string)$letter['subject']) ?>"<?= $readonlyAttr ?>></label>
                <label>涉及作品/产品<input type="text" name="work_title" maxlength="120" value="<?= e($factVal('work_title')) ?>"<?= $readonlyAttr ?>></label>
                <label>起草人 *<input type="text" name="drafter" maxlength="80" value="<?= e((string)$letter['drafter']) ?>"<?= $readonlyAttr ?>></label>
                <label>处置诉求<select name="demand"<?= $readonlyAttr ? ' disabled' : '' ?>>
                    <?php $curDemand = $factVal('demand'); ?>
                    <?php foreach ([YxInfringeLegal::DEMAND_TAKEDOWN, YxInfringeLegal::DEMAND_PURCHASE, YxInfringeLegal::DEMAND_DRAFT] as $d): ?>
                        <option value="<?= e($d) ?>" <?= $curDemand === $d ? 'selected' : '' ?>><?= e(YxInfringeLegal::demandName($d)) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>核查单号<input type="text" name="report_sn" maxlength="60" value="<?= e((string)($letter['report_sn'] ?: $factVal('report_sn'))) ?>" readonly></label>
            </div>
            <label>补充说明<textarea name="matter" rows="2"<?= $readonlyAttr ?>><?= e($factVal('matter')) ?></textarea></label>

            <label style="margin-bottom:4px">律师函正文（以本框内容为准）</label>
            <textarea name="content" id="llContent"<?= $readonlyAttr ?>><?= e((string)$letter['content']) ?></textarea>

            <?php if (!$locked): ?>
            <div class="ll-actions">
                <button class="btn btn-primary" type="submit" name="action" value="save">保存内容</button>
                <button class="btn btn-ghost" type="submit" name="action" value="ai_generate">AI 生成正文</button>
                <button class="btn btn-ghost" type="submit" name="action" value="ai_polish">AI 润色当前正文</button>
                <button class="btn btn-warn" type="submit" name="action" value="submit">提交法务复核</button>
                <a class="btn btn-mute" href="<?= e(url('admin/legal_review.php')) ?>">去法务复核</a>
            </div>
            <?php else: ?>
            <div class="alert alert-warn" style="margin-top:12px">
                该律师函<?= e(YxLegalLetter::statusName($status)) ?>，内容已锁定不可编辑。
                <?php if ($status === YxLegalLetter::ST_ISSUED): ?>如需变更请作废后重新起草。<?php endif; ?>
            </div>
            <div class="ll-actions">
                <a class="btn btn-primary" href="<?= e(url('admin/legal_letter.php?a=print&id=' . (int)$letter['id'])) ?>" target="_blank">查看 / 打印正式函件</a>
            </div>
            <?php endif; ?>
        </form>

        <?php if ($locked && $status === YxLegalLetter::ST_ISSUED): ?>
            <form method="post" class="inline-form" style="margin-top:10px">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$letter['id'] ?>"><input type="hidden" name="action" value="void">
                <input type="text" name="note" placeholder="作废原因" style="width:220px">
                <button class="btn btn-sm btn-bad" type="submit" onclick="return confirm('确认作废该已签发律师函？')">作废</button>
            </form>
        <?php elseif ($status === YxLegalLetter::ST_DRAFT || $status === YxLegalLetter::ST_REJECTED): ?>
            <form method="post" class="inline-form" style="margin-top:10px">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$letter['id'] ?>"><input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-mute" type="submit" onclick="return confirm('确认删除该草稿？此操作不可恢复')">删除草稿</button>
            </form>
        <?php endif; ?>

        <?php if ($status === YxLegalLetter::ST_APPROVED): ?>
            <div class="card" style="margin-top:14px;background:#f7faff">
                <h3 style="margin-top:0">签发并加盖电子印章</h3>
                <p class="ll-hint">复核已通过。确认正文无误后签发，系统将按签发日期生成红色电子印章，并记录内容摘要（sha256）用于事后核验。</p>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$letter['id'] ?>"><input type="hidden" name="action" value="issue">
                    <label style="font-size:13px">签发主体<input type="text" name="issuer" value="<?= e((string)($letter['issuer'] ?: $sealCompany)) ?>" style="width:280px"></label>
                    <label style="font-size:13px">印章日期<input type="date" name="seal_date" value="<?= e($sealDate) ?>"></label>
                    <button class="btn btn-primary" type="submit" onclick="return confirm('确认签发并加盖电子印章？签发后内容将锁定')">确认签发</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card ll-seal">
            <h3 style="margin-top:0">电子印章预览</h3>
            <?php
            $sealOpt = [
                'company' => (string)($letter['issuer'] ?: $sealCompany),
                'date'    => $status === YxLegalLetter::ST_ISSUED ? (string)$sealDate : date('Y-m-d'),
                'sn'      => (string)$letter['sn'],
                'size'    => 200,
            ];
            ?>
            <img id="llSealImg" src="data:image/svg+xml;base64,<?= base64_encode(YxLegalSeal::svg($sealOpt)) ?>" alt="电子印章预览">
            <div class="ll-hint" style="margin-top:6px">
                <?= e($sealOpt['company']) ?><br>
                日期：<?= e(YxLegalSeal::dateText($sealOpt['date'], false)) ?>
            </div>
            <div class="ll-actions" style="justify-content:center">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/legal_api.php?do=seal_png&id=' . (int)$letter['id'] . '&sn=' . urlencode((string)$letter['sn']) . '&date=' . urlencode($sealOpt['date']))) ?>" target="_blank">下载 PNG</a>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/legal_api.php?do=seal_svg&id=' . (int)$letter['id'] . '&sn=' . urlencode((string)$letter['sn']) . '&date=' . urlencode($sealOpt['date']))) ?>" target="_blank">下载 SVG</a>
            </div>
        </div>

        <div class="card" style="margin-top:14px">
            <h3 style="margin-top:0">复核与签发信息</h3>
            <div class="ll-hint">
                <div>状态：<?= e(YxLegalLetter::statusName($status)) ?></div>
                <div>起草人：<?= e((string)($letter['drafter'] ?: '—')) ?></div>
                <div>复核人：<?= e((string)($letter['reviewer'] ?: '—')) ?></div>
                <div>复核时间：<?= e((string)($letter['reviewed_at'] ?: '—')) ?></div>
                <?php if (!empty($letter['review_note'])): ?><div>复核意见：<?= e((string)$letter['review_note']) ?></div><?php endif; ?>
                <?php if (!empty($letter['seal_hash'])): ?>
                    <div style="margin-top:6px">印章日期：<?= e((string)$letter['seal_date']) ?></div>
                    <div style="word-break:break-all">内容摘要：<code class="mono"><?= e((string)$letter['seal_hash']) ?></code></div>
                    <div>核验：<?= YxLegalLetter::verify($letter) ? '<span class="badge badge-ok">正文与签发时一致</span>' : '<span class="badge badge-bad">正文已变更</span>' ?></div>
                <?php endif; ?>
                <?php if (!empty($letter['ai_note'])): ?><div style="margin-top:6px">生成说明：<?= e((string)$letter['ai_note']) ?></div><?php endif; ?>
            </div>
            <?php if ($logs): ?>
                <h4 style="margin-bottom:4px">流程留痕</h4>
                <ul class="ll-log">
                    <?php foreach ($logs as $lg): ?>
                        <li><?= e((string)$lg['created_at']) ?> · <?= e((string)$lg['note'] ?: (string)$lg['action']) ?><?= $lg['operator'] ? ' · ' . e((string)$lg['operator']) : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// 局部增强：AI 生成/润色走 JSON 接口，避免整页刷新；失败自动回退到表单提交
(function () {
    var form = document.getElementById('llForm');
    if (!form || !window.fetch) return;
    var ta = document.getElementById('llContent');
    var src = document.getElementById('llSource');
    var api = <?= json_encode(url('admin/legal_api.php'), JSON_UNESCAPED_SLASHES) ?>;
    var csrf = <?= json_encode(csrf_token()) ?>;
    var id = <?= (int)$letter['id'] ?>;

    function facts() {
        var o = {};
        ['recipient', 'subject', 'work_title', 'matter', 'report_sn', 'demand'].forEach(function (k) {
            var el = form.querySelector('[name="' + k + '"]');
            if (el) o[k] = el.value;
        });
        var d = form.querySelector('[name="recipient_domain"]');
        if (d) { o.target = d.value; o.domain = d.value; }
        return o;
    }

    form.querySelectorAll('button[name="action"]').forEach(function (btn) {
        var act = btn.value;
        if (act !== 'ai_generate' && act !== 'ai_polish') return;
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var body = new FormData();
            body.append('_csrf', csrf);
            body.append('id', id);
            body.append('content', ta.value);
            Object.keys(facts()).forEach(function (k) { body.append('facts[' + k + ']', facts()[k]); });
            btn.disabled = true;
            var old = btn.textContent;
            btn.textContent = '生成中…';
            fetch(api + '?do=' + act, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    btn.disabled = false; btn.textContent = old;
                    if (!j.ok) { alert(j.msg || '生成失败'); return; }
                    ta.value = j.content;
                    if (src) src.value = j.source;
                    alert('已生成：' + j.note + '\n记得点击「保存内容」落库。');
                })
                .catch(function () { btn.disabled = false; btn.textContent = old; form.submit(); });
        });
    });
})();
</script>

<?php endif; ?>

<div class="card" style="margin-top:16px">
    <div class="row-between">
        <h2 style="margin:0">律师函列表（<?= (int)$list['total'] ?>）</h2>
        <a class="btn btn-sm btn-primary" href="<?= e(url('admin/legal_letter.php?new=1')) ?>">新建律师函</a>
    </div>
    <form method="get" class="inline-form" style="margin:10px 0">
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach ([YxLegalLetter::ST_DRAFT, YxLegalLetter::ST_PENDING, YxLegalLetter::ST_APPROVED, YxLegalLetter::ST_REJECTED, YxLegalLetter::ST_ISSUED, YxLegalLetter::ST_VOID] as $s): ?>
                <option value="<?= e($s) ?>" <?= $listQ['status'] === $s ? 'selected' : '' ?>><?= e(YxLegalLetter::statusName($s)) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="kw" value="<?= e($listQ['kw']) ?>" placeholder="编号 / 收函方 / 标题">
        <button class="btn btn-sm btn-primary" type="submit">筛选</button>
        <a class="btn btn-sm btn-mute" href="<?= e(url('admin/legal_letter.php')) ?>">重置</a>
    </form>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>编号</th><th>标题 / 事由</th><th>收函方</th><th>来源</th><th>状态</th><th>起草 / 复核</th><th>更新时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr>
                <td class="mono small"><?= e((string)$r['sn']) ?></td>
                <td class="wrap"><?= e((string)$r['title']) ?><?php if (!empty($r['subject'])): ?><br><span class="muted small"><?= e((string)$r['subject']) ?></span><?php endif; ?></td>
                <td class="small"><?= e((string)($r['recipient'] ?: '—')) ?><?php if (!empty($r['recipient_domain'])): ?><br><span class="mono"><?= e((string)$r['recipient_domain']) ?></span><?php endif; ?></td>
                <td class="small"><?= e(YxLegalLetter::sourceName((string)$r['content_source'])) ?></td>
                <td><span class="badge <?= e(YxLegalLetter::statusClass((string)$r['status'])) ?>"><?= e(YxLegalLetter::statusName((string)$r['status'])) ?></span></td>
                <td class="small"><?= e((string)($r['drafter'] ?: '—')) ?><br><span class="muted"><?= e((string)($r['reviewer'] ?: '待复核')) ?></span></td>
                <td class="small muted"><?= e((string)$r['updated_at']) ?></td>
                <td>
                    <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/legal_letter.php?id=' . (int)$r['id'])) ?>">编辑</a>
                    <?php if ((string)$r['status'] === YxLegalLetter::ST_ISSUED): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/legal_letter.php?a=print&id=' . (int)$r['id'])) ?>" target="_blank">打印</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="8" class="empty">暂无律师函，点击右上角「新建律师函」开始起草</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($list['pager']) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
