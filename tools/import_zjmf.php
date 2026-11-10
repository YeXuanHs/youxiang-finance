<?php
/**
 * 优享财务系统 · 魔方(zjmf)第三方插件导入器
 *
 * 从 aazooo/zjmf-manager-plugins 读取清单，下载 zip 到 uploads/store/，
 * 做静态安全扫描，并以上架条目写入商店（分类 zjmf=第三方插件）。
 * 仅「托管下载」，不提供安装/授权，不执行任何第三方代码。
 *
 * 用法：
 *   php tools/import_zjmf.php --dry-run      # 解析+下载+扫描，不写库
 *   php tools/import_zjmf.php                # 正式导入（幂等，按 key 更新）
 *   php tools/import_zjmf.php --limit=5      # 仅处理前 N 个（测试）
 *   php tools/import_zjmf.php --no-download  # 使用已存在的 zip
 *   php tools/import_zjmf.php --conf=/path/conf.php  # 指定配置（测试用临时库）
 */

$root = dirname(__DIR__);
$args = [];
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}
$dryRun = isset($args['dry-run']);
$noDl   = isset($args['no-download']);
$limit  = isset($args['limit']) ? max(0, (int)$args['limit']) : 0;
if (!empty($args['conf']) && is_file((string)$args['conf'])) {
    define('YX_CONF_OVERRIDE', require (string)$args['conf']);
}

require_once $root . '/includes/bootstrap.php';

const ZJMF_README   = 'https://raw.githubusercontent.com/aazooo/zjmf-manager-plugins/main/README.md';
const ZJMF_CAT      = 'zjmf';
const ZJMF_CAT_NAME = '第三方插件（魔方）';

echo "优享财务系统 · 魔方插件导入\n";
echo '数据库：' . YxDb::driver() . '  根目录：' . $root . "\n";
echo $dryRun ? "模式：dry-run（不写库）\n" : "模式：正式导入\n";
echo "----------------------------------------\n";

if (!class_exists('ZipArchive')) {
    exit("缺少 zip 扩展，无法扫描。\n");
}

$storeDir = $root . '/uploads/store';
if (!is_dir($storeDir) && !@mkdir($storeDir, 0755, true)) {
    exit("无法创建目录：{$storeDir}\n");
}

/* 1. 拉取并解析清单 */
$readme = http_get(ZJMF_README, 25);
if (!$readme) {
    exit("无法获取清单 README（网络或 GitHub 受限）\n");
}
$items = [];
$re    = '/^\|\s*\[([^\]]+)\]\((https?:\/\/[^)\s]+\.zip)\)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*(?:\[[^\]]*\]\(([^)]+)\))?\s*\|\s*$/m';
if (preg_match_all($re, $readme, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) {
        $zipUrl = trim($m[2]);
        $seg    = '';
        if (preg_match('#/plugins/([^/]+)/#', $zipUrl, $sm)) {
            $seg = $sm[1];
        }
        $items[] = [
            'id'        => trim($m[1]),
            'zip'       => $zipUrl,
            'name'      => trim($m[3]),
            'version'   => trim($m[4]),
            'date'      => trim($m[5]),
            'developer' => trim($m[6]),
            'intro'     => isset($m[7]) ? trim($m[7]) : '',
            'seg'       => $seg,
        ];
    }
}
echo '解析到插件：' . count($items) . " 个\n";
if (!$items) {
    exit("清单解析为空，终止。\n");
}
if ($limit > 0) {
    $items = array_slice($items, 0, $limit);
    echo "限制处理：{$limit} 个\n";
}

$segName = [
    'addon' => '插件', 'server' => '模块', 'gateway' => '支付接口',
    'sms' => '短信接口', 'mail' => '邮件接口', 'certification' => '实名接口',
];

/* 高信号后门/危险特征 */
$patterns = [
    'eval_paren'      => '/\beval\s*\(/i',
    'assert_paren'    => '/\bassert\s*\(/i',
    'base64_decode'   => '/base64_decode\s*\(/i',
    'gzinflate'       => '/gzinflate\s*\(/i',
    'gzuncompress'    => '/gzuncompress\s*\(/i',
    'shell_exec'      => '/\b(system|exec|shell_exec|passthru|popen|proc_open)\s*\(/i',
    'file_put'        => '/file_put_contents\s*\(/i',
    'curl_exec'       => '/curl_exec\s*\(/i',
    'create_function' => '/create_function\s*\(/i',
    'preg_e'          => '/preg_replace\s*\(\s*[\'"][^\'"]*\/e[\'"]/i',
    'hex_escape'      => '/\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}/i',
];
$highSignal = ['eval_paren', 'assert_paren', 'base64_decode', 'gzinflate', 'create_function', 'preg_e', 'hex_escape'];

/* 2. 确保分类存在 */
if (!$dryRun) {
    YxSchema::sync(YxDb::pdo(), YxDb::driver());
    if (!YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_category') . ' WHERE `key` = ?', [ZJMF_CAT])) {
        YxDb::insert('app_category', ['key' => ZJMF_CAT, 'name' => ZJMF_CAT_NAME, 'icon' => '📦', 'desc' => '魔方财务系统第三方插件（仅供下载，非优享原生应用）', 'sort' => 80, 'status' => 1]);
        echo "已创建分类：" . ZJMF_CAT_NAME . "\n";
    }
}

