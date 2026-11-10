<?php
/**
 * 优享财务系统 · 上游商品上架（同步到产品中心）
 *
 * 把已对接上游的商品幂等写入 yx_product + yx_upstream_map：
 *   - 先按 (upstream_id, remote_code) 找映射，找不到再按产品编码 up{上游ID}-{编码} 复用产品；
 *   - 上游商品消失时默认下架对应产品与映射（不删数据，便于人工处理），加 --keep-missing 可保留；
 *   - 已有产品默认不改售价（避免覆盖人工定价），需要覆盖时加 --force-price。
 *
 * 价格口径：本站产品价格是「月价」，下单时由 YxBusiness::cyclePrice() 按月数相乘
 * （年付 = 月价 × 12 × 0.9）。上游按周期给价（如年价 399），必须折算成月价 33.25 入库，
 * 否则会被再乘 12 个月。年付商品因此按「年价 / 12」入库，上游周期写在商品说明里。
 *
 * 周期口径：上游按年计费的商品（months >= 12）只开放年付（product.cycles = year），
 * 因为上游按整年收费，按月价卖一个月等于倒贴 11/12；其余商品四周期全开（cycles 为空）。
 * 若要人工放开周期，直接在后台改产品的 cycles 字段即可，本工具只在 --force-price 时覆盖它。
 *
 * 库存口径：上游 stock_control=1 时 qty 是真实余量，直接落到本地库存；
 * stock_control=0 表示上游不做库存控制，本地记为 -1（不限量）。
 * 价格为 0 或库存为 0 的商品仍入库但 status=0：下单端不允许 0 元订单与无库存订单。
 *
 * 开通口径：本工具只做商品与映射，auto_setup 一律 0（人工开通），
 * 因为开通/续费需要上游开放写接口，只读的商品接口无法代替。
 *
 * 用法：
 *   php tools/import_upstream.php --upstream=3 --dry-run      # 预览，不写库
 *   php tools/import_upstream.php --upstream=3                # 正式同步
 *   php tools/import_upstream.php --upstream=3 --group=八彩云计算 --force-price
 *   php tools/import_upstream.php --upstream=3 --limit=5      # 只处理前 N 个（调试）
 */

// 仅当本文件是被直接请求/执行的入口时才禁止 Web 访问；
// 被 admin/upstream.php 等页面 include 时只提供上面的函数，不能在此中断请求。
if (PHP_SAPI !== 'cli'
    && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    exit('该脚本仅支持命令行执行');
}

/* ------------------------------------------------------------ 映射规则（纯函数） */

/**
 * 上游周期文案折算成月数
 *
 * 覆盖本上游实际出现的写法：1个月 / 月 / 1个月-无优惠 / 一年 / 年 / 空 / 0。
 * 认不出的一律按 1 个月处理（宁可少乘，也不要把年价按月价再乘 12）。
 */
function yx_import_cycle_months(string $text): int
{
    $t = trim($text);
    if ($t === '' || $t === '0') {
        return 1;
    }
    // 英文周期码（魔方购物车接口的 billingcycle 等）先按码表换算
    $en = [
        'month' => 1, 'monthly' => 1, 'mo' => 1,
        'quarter' => 3, 'quarterly' => 3,
        'semiannual' => 6, 'semiannually' => 6, 'halfyear' => 6, 'half_year' => 6,
        'year' => 12, 'annual' => 12, 'annually' => 12, 'oneyear' => 12,
    ];
    $k = strtolower(str_replace(['-', ' '], ['', ''], $t));
    if (isset($en[$k])) {
        return $en[$k];
    }
    if (preg_match('/(\d+)\s*年/u', $t, $m)) {
        return max(1, (int)$m[1]) * 12;
    }
    if (preg_match('/(\d+)\s*个?月/u', $t, $m)) {
        return max(1, (int)$m[1]);
    }
    if (mb_strpos($t, '年') !== false) {
        return 12;
    }
    return 1;
}

/**
 * 会员中心导航分类（= 产品类型）可选值
 *
 * 与后台「产品与套餐 → 产品类型」同一套分类，前台产品导航与下游接口都按它归集，
 * 因此导入页选择分类后直接写入 product.type，不额外引入字段。
 */
function yx_import_types(): array
{
    return ['host' => '虚拟主机', 'vps' => '云服务器', 'server' => '独立服务器', 'other' => '其他 / 增值'];
}

