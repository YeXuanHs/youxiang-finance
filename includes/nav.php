<?php
/**
 * 优享财务系统 · 菜单定义
 * 菜单项按当前版本能力自动收敛（feature 为 null 表示所有版本可见）。
 */

function yx_admin_menu(): array
{
    return [
        ['key' => 'dashboard', 'label' => '仪表盘', 'icon' => '◉', 'url' => 'admin/index.php', 'feature' => null],
        ['group' => '业务管理'],
        ['key' => 'products',  'label' => '产品与套餐', 'icon' => '▦', 'url' => 'admin/products.php', 'feature' => null],
        ['key' => 'services',  'label' => '业务实例',   'icon' => '☰', 'url' => 'admin/services.php', 'feature' => 'business.service'],
        ['key' => 'upstream',  'label' => '上游对接',   'icon' => '⇧', 'url' => 'admin/upstream.php', 'feature' => 'upstream'],
        ['key' => 'product_import', 'label' => '导入商品', 'icon' => '⤓', 'url' => 'admin/product_import.php', 'feature' => 'upstream'],
        ['key' => 'tasks',     'label' => '任务队列',   'icon' => '⟳', 'url' => 'admin/tasks.php', 'feature' => 'upstream'],
        ['key' => 'upstream_markup', 'label' => '代理等级加价', 'icon' => '⇗', 'url' => 'admin/upstream_markup.php', 'feature' => 'upstream.markup'],
        ['key' => 'nodes',     'label' => '资源节点',   'icon' => '⬢', 'url' => 'admin/nodes.php', 'feature' => 'business.node'],
        ['key' => 'tickets',   'label' => '工单协同',   'icon' => '✉', 'url' => 'admin/tickets.php', 'feature' => 'ticket'],
        ['group' => '客户与渠道'],
        ['key' => 'customers', 'label' => '客户管理',   'icon' => '☺', 'url' => 'admin/customers.php', 'feature' => null],
        ['key' => 'agents',    'label' => '渠道 / 代理', 'icon' => '⇄', 'url' => 'admin/agents.php', 'feature' => null],
        ['key' => 'agent_apply', 'label' => '代理申请审核', 'icon' => '✎', 'url' => 'admin/agent_apply.php', 'feature' => null],
        ['key' => 'promoters', 'label' => '推广商',     'icon' => '◎', 'url' => 'admin/promoters.php', 'feature' => null],
        ['key' => 'affiliate', 'label' => '推广计划',   'icon' => '◈', 'url' => 'admin/affiliate.php', 'feature' => null],
        ['key' => 'ticket_reports', 'label' => '工单举报', 'icon' => '⚑', 'url' => 'admin/ticket_reports.php', 'feature' => 'ticket'],
        ['key' => 'checkin',   'label' => '每日签到', 'icon' => '✓', 'url' => 'admin/checkin.php', 'feature' => null],
        ['key' => 'careers',   'label' => '招聘管理', 'icon' => '👤', 'url' => 'admin/careers.php', 'feature' => null],
        ['key' => 'news',      'label' => '新闻公告', 'icon' => '📰', 'url' => 'admin/news.php', 'feature' => null],
        ['key' => 'seo_push',  'label' => '搜索引擎推送', 'icon' => '⇪', 'url' => 'admin/seo_push.php', 'feature' => null],
        ['key' => 'chat',      'label' => '在线客服', 'icon' => '💬', 'url' => 'admin/chat.php', 'feature' => null],
        ['key' => 'service_center', 'label' => '服务中心', 'icon' => '☎', 'url' => 'admin/service_center.php', 'feature' => null],
        ['group' => '财务中心'],
        ['key' => 'orders',       'label' => '订单管理', 'icon' => '▤', 'url' => 'admin/orders.php', 'feature' => null],
        ['key' => 'invoices',     'label' => '账单中心', 'icon' => '▧', 'url' => 'admin/invoices.php', 'feature' => 'finance.invoice'],
        ['key' => 'transactions', 'label' => '收支流水', 'icon' => '⇅', 'url' => 'admin/transactions.php', 'feature' => 'finance.cashflow'],
        ['key' => 'coupons',      'label' => '优惠券',   'icon' => '%', 'url' => 'admin/coupons.php', 'feature' => 'coupon'],
        ['key' => 'report',       'label' => '经营分析', 'icon' => '◪', 'url' => 'admin/report.php', 'feature' => 'report'],
        ['group' => '应用与授权'],
        ['key' => 'plugin_center', 'label' => '插件中心', 'icon' => '🧩', 'url' => 'admin/plugin_center.php', 'feature' => null, 'perm' => 'store'],
        ['key' => 'store',      'label' => '商店管理', 'icon' => '🛒', 'url' => 'admin/store.php', 'feature' => null, 'perm' => 'store'],
        ['key' => 'marketing',  'label' => '商品图制作', 'icon' => '🖼', 'url' => 'admin/marketing.php', 'feature' => null],
        ['key' => 'developers', 'label' => '开发者中心', 'icon' => '◆', 'url' => 'admin/developers.php', 'feature' => null, 'perm' => 'developer'],
        ['key' => 'infringe',   'label' => '版权侵权', 'icon' => '⚠', 'url' => 'admin/infringe.php', 'feature' => null],
        ['key' => 'api_apps',   'label' => '下游接口', 'icon' => '⇄', 'url' => 'admin/api_apps.php', 'feature' => 'openapi'],
        ['key' => 'zjmf',       'label' => '接口对接', 'icon' => '⤯', 'url' => 'admin/zjmf.php', 'feature' => 'openapi'],
        ['key' => 'licenses', 'label' => '授权中心', 'icon' => '🔑', 'url' => 'admin/licenses.php', 'feature' => null],
        ['key' => 'edition',  'label' => '版本与模块', 'icon' => '★', 'url' => 'admin/edition.php', 'feature' => null],
        ['group' => '系统'],
        ['key' => 'banners',  'label' => '首页横幅', 'icon' => '▭', 'url' => 'admin/banners.php', 'feature' => null],
        ['key' => 'ads',      'label' => '展示广告位', 'icon' => '◫', 'url' => 'admin/ads.php', 'feature' => null],
        ['key' => 'about',    'label' => '关于我们', 'icon' => 'ⓘ', 'url' => 'admin/about.php', 'feature' => null],
        ['key' => 'vuln',     'label' => '漏洞审核', 'icon' => '🛡', 'url' => 'admin/vuln.php', 'feature' => null],
        ['key' => 'logs',     'label' => '操作日志', 'icon' => '≡', 'url' => 'admin/logs.php', 'feature' => null],
        ['key' => 'admins',   'label' => '管理员管理', 'icon' => '👥', 'url' => 'admin/admins.php', 'feature' => null, 'perm' => 'admin_users'],
        ['key' => 'settings', 'label' => '站务管理', 'icon' => '⚙', 'url' => 'admin/settings.php', 'feature' => null],
        ['key' => 'desktop',  'label' => '电脑版访问', 'icon' => '🖥', 'url' => 'desktop.php', 'feature' => null, 'new_tab' => true],
    ];
}

