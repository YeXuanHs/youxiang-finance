<?php
/**
 * 优享财务系统 · 每日签到（后台管理）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_rules') {
        set_setting('checkin_enabled', !empty($_POST['checkin_enabled']) ? '1' : '0');
        set_setting('checkin_points', (string)max(0, (int)($_POST['checkin_points'] ?? 5)));
        set_setting('checkin_streak_days', (string)max(2, (int)($_POST['checkin_streak_days'] ?? 7)));
        set_setting('checkin_streak_bonus', (string)max(0, (int)($_POST['checkin_streak_bonus'] ?? 10)));
        log_action('checkin.save_rules');
        flash('签到规则已保存');
    } else {
        flash('未知操作', 'error');
    }
    redirect(url('admin/checkin.php'));
}

$total   = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('checkin_log'));
$todayN  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('checkin_log') . ' WHERE check_date = ?', [date('Y-m-d')]);
$monthN  = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('checkin_log') . ' WHERE check_date LIKE ?', [date('Y-m') . '-%']);
$pointsN = (int)YxDb::value('SELECT COALESCE(SUM(points),0) FROM ' . YxDb::table('checkin_log'));

$page  = paginate($total, 30);
$rows  = YxDb::all(
    'SELECT l.*, c.username FROM ' . YxDb::table('checkin_log') . ' l
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = l.customer_id
     ORDER BY l.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset']
);

$pageTitle  = '每日签到';
$activeMenu = 'checkin';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="stat accent"><span>今日签到</span><strong><?= $todayN ?></strong></div>
    <div class="stat"><span>本月签到</span><strong><?= $monthN ?></strong></div>
    <div class="stat"><span>累计记录</span><strong><?= $total ?></strong></div>
    <div class="stat"><span>累计发放积分</span><strong><?= $pointsN ?></strong></div>
</div>

<div class="card mt-4" style="max-width:720px">
    <h2>签到规则</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_rules">
        <label class="checkbox"><input type="checkbox" name="checkin_enabled" value="1" <?= YxCheckin::enabled() ? 'checked' : '' ?>> 开启每日签到</label>
        <div class="grid-3">
            <label>每日积分 <input type="number" min="0" name="checkin_points" value="<?= (int)YxCheckin::points() ?>"></label>
            <label>连续周期（天） <input type="number" min="2" name="checkin_streak_days" value="<?= (int)YxCheckin::streakDays() ?>"></label>
            <label>周期奖励积分 <input type="number" min="0" name="checkin_streak_bonus" value="<?= (int)YxCheckin::streakBonus() ?>"></label>
        </div>
        <p class="muted small">积分累加到客户「积分」字段，可用于后续积分商城 / 抵扣等场景。</p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存规则</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>签到记录</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>客户</th><th>签到日期</th><th>获得积分</th><th>连续天数</th><th>IP</th><th>时间</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['username'] ?: ('#' . $r['customer_id'])) ?></td>
                <td><?= e($r['check_date']) ?></td>
                <td>+<?= (int)$r['points'] ?></td>
                <td><?= (int)$r['streak'] ?></td>
                <td class="mono small"><?= e($r['ip']) ?></td>
                <td class="small muted"><?= e($r['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无签到记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($page) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
