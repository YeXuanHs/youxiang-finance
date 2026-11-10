<?php
/**
 * 优享财务系统 · 代理中心（用户端）
 *
 * 顶部下拉框切换四个子功能：
 *   apply     代理申请     —— 需先完成实名认证
 *   mine      我的代理     —— 申请记录 + 已开通代理账号
 *   api       API 管理     —— 开通下游对接、创建 AppID / AppSecret、调用日志
 *   affiliate 推广计划     —— 邀请链接、佣金统计、推广客户与佣金明细
 *
 * 兼容旧入口：?sub=apply / ?sub=mine 仍可直接访问（等同于 ?tab=）。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

// 实名状态以数据库为准（会话中的客户信息可能过期）
$fresh    = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]) ?: $customer;
$verified = yx_customer_verified($fresh);

$TABS = [
    'apply'     => '代理申请',
    'mine'      => '我的代理',
    'api'       => 'API管理',
    'affiliate' => '推广计划',
];

$tab = (string)($_GET['tab'] ?? $_GET['sub'] ?? 'apply');
if (!isset($TABS[$tab])) {
    $tab = 'apply';
}

/** 跳回当前子页（保留 tab 参数） */
$back = function (string $t) {
    return url('user/agent_center.php?tab=' . $t);
};

$res = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    switch ($action) {
        case 'apply':
            if (!$verified) {
                $res = ['ok' => false, 'msg' => '当前账号尚未完成实名认证，请先在用户中心完成认证，认证通过后再提交代理申请。'];
            } else {
                $res = YxAgentApply::submit($cid, $_POST);
            }
            $tab = 'apply';
            break;

        case 'api_enable':
            $res = YxAffiliate::enableApi($cid, !empty($_POST['agree']));
            $tab = 'api';
            break;

        case 'api_disable':
            $res = YxAffiliate::disableApi($cid);
            $tab = 'api';
            break;

        case 'api_create':
            $res = apiCreateApp($cid);
            $tab = 'api';
            break;

        case 'api_regen':
            $res = apiRegenSecret($cid, (int)($_POST['id'] ?? 0));
            $tab = 'api';
            break;

        case 'api_delete':
            $res = apiDeleteApp($cid, (int)($_POST['id'] ?? 0));
            $tab = 'api';
            break;

        default:
            $res = ['ok' => false, 'msg' => '未知操作'];
    }

    // API 相关操作统一用 flash + 重定向（密钥只显示一次，避免刷新重复提交）
    if ($res !== null && in_array($action, ['api_enable', 'api_disable', 'api_create', 'api_regen', 'api_delete'], true)) {
        flash((string)$res['msg'], !empty($res['ok']) ? 'success' : 'error');
        redirect($back($tab));
    }
}

/* ------------------------------------------------------------------ API 管理动作 */

/** 创建接口应用（与 user/api.php 同一数据表，最多 5 个） */
function apiCreateApp(int $cid): array
{
    if (!YxEdition::can('openapi')) {
        return ['ok' => false, 'msg' => '当前站点版本未开放 API 对接，请联系平台升级'];
    }
    if (!YxAffiliate::apiOn($cid)) {
        return ['ok' => false, 'msg' => '请先开启 API 对接功能'];
    }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'msg' => '请填写应用名称'];
    }
    $cnt = (int)YxDb::value(
        'SELECT COUNT(*) FROM ' . YxDb::table('api_app') . " WHERE owner_type = 'customer' AND owner_id = ?",
        [$cid]
    );
    if ($cnt >= 5) {
        return ['ok' => false, 'msg' => '最多创建 5 个应用，请先删除不用的'];
    }
    $appid  = 'yx' . date('ymd') . strtolower(substr(md5(random_nonce(8) . microtime()), 0, 10));
    $secret = random_nonce(32);
    YxDb::insert('api_app', [
        'name'       => mb_substr($name, 0, 60),
        'appid'      => $appid,
        'secret'     => yx_encrypt($secret),
        'owner_type' => 'customer',
        'owner_id'   => $cid,
        'rate_limit' => 120,
        'status'     => 1,
        'created_at' => now(),
    ]);
    log_action('api_app.self_create', $appid, ['customer' => $cid, 'from' => 'agent_center']);
    return ['ok' => true, 'msg' => '创建成功！AppID：' . $appid . '　AppSecret：' . $secret . '（密钥仅此一次完整显示，请立即保存）'];
}

