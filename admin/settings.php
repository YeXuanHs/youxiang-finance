<?php
/**
 * 优享财务系统 · 站务管理
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
yx_require_installed();
$admin = YxAuth::requireAdmin();

$FIELDS = [
    'site_name'             => ['系统名称', 'text'],
    'site_sub'              => ['副标题', 'text'],
    'site_url'              => ['站点根地址（留空自动识别）', 'text'],
    'site_domain'           => ['站点授权域名（定时任务校验授权用，如 www.example.com）', 'text'],
    'icp'                   => ['ICP 备案号（工信部备案）', 'text'],
    'gongan'                => ['公安备案号（如：滇公网安备53010202000000号）', 'text'],
    'contact_qq'            => ['客服 QQ', 'text'],
    'contact_email'         => ['客服邮箱', 'text'],
    'notice'                => ['全局公告', 'textarea'],
    'renew_notice_days'     => ['到期提醒提前天数', 'number'],
    'renew_ahead_days'      => ['自动续费提前天数（0 = 到期当天才扣费）', 'number'],
    'commission_rate'       => ['默认渠道佣金比例（%）', 'number'],
    'pay_gateway_url'       => ['支付网关地址（如 https://pay.example.com）', 'text'],
    'pay_pid'               => ['支付商户号（pid）', 'text'],
    'pay_key'               => ['支付商户密钥（key，留空不修改）', 'password'],
    'pay_mode'              => ['支付下单模式', 'select'],
    'offline_enabled'       => ['启用银联卡转账（线下付款）', 'checkbox'],
    'offline_type'          => ['收款账户类型', 'offline_type'],
    'offline_name'          => ['收款户名（公司名称 / 个人姓名，前台原样展示）', 'text'],
    'offline_card'          => ['银行卡号', 'text'],
    'offline_bank'          => ['开户银行（如 中国工商银行）', 'text'],
    'offline_branch'        => ['开户支行（可选）', 'text'],
    'offline_contact'       => ['转账咨询联系方式（可选，如 QQ / 微信号）', 'text'],
    'offline_note'          => ['转账说明（展示在收款信息下方）', 'textarea'],
    'cron_token'            => ['定时任务令牌（cron.php 调用凭证）', 'text'],
    'video_title'           => ['首页功能视频介绍 · 标题', 'text'],
    'video_sub'             => ['首页功能视频介绍 · 副标题（可选）', 'text'],
    'video_url'             => ['首页功能视频地址（MP4 直链或 uploads/ 相对路径，留空则只展示文字介绍）', 'text'],
    'video_poster'          => ['首页功能视频封面图（可选，uploads/ 相对路径或图片直链）', 'text'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        foreach ($FIELDS as $key => $meta) {
            // 勾选框未勾选时不会出现在 POST 中，必须显式写 0，否则无法关闭
            if ($meta[1] === 'checkbox') {
                set_setting($key, !empty($_POST[$key]) ? '1' : '0');
                continue;
            }
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $raw = trim((string)$_POST[$key]);
            // 密钥类字段留空表示保留原值
            if ($meta[1] === 'password' && $raw === '') {
                continue;
            }
            if ($meta[1] === 'offline_type') {
                $raw = $raw === 'personal' ? 'personal' : 'company';
            }
            if ($key === 'offline_card') {
                // 卡号仅保留数字与分隔符，去掉误粘贴的空格 / 全角字符
                $raw = preg_replace('/[^0-9]/', '', $raw);
            }
            set_setting($key, $raw);
        }
        set_setting('license_enforce', !empty($_POST['license_enforce']) ? '1' : '0');
        set_setting('auto_renew', !empty($_POST['auto_renew']) ? '1' : '0');
        // 锁定为开源版的发行包：不读取提交的模式，避免缺字段时把 community 悄悄改回 official
        $modeLocked = YxLicense::modeLocked();
        $modeRejected = false;
        if ($modeLocked) {
            YxLicense::setMode('community');
        } else {
            $modeRejected = !YxLicense::setMode(($_POST['license_mode'] ?? 'official') === 'community' ? 'community' : 'official');
        }
        YxLicense::flushCache();
        log_action('setting.save');
        flash($modeRejected ? '当前为开源版发行包，授权模式已锁定为「开源版」，不可切换正式版。' : '设置已保存', $modeRejected ? 'error' : 'success');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'upload_license') {
        if (empty($_FILES['license_img']) || (int)$_FILES['license_img']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('请选择要上传的营业执照图片', 'error');
            redirect(url('admin/settings.php'));
        }
        $up = yx_upload($_FILES['license_img'], [
            'sub'      => 'license',
            'prefix'   => 'license',
            'exts'     => ['jpg', 'jpeg', 'png', 'webp'],
            'mimes'    => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size' => 5 * 1024 * 1024,
        ]);
        if (!$up['ok']) {
            flash('上传失败：' . $up['msg'], 'error');
            redirect(url('admin/settings.php'));
        }
        $old = trim((string)setting('license_img', ''));
        if ($old !== '') {
            $oldFile = YX_ROOT . '/uploads/' . ltrim($old, '/');
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
        set_setting('license_img', $up['path']);
        log_action('setting.upload_license');
        flash('营业执照图片已更新');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'del_license') {
        $old = trim((string)setting('license_img', ''));
        if ($old !== '') {
            $oldFile = YX_ROOT . '/uploads/' . ltrim($old, '/');
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
        set_setting('license_img', '');
        log_action('setting.del_license');
        flash('营业执照图片已删除');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'gen_cron_token') {
        set_setting('cron_token', random_key(32));
        log_action('setting.gen_cron_token');
        flash('定时任务令牌已重新生成');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'save_wechat') {
        set_setting('wechat_show', !empty($_POST['wechat_show']) ? '1' : '0');
        set_setting('wechat_title', trim((string)($_POST['wechat_title'] ?? '')));
        set_setting('wechat_desc', trim((string)($_POST['wechat_desc'] ?? '')));
        $titles = (array)($_POST['wechat_item_title'] ?? []);
        $descs  = (array)($_POST['wechat_item_desc'] ?? []);
        $list   = [];
        foreach (wechat_images() as $i => $item) {
            $item['title'] = trim((string)($titles[$i] ?? $item['title']));
            $item['desc']  = trim((string)($descs[$i] ?? $item['desc']));
            $list[] = $item;
        }
        set_setting('wechat_images', json_encode($list, JSON_UNESCAPED_UNICODE));
        log_action('setting.save_wechat');
        flash('公众号展示已保存');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'upload_wechat') {
        if (empty($_FILES['wechat_img']) || (int)$_FILES['wechat_img']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('请选择要上传的公众号图片', 'error');
            redirect(url('admin/settings.php'));
        }
        $up = yx_upload($_FILES['wechat_img'], [
            'sub'      => 'wechat',
            'prefix'   => 'wechat',
            'exts'     => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'mimes'    => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'max_size' => 5 * 1024 * 1024,
        ]);
        if (!$up['ok']) {
            flash('上传失败：' . $up['msg'], 'error');
            redirect(url('admin/settings.php'));
        }
        $list   = wechat_images();
        $list[] = [
            'img'   => $up['path'],
            'title' => trim((string)($_POST['wechat_new_title'] ?? '')),
            'desc'  => trim((string)($_POST['wechat_new_desc'] ?? '')),
        ];
        set_setting('wechat_images', json_encode($list, JSON_UNESCAPED_UNICODE));
        log_action('setting.upload_wechat', $up['path']);
        flash('公众号图片已上传');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'del_wechat') {
        $idx  = (int)($_POST['index'] ?? -1);
        $list = wechat_images();
        if (isset($list[$idx])) {
            $file = YX_ROOT . '/uploads/' . ltrim($list[$idx]['img'], '/');
            if (is_file($file)) {
                @unlink($file);
            }
            array_splice($list, $idx, 1);
            set_setting('wechat_images', json_encode($list, JSON_UNESCAPED_UNICODE));
            log_action('setting.del_wechat', $list ? 'index ' . $idx : 'last');
            flash('公众号图片已删除');
        } else {
            flash('图片不存在', 'error');
        }
        redirect(url('admin/settings.php'));
    }

    if ($action === 'upload_logo') {
        if (empty($_FILES['logo_img']) || (int)$_FILES['logo_img']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('请选择要上传的 LOGO 图片', 'error');
            redirect(url('admin/settings.php'));
        }
        $up = yx_upload($_FILES['logo_img'], [
            'sub'      => 'logo',
            'prefix'   => 'logo',
            'exts'     => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'mimes'    => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'max_size' => 2 * 1024 * 1024,
        ]);
        if (!$up['ok']) {
            flash('上传失败：' . $up['msg'], 'error');
            redirect(url('admin/settings.php'));
        }
        $old = yx_logo_upload_path();
        set_setting('site_logo', $up['path']);
        // 旧图仅清理 uploads/logo 下的自有文件，内置图片与目录文件不动
        if ($old !== '' && strpos($old, 'logo/') === 0) {
            $oldFile = YX_ROOT . '/uploads/' . $old;
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
        log_action('setting.upload_logo', $up['path']);
        flash('站点 LOGO 已更新，登录 / 注册页即时生效');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'del_logo') {
        $old = yx_logo_upload_path();
        if ($old !== '' && strpos($old, 'logo/') === 0) {
            $oldFile = YX_ROOT . '/uploads/' . $old;
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
        set_setting('site_logo', '');
        log_action('setting.del_logo');
        flash('已恢复为内置 LOGO');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'save_logo_wall') {
        set_setting('logo_wall_show', !empty($_POST['logo_wall_show']) ? '1' : '0');
        set_setting('logo_wall_title', trim((string)($_POST['logo_wall_title'] ?? '')));
        $names = (array)($_POST['wall_name'] ?? []);
        $list  = [];
        foreach (yx_wall_logos() as $i => $item) {
            // 目录文件不写入设置表，其名称由文件名决定
            if ($item['src'] !== 'db') {
                continue;
            }
            $item['name'] = trim((string)($names[$i] ?? $item['name']));
            unset($item['src']);
            $list[] = $item;
        }
        set_setting('logo_wall_images', json_encode($list, JSON_UNESCAPED_UNICODE));
        log_action('setting.save_logo_wall');
        flash('LOGO 墙设置已保存');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'upload_logo_wall') {
        if (empty($_FILES['wall_img']) || (int)$_FILES['wall_img']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('请选择要上传的 LOGO 图片', 'error');
            redirect(url('admin/settings.php'));
        }
        $up = yx_upload($_FILES['wall_img'], [
            'sub'      => 'logo-wall',
            'prefix'   => 'wall',
            'exts'     => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'mimes'    => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'max_size' => 2 * 1024 * 1024,
        ]);
        if (!$up['ok']) {
            flash('上传失败：' . $up['msg'], 'error');
            redirect(url('admin/settings.php'));
        }
        $list   = [];
        foreach (yx_wall_logos() as $item) {
            if ($item['src'] !== 'db') {
                continue;
            }
            unset($item['src']);
            $list[] = $item;
        }
        $list[] = [
            'img'  => $up['path'],   // 相对 uploads/，读取时由 yx_wall_logos() 补全前缀
            'name' => trim((string)($_POST['wall_new_name'] ?? '')),
        ];
        set_setting('logo_wall_images', json_encode($list, JSON_UNESCAPED_UNICODE));
        log_action('setting.upload_logo_wall', $up['path']);
        flash('LOGO 墙图片已上传');
        redirect(url('admin/settings.php'));
    }

    if ($action === 'del_logo_wall') {
        $idx  = (int)($_POST['index'] ?? -1);
        $list = [];
        foreach (yx_wall_logos() as $item) {
            // 目录文件不写入设置表，删除请直接删服务器文件
            if ($item['src'] !== 'db') {
                continue;
            }
            unset($item['src']);
            $list[] = $item;
        }
        if (isset($list[$idx])) {
            $file = YX_ROOT . '/' . $list[$idx]['img'];
            if (is_file($file)) {
                @unlink($file);
            }
            array_splice($list, $idx, 1);
            // 回写时 img 恢复为相对 uploads/ 的路径
            foreach ($list as $k => $item) {
                $list[$k]['img'] = preg_replace('#^uploads/#', '', $item['img']);
            }
            set_setting('logo_wall_images', json_encode($list, JSON_UNESCAPED_UNICODE));
            log_action('setting.del_logo_wall', 'index ' . $idx);
            flash('LOGO 墙图片已删除');
        } else {
            flash('图片不存在', 'error');
        }
        redirect(url('admin/settings.php'));
    }

    if ($action === 'save_sms') {
        // 兼容旧版「演示模式」提交：旧表单只提交 sms_mock，新表单提交 sms_enabled + sms_demo
        $enabled = !empty($_POST['sms_enabled']);
        set_setting('sms_enabled', $enabled ? '1' : '0');
        $demo = !empty($_POST['sms_demo']) || !empty($_POST['sms_mock']);
        set_setting('sms_demo', $demo ? '1' : '0');
        set_setting('sms_mock', '0'); // 统一到 sms_demo，避免两个开关打架
        $driver = (string)($_POST['sms_driver'] ?? 'generic');
        set_setting('sms_driver', in_array($driver, ['generic', 'aliyun', 'tencent'], true) ? $driver : 'generic');
        set_setting('sms_api_url', trim((string)($_POST['sms_api_url'] ?? '')));
        set_setting('sms_account', trim((string)($_POST['sms_account'] ?? '')));
        set_setting('sms_sign', trim((string)($_POST['sms_sign'] ?? '')));
        foreach (['sms_aliyun_template_code', 'sms_aliyun_region', 'sms_tencent_sdk_appid', 'sms_tencent_template_id', 'sms_tencent_appid', 'sms_tencent_region'] as $k) {
            if (array_key_exists($k, $_POST)) {
                set_setting($k, trim((string)$_POST[$k]));
            }
        }
        $tpl = trim((string)($_POST['sms_template'] ?? ''));
        if ($tpl === '') {
            $tpl = '您的验证码为 {code}，5 分钟内有效。如非本人操作请忽略。';
        }
        // 模板必须包含 {code}，否则用户收到的短信没有验证码
        if (strpos($tpl, '{code}') === false) {
            $tpl .= ' 验证码：{code}';
        }
        set_setting('sms_template', $tpl);
        // 密钥留空表示保留原值
        $pwd = (string)($_POST['sms_password'] ?? '');
        if ($pwd !== '') {
            set_setting('sms_password', $pwd);
        }
        log_action('setting.save_sms', 'driver=' . setting('sms_driver', 'generic') . ' enabled=' . setting('sms_enabled', '0') . ' demo=' . setting('sms_demo', '0'));
        flash('短信验证设置已保存');
        redirect(url('admin/settings.php#sms'));
    }

    if ($action === 'test_sms') {
        // 只用已保存的配置试发：测试表单不提交任何配置字段，
        // 避免「测试」把表单里的半成品配置写进设置，也避免覆盖已存密钥
        $res = YxSms::test((string)($_POST['test_phone'] ?? ''));
        log_action('setting.test_sms', $res['ok'] ? 'ok' : 'fail');
        flash(($res['ok'] ? '测试短信已发送：' : '测试发送失败：') . $res['msg'], $res['ok'] ? 'success' : 'error');
        redirect(url('admin/settings.php#sms'));
    }

    if ($action === 'save_mail') {
        set_setting('mail_enabled', !empty($_POST['mail_enabled']) ? '1' : '0');
        set_setting('mail_demo', !empty($_POST['mail_demo']) ? '1' : '0');
        $secure = (string)($_POST['smtp_secure'] ?? 'ssl');
        set_setting('smtp_secure', in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'ssl');
        $port = (int)($_POST['smtp_port'] ?? 465);
        set_setting('smtp_port', (string)($port > 0 && $port < 65536 ? $port : 465));
        set_setting('smtp_host', trim((string)($_POST['smtp_host'] ?? '')));
        set_setting('smtp_user', trim((string)($_POST['smtp_user'] ?? '')));
        set_setting('smtp_from', trim((string)($_POST['smtp_from'] ?? '')));
        set_setting('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
        $pwd = (string)($_POST['smtp_pass'] ?? '');
        if ($pwd !== '') {
            set_setting('smtp_pass', $pwd);
        }
        log_action('setting.save_mail', 'enabled=' . setting('mail_enabled', '0') . ' demo=' . setting('mail_demo', '0'));
        flash('邮箱验证设置已保存');
        redirect(url('admin/settings.php#mail'));
    }

    if ($action === 'test_mail') {
        // 只用已保存的配置试发：测试表单不提交密码类字段，避免误把已存授权码覆盖成空
        $res = YxMail::test((string)($_POST['test_email'] ?? ''));
        log_action('setting.test_mail', $res['ok'] ? 'ok' : 'fail');
        flash(($res['ok'] ? '测试邮件已发送：' : '测试发送失败：') . $res['msg'], $res['ok'] ? 'success' : 'error');
        redirect(url('admin/settings.php#mail'));
    }

    if ($action === 'change_pass') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $rep = (string)($_POST['new_password2'] ?? '');
        if (!password_verify($old, $admin['password'])) {
            flash('原密码不正确', 'error');
        } elseif (strlen($new) < 8) {
            flash('新密码至少 8 位', 'error');
        } elseif ($new !== $rep) {
            flash('两次输入的新密码不一致', 'error');
        } else {
            YxDb::update('admin', ['password' => YxAuth::hash($new)], 'id = :id', ['id' => (int)$admin['id']]);
            log_action('admin.change_password', $admin['username']);
            flash('登录密码已更新');
        }
        redirect(url('admin/settings.php'));
    }
}

$enforce   = setting('license_enforce', '0') === '1';
$autoRenew = setting('auto_renew', '0') === '1';
$cronToken = setting('cron_token', '');
$cronCmd   = 'php ' . YX_ROOT . '/tools/cron.php';
$cronUrl   = url('tools/cron.php') . ($cronToken !== '' ? '?token=' . urlencode($cronToken) : '');

$pageTitle  = '站务管理';
$activeMenu = 'settings';
require dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="grid-2">
    <div class="card">
        <h2>基础设置</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <?php foreach ($FIELDS as $key => $meta): ?>
                <?php if ($meta[1] === 'textarea'): ?>
                    <label><?= e($meta[0]) ?><textarea name="<?= e($key) ?>"><?= e(setting($key, '')) ?></textarea></label>
                <?php elseif ($meta[1] === 'password'): ?>
                    <label><?= e($meta[0]) ?><?= setting($key, '') !== '' ? ' <span class="badge badge-ok">已配置</span>' : ' <span class="badge badge-mute">未配置</span>' ?>
                        <input type="password" name="<?= e($key) ?>" value="" autocomplete="new-password" placeholder="留空则不修改"></label>
                <?php elseif ($meta[1] === 'select'): ?>
                    <label><?= e($meta[0]) ?>
                        <select name="<?= e($key) ?>">
                            <option value="mapi" <?= YxPayment::mode() === 'mapi' ? 'selected' : '' ?>>站点内二维码（扫码支付，推荐）</option>
                            <option value="submit" <?= YxPayment::mode() === 'submit' ? 'selected' : '' ?>>跳转网关收银台</option>
                        </select>
                    </label>
                    <p class="muted small" style="margin-top:-6px">
                        网关需支持 <code>mapi.php</code> 接口（返回二维码链接）。选择站点内二维码时，用户停留本站扫码，支付结果由
                        <code>api/pay_notify.php</code> 异步回调入账；若网关拒绝该接口，会自动降级为跳转收银台。
                    </p>
                <?php elseif ($meta[1] === 'checkbox'): ?>
                    <label class="checkbox"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= setting($key, '0') === '1' ? 'checked' : '' ?>>
                        <?= e($meta[0]) ?></label>
                <?php elseif ($meta[1] === 'offline_type'): ?>
                    <label><?= e($meta[0]) ?>
                        <select name="<?= e($key) ?>">
                            <option value="company" <?= yx_offline_info()['type'] === 'company' ? 'selected' : '' ?>>公司账户（对公）</option>
                            <option value="personal" <?= yx_offline_info()['type'] === 'personal' ? 'selected' : '' ?>>个人账户（对私）</option>
                        </select>
                    </label>
                <?php else: ?>
                    <label><?= e($meta[0]) ?>
                        <input type="<?= $meta[1] === 'number' ? 'number' : 'text' ?>" name="<?= e($key) ?>" value="<?= e(setting($key, '')) ?>"<?= $key === 'offline_card' ? ' inputmode="numeric" autocomplete="off"' : '' ?>>
                    </label>
                    <?php if ($key === 'offline_card'): ?>
                        <p class="muted small" style="margin-top:-6px">
                            仅保存数字。启用并填好「收款户名 + 银行卡号」后，应用商店购买页与订单支付页会出现「银联卡转账」选项，
                            用户转账后在订单页点「我已转账」，管理员在
                            <a href="<?= e(url('admin/orders.php?status=awaiting')) ?>">订单管理 → 待确认转账</a> 核对到账并确认收款。
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php $licMode = YxLicense::mode(); $modeLocked = YxLicense::modeLocked(); ?>
            <?php if ($modeLocked): ?>
                <label>授权模式
                    <input type="text" readonly value="已锁定，不可切换">
                </label>
                <p class="muted small" style="margin-top:-6px">
                    本站点的授权模式已在配置中固定，后台不提供切换入口。
                </p>
            <?php else: ?>
                <label>授权模式
                    <select name="license_mode">
                        <option value="official" <?= $licMode === 'official' ? 'selected' : '' ?>>正式版（official）—— 按域名校验授权，展示正版 / 未授权结论</option>
                        <option value="community" <?= $licMode === 'community' ? 'selected' : '' ?>>离线模式（community）—— 本地给出授权结论，不联网</option>
                    </select>
                </label>
                <p class="muted small" style="margin-top:-6px">
                    离线模式不联网查询、不写校验日志、不做版本收敛，授权结论完全由本地给出。
                    如需按域名向授权中心校验，请选择正式版。
                </p>
            <?php endif; ?>
            <?php $credReady = YxLicense::hasCredentials(); ?>
            <div class="alert <?= $credReady ? 'alert-ok' : 'alert-warn' ?>" style="margin:12px 0">
                <strong>授权对接三要素：</strong>
                <?= $credReady ? '已配齐' : '未配齐' ?>（授权地址 + 授权ID + 授权密钥）。
                这三项不再在此处编辑，统一在
                <a href="<?= e(url('admin/licenses.php#credential')) ?>">授权中心 → 授权对接</a>
                中设置，并可「一键生成授权ID与密钥」或「测试对接」。
                <br>
                当前授权地址：<code><?= e(YxLicense::officialApi() !== '' ? YxLicense::officialApi() : '未配置') ?></code>
                ／ 授权ID：<code><?= e(YxLicense::appId() !== '' ? YxLicense::appId() : '未配置') ?></code>
                ／ 授权密钥：<?= YxLicense::channelSecret() !== '' ? '已配置' : '未配置' ?>
            </div>
            <label class="checkbox"><input type="checkbox" name="license_enforce" value="1" <?= $enforce ? 'checked' : '' ?>>
                未授权时强制收敛为「普惠版」（默认关闭，仅提示不限制）</label>
            <label class="checkbox"><input type="checkbox" name="auto_renew" value="1" <?= $autoRenew ? 'checked' : '' ?>>
                开启到期自动续费（定时任务执行，客户余额充足时自动扣费顺延）</label>
            <p class="muted small" style="margin-top:-6px">
                <strong>开启后会产生真实扣费</strong>：定时任务每次执行时，对「已到期或落在提前天数内」的业务，
                从客户余额扣除该业务价格并顺延一个周期，同时生成已结清账单与收支流水。<br>
                余额不足的业务会被跳过并保持原到期日，下次定时任务继续尝试；扣费失败不会产生任何账单。<br>
                每笔续费都会记入 <code>runtime/cron.log</code> 的自动续费明细，可在
                <a href="<?= e(url('admin/transactions.php')) ?>">收支流水</a> 与
                <a href="<?= e(url('admin/invoices.php')) ?>">账单中心</a> 核对。
            </p>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存设置</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>定时任务</h2>
        <p class="muted small">在宝塔面板「计划任务」中添加 Shell 脚本，每 10 分钟执行一次，用于到期标记、自动续费与授权巡检。</p>
        <label>Shell 命令
            <input type="text" readonly value="<?= e($cronCmd . ($cronToken !== '' ? ' --token=' . $cronToken : '')) ?>" onclick="this.select()">
        </label>
        <label>URL 触发（不支持 CLI 时可改用此方式）
            <input type="text" readonly value="<?= e($cronUrl) ?>" onclick="this.select()">
        </label>
        <form method="post" class="form-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="gen_cron_token">
            <button class="btn btn-ghost" type="submit"><?= $cronToken === '' ? '生成任务令牌' : '重新生成令牌' ?></button>
        </form>
    </div>

    <div class="card">
        <h2>修改我的密码</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_pass">
            <label>原密码 <input type="password" name="old_password" required autocomplete="current-password"></label>
            <label>新密码（≥8 位） <input type="password" name="new_password" required autocomplete="new-password"></label>
            <label>确认新密码 <input type="password" name="new_password2" required autocomplete="new-password"></label>
            <button class="btn btn-primary" type="submit">更新密码</button>
        </form>

        <h2 style="margin-top:24px">运行环境</h2>
        <table class="data">
            <tr><th>系统版本</th><td><?= e(config('app.version')) ?>（build <?= e(config('app.build')) ?>）</td></tr>
            <tr><th>PHP 版本</th><td><?= e(PHP_VERSION) ?></td></tr>
            <tr><th>数据库驱动</th><td><?= e(strtoupper(YxDb::driver())) ?></td></tr>
            <tr><th>当前版本</th><td><?= e(YxEdition::name()) ?></td></tr>
            <tr><th>当前域名</th><td class="mono"><?= e(current_domain()) ?></td></tr>
            <tr><th>授权状态</th><td>
                <span class="badge badge-<?= YxLicense::badge()['class'] === 'ok' ? 'ok' : 'bad' ?>"><?= e(YxLicense::badge()['text']) ?></span>
            </td></tr>
            <tr><th>curl 扩展</th><td><?= function_exists('curl_init') ? '已启用' : '未启用（将回退到 allow_url_fopen）' ?></td></tr>
            <tr><th>GD 扩展</th><td><?= function_exists('imagecreatetruecolor') ? '已启用' : '未启用（无法生成 PNG 版 Logo）' ?></td></tr>
        </table>
    </div>
</div>

<div class="card" id="sms">
    <h2>手机短信验证码</h2>
    <p class="muted small" style="margin-top:-6px">
        启用后，客户端注册页（<a href="<?= e(url('user/register.php')) ?>" target="_blank">user/register.php</a>）要求必须填写手机号并通过短信验证码校验；
        关闭后手机号仍为选填项。验证码 5 分钟有效、同一手机号 60 秒内只能获取一次、单个验证码最多尝试 5 次、每会话每小时最多 10 条。
    </p>
    <?php
    $smsOn     = YxSms::enabled();
    $smsDemo   = YxSms::mock();
    $smsIssue  = YxSms::configIssue();
    $smsDriver = YxSms::driver();
    ?>
    <p style="margin:0 0 12px">
        当前状态：
        <span class="badge badge-<?= $smsOn ? 'ok' : 'mute' ?>"><?= $smsOn ? '已开启' : '已关闭' ?></span>
        <span class="badge badge-<?= $smsDemo ? 'warn' : 'mute' ?>"><?= $smsDemo ? '演示模式' : '真实发送' ?></span>
        <span class="badge badge-mute"><?= e(YxSms::driverLabel()) ?></span>
        <span class="badge badge-<?= $smsIssue === '' ? 'ok' : 'bad' ?>"><?= $smsIssue === '' ? '通道可用' : '通道不可用' ?></span>
    </p>
    <?php if ($smsDemo): ?>
        <div class="alert alert-warn" style="margin:0 0 14px">
            <strong>演示模式：</strong>不会真实发送短信，验证码会直接显示在注册页提示中，并写入 <code>runtime/sms.log</code>。
            正式上线前请填写短信网关并对上接口，同时关闭演示模式，否则任何人都能看到验证码。
        </div>
    <?php elseif ($smsIssue !== ''): ?>
        <div class="alert alert-error" style="margin:0 0 14px">
            <strong>通道未就绪：<?= e($smsIssue) ?>。</strong>
            此状态下用户点「获取验证码」会直接失败并看到原因，不会再出现「提示已发送但实际没发出」。
        </div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_sms">
        <label class="checkbox"><input type="checkbox" name="sms_enabled" value="1" <?= $smsOn ? 'checked' : '' ?>>
            注册时启用手机号短信验证（手机号必填）</label>
        <label class="checkbox"><input type="checkbox" name="sms_demo" value="1" <?= $smsDemo ? 'checked' : '' ?>>
            演示模式（不真实发送，验证码回显在页面上，便于内网联调）</label>
        <label>短信通道
            <select name="sms_driver">
                <option value="aliyun" <?= $smsDriver === 'aliyun' ? 'selected' : '' ?>>阿里云短信（Dysmsapi，推荐）</option>
                <option value="tencent" <?= $smsDriver === 'tencent' ? 'selected' : '' ?>>腾讯云短信（SMS）</option>
                <option value="generic" <?= $smsDriver === 'generic' ? 'selected' : '' ?>>通用 JSON 网关（自建 / 第三方聚合网关）</option>
            </select>
        </label>
        <label><?= $smsDriver === 'aliyun' ? '阿里云 AccessKey ID' : ($smsDriver === 'tencent' ? '腾讯云 SecretId' : '网关账号 / AppKey') ?>
            <input type="text" name="sms_account" value="<?= e(setting('sms_account', '')) ?>" autocomplete="off">
        </label>
        <label><?= $smsDriver === 'aliyun' ? '阿里云 AccessKey Secret' : ($smsDriver === 'tencent' ? '腾讯云 SecretKey' : '网关密钥 / AppSecret') ?>
            <?= setting('sms_password', '') !== '' ? '<span class="badge badge-ok">已配置</span>' : '<span class="badge badge-mute">未配置</span>' ?>
            <input type="password" name="sms_password" value="" autocomplete="new-password" placeholder="留空则不修改">
        </label>
    <?php if ($smsDriver === 'aliyun'): ?>
        <label>阿里云短信模板 CODE
            <input type="text" name="sms_aliyun_template_code" value="<?= e(setting('sms_aliyun_template_code', '')) ?>" placeholder="SMS_123456789">
        </label>
        <label>阿里云地域 Endpoint
            <input type="text" name="sms_aliyun_region" value="<?= e(setting('sms_aliyun_region', 'cn-hangzhou')) ?>" placeholder="cn-hangzhou">
        </label>
        <p class="muted small" style="margin-top:-6px">
            模板需在阿里云控制台「短信服务 → 模板管理」审核通过，内容形如 <code>您的验证码为${code}，5 分钟内有效。</code>
            （阿里云使用 <code>${code}</code> 占位符，系统会自动传入 <code>code</code> 变量）。
            短信签名填控制台已审核的签名名称。请求使用 RPC V1 签名（HMAC-SHA1）。
        </p>
    <?php elseif ($smsDriver === 'tencent'): ?>
        <label>腾讯云短信 SdkAppId
            <input type="text" name="sms_tencent_sdk_appid" value="<?= e(setting('sms_tencent_sdk_appid', '')) ?>" placeholder="1400000000">
        </label>
        <label>腾讯云短信模板 ID
            <input type="text" name="sms_tencent_template_id" value="<?= e(setting('sms_tencent_template_id', '')) ?>" placeholder="1234567">
        </label>
        <label>腾讯云地域
            <input type="text" name="sms_tencent_region" value="<?= e(setting('sms_tencent_region', 'ap-guangzhou')) ?>" placeholder="ap-guangzhou">
        </label>
        <p class="muted small" style="margin-top:-6px">
            模板变量为单个 <code>{1}</code>，内容形如 <code>您的验证码为{1}，5 分钟内有效。</code>，系统会把验证码作为第 1 个变量传入。
            请求使用 TC3-HMAC-SHA256 签名。
        </p>
    <?php else: ?>
        <label>短信网关接口地址（POST JSON）
            <input type="text" name="sms_api_url" value="<?= e(setting('sms_api_url', '')) ?>" placeholder="https://sms.example.com/api/send">
        </label>
    <?php endif; ?>
        <label>短信签名
            <input type="text" name="sms_sign" value="<?= e(setting('sms_sign', '')) ?>" placeholder="如：优享财务">
        </label>
        <label>短信内容模板（{code} 为验证码占位符）
            <textarea name="sms_template" placeholder="您的验证码为 {code}，5 分钟内有效。"><?= e(setting('sms_template', '您的验证码为 {code}，5 分钟内有效。如非本人操作请忽略。')) ?></textarea>
        </label>
        <p class="muted small" style="margin-top:-6px">
            <?php if ($smsDriver === 'generic'): ?>
                请求体为 JSON：<code>account / password / phone / mobile / sign / content / code</code>。
                <strong>只有</strong>返回 <code>code=0</code>、<code>errcode=0</code>、<code>ret=0</code>、<code>success=true</code>、<code>ok=true</code>、
                <code>status=ok</code> 或纯文本 <code>ok / success</code> 才算成功；
                返回网页、空响应、格式无法识别或没有成功标记，都会按失败提示（取 <code>msg / message / errmsg / error</code>）。
            <?php else: ?>
                云厂商接口按官方签名协议调用，只有返回明确的成功标识（阿里云 <code>Code=OK</code> / 腾讯云 <code>SendStatusSet[0].Code=Ok</code>）才算发送成功。
                上方「短信内容模板」仅用于通用网关与日志展示，云厂商实际短信内容由控制台审核通过的模板决定。
            <?php endif; ?>
            演示模式与失败原因记录在 <code>runtime/sms.log</code>。
        </p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存短信设置</button>
        </div>
    </form>

    <h2 style="margin-top:24px">测试发送短信</h2>
    <p class="muted small" style="margin-top:-6px">
        使用当前已保存的配置直接试发一条，<strong>不受「启用」开关与发送频率限制</strong>；发送结果会明确显示成功或失败原因。
        请先在上方点「保存短信设置」再测试（修改通道或字段后务必先保存）。
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_sms">
        <label>接收手机号
            <input type="tel" name="test_phone" inputmode="numeric" maxlength="11" placeholder="11 位手机号">
        </label>
        <div class="form-actions">
            <button class="btn btn-ghost" type="submit">发送测试短信</button>
        </div>
    </form>
</div>

<div class="card" id="mail">
    <h2>邮箱验证码</h2>
    <p class="muted small" style="margin-top:-6px">
        启用后，客户端注册页要求填写邮箱并通过邮箱验证码校验（按钮在邮箱输入框右侧）；
        关闭后邮箱仍为必填但无需验证码。验证码 10 分钟有效、同一邮箱 60 秒内只能获取一次、单个验证码最多尝试 5 次。
    </p>
    <?php
    $mailOn    = YxMail::enabled();
    $mailDemo  = YxMail::demo();
    $mailIssue = YxMail::configIssue();
    ?>
    <p style="margin:0 0 12px">
        当前状态：
        <span class="badge badge-<?= $mailOn ? 'ok' : 'mute' ?>"><?= $mailOn ? '已开启' : '已关闭' ?></span>
        <span class="badge badge-<?= $mailDemo ? 'warn' : 'mute' ?>"><?= $mailDemo ? '演示模式' : '真实发送' ?></span>
        <span class="badge badge-<?= $mailIssue === '' ? 'ok' : 'bad' ?>"><?= $mailIssue === '' ? '通道可用' : '通道不可用' ?></span>
    </p>
    <?php if ($mailDemo): ?>
        <div class="alert alert-warn" style="margin:0 0 14px">
            <strong>演示模式：</strong>不会真实发信，验证码直接显示在注册页，建议仅用于联调。
        </div>
    <?php elseif ($mailIssue !== ''): ?>
        <div class="alert alert-error" style="margin:0 0 14px">
            <strong>通道未就绪：<?= e($mailIssue) ?>。</strong>
            请填写 SMTP 服务器与授权码（QQ 邮箱 / 163 邮箱需先在邮箱设置中开启 SMTP 并获取「授权码」，不是登录密码）。
        </div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_mail">
        <label class="checkbox"><input type="checkbox" name="mail_enabled" value="1" <?= $mailOn ? 'checked' : '' ?>>
            注册时启用邮箱验证码（邮箱必须验证）</label>
        <label class="checkbox"><input type="checkbox" name="mail_demo" value="1" <?= $mailDemo ? 'checked' : '' ?>>
            演示模式（不真实发信，验证码回显在页面上）</label>
        <label>SMTP 服务器
            <input type="text" name="smtp_host" value="<?= e(setting('smtp_host', '')) ?>" placeholder="smtp.qq.com / smtp.163.com">
        </label>
        <label>SMTP 端口
            <input type="number" name="smtp_port" value="<?= (int)YxMail::port() ?>" placeholder="465">
        </label>
        <label>加密方式
            <select name="smtp_secure">
                <option value="ssl" <?= YxMail::secure() === 'ssl' ? 'selected' : '' ?>>SSL（端口 465）</option>
                <option value="tls" <?= YxMail::secure() === 'tls' ? 'selected' : '' ?>>STARTTLS（端口 587 / 25）</option>
                <option value="none" <?= YxMail::secure() === 'none' ? 'selected' : '' ?>>不加密（仅内网邮件服务）</option>
            </select>
        </label>
        <label>SMTP 账号
            <input type="text" name="smtp_user" value="<?= e(setting('smtp_user', '')) ?>" autocomplete="off" placeholder="通常是完整邮箱地址">
        </label>
        <label>SMTP 授权码 / 密码
            <?= setting('smtp_pass', '') !== '' ? '<span class="badge badge-ok">已配置</span>' : '<span class="badge badge-mute">未配置</span>' ?>
            <input type="password" name="smtp_pass" value="" autocomplete="new-password" placeholder="留空则不修改">
        </label>
        <label>发件邮箱（留空取 SMTP 账号）
            <input type="email" name="smtp_from" value="<?= e(setting('smtp_from', '')) ?>" placeholder="noreply@example.com">
        </label>
        <label>发件人名称（留空取站点名称）
            <input type="text" name="smtp_from_name" value="<?= e(setting('smtp_from_name', '')) ?>" placeholder="<?= e(setting('site_name', '优享财务系统')) ?>">
        </label>
        <p class="muted small" style="margin-top:-6px">
            系统直接与 SMTP 服务器对话（支持 SSL / STARTTLS），无需服务器安装发信组件。
            发送结果与失败原因记录在 <code>runtime/mail.log</code>。
        </p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存邮箱设置</button>
        </div>
    </form>

    <h2 style="margin-top:24px">测试发送邮件</h2>
    <p class="muted small" style="margin-top:-6px">
        使用当前已保存的 SMTP 配置，向指定邮箱试发一封信，并明确回报成功或失败原因。
        修改配置后请先点上方「保存邮箱设置」再测试。
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_mail">
        <label>接收邮箱
            <input type="email" name="test_email" placeholder="接收测试邮件的邮箱">
        </label>
        <div class="form-actions">
            <button class="btn btn-ghost" type="submit">发送测试邮件</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>营业执照</h2>
    <p class="muted small" style="margin-top:-6px">上传后将展示在前台页脚，访客可点击查看大图。支持 JPG / PNG / WEBP，大小不超过 5MB。</p>
    <?php $licImg = trim((string)setting('license_img', '')); ?>
    <?php if ($licImg !== ''): ?>
        <div style="margin:10px 0">
            <a href="<?= e(yx_upload_url($licImg)) ?>" target="_blank" rel="noopener" title="点击查看大图">
                <img src="<?= e(yx_upload_url($licImg)) ?>" alt="营业执照"
                     style="max-width:260px;max-height:180px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:4px">
            </a>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_license">
        <label>营业执照图片
            <input type="file" name="license_img" accept="image/jpeg,image/png,image/webp" required>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $licImg !== '' ? '更换营业执照图片' : '上传营业执照图片' ?></button>
        </div>
    </form>
    <?php if ($licImg !== ''): ?>
    <form method="post" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="del_license">
        <button class="btn btn-ghost" type="submit" onclick="return confirm('确定删除营业执照图片吗？');">删除营业执照图片</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>站点 LOGO</h2>
    <p class="muted small" style="margin-top:-6px">
        站点 LOGO 展示在用户登录页与注册页（左侧品牌区），上传后即时生效。
        支持 JPG / PNG / GIF / WEBP，建议正方形、不超过 2MB，留空时使用内置 LOGO。
        也可直接在服务器上替换文件：<code>assets/logo/logo-mark.png</code>（或先在后台上传，再改 <code><?= e(setting('site_logo', '') !== '' ? 'uploads/' . setting('site_logo', '') : 'assets/logo/logo-mark.png') ?></code>）。
    </p>
    <?php $logoOwn = yx_logo_upload_path(); ?>
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px">
        <img src="<?= e(yx_logo_url()) ?>" alt="站点 LOGO"
             style="width:72px;height:72px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:10px;padding:6px">
        <span class="muted small">当前来源：<?= $logoOwn !== '' ? '后台上传（uploads/' . e($logoOwn) . '）' : '内置图片（assets/logo/logo-mark.png）' ?></span>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_logo">
        <label>LOGO 图片
            <input type="file" name="logo_img" accept="image/jpeg,image/png,image/gif,image/webp" required>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $logoOwn !== '' ? '更换 LOGO' : '上传 LOGO' ?></button>
        </div>
    </form>
    <?php if ($logoOwn !== ''): ?>
    <form method="post" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="del_logo">
        <button class="btn btn-ghost" type="submit" onclick="return confirm('确定恢复为内置 LOGO 吗？');">恢复内置 LOGO</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>LOGO 墙（登录 / 注册页）</h2>
    <p class="muted small" style="margin-top:-6px">
        展示在用户登录页与注册页的登录卡片上方，默认灰度显示、鼠标悬停恢复彩色。支持 JPG / PNG / GIF / WEBP，单张不超过 2MB。
        也可直接把图片放进服务器目录 <code>assets/logo/wall/</code>（文件名即名称，如 <code>aliyun-cloud.png</code>），无需后台操作即可生效。
    </p>
    <?php
    $wallLogos = yx_wall_logos();
    $wallShow  = setting('logo_wall_show', '1') !== '0';
    ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_logo_wall">
        <label>LOGO 图片
            <input type="file" name="wall_img" accept="image/jpeg,image/png,image/gif,image/webp" required>
        </label>
        <label>展示名称（可选，用于鼠标悬停提示） <input type="text" name="wall_new_name" placeholder="如：某云服务商"></label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">上传 LOGO</button>
        </div>
    </form>

    <form method="post" style="margin-top:16px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_logo_wall">
        <label class="checkbox"><input type="checkbox" name="logo_wall_show" value="1" <?= $wallShow ? 'checked' : '' ?>>
            在登录 / 注册页展示 LOGO 墙</label>
        <label>板块标题 <input type="text" name="logo_wall_title" value="<?= e(setting('logo_wall_title', '合作伙伴 · 服务网络')) ?>" placeholder="留空则不显示标题"></label>
        <?php if ($wallLogos): ?>
        <div class="grid-3">
            <?php foreach ($wallLogos as $i => $w): ?>
                <div class="card" style="margin:0;padding:12px">
                    <div style="height:52px;display:flex;align-items:center;justify-content:center;margin-bottom:8px">
                        <img src="<?= e(yx_wall_img_url($w['img'])) ?>" alt="" style="max-width:100%;max-height:52px;object-fit:contain">
                    </div>
                    <input type="text" name="wall_name[<?= $i ?>]" value="<?= e($w['name']) ?>" placeholder="展示名称"<?= $w['src'] === 'file' ? ' disabled' : '' ?>>
                    <p class="muted small" style="margin:6px 0 0">
                        <?= $w['src'] === 'file' ? '目录文件：assets/logo/wall/' . e(basename($w['img'])) : '后台上传' ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted small">目录 <code>assets/logo/wall/</code> 中的图片名称固定由文件名决定，删除文件即可移除。</p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存 LOGO 墙设置</button>
        </div>
        <?php else: ?>
        <p class="muted small">暂无 LOGO 图片，上传后即会展示在登录与注册页。</p>
        <?php endif; ?>
    </form>

    <?php
    $wallDb = array_values(array_filter($wallLogos, function ($w) { return $w['src'] === 'db'; }));
    if ($wallDb):
    ?>
    <div class="grid-3" style="margin-top:10px">
        <?php foreach ($wallDb as $i => $w): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="del_logo_wall">
                <input type="hidden" name="index" value="<?= $i ?>">
                <button class="btn btn-ghost btn-sm" type="submit"
                        onclick="return confirm('确定删除这张 LOGO 吗？');">删除 <?= e($w['name'] !== '' ? $w['name'] : basename($w['img'])) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>公众号展示</h2>
    <p class="muted small" style="margin-top:-6px">上传的图片与标题会以卡片形式展示在首页「公众号」板块，访客可扫码关注。支持 JPG / PNG / GIF / WEBP，单张不超过 5MB。</p>
    <?php $wxImgs = wechat_images(); $wxShow = setting('wechat_show', '1') !== '0'; ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_wechat">
        <label>公众号图片
            <input type="file" name="wechat_img" accept="image/jpeg,image/png,image/gif,image/webp" required>
        </label>
        <label>图片标题（可选） <input type="text" name="wechat_new_title" placeholder="如：官方公众号"></label>
        <label>图片说明（可选） <input type="text" name="wechat_new_desc" placeholder="如：扫码关注，获取上新与优惠通知"></label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">上传图片</button>
        </div>
    </form>

    <?php if ($wxImgs): ?>
    <form method="post" style="margin-top:16px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_wechat">
        <label class="checkbox"><input type="checkbox" name="wechat_show" value="1" <?= $wxShow ? 'checked' : '' ?>>
            在首页展示公众号板块</label>
        <label>板块标题 <input type="text" name="wechat_title" value="<?= e(setting('wechat_title', '关注公众号')) ?>" placeholder="关注公众号"></label>
        <label>板块说明（可选） <input type="text" name="wechat_desc" value="<?= e(setting('wechat_desc', '')) ?>" placeholder="扫码关注，第一时间获取产品上新与服务通知"></label>
        <div class="grid-3">
            <?php foreach ($wxImgs as $i => $w): ?>
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#fff">
                    <img src="<?= e(yx_upload_url($w['img'])) ?>" alt="<?= e($w['title'] ?: '公众号图片') ?>"
                         style="width:100%;height:150px;object-fit:contain;background:#f8f9fa;border-radius:6px">
                    <label>标题 <input type="text" name="wechat_item_title[<?= $i ?>]" value="<?= e($w['title']) ?>" placeholder="官方公众号"></label>
                    <label>说明 <input type="text" name="wechat_item_desc[<?= $i ?>]" value="<?= e($w['desc']) ?>" placeholder="扫码关注"></label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存公众号展示</button>
        </div>
    </form>

<div class="grid-3" style="margin-top:10px">
    <?php foreach ($wxImgs as $i => $w): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="del_wechat">
            <input type="hidden" name="index" value="<?= $i ?>">
            <button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('确定删除这张公众号图片吗？');">删除第 <?= $i + 1 ?> 张图片</button>
        </form>
    <?php endforeach; ?>
</div>
    <?php else: ?>
        <p class="muted small" style="margin-top:12px">还没有图片，上传后首页会自动出现「公众号」板块。</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>安全建议</h2>
    <ul class="tight muted">
        <li>安装完成后建议在服务器层面限制 <code>install/</code> 目录访问，或将其移出站点根目录。</li>
        <li><code>runtime/</code> 目录存放 SQLite 数据与缓存，请勿对外暴露。</li>
        <li>生产环境请关闭调试模式（includes/config.php → app.debug = false）。</li>
        <li>本系统所有数据库操作均使用预处理语句；所有写操作均校验 CSRF 令牌。</li>
    </ul>
</div>

<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
