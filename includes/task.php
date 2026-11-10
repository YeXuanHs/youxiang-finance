<?php
/**
 * 优享财务系统 · 异步任务队列
 *
 * 上游接口调用耗时且可能失败，因此统一走队列：
 *   业务动作 → YxTask::push() 入队 → cron 触发 YxTask::run() → 执行 → 成功/重试/失败
 *
 * 特性：
 *   - 同类型同对象去重（避免重复开通）
 *   - 令牌式抢占（UPDATE ... WHERE status='pending' 原子加锁），支持并发 worker
 *   - 指数退避重试，超过上限标记 failed
 *   - 卡死任务自动释放（运行超过 LOCK_TTL 秒）
 */

class YxTask
{
    /** 退避间隔（秒） */
    const BACKOFF = [60, 300, 900, 3600, 7200];

    /** 运行中任务的锁超时（秒） */
    const LOCK_TTL = 600;

    /** 可用任务类型 */
    public static function types(): array
    {
        return [
            'upstream.create'    => '上游开通',
            'upstream.renew'     => '上游续费',
            'upstream.power'     => '上游开关机',
            'upstream.reinstall' => '上游重装',
            'upstream.destroy'   => '上游销毁',
            'upstream.sync'      => '上游状态同步',
            'downstream.notify'  => '下游回调通知',
        ];
    }

    public static function typeName(string $type): string
    {
        return self::types()[$type] ?? $type;
    }

    public static function statusName(string $status): string
    {
        $map = ['pending' => '待执行', 'running' => '执行中', 'done' => '已完成', 'failed' => '已失败', 'cancelled' => '已取消'];
        return $map[$status] ?? $status;
    }

