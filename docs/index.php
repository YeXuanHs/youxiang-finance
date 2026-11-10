<?php
/**
 * 优享财务系统 · 站点文档中心
 *
 * 读取根目录 docs/ 下的 Markdown 文档，渲染为统一版式的 HTML 页面。
 * 仅允许渲染白名单内的 .md 文件，避免任意文件读取。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();

const YX_DOC_DIR = __DIR__;

/** 文档清单：文件名 => 标题 */
$docs = [
    '安装教程.md'       => '安装教程',
    '部署说明.md'       => '宝塔面板部署说明',
    '接口对接说明.md'   => '上游 / 下游接口对接说明',
];

$current = (string)($_GET['doc'] ?? '');
if ($current === '' || !isset($docs[$current])) {
    $current = '安装教程.md';
}

$filePath = YX_DOC_DIR . '/' . $current;
$markdown = is_file($filePath) ? (string)file_get_contents($filePath) : '';
if ($markdown === '') {
    http_response_code(404);
    $markdown = '# 文档不存在

请返回[文档中心](' . url('docs/') . ')重新选择。';
}

/* ---------- 极简 Markdown 渲染 ---------- */

/**
 * 图片地址白名单：http(s) 外链、站内绝对路径（/ 开头）与 ../uploads 相对路径。
 * 其它（data:、javascript:、协议相对 //）一律拒绝，返回空串。
 */
function yx_doc_image_src(string $src): string
{
    $src = trim($src);
    if ($src === '') {
        return '';
    }
    if (preg_match('~^(https?://|/(?!/)|\.\./uploads/)~i', $src)) {
        return $src;
    }
    return '';
}

