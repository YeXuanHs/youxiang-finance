<?php
/**
 * 优享财务系统 · 结构与数据升级工具
 *
 * 用途：已有站点升级到新版本代码后，补齐新增的表、字段与索引。
 * 特性：只做加法，不删列、不删表、不清空数据，可在生产库直接执行。
 *
 * 用法：
 *   php tools/upgrade.php                 # 执行增量升级
 *   php tools/upgrade.php --dry-run       # 只列出将要执行的变更，不实际修改
 *   php tools/upgrade.php --token=xxx     # 通过 URL 触发（需与系统设置中的 cron_token 一致）
 */

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';

$cli = PHP_SAPI === 'cli';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expected = (string)setting('cron_token', '');
    $token    = (string)($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit("forbidden\n");
    }
}

$dryRun = $cli && in_array('--dry-run', $argv ?? [], true);

echo "优享财务系统 · 结构升级\n";
echo "数据库驱动：" . YxDb::driver() . "\n";
echo "表前缀：" . YxDb::prefix() . "\n";
echo $dryRun ? "模式：试运行（不会写入任何变更）\n\n" : "模式：正式执行\n\n";

try {
    if ($dryRun) {
        // 试运行：对比 schema 定义与实际库结构，仅输出差异
        $defs   = require $root . '/includes/schema.php';
        $pdo    = YxDb::pdo();
        $driver = YxDb::driver();
        $plan   = [];

        foreach ($defs as $table => $def) {
            $real = YxDb::prefix() . $table;
            if (!YxSchema::tableExists($pdo, $driver, $real)) {
                $plan[] = "将建表 {$real}（{$def['comment']}）";
                continue;
            }
            $have = YxSchema::existingColumns($pdo, $driver, $real);
            foreach ($def['columns'] as $col => $spec) {
                if (!in_array(strtolower($col), $have, true)) {
                    $plan[] = "将补列 {$real}.{$col}";
                }
            }
        }

        if (!$plan) {
            echo "数据库结构已是最新，无需变更。\n";
        } else {
            echo "待执行变更（共 " . count($plan) . " 项）：\n";
            foreach ($plan as $line) {
                echo "  - {$line}\n";
            }
        }
        exit(0);
    }

    $log = YxSchema::sync(YxDb::pdo(), YxDb::driver());
    if (YxDb::driver() === 'sqlite') {
        foreach (YxSchema::sqliteIndexes() as $sql) {
            YxDb::pdo()->exec($sql);
        }
    }

    if (!$log) {
        echo "数据库结构已是最新，无需变更。\n";
    } else {
        echo "已完成 " . count($log) . " 项变更：\n";
        foreach ($log as $line) {
            echo "  - {$line}\n";
        }
    }

    // 为历史客户补写 5 位用户编号（幂等：已有编号的不动）
    try {
        $real = YxDb::table('customer');
        $todo = YxDb::all(
            'SELECT id FROM ' . $real . ' WHERE user_no IS NULL OR user_no = 0 ORDER BY id ASC'
        );
        if ($todo) {
            $next = customer_next_no();
            YxDb::begin();
            foreach ($todo as $row) {
                while (YxDb::value('SELECT COUNT(*) FROM ' . $real . ' WHERE user_no = ?', [$next])) {
                    $next++;
                }
                YxDb::update('customer', ['user_no' => $next], 'id = :id', ['id' => (int)$row['id']]);
                $next++;
            }
            YxDb::commit();
            echo '已为 ' . count($todo) . " 位历史客户补写 5 位用户编号。\n";
        }

        // 老库补列时无法携带 UNIQUE 约束，这里显式补建唯一索引，防止并发下编号重复
        $driver = YxDb::driver();
        $idx    = 'ux_' . $real . '_user_no';
        try {
            if ($driver === 'sqlite') {
                YxDb::pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS `' . $idx . '` ON `' . $real . '` (`user_no`)');
            } else {
                $have = YxSchema::existingIndexes(YxDb::pdo(), $driver, $real);
                if (!in_array(strtolower($idx), $have, true)) {
                    YxDb::pdo()->exec('ALTER TABLE `' . $real . '` ADD UNIQUE INDEX `' . $idx . '` (`user_no`)');
                }
            }
        } catch (Throwable $e) {
            echo '用户编号唯一索引跳过：' . $e->getMessage() . "\n";
        }
    } catch (Throwable $e) {
        YxDb::rollback();
        echo '用户编号补写跳过：' . $e->getMessage() . "\n";
    }

    // 补齐可能缺失的默认设置项（幂等）
    // 注意：license_mode 必须跟随发行包的配置默认值，不能硬编码为 official，
    //       否则开源版（config 默认 community）在升级后会被静默改回正式版并开始强制校验授权。
    $defaults = [
        'license_mode'      => (string)config('license.mode', 'official'),
        'license_enforce'   => '0',
        'auto_renew'        => '0',
        'upstream_auto'     => '0',
        'api_enabled'       => '1',
    ];
    $added = 0;
    foreach ($defaults as $k => $v) {
        if (YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('setting') . ' WHERE k = ?', [$k]) == 0) {
            set_setting($k, $v);
            $added++;
        }
    }
    if ($added > 0) {
        echo "已补齐 {$added} 项默认设置。\n";
    }

    // 补齐新增功能所需的默认内容（幂等：已存在的不覆盖）
    try {
        $before = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('about_item'));
        YxAbout::seedDefaults();
        $after = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('about_item'));
        if ($after > $before) {
            echo '已写入「关于我们」默认内容 ' . ($after - $before) . " 条。\n";
        }
    } catch (Throwable $e) {
        echo '「关于我们」默认内容写入跳过：' . $e->getMessage() . "\n";
    }

    // 首页横幅默认内容（幂等：表里已有数据时不动）
    try {
        $before = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('banner'));
        YxBanner::seedDefaults();
        $after = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('banner'));
        if ($after > $before) {
            echo '已写入首页默认横幅 ' . ($after - $before) . " 条。\n";
        }
    } catch (Throwable $e) {
        echo '首页默认横幅写入跳过：' . $e->getMessage() . "\n";
    }

    // 展示广告位默认内容（幂等：已有广告位时不动）
    try {
        $before = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ad_slot'));
        YxAd::seedDefaults();
        $after = (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('ad_slot'));
        if ($after > $before) {
            echo '已写入默认展示广告位 ' . ($after - $before) . " 个。\n";
        }
    } catch (Throwable $e) {
        echo '默认展示广告位写入跳过：' . $e->getMessage() . "\n";
    }

    echo "\n升级完成。\n";
} catch (Throwable $e) {
    echo "升级失败：" . $e->getMessage() . "\n";
    exit(1);
}
