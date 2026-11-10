<?php
/**
 * 优享财务系统 · 插件中心（用户端）
 *
 * 一个入口两件事：
 *  - 市场：浏览在售插件，跳转 store/detail.php 完成购买（复用原有下单/支付链路）
 *  - 我的：查看已购插件的授权状态、到期时间与绑定域名
 *
 * 购买与授权一律不在本页写入，仅做展示与跳转，避免出现第二套计费入口。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$customer = YxAuth::requireCustomer();
$cid      = (int)$customer['id'];

$tab = (string)($_GET['tab'] ?? 'market');
if (!in_array($tab, ['market', 'mine'], true)) {
    $tab = 'market';
}

/* ---------------- 市场 ---------------- */
$kw     = trim((string)($_GET['kw'] ?? ''));
$cat    = trim((string)($_GET['cat'] ?? ''));
$author = YxPluginCenter::authorKey((string)($_GET['author'] ?? ''));
$bill   = YxPluginCenter::billKey((string)($_GET['bill'] ?? ''));
$sort   = YxPluginCenter::sortKey((string)($_GET['sort'] ?? 'default'));
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 12;

list($where, $bind) = YxPluginCenter::marketWhere($kw, $cat, $author, $bill);
$total  = YxPluginCenter::marketCount($where, $bind);
$pages  = max(1, (int)ceil($total / $per));
$page   = min($page, $pages);
$apps   = YxPluginCenter::marketList($where, $bind, $sort, $per, ($page - 1) * $per);

$cats      = YxPluginCenter::categories();
$billStats = YxPluginCenter::billStats();
$market    = YxPluginCenter::marketStats();

/* ---------------- 我的 ---------------- */
$mineFilter = (string)($_GET['f'] ?? '');
if (!in_array($mineFilter, ['', 'active', 'expired'], true)) {
    $mineFilter = '';
}
$mine  = YxPluginCenter::myPlugins($cid, $mineFilter);
$stats = YxPluginCenter::myStats($cid);

function pc_url(array $override = []): string
{
    $q = array_merge([
        'tab'    => (string)($_GET['tab'] ?? 'market'),
        'kw'     => (string)($_GET['kw'] ?? ''),
        'cat'    => (string)($_GET['cat'] ?? ''),
        'author' => (string)($_GET['author'] ?? ''),
        'bill'   => (string)($_GET['bill'] ?? ''),
        'sort'   => (string)($_GET['sort'] ?? 'default'),
        'f'      => (string)($_GET['f'] ?? ''),
        'page'   => (string)($_GET['page'] ?? ''),
    ], $override);
    $q = array_filter($q, static function ($v) {
        return $v !== '' && $v !== null && $v !== 'default';
    });
    return url('user/plugin_center.php' . ($q ? '?' . http_build_query($q) : ''));
}

$userTitle  = '插件中心';
$userActive = 'plugin_center';
require dirname(__DIR__) . '/includes/user_header.php';
?>

<div class="cat-bar">
    <a class="<?= $tab === 'market' ? 'active' : '' ?>" href="<?= e(pc_url(['tab' => 'market', 'page' => ''])) ?>">插件市场（<?= (int)$market['on'] ?>）</a>
    <a class="<?= $tab === 'mine' ? 'active' : '' ?>" href="<?= e(pc_url(['tab' => 'mine', 'page' => ''])) ?>">我的插件（<?= (int)$stats['total'] ?>）</a>
</div>

