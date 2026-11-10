<?php
/**
 * 优享财务系统 · 魔方前台购物车式商品树（公开接口）
 *
 * 地址：/cart/prolist（或 /cart/prolist/index.php）
 * 用途：魔方财务等下游在未开通开放接口时，用此通道同步商品 / 库存 / 价格，
 *       无需账户密钥；开通 / 续费类动作仍需开放接口。
 *
 * 响应结构与真实魔方 /cart/prolist 一致：
 *   {"status":200,"msg":"ok","data":{"fgs":[{"id","name","group":[{"products":[...]}]}]}}
 *
 * 受后台「API 总开关」约束：关闭后返回 503。
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$out = function (int $status, string $msg, $data = null): void {
    http_response_code($status);
    echo json_encode(
        ['status' => $status, 'msg' => $msg, 'data' => $data, 'ts' => time()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
};

if (!YxZjmf::switchOn(YxZjmf::SET_ENABLED)) {
    $out(503, '接口已关闭');
}

try {
    $out(200, 'ok', YxZjmf::productTree());
} catch (Throwable $e) {
    $out(500, '服务端异常：' . $e->getMessage());
}
