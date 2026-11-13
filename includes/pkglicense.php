<?php
/**
 * 优享财务系统 · 签名授权文件（Ed25519 公钥验签）
 *
 * 定位
 *   安装授权码原先只有「授权中心在线复核」一条路：发行包里没有任何可用于
 *   复核的材料，离线或断网时只能中止安装。本文件给发行包补上**只读**能力：
 *   包内放授权中心的公钥，拿到「授权文件」的买家可在本机离线验签，
 *   不需要联网、也不需要把任何签名私钥带进发行包。
 *
 * 边界
 *   1) 私钥只存在于授权中心本机（root-only 0600，位于站点目录之外），签发工具
 *      同样只在授权中心运行；本文件只有公钥，只能验、不能签。
 *   2) 授权文件里的字段全部参与签名（见 yx_pkg_license_payload()），
 *      改域名、改版本、改到期时间都会让签名校验失败。
 *   3) 签名通过 ≠ 授权仍在有效期：到期时间由签名保护，但仍按本机当前日期判定。
 *   4) 公钥随发行包公开属于预期行为：Ed25519 只有私钥能签，公开公钥不等于可伪造。
 *
 * 授权文件（license.json）
 *   {
 *     "v": 1,
 *     "product": "official",           // official | community
 *     "domain": "www.example.com",
 *     "edition": "enterprise",
 *     "code": "8F41-CB20-2D8B-9A76",   // 安装授权码（与签名绑定）
 *     "issued_at": "2026-11-11",
 *     "expire_at": "2027-11-11",       // 空串表示永久
 *     "sig": "base64(ed25519 签名)"
 *   }
 */

/** 授权中心 Ed25519 公钥（32 字节 base64；私钥只在 root-only 目录，永不进包） */
const YX_PKG_LICENSE_PUBKEY = 'IbB8lkVMQDFvVB4cuAhc2/P6BOfpXHB0Ljd/Q4FDs8E=';

/** 授权文件格式版本：改动字段顺序或拼接口径时必须 +1 */
const YX_PKG_LICENSE_VER = 1;

/** 允许的版本档位（与 includes/edition.php 的 EDITIONS 保持一致） */
const YX_PKG_LICENSE_EDITIONS = ['basic', 'pro', 'enterprise'];

/**
 * 授权文件候选路径（按优先级）
 *
 * 买家可能把文件放在站点根目录、解压后的「授权文件」目录，或上传目录；
 * 这里只给固定白名单，不接受任何来自请求的路径，避免读任意文件。
 */
function yx_pkg_license_paths(string $root = ''): array
{
    $root = $root !== '' ? rtrim($root, '/') : (defined('YX_ROOT') ? rtrim(YX_ROOT, '/') : dirname(__DIR__));
    $names = ['license.json', '授权文件.json'];
    $dirs  = ['', '/授权文件', '/uploads/license'];
    $out   = [];
    foreach ($dirs as $dir) {
        foreach ($names as $name) {
            $out[] = $root . $dir . '/' . $name;
        }
    }
    return $out;
}

/** 域名归一化：仅用于比较，不依赖站点其它模块 */
function yx_pkg_license_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('~^[a-z]+://~i', '', $domain) ?? '';
    $domain = preg_replace('~[/?#].*$~', '', $domain) ?? '';
    $domain = preg_replace('~:\d+$~', '', $domain) ?? '';
    $domain = rtrim($domain, '.');
    return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain) && strpos($domain, '..') === false ? $domain : '';
}

/**
 * 参与签名的规范串
 *
 * 刻意不用 JSON 序列化：字段顺序、转义、空格在不同 PHP 版本/语言里都可能不同，
 * 一处差异就会让「签得出、验不过」。这里固定 k=v 顺序、用 | 分隔，
 * 并在校验时拒绝任何含 | 或换行的取值，保证拼接无歧义。
 */
function yx_pkg_license_payload(array $lic): string
{
    $get = static function (string $k) use ($lic): string {
        $v = $lic[$k] ?? '';
        return is_scalar($v) ? trim((string)$v) : '';
    };
    $parts = [
        'yxlic',
        'v=' . (string)YX_PKG_LICENSE_VER,
        'product=' . strtolower($get('product')),
        'domain=' . yx_pkg_license_domain($get('domain')),
        'edition=' . strtolower($get('edition')),
        'code=' . strtoupper(preg_replace('/\s+/', '', $get('code')) ?? ''),
        'issued_at=' . $get('issued_at'),
        'expire_at=' . $get('expire_at'),
    ];
    return implode('|', $parts);
}

