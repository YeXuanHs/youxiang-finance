<?php
/**
 * 优享财务系统 · 开发者中心（后台管理）
 *
 * 入驻审核 / 实名记录 / 保证金流水 / 商品审核 / 分成结算 / 相关设置
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxAuth::requireAdminPermission('developer');

$tab = (string)($_GET['tab'] ?? 'devs');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $back   = 'devs';

    if ($action === 'approve_dev') {
        $res  = YxDeveloper::approve((int)($_POST['id'] ?? 0));
        $back = 'devs';
    } elseif ($action === 'reject_dev') {
        $res  = YxDeveloper::reject((int)($_POST['id'] ?? 0), trim((string)($_POST['reason'] ?? '')));
        $back = 'devs';
    } elseif ($action === 'deposit_confirm') {
        $dep = YxDb::one('SELECT * FROM ' . YxDb::table('developer_deposit') . ' WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if (!$dep) {
            $res = ['ok' => false, 'msg' => '保证金单据不存在'];
        } elseif ($dep['status'] === 'paid') {
            $res = ['ok' => false, 'msg' => '该单据已缴纳'];
        } else {
            $res = YxBusiness::payOrderExternal((int)$dep['order_id'], 'manual');
        }
        $back = 'deposits';
    } elseif ($action === 'app_review') {
        $id   = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['pass'] ?? '') === '1';
        $app  = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ? AND developer_id > 0', [$id]);
        if (!$app) {
            $res = ['ok' => false, 'msg' => '商品不存在'];
        } elseif ($pass) {
            YxDb::update('app', ['status' => 'on', 'review_note' => null, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '商品已审核通过并上架'];
        } else {
            YxDb::update('app', ['status' => 'rejected', 'review_note' => trim((string)($_POST['reason'] ?? '')), 'updated_at' => now()], 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '商品已驳回'];
        }
        $back = 'apps';
    } elseif ($action === 'app_toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $app = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ? AND developer_id > 0', [$id]);
        if (!$app) {
            $res = ['ok' => false, 'msg' => '商品不存在'];
        } else {
            YxDb::update('app', ['status' => $app['status'] === 'on' ? 'off' : 'on', 'updated_at' => now()], 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '状态已切换'];
        }
        $back = 'apps';
    } elseif ($action === 'save_dev_contact') {
        $id  = (int)($_POST['id'] ?? 0);
        $dev = YxDb::one('SELECT * FROM ' . YxDb::table('developer') . ' WHERE id = ?', [$id]);
        if (!$dev) {
            $res = ['ok' => false, 'msg' => '开发者不存在'];
        } else {
            $website = trim((string)($_POST['website'] ?? ''));
            if ($website !== '' && !preg_match('#^https?://#i', $website)) {
                $website = 'https://' . $website;
            }
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res = ['ok' => false, 'msg' => '电子邮箱格式不正确'];
            } else {
                YxDb::update('developer', [
                    'website'    => mb_substr($website, 0, 160),
                    'email'      => mb_substr($email, 0, 120),
                    'qq'         => mb_substr(trim((string)($_POST['qq'] ?? '')), 0, 30),
                    'wechat'     => mb_substr(trim((string)($_POST['wechat'] ?? '')), 0, 60),
                    'intro'      => mb_substr(trim((string)($_POST['intro'] ?? '')), 0, 255),
                    'updated_at' => now(),
                ], 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '开发者联系资料已更新'];
            }
        }
        $back = 'devs';
    } elseif ($action === 'settle') {
        YxDb::update('developer_settlement', ['status' => 'settled', 'settled_at' => now()], 'id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        $res  = ['ok' => true, 'msg' => '已标记结算'];
        $back = 'settlements';
    } elseif ($action === 'save_settings') {
        set_setting('developer_deposit_amount', (string)round_money($_POST['developer_deposit_amount'] ?? 600));
        set_setting('developer_package_max_mb', (string)max(1, (int)($_POST['developer_package_max_mb'] ?? 50)));
        set_setting('developer_auto_approve', !empty($_POST['developer_auto_approve']) ? '1' : '0');
        $provider = (string)($_POST['realname_provider'] ?? 'auto');
        set_setting('realname_provider', array_key_exists($provider, YxRealname::drivers()) ? $provider : 'auto');
        set_setting('realname_endpoint', trim((string)($_POST['realname_endpoint'] ?? '')));
        set_setting('realname_appid', trim((string)($_POST['realname_appid'] ?? '')));
        // 密钥留空表示不修改，避免回显与误覆盖
        $secret = (string)($_POST['realname_secret'] ?? '');
        if ($secret !== '') {
            set_setting('realname_secret', $secret);
        }
        $res  = ['ok' => true, 'msg' => '设置已保存'];
        $back = 'settings';
    }

    log_action('developer.admin.' . $action, '', ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/developers.php?tab=' . urlencode($back)));
}

/* ---------------- 数据 ---------------- */
$devs = YxDb::all(
    'SELECT d.*, c.username FROM ' . YxDb::table('developer') . ' d
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = d.customer_id
     ORDER BY d.id DESC LIMIT 200'
);
$appStatusF = (string)($_GET['astatus'] ?? '');
$contactId  = (int)($_GET['contact'] ?? 0);
$appWhere   = ' WHERE a.developer_id > 0';
$appBind    = [];
if ($appStatusF !== '' && isset(YxDeveloper::APP_STATUS[$appStatusF])) {
    $appWhere .= ' AND a.status = ?';
    $appBind[] = $appStatusF;
}
$devApps = YxDb::all(
    'SELECT a.*, d.customer_id FROM ' . YxDb::table('app') . ' a
     LEFT JOIN ' . YxDb::table('developer') . ' d ON d.id = a.developer_id' . $appWhere . '
     ORDER BY (a.status = \'pending\') DESC, a.id DESC LIMIT 300',
    $appBind
);
$deposits = YxDb::all(
    'SELECT dep.*, c.username FROM ' . YxDb::table('developer_deposit') . ' dep
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = dep.customer_id
     ORDER BY dep.id DESC LIMIT 200'
);
$realnames = YxDb::all('SELECT * FROM ' . YxDb::table('developer_realname') . ' ORDER BY id DESC LIMIT 200');
$settlements = YxDb::all(
    'SELECT s.*, d.company, d.real_name, c.username FROM ' . YxDb::table('developer_settlement') . ' s
     LEFT JOIN ' . YxDb::table('developer') . ' d ON d.id = s.developer_id
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = d.customer_id
     ORDER BY s.id DESC LIMIT 200'
);
$pendingApps = (int)YxDb::value("SELECT COUNT(*) FROM " . YxDb::table('app') . " WHERE developer_id > 0 AND status = 'pending'");
$pendingDevs = (int)YxDb::value("SELECT COUNT(*) FROM " . YxDb::table('developer') . " WHERE status IN ('pending_review','applying')");

$rnStatusCls = ['verified' => 'badge-ok', 'pending' => 'badge-warn', 'failed' => 'badge-bad', 'expired' => 'badge-mute'];

$pageTitle  = '开发者中心';
$activeMenu = 'developers';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="<?= $tab === 'devs' ? 'active' : '' ?>" href="<?= e(url('admin/developers.php?tab=devs')) ?>">入驻审核（<?= $pendingDevs ?> 待处理）</a>
    <a class="<?= $tab === 'apps' ? 'active' : '' ?>" href="<?= e(url('admin/developers.php?tab=apps')) ?>">商品审核（<?= $pendingApps ?> 待审）</a>
    <a class="<?= $tab === 'deposits' ? 'active' : '' ?>" href="<?= e(url('admin/developers.php?tab=deposits')) ?>">保证金流水</a>
    <a class="<?= $tab === 'realnames' ? 'active' : '' ?>" href="<?= e(url('admin/developers.php?tab=realnames')) ?>">实名记录</a>
    <a class="<?= $tab === 'settlements' ? 'active' : '' ?>" href="<?= e(url('admin/developers.php?tab=settlements')) ?>">分成结算</a>
    <a class="<?= $tab === 'settings' ? 'active' : '' ?>" href="<?= e(url('admin/developers.php?tab=settings')) ?>">设置</a>
</div>

<?php if ($tab === 'apps'): ?>
    <div class="card">
        <div class="section-head">
            <h2>开发者商品（<?= count($devApps) ?>）</h2>
            <form method="get" class="inline-form">
                <input type="hidden" name="tab" value="apps">
                <label>状态
                    <select name="astatus">
                        <option value="">全部</option>
                        <?php foreach (YxDeveloper::APP_STATUS as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= $appStatusF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit">筛选</button>
            </form>
        </div>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>商品</th><th>类型</th><th>价格</th><th>版本</th><th>状态</th><th>销量</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($devApps as $a): ?>
                <tr>
                    <td class="wrap"><?= e($a['name']) ?><br><span class="muted small mono"><?= e($a['key']) ?></span></td>
                    <td><?= e(YxDeveloper::typeName((string)$a['type'])) ?></td>
                    <td><?= (float)$a['price'] > 0 ? e(money($a['price'], true)) : '免费' ?></td>
                    <td><?= e($a['version']) ?></td>
                    <td>
                        <?php $cls = ['on' => 'badge-ok', 'pending' => 'badge-warn', 'rejected' => 'badge-bad', 'off' => 'badge-mute'][$a['status']] ?? 'badge-mute'; ?>
                        <span class="badge <?= $cls ?>"><?= e(YxDeveloper::appStatusName((string)$a['status'])) ?></span>
                    </td>
                    <td><?= (int)$a['sales'] ?></td>
                    <td>
                        <div class="row-actions">
                            <?php if ($a['status'] === 'pending'): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="app_review">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <input type="hidden" name="pass" value="1">
                                    <button class="btn btn-sm btn-primary" type="submit">通过上架</button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认驳回该商品？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="app_review">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <input type="hidden" name="pass" value="0">
                                    <input type="text" name="reason" placeholder="驳回原因" style="width:120px">
                                    <button class="btn btn-sm btn-bad" type="submit">驳回</button>
                                </form>
                            <?php elseif (in_array($a['status'], ['on', 'off'], true)): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="app_toggle">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-sm" type="submit"><?= $a['status'] === 'on' ? '下架' : '上架' ?></button>
                                </form>
                            <?php else: ?>
                                <span class="muted small"><?= e($a['review_note'] ?: '-') ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$devApps): ?><tr><td colspan="7" class="empty">暂无开发者商品</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($tab === 'deposits'): ?>
    <div class="card">
        <h2>保证金流水（<?= count($deposits) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>单号</th><th>客户</th><th>金额</th><th>状态</th><th>支付时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($deposits as $d): ?>
                <tr>
                    <td class="mono small"><?= e($d['sn']) ?></td>
                    <td><?= e($d['username'] ?: ('#' . $d['customer_id'])) ?></td>
                    <td><?= e(money($d['amount'], true)) ?></td>
                    <td><span class="badge <?= $d['status'] === 'paid' ? 'badge-ok' : 'badge-warn' ?>"><?= e(YxDeveloper::depositStatusName((string)$d['status'])) ?></span></td>
                    <td class="small muted"><?= e($d['paid_at'] ?: '-') ?></td>
                    <td>
                        <?php if ($d['status'] !== 'paid'): ?>
                            <form method="post" onsubmit="return confirm('确认已收到该开发者保证金？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deposit_confirm">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <button class="btn btn-sm btn-primary" type="submit">确认收款</button>
                            </form>
                        <?php else: ?><span class="muted small">-</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$deposits): ?><tr><td colspan="6" class="empty">暂无保证金流水</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($tab === 'realnames'): ?>
    <div class="card">
        <h2>实名认证记录（<?= count($realnames) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>类型</th><th>姓名 / 企业</th><th>证件</th><th>营业执照</th><th>审核方式</th><th>AI 检测</th><th>状态</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach ($realnames as $r): ?>
                <?php
                $raw   = json_decode((string)($r['result_raw'] ?? ''), true);
                $raw   = is_array($raw) ? $raw : [];
                $audit = (array)($raw['audit'] ?? []);
                ?>
                <tr>
                    <td><?= e(YxRealname::typeName((string)$r['dev_type'])) ?></td>
                    <td class="wrap">
                        <?= e($r['company'] ?: ($r['name'] ?: '-')) ?>
                        <?php if ($r['legal_person']): ?><br><span class="muted small">法人:<?= e($r['legal_person']) ?></span><?php endif; ?>
                    </td>
                    <td class="mono small">
                        <?php
                        $idc = $r['id_card'] ? yx_decrypt((string)$r['id_card']) : '';
                        echo $idc !== '' ? e(substr($idc, 0, 6) . '********' . substr($idc, -4)) : '-';
                        if ($r['license_no']) { echo '<br>' . e($r['license_no']); }
                        ?>
                    </td>
                    <td>
                        <?php if (!empty($r['license_image'])): ?>
                            <a href="<?= e(url('uploads/' . $r['license_image'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('uploads/' . $r['license_image'])) ?>" alt="营业执照" style="height:52px;border-radius:4px;border:1px solid #e3e8f0">
                            </a>
                        <?php else: ?><span class="muted small">-</span><?php endif; ?>
                    </td>
                    <td class="small"><?= e(YxRealname::drivers()[$r['provider']] ?? (string)$r['provider']) ?></td>
                    <td class="small" style="max-width:260px">
                        <?php if (!empty($audit['checks'])): ?>
                            <b style="color:#2f9e44">评分 <?= (int)($audit['score'] ?? 0) ?></b>
                            <details>
                                <summary class="muted" style="cursor:pointer">明细</summary>
                                <ul style="margin:6px 0 0 16px;padding:0">
                                <?php foreach ((array)$audit['checks'] as $c): ?>
                                    <li style="color:<?= !empty($c['ok']) ? '#2f9e44' : ((string)($c['level'] ?? '') === 'warn' ? '#e8590c' : '#e03131') ?>">
                                        <?= e((string)($c['label'] ?? '')) ?>：<?= e((string)($c['note'] ?? '')) ?>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php else: ?><span class="muted">-</span><?php endif; ?>
                    </td>
                    <td><span class="badge <?= e($rnStatusCls[$r['status']] ?? 'badge-mute') ?>"><?= e(YxDeveloper::realnameStatusName((string)$r['status'])) ?></span>
                        <?php if (!empty($r['message'])): ?><div class="muted small"><?= e(mb_substr((string)$r['message'], 0, 60)) ?></div><?php endif; ?>
                    </td>
                    <td class="small muted"><?= e($r['verified_at'] ?: $r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$realnames): ?><tr><td colspan="8" class="empty">暂无实名记录</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($tab === 'settlements'): ?>
    <div class="card">
        <h2>分成结算（<?= count($settlements) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>开发者</th><th>应用</th><th>成交额</th><th>平台佣金</th><th>开发者应得</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($settlements as $s): ?>
                <tr>
                    <td><?= e($s['company'] ?: ($s['real_name'] ?: ($s['username'] ?: ('#'.$s['developer_id'])))) ?></td>
                    <td class="mono small">#<?= (int)$s['app_id'] ?></td>
                    <td><?= e(money($s['gross'], true)) ?></td>
                    <td><?= e(money($s['commission'], true)) ?></td>
                    <td><strong><?= e(money($s['net'], true)) ?></strong></td>
                    <td><span class="badge <?= $s['status'] === 'settled' ? 'badge-ok' : 'badge-warn' ?>"><?= $s['status'] === 'settled' ? '已结算' : '待结算' ?></span></td>
                    <td>
                        <?php if ($s['status'] !== 'settled'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="settle">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-sm" type="submit">标记结算</button>
                            </form>
                        <?php else: ?><span class="muted small">-</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$settlements): ?><tr><td colspan="7" class="empty">暂无分成记录</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