/**
 * 用户中心一级功能
 *
 * 账户概览、用户中心、我的订单、账户管理、我的账单、新闻中心、帮助中心
 * 统一由 $yx_nav_group + $yx_nav_item 描述：
 *   group  一级功能（顶栏下拉的主入口）
 *   item   一级功能下的二级功能（下拉项，也用于左侧菜单分组）
 *   parent 二级功能归属的一级功能，供左侧菜单 / 面包屑 / 页面标题反查
 *   show   不进入下拉，仅出现在左侧菜单（如账单列表、发票申请这类细粒度入口）
 *
 * 版本能力（feature）与菜单渲染共用同一份定义，避免两处各写一遍导致入口丢失。
 */
function yx_user_nav_spec(): array
{
    // 一级功能行：用 type=group 显式标记。
    // 不能靠「有 group 键且无 key 键」来判断——二级功能同样带 group 键（归属的一级功能），
    // 之前的写法会把每个二级功能也当成分组标题，导致侧栏与下拉都渲染不出来。
    $group = function (string $key, string $label, string $icon, string $url, ?string $desc = null): array {
        return ['type' => 'group', 'group' => $key, 'label' => $label, 'icon' => $icon, 'url' => $url, 'desc' => $desc];
    };
    $item = function (string $group, string $key, string $label, string $icon, string $url, ?string $desc = null, ?string $feature = null, bool $show = true): array {
        return [
            'type' => 'item',
            'group' => $group, 'key' => $key, 'label' => $label, 'icon' => $icon, 'url' => $url,
            'desc' => $desc, 'feature' => $feature, 'show' => $show,
        ];
    };

    return [
        /* ---------------- 账户概览 ----------------
         * 一级入口回落 user/index.php（真实存在的概览页），四个二级功能各自独立成页 */
        $group('account', '账户概览', '◉', 'user/index.php', '余额 · 消费 · 安全'),
        $item('account', 'profile', '资料设置', '⚙', 'user/profile.php', '手机 · 邮箱 · 密码'),
        $item('account', 'realname', '实名认证', '✔', 'user/realname.php', '扫码人脸 · 企业认证'),
        $item('account', 'security', '安全设置', '🛡', 'user/security.php', '密码 · 登录保护'),
        $item('account', 'orders', '我的订单', '▤', 'user/orders.php', '订单与支付状态'),

        /* ---------------- 用户中心 ---------------- */
        $group('ucenter', '用户中心', '☺', 'user/index.php', '业务 · 授权 · 推广'),
        $item('ucenter', 'services', '我的业务', '☰', 'user/services.php', '已购产品实例', 'business.service'),
        $item('ucenter', 'upstream', '自动对接', '⇧', 'user/upstream.php', '购买 · 自动开通', 'upstream'),
        $item('ucenter', 'upstream_markup', '代理等级加价', '⇗', 'user/upstream_markup.php', '加价规则 · 上游检测', 'upstream.markup'),
        $item('ucenter', 'service_orders', '我的服务', '☎', 'user/service_orders.php', '增值服务订单'),
        $item('ucenter', 'recharge', '余额充值', '＋', 'user/recharge.php', '在线充值 · 流水'),

        /* ---------------- 我的订单 ----------------
         * 一级入口指向订单列表，不再指向不存在的 user/order.php */
        $group('order', '我的订单', '▤', 'user/orders.php', '待支付 · 已交付'),
        $item('order', 'store', '应用商店', '🛒', 'store/index.php', '第三方插件 · 付费购买'),
        $item('order', 'plugin_center', '插件中心', '🧩', 'user/plugin_center.php', '市场选购 · 已购管理'),
        $item('order', 'licenses', '我的授权', '🔑', 'user/licenses.php', '授权码与绑定'),
        $item('order', 'certificates', '购买证明', '📜', 'user/certificate.php', '正版凭证 · 电子公章'),
        $item('order', 'developer', '开发者中心', '◆', 'user/developer.php', '应用与分发'),

        /* ---------------- 账户管理 ----------------
         * 一级入口指向工单支持（账户管理下最常用的落地页） */
        $group('manage', '账户管理', '⚙', 'user/tickets.php', '工单 · 绑定 · 日志'),
        $item('manage', 'ticket_new', '提交工单', '✎', 'user/tickets.php?new=1', '提交问题', 'ticket'),
        $item('manage', 'tickets', '工单支持', '✉', 'user/tickets.php', '提交与跟进', 'ticket'),
        $item('manage', 'binding', '账号绑定', '⇄', 'user/binding.php', '手机 · 邮箱 · 微信'),
        $item('manage', 'logs', '系统日志', '≡', 'user/logs.php', '登录与操作记录'),

        /* ---------------- 我的账单 ----------------
         * 一级入口 = 账单列表；四个二级功能全部进下拉（原先 show=false 导致下拉框为空） */
        $group('bill', '我的账单', '▧', 'user/invoice_list.php', '账单 · 票据'),
        $item('bill', 'invoices', '账单总览', '▧', 'user/invoices.php', '应付与已结清', 'finance.invoice'),
        $item('bill', 'invoice_list', '账单列表', '▤', 'user/invoice_list.php', '流水明细与支付', 'finance.invoice'),
        $item('bill', 'invoice_apply', '发票申请', '⎙', 'user/invoice_apply.php', '普票 · 专票', 'finance.invoice'),
        // 付款入口指向充值页：账单收款由财务在后台确认，用户侧唯一可自助完成付款的动作是充值余额。
        // 原先指向 user/purchase.php（只接受 POST 的产品下单页），GET 访问会被 302 弹回首页。
        $item('bill', 'purchase', '账单付款', '¥', 'user/recharge.php', '充值余额后结清', 'finance.invoice'),

        /* ---------------- 新闻中心 ---------------- */
        $group('news', '新闻中心', '📰', 'news.php', '公告 · 动态'),
        $item('news', 'news', '新闻公告', '📰', 'news.php', '平台公告与动态'),
        $item('news', 'career', '加入我们', '👤', 'career.php', '招聘与岗位'),
        $item('news', 'about', '关于我们', 'ⓘ', 'about.php', '公司资质与服务'),

        /* ---------------- 帮助中心 ---------------- */
        $group('help', '帮助中心', '?', 'docs/index.php', '文档 · 对接 · 举报'),
        $item('help', 'docs', '帮助文档', '📖', 'docs/index.php', '安装 · 部署 · 对接'),
        $item('help', 'reports', '我的举报', '⚑', 'report.php', '侵权与漏洞举报', 'ticket'),
        $item('help', 'agent_center', '代理中心', '⇄', 'user/agent_center.php', '代理申请 · 我的代理'),
        $item('help', 'promoter', '推广商中心', '◎', 'user/promoter.php', '推广产品 · 收款码'),
    ];
}

