<?php
/**
 * 优享财务系统 · 上游对接（供应商 / 产品映射）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('upstream');

// 导入映射函数（周期折算 / 库存口径 / 勾选过滤）必须在 POST 处理之前加载：
// pull_raw、import_products 等分支会直接调用 yx_import_filter_unimported() 与
// yx_import_pick_items()，若把 require 放在 POST 块之后，勾选上架 / 只看未上架
// 会抛 "Call to undefined function" 致命错误。
require_once dirname(__DIR__) . '/tools/import_upstream.php';

$DRIVERS = YxUpstream::drivers();

/**
 * 拉取上游「原始条目」（含 cycle / qty / stock_control）
 *
 * 上架要做周期折算与库存判定，统一后的预览结构没有 cycle，折算会一律按 1 个月处理；
 * 因此勾选上架与「只看未上架」都以原始条目为准。失败时返回 ok=false 与原因，
 * 结构与其他拉取接口一致（ok/msg/list）。
 */
function yx_pull_raw_items(array $row): array
{
    try {
        $info = YxUpstream::productsRaw($row);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage(), 'list' => []];
    }
    if (empty($info['ok'])) {
        return ['ok' => false, 'msg' => (string)($info['msg'] ?? '未知原因'), 'list' => []];
    }
    return ['ok' => true, 'msg' => '', 'list' => (array)($info['list'] ?? [])];
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save_upstream') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $driver = isset($DRIVERS[$_POST['driver'] ?? '']) ? $_POST['driver'] : 'mock';
        if ($name === '') {
            $res = ['ok' => false, 'msg' => '供应商名称不能为空'];
        } else {
            $data = [
                'name'       => $name,
                'driver'     => $driver,
                'endpoint'   => trim((string)($_POST['endpoint'] ?? '')),
                'appid'      => trim((string)($_POST['appid'] ?? '')),
                'timeout'    => max(1, min(60, (int)($_POST['timeout'] ?? 10))),
                'auto_setup' => !empty($_POST['auto_setup']) ? 1 : 0,
                'status'     => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
                'remark'     => trim((string)($_POST['remark'] ?? '')),
            ];
            $secret = trim((string)($_POST['secret'] ?? ''));
            if ($secret !== '') {
                $data['secret'] = yx_encrypt($secret);
            }

            // 接口协议配置（JSON）：留空表示使用该驱动的默认模板
            $cfgRaw = trim((string)($_POST['config'] ?? ''));
            if ($cfgRaw === '') {
                $data['config'] = null;
            } else {
                $cfg = json_decode($cfgRaw, true);
                if (!is_array($cfg)) {
                    flash('接口协议配置不是合法 JSON：' . json_last_error_msg(), 'error');
                    redirect(url('admin/upstream.php' . ($id > 0 ? '?edit=' . $id : '')));
                }
                $data['config'] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
            }

            if ($id > 0) {
                YxDb::update('upstream', $data, 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '供应商已更新'];
            } else {
                $data['created_at'] = now();
                $id = YxDb::insert('upstream', $data);
                $res = ['ok' => true, 'msg' => '供应商已创建'];
            }
            log_action('upstream.save', $name, ['id' => $id]);

            // 保存后自动测试一次连通性，结论（含原因）回写并直接提示
            $t = YxUpstream::test((int)$id);
            if (!empty($t['ok'])) {
                $res['msg'] .= '，对接成功：' . (string)($t['msg'] ?? '');
                $res['type'] = 'success';
            } else {
                $res['msg'] .= '，但对接失败：' . (string)($t['msg'] ?? '未知错误');
                $res['type'] = 'error';
            }
        }
    } elseif ($action === 'test') {
        $r = YxUpstream::test((int)($_POST['id'] ?? 0));
        log_action('upstream.test', (string)($_POST['id'] ?? ''), ['ok' => !empty($r['ok'])]);
        $res = $r;
    } elseif ($action === 'stock_check') {
        $productId = (int)($_POST['product_id'] ?? 0);
        // 检测结论由 stockCheck() 直接写回供应商行的 last_stock_* 字段，刷新/换会话都不会丢
        $r = YxUpstream::stockCheck($productId);
        log_action('upstream.stock_check', 'product#' . $productId, ['ok' => !empty($r['ok']), 'stock' => $r['stock']]);
        $res = $r;
    } elseif ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $cnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE upstream_id = ?', [$id]);
        if ($cnt > 0) {
            $res = ['ok' => false, 'msg' => '该供应商下仍有 ' . $cnt . ' 个业务实例，建议停用而非删除'];
        } else {
            YxDb::delete('upstream_map', 'upstream_id = :id', ['id' => $id]);
            YxDb::delete('upstream', 'id = :id', ['id' => $id]);
            log_action('upstream.delete', (string)$id);
            $res = ['ok' => true, 'msg' => '供应商已删除，相关产品映射一并清理'];
        }
    } elseif ($action === 'pull_products') {
        $row = YxUpstream::row((int)($_POST['id'] ?? 0));
        if (!$row) {
            $res = ['ok' => false, 'msg' => '供应商不存在'];
        } else {
            try {
                $info = YxUpstream::productsOf($row);
                if (empty($info['ok'])) {
                    $res = ['ok' => false, 'msg' => '拉取失败：' . (string)($info['msg'] ?? '未知原因')];
                } else {
                    $list = (array)($info['list'] ?? []);
                    $res  = ['ok' => true, 'msg' => '已拉取 ' . count($list) . ' 个上游产品', 'list' => $list];
                    $_SESSION['_upstream_preview'] = ['id' => (int)$row['id'], 'list' => $list];
                    $_SESSION['_upstream_raw']     = ['id' => (int)$row['id'], 'list' => (array)(yx_pull_raw_items($row)['list'] ?? [])];
                }
            } catch (Throwable $e) {
                $res = ['ok' => false, 'msg' => '拉取失败：' . $e->getMessage()];
            }
        }
    } elseif ($action === 'pull_raw') {
        // 「只看未上架」：服务端过滤比前端按文本判断可靠（产品编码/状态都以库为准）
        $row = YxUpstream::row((int)($_POST['id'] ?? 0));
        if (!$row) {
            $res = ['ok' => false, 'msg' => '供应商不存在'];
        } else {
            $raw  = yx_pull_raw_items($row);
            $info = !empty($raw['ok']) ? ['ok' => true, 'list' => (array)$raw['list']] : YxUpstream::productsOf($row);
            if (empty($info['ok'])) {
                $res = ['ok' => false, 'msg' => '拉取失败：' . (string)($raw['msg'] ?? $info['msg'] ?? '未知原因')];
            } else {
                $list = yx_import_filter_unimported($row, (array)($info['list'] ?? []));
                $res  = ['ok' => true, 'msg' => '已拉取 ' . count($list) . ' 个未上架商品', 'list' => $list];
                $_SESSION['_upstream_preview'] = ['id' => (int)$row['id'], 'list' => $list];
                $_SESSION['_upstream_raw']     = ['id' => (int)$row['id'], 'list' => (array)($raw['list'] ?? [])];
            }
        }
    } elseif ($action === 'import_products') {
        // 把预览中勾选的上游商品上架到产品中心，并建立 upstream_map 绑定
        $upId    = (int)($_POST['upstream_id'] ?? 0);
        $row     = YxUpstream::row($upId);
        $codes   = array_values(array_filter(array_map('strval', (array)($_POST['codes'] ?? []))));
        // 预览（用于识别「勾选的编码是否属于本次拉取结果」）与原始条目（周期/库存口径）
        $sessPreview = (int)($_SESSION['_upstream_preview']['id'] ?? 0) === $upId
            ? (array)($_SESSION['_upstream_preview']['list'] ?? []) : [];
        $sessRaw     = (int)($_SESSION['_upstream_raw']['id'] ?? 0) === $upId
            ? (array)($_SESSION['_upstream_raw']['list'] ?? []) : [];
        if (!$row) {
            $res = ['ok' => false, 'msg' => '供应商不存在'];
        } elseif (!$codes) {
            $res = ['ok' => false, 'msg' => '请先勾选要上架的上游商品'];
        } else {
            $pickMsg = '';
            $pick    = yx_import_pick_items($row, $codes, $sessPreview, $sessRaw, $pickMsg);
            if (!$pick['picked']) {
                $res = ['ok' => false, 'msg' => $pickMsg !== '' ? $pickMsg : '勾选的商品不可导入'];
            } else {
                $groupId = (int)($_POST['group_id'] ?? 0);
                if ($groupId <= 0) {
                    // 未指定分组时按供应商名建一个，保持产品中心分组归类
                    $gname = trim((string)$row['name']);
                    $grp = YxDb::one('SELECT * FROM ' . YxDb::table('product_group') . ' WHERE name = ?', [$gname]);
                    $groupId = $grp ? (int)$grp['id']
                        : YxDb::insert('product_group', ['name' => $gname, 'sort' => 0, 'status' => 1]);
                }
                require_once dirname(__DIR__) . '/tools/import_upstream.php';
                $stats = yx_import_upstream_items($row, $pick['picked'], [
                    'group_id'     => $groupId,
                    'keep_missing' => true,        // 只同步勾选的，不能把其余商品当「上游消失」下架
                    'picked_codes' => $pick['codes'],
                    'force_price'  => !empty($_POST['force_price']),
                ]);
                log_action('upstream.import_products', 'upstream#' . $upId, [
                    'group_id' => $groupId, 'picked' => count($pick['codes']),
                    'total'    => $stats['total'],
                    'create'   => $stats['create'], 'update' => $stats['update'], 'fail' => $stats['fail'],
                ]);
                $msg = '上架完成：新增 ' . $stats['create'] . ' 个，更新 ' . $stats['update'] . ' 个';
                $off = 0;
                foreach ($stats['rows'] as $r) {
                    if ((int)$r['status'] === 0) {
                        $off++;
                    }
                }
                if ($off > 0) {
                    $msg .= '，其中 ' . $off . ' 个未上架（0 元或上游售罄，可按需手动调整）';
                }
                if ($stats['fail'] > 0) {
                    $msg .= '，失败 ' . $stats['fail'] . ' 个';
                }
                if ($pick['missing']) {
                    $msg .= '，' . count($pick['missing']) . ' 个在上游已下架未导入';
                }
                $res = ['ok' => true, 'msg' => $msg, 'type' => ($stats['fail'] > 0 || $pick['missing']) ? 'warn' : 'success'];
            }
        }
    } elseif ($action === 'save_map') {
        $id      = (int)($_POST['map_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $upId    = (int)($_POST['upstream_id'] ?? 0);
        if ($productId <= 0 || $upId <= 0) {
            $res = ['ok' => false, 'msg' => '请选择本地产品与上游供应商'];
        } else {
            $specRaw = trim((string)($_POST['spec'] ?? ''));
            $spec = [];
            if ($specRaw !== '') {
                $spec = json_decode($specRaw, true);
                if (!is_array($spec)) {
                    $res = ['ok' => false, 'msg' => '规格 JSON 格式不正确'];
                    flash($res['msg'], 'error');
                    redirect(url('admin/upstream.php'));
                }
            }
            $data = [
                'product_id'  => $productId,
                'upstream_id' => $upId,
                'remote_code' => trim((string)($_POST['remote_code'] ?? '')),
                'remote_name' => trim((string)($_POST['remote_name'] ?? '')),
                'spec'        => $spec ? json_encode($spec, JSON_UNESCAPED_UNICODE) : null,
                'price'       => round_money($_POST['price'] ?? 0),
                'auto_setup'  => !empty($_POST['map_auto']) ? 1 : 0,
                'status'      => 1,
            ];
            if ($id > 0) {
                YxDb::update('upstream_map', $data, 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '产品映射已更新'];
            } else {
                $data['created_at'] = now();
                YxDb::insert('upstream_map', $data);
                $res = ['ok' => true, 'msg' => '产品映射已创建'];
            }
            log_action('upstream.save_map', 'product#' . $productId);
        }
    } elseif ($action === 'delete_map') {
        YxDb::delete('upstream_map', 'id = :id', ['id' => (int)($_POST['map_id'] ?? 0)]);
        $res = ['ok' => true, 'msg' => '映射已删除'];
    }

    log_action('upstream.' . $action, '', ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], (string)($res['type'] ?? (!empty($res['ok']) ? 'success' : 'error')));
    redirect(url('admin/upstream.php'));
}

