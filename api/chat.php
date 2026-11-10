<?php
/**
 * 优享财务系统 · 小优助手接口（游客与登录客户通用）
 *
 * GET  ?act=state&after=<id>      拉取会话状态与新消息
 * POST act=send&after=<id>&content 发送消息（AI 阶段自动解答）
 * POST act=leave&category=&contact=&content 商务合作 / 售后咨询留言（自动建工单）
 * POST act=transfer               转人工（自动建工单）
 * POST act=close                  结束会话
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!yx_installed()) {
    json_out(['ok' => false, 'msg' => '站点未安装'], 503);
}
if (!YxChat::enabled()) {
    json_out(['ok' => false, 'msg' => '客服未开启'], 403);
}

/* 会话标识：cookie 优先，session 兜底 */
$token = (string)($_COOKIE['yx_chat'] ?? ($_SESSION['chat_token'] ?? ''));
if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
    $token = random_key(16);
    $_SESSION['chat_token'] = $token;
    setcookie('yx_chat', $token, [
        'expires'  => time() + 86400 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$customer = YxAuth::customer();
$act      = (string)($_REQUEST['act'] ?? 'state');
$after    = (int)($_REQUEST['after'] ?? 0);

/* 写操作校验 CSRF（前端通过 X-CSRF-Token 头提交） */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

$session = YxChat::ensureSession($token, $customer, client_ip(), (string)($_REQUEST['url'] ?? ''));

$payload = function (int $sid, array $sess, int $after) {
    $msgs = [];
    foreach (YxChat::messages($sid, $after) as $m) {
        $msgs[] = ['id' => (int)$m['id'], 'role' => $m['role'], 'content' => (string)$m['content'], 'time' => (string)$m['created_at']];
    }
    return [
        'ok'        => true,
        'status'    => (string)$sess['status'],
        'status_tx' => YxChat::statusName((string)$sess['status']),
        'ticket_id' => (int)$sess['ticket_id'],
        'messages'  => $msgs,
    ];
};

if ($act === 'send') {
    $content = (string)($_POST['content'] ?? '');
    $res     = YxChat::visitorSend($session, $content);
    if (empty($res['ok'])) {
        json_out(['ok' => false, 'msg' => $res['msg'] ?? '发送失败'], 400);
    }
    $session = YxChat::byId((int)$session['id']);
    YxChat::markVisitorRead((int)$session['id']);
    json_out($payload((int)$session['id'], $session, $after));
}

if ($act === 'leave') {
    $res = YxChat::leave(
        $session,
        (string)($_POST['category'] ?? ''),
        (string)($_POST['contact'] ?? ''),
        (string)($_POST['content'] ?? '')
    );
    if (empty($res['ok'])) {
        json_out(['ok' => false, 'msg' => $res['msg'] ?? '提交失败'], 400);
    }
    $session = YxChat::byId((int)$session['id']);
    YxChat::markVisitorRead((int)$session['id']);
    json_out($payload((int)$session['id'], $session, $after) + [
        'notice'    => $res['msg'] ?? '留言已提交',
        'ticket_sn' => $res['ticket_sn'] ?? '',
    ]);
}

if ($act === 'transfer') {
    $res = YxChat::transfer($session, trim((string)($_POST['reason'] ?? '')));
    $session = YxChat::byId((int)$session['id']);
    json_out($payload((int)$session['id'], $session, $after) + ['notice' => $res['msg'] ?? '']);
}

if ($act === 'close') {
    YxChat::close((int)$session['id'], 'visitor');
    $session = YxChat::byId((int)$session['id']);
    YxChat::markVisitorRead((int)$session['id']);
    json_out($payload((int)$session['id'], $session, $after));
}

/* 默认：state */
YxChat::markVisitorRead((int)$session['id']);
json_out($payload((int)$session['id'], $session, $after));