/** 用户中心完整菜单（一级功能 + 二级功能，平铺顺序即渲染顺序） */
function yx_user_menu(): array
{
    $out = [];
    foreach (yx_user_nav_spec() as $row) {
        unset($row['show']);
        $out[] = $row;
    }
    return $out;
}

/** 该行是否是一级功能（分组）行 */
function yx_nav_is_group(array $row): bool
{
    return ($row['type'] ?? '') === 'group';
}

/**
 * 用户中心菜单（分组版）
 * 与 yx_user_menu() 项一致，仅增加「分组标题 + 说明文字」，供控制台式侧栏渲染。
 * 说明文字作为侧栏二级小字，可省略。
 *
 * 输出结构中，分组行只保留 ['group' => 标题] 这一种键（与 yx_render_menu_groups 的
 * 约定一致），二级功能行则去掉 type/group 归属键，避免被侧栏误判为分组标题。
 */
function yx_user_menu_groups(): array
{
    $out = [];
    foreach (yx_user_nav_spec() as $row) {
        if (yx_nav_is_group($row)) {
            $out[] = ['group' => (string)$row['label']];
            continue;
        }
        unset($row['show'], $row['type'], $row['group']);
        $out[] = $row;
    }
    return $out;
}

/**
 * 二级功能 → 所属一级功能（面包屑、页面标题使用）
 * 返回 key / label（二级名称）/ url / group（一级 key）/ parent（一级名称），未命中返回 null。
 */