$preview = $_SESSION['_upstream_preview'] ?? null;
unset($_SESSION['_upstream_preview']);

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxUpstream::row($editId) : null;

$upstreams = YxDb::all('SELECT * FROM ' . YxDb::table('upstream') . ' ORDER BY id DESC');
$upMap     = [];
$stockMemo = [];
foreach ($upstreams as $u) {
    $upMap[(int)$u['id']] = $u['name'];
    $memo = YxUpstream::stockMemo($u);
    if ($memo !== null) {
        $stockMemo[(int)$u['id']] = $memo;   // 按供应商 ID 记忆：最近一次检测结论（持久化在 last_stock_*）
    }
}

$maps = YxDb::all(
    'SELECT m.*, p.name AS product_name, u.name AS upstream_name, u.driver
     FROM ' . YxDb::table('upstream_map') . ' m
     LEFT JOIN ' . YxDb::table('product') . ' p ON p.id = m.product_id
     LEFT JOIN ' . YxDb::table('upstream') . ' u ON u.id = m.upstream_id
     ORDER BY m.id DESC'
);

$mapEditId = (int)($_GET['mapedit'] ?? 0);
$mapEditing = $mapEditId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('upstream_map') . ' WHERE id = ?', [$mapEditId]) : null;

$products = YxDb::all('SELECT id, name FROM ' . YxDb::table('product') . ' ORDER BY id ASC');

