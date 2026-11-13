<?php
/**
 * 优享财务系统 · 应用商店管理
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();
YxAuth::requireAdminPermission('store');

$tab = (string)($_GET['tab'] ?? 'apps');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $res    = ['ok' => false, 'msg' => '未知操作'];
    $auditTarget = '';
    $auditDetail = [];

    if ($action === 'save_app') {
        $id   = (int)($_POST['id'] ?? 0);
        $key  = strtolower(trim((string)($_POST['key'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        if ($key === '' || $name === '') {
            $res = ['ok' => false, 'msg' => '应用标识与应用名称为必填项'];
        } elseif (!preg_match('/^[a-z0-9\-_]+$/', $key)) {
            $res = ['ok' => false, 'msg' => '应用标识仅允许小写字母、数字、- 与 _'];
        } else {
            $data = [
                'category_key' => (string)($_POST['category_key'] ?? 'other'),
                'key'          => $key,
                'name'         => $name,
                'version'      => trim((string)($_POST['version'] ?? '1.0.0')),
                'author'       => trim((string)($_POST['author'] ?? '')),
                'price'        => round_money($_POST['price'] ?? 0),
                'bill_type'    => in_array($_POST['bill_type'] ?? '', ['free', 'once', 'year', 'points'], true) ? $_POST['bill_type'] : 'free',
                'points_price' => max(0, (int)($_POST['points_price'] ?? 0)),
                'description'  => trim((string)($_POST['description'] ?? '')),
                'changelog'    => trim((string)($_POST['changelog'] ?? '')),
                'edition_min'  => isset(YxEdition::EDITIONS[$_POST['edition_min'] ?? '']) ? $_POST['edition_min'] : 'basic',
                'compatible'   => trim((string)($_POST['compatible'] ?? '')),
                'source'       => trim((string)($_POST['source'] ?? '')),
                'specs'        => json_encode(YxServiceCenter::cleanSpecs($_POST['specs'] ?? '[]'), JSON_UNESCAPED_UNICODE),
                'price_options' => json_encode(YxStore::cleanOptions($_POST['price_options'] ?? ''), JSON_UNESCAPED_UNICODE),
                /* 仅官方发行包（type=package + author_type=official）使用：仓库相对路径，
                   前后台下载都要求它落在 uploads/ 之内，非法值会被 YxStore::packageFile() 拦下 */
                'package_path' => ltrim(trim((string)($_POST['package_path'] ?? '')), '/'),
                'language'     => trim((string)($_POST['language'] ?? '')) ?: '简体中文',
                'published_at' => trim((string)($_POST['published_at'] ?? '')) ?: now(),
                'featured'     => !empty($_POST['featured']) ? 1 : 0,
                'status'       => in_array($_POST['status'] ?? '', ['on', 'off'], true) ? $_POST['status'] : 'on',
                'updated_at'   => now(),
            ];
            $dup = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . ' WHERE key = ? AND id <> ?', [$key, $id]);
            if ($dup) {
                $res = ['ok' => false, 'msg' => '应用标识已存在'];
            } elseif ($id > 0) {
                YxDb::update('app', $data, 'id = :id', ['id' => $id]);
                $res = ['ok' => true, 'msg' => '应用已更新'];
            } else {
                $data['created_at'] = now();
                $data['downloads']  = 0;
                $data['sales']      = 0;
                YxDb::insert('app', $data);
                $res = ['ok' => true, 'msg' => '应用已上架'];
            }
        }
    } elseif ($action === 'delete_app') {
        $id = (int)($_POST['id'] ?? 0);
        $cnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_install') . ' WHERE app_id = ?', [$id]);
        if ($cnt > 0) {
            $res = ['ok' => false, 'msg' => '该应用已被安装 ' . $cnt . ' 次，建议下架而非删除'];
        } else {
            YxDb::delete('app', 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '应用已删除'];
        }
    } elseif ($action === 'toggle_app') {
        $id = (int)($_POST['id'] ?? 0);
        $app = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$id]);
        if ($app) {
            YxDb::update('app', ['status' => $app['status'] === 'on' ? 'off' : 'on', 'updated_at' => now()], 'id = :id', ['id' => $id]);
            $res = ['ok' => true, 'msg' => '状态已切换'];
        }
    } elseif ($action === 'upload_package') {
        /*
         * 官方发行包上传：只允许绑定到 author_type=official 且 type=package 的条目。
         * 文件名沿用发行包命名（.zip 正版程序包与开源包 / .zip.enc 安装授权包，兼容旧版 .tar.gz.enc），不接受随机重命名，保证 package_path、
         * dist 受控副本与运维交付的文件名一致；同名文件原子替换。
         */
        $id   = (int)($_POST['id'] ?? 0);
        $app  = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$id]);
        $file = $_FILES['package_file'] ?? null;
        if (!$app || !YxStore::isOfficialPackage($app)) {
            $res = ['ok' => false, 'msg' => '仅官方发行包（type=package）可上传'];
        } elseif (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $res = ['ok' => false, 'msg' => '请选择要上传的发行包'];
        } elseif ((int)$file['error'] !== UPLOAD_ERR_OK) {
            $res = ['ok' => false, 'msg' => '上传失败（错误码 ' . (int)$file['error'] . '），请检查 PHP 上传大小限制'];
        } elseif ((int)($file['size'] ?? 0) <= 0) {
            $res = ['ok' => false, 'msg' => '发行包为空文件'];
        } else {
            $name = basename((string)($file['name'] ?? ''));
            if (strpos($name, '..') !== false
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:zip\.enc|zip|tar\.gz\.enc)$/', $name)) {
                $res = ['ok' => false, 'msg' => '文件名不合法：仅允许字母、数字、点、下划线与短横线，且必须以 .zip（正版程序包/开源包）、.zip.enc（安装授权包）或旧格式 .tar.gz.enc 结尾'];
            } else {
                $dir = yx_upload_dir('license');
                if (!is_writable($dir)) {
                    $res = ['ok' => false, 'msg' => '上传目录不可写：uploads/license'];
                } else {
                    $tmp  = $dir . '/.upload-' . bin2hex(random_bytes(6)) . '.tmp';
                    $dest = $dir . '/' . $name;
                    $moved = PHP_SAPI === 'cli'
                        ? @copy($file['tmp_name'], $tmp)
                        : @move_uploaded_file($file['tmp_name'], $tmp);
                    if (!$moved) {
                        $res = ['ok' => false, 'msg' => '保存文件失败'];
                    } elseif (!@rename($tmp, $dest)) {
                        @unlink($tmp);
                        $res = ['ok' => false, 'msg' => '写入发行包失败'];
                    } else {
                        @chmod($dest, 0640);
                        $size = (int)@filesize($dest);
                        YxDb::update('app', [
                            'package_path' => 'license/' . $name,
                            'package_size' => $size,
                            'updated_at'   => now(),
                        ], 'id = :id', ['id' => $id]);

                        // 受控副本：dist/ 在站点根目录之外且通常仅 root 可写，不可写时不影响前台下载
                        $distDir = rtrim((string)config('store.release_dir', ''), '/');
                        $distOk  = false;
                        if ($distDir !== '' && @is_dir($distDir) && @is_writable($distDir)) {
                            $distOk = @copy($dest, $distDir . '/' . $name);
                            if ($distOk) {
                                @chmod($distDir . '/' . $name, 0640);
                            }
                        }
                        $auditTarget = 'app#' . $id;
                        $auditDetail = ['file' => $name, 'size' => $size, 'dist_synced' => (bool)$distOk];
                        $res = [
                            'ok'  => true,
                            'msg' => '发行包已上传并绑定（' . number_format($size / 1048576, 2) . ' MB）'
                                . ($distOk ? '，受控副本已同步' : '；dist/ 不可写，受控副本需运维同步'),
                        ];
                    }
                }
            }
        }
    } elseif ($action === 'save_cat') {
        $cid  = (int)($_POST['cid'] ?? 0);
        $ckey = strtolower(trim((string)($_POST['ckey'] ?? '')));
        $cname = trim((string)($_POST['cname'] ?? ''));
        if ($ckey === '' || $cname === '' || !preg_match('/^[a-z0-9\-_]+$/', $ckey)) {
            $res = ['ok' => false, 'msg' => '分类标识（小写字母/数字/-/_）与名称为必填项'];
        } else {
            $data = ['key' => $ckey, 'name' => $cname, 'icon' => (string)($_POST['cicon'] ?? ''), 'desc' => (string)($_POST['cdesc'] ?? ''), 'sort' => (int)($_POST['csort'] ?? 0)];
            $dup  = YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app_category') . ' WHERE key = ? AND id <> ?', [$ckey, $cid]);
            if ($dup) {
                $res = ['ok' => false, 'msg' => '分类标识已存在'];
            } elseif ($cid > 0) {
                YxDb::update('app_category', $data, 'id = :id', ['id' => $cid]);
                $res = ['ok' => true, 'msg' => '分类已更新'];
            } else {
                YxDb::insert('app_category', $data + ['status' => 1]);
                $res = ['ok' => true, 'msg' => '分类已创建'];
            }
        }
    } elseif ($action === 'delete_cat') {
        $cid = (int)($_POST['cid'] ?? 0);
        $cnt = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . ' WHERE category_key = (SELECT `key` FROM ' . YxDb::table('app_category') . ' WHERE id = ?)', [$cid]);
        if ($cnt > 0) {
            $res = ['ok' => false, 'msg' => '该分类下仍有 ' . $cnt . ' 个应用，无法删除'];
        } else {
            YxDb::delete('app_category', 'id = :id', ['id' => $cid]);
            $res = ['ok' => true, 'msg' => '分类已删除'];
        }
    } elseif ($action === 'uninstall') {
        YxDb::update('app_install', ['status' => 'disabled'], 'id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        $res = ['ok' => true, 'msg' => '已停用该安装记录'];
    } elseif ($action === 'sync') {
        $api = trim((string)config('store.remote_api', ''));
        if ($api === '') {
            $res = ['ok' => false, 'msg' => '未配置商店远端源（config.php → store.remote_api）'];
        } else {
            $body = http_get(rtrim($api, '/') . '/api/apps.php?act=list', 8);
            $json = $body ? json_decode($body, true) : null;
            if (!is_array($json) || empty($json['data'])) {
                $res = ['ok' => false, 'msg' => '远端返回数据为空或格式不正确'];
            } else {
                $n = 0;
                foreach ($json['data'] as $item) {
                    if (empty($item['key']) || empty($item['name'])) {
                        continue;
                    }
                    $exists = YxDb::one('SELECT id FROM ' . YxDb::table('app') . ' WHERE key = ?', [$item['key']]);
                    $row = [
                        'category_key' => $item['category_key'] ?? 'other',
                        'key'          => $item['key'],
                        'name'         => $item['name'],
                        'version'      => $item['version'] ?? '1.0.0',
                        'author'       => $item['author'] ?? '远端商店',
                        'price'        => round_money($item['price'] ?? 0),
                        'description'  => $item['description'] ?? '',
                        'edition_min'  => $item['edition_min'] ?? 'basic',
                        'compatible'   => $item['compatible'] ?? '',
                        'source'       => $item['source'] ?? '',
                        'status'       => 'on',
                        'updated_at'   => now(),
                    ];
                    if ($exists) {
                        YxDb::update('app', $row, 'id = :id', ['id' => (int)$exists['id']]);
                    } else {
                        YxDb::insert('app', $row + ['downloads' => 0, 'sales' => 0, 'featured' => 0, 'created_at' => now()]);
                    }
                    $n++;
                }
                $res = ['ok' => true, 'msg' => '远端同步完成，处理 ' . $n . ' 个应用'];
            }
        }
    }

    log_action('store.' . $action, $auditTarget, $auditDetail + ['ok' => $res['ok'] ?? false]);
    flash($res['msg'], !empty($res['ok']) ? 'success' : 'error');
    redirect(url('admin/store.php?tab=' . urlencode(in_array($action, ['save_cat', 'delete_cat'], true) ? 'cats' : ($action === 'uninstall' ? 'installs' : 'apps'))));
}

