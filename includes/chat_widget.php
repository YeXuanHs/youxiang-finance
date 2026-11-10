<?php
/**
 * 优享财务系统 · 小优助手浮窗（轻触小兔咨询 / 再触收回弹窗）
 * 由门户/用户中心页脚引入；游客与登录客户均可用。
 * AI 自动值守解答，答不上来自动转人工；支持商务合作 / 售后咨询留言。
 */
if (!function_exists('yx_installed') || !yx_installed() || !YxChat::enabled()) {
    return;
}
$yxChatApi = url('api/chat.php');
$yxChatCss = url('assets/css/chat.css') . '?v=' . (int)@filemtime(__DIR__ . '/../assets/css/chat.css');
$yxChatCsrf = csrf_token();
$yxChatMe = YxAuth::customer();
?>
<link rel="stylesheet" href="<?= e($yxChatCss) ?>">
<div id="yx-chat" class="yx-chat" aria-live="polite">
    <button type="button" class="yx-chat-fab" id="yxChatFab" title="轻触咨询 · 小优助手" aria-expanded="false" aria-controls="yxChatPanel">
        <span class="yx-chat-fab-ico" aria-hidden="true">🐰</span>
        <span class="yx-chat-fab-tx" id="yxChatFabTx">轻触咨询</span>
    </button>

    <section class="yx-chat-panel" id="yxChatPanel" hidden>
        <header class="yx-chat-head">
            <div class="yx-chat-title">
                <strong>小优助手</strong>
                <span class="yx-chat-status" id="yxChatStatus">AI 值守中</span>
            </div>
            <div class="yx-chat-head-btns">
                <button type="button" class="yx-chat-mini" id="yxChatTransfer">转人工</button>
                <button type="button" class="yx-chat-mini" id="yxChatClose" title="结束会话">结束</button>
                <button type="button" class="yx-chat-mini" id="yxChatMin" title="收起（轻触小兔也可收回）">—</button>
            </div>
        </header>

        <div class="yx-chat-quick" id="yxChatQuick">
            <button type="button" data-topic="business">🤝 商务合作</button>
            <button type="button" data-topic="aftersale">🛠 售后咨询</button>
        </div>

        <div class="yx-chat-body" id="yxChatBody"></div>

        <form class="yx-chat-leave" id="yxChatLeave" hidden>
            <div class="yx-chat-leave-head">
                <b id="yxChatLeaveTitle">商务合作留言</b>
                <button type="button" id="yxChatLeaveCancel">取消</button>
            </div>
            <input type="text" id="yxChatContact" maxlength="120" autocomplete="off"
                   placeholder="联系方式：手机 / 微信 / 邮箱">
            <textarea id="yxChatLeaveText" maxlength="500" rows="3"
                      placeholder="请简要描述需求，客服会尽快回复…"></textarea>
            <p class="yx-chat-leave-msg" id="yxChatLeaveMsg"></p>
            <button type="submit" class="yx-chat-send" id="yxChatLeaveSubmit">提交留言</button>
            <p class="yx-chat-leave-tip">提交后将自动生成工单，客服通过您留下的联系方式回复。</p>
        </form>

        <div class="yx-chat-foot" id="yxChatFoot">
            <input type="text" id="yxChatInput" maxlength="500" placeholder="请输入您的问题…" autocomplete="off">
            <button type="button" class="yx-chat-send" id="yxChatSend">发送</button>
        </div>
        <div class="yx-chat-tip">小优助手的回答仅供参考；重要事项请「转人工」或留言。</div>
    </section>