/** 校验授权文件结构；返回 '' 表示结构合格，否则返回原因 */
function yx_pkg_license_schema(array $lic): string
{
    if ((int)($lic['v'] ?? 0) !== YX_PKG_LICENSE_VER) {
        return 'ver';
    }
    $product = strtolower(trim((string)($lic['product'] ?? '')));
    if ($product !== 'official' && $product !== 'community') {
        return 'product';
    }
    if (yx_pkg_license_domain((string)($lic['domain'] ?? '')) === '') {
        return 'domain';
    }
    $edition = strtolower(trim((string)($lic['edition'] ?? '')));
    if (!in_array($edition, YX_PKG_LICENSE_EDITIONS, true)) {
        return 'edition';
    }
    $code = strtoupper(preg_replace('/\s+/', '', (string)($lic['code'] ?? '')) ?? '');
    if ($code !== '' && !preg_match('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $code)) {
        return 'code';
    }
    $issued = trim((string)($lic['issued_at'] ?? ''));
    $expire = trim((string)($lic['expire_at'] ?? ''));
    $okDate = static function (string $d): bool {
        return $d === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    };
    if (!$okDate($issued) || !$okDate($expire)) {
        return 'date';
    }
    $sig = (string)($lic['sig'] ?? '');
    if ($sig === '' || strlen((string)base64_decode($sig, true)) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return 'sig';
    }
    // 取值里不允许出现分隔符与空白控制字符，否则规范串可能被拼接歧义利用
    foreach (['product', 'domain', 'edition', 'code', 'issued_at', 'expire_at'] as $k) {
        $v = (string)($lic[$k] ?? '');
        if (strpos($v, '|') !== false || strpos($v, "\n") !== false || strpos($v, "\r") !== false) {
            return 'field';
        }
    }
    return '';
}

/**
 * 验签 + 域名/产品/有效期判定
 *
 * @param array       $lic     授权文件数组
 * @param string|null $pubB64  公钥（默认包内公钥；自检可用临时密钥）
 * @return array{ok:bool,reason:string,message:string,data:array}
 */
function yx_pkg_license_verify(array $lic, ?string $pubB64 = null, string $expectDomain = '', string $expectProduct = ''): array
{
    $fail = static function (string $reason, string $msg) use ($lic): array {
        return ['ok' => false, 'reason' => $reason, 'message' => $msg, 'data' => $lic];
    };

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return $fail('sodium', '本机缺少 sodium 扩展，无法离线验签，请改用在线复核或安装 php-sodium');
    }

    $schema = yx_pkg_license_schema($lic);
    if ($schema !== '') {
        return $fail($schema, '授权文件格式不正确（' . $schema . '）');
    }

    $pubB64 = $pubB64 !== null && $pubB64 !== '' ? $pubB64 : YX_PKG_LICENSE_PUBKEY;
    $pub    = base64_decode($pubB64, true);
    if (!is_string($pub) || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return $fail('pubkey', '发行包内的授权公钥不可用，请重新下载发行包');
    }

    $sig = (string)base64_decode((string)$lic['sig'], true);
    $ok  = sodium_crypto_sign_verify_detached($sig, yx_pkg_license_payload($lic), $pub);
    if (!$ok) {
        return $fail('signature', '授权文件签名不正确：文件可能被改动，或不是本发行包对应的授权文件');
    }

    $domain = yx_pkg_license_domain((string)$lic['domain']);
    if ($expectDomain !== '') {
        $expect = yx_pkg_license_domain($expectDomain);
        if ($expect === '' || !hash_equals($domain, $expect)) {
            return $fail('domain', '授权文件绑定的域名（' . $domain . '）与当前站点域名不一致');
        }
    }
    $product = strtolower(trim((string)$lic['product']));
    if ($expectProduct !== '') {
        $expectP = strtolower(trim($expectProduct)) === 'community' ? 'community' : 'official';
        if ($product !== $expectP) {
            return $fail('product', '授权文件的产品类型与本发行包不一致');
        }
    }

    $expire = trim((string)($lic['expire_at'] ?? ''));
    if ($expire !== '' && $expire < date('Y-m-d')) {
        return $fail('expired', '授权文件已于 ' . $expire . ' 到期，请联系授权中心续期');
    }

    return [
        'ok'      => true,
        'reason'  => '',
        'message' => '授权文件签名有效',
        'data'    => [
            'domain'    => $domain,
            'product'   => $product,
            'edition'   => strtolower(trim((string)$lic['edition'])),
            'code'      => strtoupper(preg_replace('/\s+/', '', (string)($lic['code'] ?? '')) ?? ''),
            'issued_at' => (string)$lic['issued_at'],
            'expire_at' => $expire,
        ],
    ];
}

/** 读取并解析授权文件；结构不合法返回 null */
function yx_pkg_license_read(string $file): ?array
{
    if ($file === '' || !is_file($file) || !is_readable($file) || (int)@filesize($file) > 65536) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** 解析一段授权文件文本（安装向导粘贴用） */
function yx_pkg_license_parse(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || $raw[0] !== '{' || strlen($raw) > 65536) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * 在站点根目录白名单里找授权文件并验签
 *
 * @return array|null 没有找到授权文件返回 null；找到则返回 verify 结果 + file 字段
 */
function yx_pkg_license_find(string $root, string $expectDomain, string $expectProduct): ?array
{
    foreach (yx_pkg_license_paths($root) as $file) {
        $lic = yx_pkg_license_read($file);
        if ($lic === null) {
            continue;
        }
        $res = yx_pkg_license_verify($lic, null, $expectDomain, $expectProduct);
        $res['file'] = strpos($file, rtrim($root, '/') . '/') === 0
            ? substr($file, strlen(rtrim($root, '/')) + 1)
            : basename($file);
        return $res;
    }
    return null;
}
