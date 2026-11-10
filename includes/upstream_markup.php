<?php
/**
 * 优享财务系统 · 上游对接代理等级加价（插件：upstream-agent-markup）
 *
 * 说明：
 *  - 代理「等级」在本系统中属于插件自定义维度，不写入 yx_agent 表，
 *    以设置项 upstream_markup_levels 保存，避免改动既有表结构与其它模块的 SQL。
 *  - 加价规则：每级可选固定额（fixed）或百分比（percent）；
 *    加价基数可选上游成本价（cost）或当前售价（sale）。
 *  - 生成新订单时把届时生效的加价写入订单 upstream_markup 字段，
 *    已生成的订单不受后续规则调整影响（可用 orderMarkup() 回读）。
 */

class YxUpstreamMarkup
{
    /** 商城应用标识（购买后解锁） */
    const APP_KEY = 'upstream-agent-markup';

    /** 本插件自己的功能位：定位为普惠版可用，不占用 upstream（专业版）门禁 */
    const FEATURE = 'upstream.markup';

    const SET_ENABLED  = 'upstream_markup_enabled';
    const SET_LEVELS   = 'upstream_markup_levels';
    const SET_BASE     = 'upstream_markup_base';
    const SET_AGENTS   = 'upstream_markup_agents';

    /* ------------------------------ 配置读写 ------------------------------ */

    public static function enabled(): bool
    {
        return setting(self::SET_ENABLED, '0') === '1';
    }

    public static function setEnabled(bool $on): void
    {
        set_setting(self::SET_ENABLED, $on ? '1' : '0');
    }

    /** 加价基数：cost（上游成本价）| sale（当前售价） */
    public static function baseMode(): string
    {
        $v = (string)setting(self::SET_BASE, 'cost');
        return in_array($v, ['cost', 'sale'], true) ? $v : 'cost';
    }

    public static function baseName(string $mode = null): string
    {
        return ($mode ?? self::baseMode()) === 'sale' ? '当前售价' : '上游成本价';
    }

    public static function setBaseMode(string $mode): void
    {
        set_setting(self::SET_BASE, in_array($mode, ['cost', 'sale'], true) ? $mode : 'cost');
    }