function yx_doc_inline(string $text): string
{
    $text = e($text);
    // 行内代码
    $text = preg_replace_callback('~`([^`]+)`~u', function ($m) {
        return '<code>' . $m[1] . '</code>';
    }, $text);
    // 图片 ![说明](地址)：先于链接处理，否则会被当成 [说明](地址) 的普通链接
    $text = preg_replace_callback('~!\[([^\]]*)\]\(([^)\s]+)\)~u', function ($m) {
        $src = yx_doc_image_src(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if ($src === '') {
            return $m[1] !== '' ? '<em>' . $m[1] . '</em>' : '';
        }
        return '<img src="' . e($src) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $text);
    // 链接 [文本](地址) —— 仅允许 http(s) 与站内相对地址
    $text = preg_replace_callback('~\[([^\]]+)\]\(([^)\s]+)\)~u', function ($m) {
        $href = $m[2];
        if (!preg_match('~^(https?://|/|#)~i', $href)) {
            return $m[1];
        }
        $ext = preg_match('~^https?://~i', $href) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . $href . '"' . $ext . '>' . $m[1] . '</a>';
    }, $text);
    // 加粗 / 强调
    $text = preg_replace('~\*\*([^*]+)\*\*~u', '<strong>$1</strong>', $text);
    $text = preg_replace('~(?<!\*)\*([^*]+)\*(?!\*)~u', '<em>$1</em>', $text);
    return $text;
}

/** 渲染表格行单元格 */
function yx_doc_cells(string $line): array
{
    $line = trim($line);
    $line = trim($line, '|');
    return array_map('trim', explode('|', $line));
}

function yx_doc_markdown(string $md): array
{
    $md    = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $html  = [];
    $toc   = [];
    $idx   = 0;
    $total = count($lines);
    $anchor = 0;

    for ($i = 0; $i < $total; $i++) {
        $line = $lines[$i];

        // 代码块
        if (preg_match('/^\s*```(\w*)/', $line, $m)) {
            $buf = [];
            for ($i++; $i < $total && !preg_match('/^\s*```/', $lines[$i]); $i++) {
                $buf[] = $lines[$i];
            }
            $html[] = '<pre class="doc-code"><code>' . e(implode("\n", $buf)) . '</code></pre>';
            continue;
        }

        // 标题
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $line, $m)) {
            $level = strlen($m[1]);
            $text  = trim($m[2]);
            $anchor++;
            $id = 'sec-' . $anchor;
            if ($level <= 3) {
                $toc[] = ['level' => $level, 'id' => $id, 'text' => $text];
            }
            $tag = 'h' . min($level, 4);
            $html[] = '<' . $tag . ' id="' . $id . '">' . yx_doc_inline($text) . '</' . $tag . '>';
            continue;
        }

        // 分隔线
        if (preg_match('/^\s*---+\s*$/', $line)) {
            $html[] = '<hr>';
            continue;
        }

        // 表格
        if (strpos($line, '|') !== false && isset($lines[$i + 1]) && preg_match('/^\s*\|?[\s:-]+\|[\s:|-]*$/', $lines[$i + 1])) {
            $head  = yx_doc_cells($line);
            $th    = '';
            foreach ($head as $c) {
                $th .= '<th>' . yx_doc_inline($c) . '</th>';
            }
            $rows = '';
            for ($i += 2; $i < $total && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== ''; $i++) {
                $td = '';
                foreach (yx_doc_cells($lines[$i]) as $c) {
                    $td .= '<td>' . yx_doc_inline($c) . '</td>';
                }
                $rows .= '<tr>' . $td . '</tr>';
            }
            $i--;
            $html[] = '<div class="table-wrap"><table class="doc-table"><thead><tr>' . $th . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
            continue;
        }

        // 引用
        if (preg_match('/^\s*>\s?(.*)$/u', $line, $m)) {
            $buf = [$m[1]];
            for ($i++; $i < $total && preg_match('/^\s*>\s?(.*)$/u', $lines[$i], $m2); $i++) {
                $buf[] = $m2[1];
            }
            $i--;
            $html[] = '<blockquote>' . yx_doc_inline(implode(' ', $buf)) . '</blockquote>';
            continue;
        }

        // 列表（- / * / 数字. / - [ ] 任务项）
        if (preg_match('/^\s*([-*]|\d+\.)\s+/u', $line)) {
            $ordered = (bool)preg_match('/^\s*\d+\.\s+/u', $line);
            $items   = [];
            for (; $i < $total; $i++) {
                $l = $lines[$i];
                if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/u', $l, $m)) {
                    $text = $m[3];
                    if (preg_match('/^\[( |x|X)\]\s*(.*)$/u', $text, $c)) {
                        $box   = $c[1] === ' ' ? '☐' : '☑';
                        $text  = $box . ' ' . $c[2];
                        $items[] = '<li class="doc-task">' . yx_doc_inline($text) . '</li>';
                    } else {
                        $items[] = '<li>' . yx_doc_inline($text) . '</li>';
                    }
                    continue;
                }
                // 缩进续行并入上一项
                if (trim($l) !== '' && preg_match('/^\s{2,}\S/u', $l) && $items) {
                    $items[count($items) - 1] = rtrim(substr($items[count($items) - 1], 0, -5)) . ' ' . yx_doc_inline(trim($l)) . '</li>';
                    continue;
                }
                break;
            }
            $i--;
            $tag = $ordered ? 'ol' : 'ul';
            $html[] = '<' . $tag . ' class="doc-list">' . implode('', $items) . '</' . $tag . '>';
            continue;
        }

        // 空行
        if (trim($line) === '') {
            continue;
        }

        // 独占一行的图片：输出带说明的图注，避免被段落包裹后与文字挤在一行
        if (preg_match('/^\s*!\[([^\]]*)\]\(([^)\s]+)\)\s*$/u', $line, $m)) {
            $src = yx_doc_image_src(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            if ($src === '') {
                if (trim($m[1]) !== '') {
                    $html[] = '<p><em>' . yx_doc_inline($m[1]) . '</em></p>';
                }
                continue;
            }
            $html[] = '<figure class="doc-figure"><img src="' . e($src) . '" alt="' . $m[1] . '" loading="lazy">'
                . (trim($m[1]) !== '' ? '<figcaption>' . yx_doc_inline($m[1]) . '</figcaption>' : '')
                . '</figure>';
            continue;
        }

        // 普通段落（合并相邻非空行）
        $buf = [$line];
        for ($i++; $i < $total; $i++) {
            $l = $lines[$i];
            if (trim($l) === '' || preg_match('/^(#{1,4}\s|\s*```|\s*>|\s*([-*]|\d+\.)\s|\s*---+\s*$|!\[[^\]]*\]\([^)\s]+\)\s*$)/u', $l)) {
                $i--;
                break;
            }
            $buf[] = $l;
        }
        $html[] = '<p>' . yx_doc_inline(implode(' ', array_map('trim', $buf))) . '</p>';
    }

    return [$html, $toc];
}

