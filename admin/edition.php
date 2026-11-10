<?php
/**
 * 优享财务系统 · 版本与模块
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'switch') {
        $target = (string)($_POST['edition'] ?? '');
        if (!isset(YxEdition::EDITIONS[$target])) {
            flash('未知的版本标识', 'error');
        } elseif (!YxEdition::canSwitch($target)) {
            flash('无法切换到「' . YxEdition::name($target) . '」：超出当前授权允许的版本上限（' . YxEdition::name(YxLicense::maxEdition()) . '）', 'error');
        } else {
            $old = YxEdition::current();
            set_setting('edition', $target);
            log_action('edition.switch', $target, ['from' => $old, 'to' => $target]);
            flash('版本已从「' . YxEdition::name($old) . '」切换为「' . YxEdition::name($target) . '」');
        }
        redirect(url('admin/edition.php'));
    }
}

$current = YxEdition::current();
$matrix  = YxEdition::matrix();
$maxEdition = YxLicense::maxEdition();
$license = YxLicense::check();

$pageTitle  = '版本与模块';
$activeMenu = 'edition';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="alert alert-warn">
    当前域名授权结论：<strong><?= e(YxLicense::badge()['text']) ?></strong>，授权允许的最高版本为
    <strong><?= e(YxEdition::name($maxEdition)) ?></strong>。
    <?php if (YxLicense::modeLocked()): ?>
        <br>本站为<strong>开源版发行包</strong>：授权模式已锁定为「开源版」，<strong>不可切换正式版</strong>，下方仅可调整开源版自身的版本档位。
    <?php else: ?>
        未授权时默认仅做提示、不限制切换；如需强制收敛请在「站务管理」开启 <code>license_enforce</code>。
    <?php endif; ?>
</div>

<div class="tiers">
    <?php foreach (YxEdition::EDITIONS as $key => $ed): ?>
        <div class="tier <?= $key === $current ? 'current' : '' ?>">
            <h3>
                <span class="badge badge-edition" style="--c:<?= e($ed['color']) ?>"><?= e($ed['name']) ?></span>
                <?php if ($key === $current): ?><span class="badge badge-ok">当前版本</span><?php endif; ?>
            </h3>
            <div class="price"><?= e($ed['tag']) ?> · <?= e($ed['price']) ?></div>
            <p class="muted small"><?= e($ed['desc']) ?></p>
            <ul class="tight">
                <?php foreach ($ed['items'] as $item): ?>
                    <li><?= e($item) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="form-actions" style="margin-top:16px">
                <?php if ($key === $current): ?>
                    <button class="btn" disabled>使用中</button>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="switch">
                        <input type="hidden" name="edition" value="<?= e($key) ?>">
                        <button class="btn btn-primary" type="submit" <?= YxEdition::canSwitch($key) ? '' : 'disabled' ?>>
                            <?= YxEdition::canSwitch($key) ? '切换到' . e($ed['name']) : '超出授权版本' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card" style="margin-top:18px">
    <h2>功能模块对照</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>功能模块</th><th>普惠版</th><th>专业版</th><th>企业版</th><th>当前可用</th></tr></thead>
        <tbody>
        <?php foreach ($matrix as $feature => $row): ?>
            <tr>
                <td><?= e(YxEdition::featureName($feature)) ?><br><span class="muted small mono"><?= e($feature) ?></span></td>
                <td><?= $row['basic'] ? '<span class="badge badge-ok">✓</span>' : '<span class="badge badge-mute">—</span>' ?></td>
                <td><?= $row['pro'] ? '<span class="badge badge-ok">✓</span>' : '<span class="badge badge-mute">—</span>' ?></td>
                <td><?= $row['enterprise'] ? '<span class="badge badge-ok">✓</span>' : '<span class="badge badge-mute">—</span>' ?></td>
                <td><?= YxEdition::can($feature) ? '<span class="badge badge-ok">已启用</span>' : '<span class="badge badge-warn">未启用</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <h2>版本能力说明</h2>
    <table class="data">
        <tr><th style="width:120px">普惠版</th><td>面向起步阶段：客户、产品、订单、账单、收支记账，并可在应用商店购买官方免费与付费插件，无到期提醒外发与渠道分账。</td></tr>
        <tr><th>专业版</th><td>在普惠版之上开放工单、自动续费、优惠券与渠道代理能力。</td></tr>
        <tr><th>企业版</th><td>再开放多节点资源、分站、开放 API、经营分析与授权中心对外签发能力。</td></tr>
    </table>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