    /**
     * 等级规则列表
     * @return array<int,array{key:string,name:string,type:string,value:float,note:string}>
     */
    public static function levels(): array
    {
        $raw = json_decode((string)setting(self::SET_LEVELS, '[]'), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key  = self::safeKey((string)($row['key'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if ($key === '' || $name === '') {
                continue;
            }
            $type = ($row['type'] ?? '') === 'percent' ? 'percent' : 'fixed';
            $val  = round_money(max(0, (float)($row['value'] ?? 0)));
            $out[] = [
                'key'   => $key,
                'name'  => mb_substr($name, 0, 40),
                'type'  => $type,
                'value' => $val,
                'note'  => mb_substr(trim((string)($row['note'] ?? '')), 0, 120),
            ];
        }
        return $out;
    }

    public static function levelMap(): array
    {
        $map = [];
        foreach (self::levels() as $lv) {
            $map[$lv['key']] = $lv;
        }
        return $map;
    }

    public static function level(string $key): ?array
    {
        return self::levelMap()[$key] ?? null;
    }

    /**
     * 保存等级规则（整表覆盖）
     * @param array $rows [{key,name,type,value,note}]
     * @return array ok,msg,levels
     */
    public static function saveLevels(array $rows): array
    {
        $clean = [];
        $seen  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key  = self::safeKey((string)($row['key'] ?? ''));
            if ($key === '') {
                $key = self::autoKey($name, $seen);
            }
            if (isset($seen[$key])) {
                return ['ok' => false, 'msg' => '等级标识「' . $key . '」重复，请修改后再保存'];
            }
            $seen[$key] = true;

            $type = ($row['type'] ?? '') === 'percent' ? 'percent' : 'fixed';
            $val  = (float)($row['value'] ?? 0);
            if ($val < 0) {
                return ['ok' => false, 'msg' => '等级「' . $name . '」的加价数值不能为负数'];
            }
            if ($type === 'percent' && $val > 500) {
                return ['ok' => false, 'msg' => '等级「' . $name . '」的百分比加价不能超过 500%'];
            }
            $clean[] = [
                'key'   => $key,
                'name'  => mb_substr($name, 0, 40),
                'type'  => $type,
                'value' => round_money($val),
                'note'  => mb_substr(trim((string)($row['note'] ?? '')), 0, 120),
            ];
        }
        set_setting(self::SET_LEVELS, json_encode($clean, JSON_UNESCAPED_UNICODE));
        return ['ok' => true, 'msg' => '等级规则已保存（共 ' . count($clean) . ' 级）', 'levels' => $clean];
    }

    private static function safeKey(string $key): string
    {
        $key = strtolower(trim($key));
        return preg_match('/^[a-z0-9_-]{1,32}$/', $key) ? $key : '';
    }

    private static function autoKey(string $name, array $seen): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name) ?: '');
        $base = $base !== '' ? substr($base, 0, 20) : 'lv';
        $k = $base;
        $i = 0;
        while (isset($seen[$k])) {
            $k = $base . '-' . (++$i);
        }
        return $k;
    }

    /* ------------------------------ 加价计算 ------------------------------ */

    /**
     * 按等级计算加价
     * @param string $levelKey 等级标识，空表示无等级（不加价）
     * @param float  $base     加价基数（成本价或售价）
     * @return array ok, level, amount, cost, sale, rate, msg
     */
    public static function quote(string $levelKey, float $base): array
    {
        $base = round_money(max(0, $base));
        $out  = ['ok' => true, 'level' => null, 'amount' => 0.0, 'cost' => $base, 'sale' => $base, 'msg' => ''];

        if (!self::enabled()) {
            $out['msg'] = '插件未启用，按原价';
            return $out;
        }
        if ($levelKey === '') {
            $out['msg'] = '代理未设置等级，按原价';
            return $out;
        }
        $lv = self::level($levelKey);
        if (!$lv) {
            $out['msg'] = '等级「' . $levelKey . '」不存在，按原价';
            return $out;
        }
        $out['level'] = $lv;

        $extra = $lv['type'] === 'percent'
            ? $base * ($lv['value'] / 100)
            : (float)$lv['value'];
        $extra = round_money(max(0, $extra));
        $sale  = round_money($base + $extra);

        // 成本价高于售价时不允许用「售价」口径把亏损放大，仅保证售价不为负
        $out['amount'] = $extra;
        $out['sale']   = $sale;
        $out['msg']    = $lv['name'] . '：' . self::ruleText($lv);
        return $out;
    }

    /** 规则文字，如「固定 +10.00 元」「百分比 +8.00%」 */
    public static function ruleText(array $lv): string
    {
        if (($lv['type'] ?? '') === 'percent') {
            return '百分比 +' . rtrim(rtrim(number_format((float)$lv['value'], 2, '.', ''), '0'), '.') . '%';
        }
        return '固定 +' . money($lv['value'], true);
    }

    /**
     * 订单入账的加价快照
     * @param array|null $level 等级行，null 表示不加价
     */
    public static function snapshot(?array $level, float $base): ?string
    {
        if (!$level || !self::enabled()) {
            return null;
        }
        return json_encode([
            'level'  => $level['key'],
            'name'   => $level['name'],
            'type'   => $level['type'],
            'value'  => $level['value'],
            'base'   => self::baseMode(),
            'cost'   => round_money($base),
            'amount' => (float)self::quote($level['key'], $base)['amount'],
            'rate'   => ($level['type'] === 'percent') ? $level['value'] : 0,
            'at'     => now(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /* ------------------------------ 上游连通性自动检测 ------------------------------ */

    /**
     * 检测全部启用的平台上游与客户对接，结果回写 last_test_*
     * @param int $limit 最多检测数量，0 表示不限
     * @return array ok,items,total,failed,msg
     */
    public static function checkAll(int $limit = 0): array
    {
        $items   = [];
        $failed  = 0;
        $total   = 0;
        $sources = [
            ['key' => 'upstream', 'table' => 'upstream', 'label' => '平台上游'],
            ['key' => 'customer', 'table' => 'customer_upstream', 'label' => '客户对接'],
        ];
        foreach ($sources as $src) {
            $sql = 'SELECT id, name, status FROM ' . YxDb::table($src['table']) . ' ORDER BY id ASC';
            $rows = YxDb::all($sql) ?: [];
            foreach ($rows as $row) {
                if ($limit > 0 && $total >= $limit) {
                    break 2;
                }
                $total++;
                $id  = (int)$row['id'];
                try {
                    $r = ($src['key'] === 'upstream')
                        ? YxUpstream::test($id)
                        : YxCustomerUpstream::adminTest($id);
                } catch (Throwable $ex) {
                    $r = ['ok' => false, 'msg' => '异常：' . $ex->getMessage()];
                }
                $ok = !empty($r['ok']);
                if (!$ok) {
                    $failed++;
                }
                $items[] = [
                    'source' => $src['label'],
                    'id'     => $id,
                    'name'   => (string)$row['name'],
                    'ok'     => $ok,
                    'msg'    => mb_substr((string)($r['msg'] ?? ''), 0, 200),
                ];
            }
        }
        set_setting('upstream_last_check_at', now());
        return [
            'ok'     => $failed === 0,
            'items'  => $items,
            'total'  => $total,
            'failed' => $failed,
            'msg'    => $failed === 0
                ? sprintf('检测完成：%d 个对接全部正常', $total)
                : sprintf('检测完成：%d 个对接中 %d 个异常', $total, $failed),
        ];
    }

    /** 上次自动检测时间 */
    public static function lastCheckAt(): string
    {
        return (string)setting('upstream_last_check_at', '');
    }

    /* ------------------------------ 代理 / 客户等级 ------------------------------ */

    /**
     * 客户所属代理的等级 key（未关联代理或代理未设等级时为 ''）
     */
    public static function agentLevelKeyForCustomer(int $customerId): string
    {
        if ($customerId <= 0) {
            return '';
        }
        $agentId = (int)YxDb::value('SELECT COALESCE(agent_id, 0) FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);
        if ($agentId <= 0) {
            return '';
        }
        $lv = self::levelOfAgent($agentId);
        return $lv ? (string)$lv['key'] : '';
    }

    /** 代理自身等级 */
    public static function levelOfAgent(int $agentId): ?array
    {
        if ($agentId <= 0) {
            return null;
        }
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('agent') . ' WHERE id = ?', [$agentId]);
        if (!$row) {
            return null;
        }
        $key = trim((string)($row['level_key'] ?? ''));
        return $key === '' ? null : self::level($key);
    }

    /** 客户等级标识（作为 applyForCustomer 的入参） */
    public static function levelForCustomer(int $customerId): string
    {
        return self::agentLevelKeyForCustomer($customerId);
    }

    /**
     * 客户下单时应用加价：返回加价后的金额与订单快照
     * @return array ok amount cost added level msg snapshot
     */
    public static function applyForCustomer(int $customerId, float $base): array
    {
        $res  = self::quote(self::agentLevelKeyForCustomer($customerId), $base);
        $snap = self::snapshot($res['level'], $base);
        return [
            'ok'       => true,
            'amount'   => (float)$res['sale'],   // 客户实付
            'cost'     => (float)$res['cost'],
            'added'    => (float)$res['amount'],
            'level'    => $res['level'],
            'msg'      => $res['msg'],
            'snapshot' => $snap,
        ];
    }

    /** 订单已记录的加价快照（下单时写入，规则调整后仍可回溯） */
    public static function orderMarkup(array $order): ?array
    {
        $raw = trim((string)($order['upstream_markup'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /* ------------------------------ 授权 ------------------------------ */

    public static function app(): ?array
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('app') . ' WHERE key = ?', [self::APP_KEY]);
        return $row ?: null;
    }

    /** 管理员可直接使用；客户需已购买该应用 */
    public static function canUse(?int $customerId = null): bool
    {
        $app = self::app();
        if (!$app || (string)$app['status'] !== 'on') {
            return false;
        }
        if (YxEdition::rank() < YxEdition::rank((string)$app['edition_min'])) {
            return false;
        }
        if ($customerId === null) {
            return true;
        }
        return YxBusiness::entitlement((int)$app['id'], $customerId) !== null;
    }

    public static function licensed(int $customerId): bool
    {
        return self::canUse($customerId);
    }
}