/** 重置密钥 */
function apiRegenSecret(int $cid, int $id): array
{
    $app = YxDb::one(
        'SELECT * FROM ' . YxDb::table('api_app') . " WHERE id = ? AND owner_type = 'customer' AND owner_id = ?",
        [$id, $cid]
    );
    if (!$app) {
        return ['ok' => false, 'msg' => '应用不存在'];
    }
    $secret = random_nonce(32);
    YxDb::update('api_app', ['secret' => yx_encrypt($secret)], 'id = :id', ['id' => (int)$app['id']]);
    log_action('api_app.self_regen', $app['appid'], ['customer' => $cid, 'from' => 'agent_center']);
    return ['ok' => true, 'msg' => '密钥已重置，新 AppSecret：' . $secret . '（旧密钥立即失效）'];
}

/** 删除应用 */
function apiDeleteApp(int $cid, int $id): array
{
    $n = YxDb::delete(
        'api_app',
        "id = :id AND owner_type = 'customer' AND owner_id = :cid",
        ['id' => $id, 'cid' => $cid]
    );
    if ($n < 1) {
        return ['ok' => false, 'msg' => '应用不存在或已删除'];
    }
    log_action('api_app.self_delete', 'api_app#' . $id, ['customer' => $cid, 'from' => 'agent_center']);
    return ['ok' => true, 'msg' => '应用已删除'];
}

/* ------------------------------------------------------------------ 视图数据 */

$latest   = YxAgentApply::latest($cid);
$myRows   = YxAgentApply::rows($cid);
$agent    = YxAgentApply::agentOf($cid);
$canApply = $verified && !YxAgentApply::isApproved($cid) && !YxAgentApply::hasPending($cid);

$current = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]) ?: $customer;

// API 管理
$apiEditionOk = YxEdition::can('openapi');
$apiOn        = YxAffiliate::apiOn($cid);
$apiAgreeAt   = YxAffiliate::apiAgreeAt($cid);
$apiApps      = $apiOn ? YxAffiliate::apiApps($cid) : [];
$apiLogs      = $apiOn ? YxAffiliate::apiLogs($cid, 15) : [];
$apiEndpoint  = url('api/v1.php');

// 推广计划
$affEnabled  = YxAffiliate::enabled();
$affRate     = YxAffiliate::rate();
$affCode     = YxAffiliate::code($current);
$affUrl      = YxAffiliate::inviteUrl($current);
$affStats    = YxAffiliate::stats($cid);
$affPage     = max(1, (int)($_GET['page'] ?? 1));
$affPer      = 15;
$affComm     = YxAffiliate::commissions($cid, $affPage, $affPer);
$affPager    = paginate((int)$affComm['total'], $affPer);
$affRefs     = YxAffiliate::referrals($cid, 50);
$affMinPay   = YxAffiliate::minPay();

$affRatePct  = rtrim(rtrim(number_format($affRate * 100, 2, '.', ''), '0'), '.');

$userTitle  = '代理中心';
$userActive = 'agent_center';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if ($res): ?>
    <div class="card" style="border-left:4px solid <?= $res['ok'] ? '#2e9e5b' : '#d9534f' ?>;margin-bottom:14px">
        <p style="margin:0"><?= e($res['msg']) ?></p>
    </div>
<?php endif; ?>

