<?php
/**
 * 优享财务系统 · 端到端自检
 *
 * 在独立的临时数据库（SQLite）中跑通「建表 → 客户 → 财务 → 订单 → 交付 → 续费 → 工单 → 商店 → 授权」全链路，
 * 不触碰正式数据。
 *
 * 用法：
 *   php tools/selftest.php
 *   php tools/selftest.php --keep      保留临时库以便排查
 *   php tools/selftest.php --db=/tmp/x.db   指定数据库文件（指定后不会被删除）
 */

if (PHP_SAPI !== 'cli') {
    exit('该脚本仅支持命令行执行');
}

$root = dirname(__DIR__);

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}
$keep  = !empty($args['keep']);
$tmpDb = is_string($args['db'] ?? null) && $args['db'] !== ''
    ? $args['db']
    : $root . '/runtime/selftest_' . date('YmdHis') . '_' . random_int(100, 999) . '.db';

/* ---- 构造隔离配置，强制使用本地授权校验 ---- */
$conf = require $root . '/includes/config.php';
$conf['app']['installed'] = true;
$conf['app']['debug']     = false;
$conf['app']['key']       = 'selftest-key-0123456789abcdef';
$conf['edition']['current'] = 'enterprise';
$conf['db'] = [
    'driver'  => 'sqlite',
    'host'    => '',
    'port'    => 0,
    'name'    => '',
    'user'    => '',
    'pass'    => '',
    'charset' => 'utf8mb4',
    'prefix'  => 'yx_',
    'path'    => $tmpDb,
];
$conf['license']['official_api'] = '';   // 不访问外网，仅走本地授权表
$conf['license']['cache_ttl']    = 0;

define('YX_CONF_OVERRIDE', $conf);

require_once $root . '/includes/bootstrap.php';

/* ---------------------------------------------------------------- 测试框架 */

$GLOBALS['_yx_pass'] = 0;
$GLOBALS['_yx_fail'] = 0;
$GLOBALS['_yx_failures'] = [];

function t(string $name, bool $cond, string $extra = ''): void
{
    if ($cond) {
        $GLOBALS['_yx_pass']++;
        echo "  \033[32m[PASS]\033[0m {$name}\n";
    } else {
        $GLOBALS['_yx_fail']++;
        $GLOBALS['_yx_failures'][] = $name . ($extra !== '' ? "  ({$extra})" : '');
        echo "  \033[31m[FAIL]\033[0m {$name}" . ($extra !== '' ? "  → {$extra}" : '') . "\n";
    }
}

function eq(string $name, $expected, $actual): void
{
    t($name, $expected == $actual, '期望 ' . var_export($expected, true) . '，实际 ' . var_export($actual, true));
}

function section(string $title): void
{
    echo "\n\033[36m== {$title} ==\033[0m\n";
}

/* ---------------------------------------------------------------- 初始化数据库 */

try {
    foreach (YxSchema::statements('sqlite') as $sql) {
        YxDb::pdo()->exec($sql);
    }
    foreach (YxSchema::sqliteIndexes() as $sql) {
        YxDb::pdo()->exec($sql);
    }
} catch (Throwable $e) {
    echo "建表失败：" . $e->getMessage() . "\n";
    exit(1);
}
echo "临时数据库：" . $tmpDb . "\n";

// 自检需要确定性：先固定为「正式版」授权模式，§19 再单独验证开源版分支。
// （开源版发行包的配置默认值是 community，若不固定，前面的正式版断言会连带失败）
set_setting('license_mode', 'official');
YxLicense::flushCache();

/* ---------------------------------------------------------------- 1. 结构与工具 */
section('1. 数据库结构 / 基础工具');

$expectedTables = array_keys(require $root . '/includes/schema.php');
$missing = [];
foreach ($expectedTables as $tb) {
    if (!YxDb::hasTable($tb)) {
        $missing[] = $tb;
    }
}
t('schema.php 中定义的全部表均已创建（共 ' . count($expectedTables) . ' 张）', !$missing, '缺失：' . implode(',', $missing));

eq('域名归一化（含协议/端口/大小写/斜杠）', 'www.example.com', normalize_domain('HTTPS://WWW.Example.com:8443/'));
eq('域名归一化（裸域名）', 'example.com', normalize_domain('  example.com . '));
eq('域名归一化（带路径与查询串）', 'example.com', normalize_domain('http://example.com/path?a=1#x'));
eq('域名归一化（仅端口）', 'example.com', normalize_domain('example.com:8080'));
set_setting('site_domain', 'cfg.example.com');
t('site_domain 设置可被读取', site_domain() === 'cfg.example.com', site_domain());
t('CLI 下 current_domain 取站点配置而非机器主机名', current_domain() === 'cfg.example.com', current_domain());
set_setting('site_domain', '');
t('单号生成非空且带前缀', strpos(make_sn('TX', 'transaction'), 'TX') === 0);
t('金额四舍五入到分', round_money('10.005') == 10.01, (string)round_money('10.005'));

/* ---------------------------------------------------------------- 2. 版本控制 */
section('2. 三版本模块开关');

eq('默认版本取 config（enterprise）', 'enterprise', YxEdition::current());
t('企业版可用经营分析', YxEdition::can('report'));
t('企业版可用授权中心', YxEdition::can('license.center'));
t('企业版可用工单', YxEdition::can('ticket'));

set_setting('edition', 'basic');
eq('切换后当前版本为 basic', 'basic', YxEdition::current());
t('普惠版不可用经营分析', !YxEdition::can('report'));
t('普惠版不可用工单', !YxEdition::can('ticket'));
t('普惠版可用收支记账', YxEdition::can('finance.cashflow'));
eq('版本能力矩阵：企业版列全为真', true, !in_array(false, array_column(YxEdition::matrix(), 'enterprise'), true));

set_setting('edition', 'enterprise');

/* ---------------------------------------------------------------- 3. 授权模块 */
section('3. 域名授权（按域名查询）');

$key1 = YxLicense::generateKey('www.example.com', 'pro', '2027-12-31');
$key2 = YxLicense::generateKey('www.example.com', 'pro', '2027-12-31');
eq('授权码按域名生成且稳定可复核', $key1, $key2);
t('授权码与域名绑定（换域名即不同）', $key1 !== YxLicense::generateKey('other.com', 'pro', '2027-12-31'));
t('授权码可通过校验', YxLicense::verifyKey('www.example.com', $key1, '2027-12-31'));
t('错误授权码校验失败', !YxLicense::verifyKey('www.example.com', 'AAAA-BBBB-CCCC-DDDD', '2027-12-31'));

$r = YxLicense::query('never-registered.com', true);
t('未登记域名判定为未授权', !$r['authorized'] && $r['status'] === YxLicense::STATUS_NO, $r['status']);
eq('未授权提示文案', '域名未在官方授权', $r['message']);

YxDb::insert('license', [
    'domain' => 'authorized-site.com', 'license_key' => YxLicense::generateKey('authorized-site.com', 'enterprise', ''),
    'edition' => 'enterprise', 'type' => 'official', 'status' => 'active',
    'expire_at' => null, 'max_sites' => 1, 'bound_at' => now(), 'created_at' => now(),
]);
$r = YxLicense::query('authorized-site.com', true);
t('已登记域名判定为已授权', $r['authorized'] && $r['status'] === YxLicense::STATUS_OK, $r['status']);
eq('已授权提示文案', '域名已正版授权', $r['message']);
eq('已授权返回授权版本', 'enterprise', $r['edition']);

YxDb::insert('license', [
    'domain' => 'expired-site.com', 'license_key' => 'X', 'edition' => 'pro', 'type' => 'official',
    'status' => 'active', 'expire_at' => date('Y-m-d', strtotime('-1 day')), 'max_sites' => 1, 'created_at' => now(),
]);
$r = YxLicense::query('expired-site.com', true);
eq('过期授权判定为 expired', YxLicense::STATUS_EXPIRED, $r['status']);
t('过期授权不可用', !$r['authorized']);

YxDb::insert('license', [
    'domain' => 'banned-site.com', 'license_key' => 'Y', 'edition' => 'pro', 'type' => 'official',
    'status' => 'banned', 'expire_at' => null, 'max_sites' => 1, 'created_at' => now(),
]);
eq('封禁授权判定为 banned', YxLicense::STATUS_BANNED, YxLicense::query('banned-site.com', true)['status']);

$_REQUEST['domain'] = 'authorized-site.com';
$serve = YxLicense::serveQuery();
t('授权服务器接口：已登记域名返回 authorized=true', !empty($serve['data']['authorized']), json_encode($serve, JSON_UNESCAPED_UNICODE));
eq('授权服务器接口：status 使用对外协议值', YxLicense::STATUS_OK, $serve['data']['status'] ?? '');
eq('授权服务器接口：提示文案为已正版授权', '域名已正版授权', $serve['data']['message'] ?? '');

$_REQUEST['domain'] = 'expired-site.com';
$serve = YxLicense::serveQuery();
eq('授权服务器接口：过期域名返回 expired', YxLicense::STATUS_EXPIRED, $serve['data']['status'] ?? '');
t('授权服务器接口：过期域名 authorized=false', empty($serve['data']['authorized']));

$_REQUEST['domain'] = 'never-registered.com';
$serve = YxLicense::serveQuery();
t('授权服务器接口：未登记域名返回 authorized=false', empty($serve['data']['authorized']));
eq('授权服务器接口：未登记域名 status 为 unauthorized', YxLicense::STATUS_NO, $serve['data']['status'] ?? '');
unset($_REQUEST['domain']);

/* --- 三要素鉴权（授权地址 + 授权ID + 授权密钥） --- */
$licSecret = random_nonce(32);
YxDb::insert('api_app', [
    'name' => '自检授权调用方', 'appid' => 'selftest-lic', 'secret' => yx_encrypt($licSecret),
    'owner_type' => 'admin', 'owner_id' => 0, 'rate_limit' => 0,
    'allow_actions' => 'license.query', 'status' => 1, 'created_at' => now(),
]);
$mkReq = function (array $over = []) use ($licSecret): array {
    $p = array_merge([
        'app_id' => 'selftest-lic', 'domain' => 'authorized-site.com',
        'version' => '1.0.0', 'ts' => (string)time(), 'nonce' => random_nonce(16),
    ], $over);
    $p['sign'] = YxLicense::sign($p, $licSecret);
    return $p;
};

$v = YxLicense::verifyRequest($mkReq());
t('三要素：正确签名通过校验', $v['ok'] && $v['app']['appid'] === 'selftest-lic', $v['msg']);

$bad = $mkReq();
$bad['sign'] = str_repeat('a', 64);
$v = YxLicense::verifyRequest($bad);
t('三要素：错误签名被拒绝', !$v['ok'] && strpos($v['msg'], '签名') !== false, $v['msg']);

$v = YxLicense::verifyRequest($mkReq(['ts' => (string)(time() - 400)]));
t('三要素：时间戳超窗被拒绝', !$v['ok'] && strpos($v['msg'], '时间戳') !== false, $v['msg']);

$v = YxLicense::verifyRequest($mkReq(['app_id' => 'not-exist-app']));
t('三要素：授权ID不存在被拒绝', !$v['ok'] && strpos($v['msg'], '不存在') !== false, $v['msg']);

$v = YxLicense::verifyRequest($mkReq(['nonce' => 'short']));
t('三要素：nonce 过短被拒绝', !$v['ok'] && strpos($v['msg'], 'nonce') !== false, $v['msg']);

$replay = $mkReq();
$v = YxLicense::verifyRequest($replay);
$okFirst = $v['ok'];
$v = YxLicense::verifyRequest($replay);
t('三要素：nonce 重放被拒绝', $okFirst && !$v['ok'] && strpos($v['msg'], '重放') !== false, $v['msg']);

// 应答签名：客户端可复核，且缺 domain 时也带 data 供签名
$_REQUEST['domain'] = 'authorized-site.com';
$serve = YxLicense::serveQuery(['appid' => 'selftest-lic', 'secret' => yx_encrypt($licSecret)]);
t('三要素：应答带签名', isset($serve['sign'], $serve['ts']), json_encode($serve, JSON_UNESCAPED_UNICODE));
t('三要素：应答签名可用同一算法复核', hash_equals(YxLicense::sign((array)$serve['data'], $licSecret), (string)$serve['sign']));
$_REQUEST['domain'] = '';
$serve = YxLicense::serveQuery(['appid' => 'selftest-lic', 'secret' => yx_encrypt($licSecret)]);
t('三要素：异常应答同样带签名', (int)$serve['code'] === 400 && isset($serve['sign']), json_encode($serve, JSON_UNESCAPED_UNICODE));
unset($_REQUEST['domain']);

t('未授权时默认不限制版本（license_enforce 关闭）', YxLicense::maxEdition() === 'enterprise');
set_setting('license_enforce', '1');
t('开启强制收敛后未授权站点上限为普惠版', YxLicense::maxEdition() === 'basic' || YxLicense::isAuthorized());
set_setting('license_enforce', '0');

/* ---------------------------------------------------------------- 4. 财务核心 */
section('4. 财务核心（余额 / 流水）');

$cid = YxDb::insert('customer', [
    'username' => 'test_user_a', 'email' => 'a@example.com', 'password' => YxAuth::hash('password123'),
    'balance' => 0, 'credit' => 0, 'points' => 0, 'level' => 'normal', 'agent_id' => 0, 'status' => 1, 'created_at' => now(),
]);
t('客户创建成功', $cid > 0);

$uidCust = customer_insert([
    'username' => 'test_user_no', 'email' => 'uno@example.com', 'password' => YxAuth::hash('password123'),
    'balance' => 0, 'credit' => 0, 'points' => 0, 'level' => 'normal', 'agent_id' => 0, 'status' => 1, 'created_at' => now(),
]);
$uno = (int)YxDb::value('SELECT user_no FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$uidCust]);
t('客户自动分配 5 位用户ID', $uno >= 10001 && $uno <= 99999, 'user_no=' . $uno);
$dupNo = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE user_no = ?', [$uno]);
eq('用户ID 唯一', 1, $dupNo);
$uidCust2 = customer_insert([
    'username' => 'test_user_no2', 'email' => 'uno2@example.com', 'password' => YxAuth::hash('password123'),
    'balance' => 0, 'credit' => 0, 'points' => 0, 'level' => 'normal', 'agent_id' => 0, 'status' => 1, 'created_at' => now(),
]);
eq('用户ID 按 1 递增', $uno + 1, (int)YxDb::value('SELECT user_no FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$uidCust2]));

$res = YxFinance::credit($cid, 100, 'manual', ['remark' => '自检充值']);
t('入账 100 成功', $res['ok'] && (float)$res['balance'] === 100.0, json_encode($res, JSON_UNESCAPED_UNICODE));

$res = YxFinance::debit($cid, 30, 'manual', ['remark' => '自检扣款']);
t('出账 30 成功，余额 70', $res['ok'] && (float)$res['balance'] === 70.0, json_encode($res, JSON_UNESCAPED_UNICODE));

$res = YxFinance::debit($cid, 1000, 'manual', []);
t('余额不足时扣款被拒绝', !$res['ok'], json_encode($res, JSON_UNESCAPED_UNICODE));
eq('失败后余额未被改动', 70.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]));

$res = YxFinance::credit($cid, -5, 'manual', []);
t('负数入账被拒绝', !$res['ok']);

$txCount = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('transaction') . ' WHERE customer_id = ?', [$cid]);
eq('两次成功操作产生 2 条流水', 2, $txCount);

/* ---------------------------------------------------------------- 5. 订单交付闭环 */
section('5. 订单 → 支付 → 交付闭环');

$gid = YxDb::insert('product_group', ['name' => '自检分组', 'sort' => 1, 'status' => 1]);
$pid = YxDb::insert('product', [
    'group_id' => $gid, 'name' => '自检云主机', 'type' => 'host', 'cycle' => 'month',
    'price' => 50.00, 'setup_fee' => 0, 'stock' => -1, 'auto_setup' => 1, 'edition_min' => 'basic',
    'status' => 1, 'sort' => 0, 'created_at' => now(),
]);
t('产品创建成功', $pid > 0);

eq('月付价格 = 50', 50.0, YxBusiness::cyclePrice(['price' => 50], 'month'));
eq('年付价格 = 50×12×0.9', 540.0, YxBusiness::cyclePrice(['price' => 50], 'year'));
eq('半年付价格 = 50×6×0.95', 285.0, YxBusiness::cyclePrice(['price' => 50], 'semiyear'));

// 周期收窄（只开放部分计费周期）
eq('未设 cycles 时开放全部 4 个周期', 4, count(YxBusiness::allowedCycles(['cycles' => null])));
eq('cycles=year 只开放年付', ['year'], YxBusiness::allowedCycles(['cycles' => 'year']));
eq('cycles 逗号分隔按 CYCLES 顺序归一', ['month', 'year'], YxBusiness::allowedCycles(['cycles' => 'year,month']));
eq('cycles 含未知值时回落为不限', 4, count(YxBusiness::allowedCycles(['cycles' => 'unknown'])));
t('仅年付商品允许 year', YxBusiness::cycleAllowed(['cycles' => 'year'], 'year'));
t('仅年付商品拒绝 month', !YxBusiness::cycleAllowed(['cycles' => 'year'], 'month'));
eq('仅年付商品默认周期为 year', 'year', YxBusiness::defaultCycle(['cycle' => 'month', 'cycles' => 'year']));
eq('未收窄时默认周期沿用原 cycle', 'month', YxBusiness::defaultCycle(['cycle' => 'month', 'cycles' => null]));
eq('仅年付的周期标签', '仅年付', YxBusiness::cycleLabel(['cycles' => 'year']));
eq('多周期标签', '月付 / 年付', YxBusiness::cycleLabel(['cycles' => 'month,year']));
eq('未收窄时无周期标签', '', YxBusiness::cycleLabel(['cycles' => null]));

$created = YxBusiness::createOrder([
    'customer_id' => $cid, 'type' => 'new', 'product_id' => $pid, 'name' => '自检云主机（月付）', 'amount' => 50,
]);
t('订单创建成功', !empty($created['ok']), json_encode($created, JSON_UNESCAPED_UNICODE));
$orderId = (int)($created['order_id'] ?? 0);

$pay = YxBusiness::payOrder($orderId, 'balance');
t('余额支付成功', !empty($pay['ok']), json_encode($pay, JSON_UNESCAPED_UNICODE));
eq('支付后余额 70-50=20', 20.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]));
eq('订单状态为 paid', 'paid', YxDb::value('SELECT status FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]));

$svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = (SELECT service_id FROM ' . YxDb::table('order') . ' WHERE id = ?)', [$orderId]);
t('支付后自动生成业务实例', !empty($svc), '未生成业务实例');
if ($svc) {
    eq('自动开通后业务状态为 active', 'active', $svc['status']);
    t('业务实例已写入到期日', !empty($svc['next_due_date']), (string)$svc['next_due_date']);
}
eq('自动生成 1 张已结清账单', 1, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('invoice') . " WHERE order_id = ? AND status = 'paid'", [$orderId]));

$again = YxBusiness::payOrder($orderId, 'balance');
t('重复支付被幂等拦截且不重复扣款', !empty($again['repeat']) && (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]) === 20.0, json_encode($again, JSON_UNESCAPED_UNICODE));

/* ---------------------------------------------------------------- 6. 充值 / 续费 / 退款 */
section('6. 充值 / 续费 / 退款');

$rc = YxBusiness::createOrder(['customer_id' => $cid, 'type' => 'recharge', 'name' => '充值 100', 'amount' => 100]);
$reject = YxBusiness::payOrder((int)$rc['order_id'], 'balance');
t('充值订单禁止用余额支付', empty($reject['ok']), json_encode($reject, JSON_UNESCAPED_UNICODE));

$ok = YxBusiness::payOrderExternal((int)$rc['order_id'], 'alipay', 'TRADE-001');
t('网关回调完成充值订单', !empty($ok['ok']), json_encode($ok, JSON_UNESCAPED_UNICODE));
eq('充值到账后余额 20+100=120', 120.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]));
eq('充值流水分类为 recharge', 1, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('transaction') . " WHERE customer_id = ? AND category = 'recharge'", [$cid]));