/**
 * 单条上游商品 → 本地产品字段 + 映射字段
 *
 * 同时兼容两种入参：
 *   - 上游原始条目（zjmf：id/name/price/cycle/qty/stock_control/config）
 *   - 驱动统一后的条目（code/name/price/stock/spec）
 */
function yx_import_payload(array $item, int $upstreamId, string $upstreamName, int $groupId = 0, array $opt = []): array
{
    $code = trim((string)($item['id'] ?? $item['code'] ?? ''));
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        $name = '上游商品 ' . $code;
    }

    // 原始条目字段名因通道而异（开放接口 price/cycle，购物车接口 product_price/billingcycle）
    $rawPrice  = (float)($item['price'] ?? $item['product_price'] ?? 0);
    $cycleText = (string)($item['cycle'] ?? $item['billingcycle'] ?? $item['billingcycle_zh'] ?? '');
    $months    = yx_import_cycle_months($cycleText);
    // 只有显式带着 stock_control 的原始条目才按「是否控制库存」判断；
    // 统一后的条目里 stock=null 表示上游未提供库存，同样按不限量处理。
    if (array_key_exists('stock_control', $item)) {
        $stock = (int)$item['stock_control'] === 1 ? max(0, (int)($item['qty'] ?? 0)) : -1;
    } else {
        $stockRaw = $item['stock'] ?? null;
        $stock = $stockRaw === null ? -1 : (int)$stockRaw;
    }

    // 利润百分比：在上游折算月价基础上加价，0 表示按成本价上架。
    // 单条导入页会显式传入；批量同步不传时保持原有「上游价 = 售价」口径。
    $profitPercent = isset($opt['profit_percent']) ? (float)$opt['profit_percent'] : 0.0;
    $profitPercent = max(0.0, min(1000.0, $profitPercent));
    $costPrice = $rawPrice > 0 ? round_money($rawPrice / $months) : 0.0;
    $price  = $costPrice > 0 ? round_money($costPrice * (1 + $profitPercent / 100)) : 0.0;
    $status = ($price > 0 && ($stock < 0 || $stock > 0)) ? 1 : 0;

    // 会员中心导航分类：落到产品类型（host/vps/server/other），
    // 与后台「产品与套餐」及会员中心产品导航使用同一套分类，不额外引入字段。
    $type = trim((string)($opt['type'] ?? 'host'));
    if (!isset(yx_import_types()[$type])) {
        $type = 'host';
    }

    $notes = [];
    if ($profitPercent > 0 && $costPrice > 0) {
        $notes[] = '售价按上游折算月价加价 ' . rtrim(rtrim(number_format($profitPercent, 2, '.', ''), '0'), '.')
            . '% 生成（成本 ' . money($costPrice, true) . ' → 售价 ' . money($price, true) . '）。';
    }
    if ($cycleText !== '' && $cycleText !== '0' && $months > 1) {
        $notes[] = '上游按「' . $cycleText . '」计费（上游价 ' . money($rawPrice, true) . '），本站折算为月价 '
            . money($price, true) . '。';
        if ($months >= 12) {
            $notes[] = '该商品仅开放年付（上游按整年收费，月付会亏本），年付实收 = 折算月价 × 12 × 0.9 = '
                . money(round_money($price * 12 * 0.9), true) . '。';
        }
    } elseif ($cycleText === '' || $cycleText === '0') {
        $notes[] = '上游未标注计费周期，按 1 个月计。';
    }
    if ($rawPrice <= 0) {
        $notes[] = '上游未设置有效价格，暂不上架（0 元订单无法提交）。';
    }
    if ($stock === 0) {
        $notes[] = '上游库存为 0，暂不上架。';
    }

    $description = '来源：' . $upstreamName . '（上游 #' . $upstreamId . '，商品编码 ' . $code . '）'
        . "\n开通方式：人工开通（该上游未开放开通写接口）。"
        . ($notes ? "\n" . implode("\n", $notes) : '');

    $spec = $item['config'] ?? $item['spec'] ?? null;
    if (!is_array($spec) || !$spec) {
        $spec = null;
    }

    return [
        'remote_code' => $code,
        'remote_name' => $name,
        'raw_price'   => $rawPrice,
        'months'      => $months,
        'cycle_text'  => $cycleText,
        'status'      => $status,
        'product'     => [
            'group_id'    => $groupId,
            'name'        => mb_substr($name, 0, 120),
            'code'        => 'up' . $upstreamId . '-' . $code,
            'type'        => $type,
            'cycle'       => 'month',
            // 上游按年计费只开年付，其余（含未标注周期）四周期全开
            'cycles'      => $months >= 12 ? 'year' : null,
            'price'       => $price,
            'stock'       => $stock,
            'auto_setup'  => 0,
            'edition_min' => 'basic',
            'description' => $description,
            'status'      => $status,
        ],
        'map' => [
            'upstream_id' => $upstreamId,
            'remote_code' => $code,
            'remote_name' => mb_substr($name, 0, 160),
            'spec'        => $spec ? json_encode($spec, JSON_UNESCAPED_UNICODE) : null,
            'price'       => $rawPrice,          // 上游成本价（该周期原价）
            'auto_setup'  => 0,
            'status'      => $status,
        ],
    ];
}

