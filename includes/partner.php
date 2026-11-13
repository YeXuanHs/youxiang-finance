<?php
/**
 * 优享财务系统 · 合作伙伴 / 服务网络
 *
 * 设计约定：
 *   - 存 yx_partner，一条记录 = 一家合作机构（名称 + Logo + 说明 + 可选链接）
 *   - 前台只展示 status = 1 的记录，按 sort 升序、id 升序
 *   - 图片仅接受 uploads/ 下的相对路径，拒绝协议、绝对路径与目录穿越
 *   - 后台可上传 / 更换 / 删除图片；未设置图片时前台用名称首字占位
 */

class YxPartner
{
    /** 清洗图片相对路径（相对 uploads/）。非法路径返回空串。 */
    public static function cleanLogo(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strpos($path, '..') !== false || strpos($path, '\\') !== false) {
            return '';
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $path)) {
            return '';
        }
        return ltrim($path, '/');
    }

    /** Logo 可访问 URL；未设置或路径非法时返回空串 */
    public static function logoUrl(array $row): string
    {
        $img = self::cleanLogo((string)($row['logo'] ?? ''));
        return $img === '' ? '' : yx_upload_url($img);
    }

    /** 删除 Logo 文件（仅限 uploads/ 目录内，避免误删其它文件） */
    public static function removeLogoFile(string $logo): void
    {
        $rel = self::cleanLogo($logo);
        if ($rel === '') {
            return;
        }
        $base = realpath(YX_ROOT . '/uploads');
        $real = realpath(YX_ROOT . '/uploads/' . $rel);
        if ($base !== false && $real !== false && is_file($real)
            && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
            @unlink($real);
        }
    }

    /** 安全跳转地址：仅允许 http(s) 外链与站内相对路径，其余一律返回空串 */
    public static function href(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return ''; // javascript: / data: 等一律拒绝
        }
        if (strpos($url, '//') === 0) {
            return '';
        }
        return url($url);
    }

    /** 管理端全部记录 */
    public static function all(): array
    {
        try {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('partner') . ' ORDER BY sort ASC, id ASC'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 前台展示的记录（已启用） */
    public static function active(): array
    {
        try {
            return YxDb::all(
                'SELECT * FROM ' . YxDb::table('partner') . ' WHERE status = 1 ORDER BY sort ASC, id ASC'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function find(int $id)
    {
        if ($id <= 0) {
            return null;
        }
        try {
            return YxDb::one('SELECT * FROM ' . YxDb::table('partner') . ' WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 名称占位字符（无 Logo 时展示） */
    public static function initial(array $row): string
    {
        $name = trim((string)($row['name'] ?? ''));
        return $name === '' ? '·' : mb_substr($name, 0, 1);
    }
}