<?php if ($tab === 'market'): ?>
    <div class="card" style="margin-bottom:16px">
        <form method="get" class="grid-4" style="align-items:end">
            <input type="hidden" name="tab" value="market">
            <label>关键词
                <input type="text" name="kw" value="<?= e($kw) ?>" placeholder="插件名称 / 标识">
            </label>
            <label>分类
                <select name="cat">
                    <option value="">全部分类</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= e($c['key']) ?>" <?= $cat === (string)$c['key'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>（<?= (int)$c['count'] ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>来源
                <select name="author">
                    <?php foreach (YxPluginCenter::AUTHOR_FILTERS as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $author === (string)$k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>计费
                <select name="bill">
                    <?php foreach (YxPluginCenter::BILL_FILTERS as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $bill === (string)$k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>排序
                <select name="sort">
                    <option value="default" <?= $sort === 'default' ? 'selected' : '' ?>>推荐</option>
                    <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>最新</option>
                    <option value="sales" <?= $sort === 'sales' ? 'selected' : '' ?>>销量</option>
                    <option value="hot" <?= $sort === 'hot' ? 'selected' : '' ?>>下载</option>
                    <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>价格从低到高</option>
                </select>
            </label>
            <label>&nbsp;<button class="btn btn-sm" type="submit">筛选</button></label>
        </form>
    </div>

    <?php if (!$apps): ?>
        <div class="card"><div class="empty">没有符合条件的插件</div></div>
    <?php else: ?>
        <div class="grid-3">
            <?php foreach ($apps as $a): $billT = YxBusiness::billType($a); ?>
                <div class="card">
                    <h2 style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <a href="<?= e(url('store/detail.php?id=' . (int)$a['id'])) ?>"><?= e($a['name']) ?></a>
                        <?php if (!empty($a['featured'])): ?><span class="badge badge-info">推荐</span><?php endif; ?>
                    </h2>
                    <p class="muted small">
                        <code><?= e($a['key']) ?></code> · v<?= e($a['version']) ?>
                        · <?= e(YxPluginCenter::authorName((string)($a['author_type'] ?? 'official'))) ?>
                    </p>
                    <p class="muted small">
                        计费：<?= e(YxBusiness::billName($billT)) ?>
                        · <?= (int)$a['sales'] ?> 人已购
                    </p>
                    <p style="font-weight:700;margin:8px 0"><?= e(YxBusiness::priceLabel($a)) ?></p>
                    <a class="btn btn-sm" href="<?= e(url('store/detail.php?id=' . (int)$a['id'])) ?>">查看详情 / 购买</a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <div class="cat-bar" style="margin-top:16px">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <a class="<?= $i === $page ? 'active' : '' ?>" href="<?= e(pc_url(['page' => (string)$i])) ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'mine'): ?>
    <div class="grid-3" style="margin-bottom:16px">
        <div class="card"><h2>已购插件</h2><div style="font-size:26px;font-weight:700"><?= (int)$stats['total'] ?></div></div>
        <div class="card"><h2>生效中</h2><div style="font-size:26px;font-weight:700"><?= (int)$stats['active'] ?></div></div>
        <div class="card"><h2>已过期 / 停用</h2><div style="font-size:26px;font-weight:700"><?= (int)$stats['expired'] ?></div></div>
    </div>

    <div class="cat-bar" style="margin-bottom:12px">
        <a class="<?= $mineFilter === '' ? 'active' : '' ?>" href="<?= e(pc_url(['tab' => 'mine', 'f' => ''])) ?>">全部</a>
        <a class="<?= $mineFilter === 'active' ? 'active' : '' ?>" href="<?= e(pc_url(['tab' => 'mine', 'f' => 'active'])) ?>">生效中</a>
        <a class="<?= $mineFilter === 'expired' ? 'active' : '' ?>" href="<?= e(pc_url(['tab' => 'mine', 'f' => 'expired'])) ?>">已过期</a>
    </div>

    <div class="card">
        <?php if (!$mine): ?>
            <div class="empty">还没有购买过插件，去<a href="<?= e(pc_url(['tab' => 'market'])) ?>">插件市场</a>看看</div>
        <?php else: ?>
            <table>
                <tr><th>插件</th><th>绑定域名</th><th>授权码</th><th>到期</th><th>状态</th><th>操作</th></tr>
                <?php foreach ($mine as $m): $st = YxPluginCenter::installState($m); ?>
                    <tr>
                        <td>
                            <?= e($m['app_name'] ?: ($m['app_key'] ?: '—')) ?>
                            <?php if (!empty($m['version'])): ?><span class="muted small">v<?= e($m['version']) ?></span><?php endif; ?>
                        </td>
                        <td><?= $m['domain'] === 'resource' ? '<span class="muted">资源权益</span>' : e($m['domain']) ?></td>
                        <td><code><?= e($m['license_key'] ? (YxPluginCenter::installState($m)['key'] === 'enabled' ? $m['license_key'] : YxBusiness::maskLicenseKey((string)$m['license_key'])) : '—') ?></code></td>
                        <td><?= e($m['expire_at'] ?: '长期') ?></td>
                        <td><span class="badge <?= e($st['class']) ?>"><?= e($st['name']) ?></span></td>
                        <td>
                            <?php if ((int)$m['app_id'] > 0): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('store/detail.php?id=' . (int)$m['app_id'])) ?>">
                                    <?= $st['key'] === 'enabled' ? '续期' : '重新获取' ?>
                                </a>
                            <?php else: ?>
                                <span class="muted small">该插件已下架</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/user_footer.php'; ?>