/**
 * 勾选的商品编码 → 上游商品条目（后台「勾选上架」用）
 *
 * 入参既可以是本次上架刚拉取到的原始条目，也可以是统一的预览结构：
 *   - 预览里的 price/stock 只用于展示（年付商品已被折算成月价、库存 0 被标成售罄），
 *     若把它当作上游原始数据再折算一次，年付价会再除以 12，周期也会退化成「未标注」；
 *   - 预览不带 cycle / qty / stock_control，因此条目一律以原始条目为准：
 *     同一次请求里已拉到原始条目就直接用，否则按需重新拉取一次（最多一次网络请求）。
 *
 * 不在原始列表里的编码（伪造、或上游已下架）一律丢弃，避免写入脏数据。
 *
 * @param array  $up       上游行（需 id/name）
 * @param array  $codes    勾选的商品编码
 * @param array  $preview  预览条目（可为空）
 * @param array  $rawItems 已拉取到的原始条目（可为空）
 * @return array picked(array) 可导入条目 / codes(array) 生效编码 / missing(array) 上游已不存在的编码 / msg(string)
 */
function yx_import_pick_items(array $up, array $codes, array $preview = [], array $rawItems = [], string &$msg = ''): array
{
    $out = ['picked' => [], 'codes' => [], 'missing' => [], 'msg' => ''];

    $wanted = [];
    foreach ($codes as $c) {
        $c = trim((string)$c);
        if ($c !== '') {
            $wanted[$c] = true;
        }
    }
    $wanted = array_keys($wanted);
    if (!$wanted) {
        $out['msg'] = '请先勾选要上架的上游商品';
        $msg = $out['msg'];
        return $out;
    }

    // 预览按编码建索引；同一编码先出现者为准
    $previewBy = [];
    foreach ($preview as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = trim((string)($item['code'] ?? $item['id'] ?? ''));
        if ($code !== '' && !isset($previewBy[$code])) {
            $previewBy[$code] = $item;
        }
    }

    $index = static function (array $items): array {
        $by = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string)($item['id'] ?? $item['code'] ?? ''));
            if ($code !== '' && !isset($by[$code])) {
                $by[$code] = $item;
            }
        }
        return $by;
    };

    // 预览只用于展示（价格已折算成月价、库存 0 已标售罄），不能当作上游原始数据二次折算，
    // 因此条目一律以原始条目为准；预览仅用于判断哪些编码属于本次拉取结果。
    $rawBy  = $rawItems ? $index($rawItems) : [];
    $need   = array_fill_keys($wanted, true);
    $lookupRaw = static function (array $need) use ($up, &$rawBy, &$out, $index): void {
        // 只补拉一次：全部拉一遍，命中哪些编码都在这批里找
        if (!array_diff_key($need, $rawBy)) {
            return;
        }
        try {
            $info = YxUpstream::productsRaw($up);
        } catch (Throwable $e) {
            $info = ['ok' => false, 'msg' => $e->getMessage()];
        }
        if (empty($info['ok'])) {
            $out['msg'] = '重新拉取上游商品失败：' . (string)($info['msg'] ?? '未知原因');
            return;
        }
        $rawBy = $rawBy + $index((array)($info['list'] ?? []));
    };

    $lookupRaw($need);
    if ($out['msg'] !== '') {
        $msg = $out['msg'];
        return $out;
    }

    foreach ($wanted as $code) {
        if (isset($rawBy[$code])) {
            $item = $rawBy[$code];
            $item['id'] = $code;   // 原始条目可能只有 code，统一带上编码
            $out['picked'][] = $item;
            $out['codes'][]  = $code;
            continue;
        }
        if (isset($previewBy[$code])) {
            $out['missing'][] = $code;   // 预览里有、原始列表里没有：上游已下架
        }
    }

    if (!$out['picked']) {
        $out['msg'] = '勾选的上游商品在上游列表中已不存在，请重新拉取';
    }
    $msg = $out['msg'];
    return $out;
}

