<?php
/**
 * 优享财务系统 · 资源节点管理（企业版）
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxEdition::requireFeature('business.node');

$TYPES = ['host' => '虚拟主机节点', 'vps' => '云服务器节点', 'server' => '物理服务器节点', 'cdn' => 'CDN 节点'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [
            'name'     => trim((string)($_POST['name'] ?? '')),
            'type'     => isset($TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'host',
            'host'     => trim((string)($_POST['host'] ?? '')),
            'port'     => (int)($_POST['port'] ?? 0),
            'capacity' => (int)($_POST['capacity'] ?? 0),
            'status'   => (int)($_POST['status'] ?? 1) === 1 ? 1 : 0,
        ];
        $apiKey = trim((string)($_POST['api_key'] ?? ''));
        if ($apiKey !== '') {
            $data['api_key'] = yx_encrypt($apiKey);
        }
        if ($data['name'] === '') {
            flash('节点名称不能为空', 'error');
            redirect(url('admin/nodes.php'));
        }
        if ($id > 0) {
            YxDb::update('node', $data, 'id = :id', ['id' => $id]);
            flash('节点已更新');
        } else {
            $data['used']       = 0;
            $data['created_at'] = now();
            YxDb::insert('node', $data);
            flash('节点已创建');
        }
        log_action('node.save', $data['name']);
        redirect(url('admin/nodes.php'));
    }

    if ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $cnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . ' WHERE node_id = ?', [$id]);
        if ($cnt > 0) {
            flash('该节点下仍有 ' . $cnt . ' 个业务，无法删除', 'error');
        } else {
            YxDb::delete('node', 'id = :id', ['id' => $id]);
            log_action('node.delete', (string)$id);
            flash('节点已删除');
        }
        redirect(url('admin/nodes.php'));
    }
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('node') . ' WHERE id = ?', [$editId]) : null;
$nodes   = YxDb::all('SELECT * FROM ' . YxDb::table('node') . ' ORDER BY id DESC');

$pageTitle  = '资源节点';
$activeMenu = 'nodes';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="alert alert-warn">节点用于标记业务实例的部署位置与容量。填写接口密钥后可与上游平台对接实现自动开通，密钥以 AES 加密存储。</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? '编辑节点 #' . (int)$editing['id'] : '新增节点' ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="grid-2">
                <label>节点名称 <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
                <label>节点类型
                    <select name="type">
                        <?php foreach ($TYPES as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= ($editing['type'] ?? 'host') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>主机地址 <input type="text" name="host" value="<?= e($editing['host'] ?? '') ?>" placeholder="node.example.com"></label>
                <label>接口端口 <input type="number" name="port" value="<?= e($editing['port'] ?? 0) ?>"></label>
                <label>容量上限 <input type="number" name="capacity" value="<?= e($editing['capacity'] ?? 0) ?>"></label>
                <label>状态
                    <select name="status">
                        <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </label>
            </div>
            <label>接口密钥 <input type="password" name="api_key" placeholder="<?= $editing ? '留空表示不修改' : '可留空' ?>" autocomplete="new-password"></label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建节点' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/nodes.php')) ?>">取消</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>节点列表</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>名称</th><th>类型</th><th>地址</th><th>容量</th><th>密钥</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($nodes as $n): ?>
                <tr>
                    <td><?= e($n['name']) ?></td>
                    <td><?= e($TYPES[$n['type']] ?? $n['type']) ?></td>
                    <td class="mono small"><?= e($n['host'] ?: '-') ?><?= (int)$n['port'] > 0 ? ':' . (int)$n['port'] : '' ?></td>
                    <td><?= (int)$n['used'] ?> / <?= (int)$n['capacity'] ?></td>
                    <td><?= $n['api_key'] ? '<span class="badge badge-ok">已配置</span>' : '<span class="badge badge-mute">未配置</span>' ?></td>
                    <td><?= (int)$n['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-mute">停用</span>' ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/nodes.php?edit=' . (int)$n['id'])) ?>">编辑</a>
                            <form method="post" onsubmit="return confirm('确认删除该节点？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$nodes): ?><tr><td colspan="7" class="empty">暂无节点</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
