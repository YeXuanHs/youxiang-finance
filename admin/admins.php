<?php
/**
 * 优享财务系统 · 管理员管理
 *
 * 新增 / 编辑 / 启用停用 / 重置密码 / 删除后台管理员。
 * 角色决定权限范围：超级管理员拥有全部权限，运营管理员除「应用商店」「开发者中心」
 * 与「管理员管理」外拥有全部权限（见 YxAuth::DENIED）。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxAuth::requireAdminPermission('admin_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];

    if ($action === 'save_admin') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $nickname = trim((string)($_POST['nickname'] ?? ''));
        $role     = isset(YxAuth::ROLES[$_POST['role'] ?? '']) ? (string)$_POST['role'] : 'operator';
        $status   = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;
        $pass     = (string)($_POST['password'] ?? '');
        $pass2    = (string)($_POST['password2'] ?? '');

        // 用户名只允许字母数字与 . _ -，长度与表字段一致
        if ($username === '') {
            $res = ['ok' => false, 'msg' => '登录账号不能为空'];
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $res = ['ok' => false, 'msg' => '登录账号只能用字母、数字、点、下划线、短横线，长度 3~50 位'];
        } elseif (mb_strlen($nickname) > 50) {
            $res = ['ok' => false, 'msg' => '显示名称不能超过 50 个字'];
        } elseif ($id <= 0 && $pass === '') {
            $res = ['ok' => false, 'msg' => '新增管理员必须设置登录密码'];
        } elseif ($pass !== '' && strlen($pass) < 8) {
            $res = ['ok' => false, 'msg' => '密码至少 8 位'];
        } elseif ($pass !== '' && $pass !== $pass2) {
            $res = ['ok' => false, 'msg' => '两次输入的密码不一致'];
        } else {
            $dup = YxDb::one(
                'SELECT id FROM ' . YxDb::table('admin') . ' WHERE username = ? AND id <> ? LIMIT 1',
                [$username, $id]
            );
            $target = $id > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE id = ?', [$id]) : null;

            if ($dup) {
                $res = ['ok' => false, 'msg' => '登录账号「' . $username . '」已存在'];
            } elseif ($id > 0 && !$target) {
                $res = ['ok' => false, 'msg' => '该管理员不存在'];
            } elseif ($id > 0 && YxAuth::roleOf($target) === 'owner') {
                // 超级管理员不提供任何管理操作：页面上已隐藏入口，这里拦住直接构造的请求
                $res = ['ok' => false, 'msg' => '超级管理员账号受保护，不能编辑'];
            } elseif ($id > 0 && $id === (int)$admin['id'] && ($status === 0 || $role !== YxAuth::roleOf($admin))) {
                // 防止把自己停用或降权后无人能管理系统
                $res = ['ok' => false, 'msg' => '不能停用自己的账号，也不能修改自己的角色'];
            } else {
                $data = [
                    'username' => $username,
                    'nickname' => $nickname !== '' ? $nickname : null,
                    'role'     => $role,
                    'status'   => $status,
                ];
                if ($pass !== '') {
                    $data['password']   = YxAuth::hash($pass);
                    // 重置密码后解除锁定，否则新密码还要等锁定期结束才能用
                    $data['fail_count'] = 0;
                    $data['lock_until'] = null;
                }

                if ($id > 0) {
                    YxDb::update('admin', $data, 'id = :id', ['id' => $id]);
                    log_action('admin.update', $username, ['id' => $id, 'role' => $role, 'status' => $status, 'reset_pass' => $pass !== '']);
                    $res = ['ok' => true, 'msg' => '管理员已更新' . ($pass !== '' ? '，登录密码已重置' : '')];
                } else {
                    $data['created_at'] = now();
                    $newId = YxDb::insert('admin', $data);
                    log_action('admin.create', $username, ['id' => $newId, 'role' => $role]);
                    $res = ['ok' => true, 'msg' => '管理员「' . $username . '」已创建，现在可以用该账号登录后台'];
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $target = YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE id = ?', [$id]);
        if (!$target) {
            $res = ['ok' => false, 'msg' => '该管理员不存在'];
        } elseif (YxAuth::roleOf($target) === 'owner') {
            $res = ['ok' => false, 'msg' => '超级管理员账号受保护，不能停用或启用'];
        } elseif ($id === (int)$admin['id']) {
            $res = ['ok' => false, 'msg' => '不能停用自己的账号'];
        } else {
            $to = (int)$target['status'] === 1 ? 0 : 1;
            YxDb::update('admin', ['status' => $to], 'id = :id', ['id' => $id]);
            log_action('admin.toggle_status', (string)$target['username'], ['status' => $to]);
            $res = ['ok' => true, 'msg' => '管理员「' . $target['username'] . '」已' . ($to === 1 ? '启用' : '停用')];
        }
    } elseif ($action === 'delete_admin') {
        $id     = (int)($_POST['id'] ?? 0);
        $target = YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE id = ?', [$id]);
        if (!$target) {
            $res = ['ok' => false, 'msg' => '该管理员不存在'];
        } elseif (YxAuth::roleOf($target) === 'owner') {
            $res = ['ok' => false, 'msg' => '超级管理员账号受保护，不能删除'];
        } elseif ($id === (int)$admin['id']) {
            $res = ['ok' => false, 'msg' => '不能删除自己的账号'];
        } else {
            // 至少保留一个可用的超级管理员，否则无人能再进后台
            $owners = (int)YxDb::value(
                'SELECT COUNT(*) FROM ' . YxDb::table('admin') . ' WHERE role = ? AND status = 1 AND id <> ?',
                ['owner', $id]
            );
            if (YxAuth::roleOf($target) === 'owner' && (int)$target['status'] === 1 && $owners < 1) {
                $res = ['ok' => false, 'msg' => '这是最后一个可用的超级管理员，不能删除'];
            } else {
                YxDb::delete('admin', 'id = :id', ['id' => $id]);
                log_action('admin.delete', (string)$target['username'], ['id' => $id, 'role' => YxAuth::roleOf($target)]);
                $res = ['ok' => true, 'msg' => '管理员「' . $target['username'] . '」已删除'];
            }
        }
    }

    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/admins.php'));
}

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE id = ?', [$editId]) : null;
if ($editing && YxAuth::roleOf($editing) === 'owner') {
    // 超级管理员不提供编辑入口，直接访问编辑页同样拦回列表
    flash('超级管理员账号受保护，不能编辑', 'error');
    redirect(url('admin/admins.php'));
}

$admins = YxDb::all('SELECT * FROM ' . YxDb::table('admin') . ' ORDER BY id ASC');

$pageTitle  = '管理员管理';
$activeMenu = 'admins';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="alert alert-warn">
    <strong>角色决定能看哪些菜单和页面。</strong>
    <span>
        「超级管理员」拥有全部权限，其账号在列表中不提供编辑 / 停用 / 删除等操作；
        「运营管理员」拥有除
        <strong>应用商店</strong>、<strong>开发者中心</strong>、<strong>管理员管理</strong>
        之外的全部权限，登录后这些入口不会出现在左侧菜单，直接访问也会被拦回首页。
    </span>
</div>

<div class="card">
    <h2><?= $editing ? '编辑管理员：' . e($editing['username']) : '新增管理员' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_admin">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="grid-2">
            <label>登录账号（字母、数字、点、下划线、短横线，3~50 位）
                <input type="text" name="username" required maxlength="50"
                       value="<?= e($editing['username'] ?? '') ?>" placeholder="如：yunying01"></label>
            <label>显示名称（可选）<input type="text" name="nickname" maxlength="50" value="<?= e($editing['nickname'] ?? '') ?>"></label>
            <label>角色
                <select name="role">
                    <?php foreach (YxAuth::ROLES as $rk => $rn): ?>
                        <option value="<?= e($rk) ?>" <?= YxAuth::roleOf($editing ?: []) === $rk ? 'selected' : '' ?>>
                            <?= e($rn) ?><?= $rk === 'owner' ? '（全部权限）' : '（除应用商店 / 开发者中心）' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>状态
                <select name="status">
                    <option value="1" <?= (int)($editing['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
                    <option value="0" <?= (int)($editing['status'] ?? 1) === 0 ? 'selected' : '' ?>>停用</option>
                </select>
            </label>
            <label>登录密码<?= $editing ? '（留空不修改）' : '' ?>
                <input type="password" name="password" autocomplete="new-password" placeholder="<?= $editing ? '留空则不修改密码' : '至少 8 位' ?>"></label>
            <label>确认密码 <input type="password" name="password2" autocomplete="new-password" placeholder="再输入一次"></label>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '创建管理员' ?></button>
            <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/admins.php')) ?>">取消</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2>管理员列表（<?= count($admins) ?> 个）</h2>
    <div class="table-wrap"><table class="data">
        <thead><tr>
            <th>ID</th><th>登录账号</th><th>显示名称</th><th>角色</th><th>状态</th>
            <th>最近登录</th><th>创建时间</th><th>操作</th>
        </tr></thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
            <?php
            $isSelf = (int)$a['id'] === (int)$admin['id'];
            $role   = YxAuth::roleOf($a);
            ?>
            <tr>
                <td><?= (int)$a['id'] ?></td>
                <td class="mono">
                    <?= e($a['username']) ?>
                    <?php if ($isSelf): ?><span class="badge badge-ok">当前账号</span><?php endif; ?>
                </td>
                <td><?= e($a['nickname'] ?: '-') ?></td>
                <td>
                    <?php if ($role === 'owner'): ?>
                        <span class="badge badge-ok"><?= e(YxAuth::roleName($role)) ?></span>
                    <?php else: ?>
                        <span class="badge badge-warn" title="无「应用商店」「开发者中心」「管理员管理」权限"><?= e(YxAuth::roleName($role)) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= (int)$a['status'] === 1 ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-bad">停用</span>' ?></td>
                <td class="muted small">
                    <?php if (!empty($a['last_login_at'])): ?>
                        <?= e($a['last_login_at']) ?><br><span class="mono"><?= e($a['last_login_ip'] ?: '-') ?></span>
                    <?php else: ?>
                        从未登录
                    <?php endif; ?>
                </td>
                <td class="muted small"><?= e($a['created_at'] ?: '-') ?></td>
                <td>
                    <div class="row-actions">
                        <?php if ($role === 'owner'): ?>
                            <span class="muted small">超级管理员账号受保护，不提供操作</span>
                        <?php else: ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/admins.php?edit=' . (int)$a['id'])) ?>">编辑</a>
                        <?php if (!$isSelf): ?>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm" type="submit"><?= (int)$a['status'] === 1 ? '停用' : '启用' ?></button>
                            </form>
                            <form method="post" onsubmit="return confirm('确认删除管理员「<?= e($a['username']) ?>」？该账号将立即无法登录。')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_admin">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">删除</button>
                            </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
