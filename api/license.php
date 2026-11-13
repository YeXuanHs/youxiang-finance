<?php
/**
 * 优享财务系统 · 对外授权查询接口（授权中心）
 *
 * 三要素鉴权：授权地址（本接口所在站点）+ 授权ID + 授权密钥
 *   GET /api/license.php?app_id=xxx&domain=www.example.com&ts=1699999999&nonce=xxxxxxxx&sign=xxxx
 *
 * sign = HMAC-SHA256(除 sign 外全部一级标量参数按 key 升序拼成 k=v&k=v…, 授权密钥)
 *       与开放接口（api/v1.php）签名算法完全一致，下游可用同一套代码实现。
 *
 * 返回（已授权）：
 *   {"code":0,"msg":"域名已正版授权","ts":1699999999,
 *    "data":{"domain":"...","authorized":true,"status":"authorized","edition":"pro","expire_at":"2027-10-13"},
 *    "sign":"<对 data 的 HMAC 签名>"}
 *
 * 安全：未配置任何授权ID时整体拒绝（不再出现「无密钥即放行」的旁路）；
 *       验签 → 时间戳窗口 → 授权ID状态 → 防重放 → 应答签名。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$domain = normalize_domain((string)($_REQUEST['domain'] ?? ''));

/* ------------------------------------------------------------------ */
/* 安装授权码在线复核                                                   */
/*                                                                     */
/* 发行包不再携带可用于签发/离线校验的密钥，安装向导第 4 步改为向本接口 */
/* 提交 domain + product + code。授权码本身即凭证（由产品密钥对「产品+   */
/* 域名」签名），因此不需要 app_id；接口按 IP 限流、失败达阈值即锁定并  */
/* 触发安全告警。不返回任何可用于推算正确授权码的信息。                 */
/* ------------------------------------------------------------------ */
if (strtolower(trim((string)($_REQUEST['action'] ?? ''))) === 'pkg_verify') {
    $res  = YxLicense::servePkgVerify($_REQUEST);
    $code = (int)($res['code'] ?? 0);
    json_out($res, $code === 0 ? 200 : ($code >= 400 ? $code : 200));
}

/* ------------------------------------------------------------------ */
/* 签名授权文件在线复核（Ed25519 公钥验签）                             */
/*                                                                     */
/* 与 pkg_verify 的区别：不使用产品密钥，只验发行包内公开的公钥，因此   */
/* 不构成签发预言机。买家站点在线确认「拿到的授权文件确为授权中心签发」。 */
/* 参数：domain + product + license（JSON 字符串或对象）。              */
/* ------------------------------------------------------------------ */
if (strtolower(trim((string)($_REQUEST['action'] ?? ''))) === 'pkg_sig_verify') {
    $res  = YxLicense::servePkgSigVerify($_REQUEST);
    $code = (int)($res['code'] ?? 0);
    json_out($res, $code === 0 ? 200 : ($code >= 400 ? $code : 200));
}

// 授权中心尚未启用任何授权ID：说明本机不是授权中心，拒绝对外提供查询
$hasApp = false;
try {
    $hasApp = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('api_app')) > 0;
} catch (Throwable $e) {
    $hasApp = false;
}
if (!$hasApp) {
    json_out(['code' => 403, 'msg' => '本机未配置任何授权ID，未开放对外授权查询', 'data' => null], 403);
}

// 三要素校验（app_id + ts + nonce + sign）
$verify = YxLicense::verifyRequest($_REQUEST);
if (!$verify['ok']) {
    YxLicense::log($domain, YxLicense::STATUS_NO, 'server', $verify['msg'], trim((string)($_REQUEST['app_id'] ?? '')));
    json_out(['code' => $verify['code'], 'msg' => $verify['msg'], 'data' => null], 401);
}

if ($domain === '') {
    json_out(['code' => 400, 'msg' => '缺少 domain 参数', 'data' => null], 400);
}

$result = YxLicense::serveQuery($verify['app']);
json_out($result, $result['code'] === 0 ? 200 : 400);