    /**
     * 入队
     *
     * @param string $type  任务类型
     * @param array  $opt   ref_type/ref_id/upstream_id/payload/max_attempts/delay/unique
     * @return int 任务 ID（去重命中时返回已存在任务 ID；失败返回 0）
     */
    public static function push(string $type, array $opt = []): int
    {
        $refType    = (string)($opt['ref_type'] ?? '');
        $refId      = (int)($opt['ref_id'] ?? 0);
        $upstreamId = (int)($opt['upstream_id'] ?? 0);
        $payload    = $opt['payload'] ?? [];
        $max        = (int)($opt['max_attempts'] ?? 5);
        $delay      = max(0, (int)($opt['delay'] ?? 0));
        $unique     = array_key_exists('unique', $opt) ? (bool)$opt['unique'] : true;

        // 去重：同一类型 + 同一对象已有排队/执行中的任务则复用
        if ($unique && $refId > 0) {
            $exist = YxDb::value(
                'SELECT id FROM ' . YxDb::table('task') . " WHERE type = ? AND ref_id = ? AND status IN ('pending','running') ORDER BY id ASC LIMIT 1",
                [$type, $refId]
            );
            if ($exist) {
                return (int)$exist;
            }
        }

        try {
            return YxDb::insert('task', [
                'type'          => $type,
                'ref_type'      => $refType ?: null,
                'ref_id'        => $refId,
                'upstream_id'   => $upstreamId,
                'payload'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'status'        => 'pending',
                'attempts'      => 0,
                'max_attempts'  => $max > 0 ? $max : 5,
                'next_retry_at' => $delay > 0 ? date('Y-m-d H:i:s', time() + $delay) : now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** 释放卡死的运行中任务 */
    public static function releaseStuck(): int
    {
        return YxDb::exec(
            'UPDATE ' . YxDb::table('task') . " SET status = 'pending', locked_at = NULL, updated_at = ? WHERE status = 'running' AND locked_at IS NOT NULL AND locked_at < ?",
            [now(), date('Y-m-d H:i:s', time() - self::LOCK_TTL)]
        );
    }

    /**
     * 抢占待执行任务（原子加锁）
     *
     * @param int    $limit  最多抢占条数
     * @param string $type   只看某类任务（空 = 全部）
     * @param int    $refId  只看某个业务对象（0 = 不限）
     * @return array 已加锁的任务列表
     */
    public static function claim(int $limit = 10, string $type = '', int $refId = 0): array
    {
        $limit = max(1, min(100, $limit));
        $where = " WHERE status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= ?)";
        $bind  = [now()];
        if ($type !== '') {
            $where .= ' AND type = ?';
            $bind[] = $type;
        }
        if ($refId > 0) {
            $where .= ' AND ref_id = ?';
            $bind[] = $refId;
        }
        $candidates = YxDb::all(
            'SELECT * FROM ' . YxDb::table('task') . $where . ' ORDER BY id ASC LIMIT ' . $limit,
            $bind
        );

        $claimed = [];
        foreach ($candidates as $row) {
            $n = YxDb::exec(
                'UPDATE ' . YxDb::table('task') . " SET status = 'running', locked_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
                [now(), now(), (int)$row['id']]
            );
            if ($n > 0) {
                $row['status'] = 'running';
                $claimed[] = $row;
            }
        }
        return $claimed;
    }

    /**
     * 执行一批任务
     *
     * $type / $refId 可选，用于「支付成功后立即执行本单开通任务」等定向场景，
     * 不传时行为与原来完全一致（处理全部类型的待执行任务）。
     */
    public static function run(int $limit = 20, string $type = '', int $refId = 0): array
    {
        self::releaseStuck();

        $stat = ['done' => 0, 'retry' => 0, 'failed' => 0, 'items' => []];
        foreach (self::claim($limit, $type, $refId) as $task) {
            $res = self::handle($task);
            $id  = (int)$task['id'];

            if (!empty($res['ok'])) {
                YxDb::update('task', [
                    'status'     => 'done',
                    'attempts'   => (int)$task['attempts'] + 1,
                    'result'     => json_encode($res, JSON_UNESCAPED_UNICODE),
                    'error'      => null,
                    'locked_at'  => null,
                    'updated_at' => now(),
                ], 'id = :id', ['id' => $id]);
                $stat['done']++;
                $stat['items'][] = "#{$id} " . self::typeName($task['type']) . ' 成功';
                continue;
            }

            $attempts = (int)$task['attempts'] + 1;
            $max      = (int)$task['max_attempts'];
            $msg      = (string)($res['msg'] ?? '未知错误');

            if ($attempts >= $max) {
                YxDb::update('task', [
                    'status'     => 'failed',
                    'attempts'   => $attempts,
                    'error'      => mb_substr($msg, 0, 500),
                    'result'     => json_encode($res, JSON_UNESCAPED_UNICODE),
                    'locked_at'  => null,
                    'updated_at' => now(),
                ], 'id = :id', ['id' => $id]);
                $stat['failed']++;
                $stat['items'][] = "#{$id} " . self::typeName($task['type']) . ' 最终失败：' . $msg;
            } else {
                $delay = self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];
                YxDb::update('task', [
                    'status'        => 'pending',
                    'attempts'      => $attempts,
                    'error'         => mb_substr($msg, 0, 500),
                    'next_retry_at' => date('Y-m-d H:i:s', time() + $delay),
                    'locked_at'     => null,
                    'updated_at'    => now(),
                ], 'id = :id', ['id' => $id]);
                $stat['retry']++;
                $stat['items'][] = "#{$id} " . self::typeName($task['type']) . " 失败将 {$delay}s 后重试：{$msg}";
            }
        }
        return $stat;
    }

    /** 单条任务处理 */
    public static function handle(array $task): array
    {
        $payload = json_decode((string)($task['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $refId = (int)$task['ref_id'];

        switch ($task['type']) {
            case 'upstream.create':
                return YxProvision::create($refId, $payload);

            case 'upstream.renew':
                return YxProvision::renew($refId, (string)($payload['cycle'] ?? ''));

            case 'upstream.power':
                return YxProvision::power($refId, (string)($payload['action'] ?? ''));

            case 'upstream.reinstall':
                return YxProvision::reinstall($refId, $payload);

            case 'upstream.destroy':
                return YxProvision::destroy($refId);

            case 'upstream.sync':
                return YxProvision::sync($refId);

            case 'downstream.notify':
                return YxApiNotify::send($payload);

            default:
                return ['ok' => false, 'msg' => '未知任务类型：' . $task['type']];
        }
    }

    /** 手动重试（重置为待执行） */
    public static function retry(int $id): bool
    {
        return YxDb::exec(
            'UPDATE ' . YxDb::table('task') . " SET status = 'pending', attempts = 0, error = NULL, next_retry_at = ?, locked_at = NULL, updated_at = ? WHERE id = ? AND status IN ('failed','cancelled')",
            [now(), now(), $id]
        ) > 0;
    }

    /** 取消 */
    public static function cancel(int $id): bool
    {
        return YxDb::exec(
            'UPDATE ' . YxDb::table('task') . " SET status = 'cancelled', locked_at = NULL, updated_at = ? WHERE id = ? AND status IN ('pending','running')",
            [now(), $id]
        ) > 0;
    }

    /** 队列统计 */
    public static function stats(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0];
        try {
            foreach (YxDb::all('SELECT status, COUNT(*) AS cnt FROM ' . YxDb::table('task') . ' GROUP BY status') as $row) {
                $out[$row['status']] = (int)$row['cnt'];
            }
        } catch (Throwable $e) {
            // 表可能尚未创建
        }
        return $out;
    }

    /** 清理历史任务 */
    public static function purge(int $days = 30): int
    {
        return YxDb::exec(
            'DELETE FROM ' . YxDb::table('task') . " WHERE status IN ('done','cancelled') AND updated_at < ?",
            [date('Y-m-d H:i:s', time() - max(1, $days) * 86400)]
        );
    }
}
