<?php
/**
 * 优享财务系统 · 魔方财务（ZJMF）兼容对接接口
 *
 * 对接方在后台「接口对接」页拿到 接口名称 / 接口地址 / API 密钥 三项后，
 * 只需把接口地址填到魔方财务的「API 接口」配置里即可。
 *
 * 统一入口：POST /api/zjmf.php（GET 亦可，便于排查）
 * 鉴权要素：api_name、api_key（表单 / Query / JSON body 均可）
 * 响应格式：{"status":200,"msg":"ok","data":{...},"ts":1730000000}
 *
 * 动作：
 *   ping            连通性测试
 *   products        商品树（与 /cart/prolist 同结构）+ 扁平列表
 *   product.detail  产品详情（product_id / product_code / code）
 *   order.create    创建订单（product_id|code、cycle、email|external_user_id、external_sn?）
 *   order.query     查询订单（order_sn，可选 external_user_id）
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

/* 只保留一级标量参数，避免数组注入 */
$params = [];
foreach ($req as $k => $v) {
    if (is_scalar($v) || $v === null) {
        $params[(string)$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
    }
}

$action = trim((string)($params['action'] ?? $params['method'] ?? 'ping'));

$finish = function (array $body, int $status) use ($start, $action): void {
    http_response_code(in_array($status, [200, 400, 401, 403, 404, 429, 500, 503], true) ? $status : 400);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

/* ---------------- 鉴权 ---------------- */
$auth = YxZjmf::auth($params);
if (empty($auth['ok'])) {
    YxZjmf::log($action, (int)$auth['code'], (string)$auth['msg'], $params);
    $finish(YxZjmf::respond(null, (int)$auth['code'], (string)$auth['msg']), (int)$auth['code']);
}

try {
    switch ($action) {
        case 'ping':
            $tree = YxZjmf::productTree();
            $data = YxZjmf::respond([
                'pong'      => true,
                'server'    => setting('site_name', config('app.name')),
                'version'   => config('app.version'),
                'edition'   => YxEdition::name(),
                'time'      => now(),
                'timestamp' => time(),
                'products'  => count(YxZjmf::productList()),
                'switches'  => [
                    'realname' => YxZjmf::switchOn(YxZjmf::SET_REALNAME),
                    'phone'    => YxZjmf::switchOn(YxZjmf::SET_PHONE),
                ],
            ], 200, 'ok');
            break;

        case 'products':
        case 'product.list':
        case 'cart':
            $data = YxZjmf::respond([
                'fgs'  => YxZjmf::productTree()['fgs'],
                'list' => YxZjmf::productList(),
            ], 200, 'ok');
            break;

        case 'product.detail':
        case 'product.info':
            $p = YxZjmf::resolveProduct($params);
            if (!$p) {
                $data = YxZjmf::respond(null, 404, '产品不存在或已下架');
                break;
            }
            $cycles = [];
            foreach (YxBusiness::CYCLES as $k => $c) {
                $cycles[] = [
                    'cycle'     => $k,
                    'name'      => $c['name'],
                    'months'    => $c['months'],
                    'price'     => YxBusiness::cyclePrice($p, $k),
                    'setup_fee' => (float)$p['setup_fee'],
                ];
            }
            $data = YxZjmf::respond([
                'product_id'  => (int)$p['id'],
                'code'        => trim((string)($p['code'] ?? '')) !== '' ? (string)$p['code'] : (string)$p['id'],
                'name'        => $p['name'],
                'type'        => $p['type'],
                'gid'         => (int)$p['group_id'],
                'price'       => (float)$p['price'],
                'setup_fee'   => (float)$p['setup_fee'],
                'stock'       => (int)$p['stock'],
                'description' => $p['description'],
                'cycles'      => $cycles,
            ], 200, 'ok');
            break;

        case 'order.create':
        case 'order':
            $data = YxZjmf::createOrder($params);
            break;

        case 'order.query':
        case 'order.status':
            $order = YxZjmf::findOrder($params);
            $data = $order
                ? YxZjmf::respond(YxZjmf::orderData($order), 200, 'ok')
                : YxZjmf::respond(null, 404, '订单不存在或不属于该接口');
            break;

        default:
            $data = YxZjmf::respond(null, 400, '未知动作：' . $action);
    }
} catch (Throwable $e) {
    $data = YxZjmf::respond(null, 500, '服务端异常：' . $e->getMessage());
    if (config('app.debug')) {
        $data['trace'] = basename($e->getFile()) . ':' . $e->getLine();
    }
}

$status = (int)($data['status'] ?? 500);
YxZjmf::log($action, $status, (string)($data['msg'] ?? ''), $params);
$finish($data, $status);
