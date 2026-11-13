<?php
/**
 * 优享财务系统 · 服务条款 / 隐私政策文档
 *
 * 设计约定：
 *   - 两个文档各占一条记录，存 yx_legal_doc（slot = terms | privacy），标题、版本号、
 *     生效日期与正文全部由后台维护，不写死在页面里
 *   - 正文为纯文本，用空行分段、单行内前缀「- 」表示列表项、以「# 」开头表示二级小标题，
 *     渲染时统一转义，不允许内联 HTML，避免后台误填导致样式错乱或脚本注入
 *   - 注册页只读取「生效日期」，未填写时退回更新时间，保证提示文案里始终有日期
 */

class YxLegalDoc
{
    const TERMS   = 'terms';
    const PRIVACY = 'privacy';

    /**
     * 文档槽位定义
     *   name  前台页面主标题与注册页链接文案
     *   path  前台访问路径（相对站点根目录）
     */
    const SLOTS = [
        self::TERMS => [
            'name' => '服务条款',
            'path' => 'terms.php',
            'tip'  => '用户注册、使用系统与服务时需要遵守的约定。',
        ],
        self::PRIVACY => [
            'name' => '隐私政策',
            'path' => 'privacy.php',
            'tip'  => '说明我们收集哪些信息、如何使用与保护这些信息。',
        ],
    ];

    /** 默认标题（记录不存在或标题留空时使用） */
    public static function defaultTitle(string $slot): string
    {
        return (self::SLOTS[$slot]['name'] ?? '法律文档');
    }

    /** 是否合法槽位 */
    public static function isSlot(string $slot): bool
    {
        return isset(self::SLOTS[$slot]);
    }

    /** 前台路径（相对站点根），非法槽位返回空串 */
    public static function path(string $slot): string
    {
        return self::isSlot($slot) ? self::SLOTS[$slot]['path'] : '';
    }

    /** 前台可访问 URL */
    public static function url(string $slot): string
    {
        $path = self::path($slot);
        return $path === '' ? '' : url($path);
    }

    /** 取一条文档记录，不存在返回空数组 */
    public static function find(string $slot): array
    {
        if (!self::isSlot($slot)) {
            return [];
        }
        try {
            $row = YxDb::one(
                'SELECT * FROM ' . YxDb::table('legal_doc') . ' WHERE slot = ? LIMIT 1',
                [$slot]
            );
            return $row ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 前台取文档：保证返回结构完整（缺失字段回落到默认值） */
    public static function get(string $slot): array
    {
        $row = self::find($slot);
        $title = trim((string)($row['title'] ?? ''));
        return [
            'slot'        => $slot,
            'title'       => $title !== '' ? $title : self::defaultTitle($slot),
            'version'     => trim((string)($row['version'] ?? '')),
            'effective'   => self::effectiveDate($row),
            'content'     => (string)($row['content'] ?? ''),
            'updated_at'  => (string)($row['updated_at'] ?? ''),
        ];
    }

    /**
     * 生效日期（Y-m-d），未填写时依次回落到更新时间、创建时间
     * 只取日期部分，库里可能存了带时分秒的时间。
     */
    public static function effectiveDate(array $row): string
    {
        foreach (['effective_date', 'updated_at', 'created_at'] as $key) {
            $v = trim((string)($row[$key] ?? ''));
            if ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $v, $m)) {
                return $m[0];
            }
        }
        return '';
    }

    /** 生效日期的中文展示形式（2026-11-12 → 2026 年 11 月 12 日） */
    public static function effectiveText(array $doc): string
    {
        $d = (string)($doc['effective'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return '';
        }
        return (int)$m[1] . ' 年 ' . (int)$m[2] . ' 月 ' . (int)$m[3] . ' 日';
    }

    /** 正文转义后的标题、段落与列表块 */
    public static function blocks(string $content): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($content));
        if ($text === '') {
            return [];
        }
        $out = [];
        foreach (preg_split("/\n\s*\n/", $text) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $lines = preg_split("/\n/", $chunk);
            $first = ltrim((string)$lines[0]);

            // 小标题：# 开头
            if (strpos($first, '# ') === 0) {
                $heading = trim(substr($first, 2));
                if ($heading !== '') {
                    $out[] = ['type' => 'heading', 'text' => $heading];
                }
                // 标题后紧跟的内容另起一段处理
                $rest = trim(implode("\n", array_slice($lines, 1)));
                if ($rest !== '') {
                    $out = array_merge($out, self::blocks($rest));
                }
                continue;
            }

            // 同一段里可能「说明文字 + 紧跟列表」（中间没有空行），按行切成文本段与列表段，
            // 否则整段会被当成普通段落，列表项前面的「- 」会原样显示出来。
            $buffer = [];
            $items  = [];
            foreach ($lines as $line) {
                $lineTrim = ltrim((string)$line);
                if (strpos($lineTrim, '- ') === 0) {
                    if ($buffer) {
                        $text = trim(implode("\n", $buffer));
                        if ($text !== '') {
                            $out[] = ['type' => 'para', 'text' => $text];
                        }
                        $buffer = [];
                    }
                    $item = trim(substr($lineTrim, 2));
                    if ($item !== '') {
                        $items[] = $item;
                    }
                    continue;
                }
                if ($items) {
                    $out[] = ['type' => 'list', 'items' => $items];
                    $items = [];
                }
                $buffer[] = $line;
            }
            if ($buffer) {
                $text = trim(implode("\n", $buffer));
                if ($text !== '') {
                    $out[] = ['type' => 'para', 'text' => $text];
                }
            }
            if ($items) {
                $out[] = ['type' => 'list', 'items' => $items];
            }
        }
        return $out;
    }

    /** 是否已发布内容（决定前台是否可访问） */
    public static function published(string $slot): bool
    {
        $doc = self::get($slot);
        return trim((string)$doc['content']) !== '';
    }
}