$cats     = YxDb::all('SELECT * FROM ' . YxDb::table('app_category') . ' ORDER BY sort ASC, id ASC');
$catMap   = [];
foreach ($cats as $c) {
    $catMap[$c['key']] = $c['name'];
}
$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE id = ?', [$editId]) : null;
$catEditId = (int)($_GET['catedit'] ?? 0);
$catEditing = $catEditId > 0 ? YxDb::one('SELECT * FROM ' . YxDb::table('app_category') . ' WHERE id = ?', [$catEditId]) : null;

$apps = YxDb::all('SELECT * FROM ' . YxDb::table('app') . ' ORDER BY featured DESC, id DESC');
$installs = YxDb::all(
    'SELECT i.*, a.name AS app_name, c.username FROM ' . YxDb::table('app_install') . ' i
     LEFT JOIN ' . YxDb::table('app') . ' a ON a.id = i.app_id
     LEFT JOIN ' . YxDb::table('customer') . ' c ON c.id = i.customer_id
     ORDER BY i.id DESC LIMIT 100'
);

$pageTitle  = '商店管理';
$activeMenu = 'store';

/*
 * 发行包分发状态（只读）
 *
 * 官方主程序包有两个副本，本卡片把它们的状态并排展示，避免「以为已发布、
 * 其实某个通道没文件」这类静默失败：
 *   uploads/ 副本 → 前台客户下载通道（api/store_download.php，按购买权益放行）
 *   dist/ 副本   → 后台受控分发通道（admin/release_download.php，仅管理员）
 * 只输出大小/时间/指纹，绝不输出绝对路径与任何密钥。
 */
