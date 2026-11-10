<?php
/**
 * 优享财务系统 · 定时任务
 *
 * 建议在宝塔面板「计划任务」中配置（每 5-10 分钟执行一次）：
 *   类型：Shell 脚本
 *   命令：php /www/wwwroot/你的站点目录/tools/cron.php --token=后台生成的任务令牌
 *
 * 也可用 URL 方式触发（需令牌）：
 *   https://你的域名/tools/cron.php?token=xxxx
 *
 * 执行内容：
 *   1. 将已过到期日的业务标记为「已到期」
 *   2. 自动续费（需在系统设置开启 auto_renew=1，且客户余额充足）
 *   3. 执行异步任务队列（上游开通/续费/开关机/重装/销毁、下游事件回调，含失败重试）
 *   4. 清理过期的接口防重放串、接口日志与历史任务
 *   5. 上游对接连通性自动检测（插件 upstream-agent-markup 启用时，按设定间隔执行）
 *   6. 刷新授权缓存并记录巡检日志
 */

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';

$cli   = PHP_SAPI === 'cli';
$token = $cli ? '' : (string)($_GET['token'] ?? '');

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expected = (string)setting('cron_token', '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit("forbidden\n");
    }
}

$log = [];
$log[] = '[' . now() . '] 开始执行定时任务';

/* 0. 支持显式指定站点域名（CLI 下授权巡检需要） */
$domainArg = '';
if (PHP_SAPI === 'cli') {
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, '--domain=') === 0) {
            $domainArg = normalize_domain(substr($arg, 9));
        }
    }
}
$siteDomain = $domainArg !== '' ? $domainArg : current_domain();
$log[] = '· 站点域名：' . ($siteDomain !== '' ? $siteDomain : '(未配置)');

/* 1. 业务到期标记 */
try {
    $n = YxBusiness::markExpired();
    $log[] = "· 标记到期业务：{$n} 个";
} catch (Throwable $e) {
    $log[] = '· 标记到期业务失败：' . $e->getMessage();
}

/* 2. 自动续费
 *
 * 金额安全要点（实现见 YxBusiness::runAutoRenew）：
 *   - 先 claimRenew() 原子抢占期限，再用带条件的 debit 扣费：并发下只有一个进程能扣到钱
 *   - 扣费失败立即 rollbackRenew() 归还期限，保持原到期日，下次 cron 继续尝试
 *   - 整段包在 try/catch 中，异常不影响后续任务队列与巡检
 */
try {
    $autoRenew = setting('auto_renew', '0') === '1';
    if (!$autoRenew) {
        $log[] = '· 自动续费未开启（系统设置 auto_renew=0）';
    } elseif (!YxEdition::can('business.renew')) {
        $log[] = '· 自动续费已跳过（当前版本不支持）';
    } else {
        $ahead = max(0, (int)setting('renew_ahead_days', '0'));
        $res   = YxBusiness::runAutoRenew($ahead, 200);

        $log[] = sprintf(
            '· 自动续费：成功 %d 个，跳过 %d 个（提前 %d 天，阈值 %s）',
            $res['charged'],
            $res['skipped'],
            $ahead,
            $res['cutoff']
        );
        foreach (array_slice($res['details'], 0, 15) as $line) {
            $log[] = '    - ' . $line;
        }
        if (count($res['details']) > 15) {
            $log[] = '    - …（其余 ' . (count($res['details']) - 15) . ' 条略，可在账单与收支流水中核对）';
        }
    }
} catch (Throwable $e) {
    $log[] = '· 自动续费执行异常（已隔离，不影响后续任务）：' . $e->getMessage();
}

/* 3. 执行异步任务队列（上游开通/续费/操作、下游回调） */
try {
    $stat = YxTask::run(30);
    $log[] = sprintf(
        '· 任务队列：成功 %d，待重试 %d，失败 %d（待执行 %d）',
        $stat['done'],
        $stat['retry'],
        $stat['failed'],
        YxTask::stats()['pending']
    );
    foreach (array_slice($stat['items'], 0, 10) as $item) {
        $log[] = '    - ' . $item;
    }
} catch (Throwable $e) {
    $log[] = '· 任务队列执行失败：' . $e->getMessage();
}

/* 4. 清理接口防重放串与历史日志、历史任务 */
try {
    $p = YxApi::purge();
    $t = YxTask::purge(30);
    $log[] = "· 清理：nonce {$p['nonce']} 条，接口日志 {$p['log']} 条，历史任务 {$t} 条";
} catch (Throwable $e) {
    $log[] = '· 清理失败：' . $e->getMessage();
}

/* 5. 上游对接连通性自动检测（插件 upstream-agent-markup）
 *
 * 仅在插件启用且距上次检测超过 interval 分钟时执行，避免每次 cron 都打上游接口。
 */
try {
    if (!YxUpstreamMarkup::enabled()) {
        $log[] = '· 上游自动检测：已跳过（加价插件未启用）';
    } else {
        $interval = max(5, min(1440, (int)setting('upstream_check_interval', '30')));
        $last     = YxUpstreamMarkup::lastCheckAt();
        $due      = $last === '' || (time() - (int)strtotime($last)) >= $interval * 60;
        if (!$due) {
            $log[] = '· 上游自动检测：未到间隔（' . $interval . ' 分钟，上次 ' . $last . '）';
        } else {
            $r = YxUpstreamMarkup::checkAll();
            $log[] = '· 上游自动检测：' . $r['msg'];
            foreach ($r['items'] as $it) {
                $log[] = '    - ' . ($it['ok'] ? '✅' : '❌') . ' ' . $it['source'] . '「' . $it['name'] . '」' . $it['msg'];
            }
        }
    }
} catch (Throwable $e) {
    $log[] = '· 上游自动检测失败：' . $e->getMessage();
}

/* 6. 授权巡检 */
try {
    if (YxLicense::isCommunity()) {
        $log[] = '· 授权巡检：已跳过（开源版未启用授权模块）';
    } else {
        YxLicense::flushCache();
        $r = YxLicense::query($siteDomain, true);
        YxLicense::log($siteDomain, $r['status'], $r['source'], '定时巡检');
        $log[] = '· 授权巡检：' . $r['message'] . '（域名 ' . $siteDomain . '，来源 ' . $r['source'] . '）';
    }
} catch (Throwable $e) {
    $log[] = '· 授权巡检失败：' . $e->getMessage();
}

/* 7. 搜索引擎主动推送（百度 / 神马 / IndexNow）
 *
 * 限流由 YxSeoPush::runScheduled 内部完成：同一通道每日最多成功推送一次，
 * 距上次执行不足设定间隔则整体跳过，避免高频提交被平台判为作弊。
 */
try {
    $seo = YxSeoPush::runScheduled();
    foreach ($seo['lines'] as $line) {
        $log[] = $line;
    }
} catch (Throwable $e) {
    $log[] = '· 搜索引擎推送失败：' . $e->getMessage();
}

$log[] = '[' . now() . '] 定时任务执行完成';
echo implode("\n", $log) . "\n";

if (setting('cron_log', '1') === '1') {
    @file_put_contents($root . '/runtime/cron.log', implode("\n", $log) . "\n", FILE_APPEND | LOCK_EX);
}