[$blocks, $toc] = yx_doc_markdown($markdown);
$pageTitle = $docs[$current] ?? '文档中心';
$siteName  = setting('site_name', config('app.name'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e($siteName) ?></title>
<link rel="icon" href="<?= e(url('assets/logo/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/style.css') ?>">
<style>
.doc-shell{max-width:1180px;margin:0 auto;padding:24px 16px 64px;display:grid;grid-template-columns:250px minmax(0,1fr);gap:20px}
.doc-side{position:sticky;top:20px;align-self:start}
.doc-side .doc-nav{list-style:none;margin:0;padding:0}
.doc-side .doc-nav li{margin-bottom:6px}
.doc-side .doc-nav a{display:block;padding:8px 12px;border-radius:var(--radius-sm);color:var(--ink-3);background:var(--surface-3)}
.doc-side .doc-nav a.active{background:var(--primary-soft);color:var(--primary-d);font-weight:600}
.doc-side .doc-nav a:hover{text-decoration:none;background:var(--surface-2)}
.doc-toc{list-style:none;margin:10px 0 0;padding:0;font-size:13px;border-left:2px solid var(--line)}
.doc-toc li{margin:0}
.doc-toc a{display:block;padding:4px 10px;color:var(--muted);border-left:2px solid transparent;margin-left:-2px}
.doc-toc a:hover{color:var(--primary);border-left-color:var(--primary);text-decoration:none}
.doc-toc .lv3 a{padding-left:22px}
.doc-body{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:26px 30px;box-shadow:var(--shadow);line-height:1.8}
.doc-body h1{font-size:24px;margin:0 0 18px}
.doc-body h2{font-size:19px;margin:30px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--line)}
.doc-body h3{font-size:16px;margin:22px 0 10px}
.doc-body h4{font-size:14px;margin:18px 0 8px}
.doc-body code{background:var(--surface-2);padding:2px 6px;border-radius:5px;font-size:13px}
.doc-code{background:#0f172a;color:#e6edf7;padding:14px 16px;border-radius:var(--radius-sm);overflow:auto;margin:12px 0}
.doc-code code{background:none;padding:0;color:inherit;font-size:13px;line-height:1.6}
.doc-body blockquote{margin:12px 0;padding:10px 14px;background:var(--surface-3);border-left:3px solid var(--primary);color:var(--ink-2);border-radius:0 var(--radius-sm) var(--radius-sm) 0}
.doc-table{width:100%;border-collapse:collapse;margin:12px 0;font-size:13.5px}
.doc-table th,.doc-table td{border:1px solid var(--line);padding:9px 12px;text-align:left}
.doc-table th{background:var(--surface-3);font-weight:600}
.doc-list{margin:10px 0;padding-left:22px}
.doc-list li{margin:5px 0}
.doc-task{list-style:none;margin-left:-18px}
.doc-body hr{border:0;border-top:1px solid var(--line);margin:26px 0}
.doc-body a{color:var(--primary)}
/* 文档插图 */
.doc-body img{max-width:100%;height:auto;border-radius:var(--radius-sm);border:1px solid var(--line);vertical-align:middle}
.doc-figure{margin:16px 0 18px;text-align:center}
.doc-figure img{display:block;margin:0 auto;box-shadow:0 6px 20px rgba(16,24,40,.08)}
.doc-figure figcaption{margin-top:8px;font-size:12.5px;color:var(--muted)}
.doc-fig-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media (max-width:640px){.doc-fig-row{grid-template-columns:1fr}}
@media (max-width:900px){
  .doc-shell{grid-template-columns:1fr;padding-top:14px}
  .doc-side{position:static}
  .doc-toc{display:none}
  .doc-body{padding:18px}
}
</style>
</head>
<body>
<div class="doc-shell">
    <aside class="doc-side">
        <div class="card" style="padding:14px">
            <div class="muted small" style="margin-bottom:8px">站点文档</div>
            <ul class="doc-nav">
                <?php foreach ($docs as $name => $title): ?>
                    <li><a class="<?= $name === $current ? 'active' : '' ?>" href="<?= e(url('docs/?doc=' . rawurlencode($name))) ?>"><?= e($title) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="muted small" style="margin:14px 0 6px">本页目录</div>
            <ul class="doc-toc">
                <?php foreach ($toc as $item): ?>
                    <li class="lv<?= (int)$item['level'] ?>"><a href="#<?= e($item['id']) ?>"><?= e($item['text']) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <p class="muted small" style="margin:14px 0 0"><a href="<?= e(url('/')) ?>">← 返回首页</a></p>
        </div>
    </aside>
    <main class="doc-body">
        <?= implode("\n", $blocks) ?>
    </main>
</div>
</body>
</html>