$releaseCards = [];
$officialPkgs = YxDb::all(
    'SELECT * FROM ' . YxDb::table('app') . " WHERE author_type = 'official' AND type = 'package' ORDER BY id ASC"
);
foreach ($officialPkgs as $p) {
    $distFile   = YxStore::releaseFile($p);      // 受控目录（站点根目录之外）
    $publicFile = YxStore::packageFile($p);      // uploads/ 副本（前台下载用）

    $releaseCards[] = [
        'app'        => $p,
        'kind'       => YxStore::packageKind($p),
        'dist'       => $distFile,
        'public'     => $publicFile,
        'dist_size'  => $distFile ? (int)@filesize($distFile) : 0,
        'dist_mtime' => $distFile ? (int)@filemtime($distFile) : 0,
        'pub_size'   => $publicFile ? (int)@filesize($publicFile) : 0,
        'pub_mtime'  => $publicFile ? (int)@filemtime($publicFile) : 0,
    ];
}
/* 两份副本是否一致：同大小即视为同步（不做全量哈希，避免每次打开后台都读 6MB） */
$releaseInSync = true;
foreach ($releaseCards as $c) {
    if (!$c['dist'] || !$c['public'] || $c['dist_size'] !== $c['pub_size']) {
        $releaseInSync = false;
        break;
    }
}

