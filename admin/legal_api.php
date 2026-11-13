<?php
/**
 * 优享财务系统 · 律师函 / 法务复核 JSON 接口
 *
 * 所有动作都在管理员会话内、经 CSRF 校验后执行；不封装任何对外发送动作。
 * 前端页面（admin/legal_letter.php、admin/legal_review.php）在无 JS 时走表单直提，
 * 有 JS 时调用本接口做 AI 生成、印章预览与状态流转，两条路径共用同一套业务方法。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

$admin = YxAuth::requireAdmin();

$action = (string)($_REQUEST['do'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// 读接口允许 GET；写接口必须 POST 且通过 CSRF
$readOnly = ['get', 'seal_svg', 'seal_png', 'stats'];
if (!$isPost && !in_array($action, $readOnly, true)) {
    json_out(['ok' => false, 'msg' => '该操作需要 POST 提交'], 405);
}
if ($isPost) {
    $token = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], $token)) {
        json_out(['ok' => false, 'msg' => '会话已过期，请刷新页面后重试'], 419);
    }
}

/** 从请求中取律师函 id */
$letterId = (int)($_REQUEST['id'] ?? 0);

switch ($action) {
    /* ---------------- 读取 ---------------- */

    case 'get':
        $row = YxLegalLetter::get($letterId);
        if (!$row) {
            json_out(['ok' => false, 'msg' => '律师函不存在'], 404);
        }
        json_out([
            'ok'     => true,
            'letter' => [
                'id'               => (int)$row['id'],
                'sn'               => $row['sn'],
                'title'            => $row['title'],
                'recipient'        => $row['recipient'],
                'recipient_domain' => $row['recipient_domain'],
                'subject'          => $row['subject'],
                'content'          => $row['content'],
                'content_source'   => $row['content_source'],
                'content_source_name' => YxLegalLetter::sourceName((string)$row['content_source']),
                'ai_model'         => $row['ai_model'],
                'ai_note'          => $row['ai_note'],
                'status'           => $row['status'],
                'status_name'      => YxLegalLetter::statusName((string)$row['status']),
                'drafter'          => $row['drafter'],
                'reviewer'         => $row['reviewer'],
                'review_note'      => $row['review_note'],
                'reviewed_at'      => $row['reviewed_at'],
                'issuer'           => $row['issuer'],
                'seal_date'        => $row['seal_date'],
                'seal_hash'        => $row['seal_hash'],
                'issued_at'        => $row['issued_at'],
                'facts'            => $row['facts_arr'],
            ],
        ]);
        break;

    case 'stats':
        json_out(['ok' => true, 'stats' => YxLegalLetter::stats()]);

    /* ---------------- 生成 ---------------- */

    case 'ai_generate':
    case 'ai_polish':
        $mode = $action === 'ai_polish' ? 'polish' : 'full';
        $facts = (array)($_POST['facts'] ?? []);
        $existing = (string)($_POST['content'] ?? '');
        $res = YxLegalLetter::generate($facts, $mode, $existing);
        json_out([
            'ok'      => true,
            'content' => $res['content'],
            'engine'  => $res['engine'],
            'source'  => $res['source'],
            'source_name' => YxLegalLetter::sourceName($res['source']),
            'note'    => $res['note'],
        ]);
        break;

    /* ---------------- 保存 / 流程 ---------------- */

    case 'create':
        $res = YxLegalLetter::create((array)($_POST['letter'] ?? []), $admin);
        json_out($res, $res['ok'] ? 200 : 400);

    case 'save':
        $res = YxLegalLetter::save($letterId, (array)($_POST['letter'] ?? []), $admin);
        json_out($res, $res['ok'] ? 200 : 400);

    case 'submit':
        $res = YxLegalLetter::submitForReview($letterId, (array)($_POST['letter'] ?? []), $admin);
        json_out($res, $res['ok'] ? 200 : 400);

    case 'review':
        $res = YxLegalLetter::review(
            $letterId,
            (string)($_POST['decision'] ?? ''),
            (string)($_POST['reviewer'] ?? ''),
            (string)($_POST['note'] ?? ''),
            $admin
        );
        json_out($res, $res['ok'] ? 200 : 400);

    case 'issue':
        $res = YxLegalLetter::issue($letterId, [
            'issuer'    => (string)($_POST['issuer'] ?? ''),
            'seal_date' => (string)($_POST['seal_date'] ?? ''),
        ], $admin);
        json_out($res, $res['ok'] ? 200 : 400);

    case 'void':
        $res = YxLegalLetter::void($letterId, (string)($_POST['note'] ?? ''), $admin);
        json_out($res, $res['ok'] ? 200 : 400);

    case 'delete':
        $res = YxLegalLetter::delete($letterId, $admin);
        json_out($res, $res['ok'] ? 200 : 400);

    /* ---------------- 印章 ---------------- */

    case 'seal_svg':
        $opt = [
            'company'   => (string)($_REQUEST['company'] ?? ''),
            'date'      => (string)($_REQUEST['date'] ?? ''),
            'sn'        => (string)($_REQUEST['sn'] ?? ''),
            'size'      => (int)($_REQUEST['size'] ?? 220),
            'cn_date'   => !empty($_REQUEST['cn_date']),
        ];
        if ($opt['company'] === '') {
            unset($opt['company']);
        }
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store');
        echo YxLegalSeal::svg($opt);
        exit;

    case 'seal_png':
        $png = YxLegalSeal::png([
            'company' => (string)($_REQUEST['company'] ?? ''),
            'date'    => (string)($_REQUEST['date'] ?? ''),
            'sn'      => (string)($_REQUEST['sn'] ?? ''),
            'size'    => (int)($_REQUEST['size'] ?? 600),
            'cn_date' => !empty($_REQUEST['cn_date']),
        ]);
        if ($png === null) {
            json_out(['ok' => false, 'msg' => '当前环境无法生成位图印章（缺少 GD 或中文字体），请使用 SVG 印章'], 500);
        }
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="seal.png"');
        header('Cache-Control: no-store');
        echo $png;
        exit;

    default:
        json_out(['ok' => false, 'msg' => '未知操作'], 400);
}
