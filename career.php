<?php
/**
 * 优享财务系统 · 加入我们（招聘职位 + 投递简历）
 *
 * 三步走：浏览在招职位 → 线上填写并投递简历 → 凭投递编号 + 手机号查询进度
 * 职位详情使用锚点（#job-{id}），投递表单会随所选职位自动填充。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$jobs    = YxCareer::jobs();
$grouped = YxCareer::grouped($jobs);
$openApply = YxCareer::openForApply();

$errors  = [];
$old     = [];
$notice  = '';
$myResume = null;

/* ------------------------------ 提交 ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'apply') {
        $old = $_POST;
        $ip  = client_ip();
        if (YxCareer::recentByIp($ip, 10) >= 3) {
            $errors[] = '同一网络 10 分钟内最多投递 3 次，请稍后再试';
        } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
            // 蜜罐字段：正常用户不会填写
            $errors[] = '提交失败，请刷新页面后重试';
        } else {
            $file = $_FILES['resume_file'] ?? null;
            $res  = YxCareer::submit($_POST, $file);
            if (!empty($res['ok'])) {
                flash('简历已投递成功，投递编号 ' . $res['sn'] . '，请保存编号以便查询进度');
                redirect(url('career.php?sn=' . urlencode((string)$res['sn']) . '&phone='
                    . urlencode(substr((string)($_POST['phone'] ?? ''), -4)) . '#query'));
            }
            $errors[] = (string)($res['msg'] ?? '提交失败');
        }
    } elseif ($do === 'query') {
        $sn    = trim((string)($_POST['sn'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
        $row   = YxCareer::bySn($sn);
        $rowPhone = $row ? preg_replace('/\D+/', '', (string)$row['phone']) : '';
        if (!$row || $rowPhone === '' || substr($rowPhone, -4) !== substr($phone, -4) || strlen($phone) < 4) {
            $errors[] = '投递编号或手机号不匹配，请核对后重试';
        } else {
            $myResume = $row;
        }
    }
}

$flash = flash();
if ($flash) {
    $notice = (string)$flash['msg'];
}

$pageTitle = '加入我们 - 招聘职位';
require __DIR__ . '/includes/header.php';
?>

<section class="zd-section zd-sec-gray" style="padding:56px 0 34px">
    <div class="portal-container">
        <div class="zd-section-head" style="text-align:left">
            <span class="career-eyebrow">JOIN THE TEAM</span>
            <h1 style="margin:10px 0 10px">与懂行业、爱产品的人一起成长</h1>
            <p class="muted" style="max-width:760px;line-height:1.9;margin:0">
                <?= e(trim(YxAbout::get('about_join_text')) !== ''
                    ? mb_substr(trim(YxAbout::get('about_join_text')), 0, 160)
                    : '我们期待产品、设计、研发、测试和市场等不同专业背景的伙伴加入，共同参与业务从需求洞察、产品研发到客户交付的完整过程。') ?>
            </p>
        </div>

        <?php $benefits = YxAbout::items('benefit'); ?>
        <?php if ($benefits): ?>
            <div class="grid-3" style="margin-top:22px">
                <?php foreach ($benefits as $b): ?>
                    <div class="card">
                        <strong><?= e($b['title']) ?></strong>
                        <?php if (trim((string)$b['content']) !== ''): ?>
                            <p class="muted small" style="margin:6px 0 0;line-height:1.8"><?= nl2br(e($b['content'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:20px">
            <a class="btn btn-primary" href="#apply">投递简历</a>
            <a class="btn btn-ghost" href="#query">查询进度</a>
        </div>
    </div>
</section>

<?php if ($notice !== ''): ?>
<section class="section" style="padding-bottom:0">
    <div class="alert alert-ok"><?= e($notice) ?></div>
</section>
<?php endif; ?>

<?php if ($errors): ?>
<section class="section" style="padding-bottom:0">
    <div class="alert alert-error">
        <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- 投递步骤 -->
<section class="section">
    <div class="section-head">
        <h2>投递流程</h2>
        <span class="muted small">线上完成，无需注册</span>
    </div>
    <div class="grid-3 career-steps">
        <div class="card"><div class="muted small mono">STEP 01</div><h3 style="margin:6px 0 8px">选择职位</h3><p class="muted small" style="margin:0;line-height:1.8">在下方职位列表中找到合适岗位，点击「投递该职位」自动带入职位信息。</p></div>
        <div class="card"><div class="muted small mono">STEP 02</div><h3 style="margin:6px 0 8px">填写并投递</h3><p class="muted small" style="margin:0;line-height:1.8">填写基本信息、工作经历与自我介绍，可上传 PDF / Word 简历附件。</p></div>
        <div class="card"><div class="muted small mono">STEP 03</div><h3 style="margin:6px 0 8px">查询进度</h3><p class="muted small" style="margin:0;line-height:1.8">提交后会生成投递编号，凭编号 + 手机号随时查看筛选、面试与录用进展。</p></div>
    </div>
</section>

<!-- 在招职位 -->
<section class="section">
    <div class="section-head">
        <h2>在招职位</h2>
        <span class="muted small">共 <?= count($jobs) ?> 个职位</span>
    </div>

    <?php if (!$jobs): ?>
        <div class="card"><div class="empty">暂无在招职位，欢迎先提交简历至 <a href="#apply">简历投递</a>，我们会主动联系。</div></div>
    <?php else: ?>
        <?php $idx = 0; foreach ($grouped as $cat => $list): ?>
            <div class="career-group">
                <h3 class="career-group-title"><span><?= str_pad((string)(++$idx), 2, '0', STR_PAD_LEFT) ?></span><?= e($cat) ?></h3>
                <div class="career-jobs">
                    <?php foreach ($list as $job): ?>
                        <article class="career-job" id="job-<?= (int)$job['id'] ?>">
                            <div class="career-job-head">
                                <div>
                                    <h4><?= e($job['title']) ?></h4>
                                    <div class="career-job-meta">
                                        <?php if (trim((string)$job['city']) !== ''): ?><span>📍 <?= e($job['city']) ?></span><?php endif; ?>
                                        <span><?= e(YxCareer::jobTypeName((string)$job['job_type'])) ?></span>
                                        <?php if (trim((string)$job['experience']) !== ''): ?><span><?= e($job['experience']) ?></span><?php endif; ?>
                                        <?php if (trim((string)$job['education']) !== ''): ?><span><?= e($job['education']) ?></span><?php endif; ?>
                                        <?php if ((int)$job['headcount'] > 0): ?><span>招 <?= (int)$job['headcount'] ?> 人</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="career-job-side">
                                    <strong class="career-salary"><?= e(trim((string)$job['salary']) !== '' ? $job['salary'] : '面议') ?></strong>
                                    <button type="button" class="btn btn-primary btn-sm js-apply-job"
                                            data-id="<?= (int)$job['id'] ?>" data-title="<?= e($job['title']) ?>">投递该职位</button>
                                </div>
                            </div>

                            <?php $desc = YxCareer::lines((string)$job['description']); ?>
                            <?php $req  = YxCareer::lines((string)$job['requirement']); ?>
                            <?php if ($desc): ?>
                                <div class="career-job-body"><b>岗位职责</b><ul><?php foreach ($desc as $li): ?><li><?= e($li) ?></li><?php endforeach; ?></ul></div>
                            <?php endif; ?>
                            <?php if ($req): ?>
                                <div class="career-job-body"><b>任职要求</b><ul><?php foreach ($req as $li): ?><li><?= e($li) ?></li><?php endforeach; ?></ul></div>
                            <?php endif; ?>
                            <div class="career-job-foot muted small">
                                <?php $mb = YxCareer::jobMailbox($job); ?>
                                <?= $mb !== '' ? '简历接收邮箱：' . e($mb) : '通过下方表单在线投递' ?>
                                · 更新于 <?= e(substr((string)($job['updated_at'] ?: $job['created_at']), 0, 10)) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<!-- 投递表单 -->
<section class="section" id="apply">
    <div class="section-head">
        <h2>投递简历</h2>
        <span class="muted small">带 * 为必填项</span>
    </div>
    <?php if (!$openApply): ?>
        <div class="card"><div class="empty">当前未开放在线投递，请先通过 <a href="<?= e(url('user/tickets.php')) ?>">工单</a> 联系我们。</div></div>
    <?php else: ?>
    <div class="card">
        <form method="post" enctype="multipart/form-data" id="resumeForm" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="apply">
            <input type="hidden" name="source" value="web">
            <div style="position:absolute;left:-9999px" aria-hidden="true">
                <label>请勿填写<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <label>应聘职位 *
                <select name="job_id" id="jobSelect" required>
                    <option value="">请选择职位</option>
                    <?php foreach ($grouped as $cat => $list): ?>
                        <optgroup label="<?= e($cat) ?>">
                            <?php foreach ($list as $job): ?>
                                <option value="<?= (int)$job['id'] ?>"
                                    <?= (int)($old['job_id'] ?? 0) === (int)$job['id'] ? 'selected' : '' ?>>
                                    <?= e($job['title']) ?><?= trim((string)$job['city']) !== '' ? '（' . e($job['city']) . '）' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="grid-2">
                <label>姓名 * <input type="text" name="name" maxlength="40" required value="<?= e($old['name'] ?? '') ?>" placeholder="真实姓名"></label>
                <label>性别
                    <select name="gender">
                        <?php foreach (['' => '不填', '男' => '男', '女' => '女'] as $gk => $gv): ?>
                            <option value="<?= e($gk) ?>" <?= (string)($old['gender'] ?? '') === $gk ? 'selected' : '' ?>><?= e($gv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>手机号 * <input type="tel" name="phone" maxlength="20" required value="<?= e($old['phone'] ?? '') ?>" placeholder="用于接收面试通知"></label>
                <label>邮箱 * <input type="email" name="email" maxlength="120" required value="<?= e($old['email'] ?? '') ?>" placeholder="name@example.com"></label>
                <label>出生年份 <input type="number" name="birth_year" min="1940" max="<?= (int)date('Y') ?>" value="<?= e($old['birth_year'] ?? '') ?>" placeholder="如 1996"></label>
                <label>现居城市 <input type="text" name="city" maxlength="60" value="<?= e($old['city'] ?? '') ?>"></label>
                <label>最高学历
                    <select name="education">
                        <?php $eduNow = (string)($old['education'] ?? ''); ?>
                        <?php foreach (['' => '请选择', '大专' => '大专', '本科' => '本科', '硕士' => '硕士', '博士' => '博士', '其他' => '其他'] as $ek => $ev): ?>
                            <option value="<?= e($ek) ?>" <?= $eduNow === $ek ? 'selected' : '' ?>><?= e($ev) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>工作经验
                    <select name="experience">
                        <?php $expNow = (string)($old['experience'] ?? ''); ?>
                        <?php foreach (['' => '请选择', '应届生' => '应届生', '1-3 年' => '1-3 年', '3-5 年' => '3-5 年', '5-10 年' => '5-10 年', '10 年以上' => '10 年以上'] as $xk => $xv): ?>
                            <option value="<?= e($xk) ?>" <?= $expNow === $xk ? 'selected' : '' ?>><?= e($xv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>当前 / 最近公司 <input type="text" name="current_company" maxlength="120" value="<?= e($old['current_company'] ?? '') ?>"></label>
                <label>作品集 / 主页链接 <input type="text" name="portfolio" maxlength="255" value="<?= e($old['portfolio'] ?? '') ?>" placeholder="https://（可选）"></label>
            </div>

            <label>简历附件 <span class="muted small">支持 PDF / DOC / DOCX，单个不超过 10 MB</span>
                <input type="file" name="resume_file" accept=".pdf,.doc,.docx">
            </label>

            <label>自我介绍 / 求职留言 <textarea name="message" rows="5" maxlength="3000" placeholder="简单介绍你的经历、擅长方向与到岗时间"><?= e($old['message'] ?? '') ?></textarea></label>

            <label class="checkbox"><input type="checkbox" required> 我确认以上信息真实有效，并同意用于本次招聘沟通</label>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">提交简历</button>
                <a class="btn btn-ghost" href="#query">查询投递进度</a>
            </div>
            <p class="muted small" style="margin:10px 0 0">我们会在收到简历后 3 个工作日内完成初筛，通过筛选会由 HR 电话或邮件联系。</p>
        </form>
    </div>
    <?php endif; ?>
</section>

<!-- 进度查询 -->
<section class="section" id="query">
    <div class="section-head"><h2>查询投递进度</h2></div>
    <div class="grid-2">
        <div class="card">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="query">
                <label>投递编号 <input type="text" name="sn" required value="<?= e($_POST['sn'] ?? ($_GET['sn'] ?? '')) ?>" placeholder="RS..."></label>
                <label>手机号（可只填后 4 位） <input type="text" name="phone" required value="<?= e($_GET['phone'] ?? '') ?>" placeholder="138****0000"></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">查询</button></div>
            </form>
        </div>
        <div class="card">
            <?php if ($myResume): ?>
                <h3 style="margin-top:0">投递编号 <?= e($myResume['sn']) ?></h3>
                <table class="data">
                    <tr><th style="width:100px">应聘职位</th><td><?= e($myResume['job_title']) ?></td></tr>
                    <tr><th>投递时间</th><td><?= e($myResume['created_at']) ?></td></tr>
                    <tr><th>当前状态</th><td><span class="badge <?= e(YxCareer::statusBadge((string)$myResume['status'])) ?>"><?= e(YxCareer::statusName((string)$myResume['status'])) ?></span></td></tr>
                    <tr><th>简历附件</th><td><?= $myResume['file_name'] ? e($myResume['file_name']) : '<span class="muted">未上传</span>' ?></td></tr>
                </table>
                <?php if (trim((string)$myResume['note']) !== ''): ?>
                    <div class="career-note"><b>HR 反馈</b><p><?= nl2br(e($myResume['note'])) ?></p></div>
                <?php else: ?>
                    <p class="muted small" style="margin-top:12px">目前尚未有新的处理反馈，我们正在查看你的简历。</p>
                <?php endif; ?>
            <?php else: ?>
                <h3 style="margin-top:0">查询说明</h3>
                <p class="muted small" style="line-height:1.9">
                    投递成功后页面会显示投递编号（以 RS 开头），请妥善保存。<br>
                    查询需同时提供投递编号与投递时填写的手机号，避免他人查看你的信息。<br>
                    状态含义：待筛选 → 筛选中 → 面试中 → 已发offer → 已入职；若为「未通过」，可投递其他岗位。
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<style>
.career-eyebrow{display:inline-block;font-size:12px;letter-spacing:.08em;color:#1c7ed6;background:#eef4ff;border-radius:20px;padding:4px 12px}
.career-group{margin-bottom:26px}
.career-group-title{display:flex;align-items:center;gap:10px;font-size:16px;margin:0 0 12px}
.career-group-title span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#eef4ff;color:#1c7ed6;font-size:12px;font-weight:700}
.career-jobs{display:grid;gap:14px}
.career-job{border:1px solid #e6ecf5;border-radius:14px;padding:18px;background:#fff;transition:border-color .2s, box-shadow .2s}
.career-job:target{border-color:#1c7ed6;box-shadow:0 0 0 3px rgba(28,126,214,.12)}
.career-job:hover{border-color:#c8d8f0}
.career-job-head{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start}
.career-job-head h4{margin:0 0 8px;font-size:17px}
.career-job-meta{display:flex;gap:8px;flex-wrap:wrap;font-size:12px;color:#64748b}
.career-job-meta span{background:#f4f7fc;border-radius:20px;padding:3px 10px}
.career-job-side{display:flex;align-items:center;gap:12px}
.career-salary{color:#e8590c;font-size:15px}
.career-job-body{margin-top:12px;font-size:13px;line-height:1.9;color:#334155}
.career-job-body ul{margin:6px 0 0;padding-left:20px}
.career-job-foot{margin-top:12px;padding-top:10px;border-top:1px dashed #e6ecf5}
.career-note{margin-top:14px;padding:12px 14px;border-radius:10px;background:#f7faff;border:1px solid #e3ecfb}
.career-note b{display:block;margin-bottom:4px}
.career-note p{margin:0;white-space:pre-wrap;line-height:1.8}
@media(max-width:720px){.career-job-side{width:100%;justify-content:space-between}}
</style>

<script>
(function () {
    var sel = document.getElementById('jobSelect');
    document.querySelectorAll('.js-apply-job').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (sel) {
                sel.value = btn.getAttribute('data-id');
                sel.dispatchEvent(new Event('change'));
            }
            var box = document.getElementById('apply');
            if (box) { box.scrollIntoView({behavior: 'smooth'}); }
            var nameInput = document.querySelector('#resumeForm input[name="name"]');
            if (nameInput) { nameInput.focus({preventScroll: true}); }
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
