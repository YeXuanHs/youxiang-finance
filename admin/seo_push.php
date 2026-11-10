<?php
/**
 * 优享财务系统 · 搜索引擎推送
 *
 * 功能：
 *   1. 总开关 + 站点地址 + 推送范围 + 自动推送间隔
 *   2. 各平台通道配置（百度 / 神马 / IndexNow；360、搜狗、头条仅走 sitemap）
 *   3. 一键推送（首页 / 全站）与推送记录
 *   4. sitemap.xml 预览与 robots.txt 提示
 *
 * 说明：360、搜狗、头条的主动推送接口实测已下线，页面如实标注，
 *      不做伪装式"推送成功"。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save') {
        set_setting(YxSeoPush::SET_ENABLED, !empty($_POST['enabled']) ? '1' : '0');
        set_setting(YxSeoPush::SET_SCHEME, in_array($_POST['scheme'] ?? '', ['http', 'https'], true) ? $_POST['scheme'] : 'https');
        set_setting(YxSeoPush::SET_DAILY, !empty($_POST['daily']) ? '1' : '0');
        set_setting(YxSeoPush::SET_INTERVAL, (string)max(1, min(168, (int)($_POST['interval'] ?? 24))));
        set_setting(YxSeoPush::SET_SCOPE, in_array($_POST['scope'] ?? '', ['home', 'site'], true) ? $_POST['scope'] : 'home');
        $site = trim((string)($_POST['site'] ?? ''));
        set_setting(YxSeoPush::SET_SITE, $site === '' ? '' : normalize_domain($site));

        // channels 以数组提交（name="channels[]"），这里归一化为逗号分隔字符串
        // 直接 (string)$_POST['channels'] 会得到 "Array"，会把所有通道静默关闭
        $channels = is_array($_POST['channels'] ?? null) ? implode(',', $_POST['channels']) : '';
        set_setting(YxSeoPush::SET_CHANNELS, $channels);

        // 通道凭证（仅提交非空项，避免空值覆盖已有配置）
        foreach (YxSeoPush::channels() as $key => $def) {
            if ($def['type'] !== 'push') {
                continue;
            }
            $vals = [];
            foreach (array_keys($def['fields']) as $f) {
                $v = trim((string)($_POST[$key . '_' . $f] ?? ''));
                if ($v !== '') {
                    $vals[$f] = $v;
                }
            }
            if ($vals) {
                YxSeoPush::setChannelConfig($key, $vals);
            }
        }
        log_action('seo_push.save', setting(YxSeoPush::SET_SITE, site_domain()));
        $res = ['ok' => true, 'msg' => '推送配置已保存'];
    } elseif ($action === 'push') {
        $scope  = in_array($_POST['scope'] ?? '', ['home', 'site'], true) ? $_POST['scope'] : 'home';
        $urls   = YxSeoPush::urls($scope);
        $report = [];
        foreach (YxSeoPush::activeChannels() as $ch) {
            if (!YxSeoPush::canPush($ch)) {
                $report[] = ['name' => YxSeoPush::channelName($ch), 'ok' => false, 'msg' => '仅支持 sitemap 被动收录'];
                continue;
            }
            $r = YxSeoPush::pushChannel($ch, $urls, '手动推送');
            $report[] = ['name' => YxSeoPush::channelName($ch), 'ok' => $r['ok'], 'msg' => $r['msg']];
        }
        $_SESSION['_seo_push_report'] = $report;
        log_action('seo_push.push', $scope, ['ok' => count(array_filter($report, static function ($r) { return $r['ok']; }))]);
        $res = ['ok' => true, 'msg' => '推送已完成，结果见下方明细'];
    } elseif ($action === 'clear_log') {
        $n = YxSeoPush::purge(0);
        log_action('seo_push.clear_log');
        $res = ['ok' => true, 'msg' => '已清理全部推送记录'];
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/seo_push.php'));
}

$report = $_SESSION['_seo_push_report'] ?? null;
unset($_SESSION['_seo_push_report']);

$channels = YxSeoPush::channels();
$active   = YxSeoPush::activeChannels();
$siteRoot = YxSeoPush::siteRoot();
$urls     = YxSeoPush::urls('site');
$inowKey  = YxSeoPush::indexNowKey();

// 推送记录
$logF = (string)($_GET['log'] ?? '');
$where = ' WHERE 1=1';
$bind  = [];
if ($logF !== '' && in_array($logF, array_keys($channels), true)) {
    $where .= ' AND channel = ?';
    $bind[] = $logF;
}
$total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('seo_push_log') . $where, $bind);
$pg    = paginate($total, 20);
$logs  = YxDb::all(
    'SELECT * FROM ' . YxDb::table('seo_push_log') . $where . ' ORDER BY id DESC LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset'],
    $bind
);

$todayOk = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('seo_push_log') . ' WHERE ok = 1 AND created_at >= ?', [date('Y-m-d') . ' 00:00:00']);

$pageTitle  = '搜索引擎推送';
$activeMenu = 'seo_push';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-4">
    <div class="stat accent"><span>今日成功推送</span><strong><?= $todayOk ?></strong></div>
    <div class="stat"><span>启用通道</span><strong><?= count(array_filter($active, static function ($c) use ($channels) { return $channels[$c]['type'] === 'push'; })) ?></strong></div>
    <div class="stat"><span>收录 URL</span><strong><?= count($urls) ?></strong></div>
    <div class="stat"><span>总开关</span><strong style="color:<?= YxSeoPush::enabled() ? '#2f9e44' : '#e03131' ?>"><?= YxSeoPush::enabled() ? '已开启' : '已关闭' ?></strong></div>
</div>

<?php if ($report): ?>
<div class="card" style="margin-top:16px">
    <h2>本次推送结果</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>平台</th><th>结果</th><th>说明</th></tr></thead>
        <tbody>
        <?php foreach ($report as $r): ?>
            <tr>
                <td><?= e($r['name']) ?></td>
                <td><?= $r['ok'] ? '<span class="badge badge-ok">成功</span>' : '<span class="badge badge-bad">失败</span>' ?></td>
                <td class="small"><?= e($r['msg']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:16px">
    <h2>基础设置</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="section-head"><h3>推送范围</h3></div>
        <label class="checkbox">
            <input type="checkbox" name="enabled" value="1" <?= YxSeoPush::enabled() ? 'checked' : '' ?>>
            启用搜索引擎主动推送
        </label>
        <label class="checkbox">
            <input type="checkbox" name="daily" value="1" <?= trim((string)setting(YxSeoPush::SET_DAILY, '1')) === '1' ? 'checked' : '' ?>>
            开启每日自动推送（由定时任务 <code>tools/cron.php</code> 驱动）
        </label>
        <div class="grid-3">
            <label>推送站点地址
                <input type="text" name="site" value="<?= e(setting(YxSeoPush::SET_SITE, '')) ?>" placeholder="留空自动取当前域名">
            </label>
            <label>协议
                <select name="scheme">
                    <option value="https" <?= setting(YxSeoPush::SET_SCHEME, 'https') === 'https' ? 'selected' : '' ?>>https</option>
                    <option value="http" <?= setting(YxSeoPush::SET_SCHEME, 'https') === 'http' ? 'selected' : '' ?>>http</option>
                </select>
            </label>
            <label>自动推送间隔（小时）
                <input type="number" name="interval" min="1" max="168" value="<?= (int)setting(YxSeoPush::SET_INTERVAL, '24') ?>">
            </label>
        </div>
        <label>推送范围
            <select name="scope">
                <option value="home" <?= setting(YxSeoPush::SET_SCOPE, 'home') === 'home' ? 'selected' : '' ?>>仅首页</option>
                <option value="site" <?= setting(YxSeoPush::SET_SCOPE, 'home') === 'site' ? 'selected' : '' ?>>首页 + 公开栏目页 + 已发布文章</option>
            </select>
        </label>
        <div class="form-row">
            <span class="muted small">启用通道（可多选；标注「仅 sitemap」的平台无主动推送接口，只走被动收录）</span>
            <div class="checkbox-row">
                <?php foreach ($channels as $key => $def): ?>
                    <label class="checkbox mt-0">
                        <input type="checkbox" name="channels[]" value="<?= e($key) ?>" <?= in_array($key, $active, true) ? 'checked' : '' ?>>
                        <?= e($def['name']) ?>
                        <span class="muted small"><?= $def['type'] === 'push' ? '（主动推送）' : '（仅 sitemap）' ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="section-head"><h3>平台凭证</h3></div>
        <?php foreach ($channels as $key => $def): ?>
            <?php if ($def['type'] !== 'push') { continue; } ?>
            <?php $cfg = YxSeoPush::channelConfig($key); ?>
            <div class="channel-box">
                <strong><?= e($def['name']) ?></strong>
                <span class="muted small"><?= e($def['note']) ?></span>
                <?php foreach ($def['fields'] as $f => $meta): ?>
                    <label><?= e($meta['label']) ?>
                        <?php if ($f === 'resource'): ?>
                            <input type="text" name="<?= e($key) ?>_<?= e($f) ?>" value="<?= e($cfg[$f] ?? '') ?>" placeholder="默认 mip_add">
                        <?php else: ?>
                            <input type="text" name="<?= e($key) ?>_<?= e($f) ?>" value="<?= e($cfg[$f] ?? '') ?>" autocomplete="off">
                        <?php endif; ?>
                        <span class="muted small"><?= e($meta['hint']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">保存配置</button>
    </form>
</div>

<div class="card" style="margin-top:16px">
    <h2>一键推送</h2>
    <p class="muted small">
        当前推送地址：<code><?= e($siteRoot !== '' ? $siteRoot . '/' : '(未配置)') ?></code>，
        共 <strong><?= count($urls) ?></strong> 个 URL。
        同一通道每日仅成功推送一次，重复提交会被搜索引擎判为作弊。
    </p>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="push">
        <label>范围
            <select name="scope">
                <option value="home">仅首页</option>
                <option value="site">首页 + 栏目页 + 文章</option>
            </select>
        </label>
        <button class="btn btn-primary" type="submit" <?= YxSeoPush::enabled() ? '' : 'disabled' ?>>立即推送</button>
    </form>
    <?php if (!YxSeoPush::enabled()): ?>
        <p class="muted small">总开关未开启，按钮已禁用。请先在上文开启。</p>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px">
    <h2>收录文件</h2>
    <p class="muted small">
        <code>sitemap.xml</code> 由系统动态生成，供 360 / 搜狗 / 头条 等仅支持被动收录的平台抓取。
        IndexNow 密钥文件 <code><?= e($inowKey) ?>.txt</code> 已自动生成于站点根目录，需确保可通过
        <code>https://<?= e(site_domain()) ?>/<?= e($inowKey) ?>.txt</code> 公开访问，否则 IndexNow 会返回 403。
    </p>
    <details>
        <summary class="muted">查看 sitemap.xml 内容</summary>
        <pre class="mono small" style="overflow:auto;max-height:320px"><?= e(YxSeoPush::sitemapXml()) ?></pre>
    </details>
</div>

<div class="card" style="margin-top:16px">
    <div class="section-head">
        <h2>推送记录（共 <?= $total ?> 条）</h2>
        <form method="get" class="inline-form">
            <label>平台
                <select name="log">
                    <option value="">全部</option>
                    <?php foreach ($channels as $k => $def): ?>
                        <option value="<?= e($k) ?>" <?= $logF === $k ? 'selected' : '' ?>><?= e($def['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-ghost btn-sm" type="submit">筛选</button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('确定清空全部推送记录吗？');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_log">
            <button class="btn btn-ghost btn-sm" type="submit">清空记录</button>
        </form>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>ID</th><th>平台</th><th>结果</th><th>HTTP</th><th>URL 数</th><th>说明</th><th>来源</th><th>时间</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td><?= (int)$l['id'] ?></td>
                <td><?= e(YxSeoPush::channelName($l['channel'])) ?></td>
                <td><?= $l['ok'] ? '<span class="badge badge-ok">成功</span>' : '<span class="badge badge-bad">失败</span>' ?></td>
                <td class="mono small"><?= (int)$l['http_code'] ?></td>
                <td><?= (int)$l['url_count'] ?></td>
                <td class="small"><?= e($l['message']) ?></td>
                <td class="small muted"><?= e($l['source'] === 'scheduled' ? '定时' : '手动') ?></td>
                <td class="small muted"><?= e($l['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="8" class="muted">暂无推送记录</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?= pager($pg) ?>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
