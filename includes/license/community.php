<?php
/**
 * 优享财务系统 · 授权模块
 *
 * 离线实现：不联网、不签名、不读写授权表，站点自带完整授权能力。
 *   - 授权结论恒为「已正版授权 · 企业版」，不受域名与授权中心影响；
 *   - 不校验安装授权码，装完即用；
 *   - 版本能力不做收敛，普惠 / 专业 / 企业三档可自由切换；
 *   - 同时作为下游授权服务端可用（api/license.php 的 query / pkg_verify 等动作）。
 */

class YxLicense
{
    const STATUS_OK      = 'authorized';
    const STATUS_NO      = 'unauthorized';
    const STATUS_EXPIRED = 'expired';
    const STATUS_BANNED  = 'banned';
    const STATUS_COMMUNITY = 'community';

    /** 授权方展示名 */
    const OFFICIAL_NAME = 'AnchorFlow团队';

    const SIGN_WINDOW = 0;

    /** 模块标识（保留原值，供构建脚本识别本实现） */
    public const EDITION_MODULE = 'community';

    /** 授权模式：正式版形态（离线校验，不依赖授权中心） */
    public static function mode(): string
    {
        return 'official';
    }

    public static function isCommunity(): bool
    {
        return false;
    }

    /** 形态不锁定，允许在后台切换版本档位 */
    public static function modeLocked(): bool
    {
        return false;
    }

    /** 兼容自检入口：形态不锁定，调用不改变任何状态 */
    public static function debugSetLockedMode(?bool $locked): void
    {
    }

    /** 接受 official / community 两种形态 */
    public static function setMode(string $mode): bool
    {
        return in_array($mode, ['official', 'community'], true);
    }

    /** 授权结论：企业版、长期有效、不限制功能 */
    private static function openResult(string $domain): array
    {
        return [
            'domain'      => $domain,
            'authorized'  => true,
            'status'      => self::STATUS_OK,
            'edition'     => 'enterprise',
            'issued_at'   => null,
            'expire_at'   => null,
            'license_key' => null,
            'official'    => self::OFFICIAL_NAME,
            'source'      => 'builtin',
            'message'     => '已正版授权（企业版 · 永久有效）',
            'checked_at'  => now(),
        ];
    }

    /** 离线直接给出授权结论：不联网、不读缓存、不写日志 */
    public static function query(string $domain, bool $force = false): array
    {
        return self::openResult(normalize_domain($domain));
    }

    public static function check(bool $force = false): array
    {
        return self::query(current_domain(), $force);
    }

    public static function badge(?string $domain = null): array
    {
        $r = $domain === null ? self::check() : self::query($domain);
        return [
            'text'   => '域名已正版授权',
            'class'  => 'ok',
            'icon'   => '✓',
            'detail' => $r,
        ];
    }

    public static function isAuthorized(?string $domain = null): bool
    {
        return true;
    }

    /** 不做版本收敛：最高档位为企业版 */
    public static function maxEdition(): string
    {
        return 'enterprise';
    }

    /**
     * 离线授权码：由域名与档位做一次散列截断，无需任何密钥。
     */
    public static function generateKey(string $domain, string $edition = 'basic', string $expire = ''): string
    {
        $domain = normalize_domain($domain);
        if ($domain === '') {
            return '';
        }
        $raw = strtoupper(hash('sha256', 'community|' . $domain . '|' . $edition . '|' . $expire));
        return implode('-', str_split(substr($raw, 0, 16), 4));
    }

    public static function verifyKey(string $domain, string $key, string $expire = ''): bool
    {
        foreach (['basic', 'pro', 'enterprise'] as $ed) {
            if (hash_equals(self::generateKey($domain, $ed, $expire), strtoupper(trim($key)))) {
                return true;
            }
        }
        return false;
    }

    /** 离线模式不携带产品密钥 */
    public static function packageSecret(): string
    {
        return '';
    }

    public static function packageProduct(string $mode = ''): string
    {
        return 'community';
    }

    /** 无需安装授权码，签发出空码 */
    public static function packageKey(string $domain, string $product = 'official'): string
    {
        return '';
    }

    /** 安装授权码复核：离线放行 */
    public static function servePkgVerify(array $req): array
    {
        return [
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'ok'      => true,
                'reason'  => 'builtin',
                'edition' => 'enterprise',
                'message' => '已正版授权（企业版 · 永久有效）',
            ],
        ];
    }

    /** 签名授权文件复核：离线放行 */
    public static function servePkgSigVerify(array $req): array
    {
        return [
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'ok'      => true,
                'reason'  => 'builtin',
                'edition' => 'enterprise',
                'message' => '已正版授权（企业版 · 永久有效）',
            ],
        ];
    }

    /** 客户端侧：安装授权码离线复核，一律放行 */
    public static function remoteVerifyPackageCode(string $domain, string $product, string $code): ?array
    {
        return [
            'ok'      => true,
            'reason'  => 'builtin',
            'edition' => 'enterprise',
            'message' => '已正版授权（企业版 · 永久有效）',
        ];
    }

    /** 离线模式不发送安全告警邮件 / Webhook */
    public static function securityAlert(string $event, array $ctx = []): void
    {
    }

    /** 离线模式不写授权校验日志 */
    public static function log(string $domain, string $result, string $source, string $message = '', string $appId = '', string $ip = ''): void
    {
    }

    /** 作为下游授权服务端时：直接返回已正版授权结论 */
    public static function serveQuery(?array $app = null): array
    {
        $domain = normalize_domain((string)($_REQUEST['domain'] ?? ''));
        return [
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'domain'     => $domain,
                'authorized' => true,
                'status'     => self::STATUS_OK,
                'official'   => self::OFFICIAL_NAME,
                'message'    => '已正版授权（企业版 · 永久有效）',
            ],
        ];
    }

    public static function statusText(string $status): string
    {
        $map = [
            self::STATUS_OK      => '域名已正版授权',
            self::STATUS_NO      => '域名未在官方授权',
            self::STATUS_EXPIRED => '授权已过期，请续费',
            self::STATUS_BANNED  => '授权已被官方封禁',
        ];
        return $map[$status] ?? '未知授权状态';
    }

    public static function officialApi(): string
    {
        return '';
    }

    public static function queryPath(): string
    {
        return '';
    }

    public static function appId(): string
    {
        return '';
    }

    public static function channelSecret(): string
    {
        return '';
    }

    public static function hasCredentials(): bool
    {
        return false;
    }

    public static function lastError(): string
    {
        return '';
    }

    /** 离线模式不做请求签名 */
    public static function sign(array $params, string $secret): string
    {
        return '';
    }

    /** 下游授权查询请求：放行（离线模式不校验签名） */
    public static function verifyRequest(array $req): array
    {
        return ['ok' => true, 'app' => null, 'msg' => 'ok', 'code' => 200];
    }

    public static function flushCache(): void
    {
    }
}
