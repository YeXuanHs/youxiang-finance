<?php
/**
 * 优享财务系统 · 发行包文案数据（不含任何密钥）
 *
 * 定位（与旧版的关键差别）
 *   旧版把「文案 + 密钥」一起 AES 加密成密文，而派生密钥的片段就明文写在
 *   本文件里——等于把钥匙和锁放在同一个抽屉：任何人打开本文件就能拼出
 *   AES 密钥、解开密文。这层加密只增加阅读障碍，挡不住逆向，却让发行包
 *   里永久躺着一串「看着像密钥的东西」，属于必须清除的暴露面。
 *
 *   现在本文件只放**本来就可以公开**的文案（商店地址、授权包价格与入口），
 *   以普通常量直接给出；发行包内不再有密文、不再有密钥片段、不再有
 *   任何可被还原成密钥的字符串。
 *
 * 真正需要保密的只有产品密钥
 *   签发/复核安装授权码用的产品密钥只存在于站点根目录之外的
 *   YX_CENTER_SECRET_FILE（root 独占 + 本机签发服务持有），永不进发行包，
 *   发行包里也没有任何能派生它的材料。因此拿到发行包的人无法离线算出
 *   任何有效授权码。
 *
 * 使用（函数名与签名保持不变，调用方无需改动）
 *   yx_pkg_sealed('shop')        商店地址
 *   yx_pkg_sealed_all()          全部文案字段
 *   yx_pkg_sealed_ok()           文案是否可用
 *   yx_center_secret()           产品密钥（仅授权中心本机可用）
 *
 * 轮换
 *   产品密钥轮换只改 YX_CENTER_SECRET_FILE；文案变动直接改下面的常量，
 *   不再需要重新封包密文。
 */

/**
 * 发行版文案字段
 *
 * 这些值本来就是公开信息（下载页、购买页都能看到），不做加密。
 * 键名与旧版密封数据保持一致，避免调用方改动。
 */
const YX_PKG_TEXT = [
    'shop'       => 'https://www.example.com/store/',
    'auth_price' => '366 元/次',       // 安装授权包价格（按域名签发安装授权码）
    'pkg_price'  => '88 元/次',        // 正版程序包价格（仅程序包本体，不含授权码；开源版免费）
    'auth_page'  => '/user/licenses.php',
    'auth_label' => '授权包',
];

/**
 * 授权中心产品密钥文件（站点根目录之外，永不进发行包）
 *
 * 产品密钥已从本文件移出：本文件随发行包分发，任何放在这里的密钥都等于公开。
 * 只有本机（授权中心）才持有下面这个文件，缺失即视为「密钥不可用」，
 * 签发与复核一律按不通过处理，不退回任何默认值。
 */
const YX_CENTER_SECRET_FILE = '';

/** 取全部文案字段；发行包内没有密文可解，直接返回常量 */
function yx_pkg_sealed_all(): array
{
    $out = [];
    foreach (YX_PKG_TEXT as $k => $v) {
        $out[(string)$k] = (string)$v;
    }
    return $out;
}

/** 取单个文案字段；字段缺失返回 $default（默认空串） */
function yx_pkg_sealed(string $field, string $default = ''): string
{
    $all = yx_pkg_sealed_all();
    return isset($all[$field]) ? (string)$all[$field] : $default;
}

/** 文案是否可用（安装向导用它决定是否放行授权校验） */
function yx_pkg_sealed_ok(): bool
{
    return yx_pkg_sealed('shop') !== '';
}

/**
 * 读取授权中心产品密钥
 *
 * 本机（授权中心）专属：密钥不在发行包内，只存在于 YX_CENTER_SECRET_FILE。
 * 文件不可读、结构不符、缺少 active 时一律返回空数组，调用方必须按
 * 「密钥不可用」处理，不得退回任何默认密钥。
 */
function yx_center_secret(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (YX_CENTER_SECRET_FILE === '' || !is_file(YX_CENTER_SECRET_FILE)) {
        return $cache;
    }
    $data = @include YX_CENTER_SECRET_FILE;
    if (!is_array($data) || !is_array($data['active'] ?? null)) {
        return $cache;
    }
    $ver    = (string)($data['active']['ver'] ?? '');
    $secret = (string)($data['active']['secret'] ?? '');
    if ($ver === '' || $secret === '') {
        return $cache;
    }
    $retired = [];
    foreach ((array)($data['retired'] ?? []) as $v => $s) {
        if ((string)$s !== '') {
            $retired[(string)$v] = (string)$s;
        }
    }
    $cache = ['ver' => $ver, 'secret' => $secret, 'retired' => $retired];
    return $cache;
}

/**
 * 取可用于复核某个版本授权码的密钥
 *
 * 当前版本用 active，历史版本（如轮换前签发的码）用 retired。
 * 找不到对应版本返回空串，表示该版本的码一律不通过。
 */
function yx_center_secret_for(string $ver): string
{
    $all = yx_center_secret();
    if (!$all) {
        return '';
    }
    if ((string)$ver === (string)$all['ver']) {
        return (string)$all['secret'];
    }
    return (string)($all['retired'][(string)$ver] ?? '');
}

/** 产品密钥是否可用（授权中心本机） */
function yx_center_secret_ok(): bool
{
    return yx_center_secret() !== [];
}