function yx_user_nav_find(string $key): ?array
{
    $parent = null;
    foreach (yx_user_nav_spec() as $row) {
        if (yx_nav_is_group($row)) {
            $parent = $row;
            continue;
        }
        if (($row['key'] ?? '') === $key) {
            return [
                'key'    => (string)$row['key'],
                'label'  => (string)$row['label'],
                'url'    => (string)$row['url'],
                'group'  => (string)($parent['group'] ?? ''),
                'parent' => (string)($parent['label'] ?? ''),
            ];
        }
    }
    return null;
}

/**
 * 顶栏一级功能下拉导航
 *
 * 每个一级功能一个下拉框，下拉内是归属它的二级功能；版本能力不足的二级功能置灰，
 * 全部二级功能都不可用时该一级功能的入口整体隐藏（只剩主站点入口仍可点击）。
 */
function yx_render_user_nav_dropdown(string $active, string $base, string $currentUri = ''): string
{
    $groups = [];
    foreach (yx_user_nav_spec() as $row) {
        if (yx_nav_is_group($row)) {
            $groups[] = ['parent' => $row, 'items' => []];
            continue;
        }
        if (empty($row['show'])) {
            continue;
        }
        if (!$groups) {
            continue;
        }
        $groups[count($groups) - 1]['items'][] = $row;
    }

    $html = '';
    foreach ($groups as $g) {
        $parent = $g['parent'];
        $parentKey = (string)$parent['group'];
        $visibleItems = [];
        $lockedItems  = [];
        foreach ($g['items'] as $it) {
            if (empty($it['feature']) || YxEdition::can($it['feature'])) {
                $visibleItems[] = $it;
            } else {
                $lockedItems[] = $it;
            }
        }
        if (!$visibleItems && !$lockedItems) {
            continue;
        }

        // 命中状态：二级功能被激活，或当前访问地址落在该二级功能的链接上
        $isActive = $active === $parentKey;
        foreach ($g['items'] as $it) {
            if ($active !== '' && $active === (string)$it['key']) {
                $isActive = true;
                break;
            }
            if (yx_user_nav_match_uri($currentUri, (string)$it['url'])) {
                $isActive = true;
                break;
            }
        }

        $menu = '<div class="yx-nav-menu">';
        $menu .= '<a class="yx-nav-menu-home" href="' . e(yx_menu_href($base, (string)$parent['url'])) . '">'
            . '<b>' . e((string)$parent['label']) . '</b><em>' . e((string)($parent['desc'] ?? '')) . '</em></a>';
        $menu .= '<div class="yx-nav-menu-list">';
        foreach ($visibleItems as $it) {
            $cls = $active === (string)$it['key'] ? 'yx-nav-menu-item active' : 'yx-nav-menu-item';
            $menu .= '<a class="' . $cls . '" href="' . e(yx_menu_href($base, (string)$it['url'])) . '">'
                . '<span class="yx-nav-menu-ico">' . $it['icon'] . '</span>'
                . '<span class="yx-nav-menu-txt"><b>' . e((string)$it['label']) . '</b>'
                . (!empty($it['desc']) ? '<em>' . e((string)$it['desc']) . '</em>' : '')
                . '</span></a>';
        }
        foreach ($lockedItems as $it) {
            $menu .= '<span class="yx-nav-menu-item disabled" title="当前版本不可用">'
                . '<span class="yx-nav-menu-ico">' . $it['icon'] . '</span>'
                . '<span class="yx-nav-menu-txt"><b>' . e((string)$it['label']) . '</b>'
                . '<em class="yx-nav-lock">需' . e(YxEdition::name(YxEdition::FEATURES[$it['feature']] ?? 'pro')) . '</em>'
                . '</span></span>';
        }
        $menu .= '</div></div>';

        $html .= '<div class="yx-nav' . ($isActive ? ' is-active' : '') . '" data-nav="' . e($parentKey) . '">'
            . '<button type="button" class="yx-nav-btn" aria-expanded="false">'
            . '<span class="yx-nav-ico">' . $parent['icon'] . '</span>'
            . '<span class="yx-nav-label">' . e((string)$parent['label']) . '</span>'
            . '<i class="yx-nav-caret" aria-hidden="true"></i></button>'
            . $menu . '</div>';
    }
    return $html;
}

