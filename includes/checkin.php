<?php
/**
 * 优享财务系统 · 每日签到
 *
 * 规则（后台「签到管理」可配置）：
 *  - checkin_enabled        签到开关（1 开 / 0 关）
 *  - checkin_points         每日签到积分
 *  - checkin_streak_days    连续签到奖励周期（天）
 *  - checkin_streak_bonus   每满一个周期的额外奖励积分
 * 积分累加到 customer.points；同一天仅可签到一次（业务层去重）。
 */

class YxCheckin
{
    public static function enabled(): bool
    {
        return (string)setting('checkin_enabled', '1') === '1';
    }

    public static function points(): int
    {
        return max(0, (int)setting('checkin_points', 5));
    }

    public static function streakDays(): int
    {
        return max(2, (int)setting('checkin_streak_days', 7));
    }

    public static function streakBonus(): int
    {
        return max(0, (int)setting('checkin_streak_bonus', 10));
    }

    /** 今日签到记录 */
    public static function todayLog(int $customerId): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('checkin_log') . ' WHERE customer_id = ? AND check_date = ?', [$customerId, date('Y-m-d')]);
        return $r ?: null;
    }

    public static function lastLog(int $customerId): ?array
    {
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('checkin_log') . ' WHERE customer_id = ? ORDER BY check_date DESC LIMIT 1', [$customerId]);
        return $r ?: null;
    }

    /** 概览统计 */
    public static function stats(int $customerId): array
    {
        $totalPoints = (int)YxDb::value('SELECT COALESCE(SUM(points),0) FROM ' . YxDb::table('checkin_log') . ' WHERE customer_id = ?', [$customerId]);
        $days        = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('checkin_log') . ' WHERE customer_id = ?', [$customerId]);
        $monthDays   = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('checkin_log') . ' WHERE customer_id = ? AND check_date LIKE ?', [$customerId, date('Y-m') . '-%']);
        $today       = self::todayLog($customerId);

        $streak = 0;
        if ($today) {
            $streak = (int)$today['streak'];
        } else {
            $last = self::lastLog($customerId);
            if ($last && $last['check_date'] === date('Y-m-d', strtotime('-1 day'))) {
                $streak = (int)$last['streak'];
            }
        }

        return [
            'checked'      => (bool)$today,
            'streak'       => $streak,
            'total_points' => $totalPoints,
            'days'         => $days,
            'month_days'   => $monthDays,
        ];
    }

    /** 执行签到 */
    public static function checkin(int $customerId): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '签到功能未开启'];
        }
        if (self::todayLog($customerId)) {
            return ['ok' => false, 'msg' => '今天已经签到过了，明天再来'];
        }

        $last   = self::lastLog($customerId);
        $streak = 1;
        if ($last && $last['check_date'] === date('Y-m-d', strtotime('-1 day'))) {
            $streak = (int)$last['streak'] + 1;
        }

        $points = self::points();
        $bonus  = 0;
        if (self::streakBonus() > 0 && $streak > 0 && $streak % self::streakDays() === 0) {
            $bonus = self::streakBonus();
        }
        $gain = $points + $bonus;

        // 原子插入：WHERE NOT EXISTS 与插入在同一条语句内完成，
        // 并发重复提交时只有首个请求能写入，后续请求影响行数为 0，避免重复加分。
        // （checkin_log 在 customer_id 上有索引，InnoDB 会对该范围加锁，阻止并发幻读插入。）
        $table    = YxDb::table('checkin_log');
        $date     = date('Y-m-d');
        $ownTx    = !YxDb::inTransaction();
        if ($ownTx) {
            YxDb::begin();
        }
        $inserted = YxDb::exec(
            'INSERT INTO ' . $table . ' (customer_id, check_date, points, streak, ip, created_at)'
            . ' SELECT ?, ?, ?, ?, ?, ? FROM (SELECT 1) AS t'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $table . ' WHERE customer_id = ? AND check_date = ?)',
            [$customerId, $date, $gain, $streak, client_ip(), now(), $customerId, $date]
        );
        if ($inserted < 1) {
            if ($ownTx) {
                YxDb::rollback();
            }
            return ['ok' => false, 'msg' => '今天已经签到过了，明天再来'];
        }

        if ($gain > 0) {
            YxDb::exec('UPDATE ' . YxDb::table('customer') . ' SET points = points + ? WHERE id = ?', [$gain, $customerId]);
        }
        if ($ownTx) {
            YxDb::commit();
        }

        $tip = '签到成功，获得 ' . $gain . ' 积分';
        if ($bonus > 0) {
            $tip .= '（连续签到 ' . $streak . ' 天，额外奖励 ' . $bonus . ' 积分）';
        }
        return ['ok' => true, 'msg' => $tip, 'points' => $gain, 'streak' => $streak, 'bonus' => $bonus];
    }

    /** 指定月份的签到日历：['YYYY-MM-DD' => 积分] */
    public static function calendar(int $customerId, string $month = null): array
    {
        $month = $month ?: date('Y-m');
        $rows  = YxDb::all(
            'SELECT check_date, points FROM ' . YxDb::table('checkin_log') . ' WHERE customer_id = ? AND check_date LIKE ?',
            [$customerId, $month . '-%']
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['check_date']] = (int)$r['points'];
        }
        return $map;
    }
}
