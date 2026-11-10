<?php
/**
 * 优享财务系统 · 数据访问层
 *
 *  - YxDb     : PDO 单例 + 常用查询封装（全部使用预处理，杜绝拼接注入）
 *  - YxSchema : 依据 includes/schema.php 生成 MySQL / SQLite 的 DDL
 */

class YxDb
{
    /** @var PDO|null */
    private static $pdo = null;
    /** @var array */
    private static $conf = [];
    /** @var int 本次请求执行的语句数，用于自检 */
    public static $queryCount = 0;

    /**
     * 注入配置（由 config.php 调用方传入）
     * 配置发生变化时丢弃旧连接：安装向导切换数据库后必须连到新库，不能沿用旧连接。
     */
    public static function configure(array $conf): void
    {
        if (self::$conf !== $conf) {
            self::$pdo = null;
        }
        self::$conf = $conf;
    }

    public static function conf(string $key = null, $default = null)
    {
        if ($key === null) {
            return self::$conf;
        }
        return self::$conf[$key] ?? $default;
    }

    public static function driver(): string
    {
        return self::$conf['driver'] ?? 'sqlite';
    }

    public static function prefix(): string
    {
        return self::$conf['prefix'] ?? 'yx_';
    }

    /** 带前缀的真实表名 */
    public static function table(string $name): string
    {
        return self::prefix() . $name;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = self::driver();
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                self::$conf['host'] ?? '127.0.0.1',
                (int)(self::$conf['port'] ?? 3306),
                self::$conf['name'] ?? '',
                self::$conf['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, self::$conf['user'] ?? '', self::$conf['pass'] ?? '', $options);
            self::$pdo->exec("SET NAMES " . (self::$conf['charset'] ?? 'utf8mb4'));
        } else {
            $path = self::$conf['path'] ?? '';
            if ($path === '') {
                $path = dirname(__DIR__) . '/runtime/youxiang.db';
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
            // 并发写更稳，并开启外键约束
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        }

        return self::$pdo;
    }

    /** 查询多行 */
    public static function all(string $sql, array $bind = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        self::$queryCount++;
        return $st->fetchAll();
    }

    /** 查询单行 */
    public static function one(string $sql, array $bind = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        self::$queryCount++;
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** 查询单列 */
    public static function value(string $sql, array $bind = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        self::$queryCount++;
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 执行写操作，返回影响行数 */
    public static function exec(string $sql, array $bind = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        self::$queryCount++;
        return $st->rowCount();
    }

    /** 插入并返回自增 ID */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::table($table),
            implode(',', $cols),
            implode(',', array_map(static function ($c) { return ':' . $c; }, $cols))
        );
        $st = self::pdo()->prepare($sql);
        $st->execute($data);
        self::$queryCount++;
        return (int)self::pdo()->lastInsertId();
    }

    /** 更新，返回影响行数 */
    public static function update(string $table, array $data, string $where, array $bind = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = $c . '=:' . $c;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::table($table), implode(',', $sets), $where);
        $st  = self::pdo()->prepare($sql);
        $st->execute(array_merge($data, $bind));
        self::$queryCount++;
        return $st->rowCount();
    }

    public static function delete(string $table, string $where, array $bind = []): int
    {
        return self::exec(sprintf('DELETE FROM %s WHERE %s', self::table($table), $where), $bind);
    }