/* 3. 逐个下载 + 扫描 + 写库 */
$ok = 0; $fail = 0; $high = [];
$report = "优享财务系统 · 魔方插件静态扫描报告（" . now() . "）\n\n";

foreach ($items as $it) {
    $key  = ZJMF_CAT . '-' . strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '', $it['id']));
    $file = $storeDir . '/' . $key . '.zip';

    if (!$noDl && (!is_file($file) || filesize($file) < 100)) {
        $bin = http_get($it['zip'], 60);
        if (!$bin || strlen($bin) < 100) {
            echo "  [失败] 下载 {$it['id']}\n";
            $fail++;
            continue;
        }
        file_put_contents($file, $bin);
    }
    if (!is_file($file)) {
        echo "  [跳过] 缺少文件 {$it['id']}\n";
        $fail++;
        continue;
    }

    /* 扫描 */
    $findings = [];
    $phpFiles = 0;
    $zip = new ZipArchive();
    if ($zip->open($file) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm = (string)$zip->getNameIndex($i);
            if (!preg_match('/\.(php|inc|phtml)$/i', $nm)) {
                continue;
            }
            $phpFiles++;
            $src = (string)$zip->getFromIndex($i);
            if ($src === '' || strlen($src) > 2 * 1024 * 1024) {
                continue;
            }
            foreach ($patterns as $label => $regex) {
                if (preg_match($regex, $src)) {
                    $findings[$label][] = $nm;
                }
            }
        }
        $zip->close();
    } else {
        echo "  [警告] 无法打开 zip：{$it['id']}\n";
    }

    $score = 0;
    foreach ($findings as $label => $files) {
        $score += (in_array($label, $highSignal, true) ? 3 : 1) * min(count($files), 5);
    }
    $isHigh = $score >= 8
        || isset($findings['eval_paren'])
        || isset($findings['preg_e'])
        || isset($findings['create_function'])
        || isset($findings['hex_escape']);
    if ($isHigh) {
        $high[] = $it['id'] . '（score=' . $score . '）';
    }

    $r = "· {$it['id']}  文件{$phpFiles}  风险分{$score}" . ($isHigh ? '  ⚠高' : '') . "\n";
    foreach ($findings as $label => $files) {
        $r .= "    - {$label} × " . count($files) . ' 例：' . implode(', ', array_slice(array_unique($files), 0, 3)) . "\n";
    }
    $report .= $r;

    $desc = "第三方资源 · 魔方财务系统" . ($segName[$it['seg']] ?? $it['seg']) . "\n"
        . "开发者：{$it['developer']}　更新：{$it['date']}\n"
        . ($it['intro'] !== '' ? "介绍页面：{$it['intro']}\n" : '')
        . "说明：本站仅提供下载托管，未提供优享财务系统下的安装与授权支持；属第三方代码，使用前请自行评估安全风险。"
        . ($isHigh ? "\n⚠ 静态扫描提示存在可疑特征（风险分 {$score}），请谨慎使用。" : '');

    if ($dryRun) {
        echo "  [dry] {$it['id']}  " . round(filesize($file) / 1024) . "KB  风险分{$score}" . ($isHigh ? ' ⚠' : '') . "\n";
        $ok++;
        continue;
    }

    $row = [
        'category_key' => ZJMF_CAT,
        'name'         => $it['name'] !== '' ? $it['name'] : $it['id'],
        'version'      => $it['version'] !== '' ? $it['version'] : '1.0.0',
        'author'       => $it['developer'] !== '' ? $it['developer'] : '第三方',
        'price'        => 0,
        'description'  => $desc,
        'changelog'    => '',
        'edition_min'  => 'basic',
        'compatible'   => '魔方财务系统 (zjmf)',
        'developer_id' => 0,
        'author_type'  => 'thirdparty',
        'type'         => 'plugin',
        'source'       => $it['zip'],
        'package_path' => 'store/' . $key . '.zip',
        'package_size' => (int)filesize($file),
        'status'       => 'on',
        'updated_at'   => now(),
    ];

    $exists = YxDb::one('SELECT id FROM ' . YxDb::table('app') . ' WHERE `key` = ?', [$key]);
    if ($exists) {
        YxDb::update('app', $row, 'id = :id', ['id' => (int)$exists['id']]);
    } else {
        $row['key']        = $key;
        $row['downloads']  = 0;
        $row['sales']      = 0;
        $row['featured']   = 0;
        $row['created_at'] = now();
        YxDb::insert('app', $row);
    }
    $ok++;
    echo "  [OK] {$it['id']}  →  {$key}\n";
}

$report .= "\n高可疑条目（" . count($high) . "）：" . ($high ? implode('，', $high) : '无') . "\n";
@file_put_contents($root . '/runtime/zjmf_scan_report.txt', $report);

echo "----------------------------------------\n";
echo "完成：成功 {$ok}，失败/跳过 {$fail}，高可疑 " . count($high) . " 个\n";
echo "扫描报告：runtime/zjmf_scan_report.txt\n";
if ($high) {
    echo "⚠ 高可疑（前20）：" . implode('，', array_slice($high, 0, 20)) . (count($high) > 20 ? ' …' : '') . "\n";
}
