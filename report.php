<?php
/**
 * 优享财务系统 · 工单举报（前台）
 *
 * 游客可直接提交举报并按编号查询进度；已登录客户 / 分站可查看自己提交的举报并补充说明。
 */
require_once __DIR__ . '/includes/bootstrap.php';
yx_require_installed();

$customer = YxAuth::customer();
$subsite  = YxAuth::subsite();
$notice   = '';
$noticeType = 'error';

$myCustomerId = $customer ? (int)$customer['id'] : 0;
$mySubsiteId  = (!$customer && $subsite) ? (int)$subsite['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'create') {
        $res = YxTicketReport::create([
            'customer_id' => $myCustomerId,
            'subsite_id'  => $mySubsiteId,
            'category'    => (string)($_POST['category'] ?? 'other'),
            'subject'     => (string)($_POST['subject'] ?? ''),
            'content'     => (string)($_POST['content'] ?? ''),
            'contact'     => (string)($_POST['contact'] ?? ''),
            'evidence'    => trim((string)($_POST['evidence'] ?? '')) ?: null,
            'priority'    => (string)($_POST['priority'] ?? 'normal'),
        ]);
        if (!empty($res['ok'])) {
            flash('举报已提交，编号 ' . $res['sn'] . '，请保存编号以便查询进度');
            redirect(url('report.php?sn=' . urlencode((string)$res['sn'])));
        }
        $notice = (string)($res['msg'] ?? '提交失败');
    } elseif ($do === 'reply') {
        $sn     = trim((string)($_POST['sn'] ?? ''));
        $row    = YxTicketReport::bySn($sn);
        $owner  = $row && (
            ($myCustomerId > 0 && (int)$row['customer_id'] === $myCustomerId)
            || ($mySubsiteId > 0 && (int)$row['subsite_id'] === $mySubsiteId)
        );
        if (!$owner) {
            $notice = '举报单不存在或无权回复';
        } else {
            $res = YxTicketReport::addReply(
                (int)$row['id'],
                $myCustomerId > 0 ? 'customer' : 'subsite',
                $myCustomerId > 0 ? $myCustomerId : $mySubsiteId,
                (string)($_POST['content'] ?? '')
            );
            if (!empty($res['ok'])) {
                flash('补充说明已提交');
                redirect(url('report.php?sn=' . urlencode($sn)));
            }
            $notice = (string)($res['msg'] ?? '回复失败');
        }
    }
}

$flash = flash();
if ($flash) {
    $notice     = (string)$flash['msg'];
    $noticeType = $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warn' ? 'warn' : 'ok');
}

$sn     = trim((string)($_GET['sn'] ?? ''));
$detail = null;
if ($sn !== '') {
    $found  = YxTicketReport::bySn($sn);
    $detail = $found ? YxTicketReport::detail((int)$found['id']) : null;
}

$canReply = false;
if ($detail) {
    $canReply = ($myCustomerId > 0 && (int)$detail['customer_id'] === $myCustomerId)
        || ($mySubsiteId > 0 && (int)$detail['subsite_id'] === $mySubsiteId);
}

if ($myCustomerId > 0) {
    $myReports = YxTicketReport::myReports($myCustomerId, 0, 50);
} elseif ($mySubsiteId > 0) {
    $myReports = YxTicketReport::myReports(0, $mySubsiteId, 50);
} else {
    $myReports = [];
}

$defaultContact = '';
if ($customer) {
    $defaultContact = (string)($customer['email'] ?: $customer['username']);
} elseif ($subsite) {
    $defaultContact = (string)$subsite['username'];
}