/**
 * 菜单链接地址
 * $base 传站点根（url('')）时统一走 url()，以便自定义后台路径生效；
 * 传其他前缀（如子目录）时保持原有的简单拼接行为。
 */
function yx_menu_href(string $base, string $path): string
{
    if ($base === url('')) {
        return url($path);
    }
    return $base . ltrim($path, '/');
}

/**
 * 当前访问地址是否命中某个菜单链接
 *
 * 只按「路径段」精确比对，不用子串匹配：
 * 旧实现用 strpos($uri, $path) 会让 user/index.php 误命中 user/invoice_list.php
 * （"index.php" 之外的同名片段）以及 tickets.php 命中 tickets.php?new=1 之外的地址。
 * 查询串存在时（如 tickets.php?new=1）要求对应参数也一致，避免「提交工单」在
 * 普通工单页上被点亮。
 */
function yx_user_nav_match_uri(string $currentUri, string $menuUrl): bool
{
    if ($currentUri === '' || $menuUrl === '') {
        return false;
    }
    $curPath = (string)parse_url($currentUri, PHP_URL_PATH);
    $curQuery = (string)parse_url($currentUri, PHP_URL_QUERY);

    $cut = strpos($menuUrl, '?');
    $menuPath  = $cut === false ? $menuUrl : substr($menuUrl, 0, $cut);
    $menuQuery = $cut === false ? '' : substr($menuUrl, $cut + 1);
    if ($menuPath === '') {
        return false;
    }

    // 按路径结尾比对：站点可能挂在子目录，或存在 /admin/ 之类前缀
    $needle = '/' . ltrim($menuPath, '/');
    if ($curPath !== $needle && substr($curPath, -strlen($needle)) !== $needle) {
        return false;
    }
    // 前一个字符必须是路径边界，防止 /xuser/index.php 命中 /user/index.php
    $pos = strlen($curPath) - strlen($needle);
    if ($pos > 0 && substr($curPath, $pos - 1, 1) !== '/') {
        return false;
    }

    if ($menuQuery === '') {
        return true;
    }

    parse_str($menuQuery, $want);
    parse_str($curQuery, $have);
    foreach ($want as $k => $v) {
        if (!isset($have[$k]) || (string)$have[$k] !== (string)$v) {
            return false;
        }
    }
    return true;
}

