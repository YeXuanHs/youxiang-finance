<?php
/**
 * 优享财务系统 · 商店资源下载
 *
 * 支持两类：
 *  1) 第三方资源（author_type=thirdparty）：按权益（已购买/兑换）放行，仅供下载。
 *  2) 官方主程序包（author_type=official，type=package）：程序包本体免费下载——
 *     只需登录，不校验购买/授权；授权包（安装授权码）在商店另行按次付费购买，
 *     与程序包下载解耦。下载入口只有本文件，uploads/license 已禁止直链。
 *
 * 传输：支持 HTTP Range（断点续传 / 分段下载），任何输出缓冲与超时限制都不影响大文件；
 *       Range 首发（或整包）才计一次下载量，续传不重复计数。
 *
 * 安全约束：package_path 必须位于 uploads/ 内，路径做 realpath 校验，杜绝目录穿越。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!yx_installed()) {
    http_response_code(503);
    exit('站点未安装');
}

/** 纯文本错误输出，避免任何未捕获提示混进下载流 */
$fail = static function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
};

$id  = (int)($_GET['id'] ?? 0);
$app = $id > 0
    ? YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$id])
    : null;

$isThirdParty  = $app && (string)($app['author_type'] ?? '') === 'thirdparty' && (string)($app['status'] ?? '') === 'on';
$isOfficialPkg = $app && YxStore::isOfficialPackage($app) && (string)($app['status'] ?? '') === 'on';

if (!$app || (!$isThirdParty && !$isOfficialPkg)) {
    $fail(404, '资源不存在或不可下载');
}

/* 权益校验：第三方资源仍按购买/兑换放行；官方程序包免费，只需登录 */
if (!$isOfficialPkg && YxBusiness::billType($app) !== 'free') {
    $cust = YxAuth::customer();
    $ent  = $cust ? YxBusiness::entitlement((int)$app['id'], (int)$cust['id']) : null;
    if (!$ent) {
        $fail(403, $cust ? '该资源需购买或兑换后方可下载' : '请先登录后下载');
    }
} elseif ($isOfficialPkg && !YxAuth::customer()) {
    $fail(403, '请先登录后免费下载程序包');
}

$rel = (string)($app['package_path'] ?? '');
if ($rel === '') {
    $fail(404, '该应用暂未托管安装包，无法下载；如有原始地址请在应用详情页查看');
}

$base = realpath(YX_ROOT . '/uploads');
$full = realpath(YX_ROOT . '/uploads/' . ltrim($rel, '/'));
if (!$base || !$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
    $fail(404, '资源文件已失效（未上传或被删除），请联系平台客服重新上传');
}

$size = (int)filesize($full);
if ($size <= 0) {
    $fail(404, '资源文件为空');
}

/* ---------- 文件名 ---------- */
$ext  = '.tar.gz';
$raw  = (string)($app['package_path'] ?? '');
if (strtolower(substr($raw, -4)) === '.zip') {
    $ext = '.zip';
} elseif (strtolower(substr($raw, -4)) === '.tgz') {
    $ext = '.tgz';
} elseif (strtolower(substr($raw, -4)) === '.rar') {
    $ext = '.rar';
} elseif (strtolower(substr($raw, -4)) === '.7z') {
    $ext = '.7z';
}

$baseName = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)($app['key'] ?? ''));
if ($baseName === '') {
    $baseName = 'resource-' . $id;
}
$version = (string)($app['version'] ?? '');
if ($version !== '' && strpos($baseName, $version) === false) {
    $baseName .= '-' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', $version);
}
$fileName = $baseName . $ext;
/* 中文名兜底（RFC 5987），同时保留 ASCII 名 */
$fileNameUtf8 = rawurlencode($baseName . $ext);

/* ---------- 准备流式输出 ---------- */
ignore_user_abort(false);
@set_time_limit(0);
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$mtime = (int)filemtime($full);
$etag  = '"' . dechex($size) . '-' . dechex($mtime) . '-' . $id . '"';

/* If-Range / If-None-Match 命中则 304，浏览器重复点击不会重下 */
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . $fileNameUtf8);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Content-Description: File Transfer');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Accel-Buffering: no');

/* ---------- Range 解析 ---------- */
$start = 0;
$end   = $size - 1;
$isPartial = false;

$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
$ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
$rangeValidForThisFile = $ifRange === '' || $ifRange === $etag || $ifRange === gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

if ($range !== '' && $rangeValidForThisFile && stripos($range, 'bytes=') === 0) {
    $spec = trim(substr($range, 6));
    /* 多段 Range 不支持，按整包返回，符合 RFC 允许的降级 */
    if (strpos($spec, ',') === false) {
        if (preg_match('/^(\d*)-(\d*)$/', $spec, $m)) {
            $hasStart = $m[1] !== '';
            $hasEnd   = $m[2] !== '';
            if ($hasStart) {
                $start = (int)$m[1];
                if ($hasEnd && $m[2] !== '') {
                    $end = (int)$m[2];
                }
                if ($end > $size - 1) {
                    $end = $size - 1;
                }
                if ($start > $end || $start >= $size) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    header('Accept-Ranges: bytes');
                    exit;
                }
                $isPartial = true;
            } elseif ($hasEnd) {
                /* bytes=-N：末尾 N 字节 */
                $suffix = (int)$m[2];
                if ($suffix <= 0) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    exit;
                }
                $start = max(0, $size - $suffix);
                $end   = $size - 1;
                $isPartial = true;
            }
        }
    }
}

/* 下载量：整包请求或从 0 开始的续传才计一次 */
if (!$isPartial || $start === 0) {
    YxDb::exec('UPDATE ' . YxDb::table('app') . ' SET downloads = downloads + 1 WHERE id = ?', [$id]);
}

$length = $end - $start + 1;

if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . $length);

/* ---------- 输出 ---------- */
$fp = @fopen($full, 'rb');
if (!$fp) {
    $fail(500, '资源文件打开失败');
}

if ($start > 0) {
    if (@fseek($fp, $start, SEEK_SET) !== 0) {
        fclose($fp);
        $fail(500, '资源定位失败');
    }
}

$chunk = 262144; /* 256 KB */
$left  = $length;
while ($left > 0 && !feof($fp)) {
    $read = @fread($fp, (int)min($chunk, $left));
    if ($read === false || $read === '') {
        break;
    }
    echo $read;
    $left -= strlen($read);
    @flush();
    if (connection_aborted()) {
        break;
    }
}
fclose($fp);
exit;