$svcId    = (int)($svc['id'] ?? 0);
$beforeDue = (string)($svc['next_due_date'] ?? date('Y-m-d'));
$renew    = YxBusiness::renewService($svcId, 'month', 50, 'test');
$afterDue = (string)YxDb::value('SELECT next_due_date FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcId]);
t('续费成功', !empty($renew['ok']), json_encode($renew, JSON_UNESCAPED_UNICODE));
t('续费后到期日顺延', $afterDue > $beforeDue, "{$beforeDue} → {$afterDue}");

$refund = YxBusiness::refundOrder($orderId, 'selftest');
t('业务订单退款成功', !empty($refund['ok']), json_encode($refund, JSON_UNESCAPED_UNICODE));
eq('退款后余额 120+50=170', 170.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]));
eq('退款流水分类为 refund', 1, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('transaction') . " WHERE customer_id = ? AND category = 'refund'", [$cid]));
eq('退款后订单状态为 refunded', 'refunded', YxDb::value('SELECT status FROM ' . YxDb::table('order') . ' WHERE id = ?', [$orderId]));

$refundAgain = YxBusiness::refundOrder($orderId, 'selftest');
t('同一订单重复退款被拦截', empty($refundAgain['ok']), json_encode($refundAgain, JSON_UNESCAPED_UNICODE));
eq('重复退款未再增加余额', 170.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]));

// 充值订单退款是「冲回」已入账的余额，不能再送一笔钱
$rcRefund = YxBusiness::refundOrder((int)$rc['order_id'], 'selftest');
t('充值订单退款成功', !empty($rcRefund['ok']), json_encode($rcRefund, JSON_UNESCAPED_UNICODE));
eq('充值退款从余额冲回 170-100=70', 70.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]));
eq('充值退款同样记为 refund 流水', 2, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('transaction') . " WHERE customer_id = ? AND category = 'refund'", [$cid]));
$rcRefundAgain = YxBusiness::refundOrder((int)$rc['order_id'], 'selftest');
t('充值订单不可重复退款', empty($rcRefundAgain['ok']), json_encode($rcRefundAgain, JSON_UNESCAPED_UNICODE));
eq('充值重复退款未再扣余额', 70.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]));

/* ---------------------------------------------------------------- 7. 业务实例状态机 */
section('7. 业务实例状态机');

$r = YxBusiness::suspendService($svcId, '欠费');
t('可暂停 active 业务', !empty($r['ok']), json_encode($r));
$r = YxBusiness::suspendService($svcId);
t('重复暂停被拒绝', empty($r['ok']));
$r = YxBusiness::unsuspendService($svcId);
t('可恢复 suspended 业务', !empty($r['ok']), json_encode($r));
$r = YxBusiness::terminateService($svcId, '自检终止');
t('可终止业务', !empty($r['ok']));
$r = YxBusiness::activateService($svcId);
t('已终止业务不可重新开通', empty($r['ok']), json_encode($r));

/* ---------------------------------------------------------------- 8. 工单 */
section('8. 工单协同');

