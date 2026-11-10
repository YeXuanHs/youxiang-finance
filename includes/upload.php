<?php
/**
 * 优享财务系统 · 文件上传助手
 *
 * 统一处理开发者上传的封面、截图与安装包：
 *  - 校验上传错误码、文件大小、扩展名与真实 MIME
 *  - 图片使用 getimagesize 二次校验，避免伪装成图片的可执行文件
 *  - 随机命名后落盘到 uploads/ 下（默认禁止脚本执行，见 deploy 说明）
 */

if (!defined('YX_ROOT')) {
    define('YX_ROOT', dirname(__DIR__));
}

/** 上传目录绝对路径（自动创建） */
function yx_upload_dir(string $sub = ''): string
{
    $dir = YX_ROOT . '/uploads';
    if ($sub !== '') {
        $dir .= '/' . trim($sub, '/');
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** 相对路径转可访问 URL */
function yx_upload_url(string $rel): string
{
    return url('uploads/' . ltrim($rel, '/'));
}

/** 归一化 $_FILES 多文件字段为「单文件数组」列表 */
function yx_files_array(string $field): array
{
    if (empty($_FILES[$field])) {
        return [];
    }
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        $out[] = [
            'name'     => $f['name'][$i],
            'type'     => $f['type'][$i],
            'tmp_name' => $f['tmp_name'][$i],
            'error'    => $f['error'][$i],
            'size'     => $f['size'][$i],
        ];
    }
    return $out;
}

/**
 * 处理单个上传文件
 * @param array $file  $_FILES 中的单个文件项
 * @param array $opt   max_size / exts / mimes / sub / prefix
 * @return array ['ok'=>bool,'msg'=>..,'path'=>相对路径,'url'=>..,'size'=>..,'name'=>..]
 */
function yx_upload(array $file, array $opt = []): array
{
    $maxSize = (int)($opt['max_size'] ?? 5 * 1024 * 1024);
    $exts    = array_map('strtolower', (array)($opt['exts'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp']));
    $mimes   = array_map('strtolower', (array)($opt['mimes'] ?? []));
    $sub     = (string)($opt['sub'] ?? '');
    $prefix  = (string)($opt['prefix'] ?? date('Ymd'));

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'msg' => '上传参数无效'];
    }
    if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'msg' => '未选择文件'];
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL    => '文件仅上传了一部分',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
            UPLOAD_ERR_EXTENSION  => '被扩展中断',
        ];
        return ['ok' => false, 'msg' => $map[(int)$file['error']] ?? ('上传失败（错误码 ' . (int)$file['error'] . '）')];
    }
    if ($maxSize > 0 && (int)$file['size'] > $maxSize) {
        return ['ok' => false, 'msg' => '文件超过大小限制（' . number_format($maxSize / 1048576, 1) . ' MB）'];
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $exts, true)) {
        return ['ok' => false, 'msg' => '不支持的文件类型（允许：' . implode('/', $exts) . '）'];
    }

    // 真实 MIME 校验
    $realMime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $realMime = strtolower((string)@finfo_file($fi, $file['tmp_name']));
            @finfo_close($fi);
        }
    }
    if ($mimes && $realMime !== '' && !in_array($realMime, $mimes, true)) {
        return ['ok' => false, 'msg' => '文件内容与类型不符'];
    }

    // 图片二次校验
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) && function_exists('getimagesize')) {
        if (@getimagesize($file['tmp_name']) === false) {
            return ['ok' => false, 'msg' => '图片文件损坏或格式不正确'];
        }
    }

    $dir = yx_upload_dir($sub);
    if (!is_writable($dir)) {
        return ['ok' => false, 'msg' => '上传目录不可写：uploads/' . trim($sub, '/')];
    }

    $base = $prefix . '-' . date('ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $base;
    // move_uploaded_file 仅对真实 HTTP 上传有效；CLI（自检/迁移脚本）下退化为普通移动
    $moved = PHP_SAPI === 'cli'
        ? @copy($file['tmp_name'], $dest)
        : @move_uploaded_file($file['tmp_name'], $dest);
    if (!$moved) {
        return ['ok' => false, 'msg' => '保存文件失败'];
    }
    @chmod($dest, 0644);

    $rel = ($sub !== '' ? trim($sub, '/') . '/' : '') . $base;
    return [
        'ok'   => true,
        'msg'  => '上传成功',
        'path' => $rel,
        'url'  => yx_upload_url($rel),
        'size' => (int)$file['size'],
        'name' => $base,
    ];
}
