<?php
/**
 * 优享财务系统 · 上游应用市场（支付网关）目录同步
 *
 * 把上游应用市场 appType=gateway 的商品以「可上架条目」幂等写入商店：
 *   - 按规范化商品名比对现有 yx_app，已存在的商品不动（避免与历史人工定价/描述冲突）；
 *   - 新增条目的 key 固定为 zjmf-gw-{上游ID}，重跑只更新内容字段，不动下载量/销量/上架时间；
 *   - 分类固定 pay（支付通道），类型 plugin，认证类型 thirdparty（上游官方 ≠ 本站官方，不冒用）；
 *   - 价格取上游市场价（product_price），上游 0 元按免费入库；
 *   - 版本报价 price_options 按普惠/专业/企业三档写入同一上游价（上游未提供按版本差异化定价，
 *     不能臆造档位差价），保证三档授权都能在详情页切换与下单；
 *   - 不写 package_path：上游应用市场是授权分发，本站没有可托管的安装包，
 *     详情页与下载接口按「未托管」提示并跳转原始地址，不做假下载。
 *
 * 用法：
 *   php tools/import_gateway_market.php --file=/tmp/gw_all.json --dry-run   # 预览
 *   php tools/import_gateway_market.php --file=/tmp/gw_all.json            # 正式导入
 *   php tools/import_gateway_market.php --file=... --limit=5               # 只处理前 N 条
 *   php tools/import_gateway_market.php --file=... --conf=/path/conf.php    # 指定配置（测试库）
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('该脚本仅支持命令行执行');
}

$root = dirname(__DIR__);
$args = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}
if (!empty($args['conf']) && is_file((string)$args['conf'])) {
    define('YX_CONF_OVERRIDE', require (string)$args['conf']);
}

require_once $root . '/includes/bootstrap.php';

const GW_CAT       = 'pay';
const GW_CAT_NAME  = '支付通道';
const GW_SOURCE    = 'https://my.idcsmart.com/shop/shop_app.html?appType=gateway';
const GW_CERT_NAME = ['company' => '企业认证', 'person' => '个人认证', 'official' => '官方自营'];

$file   = (string)($args['file'] ?? '/tmp/gw_all.json');
$dryRun = isset($args['dry-run']);
$limit  = isset($args['limit']) ? max(0, (int)$args['limit']) : 0;

echo "上游应用市场目录同步（支付网关）\n";
echo '数据库：' . YxDb::driver() . '  数据源：' . $file . '  ' . ($dryRun ? "模式：dry-run（不写库）\n" : "模式：正式导入\n");
echo str_repeat('-', 62) . "\n";

$raw = is_file($file) ? (string)file_get_contents($file) : '';
$items = json_decode($raw, true);
if (!is_array($items) || !$items) {
    exit("无法读取上游商品 JSON（{$file}），终止。\n");
}
if ($limit > 0) {
    $items = array_slice($items, 0, $limit);
}
echo '上游商品：' . count($items) . " 个\n";

/** 名称归一：忽略大小写、空白与常见标点，用于和历史条目去重 */
$norm = static function ($s): string {
    $s = mb_strtolower(trim((string)$s));
    return (string)preg_replace('/[\s\-_（）()【】\[\]·,，。.、\/\\\\|:：;；\'"“”]+/u', '', $s);
};

/* 现有商品名（含已下架，防止重复上架同名商品） */
$nameIndex = [];
foreach (YxDb::all('SELECT id, `key`, name FROM ' . YxDb::table('app')) as $r) {
    $nameIndex[$norm($r['name'])] = $r;
}

$certNames = GW_CERT_NAME;
$stats = ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'fail' => 0, 'free' => 0, 'paid' => 0];

/* 同一批里同名商品（不同供应商）用供应商名消歧，避免列表出现两张完全同名的卡片 */
$seenName = [];
foreach ($items as $it) {
    $n = $norm($it['name'] ?? '');
    if ($n !== '') {
        $seenName[$n] = ($seenName[$n] ?? 0) + 1;
    }
}