/**
 * 过滤掉「该供应商已建过映射」的商品，供后台「只看未上架」使用
 *
 * 判定以 upstream_map 为准，而不是页面上的文本比较：已删除产品的映射仍在（product_id 指向已删行）
 * 也算已上架过，重新上架会按 remote_code 复用；因此这里只看映射存在与否。
 *
 * @param array $up    上游行（需 id）
 * @param array $items 条目列表（原始或统一结构，编码取 id / code）
 * @return array 过滤后的条目（保留原顺序与原结构）
 */
function yx_import_filter_unimported(array $up, array $items): array
{
    $upstreamId = (int)($up['id'] ?? 0);
    if ($upstreamId <= 0) {
        return array_values(array_filter($items, 'is_array'));
    }

    $have = [];
    foreach (YxDb::all(
        'SELECT remote_code FROM ' . YxDb::table('upstream_map') . ' WHERE upstream_id = ?',
        [$upstreamId]
    ) as $m) {
        $have[trim((string)$m['remote_code'])] = true;
    }

    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = trim((string)($item['id'] ?? $item['code'] ?? ''));
        if ($code !== '' && isset($have[$code])) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * 批量同步（写库入口，供 CLI 与自检复用）
 *
 * @param array $up   上游行（需 id/name）
 * @param array $items 上游商品项（原始或统一结构均可）
 * @param array $opt  dry_run(bool) / force_price(bool) / keep_missing(bool) / group_id(int) /
 *                    picked_codes(array, 仅处理这些编码，用于后台勾选上架)
 * @return array total/create/update/skip/fail/unpublish/rows
 */
function yx_import_upstream_items(array $up, array $items, array $opt = []): array
{
    $dryRun      = !empty($opt['dry_run']);
    $forcePrice  = !empty($opt['force_price']);
    $keepMissing = !empty($opt['keep_missing']);
    $groupId     = (int)($opt['group_id'] ?? 0);
    $upstreamId  = (int)($up['id'] ?? 0);
    $upstreamName = (string)($up['name'] ?? ('上游#' . $upstreamId));

    // 勾选上架：只处理勾选的编码。调用方已按编码挑好条目，这里再兜一层，
    // 防止把整份上游列表传进来时误把未勾选的商品一并更新/上架。
    $picked = null;
    if (!empty($opt['picked_codes']) && is_array($opt['picked_codes'])) {
        $picked = [];
        foreach ($opt['picked_codes'] as $c) {
            $c = trim((string)$c);
            if ($c !== '') {
                $picked[$c] = true;
            }
        }
        if (!$picked) {
            $picked = null;
        }
    }

    $stats = ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'fail' => 0, 'unpublish' => 0, 'rows' => []];
    $seen  = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ($picked !== null) {
            $itemCode = trim((string)($item['id'] ?? $item['code'] ?? ''));
            if ($itemCode === '' || !isset($picked[$itemCode])) {
                continue;
            }
        }
        $stats['total']++;
        $p = yx_import_payload($item, $upstreamId, $upstreamName, $groupId, $opt);
        if ($p['remote_code'] === '') {
            $stats['fail']++;
            continue;
        }
        $seen[$p['remote_code']] = true;

        $map = YxDb::one(
            'SELECT * FROM ' . YxDb::table('upstream_map') . ' WHERE upstream_id = ? AND remote_code = ?',
            [$upstreamId, $p['remote_code']]
        );
        $product = null;
        if ($map && (int)$map['product_id'] > 0) {
            $product = YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ?', [(int)$map['product_id']]);
        }
        if (!$product) {
            // 没有映射（或映射指向的产品已被删）：按编码复用，避免重复插入同名商品
            $product = YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE code = ?', [$p['product']['code']]);
        }

        $action = $product ? 'update' : 'create';
        if ($dryRun) {
            $stats[$action]++;
            $stats['rows'][] = [
                'code' => $p['remote_code'], 'name' => $p['remote_name'],
                'raw_price' => $p['raw_price'], 'cycle' => $p['cycle_text'],
                'price' => $p['product']['price'], 'stock' => $p['product']['stock'],
                'status' => $p['status'], 'action' => $action,
            ];
            continue;
        }

        try {
            // 单条导入页会显式给出利润/分类，属于「按本次选择重新定价」，
            // 批量同步不传这些键时保持原有「不覆盖人工定价」的行为。
            $explicit = array_key_exists('profit_percent', $opt) || !empty($opt['type']);
            if ($product) {
                $pid = (int)$product['id'];
                $data = [
                    'group_id'    => $groupId > 0 ? $groupId : (int)$product['group_id'],
                    'name'        => $p['product']['name'],
                    'stock'       => $p['product']['stock'],
                    'description' => $p['product']['description'],
                    // 库存 > 0 或 -1（不限量）才可售；上游售罄（0）时下架。
                    // 不能直接用 payload 的 status：它还要挡 0 元商品（见文件头「价格口径」），
                    // 复用已存在产品时会把「价格为 0 的占位商品」（往往是人工定价过的）
                    // 无条件改成 status=0。0 元在新增时仍不入售，复用时不擅自下架。
                    'status'      => $p['product']['stock'] !== 0 ? 1 : 0,
                ];
                // 会员中心导航分类只在显式选择时覆盖，批量同步不擅改已有分类
                if (!empty($opt['type'])) {
                    $data['type'] = $p['product']['type'];
                }
                // 价格默认不动：可能已被人工加价，覆盖会抹掉定价。
                // 上游没给有效价格（0 元）时不覆盖已有售价：否则会把在售商品改成 0 元，
                // 而下单端是按价格计费的，等于开免费口子（新增时仍由 payload.status=0 挡住）。
                if ($p['product']['price'] > 0
                    && ($forcePrice || $explicit || (float)$product['price'] === $p['product']['price'])) {
                    $data['price'] = $p['product']['price'];
                }
                // 周期限制随定价一起走：人工改过 cycles 的产品，只有强制定价才会被重置
                if ($forcePrice || $explicit) {
                    $data['cycles'] = $p['product']['cycles'];
                }
                YxDb::update('product', $data, 'id = :id', ['id' => $pid]);
            } else {
                $row = $p['product'];
                $row['setup_fee'] = 0;
                $row['sort']      = (int)$p['remote_code'];
                $row['created_at'] = now();
                $pid = YxDb::insert('product', $row);
            }

            $mapData = $p['map'] + ['product_id' => $pid];
            if ($map) {
                YxDb::update('upstream_map', $mapData, 'id = :id', ['id' => (int)$map['id']]);
            } else {
                $mapData['created_at'] = now();
                YxDb::insert('upstream_map', $mapData);
            }
            $stats[$action]++;
            $stats['rows'][] = [
                'code' => $p['remote_code'], 'name' => $p['remote_name'],
                'raw_price' => $p['raw_price'], 'cycle' => $p['cycle_text'],
                'price' => $p['product']['price'], 'stock' => $p['product']['stock'],
                'status' => $p['status'], 'action' => $action, 'product_id' => $pid,
            ];
        } catch (Throwable $e) {
            $stats['fail']++;
            error_log('import_upstream: ' . $p['remote_code'] . ' ' . $e->getMessage());
        }
    }

    // 上游已不存在的商品：下架但保留数据
    if (!$keepMissing) {
        $maps = YxDb::all('SELECT * FROM ' . YxDb::table('upstream_map') . ' WHERE upstream_id = ?', [$upstreamId]);
        foreach ($maps as $m) {
            if (isset($seen[(string)$m['remote_code']])) {
                continue;
            }
            $stats['unpublish']++;
            if ($dryRun) {
                continue;
            }
            YxDb::update('upstream_map', ['status' => 0], 'id = :id', ['id' => (int)$m['id']]);
            if ((int)$m['product_id'] > 0) {
                YxDb::update('product', ['status' => 0], 'id = :id', ['id' => (int)$m['product_id']]);
            }
        }
    }

    return $stats;
}

