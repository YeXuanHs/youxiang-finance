<?php
/**
 * 优享财务系统 · 认证与权限
 */

class YxAuth
{
    /* ------------------------------ 管理端 ------------------------------ */

    public static function attemptAdmin(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入账号与密码'];
        }

        $admin = YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE username = ? LIMIT 1', [$username]);
        if (!$admin) {
            return ['ok' => false, 'msg' => '账号或密码不正确'];
        }

        if (!empty($admin['lock_until']) && $admin['lock_until'] > now()) {
            return ['ok' => false, 'msg' => '账号已被临时锁定，请于 ' . $admin['lock_until'] . ' 后重试'];
        }

        if ((int)$admin['status'] !== 1) {
            return ['ok' => false, 'msg' => '该账号已被禁用'];
        }

        if (!password_verify($password, $admin['password'])) {
            $limit = (int)config('security.admin_login_limit', 5);
            $fails = (int)$admin['fail_count'] + 1;
            $data  = ['fail_count' => $fails];
            if ($fails >= $limit) {
                $data['lock_until'] = date('Y-m-d H:i:s', time() + (int)config('security.login_lock_seconds', 900));
                $data['fail_count'] = 0;
            }
            YxDb::update('admin', $data, 'id = :id', ['id' => $admin['id']]);
            return ['ok' => false, 'msg' => '账号或密码不正确'];
        }

        YxDb::update('admin', [
            'fail_count'    => 0,
            'lock_until'    => null,
            'last_login_at' => now(),
            'last_login_ip' => client_ip(),
        ], 'id = :id', ['id' => $admin['id']]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['admin_id']   = (int)$admin['id'];
        $_SESSION['admin_name'] = $admin['username'];
        $_SESSION['admin_time'] = time();

        return ['ok' => true, 'msg' => '登录成功'];
    }

    public static function admin()
    {
        static $admin = false;
        if ($admin !== false) {
            return $admin;
        }
        $id = (int)($_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            return $admin = null;
        }
        if (time() - (int)($_SESSION['admin_time'] ?? 0) > (int)config('security.session_ttl', 7200)) {
            self::logoutAdmin();
            return $admin = null;
        }
        $_SESSION['admin_time'] = time();
        return $admin = YxDb::one('SELECT * FROM ' . YxDb::table('admin') . ' WHERE id = ? LIMIT 1', [$id]);
    }

    public static function requireAdmin(): array
    {
        $admin = self::admin();
        if (!$admin) {
            redirect(url('admin/login.php'));
        }
        return $admin;
    }

    public static function logoutAdmin(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_time']);
    }

    /* --------------------------- 管理员角色与权限 --------------------------- */

    /**
     * 角色定义
     *
     * owner      超级管理员：全部权限，可管理其他管理员
     * operator   运营管理员：除「应用商店」「开发者中心」「管理员管理」外的全部权限
     *
     * 权限判定与版本门禁（YxEdition::can）是两件事：版本决定「这个版本有没有该功能」，
     * 角色决定「这个管理员能不能用」。页面同时受两者的约束。
     */
    const ROLES = [
        'owner'    => '超级管理员',
        'operator' => '运营管理员',
    ];

    /** 受限角色被禁止访问的能力（owner 永远不受限） */
    const DENIED = [
        'operator' => ['store', 'developer', 'admin_users'],
    ];

    /** 能力 => 中文名（用于提示与后台展示） */
    const PERMS = [
        'store'       => '应用商店',
        'developer'   => '开发者中心',
        'admin_users' => '管理员管理',
    ];

    public static function roleOf(array $admin): string
    {
        $role = (string)($admin['role'] ?? '');
        return isset(self::ROLES[$role]) ? $role : 'owner';
    }

    public static function roleName(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }

    /** 当前（或指定）管理员是否拥有某能力 */
    public static function canAdmin(string $perm, array $admin = null): bool
    {
        $admin = $admin ?? self::admin();
        if (!$admin) {
            return false;
        }
        $role = self::roleOf($admin);
        if ($role === 'owner') {
            return true;
        }
        return !in_array($perm, self::DENIED[$role] ?? [], true);
    }

