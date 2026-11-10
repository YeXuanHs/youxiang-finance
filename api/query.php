<?php
/**
 * 优享财务系统 · 下游开放接口（GET 兼容入口）
 *
 * 与 /api/v1.php 完全同一套协议、同一套鉴权与动作实现，唯一差别是：
 *   - 只接受 GET（浏览器、curl、部分对接方的回调/查询框架只能发 GET）
 *   - 路径不带版本前缀，避开 /api/v1、/api/v2 这类会被对方网关或防火墙按路径拦截的前缀
 *
 * 请求：GET /api/query.php?appid=..&action=..&ts=..&nonce=..&sign=.. [+ 业务参数]
 * 响应：{"code":0,"msg":"ok","data":{...},"ts":1730000000}
 * 签名：与 api/v1.php 一致（见 includes/api.php 的 YxApi::sign），GET 下业务参数同样参与签名。
 *
 * 说明：本入口只读转发，不复制鉴权逻辑；动作白名单 / IP 白名单 / 频次 / 防重放均沿用 YxApi::auth()。
 *       写类动作（order.create / service.renew 等）也可走这里，但推荐仍用 POST 主入口。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$start = microtime(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* 只允许 GET：避免形成第二条可被 CSRF 利用的写入口 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, OPTIONS');
    echo json_encode(
        YxApi::respond(null, YxApi::ERR_PARAM, '本入口只接受 GET，写操作请用 POST /api/v1.php'),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/* 只保留一级标量参数，避免数组注入影响签名与业务 */
$params = [];
foreach ($_GET as $k => $v) {
    if (is_scalar($v) || $v === null) {
        $params[(string)$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
    }
}

$action = trim((string)($params['action'] ?? ''));
$appid  = trim((string)($params['appid'] ?? ''));

/* ---------------- 鉴权（与 v1.php 同一实现） ---------------- */
$auth = YxApi::auth($params);
if (empty($auth['ok'])) {
    $cost = (int)round((microtime(true) - $start) * 1000);
    YxApi::log($appid, $action, (int)$auth['code'], (string)$auth['msg'], $cost, $params);
    http_response_code(in_array((int)$auth['code'], [400, 401, 403, 404, 429], true) ? (int)$auth['code'] : 400);
    echo json_encode(YxApi::respond(null, (int)$auth['code'], (string)$auth['msg']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$app = $auth['app'];

/* ---------------- 执行动作 ---------------- */
try {
    $result = YxApiGateway::dispatch($action, $params, $app);
} catch (Throwable $e) {
    $result = ['code' => YxApi::ERR_SERVER, 'msg' => '服务端异常：' . $e->getMessage(), 'data' => null];
    if (config('app.debug')) {
        $result['trace'] = basename($e->getFile()) . ':' . $e->getLine();
    }
}

$cost = (int)round((microtime(true) - $start) * 1000);
YxApi::log($appid, $action, (int)$result['code'], (string)$result['msg'], $cost, $params);

$httpCode = 200;
if ((int)$result['code'] !== YxApi::OK) {
    $httpCode = in_array((int)$result['code'], [400, 401, 403, 404, 429, 500], true) ? (int)$result['code'] : 400;
}
http_response_code($httpCode);

echo json_encode(
    YxApi::respond($result['data'] ?? null, (int)$result['code'], (string)$result['msg']),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