/* ------------------------------------------------------------ CLI 主流程 */

// CLI 主流程只在本文件作为入口时执行：被页面 include（或 Web 方式请求）时一律到此结束，
// 绝不从 HTTP 请求里跑同步流程。注意用 return 而非 exit，否则会中断调用它的页面。
if (PHP_SAPI !== 'cli'
    || realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$root = dirname(__DIR__);
$args = [];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

require_once $root . '/includes/bootstrap.php';

$upstreamId = (int)($args['upstream'] ?? 0);
if ($upstreamId <= 0) {
    exit("用法：php tools/import_upstream.php --upstream=3 [--dry-run] [--group=名称] [--force-price] [--limit=N]\n");
}
$dryRun = isset($args['dry-run']);
$limit  = isset($args['limit']) ? max(0, (int)$args['limit']) : 0;

$up = YxUpstream::row($upstreamId);
if (!$up) {
    exit("供应商 #{$upstreamId} 不存在\n");
}

echo "上游商品上架 · {$up['name']}（#" . $upstreamId . "，驱动 " . ($up['driver'] ?? '') . "）\n";
echo '数据库：' . YxDb::driver() . '　' . ($dryRun ? '模式：dry-run（不写库）' : '模式：正式同步') . "\n";
echo str_repeat('-', 62) . "\n";

/* 1. 拉取商品（productsRaw：魔方走原始条目以保留 cycle / qty / stock_control，并统一价格单位） */
$resp = YxUpstream::productsRaw($up);
if (empty($resp['ok'])) {
    exit('拉取上游商品失败：' . (string)($resp['msg'] ?? '未知错误') . "\n");
}
$items = (array)($resp['list'] ?? []);
if (!$items) {
    exit("上游未返回可售商品，终止（未写入任何数据）\n");
}
if ($limit > 0) {
    $items = array_slice($items, 0, $limit);
}
echo '上游商品：' . count($items) . " 个\n";

/* 2. 分组：按名称找，找不到就建 */
$groupName = trim((string)($args['group'] ?? '')) ?: (string)$up['name'];
$group = YxDb::one('SELECT * FROM ' . YxDb::table('product_group') . ' WHERE name = ?', [$groupName]);
if (!$group) {
    if ($dryRun) {
        echo "分组「{$groupName}」不存在（正式同步时会创建）\n";
    } else {
        $gid = YxDb::insert('product_group', ['name' => $groupName, 'sort' => 0, 'status' => 1]);
        echo "已创建分组「{$groupName}」（#{$gid}）\n";
        $group = ['id' => $gid];
    }
}
$groupId = (int)($group['id'] ?? 0);
if ($groupId > 0) {
    echo "分组：{$groupName}（#{$groupId}）\n";
}

/* 3. 同步 */
$stats = yx_import_upstream_items($up, $items, [
    'dry_run'      => $dryRun,
    'force_price'  => isset($args['force-price']),
    'keep_missing' => isset($args['keep-missing']),
    'group_id'     => $groupId,
]);

echo str_repeat('-', 62) . "\n";
printf("合计 %d 条：新增 %d，更新 %d，失败 %d，上游已消失下架 %d\n",
    $stats['total'], $stats['create'], $stats['update'], $stats['fail'], $stats['unpublish']);

$off = 0;
foreach ($stats['rows'] as $r) {
    if ((int)$r['status'] !== 0) {
        continue;
    }
    $off++;
    if ($off <= 15) {
        printf("  未上架：%-6s %s（上游价 %s/%s，库存 %s）\n",
            $r['code'], mb_substr($r['name'], 0, 28), money((float)$r['raw_price'], true),
            $r['cycle'] !== '' ? $r['cycle'] : '未标注', $r['stock']);
    }
}
if ($off > 15) {
    echo '  …另有 ' . ($off - 15) . " 个未上架\n";
}
echo '已上架 ' . ($stats['total'] - $off) . " 个，未上架 {$off} 个（0 元 / 售罄）\n";

// 上游按年计费的商品：确认已被收窄为仅年付，避免月付倒贴
$yearOnly = 0;
foreach ($stats['rows'] as $r) {
    if ((int)$r['status'] !== 0 && yx_import_cycle_months((string)$r['cycle']) >= 12) {
        $yearOnly++;
    }
}
if ($yearOnly > 0) {
    echo "上游按年计费的商品 {$yearOnly} 个，已收窄为仅年付（年付实收 = 月价 × 12 × 0.9）。\n";
}

if (!$dryRun) {
    log_action('upstream.import_products', 'upstream#' . $upstreamId, [
        'total' => $stats['total'], 'create' => $stats['create'],
        'update' => $stats['update'], 'fail' => $stats['fail'],
    ]);
}