    /**
     * 页面级能力拦截
     *
     * 无权限者：普通请求回后台首页并提示；AJAX 返回 403 JSON。
     * 这里不抛异常，避免半渲染的页面把权限细节暴露给前端。
     */
    public static function requireAdminPermission(string $perm, string $redirectTo = null): void
    {
        $admin = self::requireAdmin();
        if (self::canAdmin($perm, $admin)) {
            return;
        }
        $name = self::PERMS[$perm] ?? $perm;
        $msg  = sprintf('当前账号为「%s」，没有「%s」的访问权限。', self::roleName(self::roleOf($admin)), $name);
        log_action('admin.denied', $perm, ['role' => self::roleOf($admin)]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            json_out(['code' => 403, 'msg' => $msg], 403);
        }
        http_response_code(403);
        flash($msg, 'error');
        redirect($redirectTo ?: url('admin/index.php'));
    }

    /* ------------------------------ 代理端 ------------------------------ */

    public static function attemptAgent(string $account, string $password): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入账号与密码'];
        }
        $agent = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE username = ? LIMIT 1', [$account]);
        if (!$agent || !password_verify($password, $agent['password'])) {
            return ['ok' => false, 'msg' => '账号或密码不正确'];
        }
        if ((int)$agent['status'] !== 1) {
            return ['ok' => false, 'msg' => '该渠道账号已被停用'];
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['agent_id']   = (int)$agent['id'];
        $_SESSION['agent_name'] = $agent['username'];
        $_SESSION['agent_time'] = time();
        return ['ok' => true, 'msg' => '登录成功'];
    }

    public static function agent()
    {
        static $agent = false;
        if ($agent !== false) {
            return $agent;
        }
        $id = (int)($_SESSION['agent_id'] ?? 0);
        if ($id <= 0) {
            return $agent = null;
        }
        if (time() - (int)($_SESSION['agent_time'] ?? 0) > (int)config('security.session_ttl', 7200) * 4) {
            self::logoutAgent();
            return $agent = null;
        }
        $_SESSION['agent_time'] = time();
        return $agent = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE id = ? LIMIT 1', [$id]);
    }

    public static function requireAgent(): array
    {
        $a = self::agent();
        if (!$a) {
            redirect(url('agent/login.php'));
        }
        return $a;
    }

    public static function logoutAgent(): void
    {
        unset($_SESSION['agent_id'], $_SESSION['agent_name'], $_SESSION['agent_time']);
    }

    /* ------------------------------ 分站端 ------------------------------ */

    public static function attemptSubsite(string $account, string $password): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入分站账号与密码'];
        }

        $fails = (int)($_SESSION['ss_fail'] ?? 0);
        if ($fails >= (int)config('security.admin_login_limit', 5)) {
            if (time() - (int)($_SESSION['ss_fail_at'] ?? 0) < (int)config('security.login_lock_seconds', 900)) {
                return ['ok' => false, 'msg' => '尝试次数过多，请稍后再试'];
            }
            $fails = 0;
        }

        $subsite = YxSubsite::byUsername($account);
        if (!$subsite || !password_verify($password, (string)$subsite['password'])) {
            $_SESSION['ss_fail']    = $fails + 1;
            $_SESSION['ss_fail_at'] = time();
            return ['ok' => false, 'msg' => '账号或密码不正确'];
        }
        if ((int)$subsite['status'] !== YxSubsite::STATUS_ACTIVE) {
            return ['ok' => false, 'msg' => '该分站尚未开通或已停用，请联系上级分站'];
        }
        if (YxSubsite::isExpired($subsite)) {
            return ['ok' => false, 'msg' => '该分站已到期（' . $subsite['expire_at'] . '），请续费后登录'];
        }

        unset($_SESSION['ss_fail'], $_SESSION['ss_fail_at']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['subsite_id']   = (int)$subsite['id'];
        $_SESSION['subsite_name'] = $subsite['username'];
        $_SESSION['subsite_time'] = time();

        YxDb::update('subsite', ['last_login_at' => now()], 'id = :id', ['id' => (int)$subsite['id']]);

        return ['ok' => true, 'msg' => '登录成功'];
    }

    public static function subsite()
    {
        static $ss = false;
        if ($ss !== false) {
            return $ss;
        }
        $id = (int)($_SESSION['subsite_id'] ?? 0);
        if ($id <= 0) {
            return $ss = null;
        }
        if (time() - (int)($_SESSION['subsite_time'] ?? 0) > (int)config('security.session_ttl', 7200) * 4) {
            self::logoutSubsite();
            return $ss = null;
        }
        $row = YxSubsite::byId($id);
        if (!$row) {
            self::logoutSubsite();
            return $ss = null;
        }
        $_SESSION['subsite_time'] = time();
        return $ss = $row;
    }

    public static function requireSubsite(): array
    {
        $s = self::subsite();
        if (!$s) {
            redirect(url('subsite/login.php'));
        }
        return $s;
    }

    public static function logoutSubsite(): void
    {
        unset($_SESSION['subsite_id'], $_SESSION['subsite_name'], $_SESSION['subsite_time']);
    }

    /* ------------------------------ 客户端 ------------------------------ */

    public static function attemptCustomer(string $account, string $password): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入账号与密码'];
        }

        $fails = (int)($_SESSION['cust_fail'] ?? 0);
        if ($fails >= (int)config('security.admin_login_limit', 5)) {
            if (time() - (int)($_SESSION['cust_fail_at'] ?? 0) < (int)config('security.login_lock_seconds', 900)) {
                return ['ok' => false, 'msg' => '尝试次数过多，请稍后再试'];
            }
            $fails = 0;
        }

        $cust = YxDb::one(
            'SELECT * FROM ' . YxDb::table('customer') . ' WHERE username = ? OR email = ? OR phone = ? LIMIT 1',
            [$account, $account, $account]
        );

        if (!$cust || !password_verify($password, $cust['password'])) {
            $_SESSION['cust_fail']    = $fails + 1;
            $_SESSION['cust_fail_at'] = time();
            return ['ok' => false, 'msg' => '账号或密码不正确'];
        }
        if ((int)$cust['status'] !== 1) {
            return ['ok' => false, 'msg' => '账号已被冻结，请联系客服'];
        }

        unset($_SESSION['cust_fail'], $_SESSION['cust_fail_at']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        // 用户中心「上次登录时间 / IP」展示需要上一次登录的痕迹，故先留存再覆盖
        $_SESSION['cust_prev_login_at'] = $cust['last_login_at'] ?? '';
        $_SESSION['cust_prev_login_ip'] = $cust['last_login_ip'] ?? '';
        $_SESSION['cust_id']   = (int)$cust['id'];
        $_SESSION['cust_name'] = $cust['username'];
        $_SESSION['cust_time'] = time();

        YxDb::update('customer', [
            'last_login_at' => now(),
            'last_login_ip' => client_ip(),
        ], 'id = :id', ['id' => $cust['id']]);

        return ['ok' => true, 'msg' => '登录成功'];
    }

    public static function customer()
    {
        static $cust = false;
        if ($cust !== false) {
            return $cust;
        }
        $id = (int)($_SESSION['cust_id'] ?? 0);
        if ($id <= 0) {
            return $cust = null;
        }
        if (time() - (int)($_SESSION['cust_time'] ?? 0) > (int)config('security.session_ttl', 7200) * 4) {
            self::logoutCustomer();
            return $cust = null;
        }
        $_SESSION['cust_time'] = time();
        return $cust = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ? LIMIT 1', [$id]);
    }

    public static function requireCustomer(): array
    {
        $c = self::customer();
        if (!$c) {
            redirect(url('user/login.php?back=' . urlencode($_SERVER['REQUEST_URI'] ?? '')));
        }
        return $c;
    }

    public static function logoutCustomer(): void
    {
        unset($_SESSION['cust_id'], $_SESSION['cust_name'], $_SESSION['cust_time'],
              $_SESSION['cust_prev_login_at'], $_SESSION['cust_prev_login_ip']);
    }

    /* ------------------------------ 工具 ------------------------------ */

    public static function hash(string $password): string
    {
        return password_hash($password, config('security.password_algo', PASSWORD_DEFAULT));
    }

    public static function isAdmin(): bool
    {
        return self::admin() !== null;
    }
}
