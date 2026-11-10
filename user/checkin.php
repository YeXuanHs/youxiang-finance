<?php
/**
 * 优享财务系统 · 每日签到（用户中心）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = YxCheckin::checkin($cid);
    log_action('checkin.do', 'customer#' . $cid, ['ok' => $res['ok']]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('user/checkin.php'));
}

$month = (string)($_GET['m'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$firstTs   = strtotime($month . '-01');
$daysInMon = (int)date('t', $firstTs);
$startW    = (int)date('w', $firstTs); // 0=周日

$stats    = YxCheckin::stats($cid);
$calendar = YxCheckin::calendar($cid, $month);
$points   = (int)YxDb::value('SELECT points FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
$rules    = [
    '每日积分'   => YxCheckin::points(),
    '连签周期'   => YxCheckin::streakDays() . ' 天',
    '周期奖励'   => YxCheckin::streakBonus(),
];

$userTitle  = '每日签到';
$userActive = 'checkin';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<?php if (!YxCheckin::enabled()): ?>
    <div class="card"><div class="empty">签到功能暂未开放</div></div>
<?php else: ?>

<div class="grid-4">
    <div class="stat accent"><span>当前积分</span><strong><?= $points ?></strong></div>
    <div class="stat"><span>连续签到</span><strong><?= $stats['streak'] ?> 天</strong></div>
    <div class="stat"><span>本月签到</span><strong><?= $stats['month_days'] ?> 天</strong></div>
    <div class="stat"><span>累计获得</span><strong><?= $stats['total_points'] ?> 分</strong></div>
</div>

<div class="grid-2 mt-4">
    <div class="card">
        <h2>今日签到</h2>
        <?php if ($stats['checked']): ?>
            <div class="alert alert-ok">今天已签到，明天再来～ 已连续签到 <?= $stats['streak'] ?> 天。</div>
        <?php else: ?>
            <p class="muted">每日签到可获得 <b><?= (int)$rules['每日积分'] ?></b> 积分；连续签到每满 <b><?= (int)YxCheckin::streakDays() ?></b> 天额外奖励 <b><?= (int)YxCheckin::streakBonus() ?></b> 积分。</p>
            <form method="post" class="form-actions">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit">立即签到</button>
            </form>
        <?php endif; ?>

        <table class="data mt-3">
            <?php foreach ($rules as $k => $v): ?>
                <tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <div class="section-head">
            <h2><?= e($month) ?> 签到日历</h2>
            <div class="row-actions">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('user/checkin.php?m=' . date('Y-m', strtotime($month . '-01 -1 month')))) ?>">上一月</a>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('user/checkin.php?m=' . date('Y-m', strtotime($month . '-01 +1 month')))) ?>">下一月</a>
            </div>
        </div>
        <div class="table-wrap"><table class="data" style="text-align:center">
            <thead><tr><th>日</th><th>一</th><th>二</th><th>三</th><th>四</th><th>五</th><th>六</th></tr></thead>
            <tbody>
            <?php
            $cells = [];
            for ($i = 0; $i < $startW; $i++) {
                $cells[] = '';
            }
            for ($d = 1; $d <= $daysInMon; $d++) {
                $cells[] = sprintf('%s-%02d', $month, $d);
            }
            while (count($cells) % 7 !== 0) {
                $cells[] = '';
            }
            ?>
            <?php foreach (array_chunk($cells, 7) as $week): ?>
                <tr>
                    <?php foreach ($week as $date): ?>
                        <td>
                            <?php if ($date === ''): ?>
                                <span class="muted">·</span>
                            <?php else: ?>
                                <?php $got = $calendar[$date] ?? null; ?>
                                <div class="mono small"><?= (int)substr($date, -2) ?></div>
                                <?php if ($got !== null): ?>
                                    <span class="badge badge-ok">+<?= (int)$got ?></span>
                                <?php elseif ($date === date('Y-m-d')): ?>
                                    <span class="badge badge-warn">今日</span>
                                <?php else: ?>
                                    <span class="muted small">—</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
