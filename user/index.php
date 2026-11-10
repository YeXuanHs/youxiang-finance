<?php
/**
 * 优享财务系统 · 用户中心概览（控制台）
 *
 * 版式参考 007idc.cn clientarea 浅色控制台：顶部欢迎横幅 + 四宫格指标 + 左主区（待办 / 业务 / 安全）
 * + 右窄栏（授权 / 快捷入口 / 工单 / 公告 / 推广）。样式见 assets/css/user.css（uc-* 前缀）。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid = (int)$customer['id'];
$siteName = setting('site_name', config('app.name'));

/* ------------------------------ 账户与业务统计 ------------------------------ */
$svcTotal  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE customer_id = ?', [$cid]);
$svcActive = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . " WHERE customer_id = ? AND status = 'active'", [$cid]);
$orderTotal = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . ' WHERE customer_id = ?', [$cid]);
$unpaidCnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . " WHERE customer_id = ? AND status = 'unpaid'", [$cid]);
$unpaidAmt = (float)YxDb::value('SELECT COALESCE(SUM(total),0) FROM ' . YxDb::table('order') . " WHERE customer_id = ? AND status = 'unpaid'", [$cid]);
$openTickets = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ticket') . " WHERE customer_id = ? AND status IN ('open','replied')", [$cid]);
$appInstalls = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_install') . ' WHERE customer_id = ?', [$cid]);

$monthStart = date('Y-m-01 00:00:00');
$lastStart  = date('Y-m-01 00:00:00', strtotime('-1 month'));
$thisMonthSpend = (float)YxDb::value(
    'SELECT COALESCE(SUM(total),0) FROM ' . YxDb::table('order') . ' WHERE customer_id = ? AND status = ? AND paid_at >= ?',
    [$cid, 'paid', $monthStart]
);
$lastMonthSpend = (float)YxDb::value(
    'SELECT COALESCE(SUM(total),0) FROM ' . YxDb::table('order') . ' WHERE customer_id = ? AND status = ? AND paid_at >= ? AND paid_at < ?',
    [$cid, 'paid', $lastStart, $monthStart]
);
$totalSpend = (float)YxDb::value(
    'SELECT COALESCE(SUM(total),0) FROM ' . YxDb::table('order') . ' WHERE customer_id = ? AND status = ?',
    [$cid, 'paid']
);
$spendTrend = $lastMonthSpend > 0
    ? round(($thisMonthSpend - $lastMonthSpend) / $lastMonthSpend * 100, 1)
    : ($thisMonthSpend > 0 ? 100.0 : 0.0);

/* ------------------------------ 登录环境 ------------------------------ */
$prevLoginAt = (string)($_SESSION['cust_prev_login_at'] ?? '');
$prevLoginIp = (string)($_SESSION['cust_prev_login_ip'] ?? '');
$realnameOk  = yx_customer_verified($customer);
$realnameTxt = YxRealname::statusName((string)($customer['realname_status'] ?? ''));
$emailOk     = trim((string)($customer['email'] ?? '')) !== '';
$phoneOk     = trim((string)($customer['phone'] ?? '')) !== '';

/* 资产安全评分：实名 / 手机 / 邮箱 / 独立接口密钥，各 25 分 */
$apiEnabled = YxEdition::can('openapi');
$apiCnt     = (int)YxDb::value(
    'SELECT COUNT(*) FROM ' . YxDb::table('api_app') . " WHERE owner_type = 'customer' AND owner_id = ? AND status = 1",
    [$cid]
);
$secItems = [
    ['label' => '实名认证', 'ok' => $realnameOk, 'url' => 'user/realname.php', 'icon' => 'shield'],
    ['label' => '手机绑定', 'ok' => $phoneOk,    'url' => 'user/profile.php', 'icon' => 'phone'],
    ['label' => '邮箱验证', 'ok' => $emailOk,    'url' => 'user/profile.php', 'icon' => 'mail'],
    ['label' => '接口密钥', 'ok' => $apiEnabled && $apiCnt > 0, 'url' => $apiEnabled ? 'user/api.php' : '', 'icon' => 'key'],
];
$secScore = 0;
foreach ($secItems as $it) {
    if ($it['ok']) {
        $secScore += 25;
    }
}
$secLevel = $secScore >= 100 ? ['安全', 'ok'] : ($secScore >= 50 ? ['普通', 'warn'] : ['不安全', 'bad']);

