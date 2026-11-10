<?php
/**
 * 优享财务系统 · 实名认证结果回调
 *
 * 真实服务商回调与内置 mock 模拟扫码共用本入口：
 *  - 服务商：POST/GET 参数含 request_id + status + sign（按服务商协议适配）
 *  - mock：  ?mock=1&request_id=..&sign=.. （扫码打开即视为核验通过）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$params = array_merge($_GET, $_POST);
$mock   = (string)($params['mock'] ?? '') === '1';

$res = YxRealname::handleCallback($params, $mock);
log_action('realname.notify', (string)($params['request_id'] ?? ''), [
    'ok'     => $res['ok'] ?? false,
    'status' => $res['status'] ?? '',
    'mock'   => $mock,
]);

if (empty($res['ok'])) {
    if ($mock) {
        http_response_code(400);
        exit('核验失败：' . ($res['msg'] ?? ''));
    }
    echo 'fail';
    exit;
}

if ($mock) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>核验完成</title>
    <style>body{font-family:-apple-system,"Microsoft YaHei",sans-serif;background:#f4f6fb;margin:0;padding:60px 16px;text-align:center;color:#1f2733}
    .box{max-width:360px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(20,40,80,.08);padding:32px}
    .ok{font-size:44px;color:#2f9e44;margin:0}
    a{display:inline-block;margin-top:18px;padding:9px 18px;background:#4dabf7;color:#fff;border-radius:8px;text-decoration:none}</style>
    </head>
    <body>
    <div class="box">
        <p class="ok">✓</p>
        <h2><?= $res['status'] === 'verified' ? '模拟核验通过' : '核验未通过' ?></h2>
        <p><?= e($res['msg'] ?? '') ?></p>
        <a href="<?= e(url('user/developer.php')) ?>">返回开发者中心</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

echo 'success';