$plan = [];
foreach ($items as $it) {
    $stats['total']++;
    $uid  = (int)($it['id'] ?? 0);
    $name = trim((string)($it['name'] ?? ''));
    if ($uid <= 0 || $name === '') {
        $stats['fail']++;
        continue;
    }
    $n = $norm($name);
    if (isset($nameIndex[$n])) {
        // 已存在的同名商品：不动价格与描述（可能是人工定的价）
        $stats['skip']++;
        $plan[] = ['action' => 'skip', 'uid' => $uid, 'name' => $name, 'why' => '已存在 ' . $nameIndex[$n]['key']];
        continue;
    }
    if (($seenName[$n] ?? 0) > 1) {
        $vendor = trim((string)($it['nickname'] ?? ''));
        if ($vendor !== '' && mb_strpos($name, $vendor) === false) {
            $name .= '（' . $vendor . '）';
        }
    }

    $price = round_money((float)($it['product_price'] ?? 0));
    $bill  = $price > 0 ? 'once' : 'free';
    $cert  = (string)($it['certify_type'] ?? '');
    $vendor = trim((string)($it['nickname'] ?? ''));
    $activity = (int)($it['activity'] ?? 0);

    $opts = [];
    foreach (['basic', 'pro', 'enterprise'] as $ed) {
        $opts[] = [
            'edition'   => $ed,
            'label'     => YxEdition::name($ed),
            'price'     => $price,
            'bill_type' => $bill,
            'days'      => 0,
        ];
    }

    $desc = '上游应用市场（智简魔方）支付网关 · 目录同步'
        . "\n供应商：" . ($vendor !== '' ? $vendor : '未标注') . (isset($certNames[$cert]) ? '（' . $certNames[$cert] . '）' : '')
        . "\n上游报价：" . ($price > 0 ? money($price, true) . '（一次性）' : '免费')
        . ($activity > 0 ? "\n上游热度：" . $activity : '')
        . "\n说明：本站仅同步上游目录与报价，未托管该插件的安装包；购买/使用前请确认与优享财务系统的兼容性，并向上游供应商确认授权方式。"
        . "\n上游目录：" . GW_SOURCE;

    $row = [
        'category_key'  => GW_CAT,
        'name'          => mb_substr($name, 0, 120),
        'version'       => '1.0.0',
        'author'        => $vendor !== '' ? mb_substr($vendor, 0, 80) : '第三方',
        'price'         => $price,
        'description'   => $desc,
        'changelog'     => '',
        'edition_min'   => 'basic',
        'compatible'    => '智简魔方财务系统（zjmf）支付网关',
        'author_type'   => 'thirdparty',
        'type'          => 'plugin',
        'source'        => GW_SOURCE,
        'bill_type'     => $bill,
        'points_price'  => 0,
        'price_options' => json_encode($opts, JSON_UNESCAPED_UNICODE),
        'language'      => '简体中文',
        'status'        => 'on',
        'updated_at'    => now(),
    ];

    $plan[] = ['action' => 'upsert', 'uid' => $uid, 'name' => $name, 'row' => $row, 'price' => $price];
    $price > 0 ? $stats['paid']++ : $stats['free']++;
}

if (!$dryRun) {
    YxSchema::sync(YxDb::pdo(), YxDb::driver());
    if (!YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_category') . ' WHERE `key` = ?', [GW_CAT])) {
        YxDb::insert('app_category', ['key' => GW_CAT, 'name' => GW_CAT_NAME, 'icon' => '💳', 'desc' => '支付通道与支付网关', 'sort' => 10, 'status' => 1]);
        echo "已创建分类：" . GW_CAT_NAME . "\n";
    }
}

foreach ($plan as $p) {
    if ($p['action'] === 'skip') {
        echo "  [跳过] #{$p['uid']} {$p['name']}（{$p['why']}）\n";
        continue;
    }
    $key = 'zjmf-gw-' . $p['uid'];
    if ($dryRun) {
        echo "  [dry] #{$p['uid']} {$key}  " . ($p['price'] > 0 ? money($p['price'], true) : '免费') . "  {$p['name']}\n";
        continue;
    }
    try {
        $exists = YxDb::one('SELECT id FROM ' . YxDb::table('app') . ' WHERE `key` = ?', [$key]);
        if ($exists) {
            YxDb::update('app', $p['row'], 'id = :id', ['id' => (int)$exists['id']]);
            $stats['update']++;
            echo "  [更新] {$key}  " . $p['name'] . "\n";
        } else {
            $row = $p['row'];
            $row['key']          = $key;
            $row['downloads']    = 0;
            $row['sales']        = 0;
            $row['featured']     = 0;
            $row['created_at']   = now();
            $row['published_at'] = now();
            YxDb::insert('app', $row);
            $stats['create']++;
            echo "  [新增] {$key}  " . $p['name'] . "\n";
        }
    } catch (Throwable $e) {
        $stats['fail']++;
        echo "  [失败] {$key}  " . $e->getMessage() . "\n";
    }
}

echo str_repeat('-', 62) . "\n";
printf(
    "合计 %d 条：新增 %d，更新 %d，跳过（已存在同名）%d，失败 %d；其中免费 %d，付费 %d\n",
    $stats['total'], $stats['create'], $stats['update'], $stats['skip'], $stats['fail'], $stats['free'], $stats['paid']
);
if (!$dryRun && ($stats['create'] + $stats['update']) > 0) {
    log_action('store.import_gateway', 'gateway', ['create' => $stats['create'], 'update' => $stats['update']]);
}
