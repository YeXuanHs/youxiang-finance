<?php
/**
 * 优享财务系统 · 导入商品
 *
 * 从已对接的上游挑一个商品上架到产品中心：
 *   - 本地分组：落到 yx_product_group，未选择时按上游名称自动建/取；
 *   - 上游供应商 / 上游产品：商品列表按供应商实时拉取（魔方走原始条目，保留周期与库存口径）；
 *   - 利润百分比：在「上游价折算月价」的基础上加价，0 表示按成本价上架；
 *   - 会员中心导航分类：写入 product.type（虚拟主机 / 云服务器 / 独立服务器 / 其他），
 *     与后台「产品与套餐」、会员中心产品导航使用同一套分类。
 *
 * 价格与周期的入库口径由 tools/import_upstream.php 统一处理，本页只负责取参与落库。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('upstream');
require_once dirname(__DIR__) . '/tools/import_upstream.php';

$TYPES = yx_import_types();
$SELF  = url('admin/product_import.php');

/** 按供应商拉取商品条目（原始条目优先，驱动不支持时退回统一结构） */
function yx_import_page_items(array $row): array
{
    $raw = YxUpstream::productsRaw($row);
    if (empty($raw['ok'])) {
        $raw = YxUpstream::productsOf($row);
    }
    return $raw;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // 选择上游后拉取商品下拉列表（JSON，供页面内联刷新）
    if ($action === 'products') {
        $row = YxUpstream::row((int)($_POST['upstream_id'] ?? 0));
        if (!$row) {
            json_out(['ok' => false, 'msg' => '请先选择上游供应商']);
        }
        $info = yx_import_page_items($row);
        if (empty($info['ok'])) {
            json_out(['ok' => false, 'msg' => '拉取上游商品失败：' . (string)($info['msg'] ?? '未知原因')]);
        }
        $list = [];
        foreach ((array)($info['list'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $p = yx_import_payload($item, (int)$row['id'], (string)$row['name']);
            if ($p['remote_code'] === '') {
                continue;
            }
            $list[] = [
                'code'      => $p['remote_code'],
                'name'      => $p['remote_name'],
                'raw_price' => $p['raw_price'],
                'cycle'     => $p['cycle_text'],
                'months'    => $p['months'],
                'cost'      => $p['product']['price'],
                'stock'     => $p['product']['stock'],
                'status'    => $p['status'],
            ];
        }
        json_out(['ok' => true, 'msg' => '已拉取 ' . count($list) . ' 个上游商品', 'list' => $list]);
    }

    // 确定：导入选中的单个上游商品
    if ($action === 'import') {
        $upId    = (int)($_POST['upstream_id'] ?? 0);
        $code    = trim((string)($_POST['remote_code'] ?? ''));
        $groupId = (int)($_POST['group_id'] ?? 0);
        $profit  = (float)($_POST['profit_percent'] ?? 0);
        $type    = (string)($_POST['type'] ?? 'host');
        $type    = isset($TYPES[$type]) ? $type : 'host';

        $row = YxUpstream::row($upId);
        if (!$row) {
            flash('请选择上游供应商', 'error');
            redirect($SELF);
        }
        if ($code === '') {
            flash('请选择要导入的上游产品', 'error');
            redirect($SELF . '?upstream=' . $upId);
        }
        if ($profit < 0 || $profit > 1000) {
            flash('利润百分比需在 0 - 1000 之间', 'error');
            redirect($SELF . '?upstream=' . $upId);
        }

        // 未指定分组时按供应商名建/取，保持产品中心分组归类
        if ($groupId <= 0) {
            $gname = trim((string)$row['name']);
            $grp   = YxDb::one('SELECT * FROM ' . YxDb::table('product_group') . ' WHERE name = ?', [$gname]);
            $groupId = $grp ? (int)$grp['id']
                : YxDb::insert('product_group', ['name' => $gname, 'sort' => 0, 'status' => 1]);
        }

        $info = yx_import_page_items($row);
        if (empty($info['ok'])) {
            flash('拉取上游商品失败：' . (string)($info['msg'] ?? '未知原因'), 'error');
            redirect($SELF . '?upstream=' . $upId);
        }

        $pickMsg = '';
        $pick    = yx_import_pick_items($row, [$code], [], (array)($info['list'] ?? []), $pickMsg);
        if (!$pick['picked']) {
            flash($pickMsg !== '' ? $pickMsg : '该上游商品已不存在，请重新选择', 'error');
            redirect($SELF . '?upstream=' . $upId);
        }

        $stats = yx_import_upstream_items($row, $pick['picked'], [
            'group_id'       => $groupId,
            'keep_missing'   => true,   // 只处理本次选的商品，不能把其余商品当「上游消失」下架
            'picked_codes'   => $pick['codes'],
            'force_price'    => true,   // 本页显式定价，覆盖已有售价
            'profit_percent' => $profit,
            'type'           => $type,
        ]);

        log_action('product.import', 'upstream#' . $upId, [
            'code' => $code, 'group_id' => $groupId, 'profit_percent' => $profit,
            'type' => $type, 'create' => $stats['create'], 'update' => $stats['update'], 'fail' => $stats['fail'],
        ]);

        if ($stats['fail'] > 0 || $stats['total'] === 0) {
            flash('导入失败，请稍后重试或检查上游接口', 'error');
        } else {
            $name = (string)($pick['picked'][0]['name'] ?? $code);
            $row0 = $stats['rows'][0] ?? [];
            // 以库里的最终状态回读：更新已存在产品时，0 元不会覆盖原售价，
            // 售罄才下架，不能直接拿上游 payload 的 status 当结论。
            $pid  = (int)($row0['product_id'] ?? 0);
            $prod = $pid > 0 ? YxDb::one('SELECT price, status FROM ' . YxDb::table('product') . ' WHERE id = ?', [$pid]) : null;
            $salePrice = (float)($prod['price'] ?? $row0['price'] ?? 0);
            $onShelf   = (int)($prod['status'] ?? 1) === 1;

            $msg = ($stats['create'] > 0 ? '已导入' : '已更新') . '：' . $name
                . '，售价 ' . money($salePrice, true);
            if ($onShelf) {
                $msg .= '，已上架';
            } elseif ((int)($row0['stock'] ?? -1) === 0) {
                $msg .= '，上游库存为 0，已置为下架（补货后可手动上架）';
            } else {
                $msg .= '，上游未提供有效价格，已按下架处理（可手动定价后上架）';
            }
            flash($msg, $onShelf ? 'success' : 'warn');
        }
        redirect(url('admin/products.php'));
    }

    flash('未知操作', 'error');
    redirect($SELF);
}

$upstreams = YxDb::all('SELECT * FROM ' . YxDb::table('upstream') . ' WHERE status = 1 ORDER BY id ASC');
$groups    = YxDb::all('SELECT * FROM ' . YxDb::table('product_group') . ' ORDER BY sort ASC, id ASC');
$curUp     = (int)($_GET['upstream'] ?? 0);
$curGroup  = (int)($_GET['group'] ?? 0);

$pageTitle  = '导入商品';
$activeMenu = 'product_import';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="card">
    <div class="section-head">
        <h2>导入商品</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/products.php')) ?>">返回产品与套餐</a>
    </div>
    <p class="muted small">
        从已对接的上游选择单个商品上架到产品中心。售价 = 上游价折算月价 ×（1 + 利润百分比），
        按上游计费周期自动折算，年付商品仅开放年付；重复导入同一商品会更新售价与分类。
    </p>

    <?php if (!$upstreams): ?>
        <div class="alert alert-warn">还没有可用的上游供应商，请先在 <a href="<?= e(url('admin/upstream.php')) ?>">上游对接</a> 中添加。</div>
    <?php endif; ?>

    <form method="post" id="importForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <div class="grid-2">
            <label>本地分组
                <select name="group_id">
                    <option value="0">（按上游名称自动新建）</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>" <?= $curGroup === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>上游供应商
                <select name="upstream_id" id="upstreamSel" required>
                    <option value="">请选择上游</option>
                    <?php foreach ($upstreams as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $curUp === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= e($u['name']) ?>（<?= e(YxUpstream::driverName((string)$u['driver'])) ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>上游产品
                <select name="remote_code" id="productSel" required disabled>
                    <option value="">请先选择上游供应商</option>
                </select>
            </label>
            <label>利润百分比（%）
                <input type="number" step="0.01" min="0" max="1000" name="profit_percent" id="profitInput" value="0">
            </label>
            <label>会员中心导航分类
                <select name="type">
                    <?php foreach ($TYPES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $k === 'vps' ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="alert alert-info" id="pickTip">先选择上游供应商，系统会自动拉取该上游的在售商品。</div>

        <div class="form-actions">
            <a class="btn btn-ghost" href="<?= e(url('admin/products.php')) ?>">取消</a>
            <button class="btn btn-primary" type="submit" id="submitBtn" disabled>确定</button>
        </div>
    </form>
</div>

<script>
(function () {
    var form   = document.getElementById('importForm');
    if (!form) { return; }
    var upSel  = document.getElementById('upstreamSel');
    var pSel   = document.getElementById('productSel');
    var profit = document.getElementById('profitInput');
    var tip    = document.getElementById('pickTip');
    var btn    = document.getElementById('submitBtn');
    var tokenEl = form.querySelector('input[name="_csrf"]');
    var token  = tokenEl ? tokenEl.value : '';
    var items  = [];

    function money(n) {
        n = parseFloat(n) || 0;
        return '¥' + n.toFixed(2);
    }

    function render() {
        var i = pSel.selectedIndex;
        if (!items[i]) {
            tip.textContent = '请选择要导入的上游产品。';
            btn.disabled = true;
            return;
        }
        var it  = items[i];
        var pct = parseFloat(profit.value || '0');
        if (isNaN(pct) || pct < 0) { pct = 0; }
        var sale = it.cost * (1 + pct / 100);
        var text = '上游价 ' + money(it.raw_price) + ' / ' + (it.cycle || '未标注周期')
            + '，折算月成本 ' + money(it.cost)
            + '，加价 ' + pct + '% 后售价 ' + money(sale)
            + (it.stock === -1 ? '，库存不限' : '，库存 ' + it.stock);
        if (!it.status) {
            text += '（上游 0 元或已售罄，导入后为下架状态）';
        }
        tip.textContent = text;
        btn.disabled = false;
    }

    function load() {
        var id = parseInt(upSel.value, 10) || 0;
        items = [];
        pSel.innerHTML = '';
        pSel.disabled = true;
        btn.disabled = true;
        if (!id) {
            tip.textContent = '先选择上游供应商，系统会自动拉取该上游的在售商品。';
            return;
        }
        tip.textContent = '正在拉取上游商品…';
        var body = new URLSearchParams();
        body.set('action', 'products');
        body.set('upstream_id', String(id));
        body.set('_csrf', token);

        fetch(location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json',
                'X-Requested-With': 'fetch'
            },
            body: body.toString()
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (!res.ok) {
                tip.textContent = res.msg || '拉取上游商品失败';
                return;
            }
            items = res.list || [];
            items.forEach(function (it) {
                var opt = document.createElement('option');
                opt.value = it.code;
                opt.textContent = it.name + '（' + money(it.raw_price) + ' / ' + (it.cycle || '未标注周期') + '）';
                pSel.appendChild(opt);
            });
            pSel.disabled = items.length === 0;
            if (items.length === 0) {
                tip.textContent = '该上游没有返回可导入的商品，请在「上游对接」中重新拉取。';
                pSel.innerHTML = '<option value="">暂无商品</option>';
                return;
            }
            pSel.selectedIndex = 0;
            render();
        }).catch(function () {
            tip.textContent = '拉取上游商品失败：网络错误，请刷新页面重试。';
        });
    }

    upSel.addEventListener('change', load);
    pSel.addEventListener('change', render);
    profit.addEventListener('input', render);
    if (upSel.value) { load(); }
})();
</script>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