require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="cat-bar">
    <a class="<?= $tab === 'apps' ? 'active' : '' ?>" href="<?= e(url('admin/store.php?tab=apps')) ?>">应用列表（<?= count($apps) ?>）</a>
    <a class="<?= $tab === 'cats' ? 'active' : '' ?>" href="<?= e(url('admin/store.php?tab=cats')) ?>">分类管理（<?= count($cats) ?>）</a>
    <a class="<?= $tab === 'installs' ? 'active' : '' ?>" href="<?= e(url('admin/store.php?tab=installs')) ?>">安装记录（<?= count($installs) ?>）</a>
    <form method="post" style="margin-left:auto">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync">
        <button class="btn btn-ghost btn-sm" type="submit">从远端源同步</button>
    </form>
</div>

<?php if ($tab === 'cats'): ?>
    <div class="grid-2">
        <div class="card">
            <h2><?= $catEditing ? '编辑分类' : '新增分类' ?></h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_cat">
                <input type="hidden" name="cid" value="<?= (int)($catEditing['id'] ?? 0) ?>">
                <div class="grid-2">
                    <label>分类标识 <input type="text" name="ckey" required value="<?= e($catEditing['key'] ?? '') ?>" placeholder="pay"></label>
                    <label>分类名称 <input type="text" name="cname" required value="<?= e($catEditing['name'] ?? '') ?>" placeholder="支付通道"></label>
                    <label>图标（emoji 或文字） <input type="text" name="cicon" value="<?= e($catEditing['icon'] ?? '') ?>"></label>
                    <label>排序 <input type="number" name="csort" value="<?= e($catEditing['sort'] ?? 0) ?>"></label>
                </div>
                <label>分类说明 <input type="text" name="cdesc" value="<?= e($catEditing['desc'] ?? '') ?>"></label>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= $catEditing ? '保存修改' : '创建分类' ?></button>
                    <?php if ($catEditing): ?><a class="btn btn-ghost" href="<?= e(url('admin/store.php?tab=cats')) ?>">取消</a><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="card">
            <h2>分类列表</h2>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>标识</th><th>名称</th><th>应用数</th><th>排序</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($cats as $c): ?>
                    <tr>
                        <td class="mono"><?= e($c['key']) ?></td>
                        <td><?= e($c['icon']) ?> <?= e($c['name']) ?></td>
                        <td><?= (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('app') . ' WHERE category_key = ?', [$c['key']]) ?></td>
                        <td><?= (int)$c['sort'] ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/store.php?tab=cats&catedit=' . (int)$c['id'])) ?>">编辑</a>
                                <form method="post" onsubmit="return confirm('确认删除该分类？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_cat">
                                    <input type="hidden" name="cid" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-sm btn-bad" type="submit">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>

