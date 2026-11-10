<?php
/**
 * 优享财务系统 · 下游开放接口入口
 *
 * 统一入口：POST /api/v1.php   （也支持 GET，便于浏览器/命令行排查）
 * 请求参数：appid、action、ts、nonce、sign + 动作所需业务参数（仅一级标量）
 * 响应格式：{"code":0,"msg":"ok","data":{...},"ts":1730000000}
 *
 * 详细协议见 docs/接口对接说明.md
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

/* ---------------- API 总开关：关闭时直接拒绝，不进入鉴权与业务 ---------------- */
if (!YxZjmf::switchOn(YxZjmf::SET_ENABLED)) {
    YxApi::log('', 'api.gate', 503, '接口已关闭', 0, []);
    http_response_code(503);
    echo json_encode(YxApi::respond(null, 503, '接口已关闭'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------- 收集参数（GET / POST / JSON body 三合一） ---------------- */
$req = array_merge($_GET, $_POST);

if (empty($req)) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $req = $json;
        } elseif (strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') !== false) {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $req = $parsed;
            }
        }
    }
}

/* 只保留一级标量参数，避免数组注入影响签名与业务 */
$params = [];
foreach ($req as $k => $v) {
    if (is_scalar($v) || $v === null) {
        $params[(string)$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
    }
}

$action = trim((string)($params['action'] ?? ''));
$appid  = trim((string)($params['appid'] ?? ''));

/* ---------------- 鉴权 ---------------- */
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
