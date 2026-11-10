<?php
/**
 * 优享财务系统 · 代理自助购买
 *
 * 代理（agent）独立登录后，按「代理折扣」价自助下单；订单归属代理关联的客户账号，
 * 并带 agent_id 走既有渠道佣金结算。代理价 = 产品周期价 × (1 - 代理折扣%)。
 */

class YxAgent
{
    public static function byId(int $id): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$id]);
        return $r ?: null;
    }

    /** 全局代理折扣（%） */
    public static function discount(): float
    {
        $v = (float)setting('agent_discount', 0);
        return ($v > 0 && $v < 100) ? round($v, 2) : 0.0;
    }

    /** 代理价（周期价按折扣，含初装费由调用方另加） */
    public static function priceFor(array $product, string $cycle): float
    {
        $base = YxBusiness::cyclePrice($product, $cycle);
        return round_money(max(0, $base * (1 - self::discount() / 100)));
    }

    /** 确保代理拥有可用于下单的客户账号，返回 customer_id */
    public static function ensureCustomer(array $agent): int
    {
        $cid = (int)($agent['customer_id'] ?? 0);
        if ($cid > 0 && YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$cid])) {
            return $cid;
        }
        $uname = (string)$agent['username'];
        $i = 0;
        while (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('customer') . ' WHERE username = ?', [$uname])) {
            $i++;
            $uname = $agent['username'] . '-ag' . $i;
            if ($i > 100) {
                break;
            }
        }
        $cid = customer_insert([
            'username'   => $uname,
            'email'      => '',
            'phone'      => null,
            'password'   => (string)$agent['password'], // 复用代理密码哈希
            'company'    => (string)$agent['name'],
            'balance'    => 0,
            'credit'     => 0,
            'points'     => 0,
            'level'      => 'agent',
            'agent_id'   => 0,
            'status'     => 1,
            'reg_ip'     => client_ip(),
            'created_at' => now(),
        ]);
        YxDb::update('agent', ['customer_id' => $cid], 'id = :id', ['id' => (int)$agent['id']]);
        return $cid;
    }

    /** 代理自助下单（产品 + 周期） */
    public static function placeOrder(array $agent, array $product, string $cycle): array
    {
        if (!isset(YxBusiness::CYCLES[$cycle])) {
            $cycle = $product['cycle'];
        }
        if ((int)$product['stock'] === 0) {
            return ['ok' => false, 'msg' => '该产品库存不足'];
        }
        $cid   = self::ensureCustomer($agent);
        $price = self::priceFor($product, $cycle) + (float)$product['setup_fee'];

        $created = YxBusiness::createOrder([
            'customer_id' => $cid,
            'type'        => 'new',
            'product_id'  => (int)$product['id'],
            'name'        => $product['name'] . '（' . YxBusiness::cycleName($cycle) . '）',
            'amount'      => $price,
            'agent_id'    => (int)$agent['id'],
            'note'        => '代理自助下单：' . $agent['name'],
        ]);
        if (!empty($created['ok'])) {
            $created['customer_id'] = $cid;
        }
        return $created;
    }

    public static function myOrders(int $customerId): array
    {
        return YxDb::all('SELECT * FROM ' . YxDb::table('order') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$customerId]);
    }

    public static function myServices(int $customerId): array
    {
        return YxDb::all(
            'SELECT s.*, p.name AS product_name FROM ' . YxDb::table('service') . ' s
             LEFT JOIN ' . YxDb::table('product') . ' p ON p.id = s.product_id
             WHERE s.customer_id = ? ORDER BY s.id DESC LIMIT 100',
            [$customerId]
        );
    }
}