/**
 * 渲染菜单，自动跳过无权限项
 *
 * 两层门禁：feature 是版本能力（无权限时置灰提示升级），perm 是管理员角色能力
 * （受限账号直接不显示，避免暴露入口）。new_tab 的条目在新窗口打开。
 */
function yx_render_menu(array $items, string $active, string $base): string
{
    $html = '';
    foreach ($items as $item) {
        if (isset($item['group'])) {
            $html .= '<div class="menu-group">' . e($item['group']) . '</div>';
            continue;
        }
        if (!empty($item['feature']) && !YxEdition::can($item['feature'])) {
            $html .= '<span class="menu-item disabled" title="当前版本不可用">'
                . '<i>' . $item['icon'] . '</i>' . e($item['label'])
                . '<em>需' . e(YxEdition::name(YxEdition::FEATURES[$item['feature']] ?? 'pro')) . '</em></span>';
            continue;
        }
        if (!empty($item['perm']) && !YxAuth::canAdmin($item['perm'])) {
            continue;
        }
        $cls = $active === $item['key'] ? 'menu-item active' : 'menu-item';
        $extra = !empty($item['new_tab']) ? ' target="_blank" rel="noopener"' : '';
        $html .= '<a class="' . $cls . '" href="' . e(yx_menu_href($base, $item['url'])) . '"' . $extra . '>'
            . '<i>' . $item['icon'] . '</i>' . e($item['label']) . '</a>';
    }
    return $html;
}

/**
 * 渲染分组侧栏（用户中心控制台式布局）
 * 与 yx_render_menu 输出结构兼容，额外渲染 desc 小字；
 * 分组内全部条目无权限时，整组（含标题）一并隐藏。
 */
function yx_render_menu_groups(array $items, string $active, string $base): string
{
    // 先按分组切块，便于整组隐藏
    $blocks = [];
    foreach ($items as $item) {
        if (isset($item['group'])) {
            $blocks[] = ['title' => (string)$item['group'], 'items' => []];
            continue;
        }
        if (!$blocks) {
            $blocks[] = ['title' => '', 'items' => []];
        }
        $blocks[count($blocks) - 1]['items'][] = $item;
    }

    $html = '';
    foreach ($blocks as $block) {
        $body = '';
        foreach ($block['items'] as $item) {
            $allowed = empty($item['feature']) || YxEdition::can($item['feature']);
            if (!$allowed) {
                $body .= '<span class="menu-item disabled" title="当前版本不可用">'
                    . '<i>' . $item['icon'] . '</i>'
                    . '<span class="menu-txt"><b>' . e($item['label']) . '</b>'
                    . '<em class="menu-lock">需' . e(YxEdition::name(YxEdition::FEATURES[$item['feature']] ?? 'pro')) . '</em></span>'
                    . '</span>';
                continue;
            }
            $cls = $active === $item['key'] ? 'menu-item active' : 'menu-item';
            $body .= '<a class="' . $cls . '" href="' . e(yx_menu_href($base, $item['url'])) . '">'
                . '<i>' . $item['icon'] . '</i>'
                . '<span class="menu-txt"><b>' . e($item['label']) . '</b>'
                . (!empty($item['desc']) ? '<em>' . e($item['desc']) . '</em>' : '')
                . '</span></a>';
        }
        if ($body === '') {
            continue;
        }
        if ($block['title'] !== '') {
            $html .= '<div class="menu-group">' . e($block['title']) . '</div>';
        }
        $html .= $body;
    }
    return $html;
}