// 导入函数（周期折算 / 价格口径）与 CLI 工具共用同一份实现
require_once dirname(__DIR__) . '/tools/import_upstream.php';
$groups = YxDb::all('SELECT id, name FROM ' . YxDb::table('product_group') . ' ORDER BY sort ASC, id ASC');

// 预览里标记已上架的产品，避免重复勾选时看不出状态
$previewId = (int)($preview['id'] ?? 0);
$importedCodes = [];
if ($previewId > 0) {
    foreach (YxDb::all(
        'SELECT m.remote_code, p.code AS product_code, p.status
         FROM ' . YxDb::table('upstream_map') . ' m
         LEFT JOIN ' . YxDb::table('product') . ' p ON p.id = m.product_id
         WHERE m.upstream_id = ?',
        [$previewId]
    ) as $m) {
        $importedCodes[(string)$m['remote_code']] = [
            'code'   => (string)($m['product_code'] ?? ''),
            'status' => (int)($m['status'] ?? 0),
        ];
    }
}

$pageTitle  = '上游对接';
$activeMenu = 'upstream';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="alert alert-warn">
    <strong>上游 = 为你提供资源的供应商。</strong>
    <span>产品绑定上游并开启「支持自动开通」后，客户付款会通过任务队列自动向上游申请开通；
    上游返回的实例编号、IP、登录信息会回写到业务实例。未绑定上游的产品仍走人工开通。
    新增厂商只需实现 <code>YxUpstreamDriver</code> 接口并在 <code>YxUpstream::drivers()</code> 登记。</span>