/* ------------------------------ 产品列表 / 工单 / 公告 ------------------------------ */
$services = YxDb::all(
    'SELECT s.*, p.name AS product_name FROM ' . YxDb::table('service') . ' s
     LEFT JOIN ' . YxDb::table('product') . ' p ON p.id = s.product_id
     WHERE s.customer_id = ? ORDER BY s.id DESC LIMIT 6',
    [$cid]
);
$tickets = YxDb::all(
    'SELECT * FROM ' . YxDb::table('ticket') . ' WHERE customer_id = ? ORDER BY updated_at DESC, id DESC LIMIT 4',
    [$cid]
);
$tcStatus = ['open' => '待处理', 'replied' => '已回复', 'closed' => '已关闭'];
$tcClass  = ['open' => 'warn', 'replied' => 'info', 'closed' => 'mute'];

$newsList = [];
if (YxNews::isEnabled()) {
    try {
        $newsList = array_slice(YxNews::published(), 0, 4);
    } catch (Throwable $e) {
        $newsList = [];
    }
}

/* 待办事项：仅列出需要用户动手的项，全部完成时显示完成态 */
$todos = [];
if (!$realnameOk) {
    $todos[] = [
        'title' => $realnameTxt === '认证中' ? '实名认证进行中' : '完成实名认证',
        'desc'  => $realnameTxt === '认证中' ? '扫码核验中，可继续完成认证' : '未实名将影响购买与提现',
        'url'   => 'user/realname.php',
        'btn'   => $realnameTxt === '认证中' ? '继续' : '去认证',
        'icon'  => 'shield', 'tone' => 'warn',
    ];
}
if (!$phoneOk || !$emailOk) {
    $miss = [];
    if (!$phoneOk) { $miss[] = '手机号'; }
    if (!$emailOk) { $miss[] = '邮箱'; }
    $todos[] = [
        'title' => '完善联系方式',
        'desc'  => '未绑定' . implode('、', $miss) . '，无法接收服务与账单通知',
        'url'   => 'user/profile.php',
        'btn'   => '去绑定',
        'icon'  => 'phone', 'tone' => 'warn',
    ];
}
if ($unpaidCnt > 0) {
    $todos[] = [
        'title' => '有 ' . $unpaidCnt . ' 笔订单待支付',
        'desc'  => '待支付金额 ' . money($unpaidAmt, true) . '，支付后自动开通',
        'url'   => 'user/orders.php?status=unpaid',
        'btn'   => '去支付',
        'icon'  => 'bill', 'tone' => 'bad',
    ];
}
if ($svcTotal === 0) {
    $todos[] = [
        'title' => '还没有开通任何业务',
        'desc'  => '浏览产品中心，选择适合您的云产品',
        'url'   => 'products.php',
        'btn'   => '去选购',
        'icon'  => 'server', 'tone' => '',
    ];
}

/* ------------------------------ 授权信息 ------------------------------ */
$siteBadge = YxLicense::badge();
$licDetail = $siteBadge['detail'];

/* ------------------------------ 推广返利 ------------------------------ */
// 带推广码的邀请链接：好友通过该链接注册后归属当前客户，订单按推广计划比例计提佣金
$inviteUrl = YxAffiliate::enabled() ? YxAffiliate::inviteUrl($customer) : url('index.php');
$qrcodeSrc = url('assets/js/qrcode.min.js');

$hour  = (int)date('G');
$greet = $hour < 6 ? '夜深了' : ($hour < 12 ? '早上好' : ($hour < 18 ? '下午好' : '晚上好'));
$userNo = (int)($customer['user_no'] ?? 0) > 0 ? (int)$customer['user_no'] : $cid;

$userTitle  = '账户概览';
$userActive = 'dashboard';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if ($notice = setting('notice', '')): ?>
    <div class="alert alert-warn"><?= e($notice) ?></div>
<?php endif; ?>