<?php elseif ($tab === 'settings'): ?>
    <div class="card" style="max-width:760px">
        <h2>开发者中心设置</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="grid-2">
                <label>保证金金额（元/年，插件 / 主题 / 应用类） <input type="number" step="0.01" min="0" name="developer_deposit_amount" value="<?= e(setting('developer_deposit_amount', '600')) ?>"></label>
                <label>安装包上限（MB，实际受 PHP/nginx 限制） <input type="number" min="1" name="developer_package_max_mb" value="<?= e(setting('developer_package_max_mb', '50')) ?>"></label>
            </div>
            <p class="muted small">模板类商品<b>不收取保证金</b>（固定规则，不可配置）；保证金金额仅对插件 / 主题 / 应用类生效，按年收取。</p>
            <label class="checkbox"><input type="checkbox" name="developer_auto_approve" value="1" <?= (string)setting('developer_auto_approve', '0') === '1' ? 'checked' : '' ?>> 保证金缴纳后自动通过入驻（仍要求实名通过）</label>

            <h2 class="mt-4">实名认证审核方式</h2>
            <div class="grid-2">
                <label>审核方式
                    <select name="realname_provider">
                        <?php foreach (YxRealname::drivers() as $k => $name): ?>
                            <option value="<?= e($k) ?>" <?= YxRealname::provider() === $k ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>接口地址（HTTP 驱动） <input type="text" name="realname_endpoint" value="<?= e(setting('realname_endpoint', '')) ?>" placeholder="https://api.example.com/realname"></label>
                <label>AppID <input type="text" name="realname_appid" value="<?= e(setting('realname_appid', '')) ?>"></label>
                <label>AppSecret
                    <input type="password" name="realname_secret" value="" autocomplete="new-password"
                           placeholder="<?= setting('realname_secret', '') !== '' ? '（已配置，留空不修改）' : '未配置' ?>">
                </label>
            </div>
            <p class="muted small">
                「AI 智能自动监测」为内置默认方式，无需密钥：提交后即时审核身份证号校验位、行政区划、出生日期、是否满 18 周岁、姓名规范、统一社会信用代码与营业执照图片（尺寸 / 体积 / 空白检测）等，
                资料合规才通过，未满 18 周岁一律不通过。接入第三方厂商时选择「通用 HTTP」并填写接口地址与密钥。
            </p>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存设置</button>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="card">
        <h2>开发者入驻（<?= count($devs) ?>）</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>客户</th><th>类型</th><th>实名</th><th>保证金</th><th>状态</th><th>申请时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($devs as $d): ?>
                <tr>
                    <td><?= e($d['username'] ?: ('#' . $d['customer_id'])) ?></td>
                    <td><?= $d['type'] === 'enterprise' ? '企业' : '个人' ?></td>
                    <td><span class="badge <?= $d['realname_status'] === 'verified' ? 'badge-ok' : ($d['realname_status'] === 'pending' ? 'badge-warn' : 'badge-mute') ?>"><?= e(YxDeveloper::realnameStatusName((string)$d['realname_status'])) ?></span></td>
                    <td><span class="badge <?= $d['deposit_status'] === 'paid' ? 'badge-ok' : 'badge-mute' ?>"><?= e(YxDeveloper::depositStatusName((string)$d['deposit_status'])) ?></span>
                        <?php if ($d['deposit_status'] !== 'paid' && YxDeveloper::templateOnly($d)): ?>
                            <br><span class="muted small">仅模板 · 免缴</span>
                        <?php endif; ?></td>
                    <td><?= e(YxDeveloper::devStatusName((string)$d['status'])) ?></td>
                    <td class="small muted"><?= e($d['applied_at'] ?: $d['created_at']) ?></td>
                    <td>
                        <div class="row-actions">
                            <?php if ($d['can_publish'] != 1): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_dev">
                                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <button class="btn btn-sm btn-primary" type="submit">通过</button>
                                </form>
                                <form method="post" onsubmit="return confirm('确认驳回该入驻申请？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_dev">
                                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <button class="btn btn-sm btn-bad" type="submit">驳回</button>
                                </form>
                            <?php else: ?>
                                <span class="badge badge-ok">已通过</span>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/developers.php?tab=devs&contact=' . (int)$d['id'])) ?>">联系资料</a>
                        </div>
                        <?php if ($contactId === (int)$d['id']): ?>
                            <form method="post" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_dev_contact">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <div class="grid-2">
                                    <label>作者网站 <input type="text" name="website" value="<?= e($d['website'] ?? '') ?>" placeholder="https://www.example.com"></label>
                                    <label>电子邮箱 <input type="text" name="email" value="<?= e($d['email'] ?? '') ?>" placeholder="dev@example.com"></label>
                                    <label>咨询 QQ <input type="text" name="qq" value="<?= e($d['qq'] ?? '') ?>" placeholder="10001"></label>
                                    <label>联系微信 <input type="text" name="wechat" value="<?= e($d['wechat'] ?? '') ?>" placeholder="可选"></label>
                                </div>
                                <label>开发者简介 <input type="text" name="intro" maxlength="255" value="<?= e($d['intro'] ?? '') ?>" placeholder="展示在应用详情页的开发者卡片上"></label>
                                <div class="form-actions">
                                    <button class="btn btn-sm btn-primary" type="submit">保存联系资料</button>
                                    <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/developers.php?tab=devs')) ?>">收起</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$devs): ?><tr><td colspan="7" class="empty">暂无开发者</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