</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑供应商：' . e($editing['name']) : '新增上游供应商' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_upstream">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>供应商名称 <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="如：XX云 / 自建机房"></label>
                <label>对接驱动
                    <select name="driver">
                        <?php foreach ($DRIVERS as $k => $d): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['driver'] ?? 'mock') === $k ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>接口地址 <input type="text" name="endpoint" value="<?= e($editing['endpoint'] ?? '') ?>" placeholder="https://api.upstream.com/v1"></label>
                <label>商户/应用标识 <input type="text" name="appid" value="<?= e($editing['appid'] ?? '') ?>"></label>
                <label>接口密钥 <?= $editing && $editing['secret'] ? '<span class="badge badge-ok">已配置</span>' : '<span class="badge badge-mute">未配置</span>' ?>
                    <input type="password" name="secret" value="" autocomplete="new-password" placeholder="留空则不修改"></label>
                <label>请求超时（秒） <input type="number" name="timeout" value="<?= e($editing['timeout'] ?? 10) ?>"></label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </label>
            </div>
            <label class="checkbox"><input type="checkbox" name="auto_setup" value="1" <?= !empty($editing['auto_setup']) ? 'checked' : '' ?>> 允许该供应商自动开通</label>

            <details style="margin-bottom:14px" <?= $editing && trim((string)$editing['config']) !== '' ? 'open' : '' ?>>
                <summary style="cursor:pointer;color:var(--primary)">接口协议配置（选「自定义 / 魔方财务 / 乐众系统」驱动时使用；留空 = 用默认模板）</summary>
                <p class="muted small" style="margin-top:10px">
                    对接第三方系统时，把对方协议填到这里即可，<strong>无需改代码</strong>。可配置：
                    <code>method</code>（POST_JSON/POST_FORM/GET）、
                    <code>headers</code>（自定义请求头，支持 <code>{appid}{secret}{ts}{nonce}</code> 占位符）、
                    <code>auth</code>（append_secret / append_secret_upper / secret_prefix / hmac_md5 / hmac_sha256 / basic / none）、
                    <code>sign_field</code>、<code>drop_empty</code>、<code>params_json_unescaped</code>、
                    <code>appid_field / ts_field / nonce_field / action_field / params_wrapper</code>、
                    <code>actions</code>（动作名映射，<strong>值写成路径如 <code>/api/host/create</code> 即为多路径接口</strong>）、
                    <code>fields</code>（业务字段名映射）、
                    <code>response</code>（<code>format</code> 可选 json/xml、<code>code_path / success_value / msg_path / data_path / list_path / map / item_map</code>）。
                </p>
                <div class="form-actions" style="margin-bottom:8px">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="yxFillTemplate()">填入默认模板</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="yxCheckJson()">校验 JSON</button>
                    <span id="yxJsonMsg" class="small muted"></span>
                </div>
                <textarea name="config" id="yxConfig" class="mono" style="min-height:260px"
                          placeholder="留空则使用默认模板；点上方按钮填入模板后按对方文档修改"><?= e($editing['config'] ?? '') ?></textarea>
            </details>

            <label>备注 <input type="text" name="remark" value="<?= e($editing['remark'] ?? '') ?>"></label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建供应商' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/upstream.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
        <script>
        var YX_TEMPLATES = <?= json_encode([
            'custom'  => YxUpstream::defaultConfigFor('custom'),
            'zjmf'    => YxUpstream::defaultConfigFor('zjmf'),
            'lezhong' => YxUpstream::defaultConfigFor('lezhong'),
            'http'    => ['_note' => '标准 HTTP/JSON 驱动协议固定，无需配置'],
            'mock'    => ['_note' => '本地模拟驱动无需配置'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;

        function yxDriver() {
            var sel = document.querySelector('select[name=driver]');
            return sel ? sel.value : 'mock';
        }
        function yxFillTemplate() {
            var d = yxDriver();
            var t = YX_TEMPLATES[d] || {};
            document.getElementById('yxConfig').value = JSON.stringify(t, null, 2);
            yxCheckJson();
        }
        function yxCheckJson() {
            var el = document.getElementById('yxJsonMsg');
            var v = document.getElementById('yxConfig').value.trim();
            if (v === '') { el.textContent = '留空 = 使用默认模板'; el.style.color = '#7d8799'; return; }
            try {
                JSON.parse(v);
                el.textContent = '✓ JSON 格式正确';
                el.style.color = '#1f7a35';
            } catch (err) {
                el.textContent = '✗ JSON 格式错误：' + err.message;
                el.style.color = '#b02323';
            }
        }
        </script>
    </div>

    <div class="card">
        <h2>供应商列表</h2>
        <?php if (!$upstreams): ?>
            <div class="empty">还没有上游供应商。可以先用「本地模拟」驱动打通流程，再替换为真实厂商。</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>名称</th><th>驱动</th><th>接口地址</th><th>密钥</th><th>协议配置</th><th>连通性</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($upstreams as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e(YxUpstream::driverName($u['driver'])) ?></td>
                    <td class="mono small"><?= e($u['endpoint'] ?: '-') ?></td>
                    <td><?= $u['secret'] ? '<span class="badge badge-ok">已配置</span>' : '<span class="badge badge-mute">未配置</span>' ?></td>
                    <td>
                        <?php if (in_array($u['driver'], ['custom', 'zjmf', 'lezhong'], true)): ?>
                            <?= trim((string)$u['config']) !== '' ? '<span class="badge badge-ok">自定义</span>' : '<span class="badge badge-warn">用默认模板</span>' ?>
                        <?php else: ?>
                            <span class="muted small">内置协议</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['last_test_at']): ?>
                            <span class="badge <?= (int)$u['last_test_ok'] === 1 ? 'badge-ok' : 'badge-bad' ?>">
                                <?= (int)$u['last_test_ok'] === 1 ? '正常' : '失败' ?>
                            </span>
                            <br><span class="muted small"><?= e($u['last_test_at']) ?></span>
                            <?php if (!empty($u['last_test_msg'])): ?>
                                <br><span class="muted small wrap"><?= e($u['last_test_msg']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted small">未测试</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$u['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                    <td>
                        <div class="row-actions">
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="test">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-ok" type="submit">连通性测试</button>
                            </form>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="pull_products">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm" type="submit">拉取产品</button>
                            </form>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="pull_raw">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit" title="只列出该供应商还没上架过的商品">只看未上架</button>
                            </form>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/upstream.php?edit=' . (int)$u['id'])) ?>">编辑</a>
                            <form method="post" onsubmit="return confirm('确认删除该供应商？其产品映射也会一并删除。')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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
</div>

<?php if ($preview): ?>
<div class="card">
    <h2>上游产品预览（供应商 #<?= (int)$preview['id'] ?>，共 <?= count($preview['list']) ?> 个）</h2>
    <p class="muted small">
        勾选要卖的商品后点「上架到产品中心」，系统按 <code>up{上游ID}-{上游编码}</code> 建产品，并同时写入产品映射（后续库存检测、自动开通用）。
        「上架价」= 上游价 ÷ 周期月数（本站按月价计费，年付再 × 12 × 0.9）；上游按年计费的商品只开放年付。
        0 元或已售罄的商品会入库但不显示给客户。重复上架是安全的：已上架的商品只会更新，不会重复创建。
    </p>
    <form method="post" id="yxImportForm" onsubmit="return yxImportConfirm()">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_products">
        <input type="hidden" name="upstream_id" value="<?= (int)$preview['id'] ?>">
        <div class="form-actions" style="margin-bottom:10px;align-items:center;gap:12px;flex-wrap:wrap">
            <label class="checkbox" style="margin:0">
                <input type="checkbox" id="yxCheckAll" onclick="yxToggleAll(this)"> 全选 / 全不选
            </label>
            <label style="margin:0">目标分组
                <select name="group_id">
                    <option value="0">按供应商名自动创建</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="checkbox" style="margin:0" title="默认不覆盖已上架商品的人工定价；勾选后按上游最新价格与周期重置">
                <input type="checkbox" name="force_price" value="1"> 强制覆盖已有价格与周期
            </label>
            <button class="btn btn-primary" type="submit">上架到产品中心</button>
            <span id="yxPickCount" class="muted small">已选 0 个</span>
        </div>
        <div class="table-wrap"><table class="data">
            <thead><tr>
                <th style="width:36px"></th><th>编码</th><th>名称</th><th>上游价</th>
                <th>周期</th><th>上架价（月）</th><th>库存</th><th>状态</th><th>规格</th>
            </tr></thead>
            <tbody>
            <?php foreach ($preview['list'] as $item): ?>
                <?php
                $code  = (string)($item['code'] ?? '');
                $old   = $importedCodes[$code] ?? null;
                $cycle = (string)($item['cycle'] ?? '');
                $months = yx_import_cycle_months($cycle);
                $rawPrice = (float)($item['price'] ?? 0);
                $monthPrice = $rawPrice > 0 ? round_money($rawPrice / $months) : 0.0;
                ?>
                <tr>
                    <td><input type="checkbox" class="yx-import-pick" name="codes[]" value="<?= e($code) ?>" onclick="yxCountPicked()"></td>
                    <td class="mono"><?= e($code) ?></td>
                    <td><?= e($item['name'] ?? '') ?></td>
                    <td><?= e(money($rawPrice, true)) ?></td>
                    <td><?= $cycle !== '' && $cycle !== '0' ? e($cycle) : '<span class="muted small">未标注</span>' ?></td>
                    <td>
                        <?= $monthPrice > 0 ? e(money($monthPrice, true)) : '<span class="muted small">-</span>' ?>
                        <?php if ($months >= 12): ?><br><span class="badge badge-warn">仅年付</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if (($item['stock'] ?? null) === null): ?>
                            <span class="badge badge-warn">未提供</span>
                        <?php elseif ((int)$item['stock'] > 0): ?>
                            <span class="badge badge-ok"><?= (int)$item['stock'] ?> 台</span>
                        <?php elseif ((int)$item['stock'] === 0): ?>
                            <span class="badge badge-bad">售罄</span>
                        <?php else: ?>
                            <span class="badge badge-ok">不限量</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($old === null): ?>
                            <span class="badge badge-mute">未上架</span>
                        <?php elseif ($old['status'] === 1): ?>
                            <span class="badge badge-ok" title="<?= e($old['code']) ?>">已上架</span>
                        <?php else: ?>
                            <span class="badge badge-warn" title="<?= e($old['code']) ?>">已入库未上架</span>
                        <?php endif; ?>
                    </td>
                    <td class="mono small"><?= e(json_encode($item['spec'] ?? [], JSON_UNESCAPED_UNICODE)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$preview['list']): ?><tr><td colspan="9" class="empty">上游未返回可售产品</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </form>
    <script>
    function yxToggleAll(box) {
        document.querySelectorAll('.yx-import-pick').forEach(function (el) { el.checked = box.checked; });
        yxCountPicked();
    }
    function yxCountPicked() {
        var n = document.querySelectorAll('.yx-import-pick:checked').length;
        var el = document.getElementById('yxPickCount');
        if (el) { el.textContent = '已选 ' + n + ' 个'; }
    }
    function yxImportConfirm() {
        var n = document.querySelectorAll('.yx-import-pick:checked').length;
        if (n === 0) { alert('请先勾选要上架的上游商品'); return false; }
        return confirm('确认把选中的 ' + n + ' 个上游商品上架到产品中心？已存在的会更新为上游最新价格与库存。');
    }
    </script>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= $mapEditing ? '编辑产品映射 #' . (int)$mapEditing['id'] : '新增产品映射' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_map">
        <input type="hidden" name="map_id" value="<?= (int)($mapEditing['id'] ?? 0) ?>">
        <div class="grid-3">
            <label>本地产品
                <select name="product_id" required>
                    <option value="">请选择产品</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)($mapEditing['product_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>上游供应商
                <select name="upstream_id" required>
                    <option value="">请选择供应商</option>
                    <?php foreach ($upstreams as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)($mapEditing['upstream_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>上游产品编码 <input type="text" name="remote_code" value="<?= e($mapEditing['remote_code'] ?? '') ?>" placeholder="如 mock-hk-2c2g"></label>
            <label>上游产品名称 <input type="text" name="remote_name" value="<?= e($mapEditing['remote_name'] ?? '') ?>"></label>
            <label>上游成本价 <input type="number" step="0.01" name="price" value="<?= e($mapEditing['price'] ?? '0.00') ?>"></label>
        </div>
        <label>规格参数（JSON，将随开通请求一并提交给上游）
            <textarea name="spec" class="mono" placeholder='{"cpu":2,"mem":2048,"disk":40,"region":"hk","os":"centos7"}'><?= e($mapEditing['spec'] ?? '') ?></textarea>
        </label>
        <label class="checkbox"><input type="checkbox" name="map_auto" value="1" <?= (int)($mapEditing['auto_setup'] ?? 1) === 1 ? 'checked' : '' ?>> 允许自动开通</label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $mapEditing ? '保存映射' : '创建映射' ?></button>
            <?php if ($mapEditing): ?><a class="btn btn-ghost" href="<?= e(url('admin/upstream.php')) ?>">取消</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2>产品映射（<?= count($maps) ?> 条）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>本地产品</th><th>上游供应商</th><th>上游编码</th><th>规格</th><th>成本价</th><th>库存对接</th><th>自动开通</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($maps as $m): ?>
            <?php $memo = $stockMemo[(int)$m['upstream_id']] ?? null; ?>
            <tr>
                <td><?= e($m['product_name'] ?: ('#' . $m['product_id'])) ?></td>
                <td><?= e($m['upstream_name'] ?: '-') ?></td>
                <td class="mono small"><?= e($m['remote_code'] ?: '-') ?></td>
                <td class="mono small"><?= e($m['spec'] ?: '-') ?></td>
                <td><?= e(money($m['price'], true)) ?></td>
                <td>
                    <?php if (!$memo): ?>
                        <span class="muted small">未检测</span>
                    <?php elseif ($memo['state'] === 'fail'): ?>
                        <span class="badge badge-bad" title="<?= e($memo['msg']) ?>">对接失败</span>
                    <?php elseif ($memo['state'] === 'unknown'): ?>
                        <span class="badge badge-warn" title="<?= e($memo['msg']) ?>">未提供库存</span>
                    <?php elseif ($memo['state'] === 'empty'): ?>
                        <span class="badge badge-bad" title="<?= e($memo['msg']) ?>">上游无货</span>
                    <?php else: ?>
                        <span class="badge badge-ok" title="<?= e($memo['msg']) ?>">上游合计 <?= (int)$memo['total'] ?> 台</span>
                    <?php endif; ?>
                    <?php if ($memo): ?><br><span class="muted small"><?= e($memo['at']) ?></span><?php endif; ?>
                </td>
                <td><?= (int)$m['auto_setup'] === 1 ? '<span class="badge badge-ok">是</span>' : '<span class="badge badge-mute">否</span>' ?></td>
                <td>
                    <div class="row-actions">
                        <form method="post"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="stock_check">
                            <input type="hidden" name="product_id" value="<?= (int)$m['product_id'] ?>">
                            <button class="btn btn-sm" type="submit">检测库存</button>
                        </form>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/upstream.php?mapedit=' . (int)$m['id'])) ?>">编辑</a>
                        <form method="post" onsubmit="return confirm('确认删除该映射？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_map">
                            <input type="hidden" name="map_id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$maps): ?><tr><td colspan="8" class="empty">还没有产品映射</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
