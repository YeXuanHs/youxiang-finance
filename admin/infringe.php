<?php
/**
 * 优享财务系统 · 版权侵权处理（后台）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxInfringe::seedDefaults();

$tab = (string)($_GET['tab'] ?? 'reports');

$extTest = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'ext_test') {
    csrf_check();
    $extTest = YxInfringe::extDetect(trim((string)($_POST['text'] ?? '测试文本')));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') !== 'ext_test') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $back   = 'reports';

    if ($action === 'report_handle') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['accepted', 'rejected', 'takedown', 'closed'], true) ? $_POST['status'] : 'accepted';
        $note   = trim((string)($_POST['note'] ?? ''));
        $rep    = YxDb::one('SELECT * FROM ' . YxDb::table('infringe_report') . ' WHERE id = ?', [$id]);
        if (!$rep) {
            $res = ['ok' => false, 'msg' => '举报单不存在'];
        } else {
            if ($status === 'takedown' && (int)$rep['target_app_id'] > 0) {
                YxDb::update('app', ['status' => 'off', 'updated_at' => now()], 'id = :id', ['id' => (int)$rep['target_app_id']]);
            }
            YxDb::update('infringe_report', [
                'status' => $status, 'handle_note' => $note,
                'handled_by' => (int)$admin['id'], 'handled_at' => now(), 'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);
            $res  = ['ok' => true, 'msg' => '已处理并记录'];
            $back = 'reports';
        }
    } elseif ($action === 'rule_save') {
        $id      = (int)($_POST['id'] ?? 0);
        $type    = in_array($_POST['type'] ?? '', ['keyword', 'blacklist', 'regex'], true) ? $_POST['type'] : 'keyword';
        $pattern = trim((string)($_POST['pattern'] ?? ''));
        if ($pattern === '') {
            $res = ['ok' => false, 'msg' => '规则内容不能为空'];
        } else {
            $data = [
                'type' => $type, 'name' => trim((string)($_POST['name'] ?? '')),
                'pattern' => $pattern, 'weight' => max(1, (int)($_POST['weight'] ?? 3)),
                'risk' => in_array($_POST['risk'] ?? '', ['low', 'mid', 'high'], true) ? $_POST['risk'] : 'mid',
                'status' => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
                'remark' => trim((string)($_POST['remark'] ?? '')),
            ];
            if ($id > 0) {
                YxDb::update('infringe_rule', $data, 'id = :id', ['id' => $id]);
            } else {
                $data['created_at'] = now();
                YxDb::insert('infringe_rule', $data);
            }
            $res  = ['ok' => true, 'msg' => '规则已保存'];
            $back = 'rules';
        }
    } elseif ($action === 'rule_delete') {
        YxDb::delete('infringe_rule', 'id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        $res  = ['ok' => true, 'msg' => '规则已删除'];
        $back = 'rules';
    } elseif ($action === 'scan_run') {
        $sus  = YxInfringe::scanApps();
        $tot  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . " WHERE author_type = 'thirdparty'");
        YxDb::insert('infringe_scan', [
            'scope' => 'thirdparty', 'total' => $tot, 'hits' => count($sus),
            'result' => json_encode($sus, JSON_UNESCAPED_UNICODE), 'operator' => (string)$admin['username'], 'created_at' => now(),
        ]);
        $res  = ['ok' => true, 'msg' => '扫描完成：命中 ' . count($sus) . ' 个疑似侵权/风险条目'];
        $back = 'scan';
    } elseif ($action === 'app_off') {
        YxDb::update('app', ['status' => 'off', 'updated_at' => now()], 'id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        $res  = ['ok' => true, 'msg' => '已下架'];
        $back = 'scan';
    } elseif ($action === 'legal_review') {
        // 法务复核：纯人工动作，只登记结论，不触发任何外发
        $res  = YxInfringeLegal::review(
            (string)($_POST['sn'] ?? ''),
            (string)($_POST['decision'] ?? ''),
            (string)($_POST['reviewer'] ?? ''),
            (string)($_POST['note'] ?? '')
        );
        $back = 'reports';
    } elseif ($action === 'legal_draft') {
        // 生成律师函草稿：须先通过法务复核，且只返回文本，不发送
        $draft = YxInfringeLegal::legalLetterDraft((string)($_POST['sn'] ?? ''));
        if (!empty($draft['ok'])) {
            // 草稿只进本次会话，供页面只读展示；不落盘、不外发
            $_SESSION['_letter_draft'] = ['sn' => (string)($_POST['sn'] ?? ''), 'text' => (string)$draft['draft']];
            $res = ['ok' => true, 'msg' => '草稿已生成（未发送），请在下方查看'];
        } else {
            $res = ['ok' => false, 'msg' => (string)$draft['message']];
        }
        $back = 'reports';
    } elseif ($action === 'save_settings') {
        set_setting('infringe_ext_enabled', !empty($_POST['infringe_ext_enabled']) ? '1' : '0');
        set_setting('infringe_ext_method', in_array($_POST['infringe_ext_method'] ?? '', ['POST_JSON', 'POST_FORM', 'GET'], true) ? $_POST['infringe_ext_method'] : 'POST_JSON');
        set_setting('infringe_ext_endpoint', trim((string)($_POST['infringe_ext_endpoint'] ?? '')));
        set_setting('infringe_ext_path', trim((string)($_POST['infringe_ext_path'] ?? '')));
        set_setting('infringe_ext_appid', trim((string)($_POST['infringe_ext_appid'] ?? '')));
        set_setting('infringe_ext_appid_field', trim((string)($_POST['infringe_ext_appid_field'] ?? 'appid')));
        set_setting('infringe_ext_key_field', trim((string)($_POST['infringe_ext_key_field'] ?? 'key')));
        set_setting('infringe_ext_text_field', trim((string)($_POST['infringe_ext_text_field'] ?? 'text')));
        set_setting('infringe_ext_sign', in_array($_POST['infringe_ext_sign'] ?? '', ['none', 'append_secret', 'hmac_sha256', 'hmac_md5', 'md5'], true) ? $_POST['infringe_ext_sign'] : 'none');
        set_setting('infringe_ext_sign_field', trim((string)($_POST['infringe_ext_sign_field'] ?? 'sign')));
        set_setting('infringe_ext_ok_path', trim((string)($_POST['infringe_ext_ok_path'] ?? 'code')));
        set_setting('infringe_ext_ok_value', trim((string)($_POST['infringe_ext_ok_value'] ?? '0')));
        set_setting('infringe_ext_msg_path', trim((string)($_POST['infringe_ext_msg_path'] ?? 'msg')));
        set_setting('infringe_ext_risk_path', trim((string)($_POST['infringe_ext_risk_path'] ?? 'data.risk')));
        set_setting('infringe_ext_score_path', trim((string)($_POST['infringe_ext_score_path'] ?? 'data.score')));
        set_setting('infringe_ext_hits_path', trim((string)($_POST['infringe_ext_hits_path'] ?? 'data.hits')));
        set_setting('infringe_ext_timeout', (string)max(3, (int)($_POST['infringe_ext_timeout'] ?? 15)));
        $k = (string)($_POST['infringe_ext_key'] ?? '');
        if ($k !== '') {
            set_setting('infringe_ext_key', $k);
        }
        $res  = ['ok' => true, 'msg' => '设置已保存'];
        $back = 'settings';
    }

    log_action('infringe.admin.' . $action, '', ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/infringe.php?tab=' . urlencode($back)));
}

$reports  = YxDb::all('SELECT * FROM ' . YxDb::table('infringe_report') . ' ORDER BY id DESC LIMIT 200');
$rules    = YxInfringe::rules(false);
$editRule = (int)($_GET['rid'] ?? 0);
$editing  = $editRule > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('infringe_rule') . ' WHERE id = ?', [$editRule]) : null;
$lastScan = YxDb::one('SELECT * FROM ' . YxDb::table('infringe_scan') . ' ORDER BY id DESC LIMIT 1');
$scanRes  = $lastScan && $lastScan['result'] ? (json_decode((string)$lastScan['result'], true) ?: []) : [];
$pending  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('infringe_report') . " WHERE status = 'pending'");

$riskCls = ['high' => 'badge-bad', 'mid' => 'badge-warn', 'low' => 'badge-info', 'none' => 'badge-ok'];

$pageTitle  = '版权侵权处理';
$activeMenu = 'infringe';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="<?= $tab === 'reports' ? 'active' : '' ?>" href="<?= e(url('admin/infringe.php?tab=reports')) ?>">举报处理（<?= $pending ?> 待处理）</a>
    <a class="<?= $tab === 'rules' ? 'active' : '' ?>" href="<?= e(url('admin/infringe.php?tab=rules')) ?>">检测规则库（<?= count($rules) ?>）</a>
    <a class="<?= $tab === 'scan' ? 'active' : '' ?>" href="<?= e(url('admin/infringe.php?tab=scan')) ?>">批量扫描</a>
    <a class="<?= $tab === 'settings' ? 'active' : '' ?>" href="<?= e(url('admin/infringe.php?tab=settings')) ?>">设置</a>
</div>

<?php if ($tab === 'rules'): ?>
    <div class="grid-2">
        <div class="card">
            <h2><?= $editing ? '编辑规则' : '新增规则' ?></h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rule_save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="grid-2">
                    <label>类型
                        <select name="type">
                            <?php foreach (['keyword' => '关键词', 'blacklist' => '黑名单', 'regex' => '正则'] as $k => $v): ?>
                                <option value="<?= e($k) ?>" <?= ($editing['type'] ?? 'keyword') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>名称 <input type="text" name="name" value="<?= e($editing['name'] ?? '') ?>"></label>
                    <label>权重 <input type="number" min="1" name="weight" value="<?= e($editing['weight'] ?? 3) ?>"></label>
                    <label>风险
                        <select name="risk">
                            <?php foreach (['low' => '低', 'mid' => '中', 'high' => '高'] as $k => $v): ?>
                                <option value="<?= e($k) ?>" <?= ($editing['risk'] ?? 'mid') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>状态
                        <select name="status">
                            <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                            <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                        </select>
                    </label>
                </div>
                <label>规则内容（关键词/黑名单文本，或正则） <input type="text" name="pattern" required value="<?= e($editing['pattern'] ?? '') ?>"></label>
                <label>备注 <input type="text" name="remark" value="<?= e($editing['remark'] ?? '') ?>"></label>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '新增规则' ?></button>
                    <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/infringe.php?tab=rules')) ?>">取消</a><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="card">
            <h2>规则列表</h2>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>类型</th><th>名称</th><th>内容</th><th>权重</th><th>风险</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td><?= e($r['type']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td class="mono small wrap"><?= e($r['pattern']) ?></td>
                        <td><?= (int)$r['weight'] ?></td>
                        <td><span class="badge <?= e($riskCls[$r['risk']] ?? 'badge-mute') ?>"><?= e(YxInfringe::riskName((string)$r['risk'])) ?></span></td>
                        <td><?= (int)$r['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/infringe.php?tab=rules&rid=' . (int)$r['id'])) ?>">编辑</a>
                                <form method="post" onsubmit="return confirm('确认删除该规则？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rule_delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-bad" type="submit">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rules): ?><tr><td colspan="7" class="empty">暂无规则</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>

<?php elseif ($tab === 'scan'): ?>
    <div class="card">
        <div class="section-head">
            <h2>批量扫描（商店第三方资源）</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="scan_run">
                <button class="btn btn-primary" type="submit">立即扫描</button>
            </form>
        </div>
        <?php if ($lastScan): ?>
            <p class="muted small">上次扫描：<?= e($lastScan['created_at']) ?> · 共 <?= (int)$lastScan['total'] ?> 个 · 命中 <?= (int)$lastScan['hits'] ?> 个 · 操作人 <?= e($lastScan['operator']) ?></p>
        <?php endif; ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>应用</th><th>标识</th><th>作者</th><th>风险</th><th>评分</th><th>命中</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($scanRes as $s): ?>
                <tr>
                    <td class="wrap"><?= e($s['name']) ?></td>
                    <td class="mono small"><?= e($s['key']) ?></td>
                    <td><?= e($s['author']) ?></td>
                    <td><span class="badge <?= e($riskCls[$s['risk']] ?? 'badge-mute') ?>"><?= e(YxInfringe::riskName((string)$s['risk'])) ?></span></td>
                    <td><?= (int)$s['score'] ?></td>
                    <td class="small"><?= e($s['hit_names'] ?? '') ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('确认下架该应用？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="app_off">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">下架</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$scanRes): ?><tr><td colspan="7" class="empty">暂无扫描结果，点「立即扫描」</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($tab === 'settings'): ?>
    <div class="card" style="max-width:880px">
        <h2>外部版权检测 API（可选，全可配）</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <label class="checkbox"><input type="checkbox" name="infringe_ext_enabled" value="1" <?= YxInfringe::extEnabled() ? 'checked' : '' ?>> 启用外部检测（与本地规则库叠加评分）</label>
            <div class="grid-3">
                <label>请求方式
                    <select name="infringe_ext_method">
                        <?php foreach (['POST_JSON', 'POST_FORM', 'GET'] as $m): ?><option <?= setting('infringe_ext_method', 'POST_JSON') === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>接口地址 <input type="text" name="infringe_ext_endpoint" value="<?= e(setting('infringe_ext_endpoint', '')) ?>" placeholder="https://api.example.com"></label>
                <label>接口路径 <input type="text" name="infringe_ext_path" value="<?= e(setting('infringe_ext_path', '/detect')) ?>"></label>
                <label>AppID <input type="text" name="infringe_ext_appid" value="<?= e(setting('infringe_ext_appid', '')) ?>"></label>
                <label>AppKey
                    <input type="password" name="infringe_ext_key" autocomplete="new-password"
                           placeholder="<?= setting('infringe_ext_key', '') !== '' ? '（已配置，留空不修改）' : '未配置' ?>">
                </label>
                <label>超时（秒） <input type="number" min="3" name="infringe_ext_timeout" value="<?= e(setting('infringe_ext_timeout', '15')) ?>"></label>
            </div>

            <h2 class="mt-4">字段名与签名</h2>
            <div class="grid-3">
                <label>AppID 字段 <input type="text" name="infringe_ext_appid_field" value="<?= e(setting('infringe_ext_appid_field', 'appid')) ?>"></label>
                <label>Key 字段 <input type="text" name="infringe_ext_key_field" value="<?= e(setting('infringe_ext_key_field', 'key')) ?>"></label>
                <label>文本字段 <input type="text" name="infringe_ext_text_field" value="<?= e(setting('infringe_ext_text_field', 'text')) ?>"></label>
                <label>签名算法
                    <select name="infringe_ext_sign">
                        <?php foreach (['none' => '不签名', 'append_secret' => 'md5(串+密钥)', 'hmac_sha256' => 'HMAC-SHA256', 'hmac_md5' => 'HMAC-MD5', 'md5' => 'md5(串)'] as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= setting('infringe_ext_sign', 'none') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>签名字段 <input type="text" name="infringe_ext_sign_field" value="<?= e(setting('infringe_ext_sign_field', 'sign')) ?>"></label>
            </div>

            <h2 class="mt-4">响应解析（点号路径，如 data.risk）</h2>
            <div class="grid-3">
                <label>成功码路径 <input type="text" name="infringe_ext_ok_path" value="<?= e(setting('infringe_ext_ok_path', 'code')) ?>" placeholder="留空跳过"></label>
                <label>成功值 <input type="text" name="infringe_ext_ok_value" value="<?= e(setting('infringe_ext_ok_value', '0')) ?>"></label>
                <label>消息路径 <input type="text" name="infringe_ext_msg_path" value="<?= e(setting('infringe_ext_msg_path', 'msg')) ?>"></label>
                <label>风险路径 <input type="text" name="infringe_ext_risk_path" value="<?= e(setting('infringe_ext_risk_path', 'data.risk')) ?>"></label>
                <label>评分路径 <input type="text" name="infringe_ext_score_path" value="<?= e(setting('infringe_ext_score_path', 'data.score')) ?>"></label>
                <label>命中列表路径 <input type="text" name="infringe_ext_hits_path" value="<?= e(setting('infringe_ext_hits_path', 'data.hits')) ?>"></label>
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">保存设置</button></div>
        </form>
    </div>

    <div class="card" style="max-width:880px">
        <h2>联调测试</h2>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ext_test">
            <label class="grow">测试文本 <input type="text" name="text" value="破解版插件 nulled"></label>
            <button class="btn btn-primary" type="submit">调用外部检测</button>
        </form>
        <?php if ($extTest !== null): ?>
            <p class="mt-3">结果：
                <?= !empty($extTest['ok'])
                    ? '<span class="badge badge-ok">成功</span> 风险 ' . e((string)($extTest['risk'] ?? '')) . ' · 评分 ' . (int)($extTest['score'] ?? 0)
                    : '<span class="badge badge-bad">失败</span> ' . e((string)($extTest['msg'] ?? '')) ?>
            </p>
            <?php if (!empty($extTest['raw'])): ?>
                <pre class="mono small" style="white-space:pre-wrap;max-height:280px;overflow:auto;background:#f6f8fc;padding:10px;border-radius:8px"><?= e(is_string($extTest['raw']) ? $extTest['raw'] : json_encode($extTest['raw'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php $letterDraft = $_SESSION['_letter_draft'] ?? null; unset($_SESSION['_letter_draft']); ?>
    <?php if ($letterDraft): ?>
    <div class="card">
        <h2>律师函草稿 · <?= e((string)$letterDraft['sn']) ?>（草稿 · 未签发 · 未发送）</h2>
        <pre class="mono small" style="white-space:pre-wrap;background:#f6f8fc;padding:10px;border-radius:8px"><?= e((string)$letterDraft['text']) ?></pre>
        <p class="muted small">本页只作草稿展示，系统未向任何对象发送该文本；签发须由法务人工完成。</p>
    </div>
    <?php endif; ?>
    <div class="card">
        <h2>侵权举报（<?= count($reports) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>编号</th><th>作品/权利人</th><th>对象</th><th>检测</th><th>状态</th><th>时间</th><th>处理</th></tr></thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
                <tr>
                    <td class="mono small"><?= e($r['sn']) ?></td>
                    <td class="wrap"><?= e($r['work_title']) ?> <?= YxDispute::badge($r) ?><br><span class="muted small"><?= e($r['work_owner'] ?: '-') ?></span></td>
                    <td class="small">
                        <?= e(['app' => '应用', 'url' => '链接', 'text' => '文本'][$r['target_type']] ?? $r['target_type']) ?>
                        <?php if ((int)$r['target_app_id'] > 0): ?> #<?= (int)$r['target_app_id'] ?><?php endif; ?>
                        <?php if ($r['target_url']): ?><br><span class="mono"><?= e($r['target_url']) ?></span><?php endif; ?>
                    </td>
                    <td><span class="badge <?= e($riskCls[$r['risk']] ?? 'badge-mute') ?>"><?= e(YxInfringe::riskName((string)$r['risk'])) ?></span> <?= (int)$r['score'] ?></td>
                    <td><span class="badge <?= $r['status'] === 'takedown' ? 'badge-bad' : ($r['status'] === 'closed' ? 'badge-mute' : 'badge-warn') ?>"><?= e(YxInfringe::statusName((string)$r['status'])) ?></span>
                        <?php if (($r['kind'] ?? 'report') === 'infringe'): ?>
                            <br><span class="badge <?= ($r['review_status'] ?? '') === 'approved' ? 'badge-ok' : (($r['review_status'] ?? '') === 'rejected' ? 'badge-mute' : 'badge-info') ?>"><?= e(YxInfringeLegal::reviewName((string)($r['review_status'] ?? 'none'))) ?></span>
                            <?php if (!empty($r['demand'])): ?><br><span class="muted small"><?= e(YxInfringeLegal::demandName((string)$r['demand'])) ?></span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="small muted"><?= e($r['created_at']) ?></td>
                    <td>
                        <?= YxDispute::adminPanel($r) ?>
                        <form method="post" class="row-actions">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="report_handle">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <select name="status">
                                <?php foreach (['accepted' => '受理', 'takedown' => '下架', 'rejected' => '驳回', 'closed' => '归档'] as $k => $v): ?>
                                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="note" placeholder="处理说明" style="width:130px">
                            <button class="btn btn-sm btn-primary" type="submit">提交</button>
                        </form>
                        <?php if (($r['kind'] ?? 'report') === 'infringe'): ?>
                        <form method="post" class="row-actions" style="margin-top:6px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="legal_review">
                            <input type="hidden" name="sn" value="<?= e($r['sn']) ?>">
                            <select name="decision">
                                <option value="approved">法务复核通过</option>
                                <option value="rejected">驳回 / 撤销误判</option>
                            </select>
                            <input type="text" name="reviewer" placeholder="法务复核人（必填）" style="width:130px" required>
                            <input type="text" name="note" placeholder="复核意见" style="width:130px">
                            <button class="btn btn-sm btn-primary" type="submit">登记复核</button>
                        </form>
                        <?php if (($r['review_status'] ?? '') === 'approved'): ?>
                        <form method="post" style="margin-top:6px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="legal_draft">
                            <input type="hidden" name="sn" value="<?= e($r['sn']) ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">生成律师函草稿（不发送）</button>
                        </form>
                        <?php endif; ?>
                        <p class="muted small" style="margin:6px 0 0">系统不自动外发任何函件；签发须由法务人工完成</p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reports): ?><tr><td colspan="7" class="empty">暂无举报</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