    /** 是否存在某张表 */
    public static function hasTable(string $table): bool
    {
        try {
            self::pdo()->query('SELECT 1 FROM ' . self::table($table) . ' LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 当前是否处于事务中 */
    public static function inTransaction(): bool
    {
        return self::pdo()->inTransaction();
    }

    public static function begin(): void
    {
        // 事务不可嵌套：已在事务中时直接复用外层事务
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }
    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}

class YxSchema
{
    /**
     * 生成全部建表语句
     * @return string[]
     */
    public static function statements(string $driver): array
    {
        $defs    = require __DIR__ . '/schema.php';
        $prefix  = YxDb::prefix();
        $out     = [];
        foreach ($defs as $table => $def) {
            $out[] = self::createTable($driver, $prefix . $table, $def);
        }
        return $out;
    }

    public static function createTable(string $driver, string $table, array $def): string
    {
        $lines = [];
        foreach ($def['columns'] as $name => $spec) {
            $lines[] = '  `' . $name . '` ' . self::column($driver, $spec);
        }
        foreach ($def['indexes'] ?? [] as $cols) {
            $cols = (array)$cols;
            $lines[] = '  KEY `idx_' . $table . '_' . implode('_', $cols) . '` (`' . implode('`,`', $cols) . '`)';
        }

        if ($driver === 'mysql') {
            return sprintf(
                "CREATE TABLE IF NOT EXISTS `%s` (\n%s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4%s",
                $table,
                implode(",\n", $lines),
                isset($def['comment']) ? " COMMENT='" . str_replace("'", '', $def['comment']) . "'" : ''
            );
        }

        // SQLite 不支持表级 KEY 定义，索引单独生成
        $lines = array_filter($lines, function ($l) {
            return strpos(trim($l), 'KEY `') !== 0;
        });
        return sprintf("CREATE TABLE IF NOT EXISTS `%s` (\n%s\n)", $table, implode(",\n", $lines));
    }

    /** SQLite 的索引需要单独创建 */
    public static function sqliteIndexes(): array
    {
        $defs   = require __DIR__ . '/schema.php';
        $prefix = YxDb::prefix();
        $out    = [];
        foreach ($defs as $table => $def) {
            foreach ($def['indexes'] ?? [] as $cols) {
                $cols = (array)$cols;
                $name = 'idx_' . $prefix . $table . '_' . implode('_', $cols);
                $out[] = sprintf(
                    'CREATE INDEX IF NOT EXISTS `%s` ON `%s` (`%s`)',
                    $name,
                    $prefix . $table,
                    implode('`,`', $cols)
                );
            }
        }
        return $out;
    }

    /** 将列描述翻译为具体方言 */
    public static function column(string $driver, string $spec): string
    {
        $parts  = explode(':', $spec);
        $type   = array_shift($parts);
        $flags  = $parts;
        $isPk   = in_array('pk', $flags, true);
        $isAi   = in_array('ai', $flags, true);
        $null   = in_array('null', $flags, true);
        $unique = in_array('unique', $flags, true);

        $default = null;
        foreach ($flags as $f) {
            if (strpos($f, 'default=') === 0) {
                $default = substr($f, 8);
            }
        }

        if (preg_match('/^(\w+)\(([\d,]+)\)$/', $type, $m)) {
            $base = $m[1];
            $args = $m[2];
        } else {
            $base = $type;
            $args = null;
        }

        if ($driver === 'mysql') {
            $map = [
                'int'      => 'INT',
                'tinyint'  => 'TINYINT',
                'bigint'   => 'BIGINT',
                'varchar'  => 'VARCHAR(' . $args . ')',
                'text'     => 'TEXT',
                'json'     => 'LONGTEXT',
                'decimal'  => 'DECIMAL(' . $args . ')',
                'datetime' => 'DATETIME',
                'date'     => 'DATE',
            ];
            $sql = $map[$base] ?? 'VARCHAR(255)';
            if ($isAi) {
                return $sql . ' NOT NULL AUTO_INCREMENT PRIMARY KEY';
            }
            $sql .= $null ? ' NULL' : ' NOT NULL';
            if ($default !== null) {
                $sql .= ' DEFAULT ' . (is_numeric($default) ? $default : "'" . $default . "'");
            }
            if ($isPk) {
                $sql .= ' PRIMARY KEY';
            }
            if ($unique) {
                $sql .= ' UNIQUE';
            }
            return $sql;
        }

        // SQLite
        $map = [
            'int'      => 'INTEGER',
            'tinyint'  => 'INTEGER',
            'bigint'   => 'INTEGER',
            'varchar'  => 'TEXT',
            'text'     => 'TEXT',
            'json'     => 'TEXT',
            'decimal'  => 'NUMERIC',
            'datetime' => 'TEXT',
            'date'     => 'TEXT',
        ];
        $sql = $map[$base] ?? 'TEXT';
        if ($isAi) {
            return 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        if ($isPk) {
            $sql .= ' PRIMARY KEY';
        }
        $sql .= $null ? '' : ' NOT NULL';
        if ($default !== null) {
            $sql .= ' DEFAULT ' . (is_numeric($default) ? $default : "'" . $default . "'");
        }
        if ($unique) {
            $sql .= ' UNIQUE';
        }
        return $sql;
    }

    /**
     * 将列描述翻译为「可用于 ALTER TABLE ADD COLUMN」的方言。
     * SQLite 不允许在 ADD COLUMN 时声明 PRIMARY KEY / UNIQUE / 自增，
     * 且新增 NOT NULL 列必须带默认值，因此这里会做安全降级。
     */
    public static function columnForAlter(string $driver, string $spec): string
    {
        $parts = explode(':', $spec);
        $type  = array_shift($parts);
        $flags = $parts;

        $hasDefault = false;
        foreach ($flags as $f) {
            if (strpos($f, 'default=') === 0) {
                $hasDefault = true;
            }
        }

        // 去掉主键/自增/唯一，保持可空
        $flags = array_values(array_filter($flags, static function ($f) {
            return !in_array($f, ['pk', 'ai', 'unique', 'null'], true);
        }));
        if (!$hasDefault) {
            $flags[] = 'null';
        }

        return self::column($driver, implode(':', array_merge([$type], $flags)));
    }

    /** 表是否存在 */
    public static function tableExists(PDO $pdo, string $driver, string $table): bool
    {
        try {
            if ($driver === 'sqlite') {
                $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('table','view') AND name = ?");
                $st->execute([$table]);
                return (int)$st->fetchColumn() > 0;
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 已有列名（小写） */
    public static function existingColumns(PDO $pdo, string $driver, string $table): array
    {
        $cols = [];
        try {
            if ($driver === 'sqlite') {
                $st = $pdo->query('PRAGMA table_info(`' . $table . '`)');
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cols[] = strtolower($row['name']);
                }
                return $cols;
            }
            $st = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $st->execute([$table]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cols[] = strtolower($row['column_name']);
            }
        } catch (Throwable $e) {
            // 忽略
        }
        return $cols;
    }

    /** 已有索引名（小写） */
    public static function existingIndexes(PDO $pdo, string $driver, string $table): array
    {
        $names = [];
        try {
            if ($driver === 'sqlite') {
                $st = $pdo->query('PRAGMA index_list(`' . $table . '`)');
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $names[] = strtolower($row['name']);
                }
                return $names;
            }
            $st = $pdo->query('SHOW INDEX FROM `' . $table . '`');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[] = strtolower($row['Key_name']);
            }
        } catch (Throwable $e) {
            // 忽略
        }
        return $names;
    }

    /**
     * 增量同步：建缺失表、补缺失列、补缺失索引。只做加法，不删列不删表，可在生产库安全执行。
     *
     * @return string[] 变更清单
     */
    public static function sync(PDO $pdo, string $driver): array
    {
        $defs   = require __DIR__ . '/schema.php';
        $prefix = YxDb::prefix();
        $log    = [];

        foreach ($defs as $table => $def) {
            $real = $prefix . $table;

            if (!self::tableExists($pdo, $driver, $real)) {
                $pdo->exec(self::createTable($driver, $real, $def));
                $log[] = "建表 {$real}";
                continue;
            }

            // 补列
            $have = self::existingColumns($pdo, $driver, $real);
            foreach ($def['columns'] as $col => $spec) {
                if (in_array(strtolower($col), $have, true)) {
                    continue;
                }
                $ddl = sprintf(
                    'ALTER TABLE `%s` ADD COLUMN `%s` %s',
                    $real,
                    $col,
                    self::columnForAlter($driver, $spec)
                );
                $pdo->exec($ddl);
                $log[] = "补列 {$real}.{$col}";
            }

            // 补索引
            if ($driver === 'sqlite') {
                foreach ($def['indexes'] ?? [] as $cols) {
                    $cols = (array)$cols;
                    $name = 'idx_' . $real . '_' . implode('_', $cols);
                    $pdo->exec(sprintf(
                        'CREATE INDEX IF NOT EXISTS `%s` ON `%s` (`%s`)',
                        $name,
                        $real,
                        implode('`,`', $cols)
                    ));
                }
            } else {
                $have = self::existingIndexes($pdo, $driver, $real);
                foreach ($def['indexes'] ?? [] as $cols) {
                    $cols = (array)$cols;
                    $name = 'idx_' . $real . '_' . implode('_', $cols);
                    if (in_array(strtolower($name), $have, true)) {
                        continue;
                    }
                    try {
                        $pdo->exec(sprintf(
                            'ALTER TABLE `%s` ADD INDEX `%s` (`%s`)',
                            $real,
                            $name,
                            implode('`,`', $cols)
                        ));
                        $log[] = "补索引 {$real}(" . implode(',', $cols) . ')';
                    } catch (Throwable $e) {
                        $log[] = "索引已存在或无法创建 {$real}(" . implode(',', $cols) . ')';
                    }
                }
            }
        }

        return $log;
    }
}
