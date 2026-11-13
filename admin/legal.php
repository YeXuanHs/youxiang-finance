<?php
/**
 * 优享财务系统 · 法律文档（服务条款 / 隐私政策）
 *
 * 两个文档各一条记录（slot = terms | privacy），后台维护标题、版本号、生效日期与正文。
 * 前台页面：terms.php、privacy.php；注册页的同意文案会读取这里维护的生效日期。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $slot   = (string)($_POST['slot'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save') {
        if (!YxLegalDoc::isSlot($slot)) {
            flash('未知的文档类型', 'error');
            redirect(url('admin/legal.php'));
        }

        $title    = trim((string)($_POST['title'] ?? ''));
        $version  = trim((string)($_POST['version'] ?? ''));
        $effDate  = trim((string)($_POST['effective_date'] ?? ''));
        $content  = str_replace(["\r\n", "\r"], "\n", (string)($_POST['content'] ?? ''));
        $content  = trim($content);

        $err = '';
        if ($title === '') {
            $err = '请填写文档标题';
        } elseif (mb_strlen($title) > 120) {
            $err = '文档标题不能超过 120 个字符';
        } elseif (mb_strlen($version) > 20) {
            $err = '版本号不能超过 20 个字符';
        } elseif ($effDate !== '' && !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $effDate, $m)) {
            $err = '生效日期格式不正确';
        } elseif ($effDate !== '' && !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $err = '生效日期不是有效日期';
        }

        if ($err !== '') {
            flash($err, 'error');
            redirect(url('admin/legal.php?slot=' . urlencode($slot)));
        }

        $data = [
            'title'          => $title,
            'version'        => $version,
            'effective_date' => $effDate !== '' ? $effDate : null,
            'content'        => $content,
            'updated_at'     => now(),
        ];

        $old = YxLegalDoc::find($slot);
        if ($old) {
            YxDb::update('legal_doc', $data, 'id = :id', ['id' => (int)$old['id']]);
        } else {
            $data['slot']       = $slot;
            $data['created_at'] = now();
            YxDb::insert('legal_doc', $data);
        }

        log_action('legal.save', $slot . ' ' . $title);
        $res = ['ok' => true, 'msg' => '《' . YxLegalDoc::SLOTS[$slot]['name'] . '》已保存'];
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/legal.php?slot=' . urlencode(YxLegalDoc::isSlot($slot) ? $slot : YxLegalDoc::TERMS)));
}

$slot = (string)($_GET['slot'] ?? YxLegalDoc::TERMS);
if (!YxLegalDoc::isSlot($slot)) {
    $slot = YxLegalDoc::TERMS;
}

$slots    = YxLegalDoc::SLOTS;
$meta     = $slots[$slot];
$doc      = YxLegalDoc::get($slot);
$dateText = YxLegalDoc::effectiveText($doc);
$front    = YxLegalDoc::url($slot);
$published = YxLegalDoc::published($slot);

$pageTitle  = '法律文档';
$activeMenu = 'legal';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <?php foreach ($slots as $key => $item): ?>
        <a class="<?= $slot === $key ? 'active' : '' ?>" href="<?= e(url('admin/legal.php?slot=' . urlencode($key))) ?>">
            <?= e($item['name']) ?>
        </a>
    <?php endforeach; ?>
    <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= e($front) ?>" target="_blank">预览前台页面 →</a>
</div>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <h2><?= e($meta['name']) ?></h2>
        <p class="muted small">
            该内容会展示在前台 <a href="<?= e($front) ?>" target="_blank"><?= e($meta['path']) ?></a>，
            注册页「同意条款」中的链接与生效日期也取自这里。
        </p>
        <div class="alert alert-warn" style="margin:0 0 14px">
            首次对外发布前，请结合业务实际与法务意见核对、修改下方正文。
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="slot" value="<?= e($slot) ?>">

            <label>文档标题
                <input type="text" name="title" maxlength="120" value="<?= e($doc['title']) ?>" required>
            </label>

            <div class="grid-2">
                <label>版本号 <span class="muted small">（可留空）</span>
                    <input type="text" name="version" maxlength="20" value="<?= e($doc['version']) ?>" placeholder="如 1.0">
                </label>
                <label>生效日期 <span class="muted small">（可留空）</span>
                    <input type="date" name="effective_date" value="<?= e((string)($doc['effective'] ?? '')) ?>">
                </label>
            </div>

            <label>正文
                <textarea name="content" style="min-height:460px;line-height:1.8"><?= e($doc['content']) ?></textarea>
            </label>
            <p class="muted small">
                排版约定：<b>空行分段</b>；以「# 」开头的行为小标题；连续以「- 」开头的行为列表。
                内容按纯文本展示，不支持 HTML，所有字符都会转义输出。
            </p>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存<?= e($meta['name']) ?></button>
                <a class="btn btn-ghost" href="<?= e($front) ?>" target="_blank">前台查看</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>展示状态</h2>
        <table class="data">
            <tr><th style="width:110px">文档类型</th><td><?= e($meta['name']) ?>（<code><?= e($slot) ?></code>）</td></tr>
            <tr><th>前台地址</th><td class="wrap"><a href="<?= e($front) ?>" target="_blank"><?= e($front) ?></a></td></tr>
            <tr><th>当前标题</th><td><?= e($doc['title']) ?></td></tr>
            <tr><th>版本</th><td><?= $doc['version'] !== '' ? 'v' . e($doc['version']) : '<span class="muted">未设置</span>' ?></td></tr>
            <tr><th>生效日期</th><td><?= $dateText !== '' ? e($dateText) : '<span class="muted">未设置</span>' ?></td></tr>
            <tr><th>最近更新</th><td><?= $doc['updated_at'] !== '' ? e($doc['updated_at']) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>内容状态</th><td>
                <?php if ($published): ?>
                    <span class="badge badge-ok">已发布</span>
                <?php else: ?>
                    <span class="badge badge-warn">正文为空</span>
                <?php endif; ?>
            </td></tr>
        </table>
        <p class="muted small" style="margin-top:12px;line-height:1.8">
            生效日期未填写时，会依次回落到最近更新时间、创建时间，保证前台与注册页始终能显示日期。
            注册页的同意文案取两份文档中较新的生效日期。
        </p>
        <p class="muted small" style="line-height:1.8">
            <?= e($meta['tip']) ?>
        </p>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>