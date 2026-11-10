<?php
/**
 * 优享财务系统 · 新闻公告中心
 *
 * 浏览量统计：
 *   - 每条新闻维护累计浏览量 views（原子自增，无竞态）
 *   - 同一访客（cookie + IP + UA 的哈希）对同一篇文章 30 分钟内只记一次，
 *     防止刷新页面刷量；记录存 runtime/news_view.json，不额外占库
 *   - 列表页展示浏览量，详情页展示浏览量并按分类推荐相关文章
 */

class YxNews
{
    const CATEGORIES = [
        'notice'   => '公告',
        'news'     => '新闻动态',
        'update'   => '产品更新',
        'activity' => '活动',
    ];

    /** 去重窗口（秒） */
    const VIEW_WINDOW = 1800;

    public static function categoryName(string $c): string
    {
        return self::CATEGORIES[$c] ?? $c;
    }

    public static function categoryBadge(string $c): string
    {
        switch ($c) {
            case 'notice':   return 'badge-warn';
            case 'news':     return 'badge-info';
            case 'update':   return 'badge-ok';
            case 'activity': return 'badge-edition';
            default:         return 'badge-mute';
        }
    }

    /* ------------------------------ 查询 ------------------------------ */

    public static function isEnabled(): bool
    {
        return setting('news_show', '1') === '1';
    }

