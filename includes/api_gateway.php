<?php
/**
 * 优享财务系统 · 下游 API 动作实现
 *
 * 与 includes/api.php（鉴权/签名/日志）分工：
 *   api.php         负责「能不能调」
 *   api_gateway.php 负责「调了做什么」
 */

class YxApiGateway
{
    /**
     * 分发动作
     * @param string $action 动作名
     * @param array  $req    原始请求参数
     * @param array  $app    已通过鉴权的应用
     * @return array ['code'=>int,'msg'=>string,'data'=>mixed]
     */
    public static function dispatch(string $action, array $req, array $app): array
    {
        switch ($action) {
            case 'ping':            return self::ping($app);
            case 'product.list':    return self::productList($req, $app);
            case 'product.detail':  return self::productDetail($req, $app);
            case 'order.create':    return self::orderCreate($req, $app);
            case 'order.pay':       return self::orderPay($req, $app);
            case 'order.query':     return self::orderQuery($req, $app);
            case 'order.list':      return self::orderList($req, $app);
            case 'service.list':    return self::serviceList($req, $app);
            case 'service.detail':  return self::serviceDetail($req, $app);
            case 'service.renew':   return self::serviceRenew($req, $app);
            case 'service.operate': return self::serviceOperate($req, $app);
            case 'service.sync':    return self::serviceSync($req, $app);
            case 'balance.query':   return self::balanceQuery($req, $app);
            case 'license.query':   return self::licenseQuery($req, $app);
            default:
                return ['code' => YxApi::ERR_PARAM, 'msg' => '未实现的动作：' . $action, 'data' => null];
        }
    }

    /* ------------------------------------------------------------------ 基础 */

    private static function ok($data, string $msg = 'ok'): array
    {
        return ['code' => YxApi::OK, 'msg' => $msg, 'data' => $data];
    }

    private static function err(int $code, string $msg): array
    {
        return ['code' => $code, 'msg' => $msg, 'data' => null];
    }

    private static function ping(array $app): array
    {
        return self::ok([
            'pong'      => true,
            'app'       => $app['name'],
            'owner'     => $app['owner_type'],
            'server'    => setting('site_name', config('app.name')),
            'version'   => config('app.version'),
            'edition'   => YxEdition::name(),
            'time'      => now(),
            'timestamp' => time(),
        ]);
    }

    /* ------------------------------------------------------------------ 产品 */

    private static function resolveProduct(array $req, ?array $app = null)
    {
        $id   = (int)($req['product_id'] ?? 0);
        $code = trim((string)($req['product_code'] ?? ''));

        if ($id > 0) {
            return YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE id = ? AND status = 1', [$id]);
        }
        if ($code !== '') {
            return YxDb::one('SELECT * FROM ' . YxDb::table('product') . ' WHERE code = ? AND status = 1', [$code]);
        }
        return null;
    }

    private static function productList(array $req, array $app): array
    {
        $where = ' WHERE status = 1';
        $bind  = [];
        if (($gid = (int)($req['group_id'] ?? 0)) > 0) {
            $where .= ' AND group_id = ?';
            $bind[] = $gid;
        }
        if (($kw = trim((string)($req['keyword'] ?? ''))) !== '') {
            $where .= ' AND name LIKE ?';
            $bind[] = '%' . $kw . '%';
        }

        $groups = [];
        foreach (YxDb::all('SELECT id, name FROM ' . YxDb::table('product_group') . ' ORDER BY sort ASC, id ASC') as $g) {
            $groups[(int)$g['id']] = $g['name'];
        }

        $rows = YxDb::all('SELECT * FROM ' . YxDb::table('product') . $where . ' ORDER BY sort ASC, id ASC LIMIT 200', $bind);
        $list = [];
        foreach ($rows as $p) {
            $list[] = [
                'product_id'  => (int)$p['id'],
                'code'        => $p['code'],
                'name'        => $p['name'],
                'group'       => $groups[(int)$p['group_id']] ?? '',
                'type'        => $p['type'],
                'cycle'       => $p['cycle'],
                'price'       => (float)$p['price'],
                'setup_fee'   => (float)$p['setup_fee'],
                'stock'       => (int)$p['stock'],
                'auto_setup'  => (int)$p['auto_setup'] === 1,
                'edition_min' => $p['edition_min'],
                'description' => $p['description'],
            ];
        }
        return self::ok(['total' => count($list), 'list' => $list, 'groups' => $groups]);
    }