<?php elseif ($tab === 'installs'): ?>
    <div class="card">
        <h2>安装记录</h2>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>应用</th><th>客户</th><th>绑定域名</th><th>授权码</th><th>到期</th><th>状态</th><th>安装时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($installs as $i): ?>
                <tr>
                    <td><?= e($i['app_name'] ?: ('#' . $i['app_id'])) ?></td>
                    <td><?= e($i['username'] ?: '-') ?></td>
                    <td class="mono small"><?= e($i['domain']) ?></td>
                    <td class="mono small"><?= e($i['license_key']) ?></td>
                    <td><?= e($i['expire_at'] ?: '-') ?></td>
                    <td><?= $i['status'] === 'enabled' ? '<span class="badge badge-ok">已启用</span>' : '<span class="badge badge-mute">已停用</span>' ?></td>
                    <td class="small muted"><?= e($i['installed_at']) ?></td>
                    <td>
                        <?php if ($i['status'] === 'enabled'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="uninstall">
                            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                            <button class="btn btn-sm btn-bad" type="submit">停用</button>
                        </form>
                        <?php else: ?><span class="muted small">-</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$installs): ?><tr><td colspan="8" class="empty">暂无安装记录</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

<?php else: ?>
    <?php if ($releaseCards): ?>
    <div class="card">
        <div class="section-head">
            <h2>发行包分发</h2>
            <span class="badge <?= $releaseInSync ? 'badge-ok' : 'badge-bad' ?>"><?= $releaseInSync ? '两份副本已同步' : '副本不一致，请重新发布' ?></span>
        </div>
        <p class="muted small" style="margin:0 0 10px">
            客户下载走 <code>uploads/</code> 副本（按购买权益放行）；此处「受控分发」直取站点根目录之外的
            <code>dist/</code> 副本（仅管理员可取，前台无直链）。两者内容应保持一致。
        </p>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>SKU</th><th>应用</th><th>版本</th><th>受控副本（dist）</th><th>客户副本（uploads）</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($releaseCards as $c): ?>
                <?php
                $kindName = ['open' => '开源版', 'license' => '安装授权包', 'program' => '正版程序包'][$c['kind']] ?? $c['kind'];
                ?>
                <tr>
                    <td><span class="badge badge-info"><?= e($kindName) ?></span></td>
                    <td><?= e($c['app']['name']) ?></td>
                    <td class="mono small"><?= e($c['app']['version']) ?></td>
                    <td>
                        <?php if ($c['dist']): ?>
                            <span class="badge badge-ok">就绪</span>
                            <span class="muted small"><?= e(number_format($c['dist_size'] / 1048576, 2)) ?> MB · <?= e(date('Y-m-d H:i', $c['dist_mtime'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-mute">缺失</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['public']): ?>
                            <span class="badge badge-ok">就绪</span>
                            <span class="muted small"><?= e(number_format($c['pub_size'] / 1048576, 2)) ?> MB · <?= e(date('Y-m-d H:i', $c['pub_mtime'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-bad">缺失（客户无法下载）</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['dist']): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/release_download.php?id=' . (int)$c['app']['id'])) ?>">下载受控副本</a>
                        <?php else: ?>
                            <span class="muted small">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <details style="margin-top:14px">
            <summary style="cursor:pointer;color:var(--primary)">上传发行包</summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_package">
                <div class="grid-2">
                    <label>目标商品
                        <select name="id" required>
                            <?php foreach ($officialPkgs as $p): ?>
                                <?php $pKind = ['open' => '开源版', 'license' => '安装授权包', 'program' => '正版程序包'][YxStore::packageKind($p)] ?? YxStore::packageKind($p); ?>
                                <option value="<?= (int)$p['id'] ?>"><?= e($pKind . ' · ' . $p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>发行文件（.zip / .zip.enc，同名覆盖）
                        <input type="file" name="package_file" accept=".enc,.zip" required>
                    </label>
                </div>
                <p class="muted small" style="margin:0 0 10px">
                    上传后写入 <code>uploads/license/</code> 并更新该商品的 <code>package_path</code> 与包大小；
                    <code>dist/</code> 受控副本不可写时需运维同步。当前 PHP 上传上限：
                    <code><?= e((string)ini_get('upload_max_filesize')) ?></code> / 表单 <code><?= e((string)ini_get('post_max_size')) ?></code>。
                </p>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">上传并绑定</button>
                </div>
            </form>
        </details>
    </div>
    <?php endif; ?>

    <div class="card">
        <details <?= $editing ? 'open' : '' ?>>
            <summary style="cursor:pointer;color:var(--primary)"><?= $editing ? '编辑应用：' . e($editing['name']) : '＋ 上架新应用' ?></summary>
            <form method="post" style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_app">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="grid-3">
                    <label>应用标识 <input type="text" name="key" required value="<?= e($editing['key'] ?? '') ?>" placeholder="cloud-vps"></label>
                    <label>应用名称 <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
                    <label>分类
                        <select name="category_key">
                            <?php foreach ($cats as $c): ?>
                                <option value="<?= e($c['key']) ?>" <?= ($editing['category_key'] ?? 'other') === $c['key'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>版本号 <input type="text" name="version" value="<?= e($editing['version'] ?? '1.0.0') ?>"></label>
                    <label>作者 <input type="text" name="author" value="<?= e($editing['author'] ?? '') ?>"></label>
                    <label>价格（0 为免费） <input type="number" step="0.01" min="0" name="price" value="<?= e($editing['price'] ?? '0.00') ?>"></label>
                    <label>支持语言 <input type="text" name="language" value="<?= e($editing['language'] ?? '简体中文') ?>" placeholder="简体中文 / 简体中文、English"></label>
                    <label>上架时间 <input type="date" name="published_at" value="<?= e(!empty($editing['published_at']) ? date('Y-m-d', strtotime((string)$editing['published_at'])) : date('Y-m-d')) ?>"></label>
                    <label>收费形式
                        <select name="bill_type">
                            <?php foreach (['free' => '免费', 'once' => '一次性', 'year' => '按年', 'points' => '积分兑换'] as $bk => $bv): ?>
                                <option value="<?= e($bk) ?>" <?= ($editing['bill_type'] ?? 'free') === $bk ? 'selected' : '' ?>><?= e($bv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>积分价格（收费形式=积分兑换时） <input type="number" min="0" name="points_price" value="<?= e($editing['points_price'] ?? 0) ?>"></label>
                    <label>最低适用版本
                        <select name="edition_min">
                            <?php foreach (YxEdition::EDITIONS as $k => $ed): ?>
                                <option value="<?= e($k) ?>" <?= ($editing['edition_min'] ?? 'basic') === $k ? 'selected' : '' ?>><?= e($ed['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>兼容性说明 <input type="text" name="compatible" value="<?= e($editing['compatible'] ?? '') ?>" placeholder=">= 1.0.0"></label>
                    <label>状态
                        <select name="status">
                            <option value="on" <?= ($editing['status'] ?? 'on') === 'on' ? 'selected' : '' ?>>上架</option>
                            <option value="off" <?= ($editing['status'] ?? '') === 'off' ? 'selected' : '' ?>>下架</option>
                        </select>
                    </label>
                </div>
                <label>下载地址 / 安装包 <input type="text" name="source" value="<?= e($editing['source'] ?? '') ?>" placeholder="https://... 或留空由远端分发"></label>
                <label>应用简介 <textarea name="description"><?= e($editing['description'] ?? '') ?></textarea></label>
                <label>应用设置（可选加价项，JSON：<code>[{"name":"多站点授权","price":100}]</code>；price 为在基础价上加收的金额）
                    <textarea name="specs" class="mono" rows="4" placeholder='[{"name":"多站点授权","price":100}]'><?= e($editing ? json_encode(YxServiceCenter::appSpecs($editing), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '') ?></textarea>
                </label>
                <label>按授权版本报价（JSON：<code>[{"edition":"basic","price":199,"bill_type":"year"}]</code>；
                    edition 取 basic / pro / enterprise，price 为该版本实际售价，bill_type 取 free / once / year）
                    <textarea name="price_options" class="mono" rows="5" placeholder='[{"edition":"basic","price":199,"bill_type":"year"},{"edition":"pro","price":299,"bill_type":"year"},{"edition":"enterprise","price":399,"bill_type":"year"}]'><?= e($editing ? json_encode(YxStore::options($editing), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '') ?></textarea>
                </label>
                <label>官方发行包（受控分发，仅 <code>type=package</code> 且 <code>author_type</code>=official 的条目生效）
                    <input type="text" name="package_path" value="<?= e($editing['package_path'] ?? '') ?>" placeholder="license/youxiang-finance-1.6.4-license.zip">
                </label>
                <?php if ($editing && YxStore::hasMultiPrice($editing)): ?>
                    <p class="muted small">当前生效报价：<?php
                        $txt = [];
                        foreach (YxStore::quotes($editing) as $q) {
                            $txt[] = $q['label'] . ' ' . ($q['bill_type'] === 'free' ? '免费' : '¥' . money($q['price'], false) . ($q['bill_type'] === 'year' ? '/年' : ''));
                        }
                        echo e(implode(' · ', $txt));
                    ?></p>
                <?php endif; ?>
                <label>更新日志 <textarea name="changelog"><?= e($editing['changelog'] ?? '') ?></textarea></label>
                <label class="checkbox"><input type="checkbox" name="featured" value="1" <?= !empty($editing['featured']) ? 'checked' : '' ?>> 设为推荐（首页展示）</label>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= $editing ? '保存修改' : '上架应用' ?></button>
                    <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/store.php?tab=apps')) ?>">取消</a><?php endif; ?>
                </div>
            </form>
        </details>
    </div>

    <div class="card">
        <div class="section-head">
            <h2>应用列表（共 <?= count($apps) ?> 个）</h2>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('store/index.php')) ?>" target="_blank">查看前台商店 →</a>
        </div>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>应用</th><th>标识</th><th>分类</th><th>版本</th><th>价格</th><th>最低版本</th><th>下载 / 销量</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($apps as $a): ?>
                <?php $multiOpt = YxStore::hasMultiPrice($a); ?>
                <tr>
                    <td><?= $a['featured'] ? '<span class="badge badge-info">推荐</span> ' : '' ?><?= e($a['name']) ?></td>
                    <td class="mono small"><?= e($a['key']) ?></td>
                    <td><?= e($catMap[$a['category_key']] ?? $a['category_key']) ?></td>
                    <td><?= e($a['version']) ?></td>
                    <td>
                        <?= e(YxBusiness::priceLabel($a)) ?>
                        <?php if ($multiOpt): ?><span class="badge badge-ok" title="按授权版本差异化报价">分版本</span><?php endif; ?>
                    </td>
                    <td><?= e(YxEdition::name($a['edition_min'])) ?></td>
                    <td><?= (int)$a['downloads'] ?> / <?= (int)$a['sales'] ?></td>
                    <td><?= $a['status'] === 'on' ? '<span class="badge badge-ok">上架</span>' : '<span class="badge badge-mute">下架</span>' ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/store.php?tab=apps&edit=' . (int)$a['id'])) ?>">编辑</a>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_app">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm" type="submit"><?= $a['status'] === 'on' ? '下架' : '上架' ?></button>
                            </form>
                            <form method="post" onsubmit="return confirm('确认删除该应用？')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_app">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm btn-bad" type="submit">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$apps): ?><tr><td colspan="9" class="empty">暂无应用</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