<div class="uc-panel" style="margin-bottom:16px">
    <div class="uc-panel-body" style="padding:16px 20px">
        <form method="get" action="<?= e(url('user/agent_center.php')) ?>" class="ac-switch">
            <label for="acTab">代理中心</label>
            <select name="tab" id="acTab" onchange="this.form.submit()">
                <?php foreach ($TABS as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $tab === $k ? 'selected' : '' ?>>
                        <?= e($v) ?><?= $k === 'mine' && $myRows ? '（' . count($myRows) . '）' : '' ?><?= $k === 'api' && $apiOn ? '（已开启）' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-primary" type="submit">切换</button></noscript>
            <span class="ac-switch-desc">切换查看代理申请、代理账号、API 对接与推广收益</span>
        </form>
    </div>
</div>

<?php /* ============================ 代理申请 ============================ */ ?>
<?php if ($tab === 'apply'): ?>

    <?php if (!$verified): ?>
        <div class="card" style="border-left:4px solid #f0ad4e">
            <h2>需先完成实名认证</h2>
            <p>当前账号尚未完成实名认证，请先在用户中心完成认证，认证通过后再进行优享财务系统平台注册。</p>
            <p class="muted small">实名认证状态：<?= e(YxRealname::statusName((string)($fresh['realname_status'] ?? 'none'))) ?></p>
            <a class="btn btn-primary" href="<?= e(url('user/realname.php')) ?>">去实名认证</a>
        </div>
    <?php endif; ?>

    <?php if ($agent): ?>
        <div class="card" style="margin-bottom:14px">
            <h2>代理账号已开通</h2>
            <p class="muted small">你的代理账号：<b><?= e($agent['username']) ?></b>，专属域名：<?= e($agent['domain'] ?: '—') ?>，佣金比例：<?= e(YxAffiliate::rateText((float)$agent['commission_rate'] / 100)) ?></p>
            <a class="btn" href="<?= e(url('agent/login.php')) ?>">进入代理中心</a>
        </div>
    <?php endif; ?>

    <?php if ($latest && (string)$latest['status'] !== 'rejected'): ?>
        <div class="card">
            <h2>申请状态</h2>
            <p>
                最近一次申请：<?= e(YxAgentApply::typeName((string)$latest['apply_type'])) ?> ·
                <?= e($latest['subject_name']) ?>
                <span class="badge <?= e(YxAgentApply::statusClass((string)$latest['status'])) ?>"><?= e(YxAgentApply::statusName((string)$latest['status'])) ?></span>
            </p>
            <p class="muted small">提交时间：<?= e($latest['created_at'] ?: '—') ?>
                <?php if ($latest['reviewed_at']): ?> · 审核时间：<?= e($latest['reviewed_at']) ?><?php endif; ?>
            </p>
            <?php if ((string)$latest['status'] === 'approved'): ?>
                <p>审核已通过，请等待管理员为你开通代理账号（或直接查看「我的代理」）。</p>
            <?php else: ?>
                <p class="muted small">管理员审核通过后会在此显示结果，请耐心等待。</p>
            <?php endif; ?>
            <?php if ($latest['admin_remark']): ?>
                <p class="muted small">审核备注：<?= e($latest['admin_remark']) ?></p>
            <?php endif; ?>
        </div>
    <?php elseif ($verified): ?>
        <div class="card">
            <h2>代理申请</h2>
            <p class="muted small" style="margin:0 0 14px">
                提交后由管理员审核，通过后为你开通独立代理账号与专属域名。企业代理需填写统一社会信用代码。
                <?php if ($latest && (string)$latest['status'] === 'rejected'): ?>
                    <br><span style="color:#d9534f">上次申请已被驳回<?= $latest['admin_remark'] ? '：' . e($latest['admin_remark']) : '' ?>，可修改后重新提交。</span>
                <?php endif; ?>
            </p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply">
                <div class="grid-2">
                    <label>申请类型
                        <select name="apply_type">
                            <?php foreach (YxAgentApply::APPLY_TYPES as $k => $v): ?>
                                <option value="<?= e($k) ?>"><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>申请人 / 企业名称 <input type="text" name="subject_name" required maxlength="120"
                        value="<?= e($fresh['realname'] ?: ($fresh['username'] ?? '')) ?>"></label>
                    <label>联系人 <input type="text" name="contact_name" maxlength="60" value="<?= e($fresh['realname'] ?: '') ?>"></label>
                    <label>手机号 <input type="text" name="phone" maxlength="30" value="<?= e($fresh['phone'] ?: '') ?>"></label>
                    <label>邮箱 <input type="email" name="email" maxlength="120" value="<?= e($fresh['email'] ?: '') ?>"></label>
                    <label>企业名称（企业代理） <input type="text" name="company" maxlength="160" value="<?= e($fresh['company'] ?: '') ?>"></label>
                    <label>统一社会信用代码（企业代理必填） <input type="text" name="license_no" maxlength="64"
                        value="<?= e($fresh['license_no'] ?: '') ?>"></label>
                    <label>拟绑定代理域名 <input type="text" name="domain" maxlength="120" placeholder="agent.example.com"></label>
                    <label>推广渠道说明 <input type="text" name="channel" maxlength="120" placeholder="如：站长论坛 / 客户转介绍"></label>
                    <label>预估月销售额（元） <input type="number" name="expect_sales" step="0.01" min="0" value="0"></label>
                </div>
                <label style="display:block;margin-top:10px">补充说明
                    <textarea name="remark" rows="3" placeholder="其他希望我们了解的信息"></textarea>
                </label>
                <div style="margin-top:12px">
                    <button class="btn btn-primary" type="submit">提交申请</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php /* ============================ 我的代理 ============================ */ ?>
<?php if ($tab === 'mine'): ?>

    <div class="card" style="margin-bottom:14px">
        <h2>我的代理申请（<?= count($myRows) ?>）</h2>
        <?php if (!$myRows): ?>
            <div class="empty">还没有提交过代理申请，<a href="<?= e($back('apply')) ?>">去申请</a></div>
        <?php else: ?>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>编号</th><th>类型</th><th>名称</th><th>状态</th><th>提交时间</th><th>审核备注</th></tr></thead>
                <tbody>
                <?php foreach ($myRows as $r): ?>
                    <tr>
                        <td class="mono small">#<?= (int)$r['id'] ?></td>
                        <td><?= e(YxAgentApply::typeName((string)$r['apply_type'])) ?></td>
                        <td class="wrap"><?= e($r['subject_name']) ?></td>
                        <td><span class="badge <?= e(YxAgentApply::statusClass((string)$r['status'])) ?>"><?= e(YxAgentApply::statusName((string)$r['status'])) ?></span></td>
                        <td class="small"><?= e($r['created_at'] ?: '—') ?></td>
                        <td class="small"><?= e($r['admin_remark'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>代理账号</h2>
        <?php if ($agent): ?>
            <div class="table-wrap"><table class="data">
                <tr><th>代理账号</th><td class="mono"><?= e($agent['username']) ?></td></tr>
                <tr><th>专属域名</th><td class="mono"><?= e($agent['domain'] ?: '—') ?></td></tr>
                <tr><th>佣金比例</th><td><?= e(YxAffiliate::rateText((float)$agent['commission_rate'] / 100)) ?></td></tr>
                <tr><th>开通时间</th><td class="small"><?= e($agent['created_at'] ?: '—') ?></td></tr>
                <tr><th>状态</th><td><span class="badge <?= (int)$agent['status'] === 1 ? 'badge-ok' : 'badge-mute' ?>"><?= (int)$agent['status'] === 1 ? '正常' : '停用' ?></span></td></tr>
            </table></div>
            <div class="row-actions" style="margin-top:12px">
                <a class="btn btn-primary btn-sm" href="<?= e(url('agent/login.php')) ?>">进入代理中心</a>
            </div>
        <?php else: ?>
            <div class="empty">
                尚未开通代理账号。
                <?php if (!$myRows): ?>
                    可先<a href="<?= e($back('apply')) ?>">提交代理申请</a>。
                <?php elseif (YxAgentApply::hasPending($cid)): ?>
                    申请书正在审核中，请耐心等待。
                <?php elseif (YxAgentApply::isApproved($cid)): ?>
                    申请已通过，管理员正在为你开通账号。
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php /* ============================ API 管理 ============================ */ ?>
<?php if ($tab === 'api'): ?>

    <?php if (!$apiEditionOk): ?>
        <div class="card">
            <h2>API 对接</h2>
            <p class="alert alert-warn">API 对接属于「企业版」能力，当前站点版本为 <strong><?= e(YxEdition::name()) ?></strong>，暂不可用。</p>
        </div>
    <?php elseif (!$apiOn): ?>
        <div class="card ac-api-intro">
            <h2>API 管理</h2>
            <div class="ac-api-note">
                <p class="ac-api-empty">您还暂未开启API对接功能，需要开启此功能，才能进行上下游对接</p>
                <ul class="ac-api-facts">
                    <li><?= yx_icon('check', 14) ?>开启后可创建 AppID / AppSecret，通过 HTTPS 接口下单、开通、续费与查询业务</li>
                    <li><?= yx_icon('check', 14) ?>可对接自己的上游供货商，在本站完成「上游进货 → 本站销售 → 下游分销」</li>
                    <li><?= yx_icon('check', 14) ?>支持事件回调地址与 IP 白名单，密钥可随时重置</li>
                </ul>
            </div>
            <form method="post" class="ac-api-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="api_enable">
                <label class="checkbox ac-api-agree">
                    <input type="checkbox" name="agree" value="1" required>
                    <span>我已阅读并同意<a href="#acApiAgree" id="acApiAgreeLink">《优享财务系统协议》</a></span>
                </label>
                <button class="btn btn-primary" type="submit">立即开启</button>
                <p class="muted small" style="margin:10px 0 0">点击「立即开启」即表示你已阅读并同意《优享财务系统协议》，开启后功能框将在本页展示。</p>
            </form>

            <div class="ac-modal" id="acApiAgree" hidden>
                <div class="ac-modal-mask" data-close></div>
                <div class="ac-modal-box" role="dialog" aria-modal="true" aria-labelledby="acAgreeTitle">
                    <div class="ac-modal-head">
                        <h3 id="acAgreeTitle">优享财务系统协议</h3>
                        <button type="button" class="ac-modal-x" data-close aria-label="关闭">×</button>
                    </div>
                    <div class="ac-modal-body">
                        <p>为保障接口对接的稳定性与数据安全，开启 API 对接功能前请确认以下条款：</p>
                        <ol>
                            <li>你应妥善保管 AppID 与 AppSecret，因密钥泄露造成的损失由账号所有人承担。</li>
                            <li>通过接口提交的订单、开通与续费请求，视为你账号的正式业务操作，产生相应费用。</li>
                            <li>不得利用接口进行任何违反法律法规、侵害第三方权益或扰乱平台运营的行为。</li>
                            <li>平台有权对异常调用进行限流、停用应用或关闭对接功能，并保留追究责任的权利。</li>
                            <li>接口调用日志会保留一段时间用于对账与安全审计。</li>
                        </ol>
                        <p class="muted small">继续使用本功能即表示你已阅读、理解并接受上述条款。</p>
                    </div>
                    <div class="ac-modal-foot">
                        <button type="button" class="btn btn-ghost btn-sm" data-close>我已阅读</button>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="uc-metrics" style="margin-bottom:16px">
            <div class="uc-metric">
                <div class="uc-metric-ico"><?= yx_icon('key', 20) ?></div>
                <div class="uc-metric-txt"><p>接口应用</p><b><?= count($apiApps) ?><em>个</em></b></div>
            </div>
            <div class="uc-metric">
                <div class="uc-metric-ico tone-ok"><?= yx_icon('check', 20) ?></div>
                <div class="uc-metric-txt"><p>对接状态</p><b style="font-size:16px">已开启</b></div>
            </div>
            <div class="uc-metric">
                <div class="uc-metric-ico tone-warn"><?= yx_icon('shield', 20) ?></div>
                <div class="uc-metric-txt"><p>协议同意时间</p><b style="font-size:14px"><?= e($apiAgreeAt ?: '—') ?></b></div>
            </div>
            <div class="uc-metric">
                <div class="uc-metric-ico"><?= yx_icon('server', 20) ?></div>
                <div class="uc-metric-txt"><p>累计调用</p><b><?= (int)array_sum(array_map(function ($a) { return (int)$a['call_count']; }, $apiApps)) ?><em>次</em></b></div>
            </div>
        </div>

        <div class="grid-2" style="margin-bottom:16px">
            <div class="card">
                <h2>创建应用</h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="api_create">
                    <label>应用名称 <input type="text" name="name" required maxlength="60" placeholder="如：我的分销系统"></label>
                    <button class="btn btn-primary" type="submit">创建并生成密钥</button>
                </form>
                <p class="muted small" style="margin-top:10px">AppSecret 仅在创建或重置时完整显示一次，请立即保存。最多创建 5 个应用。</p>
            </div>
            <div class="card">
                <h2>对接信息</h2>
                <table class="data">
                    <tr><th>接口地址</th><td class="mono"><?= e($apiEndpoint) ?></td></tr>
                    <tr><th>鉴权参数</th><td class="mono small">appid、action、ts、nonce、sign</td></tr>
                    <tr><th>签名算法</th><td class="small">过滤空值后按 key 升序拼接 <code>k=v&amp;k=v</code>，用 AppSecret 做 HMAC-SHA256</td></tr>
                    <tr><th>时间容差</th><td class="small">±<?= (int)YxApi::TS_WINDOW ?> 秒，nonce 一次性</td></tr>
                </table>
                <form method="post" style="margin-top:12px" onsubmit="return confirm('关闭后原有应用密钥将全部停用，确认关闭 API 对接？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="api_disable">
                    <button class="btn btn-sm btn-mute" type="submit">关闭 API 对接</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-bottom:16px">
            <h2>我的应用（<?= count($apiApps) ?>）</h2>
            <?php if (!$apiApps): ?>
                <div class="empty">还没有接口应用，请在上方创建</div>
            <?php else: ?>
                <div class="table-wrap"><table class="data">
                    <thead><tr><th>应用名称</th><th>AppID</th><th>状态</th><th>调用次数</th><th>最近调用</th><th>创建时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($apiApps as $a): ?>
                        <tr>
                            <td><?= e($a['name']) ?></td>
                            <td class="mono small"><?= e($a['appid']) ?></td>
                            <td><span class="badge <?= (int)$a['status'] === 1 ? 'badge-ok' : 'badge-mute' ?>"><?= (int)$a['status'] === 1 ? '启用' : '停用' ?></span></td>
                            <td class="small"><?= (int)$a['call_count'] ?> 次</td>
                            <td class="small"><?= e($a['last_call_at'] ?: '—') ?></td>
                            <td class="small"><?= e($a['created_at'] ?: '—') ?></td>
                            <td>
                                <div class="row-actions">
                                    <form method="post" onsubmit="return confirm('重置后旧密钥立即失效，确认？')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="api_regen">
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                        <button class="btn btn-sm btn-warn" type="submit">重置密钥</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('确认删除该应用？删除后该 AppID 立即失效。')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="api_delete">
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                        <button class="btn btn-sm btn-bad" type="submit">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>最近调用记录</h2>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>时间</th><th>AppID</th><th>动作</th><th>结果</th><th>消息</th><th>耗时</th></tr></thead>
                <tbody>
                <?php foreach ($apiLogs as $l): ?>
                    <tr>
                        <td class="small muted"><?= e($l['created_at']) ?></td>
                        <td class="mono small"><?= e($l['appid']) ?></td>
                        <td class="mono small"><?= e($l['action']) ?></td>
                        <td><span class="badge <?= (int)$l['code'] === 0 ? 'badge-ok' : 'badge-bad' ?>"><?= (int)$l['code'] ?></span></td>
                        <td class="wrap small"><?= e($l['msg']) ?></td>
                        <td class="small"><?= (int)$l['cost_ms'] ?>ms</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$apiLogs): ?><tr><td colspan="6" class="empty">暂无调用记录</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php /* ============================ 推广计划 ============================ */ ?>
<?php if ($tab === 'affiliate'): ?>

    <?php if (!$affEnabled): ?>
        <div class="card">
            <h2>推广计划</h2>
            <p class="alert alert-warn">推广计划当前未开放，如有合作意向请联系客服。</p>
        </div>
    <?php else: ?>

        <div class="uc-metrics" style="margin-bottom:16px">
            <div class="uc-metric">
                <div class="uc-metric-ico"><?= yx_icon('wallet', 20) ?></div>
                <div class="uc-metric-txt"><p>累计佣金</p><b>¥<?= e(money($affStats['earned'])) ?></b></div>
            </div>
            <div class="uc-metric">
                <div class="uc-metric-ico tone-warn"><?= yx_icon('bill', 20) ?></div>
                <div class="uc-metric-txt"><p>待结算</p><b>¥<?= e(money($affStats['pending'])) ?></b></div>
            </div>
            <div class="uc-metric">
                <div class="uc-metric-ico tone-ok"><?= yx_icon('check', 20) ?></div>
                <div class="uc-metric-txt"><p>已结算</p><b>¥<?= e(money($affStats['settled'])) ?></b></div>
            </div>
            <div class="uc-metric">
                <div class="uc-metric-ico"><?= yx_icon('user', 20) ?></div>
                <div class="uc-metric-txt"><p>本月佣金</p><b>¥<?= e(money($affStats['month'])) ?></b></div>
            </div>
        </div>

        <div class="uc-grid" style="margin-bottom:16px">
            <div class="uc-col">
                <section class="uc-panel">
                    <div class="uc-panel-head">
                        <h2>我的推广链接 <em>每笔订单佣金 <?= e($affRatePct) ?>%</em></h2>
                        <span class="badge badge-info"><?= e($affRatePct) ?> 佣金</span>
                    </div>
                    <div class="uc-invite">
                        <div class="uc-invite-url">
                            <input type="text" id="acAffUrl" value="<?= e($affUrl) ?>" readonly>
                            <button type="button" class="uc-copy-btn" data-copy="#acAffUrl">复制链接</button>
                        </div>
                        <div class="uc-invite-qr">
                            <div id="acAffQr" class="uc-qr-box" data-url="<?= e($affUrl) ?>"></div>
                            <p>扫码直达注册页</p>
                        </div>
                        <ul class="uc-invite-points">
                            <li><?= yx_icon('check', 14) ?>好友通过你的链接注册后，其账户永久归属你的推广关系</li>
                            <li><?= yx_icon('check', 14) ?>好友每笔支付成功的订单按实付金额 <?= e($affRatePct) ?>% 计提佣金</li>
                            <li><?= yx_icon('check', 14) ?>待结算佣金满 ¥<?= e(money($affMinPay)) ?> 后可联系客服申请结款，结款登记后进入「已结算」</li>
                            <li><?= yx_icon('check', 14) ?>自己下单不计佣金，已退款订单不重复计提</li>
                        </ul>
                    </div>
                </section>

                <section class="uc-panel">
                    <div class="uc-panel-head">
                        <h2>佣金明细 <em>共 <?= (int)$affComm['total'] ?> 笔</em></h2>
                    </div>
                    <div class="uc-panel-body tight">
                        <div class="table-wrap"><table class="data">
                            <thead><tr><th>订单号</th><th>订单内容</th><th>实付</th><th>比例</th><th>佣金</th><th>支付时间</th><th>状态</th></tr></thead>
                            <tbody>
                            <?php foreach ($affComm['items'] as $c): ?>
                                <tr>
                                    <td class="mono small"><?= e($c['sn']) ?></td>
                                    <td class="wrap small"><?= e($c['name'] ?: '—') ?></td>
                                    <td>¥<?= e(money($c['total'])) ?></td>
                                    <td class="small"><?= e(YxAffiliate::rateText((float)$c['affiliate_rate'])) ?></td>
                                    <td><b>¥<?= e(money($c['affiliate_commission'])) ?></b></td>
                                    <td class="small"><?= e($c['paid_at'] ?: '—') ?></td>
                                    <td><span class="badge <?= $c['affiliate_settled_at'] ? 'badge-ok' : 'badge-warn' ?>"><?= $c['affiliate_settled_at'] ? '已结算' : '待结算' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$affComm['items']): ?>
                                <tr><td colspan="7" class="empty">还没有佣金记录，把推广链接分享给好友即可开始</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table></div>
                    </div>
                    <?php if ($affPager['pages'] > 1): ?>
                        <div class="uc-panel-body" style="padding-top:12px"><?= pager($affPager) ?></div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="uc-col">
                <section class="uc-panel">
                    <div class="uc-panel-head"><h2>推广概况</h2></div>
                    <div class="uc-panel-body">
                        <table class="data">
                            <tr><th>推广码</th><td class="mono"><?= e($affCode) ?></td></tr>
                            <tr><th>推广客户</th><td><b><?= (int)$affStats['referrals'] ?></b> 人</td></tr>
                            <tr><th>成交订单</th><td><b><?= (int)$affStats['orders'] ?></b> 笔</td></tr>
                            <tr><th>累计成交额</th><td>¥<?= e(money($affStats['gmv'])) ?></td></tr>
                            <tr><th>佣金比例</th><td><?= e($affRatePct) ?>%</td></tr>
                            <tr><th>结款门槛</th><td>¥<?= e(money($affMinPay)) ?></td></tr>
                        </table>
                        <p class="muted small" style="margin:12px 0 0">
                            待结算 ¥<?= e(money($affStats['pending'])) ?>，
                            <?= $affStats['pending'] >= $affMinPay && $affMinPay > 0 ? '已达结款门槛，可联系客服申请结款。' : '满 ¥' . e(money($affMinPay)) . ' 可申请结款。' ?>
                        </p>
                    </div>
                </section>

                <section class="uc-panel">
                    <div class="uc-panel-head">
                        <h2>推广客户 <em><?= (int)$affStats['referrals'] ?> 人</em></h2>
                    </div>
                    <div class="uc-panel-body tight">
                        <ul class="uc-tk">
                            <?php foreach ($affRefs as $r): ?>
                                <li>
                                    <div style="display:flex;align-items:center;gap:9px;padding:11px 20px">
                                        <span class="uc-tk-txt" style="flex:1">
                                            <b>#<?= (int)$r['user_no'] ?></b><?= e($r['username']) ?>
                                        </span>
                                        <span class="uc-tk-state <?= (string)$r['realname_status'] === 'verified' ? 'tone-info' : '' ?>"><?= e(YxRealname::statusName((string)$r['realname_status'])) ?></span>
                                        <span class="small muted"><?= e(substr((string)$r['created_at'], 0, 10)) ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$affRefs): ?>
                                <li><div class="empty">还没有推广客户</div></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php if (count($affRefs) >= 50): ?>
                        <div class="uc-tk-foot"><span>仅显示最近 50 位推广客户</span></div>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <script src="<?= e(url('assets/js/qrcode.min.js')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/qrcode.min.js') ?>" defer></script>
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

            // 推广二维码
            function renderQr() {
                var box = document.getElementById('acAffQr');
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

    <?php endif; ?>

<?php endif; ?>

<?php /* API 协议弹窗脚本：仅在未开启的 API 面板渲染 */ ?>
<?php if ($tab === 'api' && $apiEditionOk && !$apiOn): ?>
<script>
(function () {
    var modal = document.getElementById('acApiAgree');
    var link  = document.getElementById('acApiAgreeLink');
    if (!modal) return;
    function open(e) {
        if (e) { e.preventDefault(); }
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }
    if (link) { link.addEventListener('click', open); }
    modal.querySelectorAll('[data-close]').forEach(function (el) {
        el.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { close(); }
    });

    // 勾选协议后按钮才可点击，避免空提交
    var form = document.querySelector('.ac-api-form');
    if (!form) return;
    var box = form.querySelector('input[name="agree"]');
    var btn = form.querySelector('button[type="submit"]');
    function sync() { if (btn) { btn.disabled = !(box && box.checked); } }
    if (box) { box.addEventListener('change', sync); }
    sync();
})();
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