$pageTitle = '工单举报';
require __DIR__ . '/includes/header.php';
?>
<section class="section">
    <h1>工单举报</h1>
    <p class="muted">用于投诉违规、欺诈、滥用等行为，也可对服务问题提出申诉。提交后会生成举报编号，凭编号可随时查询处理进度。</p>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-<?= e($noticeType === 'error' ? 'error' : ($noticeType === 'warn' ? 'warn' : 'ok')) ?>"><?= e($notice) ?></div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <h2>提交举报</h2>
            <?= YxDispute::noticeHtml() ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="create">
                <div class="grid-2">
                    <label>举报类型
                        <select name="category">
                            <?php foreach (YxTicketReport::CATEGORIES as $k => $v): ?>
                                <option value="<?= e($k) ?>" <?= $k === 'abuse' ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>紧急程度
                        <select name="priority">
                            <?php foreach (YxTicketReport::PRIORITIES as $k => $v): ?>
                                <option value="<?= e($k) ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label>举报标题 <input type="text" name="subject" required maxlength="200" placeholder="一句话概括被举报的问题"></label>
                <label>举报说明 <textarea name="content" rows="5" required placeholder="请描述发生时间、涉及对象、具体事实与影响"></textarea></label>
                <div class="grid-2">
                    <label>联系方式 <input type="text" name="contact" maxlength="120" value="<?= e($defaultContact) ?>" placeholder="邮箱 / 手机 / QQ"></label>
                    <label>证据链接 <input type="text" name="evidence" maxlength="255" placeholder="截图或材料地址（可选）"></label>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">提交举报</button></div>
                <p class="muted small mt-2">请如实填写，恶意举报可能被驳回并记录。</p>
            </form>
        </div>

        <div class="card">
            <h2>查询处理进度</h2>
            <form method="get" class="inline-form">
                <label class="grow">举报编号 <input type="text" name="sn" value="<?= e($sn) ?>" placeholder="RP..."></label>
                <button class="btn btn-primary" type="submit">查询</button>
            </form>

            <?php if ($sn !== '' && !$detail): ?>
                <div class="alert alert-warn mt-3">未查到编号 <?= e($sn) ?> 的举报单，请核对后重试。</div>
            <?php elseif ($detail): ?>
                <table class="data mt-3">
                    <tr>
                        <th>编号</th><td class="mono"><?= e($detail['sn']) ?></td>
                        <th>状态</th><td><span class="badge <?= e(YxTicketReport::statusBadge((string)$detail['status'])) ?>"><?= e(YxTicketReport::statusName((string)$detail['status'])) ?></span></td>
                    </tr>
                    <tr>
                        <th>标题</th><td colspan="3"><?= e($detail['subject']) ?></td>
                    </tr>
                    <tr>
                        <th>类型</th><td><?= e(YxTicketReport::categoryName((string)$detail['category'])) ?></td>
                        <th>优先级</th><td><span class="badge <?= e(YxTicketReport::priorityBadge((string)$detail['priority'])) ?>"><?= e(YxTicketReport::priorityName((string)$detail['priority'])) ?></span></td>
                    </tr>
                    <tr>
                        <th>提交时间</th><td><?= e($detail['created_at']) ?></td>
                        <th>处理时间</th><td><?= e($detail['handled_at'] ?: '-') ?></td>
                    </tr>
                    <?php if (!empty($detail['manual_only'])): ?>
                        <tr><th>受理方式</th><td colspan="3"><?= YxDispute::badge($detail) ?> <span class="small muted">该诉求含报案 / 涉警内容，由客服人工核对后导办，不按自助流程自动答复。</span></td></tr>
                    <?php endif; ?>
                    <tr><th>举报说明</th><td colspan="3" style="white-space:pre-wrap"><?= e($detail['content']) ?></td></tr>
                    <?php if ($detail['evidence']): ?>
                        <tr><th>证据</th><td colspan="3" class="mono small"><?= e($detail['evidence']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($detail['handle_note']): ?>
                        <tr><th>处理结论</th><td colspan="3"><?= e($detail['handle_note']) ?></td></tr>
                    <?php endif; ?>
                </table>

                <h2 class="mt-4">沟通记录</h2>
                <?php if (!$detail['replies']): ?>
                    <div class="empty">暂无回复</div>
                <?php else: ?>
                    <?php foreach ($detail['replies'] as $r): $isAdmin = $r['author_type'] === 'admin'; ?>
                        <div style="border-left:3px solid <?= $isAdmin ? '#1c7ed6' : '#c9d3e3' ?>;padding:0 0 10px 12px;margin-bottom:14px">
                            <div class="small muted"><strong><?= $isAdmin ? '平台客服' : '我' ?></strong> · <?= e($r['created_at']) ?></div>
                            <div style="white-space:pre-wrap"><?= e($r['content']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($canReply && YxTicketReport::isOpen((string)$detail['status'])): ?>
                    <form method="post" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="reply">
                        <input type="hidden" name="sn" value="<?= e($detail['sn']) ?>">
                        <label>补充说明 <textarea name="content" rows="3" required placeholder="补充证据或追问处理进度"></textarea></label>
                        <div class="form-actions"><button class="btn btn-primary" type="submit">提交补充</button></div>
                    </form>
                <?php elseif ($canReply): ?>
                    <p class="muted small mt-3">该举报单已结束，如需继续反馈请重新提交。</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($myReports): ?>
    <div class="card">
        <h2>我提交的举报（<?= count($myReports) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>编号</th><th>标题</th><th>类型</th><th>优先级</th><th>状态</th><th>提交时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($myReports as $r): ?>
                <tr>
                    <td class="mono small"><?= e($r['sn']) ?></td>
                    <td class="wrap"><?= e($r['subject']) ?></td>
                    <td class="small"><?= e(YxTicketReport::categoryName((string)$r['category'])) ?></td>
                    <td><span class="badge <?= e(YxTicketReport::priorityBadge((string)$r['priority'])) ?>"><?= e(YxTicketReport::priorityName((string)$r['priority'])) ?></span></td>
                    <td><span class="badge <?= e(YxTicketReport::statusBadge((string)$r['status'])) ?>"><?= e(YxTicketReport::statusName((string)$r['status'])) ?></span></td>
                    <td class="small muted"><?= e($r['created_at']) ?></td>
                    <td><a class="btn btn-sm btn-ghost" href="<?= e(url('report.php?sn=' . urlencode((string)$r['sn']))) ?>">查看</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php elseif (!$customer && !$subsite): ?>
    <p class="muted small mt-3">
        已登录客户或分站可在此直接查看历史举报；
        <a href="<?= e(url('user/login.php')) ?>">登录用户中心</a>
        <?php if (YxEdition::can('subsite')): ?> · <a href="<?= e(url('subsite/login.php')) ?>">分站登录</a><?php endif; ?>
    </p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