</div>
<script>
(function () {
    var API = <?= json_encode($yxChatApi) ?>;
    var CSRF = <?= json_encode($yxChatCsrf) ?>;
    var TOPICS = { business: '商务合作', aftersale: '售后咨询' };
    var el = {
        fab: document.getElementById('yxChatFab'),
        fabTx: document.getElementById('yxChatFabTx'),
        panel: document.getElementById('yxChatPanel'),
        quick: document.getElementById('yxChatQuick'),
        body: document.getElementById('yxChatBody'),
        foot: document.getElementById('yxChatFoot'),
        input: document.getElementById('yxChatInput'),
        send: document.getElementById('yxChatSend'),
        transfer: document.getElementById('yxChatTransfer'),
        close: document.getElementById('yxChatClose'),
        min: document.getElementById('yxChatMin'),
        status: document.getElementById('yxChatStatus'),
        leave: document.getElementById('yxChatLeave'),
        leaveTitle: document.getElementById('yxChatLeaveTitle'),
        leaveCancel: document.getElementById('yxChatLeaveCancel'),
        leaveSubmit: document.getElementById('yxChatLeaveSubmit'),
        leaveMsg: document.getElementById('yxChatLeaveMsg'),
        contact: document.getElementById('yxChatContact'),
        leaveText: document.getElementById('yxChatLeaveText')
    };
    if (!el.fab) return;

    var afterId = 0, status = 'ai', opened = false, timer = null, busy = false, idleTimer = null;
    var leaveOpen = false, leaveTopic = '';
    var IDLE_MS = 60000; /* 面板无操作自动收起时间（毫秒） */

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function statusText(s) {
        return { ai: 'AI 值守中', waiting: '已转人工 · 等待接入', serving: '人工服务中', closed: '会话已结束' }[s] || 'AI 值守中';
    }
    function setStatus(s) {
        status = s;
        el.status.textContent = statusText(s);
        var done = (s === 'waiting' || s === 'serving' || s === 'closed');
        el.transfer.disabled = done;
        el.transfer.style.opacity = done ? '.45' : '';
        var closed = (s === 'closed');
        el.input.disabled = closed;
        el.send.disabled = closed;
        el.quick.querySelectorAll('button').forEach(function (b) { b.disabled = closed; });
        if (closed && leaveOpen) hideLeave();
    }
    function addMsg(m) {
        if (!m || m.id == null) return;
        if (m.id <= afterId) return;
        afterId = m.id;
        var who = m.role === 'visitor' ? 'me' : (m.role === 'agent' ? 'agent' : (m.role === 'system' ? 'sys' : 'ai'));
        var d = document.createElement('div');
        d.className = 'yx-msg yx-msg-' + who;
        d.innerHTML = '<div class="yx-bubble">' + esc(m.content).replace(/\n/g, '<br>') + '</div>';
        el.body.appendChild(d);
        el.body.scrollTop = el.body.scrollHeight;
        resetIdle();
    }
    function render(list) { (list || []).forEach(addMsg); }
    function poll() {
        fetch(API + '?act=state&after=' + afterId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.ok) { setStatus(d.status); render(d.messages); } })
            .catch(function () {});
    }
    function loop() {
        clearInterval(timer);
        var iv = (status === 'waiting' || status === 'serving') ? 3000 : 5000;
        timer = setInterval(function () { if (opened) poll(); }, iv);
    }
    function resetIdle() {
        clearTimeout(idleTimer);
        if (opened && !leaveOpen) idleTimer = setTimeout(close, IDLE_MS);
    }
    function post(act, extra) {
        var fd = new FormData();
        fd.append('act', act);
        fd.append('after', afterId);
        if (extra) { for (var k in extra) fd.append(k, extra[k]); }
        return fetch(API, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }
    function open() {
        opened = true;
        el.panel.hidden = false;
        el.fab.classList.add('is-open');
        el.fab.setAttribute('aria-expanded', 'true');
        el.fabTx.textContent = '收起';
        el.fab.title = '轻触收回弹窗';
        poll();
        loop();
        resetIdle();
        if (!leaveOpen) el.input.focus();
    }
    function close() {
        opened = false;
        el.panel.hidden = true;
        el.fab.classList.remove('is-open');
        el.fab.setAttribute('aria-expanded', 'false');
        el.fabTx.textContent = '轻触咨询';
        el.fab.title = '轻触咨询 · 小优助手';
        clearInterval(timer);
        clearTimeout(idleTimer);
    }

    /* ---- 留言表单 ---- */
    function showLeave(topic) {
        if (!TOPICS[topic]) return;
        leaveTopic = topic;
        leaveOpen = true;
        el.leaveTitle.textContent = TOPICS[topic] + '留言';
        el.leaveMsg.textContent = '';
        el.contact.placeholder = topic === 'business'
            ? '联系方式：手机 / 微信 / 邮箱（商务对接人）'
            : '联系方式：手机 / 微信 / 邮箱（售后回访）';
        el.leaveText.placeholder = topic === 'business'
            ? '请简要说明合作方向、规模与期望，我们会尽快对接…'
            : '请描述遇到的问题（产品 / 订单号 / 现象），我们会尽快处理…';
        el.body.hidden = true;
        el.foot.hidden = true;
        el.quick.hidden = true;
        el.leave.hidden = false;
        clearTimeout(idleTimer);
        el.contact.focus();
    }
    function hideLeave() {
        leaveOpen = false;
        leaveTopic = '';
        el.leave.hidden = true;
        el.body.hidden = false;
        el.foot.hidden = false;
        el.quick.hidden = false;
        el.leaveMsg.textContent = '';
        resetIdle();
    }
    function submitLeave() {
        if (!leaveTopic || busy) return;
        var contact = el.contact.value.trim();
        var content = el.leaveText.value.trim();
        if (contact.length < 3) { el.leaveMsg.textContent = '请填写有效的联系方式'; el.contact.focus(); return; }
        if (content.length < 2) { el.leaveMsg.textContent = '请填写留言内容'; el.leaveText.focus(); return; }
        busy = true;
        el.leaveSubmit.disabled = true;
        el.leaveSubmit.textContent = '提交中…';
        el.leaveMsg.textContent = '';
        post('leave', { category: leaveTopic, contact: contact, content: content }).then(function (d) {
            busy = false;
            el.leaveSubmit.disabled = false;
            el.leaveSubmit.textContent = '提交留言';
            if (d && d.ok) {
                el.contact.value = '';
                el.leaveText.value = '';
                hideLeave();
                if (d.status) setStatus(d.status);
                render(d.messages);
            } else {
                el.leaveMsg.textContent = (d && d.msg) ? d.msg : '提交失败，请稍后重试';
            }
        }).catch(function () {
            busy = false;
            el.leaveSubmit.disabled = false;
            el.leaveSubmit.textContent = '提交留言';
            el.leaveMsg.textContent = '网络异常，请稍后重试';
        });
    }

    /* ---- 事件 ---- */
    el.fab.addEventListener('click', function () { opened ? close() : open(); });
    el.min.addEventListener('click', close);
    el.quick.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
            if (b.disabled) return;
            if (el.body.hidden) { hideLeave(); }
            showLeave(b.getAttribute('data-topic'));
        });
    });
    el.leaveCancel.addEventListener('click', hideLeave);
    el.leave.addEventListener('submit', function (e) { e.preventDefault(); submitLeave(); });
    el.send.addEventListener('click', function () {
        var v = el.input.value.trim();
        if (!v || busy) return;
        busy = true;
        post('send', { content: v }).then(function (d) {
            busy = false;
            if (d && d.ok) { el.input.value = ''; setStatus(d.status); render(d.messages); }
            else if (d && d.msg) { alert(d.msg); }
        }).catch(function () { busy = false; });
    });
    el.input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); el.send.click(); } });
    el.input.addEventListener('input', resetIdle);
    el.panel.addEventListener('click', resetIdle);
    /* 点击弹窗与浮钮以外的区域时自动收起（留言填写中不收起，避免丢失内容） */
    document.addEventListener('click', function (e) {
        if (!opened || leaveOpen) return;
        var root = document.getElementById('yx-chat');
        if (root && !root.contains(e.target)) close();
    });
    el.transfer.addEventListener('click', function () {
        if (el.transfer.disabled) return;
        el.transfer.disabled = true;
        post('transfer', {}).then(function (d) { if (d && d.ok) { setStatus(d.status); render(d.messages); } });
    });
    el.close.addEventListener('click', function () {
        if (!confirm('确认结束本次咨询？')) return;
        post('close', {}).then(function (d) { if (d && d.ok) { setStatus(d.status); render(d.messages); } });
    });
})();
</script>