    private static function productDetail(array $req, array $app): array
    {
        $p = self::resolveProduct($req, $app);
        if (!$p) {
            return self::err(YxApi::ERR_NOTFOUND, '产品不存在或已下架');
        }
        $cycles = [];
        foreach (YxBusiness::CYCLES as $k => $c) {
            $cycles[] = [
                'cycle'  => $k,
                'name'   => $c['name'],
                'months' => $c['months'],
                'price'  => YxBusiness::cyclePrice($p, $k),
            ];
        }
        return self::ok([
            'product_id'  => (int)$p['id'],
            'code'        => $p['code'],
            'name'        => $p['name'],
            'type'        => $p['type'],
            'default_cycle' => $p['cycle'],
            'price'       => (float)$p['price'],
            'setup_fee'   => (float)$p['setup_fee'],
            'stock'       => (int)$p['stock'],
            'auto_setup'  => (int)$p['auto_setup'] === 1,
            'upstream'    => YxUpstream::mapForProduct((int)$p['id']) ? true : false,
            'description' => $p['description'],
            'cycles'      => $cycles,
        ]);
    }

    /* ------------------------------------------------------------------ 订单 */

    private static function orderCreate(array $req, array $app): array
    {
        $product = self::resolveProduct($req, $app);
        if (!$product) {
            return self::err(YxApi::ERR_NOTFOUND, '产品不存在或已下架');
        }
        if ((int)$product['stock'] === 0) {
            return self::err(YxApi::ERR_FORBIDDEN, '产品库存不足');
        }

        $externalUser = trim((string)($req['external_user_id'] ?? ''));
        $customerId = YxApi::resolveCustomer($app, $externalUser, [
            'email'   => (string)($req['email'] ?? ''),
            'phone'   => (string)($req['phone'] ?? ''),
            'company' => (string)($req['company'] ?? ''),
        ]);
        if (!$customerId) {
            return self::err(YxApi::ERR_PARAM, '缺少 external_user_id（用于标识下游终端用户）');
        }

        // 后台「实名认证 / 绑定手机号」开关：开启后不满足条件的客户不允许下单
        $cust = YxDb::one('SELECT * FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);
        if ($cust) {
            $check = YxZjmf::checkCustomer($cust);
            if (!$check['ok']) {
                return self::err(YxApi::ERR_FORBIDDEN, $check['msg']);
            }
        }

        $cycle = (string)($req['cycle'] ?? $product['cycle']);
        if (!isset(YxBusiness::CYCLES[$cycle])) {
            $cycle = $product['cycle'];
        }

        // 幂等：同一应用 + 同一外部单号 只创建一次
        $externalSn = trim((string)($req['external_sn'] ?? ''));
        if ($externalSn !== '') {
            $exist = YxDb::one(
                'SELECT * FROM ' . YxDb::table('order') . ' WHERE appid = ? AND external_sn = ? LIMIT 1',
                [$app['appid'], $externalSn]
            );
            if ($exist) {
                return self::ok(self::orderData($exist), '订单已存在（按 external_sn 幂等返回）');
            }
        }

        $amount = YxBusiness::cyclePrice($product, $cycle) + (float)$product['setup_fee'];
        $created = YxBusiness::createOrder([
            'customer_id' => $customerId,
            'type'        => 'new',
            'product_id'  => (int)$product['id'],
            'name'        => $product['name'] . '（' . YxBusiness::cycleName($cycle) . '）',
            'amount'      => $amount,
            'note'        => '开放接口下单',
            'agent_id'    => $app['owner_type'] === 'agent' ? (int)$app['owner_id'] : 0,
        ]);
        if (empty($created['ok'])) {
            return self::err(YxApi::ERR_SERVER, $created['msg'] ?? '下单失败');
        }

        YxDb::update('order', [
            'source'      => 'api',
            'appid'       => (string)$app['appid'],
            'external_sn' => $externalSn ?: null,
        ], 'id = :id', ['id' => (int)$created['order_id']]);

        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$created['order_id']]);
        return self::ok(self::orderData($order), '下单成功');
    }

    private static function orderData(array $order): array
    {
        return [
            'order_id'    => (int)$order['id'],
            'order_sn'    => $order['sn'],
            'type'        => $order['type'],
            'name'        => $order['name'],
            'amount'      => (float)$order['amount'],
            'discount'    => (float)$order['discount'],
            'total'       => (float)$order['total'],
            'status'      => $order['status'],
            'status_text' => YxBusiness::orderStatusName($order['status']),
            'pay_method'  => $order['pay_method'],
            'service_id'  => (int)$order['service_id'],
            'external_sn' => $order['external_sn'],
            'created_at'  => $order['created_at'],
            'paid_at'     => $order['paid_at'],
        ];
    }

    private static function findOrder(array $req, array $app)
    {
        $sn = trim((string)($req['order_sn'] ?? ''));
        if ($sn === '') {
            return null;
        }
        $order = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE sn = ? LIMIT 1', [$sn]);
        if (!$order) {
            return null;
        }
        if (!YxApi::inScope($app, (int)$order['customer_id'])) {
            return null;
        }
        return $order;
    }

    private static function orderQuery(array $req, array $app): array
    {
        $order = self::findOrder($req, $app);
        if (!$order) {
            return self::err(YxApi::ERR_NOTFOUND, '订单不存在或不属于该应用');
        }
        $data = self::orderData($order);
        // 附上业务实例信息，便于下游展示
        if ((int)$order['service_id'] > 0) {
            $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [(int)$order['service_id']]);
            if ($svc) {
                $data['service'] = self::serviceData($svc, true);
            }
        }
        return self::ok($data);
    }

    private static function orderPay(array $req, array $app): array
    {
        $order = self::findOrder($req, $app);
        if (!$order) {
            return self::err(YxApi::ERR_NOTFOUND, '订单不存在或不属于该应用');
        }
        if ($order['status'] !== 'unpaid') {
            return self::ok(self::orderData($order), '订单无需重复支付（当前状态：' . YxBusiness::orderStatusName($order['status']) . '）');
        }

        $method = (string)($req['method'] ?? 'balance');
        if (!in_array($method, ['balance', 'manual'], true)) {
            return self::err(YxApi::ERR_PARAM, 'method 仅支持 balance（扣我方账户余额）或 manual（下游已自行收款）');
        }

        $res = $method === 'balance'
            ? YxBusiness::payOrder((int)$order['id'], 'balance')
            : YxBusiness::payOrderExternal((int)$order['id'], 'api');

        if (empty($res['ok'])) {
            return self::err(YxApi::ERR_FORBIDDEN, $res['msg'] ?? '支付失败');
        }

        $fresh = YxDb::one('SELECT * FROM ' . YxDb::table('order') . ' WHERE id = ?', [(int)$order['id']]);
        $data  = self::orderData($fresh);
        if ((int)$fresh['service_id'] > 0) {
            $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [(int)$fresh['service_id']]);
            if ($svc) {
                $data['service'] = self::serviceData($svc, true);
            }
        }
        return self::ok($data, '支付成功');
    }

    private static function orderList(array $req, array $app): array
    {
        $where = ' WHERE 1=1';
        $bind  = [];

        $externalUser = trim((string)($req['external_user_id'] ?? ''));
        if ($externalUser !== '') {
            $cid = YxApi::resolveCustomer($app, $externalUser);
            if (!$cid) {
                return self::ok(['total' => 0, 'list' => []]);
            }
            $where .= ' AND customer_id = ?';
            $bind[] = $cid;
        } else {
            $scope = YxApi::scopeCustomerIds($app);
            if ($scope !== null) {
                if (!$scope) {
                    return self::ok(['total' => 0, 'list' => []]);
                }
                $where .= ' AND customer_id IN (' . implode(',', array_map('intval', $scope)) . ')';
            }
        }

        if (($st = trim((string)($req['status'] ?? ''))) !== '' && isset(YxBusiness::ORDER_STATUS[$st])) {
            $where .= ' AND status = ?';
            $bind[] = $st;
        }

        $limit  = max(1, min(100, (int)($req['limit'] ?? 20)));
        $page   = max(1, (int)($req['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('order') . $where, $bind);
        $rows  = YxDb::all(
            'SELECT * FROM ' . YxDb::table('order') . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $bind
        );

        $list = [];
        foreach ($rows as $o) {
            $list[] = self::orderData($o);
        }
        return self::ok(['total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list]);
    }

    /* ------------------------------------------------------------------ 业务实例 */

    private static function serviceData(array $svc, bool $withLogin = false): array
    {
        $data = [
            'service_id'    => (int)$svc['id'],
            'service_sn'    => $svc['sn'],
            'name'          => $svc['name'],
            'product_id'    => (int)$svc['product_id'],
            'hostname'      => $svc['hostname'],
            'ip'            => $svc['ip'],
            'status'        => $svc['status'],
            'status_text'   => YxBusiness::serviceStatusName($svc['status']),
            'billing_cycle' => $svc['billing_cycle'],
            'price'         => (float)$svc['price'],
            'next_due_date' => $svc['next_due_date'],
            'remote_id'     => $svc['remote_id'],
            'upstream_id'   => (int)$svc['upstream_id'],
            'remote_status' => $svc['remote_status'],
            'created_at'    => $svc['created_at'],
            'activated_at'  => $svc['activated_at'],
        ];
        if ($withLogin && $svc['login_user']) {
            $data['login_user'] = $svc['login_user'];
            $data['login_pass'] = yx_decrypt((string)$svc['login_pass']);
        }
        return $data;
    }

    private static function findService(array $req, array $app)
    {
        $sn = trim((string)($req['service_sn'] ?? ''));
        if ($sn === '') {
            return null;
        }
        $svc = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE sn = ? LIMIT 1', [$sn]);
        if (!$svc) {
            return null;
        }
        if (!YxApi::inScope($app, (int)$svc['customer_id'])) {
            return null;
        }
        return $svc;
    }

    private static function serviceList(array $req, array $app): array
    {
        $where = ' WHERE 1=1';
        $bind  = [];

        $externalUser = trim((string)($req['external_user_id'] ?? ''));
        if ($externalUser !== '') {
            $cid = YxApi::resolveCustomer($app, $externalUser);
            if (!$cid) {
                return self::ok(['total' => 0, 'list' => []]);
            }
            $where .= ' AND customer_id = ?';
            $bind[] = $cid;
        } else {
            $scope = YxApi::scopeCustomerIds($app);
            if ($scope !== null) {
                if (!$scope) {
                    return self::ok(['total' => 0, 'list' => []]);
                }
                $where .= ' AND customer_id IN (' . implode(',', array_map('intval', $scope)) . ')';
            }
        }

        if (($st = trim((string)($req['status'] ?? ''))) !== '' && isset(YxBusiness::SERVICE_STATUS[$st])) {
            $where .= ' AND status = ?';
            $bind[] = $st;
        }

        $limit  = max(1, min(100, (int)($req['limit'] ?? 20)));
        $page   = max(1, (int)($req['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $total = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('service') . $where, $bind);
        $rows  = YxDb::all(
            'SELECT * FROM ' . YxDb::table('service') . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $bind
        );

        $list = [];
        foreach ($rows as $s) {
            $list[] = self::serviceData($s);
        }
        return self::ok(['total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list]);
    }

    private static function serviceDetail(array $req, array $app): array
    {
        $svc = self::findService($req, $app);
        if (!$svc) {
            return self::err(YxApi::ERR_NOTFOUND, '业务不存在或不属于该应用');
        }
        return self::ok(self::serviceData($svc, true));
    }

    private static function serviceRenew(array $req, array $app): array
    {
        $svc = self::findService($req, $app);
        if (!$svc) {
            return self::err(YxApi::ERR_NOTFOUND, '业务不存在或不属于该应用');
        }
        if ($svc['status'] === 'terminated') {
            return self::err(YxApi::ERR_FORBIDDEN, '已终止的业务无法续费');
        }

        $cycle = (string)($req['cycle'] ?? $svc['billing_cycle']);
        if (!isset(YxBusiness::CYCLES[$cycle])) {
            $cycle = (string)$svc['billing_cycle'];
        }

        // 上游顺延由 YxBusiness::renewService 统一入队（同类型同对象自动去重）
        $res = YxBusiness::renewService((int)$svc['id'], $cycle, (float)$svc['price'], 'api');
        if (empty($res['ok'])) {
            return self::err(YxApi::ERR_SERVER, $res['msg'] ?? '续费失败');
        }

        $fresh  = YxDb::one('SELECT * FROM ' . YxDb::table('service') . ' WHERE id = ?', [(int)$svc['id']]);
        $hasUp  = $svc['remote_id'] && (int)$svc['upstream_id'] > 0;
        return self::ok(self::serviceData($fresh), $hasUp ? '本地已顺延，上游续费已入队' : '续费成功');
    }

    private static function serviceOperate(array $req, array $app): array
    {
        $svc = self::findService($req, $app);
        if (!$svc) {
            return self::err(YxApi::ERR_NOTFOUND, '业务不存在或不属于该应用');
        }
        $action = trim((string)($req['op'] ?? ''));
        if ($action === '') {
            // 兼容老调用方：早期文档把操作类型写成 action（与请求动作名冲突，故统一用 op）
            $action = trim((string)($req['service_op'] ?? ''));
        }
        if ($action === '') {
            return self::err(YxApi::ERR_PARAM, '缺少操作类型 op（start|stop|reboot|reinstall|suspend|unsuspend|destroy|sync）');
        }

        $powerMap = ['start' => 'start', 'stop' => 'stop', 'reboot' => 'reboot'];
        $id = (int)$svc['id'];

        switch ($action) {
            case 'start':
            case 'stop':
            case 'reboot':
                if (!$svc['remote_id']) {
                    return self::err(YxApi::ERR_FORBIDDEN, '该业务没有上游实例，无法执行 ' . $action);
                }
                $taskId = YxTask::push('upstream.power', [
                    'ref_type'    => 'service',
                    'ref_id'      => $id,
                    'upstream_id' => (int)$svc['upstream_id'],
                    'payload'     => ['action' => $powerMap[$action]],
                    'unique'      => false,
                ]);
                return self::ok(['queued' => true, 'task_id' => $taskId, 'op' => $action], '操作已入队');

            case 'reinstall':
                if (!$svc['remote_id']) {
                    return self::err(YxApi::ERR_FORBIDDEN, '该业务没有上游实例，无法重装');
                }
                $taskId = YxTask::push('upstream.reinstall', [
                    'ref_type'    => 'service',
                    'ref_id'      => $id,
                    'upstream_id' => (int)$svc['upstream_id'],
                    'payload'     => ['os' => (string)($req['os'] ?? '')],
                    'unique'      => false,
                ]);
                return self::ok(['queued' => true, 'task_id' => $taskId, 'op' => $action], '重装已入队');

            case 'suspend':
                $res = YxBusiness::suspendService($id, '接口暂停');
                if (empty($res['ok'])) {
                    return self::err(YxApi::ERR_FORBIDDEN, $res['msg']);
                }
                if ($svc['remote_id']) {
                    YxTask::push('upstream.power', [
                        'ref_type' => 'service', 'ref_id' => $id,
                        'upstream_id' => (int)$svc['upstream_id'],
                        'payload' => ['action' => 'stop'], 'unique' => false,
                    ]);
                }
                return self::ok(['service_sn' => $svc['sn'], 'status' => 'suspended'], '业务已暂停');

            case 'unsuspend':
                $res = YxBusiness::unsuspendService($id);
                if (empty($res['ok'])) {
                    return self::err(YxApi::ERR_FORBIDDEN, $res['msg']);
                }
                if ($svc['remote_id']) {
                    YxTask::push('upstream.power', [
                        'ref_type' => 'service', 'ref_id' => $id,
                        'upstream_id' => (int)$svc['upstream_id'],
                        'payload' => ['action' => 'start'], 'unique' => false,
                    ]);
                }
                return self::ok(['service_sn' => $svc['sn'], 'status' => 'active'], '业务已恢复');

            case 'destroy':
                $res = YxBusiness::terminateService($id, '接口终止');
                if (empty($res['ok'])) {
                    return self::err(YxApi::ERR_FORBIDDEN, $res['msg']);
                }
                if ($svc['remote_id']) {
                    YxTask::push('upstream.destroy', [
                        'ref_type' => 'service', 'ref_id' => $id,
                        'upstream_id' => (int)$svc['upstream_id'],
                    ]);
                }
                return self::ok(['service_sn' => $svc['sn'], 'status' => 'terminated'], '业务已终止');

            case 'sync':
                return self::serviceSync($req, $app);

            default:
                return self::err(YxApi::ERR_PARAM, '不支持的操作：' . $action);
        }
    }

    private static function serviceSync(array $req, array $app): array
    {
        $svc = self::findService($req, $app);
        if (!$svc) {
            return self::err(YxApi::ERR_NOTFOUND, '业务不存在或不属于该应用');
        }
        if (!$svc['remote_id']) {
            return self::ok(['service_sn' => $svc['sn'], 'remote_status' => null], '该业务没有上游实例');
        }
        $taskId = YxTask::push('upstream.sync', [
            'ref_type'    => 'service',
            'ref_id'      => (int)$svc['id'],
            'upstream_id' => (int)$svc['upstream_id'],
        ]);
        return self::ok(['queued' => true, 'task_id' => $taskId], '同步任务已入队');
    }

    /* ------------------------------------------------------------------ 余额 / 授权 */

    private static function balanceQuery(array $req, array $app): array
    {
        $externalUser = trim((string)($req['external_user_id'] ?? ''));
        if ($externalUser !== '') {
            $cid = YxApi::resolveCustomer($app, $externalUser);
            if (!$cid || !YxApi::inScope($app, $cid)) {
                return self::err(YxApi::ERR_NOTFOUND, '该终端用户不存在');
            }
            $c = YxDb::one('SELECT id, username, balance, credit FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid]);
            return self::ok([
                'customer_id'    => (int)$c['id'],
                'external_user_id' => $externalUser,
                'balance'        => (float)$c['balance'],
                'credit'         => (float)$c['credit'],
            ]);
        }

        // 未指定终端用户：客户自用型应用返回自身余额
        if ($app['owner_type'] === 'customer') {
            $c = YxDb::one('SELECT id, username, balance, credit FROM ' . YxDb::table('customer') . ' WHERE id = ?', [(int)$app['owner_id']]);
            if ($c) {
                return self::ok([
                    'customer_id' => (int)$c['id'],
                    'balance'     => (float)$c['balance'],
                    'credit'      => (float)$c['credit'],
                ]);
            }
        }
        return self::err(YxApi::ERR_PARAM, '请提供 external_user_id');
    }

    private static function licenseQuery(array $req, array $app): array
    {
        $domain = normalize_domain((string)($req['domain'] ?? ''));
        if ($domain === '') {
            return self::err(YxApi::ERR_PARAM, '缺少 domain');
        }
        $r = YxLicense::query($domain);
        return self::ok([
            'domain'      => $r['domain'],
            'authorized'  => (bool)$r['authorized'],
            'status'      => $r['status'],
            'message'     => $r['message'],
            'edition'     => $r['edition'],
            'expire_at'   => $r['expire_at'],
            'source'      => $r['source'],
        ], $r['message']);
    }
}