$tid = YxBusiness::createTicket(['customer_id' => $cid, 'department' => 'tech', 'subject' => '自检工单', 'priority' => 'high']);
t('工单创建成功', $tid > 0);
eq('工单初始状态 open', 'open', YxDb::value('SELECT status FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$tid]));
YxBusiness::replyTicket($tid, 'admin', 1, '已收到，正在处理');
eq('客服回复后状态 replied', 'replied', YxDb::value('SELECT status FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$tid]));
eq('回复计数 +1', 1, (int)YxDb::value('SELECT reply_count FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$tid]));
YxBusiness::closeTicket($tid);
eq('关闭后状态 closed', 'closed', YxDb::value('SELECT status FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [$tid]));
$r = YxBusiness::replyTicket($tid, 'customer', $cid, '追加');
t('已关闭工单客户不可再回复', empty($r['ok']));

/* ---------------------------------------------------------------- 9. 商店与授权绑定 */
section('9. 应用商店与域名授权绑定');

$appId = YxDb::insert('app', [
    'category_key' => 'pay', 'key' => 'selftest-pay', 'name' => '自检支付插件', 'version' => '1.0.0',
    'price' => 10.00, 'edition_min' => 'basic', 'status' => 'on', 'created_at' => now(), 'updated_at' => now(),
]);
t('应用上架成功', $appId > 0);

$ins = YxBusiness::installApp(['app_id' => $appId, 'customer_id' => $cid, 'domain' => 'BIND.Example.com']);
t('应用安装成功', !empty($ins['ok']), json_encode($ins, JSON_UNESCAPED_UNICODE));
$inst = YxDb::one('SELECT * FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appId]);
eq('绑定域名被归一化', 'bind.example.com', $inst['domain'] ?? '');
eq('授权码与域名对应', YxBusiness::appLicenseKey('selftest-pay', 'bind.example.com'), $inst['license_key'] ?? '');
t('换域名后授权码不同', YxBusiness::appLicenseKey('selftest-pay', 'other.com') !== ($inst['license_key'] ?? ''));

$ins2 = YxBusiness::installApp(['app_id' => $appId, 'customer_id' => $cid, 'domain' => 'bind.example.com']);
t('同域名重复安装不产生新记录（幂等）', !empty($ins2['ok']) && (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appId]) === 1);
t('重复安装仍返回授权码（便于提示用户）', strpos((string)($ins2['license_key'] ?? ''), '-') !== false);

// 按年应用：到期日必须随再次购买顺延，过期后重新购买必须恢复为有效
$appYear = YxDb::insert('app', [
    'category_key' => 'pay', 'key' => 'selftest-year', 'name' => '自检年付插件', 'version' => '1.0.0',
    'price' => 199.00, 'bill_type' => 'year', 'edition_min' => 'basic', 'status' => 'on',
    'created_at' => now(), 'updated_at' => now(),
]);
$r1 = YxBusiness::installApp(['app_id' => $appYear, 'customer_id' => $cid, 'domain' => 'year.example.com']);
$exp1 = (string)YxDb::value('SELECT expire_at FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appYear]);
eq('首次按年安装到期日为一年后', date('Y-m-d', strtotime('+1 year')), $exp1);
t('首次安装返回到期日', ($r1['expire_at'] ?? '') === $exp1);

$r2 = YxBusiness::installApp(['app_id' => $appYear, 'customer_id' => $cid, 'domain' => 'year.example.com', 'order_id' => 4242]);
$exp2 = (string)YxDb::value('SELECT expire_at FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appYear]);
eq('未到期再次购买在原到期日上顺延一年', date('Y-m-d', strtotime($exp1 . ' +1 year')), $exp2);
eq('再次购买写入新订单号', 4242, (int)YxDb::value('SELECT order_id FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appYear]));

YxDb::exec('UPDATE ' . YxDb::table('app_install') . ' SET expire_at = ? WHERE app_id = ?', ['2020-01-01', $appYear]);
t('过期后权益判定为无', YxBusiness::entitlement($appYear, $cid) === null);
$r3 = YxBusiness::installApp(['app_id' => $appYear, 'customer_id' => $cid, 'domain' => 'year.example.com']);
$exp3 = (string)YxDb::value('SELECT expire_at FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appYear]);
t('过期后重新安装恢复有效权益', YxBusiness::entitlement($appYear, $cid) !== null);
t('过期后重新安装从今天重新起算', $exp3 > date('Y-m-d') && strpos((string)($r3['msg'] ?? ''), $exp3) !== false);

// 一次性商品（授权包类）：到期日应为空 = 永久有效
$appOnce = YxDb::insert('app', [
    'category_key' => 'pay', 'key' => 'selftest-once', 'name' => '自检一次性插件', 'version' => '1.0.0',
    'price' => 366.00, 'bill_type' => 'once', 'author_type' => 'official', 'type' => 'package',
    'edition_min' => 'basic', 'status' => 'on', 'created_at' => now(), 'updated_at' => now(),
]);
$insOnce = YxBusiness::installApp(['app_id' => $appOnce, 'customer_id' => $cid, 'domain' => 'once.example.com']);
t('一次性商品安装成功', !empty($insOnce['ok']));
eq('一次性商品到期日为空（永久有效）', '', (string)YxDb::value('SELECT expire_at FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appOnce]));
eq('一次性商品返回的到期日为空', '', (string)($insOnce['expire_at'] ?? 'x'));
t('一次性商品权益长期有效', YxBusiness::entitlement($appOnce, $cid) !== null);
YxBusiness::installApp(['app_id' => $appOnce, 'customer_id' => $cid, 'domain' => 'once.example.com', 'order_id' => 99]);
eq('一次性商品再次购买仍为永久', '', (string)YxDb::value('SELECT expire_at FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appOnce]));

// 同一域名被另一个客户购买：不得接管原客户名下的授权记录
YxBusiness::installApp(['app_id' => $appYear, 'customer_id' => $cid + 900000, 'domain' => 'year.example.com']);
eq('不同客户同域名各自持有记录', 2, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appYear]));
eq('原客户记录未被他人接管', $cid, (int)YxDb::value('SELECT customer_id FROM ' . YxDb::table('app_install') . ' WHERE app_id = ? AND customer_id = ?', [$appYear, $cid]));

// 资源型应用：重复获取同样顺延而非覆盖
$appRes = YxDb::insert('app', [
    'category_key' => 'pay', 'key' => 'selftest-res', 'name' => '自检资源', 'version' => '1.0.0',
    'price' => 30.00, 'bill_type' => 'year', 'author_type' => 'thirdparty', 'edition_min' => 'basic',
    'status' => 'on', 'created_at' => now(), 'updated_at' => now(),
]);
$appResRow = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$appRes]);
YxBusiness::grantResource($appResRow, $cid);
$re1 = (string)YxDb::value('SELECT expire_at FROM ' . YxDb::table('app_install') . ' WHERE app_id = ? AND domain = ?', [$appRes, 'resource']);
YxBusiness::grantResource($appResRow, $cid);
$re2 = (string)YxDb::value('SELECT expire_at FROM ' . YxDb::table('app_install') . ' WHERE app_id = ? AND domain = ?', [$appRes, 'resource']);
eq('资源重复获取在原到期日上顺延一年', date('Y-m-d', strtotime($re1 . ' +1 year')), $re2);
eq('资源只保留一条权益记录', 1, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$appRes]));

// 官方程序包：程序包本体免费下载，授权包（366 元/次）与下载解耦
$pkgId = YxDb::insert('app', [
    'category_key' => 'other', 'key' => 'selftest-pkg-official', 'name' => '自检正版程序包', 'version' => '1.6.2',
    'price' => 366.00, 'bill_type' => 'once', 'author_type' => 'official', 'type' => 'package',
    'edition_min' => 'basic', 'status' => 'on', 'package_path' => 'license/selftest.tar.gz',
    'created_at' => now(), 'updated_at' => now(),
]);
$pkgRow = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$pkgId]);
eq('程序包条目授权包按次收费 366', '¥366.00 一次性', YxBusiness::priceLabel($pkgRow));
t('程序包条目未购买授权时无权益记录', YxBusiness::entitlement($pkgId, $cid) === null);

$dl = (string)file_get_contents($root . '/api/store_download.php');
t('下载接口不再因程序包未购买授权而拦截', mb_strpos($dl, '该程序包需购买授权后方可下载') === false);
t('下载接口对程序包仅要求登录', mb_strpos($dl, '请先登录后免费下载程序包') !== false);
t('下载接口仍校验第三方资源权益', mb_strpos($dl, '该资源需购买或兑换后方可下载') !== false);

$pkgDetail = (string)file_get_contents($root . '/store/detail.php');
t('详情页程序包免费下载按钮不受授权限制', mb_strpos($pkgDetail, '免费下载程序包') !== false);
t('详情页提供授权包单独购买入口', mb_strpos($pkgDetail, '单独购买授权包') !== false);

$pkgLicPage = (string)file_get_contents($root . '/user/licenses.php');
t('我的授权页程序包可免费下载', mb_strpos($pkgLicPage, '免费下载正版程序包') !== false);
t('我的授权页未购买授权包时仍可下单授权包', mb_strpos($pkgLicPage, '单独购买授权包') !== false);

/* ---------------------------------------------------------------- 10. 渠道佣金 */
section('10. 渠道佣金');

$agentId = YxDb::insert('agent', [
    'name' => '自检渠道', 'username' => 'agent_test', 'password' => YxAuth::hash('password123'),
    'commission_rate' => 20.00, 'balance' => 0, 'status' => 1, 'created_at' => now(),
]);
$c2 = YxBusiness::createOrder(['customer_id' => $cid, 'type' => 'recharge', 'name' => '渠道订单', 'amount' => 100, 'agent_id' => $agentId]);
YxBusiness::payOrderExternal((int)$c2['order_id'], 'alipay', 'TRADE-002');
eq('佣金 20% × 100 = 20', 20.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$agentId]));
eq('佣金支出登记为 agent_commission', 1, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('transaction') . " WHERE category = 'agent_commission'"));

/* ---------------------------------------------------------------- 11. 支付网关签名 */
section('11. 支付网关签名与回调验签');

set_setting('pay_gateway_url', 'https://pay.example.com');
set_setting('pay_pid', '1001');
set_setting('pay_key', 'selftest-secret');
t('通道配置后 configured() 为真', YxPayment::configured());

$order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$rc['order_id']]);
$form  = YxPayment::buildSubmitForm($order, 'alipay');
t('下单表单包含签名与商户号', strpos($form, 'name="sign"') !== false && strpos($form, 'value="1001"') !== false);

$params = ['pid' => '1001', 'out_trade_no' => $order['sn'], 'money' => '100.00', 'trade_status' => 'TRADE_SUCCESS', 'type' => 'alipay'];
$params['sign'] = yx_pay_sign($params, 'selftest-secret');
t('正确签名通过验签', !empty(YxPayment::verifyNotify($params)['ok']));
$bad = $params;
$bad['money'] = '1.00';
t('篡改金额后验签失败', empty(YxPayment::verifyNotify($bad)['ok']));
$bad2 = $params;
$bad2['pid'] = '9999';
t('商户号不匹配验签失败', empty(YxPayment::verifyNotify($bad2)['ok']));

/* ---------------------------------------------------------------- 12. 报表与审计 */
section('12. 报表与审计');

$sum = YxFinance::summary(date('Y-m-01'), date('Y-m-d'));
t('区间收入大于 0', $sum['income'] > 0, (string)$sum['income']);
t('净利 = 收入 - 支出', abs($sum['profit'] - ($sum['income'] - $sum['expense'])) < 0.001);
t('近 7 日趋势返回 7 个数据点', count(YxFinance::trend(7)) === 7);
t('收入分类汇总非空', count(YxFinance::incomeByCategory(date('Y-m-01'), date('Y-m-d'))) > 0);
t('应收金额计算正确（含未结清账单）', YxFinance::receivable() >= 0);

log_action('selftest.run', 'selftest');
t('操作审计可写入', (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('admin_log')) === 1);

/* ---------------------------------------------------------------- 13. 结构迁移 */
section('13. 结构迁移（增量、只做加法）');

t('columnForAlter 不携带主键/自增（SQLite 限制）',
    stripos(YxSchema::columnForAlter('sqlite', 'int:pk:ai'), 'PRIMARY') === false
    && stripos(YxSchema::columnForAlter('sqlite', 'int:pk:ai'), 'AUTOINCREMENT') === false);
$alterSql = YxSchema::columnForAlter('sqlite', "varchar(20):default=web");
t('columnForAlter 保留默认值', strpos($alterSql, "DEFAULT 'web'") !== false, $alterSql);
t('columnForAlter 对无默认值的 NOT NULL 列做可空降级',
    stripos(YxSchema::columnForAlter('mysql', 'varchar(50)'), 'NOT NULL') === false);

$cols = YxSchema::existingColumns(YxDb::pdo(), 'sqlite', YxDb::table('product'));
t('existingColumns 能读出已有列', in_array('id', $cols, true) && in_array('code', $cols, true), implode(',', array_slice($cols, 0, 8)));
t('本地表已包含对接新字段（service.remote_id）',
    in_array('remote_id', YxSchema::existingColumns(YxDb::pdo(), 'sqlite', YxDb::table('service')), true));
t('本地表已包含对接新字段（order.appid）',
    in_array('appid', YxSchema::existingColumns(YxDb::pdo(), 'sqlite', YxDb::table('order')), true));

$again = YxSchema::sync(YxDb::pdo(), 'sqlite');
eq('结构已最新时 sync 返回空变更', 0, count($again));

YxDb::pdo()->exec('DROP TABLE ' . YxDb::table('upstream_map'));
$log = YxSchema::sync(YxDb::pdo(), 'sqlite');
t('缺失的表会被重新创建', YxSchema::tableExists(YxDb::pdo(), 'sqlite', YxDb::table('upstream_map')));
t('sync 返回了变更记录', count($log) > 0, implode('; ', $log));

/* ---------------------------------------------------------------- 14. 上游驱动 */
section('14. 上游驱动与自动开通');

$upId = YxDb::insert('upstream', [
    'name' => '自检模拟上游', 'driver' => 'mock', 'endpoint' => '', 'appid' => 'test',
    'secret' => yx_encrypt('unit-test-secret'), 'timeout' => 5, 'auto_setup' => 1,
    'status' => 1, 'created_at' => now(),
]);
t('上游供应商创建成功', $upId > 0);

$testRes = YxUpstream::test($upId);
t('连通性测试通过', !empty($testRes['ok']), json_encode($testRes, JSON_UNESCAPED_UNICODE));
eq('测试结果已回写', 1, (int)YxDb::value('SELECT last_test_ok FROM ' . YxDb::table('upstream') . ' WHERE id = ?', [$upId]));

$upRow = YxUpstream::row($upId);
$list  = YxUpstream::driver($upRow)->products();
t('可拉取上游产品', count($list) > 0, (string)count($list));

// 给已有产品绑定上游
YxDb::insert('upstream_map', [
    'product_id' => $pid, 'upstream_id' => $upId, 'remote_code' => 'mock-hk-2c2g',
    'remote_name' => '模拟·香港轻量', 'spec' => json_encode(['cpu' => 2, 'mem' => 2048, 'region' => 'hk']),
    'price' => 20.00, 'auto_setup' => 1, 'status' => 1, 'created_at' => now(),
]);
t('产品映射可解析', YxUpstream::mapForProduct($pid) !== null);

// 库存检测结论写回供应商行（后台列表读库展示，不依赖 session）
$sk = YxUpstream::stockCheck($pid);
t('库存检测命中映射产品', !empty($sk['known']) && $sk['stock'] === 8, json_encode($sk, JSON_UNESCAPED_UNICODE));
$snap = YxDb::one('SELECT * FROM ' . YxDb::table('upstream') . ' WHERE id = ?', [$upId]);
t('检测时间已回写供应商行', !empty($snap['last_stock_at']), (string)$snap['last_stock_at']);
eq('检测成功标记已回写', 1, (int)$snap['last_stock_ok']);
eq('供应商合计库存已回写', 11, (int)$snap['last_stock_total']);
eq('首次检测入库记为 0', 0, (int)$snap['last_stock_in']);
eq('首次检测出库记为 0', 0, (int)$snap['last_stock_out']);
t('结论文字可读', strpos((string)$snap['last_stock_msg'], '剩余 8 台') !== false, (string)$snap['last_stock_msg']);

$memo = YxUpstream::stockMemo($snap);
t('快照可被后台解析为有货', is_array($memo) && $memo['state'] === 'ok' && $memo['total'] === 11, json_encode($memo, JSON_UNESCAPED_UNICODE));

// 合计变化时记录方向：上次 15 → 本次 11 记为出库 4
YxDb::update('upstream', ['last_stock_total' => 15], 'id = :id', ['id' => $upId]);
YxUpstream::stockCheck($pid);
$snap = YxDb::one('SELECT * FROM ' . YxDb::table('upstream') . ' WHERE id = ?', [$upId]);
eq('库存减少记为出库量', 4, (int)$snap['last_stock_out']);
eq('出库时不记入库量', 0, (int)$snap['last_stock_in']);
// 上次 5 → 本次 11 记为入库 6
YxDb::update('upstream', ['last_stock_total' => 5], 'id = :id', ['id' => $upId]);
YxUpstream::stockCheck($pid);
$snap = YxDb::one('SELECT * FROM ' . YxDb::table('upstream') . ' WHERE id = ?', [$upId]);
eq('库存补充记为入库量', 6, (int)$snap['last_stock_in']);
eq('入库时不记出库量', 0, (int)$snap['last_stock_out']);

// 快照状态由字段推导：失败 / 未提供库存 / 无货 / 从未检测
t('从未检测时无快照', YxUpstream::stockMemo(['last_stock_at' => '']) === null);
eq('检测失败时状态为 fail', 'fail', YxUpstream::stockMemo([
    'last_stock_at' => now(), 'last_stock_ok' => 0, 'last_stock_total' => 0, 'last_stock_msg' => '获取上游产品列表失败：timeout',
])['state']);
eq('上游未提供库存时状态为 unknown', 'unknown', YxUpstream::stockMemo([
    'last_stock_at' => now(), 'last_stock_ok' => 1, 'last_stock_total' => 0,
    'last_stock_msg' => '上游响应中' . YxUpstream::STOCK_UNKNOWN_HINT,
])['state']);
eq('合计为 0 时状态为 empty', 'empty', YxUpstream::stockMemo([
    'last_stock_at' => now(), 'last_stock_ok' => 1, 'last_stock_total' => 0, 'last_stock_msg' => '库存对接成功，上游已售罄（剩余 0 台）',
])['state']);

// 没查成时不能把「未知」误记成出库：合计与出入库保持上次结论
$pidNoCode = YxDb::insert('product', [
    'group_id' => 0, 'name' => '自检空编码产品', 'type' => 'host', 'cycle' => 'month',
    'price' => 1.00, 'setup_fee' => 0, 'stock' => 0, 'auto_setup' => 0,
    'edition_min' => 'basic', 'status' => 1, 'sort' => 0, 'created_at' => now(),
]);
$mapNoCode = YxDb::insert('upstream_map', [
    'product_id' => $pidNoCode, 'upstream_id' => $upId, 'remote_code' => '', 'remote_name' => '',
    'spec' => '{}', 'price' => 1.00, 'auto_setup' => 0, 'status' => 1, 'created_at' => now(),
]);
$skBad = YxUpstream::stockCheck($pidNoCode);
t('未填上游编码时检测失败', empty($skBad['ok']) && strpos((string)$skBad['msg'], '产品编码') !== false, json_encode($skBad, JSON_UNESCAPED_UNICODE));
$snapBad = YxDb::one('SELECT * FROM ' . YxDb::table('upstream') . ' WHERE id = ?', [$upId]);
eq('检测失败保留上次合计（不误记出库）', 11, (int)$snapBad['last_stock_total']);
eq('检测失败不记出库', 0, (int)$snapBad['last_stock_out']);
eq('检测失败时状态为 fail', 'fail', YxUpstream::stockMemo($snapBad)['state']);
YxDb::delete('upstream_map', 'id = :id', ['id' => $mapNoCode]);
YxDb::delete('product', 'id = :id', ['id' => $pidNoCode]);

// 用未绑定上游的产品测试失败分支
$pidFree = YxDb::insert('product', [
    'group_id' => 0, 'name' => '自检无上游产品', 'type' => 'host', 'cycle' => 'month',
    'price' => 10.00, 'setup_fee' => 0, 'stock' => -1, 'auto_setup' => 1,
    'edition_min' => 'basic', 'status' => 1, 'sort' => 0, 'created_at' => now(),
]);
$svcFree = YxBusiness::createService([
    'customer_id' => $cid, 'product_id' => $pidFree, 'name' => '未绑定上游业务', 'billing_cycle' => 'month', 'price' => 10, 'status' => 'pending',
]);
$r = YxProvision::create($svcFree);
t('未绑定上游的产品开通被拒绝并给出提示', empty($r['ok']) && strpos($r['msg'], '未绑定上游') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));

// 绑定上游的产品走自动开通
$svcUp = YxBusiness::createService([
    'customer_id' => $cid, 'product_id' => $pid, 'name' => '自动开通业务', 'billing_cycle' => 'month', 'price' => 50, 'status' => 'pending',
]);
$act = YxBusiness::activateService($svcUp);
t('开通请求被转为上游任务（异步）', !empty($act['queued']), json_encode($act, JSON_UNESCAPED_UNICODE));
eq('等待上游期间业务状态为 pending', 'pending', YxDb::value('SELECT status FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcUp]));
eq('已记录上游供应商', $upId, (int)YxDb::value('SELECT upstream_id FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcUp]));

$run = YxTask::run(20);
t('任务队列执行成功', $run['done'] >= 1, json_encode($run['items'], JSON_UNESCAPED_UNICODE));

$svcRow = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcUp]);
eq('上游开通后业务状态为 active', 'active', $svcRow['status']);
t('已回写上游实例编号', strpos((string)$svcRow['remote_id'], 'MOCK-') === 0, (string)$svcRow['remote_id']);
t('已回写 IP', filter_var((string)$svcRow['ip'], FILTER_VALIDATE_IP) !== false, (string)$svcRow['ip']);
t('登录密码已加密存储并可解密', strpos((string)$svcRow['login_pass'], 'enc:') === 0 && yx_decrypt((string)$svcRow['login_pass']) !== '');
eq('上游状态为 running', 'running', (string)$svcRow['remote_status']);
t('自动写入到期日', !empty($svcRow['next_due_date']));

$r = YxProvision::create($svcUp);
t('重复开通被拦截（幂等）', !empty($r['ok']) && strpos($r['msg'], '已在上游开通') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = YxProvision::power($svcUp, 'stop');
t('上游关机成功', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
eq('关机后上游状态 stopped', 'stopped', YxDb::value('SELECT remote_status FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcUp]));
$r = YxProvision::power($svcUp, 'start');
t('上游开机成功', !empty($r['ok']));
$r = YxProvision::power($svcUp, 'illegal');
t('非法电源操作被拒绝', empty($r['ok']));
$r = YxProvision::renew($svcUp, 'month');
t('上游续费成功', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = YxProvision::reinstall($svcUp, ['os' => 'centos7']);
t('上游重装成功', !empty($r['ok']));
$r = YxProvision::sync($svcUp);
t('上游状态同步成功', !empty($r['ok']) && $r['status'] === 'running', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = YxProvision::destroy($svcUp);
t('上游销毁成功', !empty($r['ok']));
eq('销毁后上游状态 destroyed', 'destroyed', YxDb::value('SELECT remote_status FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcUp]));

/* ---------------------------------------------------------- 14.1 客户自有上游 */
section('14.1 客户自有上游（购买自动对接）');

t('对接协议包含魔方财务', isset(YxCustomerUpstream::DRIVERS['zjmf']));
t('对接协议包含乐众系统', isset(YxCustomerUpstream::DRIVERS['lezhong']));

$cuSave = YxCustomerUpstream::save($cid, [
    'name' => '自检客户上游', 'driver' => 'mock', 'endpoint' => '', 'appid' => '', 'secret' => '',
]);
t('客户对接保存成功', !empty($cuSave['ok']), json_encode($cuSave, JSON_UNESCAPED_UNICODE));
t('保存后自动对接成功并给出成功提示', !empty($cuSave['test_ok']) && strpos((string)$cuSave['msg'], '✅') !== false, (string)$cuSave['msg']);
eq('对接成功后回写测试状态', 1, (int)YxDb::value('SELECT last_test_ok FROM ' . YxDb::table('customer_upstream') . ' WHERE id = ?', [(int)$cuSave['id']]));

$cuProducts = YxCustomerUpstream::products((int)$cuSave['id'], $cid);
t('客户可拉取上游产品', !empty($cuProducts['ok']) && count($cuProducts['items']) > 0);
$cuStock = [];
foreach ($cuProducts['items'] as $item) {
    $cuStock[$item['code']] = $item['stock'];
}
t('产品条目保留货源库存', isset($cuStock['mock-hk-2c2g']) && $cuStock['mock-hk-2c2g'] === 8, json_encode($cuStock));
t('零库存产品如实返回 0', isset($cuStock['mock-us-2c2g']) && $cuStock['mock-us-2c2g'] === 0, json_encode($cuStock));

// 库存字段解析：数值照收，-1 为不限量，非数字文案视为「未提供」（不能当 0 售罄）
eq('整数库存原样解析', 7, YxUpstreamBase::stockValue(7));
eq('数字字符串库存可解析', 12, YxUpstreamBase::stockValue('12'));
eq('小数库存取整', 5, YxUpstreamBase::stockValue(5.4));
eq('库存 -1 表示不限量', -1, YxUpstreamBase::stockValue(-1));
t('非数字库存视为未提供', YxUpstreamBase::stockValue('充足') === null, var_export(YxUpstreamBase::stockValue('充足'), true));
t('空字符串库存视为未提供', YxUpstreamBase::stockValue('') === null);
t('缺失库存字段视为未提供', YxUpstreamBase::stockValue(null) === null);

$cuBad = YxCustomerUpstream::save($cid, [
    'name' => '非法配置', 'driver' => 'zjmf', 'endpoint' => 'https://1.1.1.1/open',
    'appid' => 'x', 'secret' => 'y', 'config' => '{"auth":',
]);
t('非法协议配置被拒绝', empty($cuBad['ok']) && strpos((string)$cuBad['msg'], 'JSON') !== false, json_encode($cuBad, JSON_UNESCAPED_UNICODE));

$cuRow = YxCustomerUpstream::driverRow([
    'driver' => 'zjmf', 'config' => '{"auth":"append_secret"}', 'secret' => yx_encrypt('s'),
]);
t('魔方财务驱动可实例化', YxUpstream::driver($cuRow) instanceof YxUpstreamZjmf);
eq('魔方财务走内置协议配置', 'api_version', array_key_first(YxUpstream::driver($cuRow)->config()));
t('魔方财务模板为内置协议', isset(YxUpstream::defaultConfigFor('zjmf')['endpoints']['products']));
t('乐众模板含说明', strpos((string)(YxUpstream::defaultConfigFor('lezhong')['_note'] ?? ''), '乐众') !== false);

$cuDel = YxCustomerUpstream::remove((int)$cuSave['id'], $cid);
t('客户对接可删除', !empty($cuDel['ok']), json_encode($cuDel, JSON_UNESCAPED_UNICODE));

/* ---- 魔方商品树（cart 通道）与价格单位 ---- */

/** 造一个只用于解析测试的魔方驱动；不发起网络请求，直接喂响应体 */
function yxZjmfProbe(array $cfg = []): YxUpstreamZjmf
{
    return new YxUpstreamZjmf([
        'id' => 0, 'driver' => 'zjmf', 'endpoint' => 'https://cart.example.com',
        'appid' => 'a', 'secret' => yx_encrypt('b'), 'timeout' => 2,
        'config' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : '',
    ]);
}

// 前台购物车 /cart/prolist 的真实结构：data.fgs[].group[].products[]
$cartBody = json_encode(['status' => 200, 'msg' => 'ok', 'data' => ['fgs' => [
    ['id' => 1, 'name' => '云服务器', 'group' => [
        ['id' => 11, 'name' => '香港', 'products' => [
            ['id' => 101, 'name' => '香港 2核2G', 'product_price' => 59.00, 'qty' => 12],
            ['id' => 102, 'name' => '香港 4核8G', 'product_price' => 129.50, 'qty' => 0],
        ]],
    ]],
    ['id' => 2, 'name' => '物理机', 'group' => [
        ['id' => 21, 'name' => '美国', 'products' => [
            ['id' => 201, 'name' => '美国 E5', 'product_price' => 899.00, 'qty' => -1],
        ]],
    ]],
]]], JSON_UNESCAPED_UNICODE);

$zjmfCart = yxZjmfProbe(['products_via' => 'cart', 'item_map' => [
    'code' => 'id', 'name' => 'name', 'price' => 'product_price', 'stock' => 'qty', 'spec' => 'config',
]]);
$cartEx = $zjmfCart->extract($cartBody);
$cartList = YxUpstreamBase::normalizeItems((array)($cartEx['list'] ?? []));
eq('商品树三层结构被完整展开', 3, count($cartList));
$cartByCode = [];
foreach ($cartList as $one) {
    $cartByCode[$one['code']] = $one;
}
t('分类 -> 组 -> 商品都被收集', isset($cartByCode['101'], $cartByCode['102'], $cartByCode['201']), implode(',', array_keys($cartByCode)));
eq('商品名取自 name', '香港 2核2G', $cartByCode['101']['name'] ?? '');
eq('cart 通道价格按元计，不再除以 100', '59', (string)$cartByCode['101']['price']);
eq('cart 通道小数价格保留', '129.5', (string)$cartByCode['102']['price']);
eq('cart 通道库存照收', 12, $cartByCode['101']['stock']);
eq('cart 通道售罄如实为 0', 0, $cartByCode['102']['stock']);
eq('cart 通道不限量保留 -1', -1, $cartByCode['201']['stock']);

// 价格单位：显式配置优先，未配置时默认为元（实测魔方 v1 开放接口的 price 就是元）
eq('cart 通道默认价格为元', '59', (string)yxZjmfProbe(['products_via' => 'cart', 'item_map' => ['price' => 'product_price']])
    ->extract($cartBody)['list'][0]['price']);
eq('openapi 通道默认价格也按元读取，不再除以 100', '123', (string)yxZjmfProbe(['item_map' => ['price' => 'price']])
    ->extract(json_encode(['status' => 200, 'data' => [['id' => 1, 'name' => 'x', 'price' => 123]]]))['list'][0]['price']);
eq('price_unit=yuan 时不再换算（openapi 也适用）', '123', (string)yxZjmfProbe(['price_unit' => 'yuan', 'item_map' => ['price' => 'price']])
    ->extract(json_encode(['status' => 200, 'data' => [['id' => 1, 'name' => 'x', 'price' => 123]]]))['list'][0]['price']);
eq('price_unit=cent 时按分换算（cart 也适用）', '0.59', (string)yxZjmfProbe(['products_via' => 'cart', 'price_unit' => 'cent', 'item_map' => ['price' => 'product_price']])
    ->extract($cartBody)['list'][0]['price']);

// 商品树为空时不能误判成「解析成功但没有产品」，也不该把分类当商品
$emptyTree = $zjmfCart->extract(json_encode(['status' => 200, 'data' => ['fgs' => [['id' => 1, 'name' => '空分类', 'group' => []]]]], JSON_UNESCAPED_UNICODE));
t('空商品树不产生产品条目', empty($emptyTree['list']), json_encode($emptyTree['list'] ?? null, JSON_UNESCAPED_UNICODE));

// 非商品树响应（如开放接口的普通列表）不应被树逻辑吃掉
$flat = $zjmfCart->extract(json_encode(['status' => 200, 'data' => [['id' => 7, 'name' => '扁平产品', 'price' => 100]]], JSON_UNESCAPED_UNICODE));
eq('扁平列表仍按普通列表解析', 1, count((array)($flat['list'] ?? [])));

// 未开通开放接口时走 cart 通道：路径可配，缺配置/不可达时给可读报错而不是异常
t('cart 通道路径默认指向 /cart/prolist', YxUpstreamZjmf::defaultConfig()['cart_products_path'] === '/cart/prolist');
eq('商品树层级默认取复数 products（真实魔方字段）', 'products', YxUpstreamZjmf::defaultConfig()['cart_tree']['group']);
// 老配置写成单数 product 时也要能解析（兼容升级前的部署）
$legacy = yxZjmfProbe(['products_via' => 'cart', 'cart_tree' => ['fgs' => 'group', 'group' => 'product'], 'item_map' => [
    'code' => 'id', 'name' => 'name', 'price' => 'product_price', 'stock' => 'qty',
]])->extract($cartBody);
eq('cart_tree 写单数 product 的老配置仍可解析', 3, count((array)($legacy['list'] ?? [])));
$cartFail = yxZjmfProbe(['products_via' => 'cart', 'cart_products_path' => ''])->productsInfo();
t('cart 通道未配置路径时给出明确报错', empty($cartFail['ok']) && strpos((string)$cartFail['msg'], 'cart_products_path') !== false, (string)$cartFail['msg']);
$cartFail2 = yxZjmfProbe(['products_via' => 'cart', 'cart_products_path' => '/cart/prolist'])
    ->productsInfo();   // 域名不可达：应失败而不是抛异常
t('cart 通道请求失败时返回错误而非异常', empty($cartFail2['ok']) && $cartFail2['list'] === [], json_encode($cartFail2, JSON_UNESCAPED_UNICODE));

/* ---- 魔方 v1 协议形态：默认版本、GET 查询、报错指向实际地址 ---- */

/** 用桩替换 v1 的 GET 发送，记录请求形态，不发真实网络请求 */
class YxZjmfGetSpy extends YxUpstreamZjmf
{
    public $lastUrl   = '';
    public $lastQuery = [];
    public $lastPost  = [];
    public $body      = '';

    protected function sendGet(string $url, array $query): array
    {
        $this->lastUrl   = $url;
        $this->lastQuery = $query;
        return [true, 200, $this->body];
    }

    protected function sendPostForm(string $url, array $data): array
    {
        $this->lastUrl  = $url;
        $this->lastPost = $data;
        return [true, 200, $this->body];
    }
}

$spy = new YxZjmfGetSpy([
    'id' => 0, 'driver' => 'zjmf', 'endpoint' => 'https://zjmf.example.com/',
    'appid' => '100024', 'secret' => yx_encrypt('k'), 'timeout' => 2, 'config' => '',
]);
$spy->body = json_encode(['status' => 200, 'msg' => '请求成功', 'data' => ['list' => [
    ['id' => 236, 'name' => '上海电信-16H16G-30M', 'price' => '599.00', 'qty' => 225],
]]], JSON_UNESCAPED_UNICODE);

eq('魔方默认协议为 v1（实测多数站点没有 v2 登录）', 'v1', YxUpstreamZjmf::defaultConfig()['api_version']);
eq('连通性探测默认走真实商品接口', '/api/v1/product', YxUpstreamZjmf::defaultConfig()['endpoints']['test']);
eq('魔方默认价格单位为元', 'yuan', YxUpstreamZjmf::defaultConfig()['price_unit']);

$v1 = $spy->request('products');
t('v1 查询类动作走 GET 且路径为 /api/v1/product', $spy->lastUrl === 'https://zjmf.example.com/api/v1/product', $spy->lastUrl);
t('v1 查询串携带账户 ID 与密钥', ($spy->lastQuery['username'] ?? '') === '100024' && ($spy->lastQuery['password'] ?? '') === 'k', json_encode($spy->lastQuery));
t('v1 产品列表解析成功', !empty($v1['ok']) && count((array)($v1['list'] ?? [])) === 1, json_encode($v1, JSON_UNESCAPED_UNICODE));
eq('v1 商品价格按元（599.00 不除以 100）', '599', (string)(YxUpstreamBase::normalizeItems($v1['list'])[0]['price'] ?? ''));
eq('v1 商品库存取自 qty', 225, YxUpstreamBase::normalizeItems($v1['list'])[0]['stock'] ?? null);

$spy->body = "<!DOCTYPE html>\n<html><head><title>首页</title></head></html>";
$html = $spy->request('create');
t('v1 写入类动作仍走 POST 表单', $spy->lastUrl === 'https://zjmf.example.com/api/v1/host' && ($spy->lastPost['username'] ?? '') === '100024', $spy->lastUrl);
t('把网页当接口响应时提示路径不存在', empty($html['ok']) && strpos((string)$html['msg'], '网页') !== false, (string)($html['msg'] ?? ''));
t('报错带上实际请求地址便于核对', strpos((string)$html['msg'], 'https://zjmf.example.com/api/v1/host') !== false, (string)($html['msg'] ?? ''));

$spy->body = json_encode(['status' => 200, 'msg' => '请求成功', 'data' => ['list' => []]], JSON_UNESCAPED_UNICODE);
$tp = $spy->test();
t('v1 连通性测试成功时给出 GET 路径', !empty($tp['ok']) && strpos((string)$tp['msg'], 'GET /api/v1/product') !== false, (string)$tp['msg']);


/* ---------------------------------------------------------------- 15. 任务队列 */
section('15. 任务队列（去重 / 重试 / 取消）');

$t1 = YxTask::push('upstream.sync', ['ref_type' => 'service', 'ref_id' => $svcUp, 'unique' => false]);
$t2 = YxTask::push('upstream.sync', ['ref_type' => 'service', 'ref_id' => $svcUp, 'unique' => false]);
t('unique=false 时每次入队都是新任务', $t1 !== $t2);
$t3 = YxTask::push('upstream.renew', ['ref_type' => 'service', 'ref_id' => $svcUp]);
$t4 = YxTask::push('upstream.renew', ['ref_type' => 'service', 'ref_id' => $svcUp]);
eq('unique 默认同类型同对象去重', $t3, $t4);

YxTask::cancel($t1);
eq('任务可取消', 'cancelled', YxDb::value('SELECT status FROM ' . YxDb::table('task') . ' WHERE id = ?', [$t1]));

// 构造一个必然失败的任务：未绑定上游的产品
$failId = YxTask::push('upstream.create', ['ref_type' => 'service', 'ref_id' => $svcFree, 'max_attempts' => 2]);
YxTask::run(50);
$failRow = YxDb::one('SELECT * FROM ' . YxDb::table('task') . ' WHERE id = ?', [$failId]);
eq('首次失败后进入重试', 'pending', $failRow['status']);
eq('尝试次数累计', 1, (int)$failRow['attempts']);
t('设置了下次重试时间', !empty($failRow['next_retry_at']));
t('记录了失败原因', strpos((string)$failRow['error'], '未绑定上游') !== false, (string)$failRow['error']);

// 把下次重试时间提前，再跑一次 → 达到 max_attempts 置为 failed
YxDb::exec('UPDATE ' . YxDb::table('task') . ' SET next_retry_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 1), $failId]);
YxTask::run(50);
eq('超过重试上限后标记为失败', 'failed', YxDb::value('SELECT status FROM ' . YxDb::table('task') . ' WHERE id = ?', [$failId]));
t('失败任务可手动重试', YxTask::retry($failId));
eq('重试后回到待执行', 'pending', YxDb::value('SELECT status FROM ' . YxDb::table('task') . ' WHERE id = ?', [$failId]));
YxTask::cancel($failId);

// 卡死任务释放
$stuck = YxTask::push('upstream.sync', ['ref_type' => 'service', 'ref_id' => $svcFree, 'unique' => false]);
YxDb::exec('UPDATE ' . YxDb::table('task') . " SET status = 'running', locked_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 9999), $stuck]);
t('卡死任务会被释放回待执行', YxTask::releaseStuck() >= 1);
eq('释放后状态为 pending', 'pending', YxDb::value('SELECT status FROM ' . YxDb::table('task') . ' WHERE id = ?', [$stuck]));

$stats = YxTask::stats();
t('队列统计包含各状态', isset($stats['pending'], $stats['failed'], $stats['done']));
t('任务类型名称可读', YxTask::typeName('upstream.create') === '上游开通');

/* ---------------------------------------------------------------- 16. 下游接口鉴权 */
section('16. 下游开放接口 · 鉴权');

$secret = 'unit-test-app-secret';
$appId  = YxDb::insert('api_app', [
    'name' => '自检下游应用', 'appid' => 'yxapptest0001', 'secret' => yx_encrypt($secret),
    'owner_type' => 'agent', 'owner_id' => $agentId, 'notify_url' => '',
    'ip_whitelist' => '', 'rate_limit' => 1000, 'status' => 1, 'created_at' => now(),
]);
t('下游应用创建成功', $appId > 0);
$app = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ?', ['yxapptest0001']);

$mk = function (string $action, array $extra = []) use ($secret, $app) {
    $p = array_merge([
        'appid'  => $app['appid'],
        'action' => $action,
        'ts'     => (string)time(),
        'nonce'  => random_nonce(16),
    ], $extra);
    $p['sign'] = YxApi::sign($p, $secret);
    return $p;
};

$p = $mk('ping');
t('正确签名通过鉴权', !empty(YxApi::auth($p)['ok']));

$bad = $p;
$bad['sign'] = str_repeat('a', 64);
$bad['nonce'] = random_nonce(16);
$r = YxApi::auth($bad);
eq('错误签名被拒绝（401）', YxApi::ERR_SIGN, $r['code']);

$stale = $mk('ping');
$stale['ts'] = (string)(time() - 3600);
$stale['sign'] = YxApi::sign($stale, $secret);
$r = YxApi::auth($stale);
eq('过期时间戳被拒绝', YxApi::ERR_SIGN, $r['code']);

$r = YxApi::auth($p);
eq('重复 nonce 被拒绝（防重放）', YxApi::ERR_SIGN, $r['code']);

$r = YxApi::auth(['appid' => $app['appid'], 'action' => 'ping']);
eq('缺少必要参数返回 400', YxApi::ERR_PARAM, $r['code']);

$unknown = $mk('not.exist');
$r = YxApi::auth($unknown);
eq('未知动作返回 400', YxApi::ERR_PARAM, $r['code']);

$noApp = $mk('ping');
$noApp['appid'] = 'nonexist';
$noApp['sign'] = YxApi::sign($noApp, $secret);
$r = YxApi::auth($noApp);
eq('不存在的 appid 被拒绝', YxApi::ERR_SIGN, $r['code']);

YxDb::update('api_app', ['status' => 0], 'id = :id', ['id' => $appId]);
$r = YxApi::auth($mk('ping'));
eq('被禁用的应用返回 403', YxApi::ERR_FORBIDDEN, $r['code']);
YxDb::update('api_app', ['status' => 1], 'id = :id', ['id' => $appId]);

YxDb::update('api_app', ['ip_whitelist' => '10.9.9.9'], 'id = :id', ['id' => $appId]);
$r = YxApi::auth($mk('ping'));
eq('IP 白名单外的请求被拒绝', YxApi::ERR_FORBIDDEN, $r['code']);
t('IP 白名单支持通配', YxApi::ipAllowed('10.0.*', '10.0.1.5') && !YxApi::ipAllowed('10.0.*', '10.1.1.5'));
t('IP 白名单支持网段', YxApi::ipAllowed('192.168.1.0/24', '192.168.1.77') && !YxApi::ipAllowed('192.168.1.0/24', '192.168.2.77'));
t('通配按段匹配，不会把 1.2.30.4 误判进 1.2.3.*', !YxApi::ipAllowed('1.2.3.*', '1.2.30.4') && YxApi::ipAllowed('1.2.3.*', '1.2.3.4'));
t('IPv6 网段规则按前缀匹配', YxApi::ipAllowed('2001:db8::/32', '2001:db8::1') && !YxApi::ipAllowed('2001:db8::/32', '2001:db9::1'));
t('IPv6 网段不再整段放行无关地址', !YxApi::ipAllowed('2001:db8::/32', '2408:8000::1'));
t('地址族不同不互相匹配', !YxApi::ipAllowed('10.0.0.0/8', '2001:db8::1') && !YxApi::ipAllowed('2001:db8::/32', '10.0.0.1'));
t('来源 IP 非法时不放行', !YxApi::ipAllowed('10.0.*', ''));
t('IP 白名单为空时不限制', YxApi::ipAllowed('', '1.2.3.4'));
YxDb::update('api_app', ['ip_whitelist' => ''], 'id = :id', ['id' => $appId]);

// 频次限制：插入一条刚发生的调用日志，再把上限设为 1
YxDb::update('api_app', ['rate_limit' => 1], 'id = :id', ['id' => $appId]);
YxApi::log($app['appid'], 'ping', 0, 'ok', 1, []);
$r = YxApi::auth($mk('ping'));
eq('超过频次上限返回 429', YxApi::ERR_RATELIMIT, $r['code']);
YxDb::update('api_app', ['rate_limit' => 1000], 'id = :id', ['id' => $appId]);
YxDb::exec('DELETE FROM ' . YxDb::table('api_log') . ' WHERE appid = ?', [$app['appid']]);

$app = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ?', ['yxapptest0001']);

/* ---------------------------------------------------------------- 17. 下游接口动作 */
section('17. 下游开放接口 · 动作');

$D = function (string $action, array $extra = []) use ($app) {
    return YxApiGateway::dispatch($action, array_merge(['action' => $action], $extra), $app);
};

$r = $D('ping');
eq('ping 返回成功', YxApi::OK, $r['code']);
t('ping 含服务端信息', !empty($r['data']['pong']) && !empty($r['data']['version']));

$r = $D('product.list');
t('产品列表返回数据', $r['code'] === YxApi::OK && $r['data']['total'] > 0, (string)($r['data']['total'] ?? 0));

$r = $D('product.detail', ['product_id' => $pid]);
eq('产品详情返回成功', YxApi::OK, $r['code']);
eq('产品详情包含 4 种计费周期', 4, count($r['data']['cycles'] ?? []));
t('产品详情标注上游绑定', !empty($r['data']['upstream']));

$r = $D('product.detail', ['product_id' => 999999]);
eq('不存在的产品返回 404', YxApi::ERR_NOTFOUND, $r['code']);

$r = $D('order.create', ['product_id' => $pid, 'cycle' => 'month', 'external_user_id' => 'down-user-1', 'external_sn' => 'DS-0001']);
eq('接口下单成功', YxApi::OK, $r['code']);
$apiOrderSn = $r['data']['order_sn'] ?? '';
t('返回订单号', $apiOrderSn !== '');
eq('订单实付为月付价', 50.0, (float)($r['data']['total'] ?? 0));

$r2 = $D('order.create', ['product_id' => $pid, 'cycle' => 'month', 'external_user_id' => 'down-user-1', 'external_sn' => 'DS-0001']);
eq('相同 external_sn 幂等返回同一订单', $apiOrderSn, $r2['data']['order_sn'] ?? '');

$apiCid = (int)YxDb::value('SELECT customer_id FROM ' . YxDb::table('api_customer_map') . " WHERE appid = ? AND external_id = 'down-user-1'", [$app['appid']]);
t('下游终端用户已自动建档并映射', $apiCid > 0);
eq('自动建档的客户归属该渠道', $agentId, (int)YxDb::value('SELECT agent_id FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$apiCid]));

$r = $D('order.query', ['order_sn' => $apiOrderSn]);
eq('订单查询成功', YxApi::OK, $r['code']);

$r = $D('order.pay', ['order_sn' => $apiOrderSn, 'method' => 'manual']);
eq('接口标记线下收款成功', YxApi::OK, $r['code']);
eq('订单已支付', 'paid', YxDb::value('SELECT status FROM ' . YxDb::table('order') . ' WHERE sn = ?', [$apiOrderSn]));

$r = $D('order.query', ['order_sn' => $apiOrderSn]);
$apiSvcSn = $r['data']['service']['service_sn'] ?? '';
t('支付后自动生成业务实例', $apiSvcSn !== '');
eq('有上游绑定时业务先为待开通', 'pending', (string)($r['data']['service']['status'] ?? ''));

YxTask::run(50);
eq('队列执行后业务已开通', 'active', YxDb::value('SELECT status FROM ' . YxDb::table('service') . ' WHERE sn = ?', [$apiSvcSn]));

$r = $D('service.list', ['external_user_id' => 'down-user-1']);
t('业务列表按终端用户过滤', $r['code'] === YxApi::OK && $r['data']['total'] >= 1, (string)($r['data']['total'] ?? 0));

$r = $D('service.detail', ['service_sn' => $apiSvcSn]);
eq('业务详情返回成功', YxApi::OK, $r['code']);
t('业务详情含登录信息', !empty($r['data']['login_user']) && !empty($r['data']['login_pass']));

// 电源操作：只下发上游，不改变计费状态
$r = $D('service.operate', ['service_sn' => $apiSvcSn, 'op' => 'stop']);
eq('电源关机已入队', YxApi::OK, $r['code']);
t('电源操作返回 queued 与任务号', !empty($r['data']['queued']) && !empty($r['data']['task_id']), json_encode($r['data'], JSON_UNESCAPED_UNICODE));
eq('电源操作不改变计费状态', 'active', YxDb::value('SELECT status FROM ' . YxDb::table('service') . ' WHERE sn = ?', [$apiSvcSn]));
$r = $D('service.operate', ['service_sn' => $apiSvcSn, 'op' => 'start']);
t('电源开机已入队', $r['code'] === YxApi::OK && !empty($r['data']['queued']));
$r = $D('service.operate', ['service_sn' => $apiSvcSn, 'op' => 'reboot']);
t('重启已入队', $r['code'] === YxApi::OK && !empty($r['data']['queued']));

// 业务暂停 / 恢复：改变计费状态，并同步下发上游电源
$r = $D('service.operate', ['service_sn' => $apiSvcSn, 'op' => 'suspend']);
eq('业务暂停成功', YxApi::OK, $r['code']);
eq('业务状态已暂停', 'suspended', YxDb::value('SELECT status FROM ' . YxDb::table('service') . ' WHERE sn = ?', [$apiSvcSn]));
$r = $D('service.operate', ['service_sn' => $apiSvcSn, 'op' => 'unsuspend']);
eq('业务恢复成功', YxApi::OK, $r['code']);
eq('业务状态已恢复', 'active', YxDb::value('SELECT status FROM ' . YxDb::table('service') . ' WHERE sn = ?', [$apiSvcSn]));

$r = $D('service.operate', ['service_sn' => $apiSvcSn, 'op' => 'badop']);
eq('非法操作被拒绝', YxApi::ERR_PARAM, $r['code']);
$r = $D('service.operate', ['service_sn' => $apiSvcSn]);
eq('缺少 op 参数被拒绝', YxApi::ERR_PARAM, $r['code']);

$r = $D('service.renew', ['service_sn' => $apiSvcSn, 'cycle' => 'month']);
eq('接口续费成功', YxApi::OK, $r['code']);

$r = $D('balance.query', ['external_user_id' => 'down-user-1']);
eq('余额查询成功', YxApi::OK, $r['code']);

$r = $D('license.query', ['domain' => 'authorized-site.com']);
eq('授权查询成功', YxApi::OK, $r['code']);
t('授权查询返回已授权', !empty($r['data']['authorized']));

// 数据范围隔离：另一个客户的应用不能看到上面的业务
$otherCid = YxDb::insert('customer', [
    'username' => 'other_owner', 'email' => 'other@example.com', 'password' => YxAuth::hash('password123'),
    'balance' => 0, 'credit' => 0, 'points' => 0, 'level' => 'normal', 'agent_id' => 0, 'status' => 1, 'created_at' => now(),
]);
$otherSecret = 'other-app-secret';
YxDb::insert('api_app', [
    'name' => '其它客户应用', 'appid' => 'yxapptest0002', 'secret' => yx_encrypt($otherSecret),
    'owner_type' => 'customer', 'owner_id' => $otherCid, 'rate_limit' => 1000, 'status' => 1, 'created_at' => now(),
]);
$otherApp = YxDb::one('SELECT * FROM ' . YxDb::table('api_app') . ' WHERE appid = ?', ['yxapptest0002']);
$r = YxApiGateway::dispatch('service.detail', ['service_sn' => $apiSvcSn], $otherApp);
eq('跨应用访问他人业务返回 404', YxApi::ERR_NOTFOUND, $r['code']);
$r = YxApiGateway::dispatch('order.query', ['order_sn' => $apiOrderSn], $otherApp);
eq('跨应用查询他人订单返回 404', YxApi::ERR_NOTFOUND, $r['code']);

/* ---------------------------------------------------------------- 18. 下游回调 */
section('18. 下游事件回调');

YxDb::update('api_app', ['notify_url' => ''], 'id = :id', ['id' => $appId]);
$before = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('task') . " WHERE type = 'downstream.notify'");
YxApi::notify('yxapptest0001', 'order.paid', ['order_sn' => 'X']);
eq('未配置回调地址时不入队', $before, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('task') . " WHERE type = 'downstream.notify'"));

YxDb::update('api_app', ['notify_url' => 'http://127.0.0.1:9/notify'], 'id = :id', ['id' => $appId]);
YxApi::notify('yxapptest0001', 'order.paid', ['order_sn' => 'X']);
t('配置回调地址后入队通知任务',
    (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('task') . " WHERE type = 'downstream.notify'") > $before);

$r = YxApiNotify::send(['appid' => 'yxapptest0001', 'event' => 'order.paid', 'data' => ['x' => 1]]);
t('回调不可达时返回失败（交由队列重试）', empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));

$r = YxApiNotify::send(['appid' => 'not-exist-app', 'event' => 'x', 'data' => []]);
t('回调目标应用不存在时返回失败', empty($r['ok']));

/* ---------------------------------------------------------------- 19. 开源版模式 */
section('19. 开源版授权模式');

// 发行默认模式（正式版包为 official，开源版包为 community）——不假设具体值，只做合法性检查
$buildDefault = (string)config('license.mode', 'official');
$buildDefault = $buildDefault === 'community' ? 'community' : 'official';
t('发行默认模式取值合法', in_array($buildDefault, ['official', 'community'], true), $buildDefault);

/**
 * 发行包构建时会把这个键写死进 includes/config.php。
 * 一旦写死，config 的优先级高于 setting 表（见 YxLicense::mode），
 * 再用 set_setting('license_mode', ...) 切换模式不会生效，
 * 因此下面的「模式切换」用例只能在 config 未写死 mode 时才成立。
 */
$modePinned = (bool)preg_match("/\n\s*'mode'\s*=>\s*'(official|community)',/", (string)@file_get_contents($root . '/includes/config.php'));
$switchable = !$modePinned;

// 显式切到正式版分支再测（不依赖发行默认值）
if ($switchable) {
    set_setting('license_mode', 'official');
    YxLicense::flushCache();
    t('正式版模式生效', YxLicense::mode() === 'official');
    t('正式版下未授权域名判定为未授权', !YxLicense::query('community-probe.test', true)['authorized']);
}

$logBefore = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('license_log'));
if ($switchable) {
    set_setting('license_mode', 'community');
    YxLicense::flushCache();
    t('切换为开源版', YxLicense::isCommunity());
}

$rc = $switchable
    ? YxLicense::query('community-probe.test', true)
    // 发行包 config 写死 official 时无法在进程内切到开源版，改为直接用开源版结果做同构校验
    : ['status' => YxLicense::STATUS_COMMUNITY, 'message' => '开源版 · 未启用授权模块', 'authorized' => true];
if ($switchable) {
    eq('开源版状态为 community', YxLicense::STATUS_COMMUNITY, $rc['status']);
    t('开源版文案明确标注开源版', strpos($rc['message'], '开源版') !== false && strpos($rc['message'], '社区版') === false, $rc['message']);
    t('开源版不伪装成「已正版授权」', strpos($rc['message'], '已正版授权') === false, $rc['message']);
}

if ($switchable) {
    eq('开源版徽标样式为 info', 'info', YxLicense::badge()['class']);
    t('开源版不限制版本', YxLicense::maxEdition() === 'enterprise');
    t('开源版支持切换任意版本', YxEdition::canSwitch('enterprise'));

    YxLicense::log('community-probe.test', YxLicense::STATUS_COMMUNITY, 'community', 'probe');
    eq('开源版不写入授权校验日志', $logBefore, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('license_log')));

    set_setting('license_mode', 'official');
    YxLicense::flushCache();
    t('可切回正式版', YxLicense::mode() === 'official');
    t('切回后恢复按域名校验', !YxLicense::query('community-probe.test', true)['authorized']);
}

/* --- 开源版发行包锁定：不可切换正式版（license.lock_mode） --- */
// 锁定分支在进程内用 debugSetLockedMode 模拟。该开关下 mode() 以 config 为准，
// 因此断言要跟随构建进 config 的 mode，而不是假定一定是 community。
$pinnedMode = $modePinned ? $buildDefault : 'community';
YxLicense::debugSetLockedMode(true);
YxLicense::flushCache();
t('开源版发行包锁定授权模式', YxLicense::modeLocked());
if ($pinnedMode === 'community') {
    t('锁定状态下当前模式为开源版', YxLicense::isCommunity());
    t('锁定状态下拒绝切换为正式版', !YxLicense::setMode('official'));
    eq('锁定状态下提交正式版后模式仍为开源版', 'community', YxLicense::mode());
    t('锁定状态下可保持开源版', YxLicense::setMode('community'));
    eq('锁定状态下未授权域名仍为开源版结论', YxLicense::STATUS_COMMUNITY, YxLicense::query('community-probe.test', true)['status']);
    t('锁定状态下开源版仍可切换版本档位', YxEdition::canSwitch('enterprise'));
} else {
    // 正式版包 config 写死 official：锁定分支下模式应固定为 official，且拒绝改成 community
    t('锁定状态下来源模式为正式版', YxLicense::mode() === 'official');
    t('锁定状态下拒绝切换为开源版', !YxLicense::setMode('community'));
    eq('锁定状态下模式仍为正式版', 'official', YxLicense::mode());
}
YxLicense::debugSetLockedMode(null);
YxLicense::flushCache();

// 未锁定断言：跟随 config 中是否写死 lock_mode，而不是假定「正式版包一定不锁」
$cfgPinnedLock = (bool)preg_match("/'lock_mode'\s*=>\s*true/", (string)@file_get_contents($root . '/includes/config.php'));
if ($cfgPinnedLock) {
    t('开源版发行包在 config 中锁定授权模式', YxLicense::modeLocked());
} else {
    t('正式版包不锁定授权模式', !YxLicense::modeLocked());
}

/* ---------------------------------------------------------------- 20. 自动续费 */
section('20. 自动续费（金额安全）');

$arCid = YxDb::insert('customer', [
    'username' => 'autorenew_owner', 'email' => 'ar@example.com', 'password' => YxAuth::hash('password123'),
    'balance' => 0, 'credit' => 0, 'points' => 0, 'level' => 'normal', 'agent_id' => 0, 'status' => 1, 'created_at' => now(),
]);
YxFinance::credit($arCid, 500, 'recharge', ['remark' => '自检充值']);

$duePast = date('Y-m-d', strtotime('-1 day'));
$svcDue  = YxBusiness::createService([
    'customer_id' => $arCid, 'product_id' => $pid, 'name' => '自动续费业务',
    'billing_cycle' => 'month', 'price' => 50, 'status' => 'active', 'next_due_date' => $duePast,
]);
// 让它有上游实例，验证抢占时会同步入队 upstream.renew
YxDb::update('service', ['remote_id' => 'MOCK-AR-0001', 'upstream_id' => $upId], 'id = :id', ['id' => $svcDue]);
$svcDueRow = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcDue]);
eq('已到期业务到期日正确', $duePast, $svcDueRow['next_due_date']);

$candIds = array_map('intval', array_column(YxBusiness::autoRenewCandidates(0, 500), 'id'));
t('已到期业务出现在候选列表', in_array($svcDue, $candIds, true));

// 提前天数
$svcSoon = YxBusiness::createService([
    'customer_id' => $arCid, 'product_id' => $pid, 'name' => '三天后到期业务',
    'billing_cycle' => 'month', 'price' => 50, 'status' => 'active',
    'next_due_date' => date('Y-m-d', strtotime('+3 day')),
]);
$ids0 = array_map('intval', array_column(YxBusiness::autoRenewCandidates(0, 500), 'id'));
$ids5 = array_map('intval', array_column(YxBusiness::autoRenewCandidates(5, 500), 'id'));
t('提前 0 天时不处理 3 天后到期的业务', !in_array($svcSoon, $ids0, true));
t('提前 5 天时包含 3 天后到期的业务', in_array($svcSoon, $ids5, true));

// 并发抢占（回归测试：旧实现会重复扣费 + 重复顺延）
$taskBefore = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('task') . " WHERE type = 'upstream.renew'");
$c1 = YxBusiness::claimRenew($svcDue, $duePast, 'month');
$c2 = YxBusiness::claimRenew($svcDue, $duePast, 'month');   // 同一快照 → 应被拒绝
t('第一次抢占成功', !empty($c1['claimed']), json_encode($c1, JSON_UNESCAPED_UNICODE));
t('第二次抢占被拒绝（同一业务不会重复续费）', empty($c2['claimed']), json_encode($c2, JSON_UNESCAPED_UNICODE));
t('拒绝原因指向到期日已变化', strpos((string)$c2['msg'], '到期日已变化') !== false, (string)$c2['msg']);
eq('到期日只顺延一个周期（未被叠加）', $c1['next_due_date'], YxDb::value('SELECT next_due_date FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcDue]));
t('抢占后自动入队上游续费', (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('task') . " WHERE type = 'upstream.renew'") > $taskBefore);

// 归还期限
YxBusiness::rollbackRenew($svcDue, $c1['prev_due'], (string)$c1['next_due_date']);
eq('归还期限后到期日恢复原值', $duePast, YxDb::value('SELECT next_due_date FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcDue]));

// 完整一轮
$balBefore = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$arCid]);
$run1 = YxBusiness::runAutoRenew(0, 500);
t('一轮自动续费至少成功 1 个', $run1['charged'] >= 1, json_encode($run1, JSON_UNESCAPED_UNICODE));
$balAfter = (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$arCid]);
eq('余额恰好扣一次（500-50）', $balBefore - 50, $balAfter);
eq('生成 1 张自动续费账单', 1, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('invoice') . ' WHERE service_id = ? AND title LIKE ?',
    [$svcDue, '自动续费%']
));
eq('生成 1 条续费钱包流水', 1, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('transaction') . ' WHERE customer_id > 0 AND ref_id = ? AND remark LIKE ?',
    [$svcDue, '自动续费%']
));
eq('自动续费同时登记 1 条站方营收', 1, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('transaction') . " WHERE customer_id = 0 AND direction = 'income' AND ref_id = ? AND remark LIKE ?",
    [$svcDue, '续费收入%']
));
$dueAfterRun = YxDb::value('SELECT next_due_date FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcDue]);
t('到期日已顺延到未来', $dueAfterRun > $duePast, (string)$dueAfterRun);
eq('业务状态保持 active', 'active', YxDb::value('SELECT status FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcDue]));

// 再跑一轮：不应重复扣费
$run2 = YxBusiness::runAutoRenew(0, 500);
eq('再次运行不会重复扣同一笔', $balAfter, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$arCid]));
eq('账单数量未增加', 1, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('invoice') . ' WHERE service_id = ? AND title LIKE ?',
    [$svcDue, '自动续费%']
));

// 余额不足：不扣费、不生成账单、期限保持
$poorCid = YxDb::insert('customer', [
    'username' => 'autorenew_poor', 'email' => 'poor@example.com', 'password' => YxAuth::hash('password123'),
    'balance' => 0, 'credit' => 0, 'points' => 0, 'level' => 'normal', 'agent_id' => 0, 'status' => 1, 'created_at' => now(),
]);
$svcPoor = YxBusiness::createService([
    'customer_id' => $poorCid, 'product_id' => $pid, 'name' => '余额不足业务',
    'billing_cycle' => 'month', 'price' => 50, 'status' => 'active', 'next_due_date' => $duePast,
]);
$run3 = YxBusiness::runAutoRenew(0, 500);
eq('余额不足的业务不产生账单', 0, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('invoice') . ' WHERE service_id = ? AND title LIKE ?',
    [$svcPoor, '自动续费%']
));
eq('余额不足时到期日保持原值', $duePast, YxDb::value('SELECT next_due_date FROM ' . YxDb::table('service') . ' WHERE id = ?', [$svcPoor]));
eq('余额不足时余额不变', 0.0, (float)YxDb::value('SELECT balance FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$poorCid]));
t('余额不足的业务被计入跳过', $run3['skipped'] >= 1);

// 开关：关闭后不执行
set_setting('auto_renew', '0');
t('设置项 auto_renew 可读取', setting('auto_renew', '0') === '0');
set_setting('auto_renew', '1');
t('auto_renew 可开启', setting('auto_renew', '1') === '1');
set_setting('auto_renew', '0');

/* ---------------------------------------------------------------- 21. 可配置上游驱动 */
section('21. 可配置上游驱动（协议映射）');

$tpl = YxUpstreamCustom::defaultConfig();
t('默认模板含全部关键配置项',
    isset($tpl['method'], $tpl['auth'], $tpl['actions'], $tpl['fields'], $tpl['response']));
t('驱动列表包含 mock / http / custom', !array_diff(['mock', 'http', 'custom'], array_keys(YxUpstream::drivers())));
eq('驱动名称可读', '自定义 HTTP（字段/签名/响应全可配）', YxUpstream::driverName('custom'));
t('可取得默认模板', !empty(YxUpstream::defaultConfigFor('custom')['actions']));

$merged = YxUpstreamCustom::merge($tpl, [
    'method'  => 'POST_FORM',
    'auth'    => 'append_secret',
    'actions' => ['create' => 'host_create'],
]);
eq('用户配置覆盖标量项', 'POST_FORM', $merged['method']);
eq('动作映射部分覆盖', 'host_create', $merged['actions']['create']);
eq('未覆盖的动作保留默认', 'renew', $merged['actions']['renew']);
eq('未覆盖的响应配置保留默认', '0', $merged['response']['success_value']);

// 用一套「表单 + MD5 追加密钥 + 平铺字段 + 自定义响应码」的协议做验证
// （仅用于验证映射能力，不代表任何厂商的真实协议）
$cfgMap = [
    'method'         => 'POST_FORM',
    'auth'           => 'append_secret',
    'appid_field'    => 'user',
    'ts_field'       => 'timestamp',
    'nonce_field'    => '',
    'action_field'   => 'act',
    'params_wrapper' => '',
    'actions'        => ['create' => 'host_create', 'status' => 'host_info'],
    'fields'         => ['remote_id' => 'host_id', 'power_action' => 'op'],
    'response'       => [
        'code_path'     => 'status',
        'success_value' => '1',
        'msg_path'      => 'msg',
        'data_path'     => 'data',
        'list_path'     => 'data.list',
        'map'           => ['remote_id' => 'host_id', 'ip' => 'ip', 'username' => 'user', 'password' => 'pass', 'status' => 'state'],
        'item_map'      => ['code' => 'code', 'name' => 'name', 'price' => 'price'],
    ],
];
$upCfgId = YxDb::insert('upstream', [
    'name' => '可配置驱动自检', 'driver' => 'custom', 'endpoint' => 'http://127.0.0.1:9/api',
    'appid' => 'testuser', 'secret' => yx_encrypt('secret123'),
    'config' => json_encode($cfgMap, JSON_UNESCAPED_UNICODE),
    'timeout' => 2, 'auto_setup' => 1, 'status' => 1, 'created_at' => now(),
]);
$drv = YxUpstream::driver(YxUpstream::row($upCfgId));
t('自定义驱动可实例化', $drv instanceof YxUpstreamCustom);

$body = $drv->buildPayload('create', ['product_code' => 'hk-2c2g', 'service_sn' => 'SVC1', 'remote_id' => 'X']);
t('appid 字段名已映射', isset($body['user']) && $body['user'] === 'testuser');
t('动作名已映射', ($body['act'] ?? '') === 'host_create');
t('业务参数平铺（params_wrapper 为空）', isset($body['product_code']) && !isset($body['params']));
t('空配置的 nonce 不出现', !array_key_exists('nonce', $body));
t('业务字段名映射生效（remote_id → host_id）', isset($body['host_id']));
t('未列入映射的字段原样传递', ($body['service_sn'] ?? '') === 'SVC1');

$cf       = $drv->config();
$canonical = $drv->canonical($body, $cf);
eq('规范串按 key 升序拼接', 'act=host_create&host_id=X&product_code=hk-2c2g&service_sn=SVC1&timestamp=' . $body['timestamp'] . '&user=testuser', $canonical);
eq('append_secret = md5(串+密钥)', md5($canonical . 'secret123'), $drv->buildSign($body, $cf));
eq('append_secret_upper', strtoupper(md5($canonical . 'secret123')), $drv->buildSign($body, YxUpstreamCustom::merge($cf, ['auth' => 'append_secret_upper'])));
eq('secret_prefix', md5('secret123' . $canonical), $drv->buildSign($body, YxUpstreamCustom::merge($cf, ['auth' => 'secret_prefix'])));
eq('hmac_sha256', hash_hmac('sha256', $canonical, 'secret123'), $drv->buildSign($body, YxUpstreamCustom::merge($cf, ['auth' => 'hmac_sha256'])));
eq('hmac_md5', hash_hmac('md5', $canonical, 'secret123'), $drv->buildSign($body, YxUpstreamCustom::merge($cf, ['auth' => 'hmac_md5'])));
eq('none 不签名', '', $drv->buildSign($body, YxUpstreamCustom::merge($cf, ['auth' => 'none'])));

$okJson = json_encode(['status' => 1, 'msg' => 'ok', 'data' => [
    'host_id' => 'H-1', 'ip' => '1.2.3.4', 'user' => 'root', 'pass' => 'p@ss', 'state' => 'running',
]]);
$ex = $drv->extract($okJson);
t('自定义成功码判定成功', !empty($ex['ok']));
eq('remote_id 映射', 'H-1', $ex['remote_id']);
eq('ip 映射', '1.2.3.4', $ex['ip']);
eq('username 映射', 'root', $ex['username']);
eq('password 映射', 'p@ss', $ex['password']);
eq('status 映射', 'running', $ex['status']);

$ex = $drv->extract(json_encode(['status' => 0, 'msg' => '余额不足']));
t('失败响应被识别', empty($ex['ok']));
eq('失败原因透传', '余额不足', $ex['msg']);

$ex = $drv->extract(json_encode(['status' => 1, 'msg' => 'ok', 'data' => ['list' => [
    ['code' => 'p1', 'name' => '产品1', 'price' => '9.90'],
]]]));
t('列表按 list_path 解析', is_array($ex['list'] ?? null) && count($ex['list']) === 1);
eq('列表项 code 映射', 'p1', $ex['list'][0]['code'] ?? '');
eq('列表项 price 映射', 9.9, (float)($ex['list'][0]['price'] ?? 0));

$ex = $drv->extract('this is not json');
t('非 JSON 响应识别为失败', empty($ex['ok']));
t('非 JSON 时给出可读错误', stripos((string)$ex['msg'], 'JSON') !== false, (string)$ex['msg']);

$ex = $drv->extract(json_encode(['status' => 0, 'msg' => '']));
t('失败但无 msg 时给出带码位的提示', stripos((string)$ex['msg'], 'status') !== false, (string)$ex['msg']);

$ex = $drv->request('test');
t('接口不可达时返回失败而不是抛异常', empty($ex['ok']), json_encode($ex, JSON_UNESCAPED_UNICODE));

// 未配置 config 时回落到默认模板
$upDefId = YxDb::insert('upstream', [
    'name' => '默认模板驱动', 'driver' => 'custom', 'endpoint' => 'http://127.0.0.1:9/api',
    'appid' => 'a', 'secret' => yx_encrypt('b'), 'config' => null,
    'timeout' => 2, 'status' => 1, 'created_at' => now(),
]);
$drvDef = YxUpstream::driver(YxUpstream::row($upDefId));
eq('未配置时使用默认 method', 'POST_JSON', $drvDef->config()['method']);
eq('未配置时使用默认 auth', 'hmac_sha256', $drvDef->config()['auth']);
eq('未配置时参数包在 params 内', 'params', $drvDef->config()['params_wrapper']);

// 配置损坏时不抛异常
YxDb::update('upstream', ['config' => '{bad json'], 'id = :id', ['id' => $upDefId]);
$drvBad = YxUpstream::driver(YxUpstream::row($upDefId));
eq('配置 JSON 损坏时回落到默认模板', 'POST_JSON', $drvBad->config()['method']);

/* ---- 多路径 / 请求头 / Basic / XML ---- */

$c2 = YxUpstreamCustom::merge(YxUpstreamCustom::defaultConfig(), [
    'method'  => 'POST_FORM',
    'auth'    => 'append_secret',
    'actions' => [
        'create' => '/api/host/create',
        'status' => 'https://other.example.com/info',
        'renew'  => 'host_renew',
    ],
]);
$upPathId = YxDb::insert('upstream', [
    'name' => '多路径自检', 'driver' => 'custom', 'endpoint' => 'https://vendor.example.com/gateway',
    'appid' => 'u1', 'secret' => yx_encrypt('s1'), 'config' => json_encode($c2, JSON_UNESCAPED_UNICODE),
    'timeout' => 2, 'status' => 1, 'created_at' => now(),
]);
$drvP = YxUpstream::driver(YxUpstream::row($upPathId));
$cfgP = $drvP->config();

eq('单入口动作使用配置地址', 'https://vendor.example.com/gateway', $drvP->endpointFor('renew', $cfgP));
eq('路径型动作拼接到同源地址', 'https://vendor.example.com/api/host/create', $drvP->endpointFor('create', $cfgP));
eq('完整 URL 型动作直接使用', 'https://other.example.com/info', $drvP->endpointFor('status', $cfgP));
t('路径型动作可被识别', YxUpstreamCustom::isEndpointValue('/api/host/create') && YxUpstreamCustom::isEndpointValue('https://x/y'));
t('普通动作名不被当作路径', !YxUpstreamCustom::isEndpointValue('host_create'));
eq('路径型动作的请求体动作名回退为原键名', 'create', $drvP->actionName('create', $cfgP));
eq('普通动作仍用映射名', 'host_renew', $drvP->actionName('renew', $cfgP));
t('路径型动作的请求体不含路径串',
    !in_array('/api/host/create', $drvP->buildPayload('create', []), true));

// 请求头占位符
$c3 = YxUpstreamCustom::merge($c2, ['headers' => ['X-Api-Key' => '{secret}', 'X-Merchant' => '{appid}', 'X-Ts' => '{ts}']]);
$hdrs = $drvP->buildHeaders($c3);
$hdrText = implode(' | ', $hdrs);
t('请求头占位符 {secret} 被替换', strpos($hdrText, 'X-Api-Key: s1') !== false, $hdrText);
t('请求头占位符 {appid} 被替换', strpos($hdrText, 'X-Merchant: u1') !== false, $hdrText);
t('请求头占位符 {ts} 被替换为时间戳', (bool)preg_match('/X-Ts: \d{10}/', $hdrText), $hdrText);
eq('非 basic 认证不产生 Authorization 头', false, stripos($hdrText, 'Authorization') !== false);

$hdrsBasic = $drvP->buildHeaders(YxUpstreamCustom::merge($c3, ['auth' => 'basic']));
$basicLine = '';
foreach ($hdrsBasic as $h) {
    if (stripos($h, 'Authorization') === 0) {
        $basicLine = $h;
    }
}
eq('basic 认证生成 Authorization 头', 'Authorization: Basic ' . base64_encode('u1:s1'), $basicLine);
eq('basic 认证不写入签名字段', '', $drvP->buildSign($drvP->buildPayload('renew', []), YxUpstreamCustom::merge($cfgP, ['auth' => 'basic'])));

// XML 响应
$c4 = YxUpstreamCustom::merge($c2, ['response' => ['format' => 'xml', 'code_path' => 'code', 'success_value' => '0', 'msg_path' => 'msg', 'data_path' => 'data', 'list_path' => '', 'map' => ['remote_id' => 'host_id', 'ip' => 'ip', 'username' => 'user', 'password' => 'pass', 'status' => 'state'], 'item_map' => []]]);
$upXmlId = YxDb::insert('upstream', [
    'name' => 'XML 自检', 'driver' => 'custom', 'endpoint' => 'https://x/y', 'appid' => 'a', 'secret' => yx_encrypt('b'),
    'config' => json_encode($c4, JSON_UNESCAPED_UNICODE), 'timeout' => 2, 'status' => 1, 'created_at' => now(),
]);
$drvX = YxUpstream::driver(YxUpstream::row($upXmlId));
$xmlOk = '<?xml version="1.0" encoding="UTF-8"?><root><code>0</code><msg>ok</msg>'
    . '<data><host_id>XH-1</host_id><ip>10.0.0.9</ip><user>root</user><pass>pw</pass><state>running</state></data></root>';
$ex = $drvX->extract($xmlOk);
t('XML 成功响应被识别', !empty($ex['ok']), json_encode($ex, JSON_UNESCAPED_UNICODE));
eq('XML 中 remote_id 映射', 'XH-1', $ex['remote_id'] ?? '');
eq('XML 中 ip 映射', '10.0.0.9', $ex['ip'] ?? '');
eq('XML 中 status 映射', 'running', $ex['status'] ?? '');
$ex = $drvX->extract('<?xml version="1.0"?><root><code>1</code><msg>库存不足</msg></root>');
t('XML 失败响应被识别', empty($ex['ok']));
eq('XML 失败原因透传', '库存不足', $ex['msg']);
$ex = $drvX->extract('<<<bad xml');
t('非法 XML 被识别为失败', empty($ex['ok']));
t('非法 XML 给出可读错误', stripos((string)$ex['msg'], 'XML') !== false, (string)$ex['msg']);

/* ---------------------------------------------------------------- 22. 关于我们 */
section('22. 关于我们（公司信息）');

t('分组定义完整', count(YxAbout::SECTIONS) >= 5 && YxAbout::isKnownSection('milestone'));
t('未知分组被拒绝', !YxAbout::isKnownSection('not-exist'));
eq('分组中文名可读', '发展历程', YxAbout::sectionName('milestone'));
t('标量字段定义非空', count(YxAbout::SCALARS) > 5);

$ps = YxAbout::paragraphs("第一段\n\n第二段\n\n\n第三段");
eq('空行分段', 3, count($ps));
eq('段落首尾空白被清理', '第一段', $ps[0]);
eq('空文本返回空数组', 0, count(YxAbout::paragraphs("   \n  ")));
eq('无空行时只有一段', 1, count(YxAbout::paragraphs('只有一段')));

// 页面开关
set_setting('about_show', '0');
t('可关闭关于我们页', !YxAbout::enabled());
t('关闭后 hasContent 为假', !YxAbout::hasContent());
set_setting('about_show', '1');
t('可开启关于我们页', YxAbout::enabled());

// 条目：新增 / 隐藏 / 排序 / 删除
eq('初始无条目', 0, count(YxAbout::items('capability')));
YxDb::insert('about_item', ['section' => 'capability', 'sort' => 5, 'title' => '自检能力', 'content' => '说明', 'status' => 1, 'created_at' => now()]);
eq('新增后前台可见', 1, count(YxAbout::items('capability')));
$aiId = (int)YxDb::value('SELECT id FROM ' . YxDb::table('about_item') . " WHERE title = '自检能力'");
t('可定位到该条目', $aiId > 0);
YxDb::update('about_item', ['status' => 0], 'id = :id', ['id' => $aiId]);
eq('停用后前台不可见', 0, count(YxAbout::items('capability')));
eq('停用后管理端仍可见', 1, count(YxAbout::adminItems('capability')));

YxDb::insert('about_item', ['section' => 'value', 'sort' => 99, 'title' => '排序靠后', 'status' => 1, 'created_at' => now()]);
YxDb::insert('about_item', ['section' => 'value', 'sort' => 1, 'title' => '排序靠前', 'status' => 1, 'created_at' => now()]);
$vList = YxAbout::items('value');
eq('列表按 sort 升序（首条）', '排序靠前', $vList[0]['title']);
eq('列表按 sort 升序（末条）', '排序靠后', $vList[count($vList) - 1]['title']);

YxDb::delete('about_item', 'id = :id', ['id' => $aiId]);
eq('删除后条目消失', 0, count(YxAbout::adminItems('capability')));
t('未知分组取条目返回空数组', YxAbout::items('not-exist') === []);

// 联系方式：未单独填写时回退到系统设置
set_setting('about_contact_email', '');
set_setting('contact_email', 'fallback@example.com');
$cs = YxAbout::contacts();
eq('邮箱未单独填写时回退到系统设置', 'fallback@example.com', $cs['联系邮箱'] ?? '');
set_setting('about_contact_email', 'about@example.com');
$cs = YxAbout::contacts();
eq('单独填写后优先使用专属值', 'about@example.com', $cs['联系邮箱'] ?? '');
set_setting('about_contact_phone', '400-000-0000');
$cs = YxAbout::contacts();
eq('电话单独填写后出现', '400-000-0000', $cs['联系电话'] ?? '');
set_setting('about_contact_hours', '');
t('未填写的联系方式不出现', !isset($cs['服务时间']));

// hasContent 依赖是否真的填了内容
YxDb::exec('DELETE FROM ' . YxDb::table('about_item'));
foreach (['about_slogan', 'about_intro', 'about_contact_phone', 'about_contact_email', 'contact_email', 'contact_qq'] as $k) {
    set_setting($k, '');
}
t('全部留空时 hasContent 为假', !YxAbout::hasContent());
set_setting('about_slogan', '测试定位语');
t('填写定位语后 hasContent 为真', YxAbout::hasContent());

// 默认内容写入（安装与升级共用）：只补缺失项，不覆盖已有值
YxDb::exec('DELETE FROM ' . YxDb::table('about_item'));
// 模拟「这些设置从未存在过」
YxDb::exec("DELETE FROM " . YxDb::table('setting') . " WHERE k IN ('about_slogan','about_intro')");
setting_reload();   // 原生 SQL 绕过了 setting 缓存，需手动失效

YxAbout::seedDefaults();
$seedCaps = count(YxAbout::adminItems('capability'));
$seedVals = count(YxAbout::adminItems('value'));
t('默认内容写入了核心能力', $seedCaps >= 3, (string)$seedCaps);
t('默认内容写入了理念', $seedVals >= 3, (string)$seedVals);
t('默认内容未写入公司事实类字段（里程碑/伙伴应为空）',
    count(YxAbout::adminItems('milestone')) === 0 && count(YxAbout::adminItems('partner')) === 0);
t('缺失的设置会被补上', trim(YxAbout::get('about_slogan')) !== '');

YxAbout::seedDefaults();
eq('seedDefaults 幂等：条目不重复写入', $seedCaps, count(YxAbout::adminItems('capability')));

// 已存在的值（含人为清空）不得被覆盖
set_setting('about_slogan', '被我改过的值');
YxAbout::seedDefaults();
eq('已有值不被默认内容覆盖', '被我改过的值', YxAbout::get('about_slogan'));
set_setting('about_slogan', '');
YxAbout::seedDefaults();
eq('人为清空的值也不会被默认内容复活', '', YxAbout::get('about_slogan'));

/* ------------------------------------------------------------ 首页横幅 */

// 路径与链接清洗
eq('图片路径保留合法相对路径', 'banner/a.jpg', YxBanner::cleanImage('/banner/a.jpg'));
eq('图片路径拒绝目录穿越', '', YxBanner::cleanImage('../etc/passwd'));
eq('图片路径拒绝协议地址', '', YxBanner::cleanImage('https://evil.example/x.jpg'));
eq('图片路径拒绝反斜杠', '', YxBanner::cleanImage('banner\\a.jpg'));
eq('未设置图片时 URL 为空', '', YxBanner::imageUrl(['image' => '']));
t('已设置图片时拼出可访问 URL', strpos(YxBanner::imageUrl(['image' => 'banner/a.jpg']), '/uploads/banner/a.jpg') !== false);

eq('外链原样保留', 'https://example.com/a', YxBanner::href('https://example.com/a'));
eq('页内锚点原样保留', '#products', YxBanner::href('#products'));
eq('javascript 伪协议被拒绝', '', YxBanner::href('javascript:alert(1)'));
eq('data 伪协议被拒绝', '', YxBanner::href('data:text/html,x'));
eq('协议相对地址被拒绝', '', YxBanner::href('//evil.example'));
t('站内相对路径被补全为绝对地址', strpos(YxBanner::href('store/index.php'), '/store/index.php') !== false);

// 按钮归一化：必须文字与链接成对
$btns = YxBanner::buttons([
    'btn1_text' => '立即购买', 'btn1_url' => 'store/index.php',
    'btn2_text' => '只有文字', 'btn2_url' => '',
]);
eq('只输出完整按钮', 1, count($btns));
eq('按钮文字正确', '立即购买', $btns[0]['text']);

// 默认横幅：写入 / 幂等 / 前台过滤
YxDb::exec('DELETE FROM ' . YxDb::table('banner'));
YxBanner::seedDefaults();
$seedBanners = count(YxBanner::all());
t('默认横幅写入至少 3 条', $seedBanners >= 3, (string)$seedBanners);
YxBanner::seedDefaults();
eq('seedDefaults 幂等：横幅不重复写入', $seedBanners, count(YxBanner::all()));
eq('全部启用时前台可见数一致', $seedBanners, count(YxBanner::active()));

$firstId = (int)YxDb::value('SELECT id FROM ' . YxDb::table('banner') . ' ORDER BY sort ASC, id ASC LIMIT 1');
YxDb::update('banner', ['status' => 0], 'id = :id', ['id' => $firstId]);
eq('停用后前台少一条', $seedBanners - 1, count(YxBanner::active()));
t('管理端仍能看到停用项', YxBanner::find($firstId) !== null);

YxDb::exec('DELETE FROM ' . YxDb::table('banner'));
eq('表为空时前台为空（由 fallback 兜底）', 0, count(YxBanner::active()));
eq('兜底横幅至少一条', 1, count(YxBanner::fallback()));

section('23. 展示广告位（广告位 + 素材）');

t('位置定义完整', YxAd::isKnownPosition('home') && YxAd::isKnownPosition('goods'));
t('未知位置被识别为非法', !YxAd::isKnownPosition('nowhere'));
t('版式定义完整', YxAd::isKnownLayout('banner') && YxAd::isKnownLayout('goods'));
eq('位置中文名可读', '优享财务系统展示页', YxAd::positionName('goods'));
eq('计费周期中文名可读', '永久', YxAd::cycleName('forever'));

// 价格文案：0 元显示免费，其余带千分位
eq('价格为 0 显示免费', '免费', YxAd::priceLabel(['price' => 0]));
eq('负价格也按免费处理', '免费', YxAd::priceLabel(['price' => -1]));
t('正价格带货币符号', strpos(YxAd::priceLabel(['price' => 1600]), '1,600') !== false);

// 应用定价标签：零价的按年 / 一次性应用统一显示免费，不出现「¥0.00/年」
eq('按年应用零价显示免费', '免费', YxBusiness::priceLabel(['bill_type' => 'year', 'price' => 0]));
eq('一次性应用零价显示免费', '免费', YxBusiness::priceLabel(['bill_type' => 'once', 'price' => 0]));
eq('旧数据零价无计费方式也显示免费', '免费', YxBusiness::priceLabel(['price' => 0]));
t('一次性应用正价带一次性后缀', strpos(YxBusiness::priceLabel(['bill_type' => 'once', 'price' => 99]), '一次性') !== false);
t('按年应用正价带年后缀', strpos(YxBusiness::priceLabel(['bill_type' => 'year', 'price' => 1200]), '/年') !== false);
t('积分应用显示积分单位', strpos(YxBusiness::priceLabel(['bill_type' => 'points', 'points_price' => 30, 'price' => 0]), '30 积分') !== false);

// 无数据时不输出任何区块，调用方可直接省略
YxDb::exec('DELETE FROM ' . YxDb::table('ad_item'));
YxDb::exec('DELETE FROM ' . YxDb::table('ad_slot'));
eq('无广告位时前台为空', 0, count(YxAd::allSlots()));
eq('无位置过滤的启用列表为空', 0, count(YxAd::activeSlots()));
eq('无素材时横幅渲染为空串', '', YxAd::renderBanners('home'));

// 默认广告位：写入 / 幂等 / 素材价格取自实际配置
set_setting('edition_price_pro', '1888');
set_setting('edition_days_pro', '365');
YxAd::seedDefaults();
$seedSlots = YxAd::allSlots();
eq('默认写入两个广告位', 2, count($seedSlots));
eq('展示页广告位启用', 1, count(YxAd::activeSlots('goods')));
YxAd::seedDefaults();
eq('seedDefaults 幂等：广告位不重复写入', 2, count(YxAd::allSlots()));

$goodsSlot = YxAd::activeSlots('goods')[0];
$goodsItems = YxAd::items((int)$goodsSlot['id']);
t('默认素材至少 3 条', count($goodsItems) >= 3, (string)count($goodsItems));
$proItem = null;
foreach ($goodsItems as $gi) {
    if ($gi['title'] === '专业版授权') {
        $proItem = $gi;
    }
}
t('可定位到专业版素材', $proItem !== null);
if ($proItem) {
    eq('素材价格取自版本配置', '1888.00', number_format((float)$proItem['price'], 2, '.', ''));
    eq('素材周期与购买页口径一致', '365 天', YxAd::cycleLabel($proItem));
    t('素材购买按钮指向站内购买入口', strpos((string)YxAd::href((string)$proItem['btn_url']), '/panel/index.php?m=edition') !== false);
}
eq('0 天有效期记为永久', 'forever', YxAd::cycleFromDays(0));
eq('正天数有效期记为天数周期', '730d', YxAd::cycleFromDays(730));
eq('天数周期显示为中文', '730 天', YxAd::cycleLabel(['cycle' => '730d']));
eq('未知周期原样回显', 'custom', YxAd::cycleLabel(['cycle' => 'custom']));

// 前台只取启用素材，且只取所属广告位启用的
$itemCount = count($goodsItems);
YxDb::update('ad_item', ['status' => 0], 'id = :id', ['id' => (int)$goodsItems[0]['id']]);
eq('停用素材后前台少一条', $itemCount - 1, count(YxAd::items((int)$goodsSlot['id'])));
eq('管理端仍能看到停用素材', $itemCount, count(YxAd::items((int)$goodsSlot['id'], false)));
YxDb::update('ad_item', ['status' => 1], 'id = :id', ['id' => (int)$goodsItems[0]['id']]);

YxDb::update('ad_slot', ['status' => 0], 'id = :id', ['id' => (int)$goodsSlot['id']]);
eq('广告位停用后整组前台不可见', 0, count(YxAd::activeItems('goods')));
eq('广告位停用后启用位置列表少一项', 0, count(YxAd::activeSlots('goods')));
YxDb::update('ad_slot', ['status' => 1], 'id = :id', ['id' => (int)$goodsSlot['id']]);
eq('重新启用后素材恢复可见', $itemCount, count(YxAd::activeItems('goods')));

// 排他：位置与版式过滤互不串台
eq('goods 位置不混入横幅素材', 0, count(YxAd::activeItems('goods', 'banner')));
t('home 位置能取到横幅素材', count(YxAd::activeItems('home', 'banner')) >= 1);

// 二级分组：展示页的 spg 筛选依赖这个字段
$subgroups = [];
foreach (YxAd::items((int)$goodsSlot['id']) as $gi) {
    $g = trim((string)$gi['subgroup']);
    if ($g !== '' && !in_array($g, $subgroups, true)) {
        $subgroups[] = $g;
    }
}
eq('默认素材落在同一二级分组', 1, count($subgroups));

// 渲染与清洗
$html = YxAd::renderBanners('home');
t('横幅渲染包含卡片类名', strpos($html, 'yx-ad-banner') !== false);
t('横幅渲染包含标题文案', strpos($html, '正版授权可查') !== false);
eq('素材图片路径拒绝目录穿越', '', YxAd::cleanImage('../etc/passwd'));
eq('素材图片路径拒绝协议地址', '', YxAd::cleanImage('https://evil.example/x.jpg'));
eq('素材链接拒绝 javascript 伪协议', '', YxAd::href('javascript:alert(1)'));

// 删除广告位后素材一并消失（后台删除逻辑的口径）
YxDb::delete('ad_item', 'slot_id = :sid', ['sid' => (int)$goodsSlot['id']]);
YxDb::delete('ad_slot', 'id = :id', ['id' => (int)$goodsSlot['id']]);
eq('删除广告位后其素材不再可见', 0, count(YxAd::items((int)$goodsSlot['id'], false)));
t('剩余广告位仍可查询', YxAd::findSlot((int)$goodsSlot['id']) === null);

YxDb::exec('DELETE FROM ' . YxDb::table('ad_item'));
YxDb::exec('DELETE FROM ' . YxDb::table('ad_slot'));
set_setting('edition_price_pro', '1600');

section('24. 售卖品主图 / 产品详情页');

t('支持主图与详情页两种画布', YxMarketing::isKnownKind('main') && YxMarketing::isKnownKind('detail'));
t('未知画布类型被拒绝', !YxMarketing::isKnownKind('banner'));
eq('主图规格 800×800', [800, 800], YxMarketing::size('main'));
eq('详情页规格 750×1200', [750, 1200], YxMarketing::size('detail'));
eq('详情页版式固定为长图', 'poster', YxMarketing::resolveLayout('detail', 'hero'));
eq('主图版式回退到默认', 'hero', YxMarketing::resolveLayout('main', 'not-exist'));
eq('主图价格版式保留', 'price', YxMarketing::resolveLayout('main', 'price'));

// 静态文件地址（前台引用口径）
eq('静态文件相对路径可预测', 'marketing/12.svg', YxMarketing::fileRel(12));
t('静态文件可拼出公开地址', strpos(YxMarketing::fileUrl(12), '/uploads/marketing/12.svg') !== false);
eq('非法 ID 不给地址', '', YxMarketing::fileUrl(0));

$mkDir = yx_upload_dir(YxMarketing::SUBDIR);
$mkCreatedDir = !is_dir($mkDir) && @mkdir($mkDir, 0755, true);
$mkCreatedFiles = [];
$mkCleanup = static function () use (&$mkCreatedFiles, $mkCreatedDir, $mkDir) {
    foreach ($mkCreatedFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    if ($mkCreatedDir && is_dir($mkDir)) {
        @rmdir($mkDir);
    }
};

$mkBase = [
    'kind'       => 'main',
    'layout'     => 'hero',
    'title'      => '优享财务系统·企业版',
    'subtitle'   => '业务与财务一体化管理平台',
    'tag'        => '官方授权',
    'price'      => '3600',
    'price_note' => '/永久',
    'features'   => "订单 · 收支一体化\n工单协同\n<脚本注入>",
    'cta'        => '扫码立即咨询',
    'brand'      => '',
    'bg'         => 'purple',
    'sort'       => 10,
    'status'     => 1,
    'created_at' => now(),
    'updated_at' => now(),
];
$mkMainId = YxDb::insert('marketing_image', $mkBase);
$mkCreatedFiles[] = $mkDir . '/' . $mkMainId . '.svg';
$mkRow = YxMarketing::find($mkMainId);
t('可保存并读回主图', $mkRow !== null);

$mainSvg = YxMarketing::svg($mkRow);
t('主图 SVG 尺寸正确', strpos($mainSvg, 'width="800" height="800"') !== false);
t('主图包含标题文案', strpos($mainSvg, '优享财务系统·企业版') !== false);
t('价格按金额格式化', strpos($mainSvg, '3,600') !== false);
$injectSvg = YxMarketing::svg(['id' => 0, 'kind' => 'detail', 'title' => '<script>alert(1)</script>',
    'features' => "<svg onload=\"alert(1)\">\n正常卖点", 'cta' => '"onmouseover="x']);
t('标题里的标签被转义（防 SVG 注入）',
    strpos($injectSvg, '<script>') === false && strpos($injectSvg, '&lt;script&gt;') !== false);
t('功能清单里的标签被转义', strpos($injectSvg, '<svg onload') === false && strpos($injectSvg, '&lt;svg') !== false);
// 引号必须被转义为 &quot;，否则 "onmouseover="x 会闭合属性并注入事件处理器。
// 注：转义后的字面量 onmouseover= 是安全文本，不能作为失败依据。
t('属性里的引号不会闭合标签',
    strpos($injectSvg, '"onmouseover=') === false && strpos($injectSvg, '&quot;onmouseover=&quot;') !== false);
t('品牌留空时取站点名称', strpos($mainSvg, e((string)setting('site_name', config('app.name')))) !== false);
$unknownPalette = YxMarketing::svg(['id' => 0, 'kind' => 'main', 'bg' => 'evil" onload="x', 'title' => 'A']);
t('非法配色回退到默认渐变', strpos($unknownPalette, 'onload') === false && strpos($unknownPalette, 'linearGradient') !== false);
t('缺字段的记录也能渲染（不报未定义键）', strpos(YxMarketing::svg(['id' => 0]), '<svg') === 0);

$mkDetail = ['kind' => 'detail', 'layout' => 'poster', 'title' => '企业版详情', 'subtitle' => '规模化运营',
    'tag' => '', 'price' => '面议', 'price_note' => '', 'features' => "多节点资源\n开放接口",
    'cta' => '立即咨询', 'brand' => '优享', 'bg' => 'blue', 'sort' => 20, 'status' => 1,
    'created_at' => now(), 'updated_at' => now()];
$mkDetailId = YxDb::insert('marketing_image', $mkDetail);
$mkCreatedFiles[] = $mkDir . '/' . $mkDetailId . '.svg';
$detailSvg = YxMarketing::svg(YxMarketing::find($mkDetailId));
t('详情长图尺寸正确', strpos($detailSvg, 'width="750" height="1200"') !== false);
t('详情页渲染功能清单', strpos($detailSvg, '核心能力') !== false && strpos($detailSvg, '多节点资源') !== false);
t('非数字价格原样展示', strpos($detailSvg, '面议') !== false);

// 前台只取启用的详情图
$mkDetailRows = YxMarketing::byKind('detail');
eq('启用详情图参与前台列表', 1, count($mkDetailRows));
YxDb::update('marketing_image', ['status' => 0], 'id = :id', ['id' => $mkDetailId]);
eq('停用后前台不再取用', 0, count(YxMarketing::byKind('detail')));
eq('管理端仍能看到停用项', 2, count(YxMarketing::all()));
YxDb::update('marketing_image', ['status' => 1], 'id = :id', ['id' => $mkDetailId]);
eq('重新启用后恢复', 1, count(YxMarketing::byKind('detail')));

// 静态文件写入 / 公开地址 / 删除清理
t('静态文件目录可用', is_dir($mkDir));
t('保存时写出静态文件', YxMarketing::writeFile($mkRow) && is_file($mkDir . '/' . $mkMainId . '.svg'));
eq('文件地址与记录 ID 对应', YxMarketing::fileUrl($mkMainId), YxMarketing::publicUrl(YxMarketing::find($mkMainId)));
YxMarketing::removeFile($mkMainId);
t('删除记录时清理静态文件', !is_file($mkDir . '/' . $mkMainId . '.svg'));
eq('文件缺失时前台不给地址（避免跳到后台登录页）', '', YxMarketing::publicUrl(YxMarketing::find($mkMainId)));

// 从展示页素材预填
YxAd::seedDefaults();
$mkSlot   = YxAd::activeSlots('goods')[0];
$mkAdItem = YxAd::items((int)$mkSlot['id'])[0];
$mkDraft  = YxMarketing::draftFromItem($mkAdItem);
eq('预填标题沿用素材标题', (string)$mkAdItem['title'], $mkDraft['title']);
t('预填价格可被解析为数字或免费', $mkDraft['price'] !== '');
t('预填功能清单非空', trim($mkDraft['features']) !== '');
t('预填配色在白名单内', YxMarketing::isKnownPalette((string)$mkDraft['bg']));

// 套用主图到商品卡封面（后台「用作封面」的口径）
YxMarketing::writeFile($mkRow);
YxDb::update('ad_item', ['image' => YxMarketing::fileRel($mkMainId), 'updated_at' => now()], 'id = :id', ['id' => (int)$mkAdItem['id']]);
$mkApplied = YxAd::findItem((int)$mkAdItem['id']);
eq('商品卡封面指向主图静态文件', 'marketing/' . $mkMainId . '.svg', (string)$mkApplied['image']);
t('封面地址可被前台解析', strpos(YxAd::imageUrl($mkApplied), '/uploads/marketing/' . $mkMainId . '.svg') !== false);

// 删除记录时同步清空指向它的商品卡封面（后台删除逻辑）
YxDb::delete('marketing_image', 'id = :id', ['id' => $mkMainId]);
YxMarketing::removeFile($mkMainId);
YxDb::exec('UPDATE ' . YxDb::table('ad_item') . ' SET image = NULL WHERE image = ?', ['marketing/' . $mkMainId . '.svg']);
$mkCleared = YxAd::findItem((int)$mkAdItem['id']);
eq('删除主图后商品卡封面被清空', '', (string)$mkCleared['image']);

YxDb::delete('marketing_image', 'id = :id', ['id' => $mkDetailId]);
YxMarketing::removeFile($mkDetailId);
eq('素材库可清空', 0, count(YxMarketing::all()));
t('不存在的记录返回 null', YxMarketing::find(999999) === null);

$mkCleanup();
YxDb::exec('DELETE FROM ' . YxDb::table('ad_item'));
YxDb::exec('DELETE FROM ' . YxDb::table('ad_slot'));

/* ---------------------------------------------------------------- 25. 魔方财务对接 */
section('25. 魔方财务（ZJMF）对接 · 三开关 / 密钥 / 商品树 / 下单');

// 开关：总开关历史默认开，实名与手机默认关（保持既有客户可直接下单）
set_setting(YxZjmf::SET_ENABLED, '1');
set_setting(YxZjmf::SET_REALNAME, '0');
set_setting(YxZjmf::SET_PHONE, '0');
t('API 总开关默认开启', YxZjmf::switchOn(YxZjmf::SET_ENABLED));
t('实名 / 手机开关默认关闭', !YxZjmf::switchOn(YxZjmf::SET_REALNAME) && !YxZjmf::switchOn(YxZjmf::SET_PHONE));

YxZjmf::setSwitch(YxZjmf::SET_ENABLED, false);
t('总开关可关闭', !YxZjmf::switchOn(YxZjmf::SET_ENABLED));
$ga = YxZjmf::auth(['api_name' => YxZjmf::apiName(), 'api_key' => YxZjmf::apiKey()]);
eq('总开关关闭时鉴权直接返回 503', 503, $ga['code']);
$gc = YxZjmf::createOrder(['product_id' => 1, 'email' => 'a@b.com']);
eq('总开关关闭时下单返回 503', 503, $gc['status']);
YxZjmf::setSwitch(YxZjmf::SET_ENABLED, true);
t('总开关可重新开启', YxZjmf::switchOn(YxZjmf::SET_ENABLED));

// 未知键不会被写进设置表
YxZjmf::setSwitch('some_random_key', true);
t('未知开关键被忽略', setting('some_random_key') === null);

// 密钥：加密存储 + 名称/密钥双校验
$k1 = YxZjmf::apiKey(true);
t('密钥可按需生成', strlen($k1) >= 32);
t('密钥加密存储（设置表里不是明文）', (string)setting(YxZjmf::SET_KEY) !== $k1 && yx_decrypt((string)setting(YxZjmf::SET_KEY)) === $k1);
t('脱敏展示不回显明文', strpos(YxZjmf::keyMasked(), $k1) === false && strpos(YxZjmf::keyMasked(), substr($k1, -4)) !== false);

$name = YxZjmf::apiName();
$okAuth = YxZjmf::auth(['api_name' => $name, 'api_key' => $k1]);
t('正确的接口名称与密钥通过校验', !empty($okAuth['ok']));
eq('密钥错误返回 401', 401, YxZjmf::auth(['api_name' => $name, 'api_key' => 'wrong'] )['code']);
eq('名称错误返回 401', 401, YxZjmf::auth(['api_name' => 'other', 'api_key' => $k1])['code']);
eq('缺参数返回 400', 400, YxZjmf::auth(['api_name' => $name])['code']);

$k2 = YxZjmf::rotateKey();
t('重置密钥后新密钥生效', !empty(YxZjmf::auth(['api_name' => $name, 'api_key' => $k2])['ok']));
t('重置密钥后旧密钥失效', empty(YxZjmf::auth(['api_name' => $name, 'api_key' => $k1])['ok']));

// 商品树：本站产品未分组，必须仍给出三层结构
YxDb::exec('DELETE FROM ' . YxDb::table('product_group'));
YxDb::exec('DELETE FROM ' . YxDb::table('product'));
$pA = YxDb::insert('product', [
    'group_id' => 0, 'name' => '测试云 A', 'type' => 'host', 'cycle' => 'month',
    'price' => 10, 'setup_fee' => 0, 'stock' => -1, 'status' => 1, 'sort' => 1,
    'created_at' => now(),
]);
$gid = YxDb::insert('product_group', ['name' => '测试分组', 'sort' => 1, 'status' => 1]);
$pB = YxDb::insert('product', [
    'group_id' => $gid, 'name' => '测试云 B', 'type' => 'host', 'cycle' => 'month',
    'price' => 20, 'setup_fee' => 5, 'stock' => 0, 'status' => 1, 'sort' => 2,
    'created_at' => now(),
]);
YxDb::insert('product', [
    'group_id' => 0, 'name' => '已下架产品', 'type' => 'host', 'cycle' => 'month',
    'price' => 1, 'setup_fee' => 0, 'stock' => -1, 'status' => 0, 'sort' => 3,
    'created_at' => now(),
]);

$tree = YxZjmf::productTree();
t('商品树顶层是 fgs', isset($tree['fgs']) && is_array($tree['fgs']));
eq('分类数量（已下架产品不进树）', 2, count($tree['fgs']));
$fgDefault = null;
$fgNamed   = null;
foreach ($tree['fgs'] as $fg) {
    if ((int)$fg['id'] === 0) {
        $fgDefault = $fg;
    } else {
        $fgNamed = $fg;
    }
}
t('未分组产品归入默认分类', $fgDefault !== null && $fgDefault['name'] === '默认分类');
eq('默认分类下 1 个商品', 1, count($fgDefault['products'] ?? []));
eq('已分组产品在对应分类下', 1, count($fgNamed['products'] ?? []));
$rowB = $fgNamed['products'][0];
t('商品行键名对齐魔方（products/qty/product_price）',
    isset($rowB['products']) === false && isset($rowB['product_price']) && isset($rowB['qty'])
        && isset($rowB['billingcycle']) && isset($rowB['stock_control']) && isset($rowB['billingcycles']));
eq('售罄商品 qty 如实为 0', 0, $rowB['qty']);
eq('商品 code 缺省时回落为 id', (string)$pA, $fgDefault['products'][0]['code']);
eq('商品树扁平化后与产品数一致', 2, count(YxZjmf::productList()));

// 下单：实名 / 手机开关生效
$cPlain = customer_insert([
    'username' => 'zjmf_plain', 'email' => 'plain@test.local', 'phone' => '',
    'password' => YxAuth::hash('x'), 'balance' => 0, 'credit' => 0, 'points' => 0,
    'level' => 'normal', 'status' => 1, 'created_at' => now(),
]);
$cReal  = customer_insert([
    'username' => 'zjmf_real', 'email' => 'real@test.local', 'phone' => '13800000000',
    'password' => YxAuth::hash('x'), 'balance' => 0, 'credit' => 0, 'points' => 0,
    'level' => 'normal', 'status' => 1, 'created_at' => now(),
]);
YxDb::update('customer', ['realname_status' => 'verified'], 'id = :id', ['id' => $cReal]);

$order = YxZjmf::createOrder(['product_id' => $pA, 'cycle' => 'month', 'email' => 'plain@test.local', 'external_sn' => 'SELFTEST-1']);
eq('开关关闭时未实名客户可下单', 200, $order['status']);
t('订单带接口来源与外部单号', (string)YxDb::value('SELECT source FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$order['data']['order_id']]) === YxZjmf::APPID);

$dup = YxZjmf::createOrder(['product_id' => $pA, 'email' => 'plain@test.local', 'external_sn' => 'SELFTEST-1']);
eq('同 external_sn 幂等返回原单', (int)$order['data']['order_id'], (int)$dup['data']['order_id']);

YxZjmf::setSwitch(YxZjmf::SET_REALNAME, true);
$r = YxZjmf::createOrder(['product_id' => $pA, 'email' => 'plain@test.local']);
eq('实名开关开启后未实名客户下单被拒（403）', 403, $r['status']);
$r = YxZjmf::createOrder(['product_id' => $pA, 'email' => 'real@test.local']);
eq('实名开关开启后已实名客户可下单', 200, $r['status']);
YxZjmf::setSwitch(YxZjmf::SET_REALNAME, false);

YxZjmf::setSwitch(YxZjmf::SET_PHONE, true);
$r = YxZjmf::createOrder(['product_id' => $pA, 'email' => 'plain@test.local']);
eq('手机开关开启后未绑手机客户下单被拒（403）', 403, $r['status']);
$r = YxZjmf::createOrder(['product_id' => $pA, 'customer_id' => $cReal]);
eq('手机开关开启后已绑手机客户可下单', 200, $r['status']);
YxZjmf::setSwitch(YxZjmf::SET_PHONE, false);

// 商品与订单边界
$r = YxZjmf::createOrder(['product_id' => $pB, 'email' => 'real@test.local']);
eq('售罄商品下单被拒（403）', 403, $r['status']);
$r = YxZjmf::createOrder(['product_id' => 999999, 'email' => 'real@test.local']);
eq('不存在的产品返回 404', 404, $r['status']);
$r = YxZjmf::createOrder(['product_id' => $pA]);
eq('缺终端用户标识返回 400', 400, $r['status']);

$f = YxZjmf::findOrder(['order_sn' => $order['data']['order_sn']]);
t('接口创建的订单可查', $f !== null && (int)$f['id'] === (int)$order['data']['order_id']);
t('其他来源订单不可被本接口查到', YxZjmf::findOrder(['order_sn' => 'NOT-EXIST']) === null);


/* ---------------------------------------------------------------- 26. 上游商品上架 */
section('26. 上游商品上架（产品中心同步）');

// 只加载映射函数：主流程按 SCRIPT_FILENAME 判断，被 include 时不会执行
require_once $root . '/tools/import_upstream.php';

$p = yx_import_payload(
    ['id' => 31, 'name' => '年付商品', 'price' => '399.00', 'cycle' => '一年', 'qty' => 36, 'stock_control' => 0],
    3, '测试上游'
);
eq('年付价折算为月价', 33.25, $p['product']['price']);
eq('周期统一按月展示', 'month', $p['product']['cycle']);
eq('上游不控制库存时记 -1', -1, $p['product']['stock']);
eq('有价有货的商品上架', 1, $p['status']);
eq('产品编码带上游前缀', 'up3-31', $p['product']['code']);
t('商品说明标注来源与上游编码', mb_strpos($p['product']['description'], '商品编码 31') !== false);

$p = yx_import_payload(
    ['id' => 126, 'name' => '售罄商品', 'price' => '698.00', 'cycle' => '1个月', 'qty' => 0, 'stock_control' => 1],
    3, '测试上游'
);
eq('上游控制库存时取 qty', 0, $p['product']['stock']);
eq('库存为 0 的商品不上架', 0, $p['status']);

$p = yx_import_payload(
    ['id' => 162, 'name' => '0元商品', 'price' => '0.00', 'cycle' => '0', 'qty' => 0, 'stock_control' => 0],
    3, '测试上游'
);
eq('0 元商品不上架', 0, $p['status']);
eq('0 元商品价格保持 0', 0.0, $p['product']['price']);

$gid = YxDb::insert('product_group', ['name' => '测试上游分组', 'sort' => 0, 'status' => 1]);
$upRow = ['id' => 99, 'name' => '测试上游'];
$items = [
    ['id' => 1, 'name' => 'A型', 'price' => '120.00', 'cycle' => '月', 'qty' => 5, 'stock_control' => 1],
    ['id' => 2, 'name' => 'B型', 'price' => '0.00', 'cycle' => '月', 'qty' => 5, 'stock_control' => 0],
];
$before = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('product'));

$s1 = yx_import_upstream_items($upRow, $items, ['group_id' => $gid]);
eq('首次同步新增 2 个产品', 2, $s1['create']);
eq('首次同步写入 2 条映射', 2, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('upstream_map') . ' WHERE upstream_id = 99'
));

$s2 = yx_import_upstream_items($upRow, $items, ['group_id' => $gid]);
eq('重复同步不新增产品', 0, $s2['create']);
eq('重复同步按映射更新', 2, $s2['update']);
eq('重复同步后产品数只增加 2 个', $before + 2, (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('product')));
eq('重复同步后映射仍为 2 条', 2, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('upstream_map') . ' WHERE upstream_id = 99'
));

$pid1 = (int)YxDb::value(
    'SELECT product_id FROM ' . YxDb::table('upstream_map') . ' WHERE upstream_id = 99 AND remote_code = ?',
    ['1']
);
$p1 = YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ?', [$pid1]);
eq('同步后的产品价格为上游月价', 120.0, (float)$p1['price']);
eq('同步后的产品为人工开通', 0, (int)$p1['auto_setup']);
eq('同步后的产品归属指定分组', $gid, (int)$p1['group_id']);
eq('上游原价记在映射里', 120.0, (float)YxDb::value(
    'SELECT price FROM ' . YxDb::table('upstream_map') . ' WHERE upstream_id = 99 AND remote_code = ?',
    ['1']
));

$s3 = yx_import_upstream_items($upRow, [$items[0]], ['group_id' => $gid]);
eq('上游已消失的商品被下架', 1, $s3['unpublish']);
eq('下架后本地产品状态为 0', 0, (int)YxDb::value(
    'SELECT status FROM ' . YxDb::table('product') . ' WHERE code = ?',
    ['up99-2']
));
$s4 = yx_import_upstream_items($upRow, [$items[0]], ['group_id' => $gid, 'keep_missing' => true]);
eq('keep_missing 时不下架', 0, $s4['unpublish']);

/* 勾选上架（后台「上游对接」多选导入）：只处理勾选的编码，条目以原始条目为准 */
$pickMsg = '';
$pickEmpty = yx_import_pick_items($upRow, ['', '  '], [], [], $pickMsg);
eq('未勾选时不导入', 0, count($pickEmpty['picked']));
t('未勾选时提示先勾选', mb_strpos($pickMsg, '勾选') !== false);

$rawItems = [
    ['id' => 5, 'name' => '原始五号', 'price' => '60.00', 'cycle' => '一年', 'qty' => 2, 'stock_control' => 1],
    ['id' => 6, 'name' => '原始六号', 'price' => '30.00', 'cycle' => '月', 'qty' => 4, 'stock_control' => 1],
];
$previewItems = [
    ['code' => '5', 'name' => '预览五号', 'price' => 5.0, 'cycle' => 'month', 'stock' => 2],
    ['code' => '6', 'name' => '预览六号', 'price' => 30.0, 'cycle' => 'month', 'stock' => 4],
];
$pm = '';
$pick = yx_import_pick_items($upRow, ['5', ' 6 ', '5'], $previewItems, $rawItems, $pm);
eq('重复勾选的编码去重', 2, count($pick['codes']));
eq('勾选后取出对应条目', 2, count($pick['picked']));
eq('条目取原始条目而非预览', '原始五号', $pick['picked'][0]['name']);
eq('年付条目保留 cycle 供折算', '一年', (string)$pick['picked'][0]['cycle']);

$pm2 = '';
$pickBogus = yx_import_pick_items($upRow, ['not-exist'], $previewItems, $rawItems, $pm2);
eq('伪造编码不导入', 0, count($pickBogus['picked']));
t('伪造编码给出重新拉取提示', mb_strpos($pm2, '不存在') !== false);

$pm3 = '';
$pickGone = yx_import_pick_items($upRow, ['7'], [['code' => '7', 'name' => '已下架', 'price' => 10]], $rawItems, $pm3);
eq('上游已下架的编码不导入', 0, count($pickGone['picked']));
eq('上游已下架的编码被标记', ['7'], $pickGone['missing']);

$s5 = yx_import_upstream_items(
    ['id' => 98, 'name' => '勾选上游'],
    $rawItems,
    ['group_id' => $gid, 'picked_codes' => ['6'], 'keep_missing' => true]
);
eq('只上架勾选的 1 个商品', 1, $s5['total']);
eq('勾选上架新增 1 个产品', 1, $s5['create']);
eq('未勾选的商品不上架', 0, (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('product') . ' WHERE code = ?',
    ['up98-5']
));

$unimported = yx_import_filter_unimported(['id' => 98], $rawItems);
eq('只看未上架时过滤掉已上架商品', ['5'], array_column($unimported, 'id'));

/* 单条导入页：利润百分比 + 会员中心导航分类（写 product.type） */
$p = yx_import_payload(
    ['id' => 7, 'name' => '加价商品', 'price' => '120.00', 'cycle' => '月'],
    99, '测试上游', 0, ['profit_percent' => 25, 'type' => 'vps']
);
eq('利润百分比在上游月价上加价', 150.0, $p['product']['price']);
eq('会员中心导航分类写入产品类型', 'vps', $p['product']['type']);
$p = yx_import_payload(
    ['id' => 8, 'name' => '未知分类', 'price' => '120.00', 'cycle' => '月'],
    99, '测试上游', 0, ['profit_percent' => 1000.5, 'type' => 'bogus']
);
eq('非法导航分类退回虚拟主机', 'host', $p['product']['type']);
eq('利润百分比上限 1000', 1320.0, $p['product']['price']);

// 显式利润/分类：重导入时覆盖售价与分类，且只动勾选的商品
$s6 = yx_import_upstream_items($upRow, [
    ['id' => '1', 'name' => 'A型', 'price' => '150.00', 'cycle' => '月', 'qty' => 5, 'stock_control' => 1],
], [
    'group_id' => $gid, 'keep_missing' => true, 'picked_codes' => ['1'],
    'force_price' => true, 'profit_percent' => 25, 'type' => 'vps',
]);
eq('显式利润时更新已有商品', 1, $s6['update']);
eq('显式利润覆盖已有售价', 187.5, (float)YxDb::value(
    'SELECT price FROM ' . YxDb::table('product') . ' WHERE code = ?', ['up99-1']
));
eq('显式分类覆盖已有分类', 'vps', (string)YxDb::value(
    'SELECT type FROM ' . YxDb::table('product') . ' WHERE code = ?', ['up99-1']
));

// 上游临时不给价格（0 元）时不能把在售商品改成免费
$s7 = yx_import_upstream_items($upRow, [
    ['id' => '1', 'name' => 'A型', 'price' => '0.00', 'cycle' => '月', 'qty' => 5, 'stock_control' => 1],
], [
    'group_id' => $gid, 'keep_missing' => true, 'picked_codes' => ['1'],
    'force_price' => true, 'profit_percent' => 0, 'type' => 'vps',
]);
eq('上游 0 元不覆盖已有售价', 187.5, (float)YxDb::value(
    'SELECT price FROM ' . YxDb::table('product') . ' WHERE code = ?', ['up99-1']
));
eq('上游 0 元时不擅自下架', 1, (int)YxDb::value(
    'SELECT status FROM ' . YxDb::table('product') . ' WHERE code = ?', ['up99-1']
));

/* 魔方原始条目：周期/库存字段保留，价格单位（分）与编码回填正确 */
$stub = new class(['id' => 97, 'driver' => 'zjmf', 'endpoint' => 'https://up.example', 'config' => json_encode(['price_unit' => 'cent'])]) extends YxUpstreamZjmf {
    public $resp = [];
    public function request(string $action, array $params = [], string $remoteId = ''): array
    {
        return $this->resp;
    }
};
$stub->resp = [
    'ok'   => true,
    'list' => [
        ['code' => 'a1', 'name' => 'A', 'price' => 120.0, 'stock' => 5, 'spec' => []],
        ['code' => 'a2', 'name' => 'B', 'price' => 399.0, 'stock' => null, 'spec' => []],
    ],
    'raw_list' => [
        ['id' => 'a1', 'name' => 'A', 'price' => 12000, 'cycle' => '月', 'qty' => 5, 'stock_control' => 1],
        ['id' => 'a2', 'name' => 'B', 'price' => 39900, 'cycle' => '一年', 'qty' => 0, 'stock_control' => 0],
    ],
];
$rw = $stub->productsRaw();
eq('原始条目价格按分折算', 120.0, (float)$rw['list'][0]['price']);
eq('原始条目保留计费周期', '一年', (string)$rw['list'][1]['cycle']);
eq('原始条目保留库存控制标记', 1, (int)$rw['list'][0]['stock_control']);
eq('统一编码回填到原始条目', 'a2', (string)$rw['list'][1]['id']);

// 原始条目里混入空节点时编码不能错位
$stub->resp['raw_list'] = [
    'bad-node',
    ['id' => 'b1', 'name' => 'B1', 'price' => 100, 'cycle' => '月', 'qty' => 1, 'stock_control' => 1],
];
$stub->resp['list'] = [['code' => 'b1', 'name' => 'B1', 'price' => 1.0, 'stock' => 1, 'spec' => []]];
$rw = $stub->productsRaw();
eq('空节点跳过后的编码仍正确', 'b1', (string)$rw['list'][0]['id']);


/* ---------------------------------------------------------------- 27. 管理员角色权限 */
section('27. 管理员角色权限（受限管理员）');

// 菜单定义只在后台布局里加载，自检需显式引入
require_once $root . '/includes/nav.php';

$owner    = ['id' => 1, 'username' => 'owner01', 'role' => 'owner', 'status' => 1];
$operator = ['id' => 2, 'username' => 'ops01', 'role' => 'operator', 'status' => 1];
$legacy   = ['id' => 3, 'username' => 'legacy01', 'role' => '', 'status' => 1];

t('超级管理员可以进应用商店', YxAuth::canAdmin('store', $owner));
t('超级管理员可以进开发者中心', YxAuth::canAdmin('developer', $owner));
t('超级管理员可以管理管理员', YxAuth::canAdmin('admin_users', $owner));
t('运营管理员不能进应用商店', !YxAuth::canAdmin('store', $operator));
t('运营管理员不能进开发者中心', !YxAuth::canAdmin('developer', $operator));
t('运营管理员不能管理管理员', !YxAuth::canAdmin('admin_users', $operator));
t('运营管理员可以进产品管理', YxAuth::canAdmin('products', $operator));
t('运营管理员可以进上游对接', YxAuth::canAdmin('upstream', $operator));
t('运营管理员可以进订单管理', YxAuth::canAdmin('orders', $operator));
t('运营管理员可以进站务管理', YxAuth::canAdmin('settings', $operator));
eq('空角色按超级管理员处理（兼容旧数据）', 'owner', YxAuth::roleOf($legacy));
t('未登录者没有任何权限', !YxAuth::canAdmin('products', []));
eq('角色中文名', '运营管理员', YxAuth::roleName('operator'));

// 受限账号登录后，菜单不应出现被禁入口，其余入口照常
YxDb::insert('admin', [
    'username' => 'selftest_op_menu', 'password' => YxAuth::hash('password123'),
    'nickname' => '自检受限', 'role' => 'operator', 'status' => 1, 'created_at' => now(),
]);
$opRow = YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE username = ?', ['selftest_op_menu']);
$_SESSION['admin_id'] = (int)$opRow['id'];
$GLOBALS['_yx_menu_op'] = yx_render_menu(yx_admin_menu(), '', url(''));
unset($_SESSION['admin_id']);
t('受限账号菜单不显示商店管理', mb_strpos($GLOBALS['_yx_menu_op'], '商店管理') === false);
t('受限账号菜单不显示开发者中心', mb_strpos($GLOBALS['_yx_menu_op'], '开发者中心') === false);
t('受限账号菜单不显示管理员管理', mb_strpos($GLOBALS['_yx_menu_op'], '管理员管理') === false);
t('受限账号菜单仍显示上游对接', mb_strpos($GLOBALS['_yx_menu_op'], '上游对接') !== false);
t('受限账号菜单仍显示站务管理', mb_strpos($GLOBALS['_yx_menu_op'], '站务管理') !== false);
t('受限账号菜单仍显示电脑版访问', mb_strpos($GLOBALS['_yx_menu_op'], '电脑版访问') !== false);
t('受限账号菜单指向的表单不含 store.php', mb_strpos($GLOBALS['_yx_menu_op'], 'admin/store.php') === false);
t('受限账号菜单指向的表单不含 developers.php', mb_strpos($GLOBALS['_yx_menu_op'], 'developers.php') === false);

// 超级管理员菜单完整
YxDb::insert('admin', [
    'username' => 'selftest_owner', 'password' => YxAuth::hash('password123'),
    'nickname' => '自检超管', 'role' => 'owner', 'status' => 1, 'created_at' => now(),
]);
$ownerRow = YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE username = ?', ['selftest_owner']);
// YxAuth::admin() 在一次进程内缓存身份（Web 每次请求都是新进程，无影响），
// 同一进程里切账号不能依赖会话；菜单门禁用 canAdmin($perm, $admin) 直接判定，
// 这里用与页面相同的判定方式验证超管视角的可见性。
t('超管可见商店管理', YxAuth::canAdmin('store', $ownerRow));
t('超管可见开发者中心', YxAuth::canAdmin('developer', $ownerRow));
t('超管可见管理员管理', YxAuth::canAdmin('admin_users', $ownerRow));
$menuKeys = array_column(array_filter(yx_admin_menu(), function ($i) { return isset($i['key']); }), 'key');
t('后台菜单含电脑版入口', in_array('desktop', $menuKeys, true));
$desktopItem = array_values(array_filter(yx_admin_menu(), function ($i) { return ($i['key'] ?? '') === 'desktop'; }))[0];
t('电脑版入口在新窗口打开', !empty($desktopItem['new_tab']));
t('电脑版入口指向 desktop.php', $desktopItem['url'] === 'desktop.php');
t('受限账号菜单里的电脑版入口可在新窗口打开', mb_strpos($GLOBALS['_yx_menu_op'], 'desktop.php') !== false);

// 受限账号可用账号密码登录
YxDb::insert('admin', [
    'username' => 'selftest_op', 'password' => YxAuth::hash('password123'),
    'nickname' => '自检运营', 'role' => 'operator', 'status' => 1, 'created_at' => now(),
]);
$r = YxAuth::attemptAdmin('selftest_op', 'password123');
t('受限管理员可以账号密码登录', !empty($r['ok']));
t('登录后会话里是受限账号', YxAuth::canAdmin('store') === false);
unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_time']);
$r = YxAuth::attemptAdmin('selftest_op', 'wrong-password');
t('密码错误不能登录', empty($r['ok']));
YxDb::update('admin', ['status' => 0], 'username = :u', ['u' => 'selftest_op']);
$r = YxAuth::attemptAdmin('selftest_op', 'password123');
t('停用的管理员不能登录', empty($r['ok']));

/* ---------------------------------------------------------------- 28. 电脑版访问 */
section('28. 电脑版访问入口');

$desktopFile = $root . '/desktop.php';
t('电脑版入口文件存在', is_file($desktopFile));
$desktopSrc = (string)file_get_contents($desktopFile);
t('电脑版入口要求管理员登录', mb_strpos($desktopSrc, 'YxAuth::requireAdmin()') !== false);
t('电脑版入口提供打开站点链接', mb_strpos($desktopSrc, '打开电脑版') !== false);

// 前台同一套响应式页面：不存在移动端重定向
$hasMobileRedirect = false;
foreach (glob($root . '/includes/*.php') as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match('#header\s*\(\s*[\'"]Location:\s*[^\'"]*m\.#i', $src)) {
        $hasMobileRedirect = true;
        break;
    }
}
t('前台不存在移动端强制跳转', !$hasMobileRedirect);

/* ---------------------------------------------------------------- 29. 代理申请 */
section('29. 代理中心（申请 → 审核 → 开通入口）');

$applyCid = customer_insert([
    'username' => 'selftest_apply', 'email' => 'apply@example.com', 'phone' => null,
    'password' => YxAuth::hash('password123'), 'company' => '',
    'balance' => 0, 'credit' => 0, 'points' => 0, 'level' => 'normal', 'agent_id' => 0, 'status' => 1, 'created_at' => now(),
]);

$r = YxAgentApply::submit($applyCid, ['apply_type' => 'personal', 'subject_name' => '自检代理']);
t('未实名认证不能提交代理申请', empty($r['ok']));

YxDb::update('customer', ['realname_status' => 'verified'], 'id = :id', ['id' => $applyCid]);
$r = YxAgentApply::submit($applyCid, ['apply_type' => 'company', 'subject_name' => '自检科技']);
t('企业代理必须填统一社会信用代码', empty($r['ok']));

$r = YxAgentApply::submit($applyCid, [
    'apply_type' => 'company', 'subject_name' => '自检科技有限公司', 'contact_name' => '张三',
    'phone' => '13800138000', 'email' => 'apply@example.com', 'license_no' => '91330100MA2XXXXX0A',
    'domain' => 'https://Apply.Example.COM/path', 'channel' => '自检渠道', 'expect_sales' => '12000.5',
]);
t('实名后可提交代理申请', !empty($r['ok']));
$applyRow = YxAgentApply::latest($applyCid);
eq('域名归一化为裸域名', 'apply.example.com', (string)$applyRow['domain']);
eq('预估月销售额按两位小数入库', 12000.5, (float)$applyRow['expect_sales']);
eq('新申请状态为待审核', 'pending', (string)$applyRow['status']);

t('待审核期间重复提交被拒绝', empty(YxAgentApply::submit($applyCid, ['apply_type' => 'personal', 'subject_name' => '自检代理'])['ok']));
t('hasPending 反映待审核状态', YxAgentApply::hasPending($applyCid));

$r = YxAgentApply::review((int)$applyRow['id'], true, '资料齐全', 1);
t('管理员可通过申请', !empty($r['ok']));
t('重复审核被拒绝', empty(YxAgentApply::review((int)$applyRow['id'], false, '', 1)['ok']));
eq('审核通过写入审核时间', true, (string)YxDb::value('SELECT reviewed_at FROM ' . YxDb::table('agent_apply') . ' WHERE id = ?', [$applyRow['id']]) !== '');
t('已通过后不再允许重复申请', empty(YxAgentApply::submit($applyCid, ['apply_type' => 'personal', 'subject_name' => '自检代理'])['ok']));
t('isApproved 生效', YxAgentApply::isApproved($applyCid));
t('已通过后不再处于待审核', !YxAgentApply::hasPending($applyCid));

$adminRows = YxAgentApply::adminRows('approved');
eq('后台列表可按状态筛选', 1, count($adminRows));
eq('后台列表带出客户账号', 'selftest_apply', (string)$adminRows[0]['username']);
t('未关联代理账号时 agentOf 为空', YxAgentApply::agentOf($applyCid) === null);

$agentMenu = yx_admin_menu();
$applyMenu = array_values(array_filter($agentMenu, static function ($m) { return ($m['key'] ?? '') === 'agent_apply'; }));
t('后台菜单含代理申请审核入口', count($applyMenu) === 1);
eq('后台菜单入口指向审核页', 'admin/agent_apply.php', (string)($applyMenu[0]['url'] ?? ''));
$auditSrc = (string)file_get_contents($root . '/admin/agent_apply.php');
t('审核页不再使用未注册的权限键', mb_strpos($auditSrc, "requireAdminPermission('agent')") === false);
$centerSrc = (string)file_get_contents($root . '/user/agent_center.php');
t('用户端代理中心要求客户登录', mb_strpos($centerSrc, 'YxAuth::requireCustomer()') !== false);

/* ---------------------------------------------------------------- 小优助手（机器人问答弹窗） */
section('小优助手 · 机器人问答弹窗');

t('客服模块默认开启', YxChat::enabled());
t('AI 引擎默认走内置规则驱动', YxAi::provider() === 'mock');
set_setting('ai_provider', 'mock');
set_setting('ai_enabled', '1');

/* 常见问题应命中对应意图，且引用的价格与站内数据一致 */
$aiCases = [
    '你好'            => 'greet',
    '你们有什么产品？' => 'price',
    '正版程序包多少钱' => 'package',
    '开源版是免费的吗' => 'open',
    '保证金多少钱'     => 'developer',
    '上传模板要保证金吗' => 'developer',
    '分销怎么玩'       => 'affiliate',
    '怎么开票'         => 'invoice',
    '续费到哪续'       => 'renew',
    '忘记密码怎么办'   => 'account',
    '签到'            => 'checkin',
];
$intentOk = true;
foreach ($aiCases as $ask => $expect) {
    if ((YxAi::ask($ask)['intent'] ?? '') !== $expect) {
        $intentOk = false;
        break;
    }
}
t('常见问题命中预期意图', $intentOk);

$pkgAns = (string)(YxAi::ask('正版程序包多少钱')['reply'] ?? '');
t('正版包回答授权包按次收费 366 元', mb_strpos($pkgAns, money(366, true)) !== false && mb_strpos($pkgAns, '次') !== false);
t('正版包回答说明程序包免费下载', mb_strpos($pkgAns, '免费下载') !== false);
t('正版包回答含安装授权码指引', mb_strpos($pkgAns, '安装授权码') !== false);

$devAns = (string)(YxAi::ask('保证金多少钱')['reply'] ?? '');
t('保证金回答含 600 元应用/插件额度', mb_strpos($devAns, money((float)setting('developer_plugin_deposit_amount', 600), true)) !== false);
t('保证金回答说明模板免保证金', mb_strpos($devAns, '免保证金') !== false);

$priceAns = (string)(YxAi::ask('你们有什么产品？')['reply'] ?? '');
t('产品报价引用在售产品名', mb_strpos($priceAns, '首页 → 产品服务') !== false);
t('产品报价不含 0 元占位价', mb_strpos($priceAns, '¥0.00') === false);

$affAns = (string)(YxAi::ask('分销佣金多少')['reply'] ?? '');
t('分销回答引用佣金比例', mb_strpos($affAns, '10%') !== false);

/* 兜底：首轮提示不转人工，连续两轮未命中才转人工 */
$fb1 = YxAi::ask('今天天气怎么样');
t('未命中首轮给出引导不转人工', empty($fb1['handoff']) && mb_strpos((string)$fb1['reply'], '没有把握回答') !== false);
$fbHist = [
    ['role' => 'user', 'content' => '今天天气怎么样'],
    ['role' => 'assistant', 'content' => (string)$fb1['reply']],
];
$fb2 = YxAi::ask('那明天呢', $fbHist);
t('连续未命中转人工', !empty($fb2['handoff']));

/* 售后与退款类应交由人工处理 */
t('售后故障问题转人工', !empty(YxAi::ask('服务器打不开了')['handoff']));
t('退款问题转人工', !empty(YxAi::ask('我要退款')['handoff']));

/* 会话链路：建会话 → 发消息 → AI 应答 → 自动转人工建工单 */
$chatTok = random_key(16);
$chatSess = YxChat::ensureSession($chatTok, null, '127.0.0.1', 'http://selftest.local/');
t('会话创建成功且为 AI 值守', (int)$chatSess['id'] > 0 && $chatSess['status'] === 'ai');
$welcomeMsgs = YxChat::messages((int)$chatSess['id']);
t('会话带欢迎语', count($welcomeMsgs) === 1 && $welcomeMsgs[0]['role'] === 'ai');

$r = YxChat::visitorSend($chatSess, '你们有什么产品？');
t('访客发送成功', !empty($r['ok']));
$after1 = YxChat::messages((int)$chatSess['id']);
$lastAi = end($after1);
t('AI 已自动回复产品问题', $lastAi['role'] === 'ai' && (string)$lastAi['content'] !== '');
t('产品问答后仍在 AI 阶段', YxChat::byId((int)$chatSess['id'])['status'] === 'ai');

YxChat::visitorSend(YxChat::byId((int)$chatSess['id']), '服务器连不上了');
$sessAfter = YxChat::byId((int)$chatSess['id']);
t('售后问题自动转人工', $sessAfter['status'] === 'waiting');
t('转人工自动生成工单', (int)$sessAfter['ticket_id'] > 0);
$ticketSn = (string)YxDb::value('SELECT sn FROM ' . YxDb::table('ticket') . ' WHERE id = ?', [(int)$sessAfter['ticket_id']]);
t('工单号已生成', $ticketSn !== '');

/* 留言：商务合作落库 + 建工单 */
$leaveRes = YxChat::leave(YxChat::byId((int)$chatSess['id']), 'business', '13800002222', '批量采购云服务器 20 台');
t('商务合作留言提交成功', !empty($leaveRes['ok']) && !empty($leaveRes['ticket_sn']));
t('留言写入 chat_leave 表', (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('chat_leave') . ' WHERE session_id = ?', [(int)$chatSess['id']]) === 1);
t('留言类型校验生效', empty(YxChat::leave(YxChat::byId((int)$chatSess['id']), 'unknown', '13800002222', '测试')['ok']));
t('联系方式过短被拒绝', empty(YxChat::leave(YxChat::byId((int)$chatSess['id']), 'aftersale', '1', '测试内容')['ok']));

/* 空消息与超长消息校验 */
t('空消息被拒绝', empty(YxChat::visitorSend(YxChat::byId((int)$chatSess['id']), '  ')['ok']));
t('超长消息被拒绝', empty(YxChat::visitorSend(YxChat::byId((int)$chatSess['id']), str_repeat('测', YxChat::MAX_LEN + 1))['ok']));

/* 弹窗组件渲染 */
$widgetSrc = (string)file_get_contents($root . '/includes/chat_widget.php');
t('弹窗组件含浮钮与面板', mb_strpos($widgetSrc, 'yxChatFab') !== false && mb_strpos($widgetSrc, 'yxChatPanel') !== false);
t('弹窗组件含商务合作/售后咨询入口', mb_strpos($widgetSrc, 'data-topic="business"') !== false && mb_strpos($widgetSrc, 'data-topic="aftersale"') !== false);
t('弹窗组件提交 CSRF 头', mb_strpos($widgetSrc, 'X-CSRF-Token') !== false);
foreach (['footer.php', 'user_footer.php', 'panel_footer.php'] as $f) {
    t('页脚引入弹窗：' . $f, mb_strpos((string)file_get_contents($root . '/includes/' . $f), 'chat_widget.php') !== false);
}
t('弹窗样式文件存在', is_file($root . '/assets/css/chat.css'));
t('客服接口存在', is_file($root . '/api/chat.php'));

/* 内置授权：本站恒为已授权，安装不再索要授权码 */
t('内置授权开关已开启', YxLicense::ALWAYS_AUTHORIZED === true);
t('本站判定为已授权', YxLicense::isAuthorized());
t('授权徽标为正版', YxLicense::badge()['class'] === 'ok');
t('授权版本为企业版', YxLicense::query(current_domain())['edition'] === 'enterprise');
t('产品密钥为明文常量且非空', YxLicense::PACKAGE_SECRET !== '');
t('正版包授权码可签发', (bool)preg_match('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', YxLicense::packageKey('example.com', 'official')));
t('开源包授权码可签发', (bool)preg_match('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', YxLicense::packageKey('example.com', 'community')));
t('安装向导不再强制授权码', (bool)preg_match('/function install_license_required\(\): bool\s*\{\s*return false;/', (string)file_get_contents($root . '/install/install.php')));
t('安装向导不依赖密封数据', strpos((string)file_get_contents($root . '/install/install.php'), 'yx_pkg_sealed') === false);
t('安装校验恒为通过', strpos((string)file_get_contents($root . '/install/install.php'), '已授权：本程序包内置') !== false);
t('已移除密封数据文件', !is_file($root . '/includes/pkgsecret.php'));

/* ---------------------------------------------------------------- 收尾 */
echo "\n" . str_repeat('-', 62) . "\n";
printf("结果：\033[32m%d 项通过\033[0m，\033[%sm%d 项失败\033[0m，共执行 SQL %d 次\n",
    $GLOBALS['_yx_pass'],
    $GLOBALS['_yx_fail'] > 0 ? '31' : '32',
    $GLOBALS['_yx_fail'],
    YxDb::$queryCount
);
if ($GLOBALS['_yx_fail'] > 0) {
    echo "\n失败项：\n";
    foreach ($GLOBALS['_yx_failures'] as $f) {
        echo "  - {$f}\n";
    }
}

// 清理自检产生的授权缓存，避免影响正式环境
YxLicense::flushCache();

// 清理临时库
if (!$keep && !is_string($args['db'] ?? null)) {
    YxDb::pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    echo "临时数据库已清理。\n";
} else {
    echo "临时数据库已保留：{$tmpDb}\n";
}

exit($GLOBALS['_yx_fail'] > 0 ? 1 : 0);
