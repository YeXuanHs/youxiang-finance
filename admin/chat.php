<?php
/**
 * 优享财务系统 · 在线客服工作台（后台）
 *
 * 会话列表 / 接单回复 / 结束会话 / AI 与客服设置
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

/* 详情实时消息（JSON，供前台轮询） */
if (isset($_GET['fetch'])) {
    $sid = (int)($_GET['id'] ?? 0);
    $s   = YxChat::byId($sid);
    if (!$s) {
        json_out(['ok' => false, 'msg' => '会话不存在'], 404);
    }
    YxChat::markAdminRead($sid);
    $out = [];
    foreach (YxChat::messages($sid, (int)($_GET['after'] ?? 0)) as $m) {
        $out[] = ['id' => (int)$m['id'], 'role' => $m['role'], 'content' => (string)$m['content'], 'time' => (string)$m['created_at']];
    }
    json_out(['ok' => true, 'status' => $s['status'], 'messages' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $sid    = (int)($_POST['id'] ?? 0);
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $back   = 'sessions';

    if ($action === 'claim') {
        $res = YxChat::claim($sid, (int)$admin['id']);
    } elseif ($action === 'reply') {
        $res = YxChat::agentReply($sid, (int)$admin['id'], (string)($_POST['content'] ?? ''));
    } elseif ($action === 'close') {
        $res = YxChat::close($sid, 'agent');
    } elseif ($action === 'leave_done') {
        $res  = YxChat::markLeaveHandled((int)($_POST['leave_id'] ?? 0));
        $back = 'leaves';
    } elseif ($action === 'save_settings') {
        set_setting('chat_enabled', !empty($_POST['chat_enabled']) ? '1' : '0');
        set_setting('chat_welcome', trim((string)($_POST['chat_welcome'] ?? '')));
        $dept = (string)($_POST['chat_ticket_dept'] ?? 'sales');
        set_setting('chat_ticket_dept', in_array($dept, ['tech', 'billing', 'sales', 'abuse'], true) ? $dept : 'sales');
        set_setting('chat_rate_per_min', (string)max(1, (int)($_POST['chat_rate_per_min'] ?? 10)));

        set_setting('ai_enabled', !empty($_POST['ai_enabled']) ? '1' : '0');
        $prov = (string)($_POST['ai_provider'] ?? 'mock');
        set_setting('ai_provider', array_key_exists($prov, YxAi::drivers()) ? $prov : 'mock');
        set_setting('ai_endpoint', trim((string)($_POST['ai_endpoint'] ?? '')));
        set_setting('ai_model', trim((string)($_POST['ai_model'] ?? '')));
        set_setting('ai_system_prompt', trim((string)($_POST['ai_system_prompt'] ?? '')));
        set_setting('ai_temperature', (string)max(0, min(2, (float)($_POST['ai_temperature'] ?? 0.6))));
        set_setting('ai_max_tokens', (string)max(64, (int)($_POST['ai_max_tokens'] ?? 600)));
        // 密钥留空表示不修改
        $key = (string)($_POST['ai_api_key'] ?? '');
        if ($key !== '') {
            set_setting('ai_api_key', $key);
        }
        $res  = ['ok' => true, 'msg' => '设置已保存'];
        $back = 'settings';
    }

    log_action('chat.admin.' . $action, 'chat#' . $sid, ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/chat.php?tab=' . urlencode($back) . ($sid > 0 && $action !== 'save_settings' ? '&id=' . $sid : '')));
}

$tab  = (string)($_GET['tab'] ?? 'sessions');
$sid  = (int)($_GET['id'] ?? 0);
$filt = (string)($_GET['status'] ?? '');

$sessions = YxChat::adminSessions($filt);
$stats    = YxChat::stats();
$leaves   = $tab === 'leaves' ? YxChat::leaves((string)($_GET['lstatus'] ?? '')) : [];
$detail   = $sid > 0 ? YxChat::byId($sid) : null;
$detailMsgs = [];
if ($detail) {
    YxChat::markAdminRead($sid);
    $detailMsgs = YxChat::messages($sid);
}

$statusCls = ['ai' => 'badge-info', 'waiting' => 'badge-warn', 'serving' => 'badge-ok', 'closed' => 'badge-mute'];

$pageTitle  = '小优助手';
$activeMenu = 'chat';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="<?= $tab === 'sessions' ? 'active' : '' ?>" href="<?= e(url('admin/chat.php?tab=sessions')) ?>">会话工作台</a>
    <a class="<?= $tab === 'leaves' ? 'active' : '' ?>" href="<?= e(url('admin/chat.php?tab=leaves')) ?>">商务合作 / 售后留言<?= $stats['leave_new'] > 0 ? '（' . (int)$stats['leave_new'] . '）' : '' ?></a>
    <a class="<?= $tab === 'settings' ? 'active' : '' ?>" href="<?= e(url('admin/chat.php?tab=settings')) ?>">客服 / AI 设置</a>
    <span style="margin-left:auto" class="muted small">
        待接入 <?= $stats['waiting'] ?> · 服务中 <?= $stats['serving'] ?> · 今日 <?= $stats['today'] ?> · 待处理留言 <?= (int)$stats['leave_new'] ?>
    </span>
</div>

<?php if ($tab === 'settings'): ?>
    <div class="card" style="max-width:800px">
        <h2>客服设置</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <label class="checkbox"><input type="checkbox" name="chat_enabled" value="1" <?= YxChat::enabled() ? 'checked' : '' ?>> 开启在线客服浮窗</label>
            <label>欢迎语 <input type="text" name="chat_welcome" value="<?= e(setting('chat_welcome', '')) ?>" placeholder="留空使用默认欢迎语"></label>
            <div class="grid-3">
                <label>转人工工单部门
                    <select name="chat_ticket_dept">
                        <?php foreach (['sales' => '售前咨询', 'tech' => '技术支持', 'billing' => '财务计费', 'abuse' => '投诉与违规'] as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= YxChat::transferDept() === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>每分钟 AI 回复上限 <input type="number" min="1" name="chat_rate_per_min" value="<?= (int)YxChat::ratePerMin() ?>"></label>
            </div>

            <h2 class="mt-4">AI 解答</h2>
            <label class="checkbox"><input type="checkbox" name="ai_enabled" value="1" <?= YxAi::enabled() ? 'checked' : '' ?>> 开启 AI 自动解答</label>
            <div class="grid-2">
                <label>AI 服务商
                    <select name="ai_provider">
                        <?php foreach (YxAi::drivers() as $k => $name): ?>
                            <option value="<?= e($k) ?>" <?= YxAi::provider() === $k ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>接口地址 <input type="text" name="ai_endpoint" value="<?= e(setting('ai_endpoint', '')) ?>" placeholder="https://api.deepseek.com/v1"></label>
                <label>模型名 <input type="text" name="ai_model" value="<?= e(setting('ai_model', '')) ?>" placeholder="deepseek-chat"></label>
                <label>API Key
                    <input type="password" name="ai_api_key" value="" autocomplete="new-password"
                           placeholder="<?= setting('ai_api_key', '') !== '' ? '（已配置，留空不修改）' : '未配置' ?>">
                </label>
                <label>temperature <input type="number" step="0.1" min="0" max="2" name="ai_temperature" value="<?= e(setting('ai_temperature', '0.6')) ?>"></label>
                <label>max_tokens <input type="number" min="64" name="ai_max_tokens" value="<?= e(setting('ai_max_tokens', '600')) ?>"></label>
            </div>
            <label>系统提示词（留空使用内置默认） <textarea name="ai_system_prompt" rows="3"><?= e(setting('ai_system_prompt', '')) ?></textarea></label>
            <p class="muted small">选择「内置规则问答」无需密钥即可使用；选择「OpenAI 兼容接口」时填写接口地址 / Key / 模型（如 DeepSeek：<code>https://api.deepseek.com/v1</code> + <code>deepseek-chat</code>）。密钥仅保存于站务管理，不在前台回显。</p>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存设置</button>
            </div>
        </form>
    </div>

<?php elseif ($tab === 'leaves'): ?>
    <?php $lstatus = (string)($_GET['lstatus'] ?? ''); ?>
    <div class="card">
        <div class="section-head">
            <h2>商务合作 / 售后咨询留言（<?= count($leaves) ?>）</h2>
            <form method="get" class="inline-form">
                <input type="hidden" name="tab" value="leaves">
                <label>状态
                    <select name="lstatus">
                        <option value="">全部</option>
                        <option value="new" <?= $lstatus === 'new' ? 'selected' : '' ?>>待处理</option>
                        <option value="handled" <?= $lstatus === 'handled' ? 'selected' : '' ?>>已处理</option>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit">筛选</button>
            </form>
        </div>
        <p class="muted small">访客在小优助手提交留言时会自动建工单（商务合作 → 售前咨询，售后咨询 → 技术支持），可在「工单」中直接回复。</p>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>时间</th><th>类型</th><th>联系方式</th><th>留言内容</th><th>会话</th><th>工单</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($leaves as $l): ?>
                <tr>
                    <td class="small muted"><?= e($l['created_at']) ?></td>
                    <td><span class="badge <?= $l['category'] === 'business' ? 'badge-info' : 'badge-warn' ?>"><?= e(YxChat::TOPICS[$l['category']] ?? $l['category']) ?></span></td>
                    <td><?= e($l['contact']) ?></td>
                    <td class="small" style="max-width:320px;white-space:pre-wrap;word-break:break-word"><?= e($l['content']) ?></td>
                    <td class="small muted">#<?= (int)$l['session_id'] ?><?= $l['username'] !== '' ? '<br>' . e($l['username']) : '' ?></td>
                    <td>
                        <?php if ((int)$l['ticket_id'] > 0): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/tickets.php?id=' . (int)$l['ticket_id'])) ?>">#<?= (int)$l['ticket_id'] ?></a>
                        <?php else: ?><span class="muted small">-</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $l['status'] === 'new' ? 'badge-bad' : 'badge-ok' ?>"><?= $l['status'] === 'new' ? '待处理' : '已处理' ?></span>
                    </td>
                    <td>
                        <?php if ($l['status'] === 'new'): ?>
                            <form method="post" onsubmit="return confirm('确认该留言已处理？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="leave_done">
                                <input type="hidden" name="leave_id" value="<?= (int)$l['id'] ?>">
                                <button class="btn btn-sm btn-primary" type="submit">标记已处理</button>
                            </form>
                        <?php else: ?><span class="muted small">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$leaves): ?><tr><td colspan="8" class="empty">暂无留言</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

<?php else: ?>
    <?php if ($detail): ?>
        <?php
        $sess = $detail;
        $sess['username'] = YxDb::value('SELECT username FROM ' . YxDb::table('customer') . ' WHERE id = ?', [(int)$sess['customer_id']]) ?: '';
        ?>
        <div class="card">
            <div class="section-head">
                <h2>会话 #<?= (int)$sess['id'] ?> · <?= e($sess['username'] ?: ($sess['visitor_name'] ?: '游客')) ?></h2>
                <div class="row-actions">
                    <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/chat.php')) ?>">返回列表</a>
                    <?php if ((int)$sess['ticket_id'] > 0): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/tickets.php?id=' . (int)$sess['ticket_id'])) ?>">查看工单 #<?= (int)$sess['ticket_id'] ?></a>
                    <?php endif; ?>
                    <?php if ($sess['status'] !== 'closed'): ?>
                        <form method="post" onsubmit="return confirm('确认结束该会话？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="id" value="<?= (int)$sess['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">结束会话</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <p class="muted small">
                状态：<span class="badge <?= e($statusCls[$sess['status']] ?? 'badge-mute') ?>"><?= e(YxChat::statusName((string)$sess['status'])) ?></span>
                · IP <?= e($sess['visitor_ip']) ?> · 来源 <?= e($sess['source_url'] ?: '-') ?>
                · 创建 <?= e($sess['created_at']) ?>
            </p>

            <div class="thread" id="yxAdminThread">
                <?php foreach ($detailMsgs as $m): ?>
                    <div class="thread-item<?= $m['role'] === 'agent' ? ' is-admin' : '' ?>">
                        <div class="thread-head">
                            <?= e(['visitor' => '客户', 'ai' => 'AI', 'agent' => '客服（我）', 'system' => '系统'][$m['role']] ?? $m['role']) ?>
                            · <?= e($m['created_at']) ?>
                        </div>
                        <div class="thread-body"><?= e($m['content']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$detailMsgs): ?><div class="empty">暂无消息</div><?php endif; ?>
            </div>

            <?php if ($sess['status'] === 'waiting'): ?>
                <form method="post" class="mt-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="claim">
                    <input type="hidden" name="id" value="<?= (int)$sess['id'] ?>">
                    <button class="btn btn-primary" type="submit">接单（开始人工服务）</button>
                </form>
            <?php endif; ?>

            <?php if ($sess['status'] !== 'closed'): ?>
                <form method="post" class="mt-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="id" value="<?= (int)$sess['id'] ?>">
                    <label>回复客户 <textarea name="content" required placeholder="输入回复内容…（直接回复即为人工介入）"></textarea></label>
                    <button class="btn btn-primary" type="submit">发送回复</button>
                </form>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var lastId = <?= $detailMsgs ? (int)end($detailMsgs)['id'] : 0 ?>;
            var box = document.getElementById('yxAdminThread');
            var url = <?= json_encode(url('admin/chat.php?fetch=1&id=' . (int)$sid)) ?>;
            setInterval(function () {
                fetch(url + '&after=' + lastId, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.ok || !d.messages.length) return;
                        d.messages.forEach(function (m) {
                            if (m.id <= lastId) return;
                            lastId = m.id;
                            var who = m.role === 'agent' ? '客服（我）' : (m.role === 'visitor' ? '客户' : (m.role === 'system' ? '系统' : 'AI'));
                            var item = document.createElement('div');
                            item.className = 'thread-item' + (m.role === 'agent' ? ' is-admin' : '');
                            item.innerHTML = '<div class="thread-head"></div><div class="thread-body"></div>';
                            item.querySelector('.thread-head').textContent = who + ' · ' + m.time;
                            item.querySelector('.thread-body').textContent = m.content;
                            box.appendChild(item);
                            box.scrollIntoView(false);
                        });
                    }).catch(function () {});
            }, 4000);
        })();
        </script>

    <?php else: ?>
        <div class="card">
            <div class="section-head">
                <h2>会话列表（<?= count($sessions) ?>）</h2>
                <form method="get" class="inline-form">
                    <input type="hidden" name="tab" value="sessions">
                    <label>状态
                        <select name="status">
                            <option value="">全部</option>
                            <?php foreach (YxChat::STATUS as $k => $v): ?>
                                <option value="<?= e($k) ?>" <?= $filt === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-primary" type="submit">筛选</button>
                </form>
            </div>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>会话</th><th>状态</th><th>消息</th><th>未读</th><th>最近活动</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td>
                            #<?= (int)$s['id'] ?> · <?= e($s['username'] ?: ($s['visitor_name'] ?: '游客')) ?>
                            <br><span class="muted small"><?= e($s['visitor_ip']) ?></span>
                        </td>
                        <td><span class="badge <?= e($statusCls[$s['status']] ?? 'badge-mute') ?>"><?= e(YxChat::statusName((string)$s['status'])) ?></span></td>
                        <td><?= (int)$s['message_count'] ?></td>
                        <td><?= (int)$s['unread_admin'] > 0 ? '<span class="badge badge-bad">' . (int)$s['unread_admin'] . '</span>' : '<span class="muted small">0</span>' ?></td>
                        <td class="small muted"><?= e($s['updated_at']) ?></td>
                        <td><a class="btn btn-sm btn-primary" href="<?= e(url('admin/chat.php?id=' . (int)$s['id'])) ?>">进入</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sessions): ?><tr><td colspan="6" class="empty">暂无会话</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