<div class="uc-dash">

    <!-- ============ 欢迎横幅 ============ -->
    <section class="uc-hero">
        <div class="uc-hero-main">
            <div class="uc-hero-hello">
                <span class="uc-hero-avatar"><?= e(mb_substr($customer['username'], 0, 1)) ?></span>
                <div>
                    <h1><?= $greet ?>，<?= e($customer['username']) ?></h1>
                    <p>用户 ID：<b><?= $userNo ?></b> · <?= e(YxEdition::name()) ?>
                        · 上次登录 <?= e($prevLoginAt !== '' ? $prevLoginAt : '首次登录') ?>
                        <?= $prevLoginIp !== '' ? '（' . e($prevLoginIp) . '）' : '' ?>
                    </p>
                </div>
            </div>

            <div class="uc-hero-tags">
                <?php if ($realnameOk): ?>
                    <span class="uc-hero-tag"><?= yx_icon('shield', 13) ?>已实名认证</span>
                <?php else: ?>
                    <span class="uc-hero-tag is-warn"><?= yx_icon('shield', 13) ?><?= e($realnameTxt) ?></span>
                <?php endif; ?>
                <span class="uc-hero-tag"><?= yx_icon('server', 13) ?>进行中业务 <?= $svcActive ?></span>
                <?php if ($openTickets > 0): ?>
                    <span class="uc-hero-tag is-warn"><?= yx_icon('mail', 13) ?>待处理工单 <?= $openTickets ?></span>
                <?php endif; ?>
            </div>

            <div class="uc-hero-actions">
                <a class="uc-btn-light" href="<?= e(url('user/recharge.php')) ?>">账户充值</a>
                <a class="uc-btn-line" href="<?= e(url('products.php')) ?>">购买产品</a>
                <?php if (YxEdition::can('ticket')): ?>
                    <a class="uc-btn-line" href="<?= e(url('user/tickets.php')) ?>">提交工单</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="uc-hero-side">
            <div class="uc-hero-num">
                <span>可用余额</span>
                <b><?= e(money($customer['balance'], true)) ?></b>
                <a href="<?= e(url('user/recharge.php')) ?>">立即充值</a>
            </div>
            <div class="uc-hero-num">
                <span>待支付</span>
                <b><?= e(money($unpaidAmt, true)) ?></b>
                <a href="<?= e(url('user/orders.php?status=unpaid')) ?>"><?= $unpaidCnt > 0 ? $unpaidCnt . ' 笔待支付' : '暂无待支付' ?></a>
            </div>
            <?php if ((float)$customer['credit'] > 0): ?>
                <div class="uc-hero-num">
                    <span>授信额度</span>
                    <b><?= e(money($customer['credit'], true)) ?></b>
                </div>
            <?php endif; ?>
            <?php if (YxCheckin::enabled()): ?>
                <div class="uc-hero-num">
                    <span>我的积分</span>
                    <b><?= (int)($customer['points'] ?? 0) ?></b>
                    <a href="<?= e(url('user/checkin.php')) ?>">每日签到</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ 四宫格指标 ============ -->
    <div class="uc-metrics">
        <a class="uc-metric" href="<?= e(url('user/services.php')) ?>">
            <span class="uc-metric-ico"><?= yx_icon('server', 20) ?></span>
            <span class="uc-metric-txt">
                <p>进行中业务</p>
                <b><?= $svcActive ?><em>/ <?= $svcTotal ?> 个</em></b>
            </span>
        </a>
        <a class="uc-metric" href="<?= e(url('user/orders.php?status=unpaid')) ?>">
            <span class="uc-metric-ico<?= $unpaidCnt > 0 ? ' tone-bad' : ' tone-ok' ?>"><?= yx_icon('bill', 20) ?></span>
            <span class="uc-metric-txt">
                <p>待支付订单</p>
                <b><?= $unpaidCnt ?><em>/ <?= $orderTotal ?> 笔</em></b>
            </span>
        </a>
        <a class="uc-metric" href="<?= e(url('user/tickets.php')) ?>">
            <span class="uc-metric-ico<?= $openTickets > 0 ? ' tone-warn' : '' ?>"><?= yx_icon('mail', 20) ?></span>
            <span class="uc-metric-txt">
                <p>进行中工单</p>
                <b><?= $openTickets ?><em>/ 条</em></b>
            </span>
        </a>
        <a class="uc-metric" href="<?= e(url('user/licenses.php')) ?>">
            <span class="uc-metric-ico tone-ok"><?= yx_icon('key', 20) ?></span>
            <span class="uc-metric-txt">
                <p>已安装应用</p>
                <b><?= $appInstalls ?><em>/ <?= e($siteBadge['text']) ?></em></b>
            </span>
        </a>
    </div>

    <!-- ============ 主体 ============ -->
    <div class="uc-grid">
        <!-- 左主区 -->
        <div class="uc-col">

            <!-- 待办事项 -->
            <section class="uc-panel">
                <div class="uc-panel-head">
                    <h2>待办事项<?php if ($todos): ?><em><?= count($todos) ?> 项待处理</em><?php endif; ?></h2>
                    <a class="uc-panel-link" href="<?= e(url('user/orders.php')) ?>">我的订单 →</a>
                </div>
                <?php if (!$todos): ?>
                    <div class="uc-todo-done">
                        <span class="uc-todo-ico tone-ok"><?= yx_icon('check', 16) ?></span>
                        <span class="uc-todo-txt">
                            <b>全部事项已完成</b>
                            <span>账户资料、实名与账单均无待处理项</span>
                        </span>
                        <span class="uc-todo-btn">状态良好</span>
                    </div>
                <?php else: ?>
                    <ul class="uc-todo">
                        <?php foreach ($todos as $td): ?>
                            <li>
                                <a href="<?= e(url($td['url'])) ?>">
                                    <span class="uc-todo-ico<?= $td['tone'] !== '' ? ' tone-' . e($td['tone']) : '' ?>"><?= yx_icon($td['icon'], 16) ?></span>
                                    <span class="uc-todo-txt">
                                        <b><?= e($td['title']) ?></b>
                                        <span><?= e($td['desc']) ?></span>
                                    </span>
                                    <span class="uc-todo-btn"><?= e($td['btn']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <!-- 我的业务 -->
            <section class="uc-panel">
                <div class="uc-panel-head">
                    <h2>我的业务<em><?= $svcTotal ?> 个</em></h2>
                    <a class="uc-panel-link" href="<?= e(url('user/services.php')) ?>">全部业务 →</a>
                </div>
                <?php if (!$services): ?>
                    <div class="uc-panel-body">
                        <div class="uc-empty">
                            <p>您还没有开通任何业务</p>
                            <a class="yx-btn-primary" href="<?= e(url('products.php')) ?>">立即选购</a>
                        </div>
                    </div>
                <?php else: ?>
                    <ul class="uc-svc-list">
                        <?php foreach ($services as $s): ?>
                            <?php
                            $cls   = ['active' => 'ok', 'pending' => 'warn', 'suspended' => 'warn', 'terminated' => 'bad', 'expired' => 'bad'][$s['status']] ?? 'mute';
                            $dueTs = !empty($s['next_due_date']) ? strtotime((string)$s['next_due_date']) : 0;
                            $dueSoon = $dueTs > 0 && $dueTs < strtotime('+15 days');
                            $ident = $s['ip'] ?: ($s['hostname'] ?: ($s['sn'] ?: '未分配'));
                            ?>
                            <li class="uc-svc-item">
                                <span class="uc-svc-ico"><?= yx_icon('server', 17) ?></span>
                                <span class="uc-svc-main">
                                    <b><?= e($s['product_name'] ?: $s['name']) ?></b>
                                    <span><?= e($ident) ?></span>
                                </span>
                                <span class="uc-svc-meta">
                                    <span class="uc-svc-due<?= $dueSoon ? ' is-soon' : '' ?>">
                                        到期时间
                                        <b><?= e($s['next_due_date'] ?: '--') ?></b>
                                    </span>
                                    <span class="badge badge-<?= e($cls) ?>"><?= e(YxBusiness::serviceStatusName($s['status'])) ?></span>
                                    <a class="uc-panel-link" href="<?= e(url('user/services.php')) ?>">管理</a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <!-- 资产安全 -->
            <section class="uc-panel">
                <div class="uc-panel-head">
                    <h2>资产安全</h2>
                    <a class="uc-panel-link" href="<?= e(url('user/index.php')) ?>">重新检测 →</a>
                </div>
                <div class="uc-panel-body">
                    <div class="uc-sec">
                        <div class="uc-sec-score">
                            <div class="uc-ring" style="--p:<?= $secScore ?>"><span><?= $secScore ?><small>分</small></span></div>
                            <p>安全等级：<em class="tone-<?= e($secLevel[1]) ?>"><?= e($secLevel[0]) ?></em></p>
                        </div>
                        <div class="uc-sec-items">
                            <?php foreach ($secItems as $it): ?>
                                <?php if ($it['url'] !== ''): ?>
                                    <a class="uc-sec-item<?= $it['ok'] ? ' is-ok' : '' ?>" href="<?= e(url($it['url'])) ?>">
                                        <i><?= yx_icon($it['icon'], 15) ?></i>
                                        <span><?= e($it['label']) ?></span>
                                        <em><?= $it['ok'] ? '已设置' : '去设置' ?></em>
                                    </a>
                                <?php else: ?>
                                    <span class="uc-sec-item is-disabled">
                                        <i><?= yx_icon($it['icon'], 15) ?></i>
                                        <span><?= e($it['label']) ?></span>
                                        <em>需企业版</em>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- 右窄栏 -->
        <div class="uc-col">

            <!-- 我的授权 -->
            <section class="uc-panel">
                <div class="uc-panel-head">
                    <h2>我的授权</h2>
                    <a class="uc-panel-link" href="<?= e(url('user/licenses.php')) ?>">授权中心 →</a>
                </div>
                <div class="uc-lic">
                    <div class="uc-lic-row">
                        <span>授权状态</span>
                        <b><span class="badge badge-<?= in_array($siteBadge['class'], ['ok', 'warn', 'bad', 'info'], true) ? e($siteBadge['class']) : 'bad' ?>"><?= e($siteBadge['text']) ?></span></b>
                    </div>
                    <div class="uc-lic-row">
                        <span>授权版本</span>
                        <b><?= e(YxEdition::name($licDetail['authorized'] ? $licDetail['edition'] : 'basic')) ?></b>
                    </div>
                    <div class="uc-lic-row">
                        <span>授权域名</span>
                        <b class="mono"><?= e($licDetail['domain'] ?: '-') ?></b>
                    </div>
                    <div class="uc-lic-row">
                        <span>到期时间</span>
                        <b><?= e($licDetail['expire_at'] ?: ($licDetail['authorized'] ? '无期限' : '-')) ?></b>
                    </div>
                </div>
                <p class="uc-lic-tip">授权按域名校验，到期前请及时续费；如需绑定新域名，可在「我的授权」中提交检测。</p>
            </section>

            <!-- 快捷入口 -->
            <section class="uc-panel">
                <div class="uc-panel-head"><h2>快捷入口</h2></div>
                <div class="uc-quick">
                    <a href="<?= e(url('user/recharge.php')) ?>"><i><?= yx_icon('wallet', 16) ?></i>余额充值</a>
                    <a href="<?= e(url('user/orders.php')) ?>"><i><?= yx_icon('bill', 16) ?></i>我的订单</a>
                    <a href="<?= e(url('user/licenses.php')) ?>"><i><?= yx_icon('key', 16) ?></i>我的授权</a>
                    <a href="<?= e(url('store/index.php')) ?>"><i><?= yx_icon('store', 16) ?></i>应用商店</a>
                    <a href="<?= e(url('user/profile.php')) ?>"><i><?= yx_icon('user', 16) ?></i>资料设置</a>
                    <a href="<?= e(url('user/checkin.php')) ?>"><i><?= yx_icon('check', 16) ?></i>每日签到</a>
                </div>
            </section>

            <!-- 最近工单 -->
            <?php if (YxEdition::can('ticket')): ?>
            <section class="uc-panel">
                <div class="uc-panel-head">
                    <h2>最近工单</h2>
                    <a class="uc-panel-link" href="<?= e(url('user/tickets.php')) ?>">全部 →</a>
                </div>
                <?php if (!$tickets): ?>
                    <div class="uc-panel-body">
                        <div class="uc-empty" style="padding:26px 8px">
                            <p>暂无工单记录</p>
                            <a class="yx-btn-ghost" href="<?= e(url('user/tickets.php')) ?>">提交工单</a>
                        </div>
                    </div>
                <?php else: ?>
                    <ul class="uc-tk">
                        <?php foreach ($tickets as $t): ?>
                            <li>
                                <a href="<?= e(url('user/tickets.php?id=' . (int)$t['id'])) ?>">
                                    <span class="uc-tk-state tone-<?= e($tcClass[$t['status']] ?? 'mute') ?>"><?= e($tcStatus[$t['status']] ?? $t['status']) ?></span>
                                    <span class="uc-tk-txt"><b>#<?= e($t['sn'] ?: $t['id']) ?></b><?= e($t['subject']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="uc-tk-foot">
                    <span>进行中 <b><?= $openTickets ?></b> 条</span>
                    <a class="uc-panel-link" href="<?= e(url('user/tickets.php')) ?>">提交工单 →</a>
                </div>
            </section>
            <?php endif; ?>

            <!-- 最新公告 -->
            <?php if ($newsList): ?>
            <section class="uc-panel">
                <div class="uc-panel-head">
                    <h2>最新公告</h2>
                    <a class="uc-panel-link" href="<?= e(url('news.php')) ?>">全部 →</a>
                </div>
                <ul class="uc-news">
                    <?php foreach ($newsList as $n): ?>
                        <li>
                            <a href="<?= e(url('news_detail.php?id=' . (int)$n['id'])) ?>">
                                <b><?= e($n['title']) ?></b>
                                <span><?= e(mb_substr((string)(($n['published_at'] ?? '') ?: ($n['created_at'] ?? '')), 5, 5)) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <!-- 推广返利 -->
            <section class="uc-panel">
                <div class="uc-panel-head">
                    <h2>推广返利</h2>
                    <span class="badge badge-info">分享有礼</span>
                </div>
                <div class="uc-invite">
                    <p class="small muted" style="margin:0 0 12px">把专属链接分享给朋友，对方注册后自动绑定推广关系，在本站消费即可为你带来佣金。</p>
                    <div class="uc-invite-url">
                        <input type="text" id="yxInviteUrl" value="<?= e($inviteUrl) ?>" readonly>
                        <button type="button" class="uc-copy-btn" data-copy="#yxInviteUrl">复制链接</button>
                    </div>
                    <div class="uc-invite-qr">
                        <div id="yxInviteQr" class="uc-qr-box" data-url="<?= e($inviteUrl) ?>"></div>
                        <p>扫码直达站点</p>
                    </div>
                    <ul class="uc-invite-points">
                        <li><?= yx_icon('check', 14) ?>好友通过链接注册，自动绑定推广关系</li>
                        <li><?= yx_icon('check', 14) ?>好友下单付款后，按实付金额自动计提佣金</li>
                        <li><?= yx_icon('check', 14) ?>佣金明细与结款进度见「代理中心 → 推广计划」</li>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</div>

<script src="<?= e($qrcodeSrc) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/qrcode.min.js') ?>" defer></script>
<script>
(function () {
    // 复制推广链接
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.querySelector(btn.getAttribute('data-copy'));
            if (!el) return;
            var done = function () {
                var old = btn.textContent;
                btn.textContent = '已复制';
                setTimeout(function () { btn.textContent = old; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(el.value).then(done);
            } else {
                el.removeAttribute('readonly');
                el.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                el.setAttribute('readonly', 'readonly');
            }
        });
    });

    // 推广二维码：qrcode.min.js 存在时才渲染，避免外链依赖
    function renderQr() {
        var box = document.getElementById('yxInviteQr');
        if (!box || typeof QRCode === 'undefined') return;
        new QRCode(box, {
            text: box.getAttribute('data-url'),
            width: 124,
            height: 124,
            colorDark: '#1f2329',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }
    if (document.readyState === 'complete') { renderQr(); }
    else { window.addEventListener('load', renderQr); }
})();
</script>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>