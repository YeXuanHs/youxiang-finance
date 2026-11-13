<?php
/**
 * 优享财务系统 · 发行包受控分发（仅后台管理员）
 *
 * 定位
 *   官方主程序包的发行文件（正版程序包与开源版为明文 .zip，授权包为整包密文 .zip.enc）有两份权威副本：
 *     · uploads/license/          前台客户下载通道（api/store_download.php，按购买权益放行）
 *     · config('store.release_dir') 指向站点根目录之外的发行目录（仅 root 可写），
 *       前台没有任何直链。
 *   本文件是第二种副本的唯一出口：管理员在「商店管理 → 发行包分发」点下载时，
 *   经完整鉴权后把文件流式读出，用于运维自查/重新分发/校验。
 *
 * 安全约束（逐条对应下面代码）
 *   1) 必须是已登录管理员，且拥有 store 能力（与 admin/store.php 同一门槛）。
 *   2) 只接受 yx_app 中真实存在的官方主程序包 id，文件名一律取自数据库
 *      package_path 的 basename，绝不接受请求方传入的路径。
 *   3) realpath 必须落在 release_dir 之内，杜绝目录穿越与符号链接逃逸。
 *   4) 任何失败都以纯文本返回，不回显绝对路径、不输出密钥片段。
 *   5) 全流程只读取文件字节，不尝试解密（解密密钥在 keys/，Web 进程不可读）。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

/** 纯文本错误输出：避免任何未捕获提示混进下载流 */
$fail = static function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
};

/* ---------- 1) 鉴权：管理员 + 商店能力 ---------- */
$admin = YxAuth::requireAdmin();
if (!YxAuth::canAdmin('store', $admin)) {
    log_action('admin.denied', 'store.release_download', ['role' => YxAuth::roleOf($admin)]);
    $fail(403, '当前账号没有「应用商店」权限');
}

/* ---------- 2) 定位应用：只认官方主程序包 ---------- */
$id  = (int)($_GET['id'] ?? 0);
$app = $id > 0
    ? YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$id])
    : null;

if (!$app || !YxStore::isOfficialPackage($app)) {
    $fail(404, '发行包不存在');
}

/* ---------- 3) 解析受控目录内的文件 ---------- */
$full = YxStore::releaseFile($app);
if ($full === null) {
    $fail(404, '发行包未就绪：受控目录中没有该版本文件，或目录不在 open_basedir 白名单内');
}

$size = (int)@filesize($full);
if ($size <= 0) {
    $fail(404, '发行包文件为空');
}

$name = basename($full);
if ($name === '' || $name === '.' || $name === '..') {
    $fail(404, '发行包文件名非法');
}
$fileNameUtf8 = rawurlencode($name);

/* ---------- 4) 准备流式输出 ---------- */
ignore_user_abort(false);
@set_time_limit(0);
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$mtime = (int)@filemtime($full);
$etag  = '"' . dechex($size) . '-' . dechex($mtime) . '-' . $id . '-r"';

$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . $fileNameUtf8);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Content-Description: File Transfer');
/* 发行包属于内部资料，禁止任何中间层缓存 */
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Accel-Buffering: no');
header('X-Robots-Tag: noindex, nofollow');

/* ---------- 5) Range 解析（断点续传） ---------- */
$start = 0;
$end   = $size - 1;
$isPartial = false;

$range   = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
$ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
$rangeValid = $ifRange === '' || $ifRange === $etag || $ifRange === gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

if ($range !== '' && $rangeValid && stripos($range, 'bytes=') === 0) {
    $spec = trim(substr($range, 6));
    /* 多段 Range 不支持，按整包返回，符合 RFC 允许的降级 */
    if (strpos($spec, ',') === false && preg_match('/^(\d*)-(\d*)$/', $spec, $m)) {
        $hasStart = $m[1] !== '';
        $hasEnd   = $m[2] !== '';
        if ($hasStart) {
            $start = (int)$m[1];
            if ($hasEnd) {
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

$length = $end - $start + 1;

if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . $length);

/* ---------- 6) 输出 ---------- */
$fp = @fopen($full, 'rb');
if (!$fp) {
    $fail(500, '发行包打开失败');
}

if ($start > 0 && @fseek($fp, $start, SEEK_SET) !== 0) {
    fclose($fp);
    $fail(500, '发行包定位失败');
}

/* 仅记录「谁取了哪个包」，不记录内容与路径 */
log_action('store.release_download', (string)($app['key'] ?? ''), [
    'id'    => $id,
    'admin' => (int)($admin['id'] ?? 0),
    'partial' => $isPartial ? 1 : 0,
]);

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