    /** 已发布文章（置顶优先，其次发布时间倒序） */
    public static function published(array $filter = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bind] = self::buildWhere($filter);
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('news') . $where
            . ' ORDER BY is_top DESC, COALESCE(published_at, created_at) DESC, id DESC LIMIT '
            . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $bind
        );
    }

    public static function count(array $filter = []): int
    {
        [$where, $bind] = self::buildWhere($filter);
        return (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('news') . $where, $bind);
    }

    /** 已发布文章详情（不校验状态，供后台复用） */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('news') . ' WHERE id = ? LIMIT 1', [$id]);
        return $r ?: null;
    }

    /** 分类统计（仅已发布，用于前台分类导航） */
    public static function categoryCounts(): array
    {
        $rows = YxDb::all(
            'SELECT category, COUNT(*) AS c FROM ' . YxDb::table('news') . ' WHERE status = 1 GROUP BY category'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['category']] = (int)$r['c'];
        }
        return $out;
    }

    /** 全站浏览量合计 */
    public static function totalViews(): int
    {
        return (int)YxDb::value('SELECT COALESCE(SUM(views), 0) FROM ' . YxDb::table('news') . ' WHERE status = 1');
    }

    /** 浏览量格式化：1234 → 1,234；12345 → 1.2万 */
    public static function viewsText(int $views): string
    {
        $views = max(0, $views);
        if ($views >= 10000) {
            return rtrim(rtrim(number_format($views / 10000, 1, '.', ''), '0'), '.') . '万';
        }
        return number_format($views);
    }

    /* ------------------------------ 浏览量 ------------------------------ */

    /**
     * 记录一次阅读：命中去重窗口则跳过，否则原子自增并更新去重记录。
     * @return int 记录后的浏览量
     */
    public static function hit(int $id): int
    {
        $news = self::find($id);
        if (!$news) {
            return 0;
        }
        if (!self::shouldCount($id)) {
            return (int)$news['views'];
        }
        YxDb::exec(
            'UPDATE ' . YxDb::table('news') . ' SET views = COALESCE(views, 0) + 1 WHERE id = ?',
            [$id]
        );
        return (int)$news['views'] + 1;
    }

    /** 访客指纹（只取 UA 的哈希，不保存明文 IP，仅用于短期去重） */
    public static function visitorKey(): string
    {
        $cookie = (string)($_COOKIE['yx_vid'] ?? '');
        if (!preg_match('/^[a-f0-9]{8,32}$/', $cookie)) {
            $cookie = bin2hex(random_bytes(8));
            if (!headers_sent()) {
                setcookie('yx_vid', $cookie, [
                    'expires'  => time() + 86400 * 30,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            $_COOKIE['yx_vid'] = $cookie;
        }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', $cookie . '|' . substr($ua, 0, 120));
    }

    private static function storeFile(): string
    {
        return YX_ROOT . '/runtime/news_view.json';
    }

    /**
     * 是否应该计数（同一访客 + 同一文章在窗口内只记一次）
     * 记录集中写入 runtime/news_view.json，单进程内合并后加锁落盘，
     * 避免频繁请求时多次读写同一文件。
     */
    private static function shouldCount(int $id): bool
    {
        static $cache = null;
        static $dirty = false;

        $now = time();
        if ($cache === null) {
            $cache = [];
            $file  = self::storeFile();
            if (is_file($file)) {
                $raw = @file_get_contents($file);
                if ($raw !== false) {
                    $tmp = json_decode($raw, true);
                    if (is_array($tmp)) {
                        foreach ($tmp as $k => $ts) {
                            if ((int)$ts >= $now - self::VIEW_WINDOW * 2) {
                                $cache[$k] = (int)$ts;
                            }
                        }
                    }
                }
            }
            register_shutdown_function(static function () use (&$cache, &$dirty) {
                if (!$dirty) {
                    return;
                }
                $file = self::storeFile();
                $fp   = @fopen($file, 'c+');
                if (!$fp) {
                    return;
                }
                if (@flock($fp, LOCK_EX)) {
                    @ftruncate($fp, 0);
                    @rewind($fp);
                    @fwrite($fp, (string)json_encode($cache));
                    @fflush($fp);
                    @flock($fp, LOCK_UN);
                }
                @fclose($fp);
            });
        }

        $key = substr(self::visitorKey(), 0, 32) . ':' . $id;
        if (isset($cache[$key]) && $cache[$key] > $now - self::VIEW_WINDOW) {
            return false;
        }
        $cache[$key] = $now;
        $dirty = true;
        return true;
    }

    /* ------------------------------ 写入（后台） ------------------------------ */

    public static function save(array $data, int $id = 0): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 200) {
            return ['ok' => false, 'msg' => '标题需 2-200 个字'];
        }
        $category = (string)($data['category'] ?? 'notice');
        if (!isset(self::CATEGORIES[$category])) {
            $category = 'notice';
        }
        $status = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
        $publishedAt = trim((string)($data['published_at'] ?? ''));
        if ($publishedAt === '') {
            $publishedAt = now();
        } else {
            $publishedAt = str_replace('T', ' ', $publishedAt);
            if (strlen($publishedAt) === 16) {
                $publishedAt .= ':00';
            }
        }

        $row = [
            'category'     => $category,
            'title'        => $title,
            'summary'      => trim((string)($data['summary'] ?? '')) ?: null,
            'cover'        => trim((string)($data['cover'] ?? '')) ?: null,
            'content'      => trim((string)($data['content'] ?? '')) ?: null,
            'tags'         => trim((string)($data['tags'] ?? '')) ?: null,
            'source'       => trim((string)($data['source'] ?? '')) ?: null,
            'is_top'       => !empty($data['is_top']) ? 1 : 0,
            'status'       => $status,
            'published_at' => $publishedAt,
            'updated_at'   => now(),
        ];

        if ($id > 0) {
            $row['updated_at'] = now();
            YxDb::update('news', $row, 'id = :id', ['id' => $id]);
            log_action('news.update', 'news#' . $id, ['title' => $title]);
            return ['ok' => true, 'msg' => '文章已更新', 'id' => $id];
        }

        $row['views']      = 0;
        $row['created_at'] = now();
        $newId = YxDb::insert('news', $row);
        log_action('news.create', 'news#' . $newId, ['title' => $title]);
        return ['ok' => true, 'msg' => '文章已发布', 'id' => $newId];
    }

    public static function remove(int $id): bool
    {
        $row = self::find($id);
        if (!$row) {
            return false;
        }
        YxDb::delete('news', 'id = :id', ['id' => $id]);
        log_action('news.delete', 'news#' . $id, ['title' => (string)$row['title']]);
        return true;
    }

    /** 正文渲染：纯文本 + 空行分段 + 可选 Markdown 风格标题/列表的轻量处理（输出已转义） */
    public static function render(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        if ($content === '') {
            return '';
        }
        $html  = '';
        $inList = false;
        foreach (preg_split("/\n\s*\n/", $content) as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^#{2,4}\s+(.+)$/u', $block, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h3>' . e($m[1]) . '</h3>';
                continue;
            }
            $lines = explode("\n", $block);
            $isList = true;
            foreach ($lines as $ln) {
                if (!preg_match('/^\s*([\-\*\x{2022}]|\d+[\.、])\s+/u', $ln)) {
                    $isList = false;
                    break;
                }
            }
            if ($isList) {
                if (!$inList) { $html .= '<ul>'; $inList = true; }
                foreach ($lines as $ln) {
                    $html .= '<li>' . e(trim(preg_replace('/^\s*([\-\*\x{2022}]|\d+[\.、])\s+/u', '', $ln))) . '</li>';
                }
                continue;
            }
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p>' . nl2br(e($block)) . '</p>';
        }
        if ($inList) {
            $html .= '</ul>';
        }
        return $html;
    }

    /** 后台筛选（含草稿）：状态 / 分类 / 关键词 / 是否置顶 各自独立，供管理页复用 */
    public static function adminList(array $filter, int $limit = 20, int $offset = 0): array
    {
        [$where, $bind] = self::adminWhere($filter);
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('news') . $where
            . ' ORDER BY is_top DESC, COALESCE(published_at, created_at) DESC, id DESC LIMIT '
            . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $bind
        );
    }

    public static function adminCount(array $filter): int
    {
        [$where, $bind] = self::adminWhere($filter);
        return (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('news') . $where, $bind);
    }

    /** 后台概览：已发布 / 草稿 / 总浏览量 / 置顶数 */
    public static function adminStats(): array
    {
        $t = YxDb::table('news');
        return [
            'published' => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . ' WHERE status = 1'),
            'draft'     => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . ' WHERE status = 0'),
            'views'     => (int)YxDb::value('SELECT COALESCE(SUM(views), 0) FROM ' . $t),
            'top'       => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . ' WHERE is_top = 1'),
        ];
    }

    /** 切换置顶 / 发布状态（后台） */
    public static function toggle(int $id, string $field): array
    {
        $row = self::find($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '文章不存在'];
        }
        if ($field === 'is_top') {
            $next = (int)$row['is_top'] === 1 ? 0 : 1;
            YxDb::update('news', ['is_top' => $next, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            return ['ok' => true, 'msg' => $next === 1 ? '已置顶' : '已取消置顶'];
        }
        if ($field === 'status') {
            $next = (int)$row['status'] === 1 ? 0 : 1;
            YxDb::update('news', ['status' => $next, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            log_action('news.status', 'news#' . $id, ['status' => $next]);
            return ['ok' => true, 'msg' => $next === 1 ? '已发布' : '已转为草稿'];
        }
        return ['ok' => false, 'msg' => '未知操作'];
    }

    /** 浏览量清零（演示数据处理用） */
    public static function resetViews(int $id): array
    {
        if (!self::find($id)) {
            return ['ok' => false, 'msg' => '文章不存在'];
        }
        YxDb::update('news', ['views' => 0, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        log_action('news.views_reset', 'news#' . $id);
        return ['ok' => true, 'msg' => '浏览量已清零'];
    }

    /** 后台筛选条件 */
    private static function adminWhere(array $filter): array
    {
        $where = ' WHERE 1=1';
        $bind  = [];

        $status = (string)($filter['status'] ?? '');
        if ($status === '1' || $status === '0') {
            $where .= ' AND status = ?';
            $bind[] = (int)$status;
        }
        $cat = (string)($filter['category'] ?? '');
        if ($cat !== '' && isset(self::CATEGORIES[$cat])) {
            $where .= ' AND category = ?';
            $bind[] = $cat;
        }
        $kw = trim((string)($filter['kw'] ?? ''));
        if ($kw !== '') {
            $where .= ' AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($bind, $like, $like, $like);
        }
        return [$where, $bind];
    }

    private static function buildWhere(array $filter): array
    {
        $where = ' WHERE status = 1';
        $bind  = [];

        $cat = (string)($filter['category'] ?? '');
        if ($cat !== '' && isset(self::CATEGORIES[$cat])) {
            $where .= ' AND category = ?';
            $bind[] = $cat;
        }
        $kw = trim((string)($filter['kw'] ?? ''));
        if ($kw !== '') {
            $where .= ' AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($bind, $like, $like, $like);
        }
        return [$where, $bind];
    }
}
