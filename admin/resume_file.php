<?php
/**
 * 优享财务系统 · 简历附件下载（仅管理员）
 *
 * uploads/resume/ 下是随机文件名，这里按记录取相对路径，
 * 再用 basename() 固定到 uploads/resume 目录内，避免路径穿越。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
YxAuth::requireAdmin();

$id  = (int)($_GET['id'] ?? 0);
$row = $id > 0
    ? YxDb::one('SELECT * FROM ' . YxDb::table('resume') . ' WHERE id = ? LIMIT 1', [$id])
    : null;

if (!$row || trim((string)$row['file_path']) === '') {
    http_response_code(404);
    exit('附件不存在');
}

$abs = yx_upload_dir('resume') . '/' . basename((string)$row['file_path']);
if (!is_file($abs)) {
    http_response_code(404);
    exit('附件已丢失');
}

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
][$ext] ?? 'application/octet-stream';

$name = trim((string)$row['file_name']);
if ($name === '') {
    $name = $row['sn'] . '.' . $ext;
}
// 下载文件名只保留常规字符，去掉换行等可能造成响应头注入的字符
$name = preg_replace('/[\r\n"\\\\\/]+/', '_', $name);
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

log_action('resume.download', (string)$row['sn'], ['file' => basename($abs)]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($abs));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($abs);
exit;
