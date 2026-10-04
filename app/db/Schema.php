<?php
declare(strict_types=1);

namespace db;

/**
 * Schema Builder - 参考 Laravel Schema
 * 提供流畅的数据库表结构操作
 */
class Schema
{
    private \PDO $pdo;
    private string $table = '';
    private array $columns = [];
    private array $commands = [];
    private string $engine = 'InnoDB';
    private string $charset = 'utf8mb4';
    private string $collation = 'utf8mb4_unicode_ci';
    private string $comment = '';
    private string $driver = 'mysql';

    private static ?self $instance = null;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->driver = \strtolower($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public static function setConnection(\PDO $pdo): self
    {
        self::$instance = new self($pdo);
        return self::$instance;
    }

    public static function connection(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Schema connection not initialized');
        }
        return self::$instance;
    }

    // ─── 表操作 ───

    public function create(string $table, callable $callback): bool
    {
        $table = $this->sanitizeName($table);
        $this->table = $table;
        $this->columns = [];
        $this->commands = [];
        $this->comment = '';

        $blueprint = new Blueprint($table, $this->driver);
        $callback($blueprint);

        $this->columns = $blueprint->getColumns();
        $this->commands = $blueprint->getCommands();
        $indexes = $blueprint->getIndexes();

        $sql = $this->compileCreate();
        if (!$this->execute($sql)) {
            return false;
        }

        // SQLite 不支持表内索引子句，需在建表后单独创建
        foreach ($indexes as $index) {
            if ($index['columns'] === '') {
                continue;
            }
            if (!$this->execute(
                "CREATE INDEX `{$index['name']}` ON `{$this->table}` (`{$index['columns']}`)"
            )) {
                return false;
            }
        }

        return true;
    }

    public function table(string $table, callable $callback): bool
    {
        $table = $this->sanitizeName($table);
        $this->table = $table;
        // 必须与 create() 对称地重置 commands / comment：
        // 否则回调内抛异常时残留上一次的 UNIQUE/KEY 会在本次 ALTER 中重现
        $this->columns = [];
        $this->commands = [];
        $this->comment = '';

        $blueprint = new Blueprint($table, $this->driver);
        $callback($blueprint);

        $this->columns = $blueprint->getColumns();
        $this->commands = $blueprint->getCommands();

        // 空变更必须抛异常：无论何种驱动，都不能生成非法的空 ALTER 语句
        if (array_merge($this->columns, $this->commands) === []) {
            throw new \RuntimeException(
                "Schema::table() for `{$this->table}` has no changes; add columns or commands inside the callback."
            );
        }

        // SQLite 的 ALTER TABLE 每次只支持一个子句（ADD COLUMN），需逐条执行
        if ($this->driver === 'sqlite') {
            foreach (array_merge($this->columns, $this->commands) as $change) {
                $change = trim($change);
                if ($change === '') {
                    continue;
                }
                if (!$this->execute("ALTER TABLE `{$this->table}`\n  ADD COLUMN {$change}")) {
                    return false;
                }
            }
        } elseif (!$this->execute($this->compileAlter())) {
            return false;
        }

        foreach ($blueprint->getIndexes() as $index) {
            if ($index['columns'] === '') {
                continue;
            }
            if (!$this->execute(
                "CREATE INDEX `{$index['name']}` ON `{$this->table}` (`{$index['columns']}`)"
            )) {
                return false;
            }
        }

        return true;
    }

    public function drop(string $table): bool
    {
        $table = $this->sanitizeName($table);
        return $this->execute("DROP TABLE IF EXISTS `{$table}`");
    }

    public function dropIfExists(string $table): bool
    {
        return $this->drop($table);
    }

    public function hasTable(string $table): bool
    {
        $table = $this->sanitizeName($table);
        try {
            if ($this->driver === 'sqlite') {
                $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
                return $stmt !== false && $stmt->fetch() !== false;
            }
            // MySQL
            $escapedTable = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);
            $stmt = $this->pdo->query("SHOW TABLES LIKE '{$escapedTable}'");
            return $stmt !== false && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function hasColumn(string $table, string $column): bool
    {
        $table = $this->sanitizeName($table);
        $column = $this->sanitizeName($column);
        try {
            if ($this->driver === 'sqlite') {
                $stmt = $this->pdo->query("PRAGMA table_info(`{$table}`)");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($row['name'] === $column) {
                        return true;
                    }
                }
                return false;
            }
            // MySQL
            $escapedColumn = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $column);
            $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escapedColumn}'");
            return $stmt !== false && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid identifier: {$name}");
        }
        return $name;
    }

    public function rename(string $from, string $to): bool
    {
        $from = $this->sanitizeName($from);
        $to = $this->sanitizeName($to);
        if ($this->driver === 'sqlite') {
            return $this->execute("ALTER TABLE `{$from}` RENAME TO `{$to}`");
        }
        return $this->execute("RENAME TABLE `{$from}` TO `{$to}`");
    }

    public function truncate(string $table): bool
    {
        $table = $this->sanitizeName($table);
        if ($this->driver === 'sqlite') {
            return $this->execute("DELETE FROM `{$table}`");
        }
        return $this->execute("TRUNCATE TABLE `{$table}`");
    }

    // ─── 编译 ───

    private function compileCreate(): string
    {
        $parts = [];
        $parts[] = "CREATE TABLE `{$this->table}` (";
        $parts[] = implode(",\n  ", array_merge($this->columns, $this->commands));
        if ($this->driver === 'sqlite') {
            $parts[] = ")";
        } else {
            $parts[] = ") ENGINE={$this->engine} DEFAULT CHARSET={$this->charset} COLLATE={$this->collation}";
            if ($this->comment !== '') {
                $parts[2] .= " COMMENT='" . str_replace(["\\", "'"], ["\\\\", "''"], $this->comment) . "'";
            }
        }
        return implode("\n", $parts);
    }

    private function compileAlter(): string
    {
        $changes = array_merge($this->columns, $this->commands);
        if (empty($changes)) {
            throw new \RuntimeException("Schema::table() for `{$this->table}` has no changes; add columns or commands inside the callback.");
        }

        // columns 中的每一项都是「列定义」（如 `d` VARCHAR(255)），
        // 必须补 ADD COLUMN 前缀才是合法 ALTER 语句。
        // 此前直接拼接裸列定义，任何驱动下新增列都会报语法错误。
        $lines = [];
        foreach ($changes as $change) {
            $lines[] = '  ' . (stripos($change, 'ADD ') === 0 ? $change : 'ADD COLUMN ' . $change);
        }

        // SQLite 的 ALTER TABLE 每次只接受一个子句，多行逗号分隔会语法错误
        if ($this->driver === 'sqlite') {
            return "ALTER TABLE `{$this->table}`\n" . $lines[0];
        }

        return "ALTER TABLE `{$this->table}`\n" . implode(",\n", $lines);
    }

    private function execute(string $sql): bool
    {
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (\Throwable $e) {
            throw new \core\exception\DatabaseException(
                "Schema operation failed: {$e->getMessage()}\nSQL: {$sql}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    // ─── 配置选项 ───

    public function engine(string $engine): self
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $engine)) {
            throw new \InvalidArgumentException("Invalid engine name: {$engine}");
        }
        $this->engine = $engine;
        return $this;
    }

    public function charset(string $charset): self
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $charset)) {
            throw new \InvalidArgumentException("Invalid charset name: {$charset}");
        }
        $this->charset = $charset;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }
}

/**
 * 表蓝图 - 定义列和索引
 */
class Blueprint
{
    private string $table;
    private array $columns = [];
    private array $commands = [];
    private array $indexes = [];
    private array $indexColumns = [];
    private ?string $lastColumn = null;
    private string $driver = 'mysql';

    public function __construct(string $table, string $driver = 'mysql')
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        $this->table = $table;
        $this->driver = $driver;
    }

    // ─── 列类型 ───

    public function id(string $name = 'id'): self
    {
        if ($this->driver === 'sqlite') {
            return $this->addColumn("`{$name}`", 'INTEGER PRIMARY KEY AUTOINCREMENT');
        }
        return $this->addColumn("`{$name}`", 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn("`{$name}`", "VARCHAR({$length})");
    }

    public function text(string $name): self
    {
        return $this->addColumn("`{$name}`", 'TEXT');
    }

    public function longText(string $name): self
    {
        return $this->addColumn("`{$name}`", 'LONGTEXT');
    }

    public function integer(string $name): self
    {
        return $this->addColumn("`{$name}`", 'INT');
    }

    public function bigInteger(string $name): self
    {
        return $this->addColumn("`{$name}`", 'BIGINT');
    }

    public function tinyInteger(string $name): self
    {
        return $this->addColumn("`{$name}`", 'TINYINT');
    }

    public function boolean(string $name): self
    {
        return $this->tinyInteger($name)->default(0);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        return $this->addColumn("`{$name}`", "DECIMAL({$precision},{$scale})");
    }

    public function float(string $name): self
    {
        return $this->addColumn("`{$name}`", 'FLOAT');
    }

    public function double(string $name): self
    {
        return $this->addColumn("`{$name}`", 'DOUBLE');
    }

    public function date(string $name): self
    {
        return $this->addColumn("`{$name}`", 'DATE');
    }

    public function dateTime(string $name): self
    {
        return $this->addColumn("`{$name}`", 'DATETIME');
    }

    public function timestamp(string $name): self
    {
        return $this->addColumn("`{$name}`", 'TIMESTAMP');
    }

    public function timestamps(): self
    {
        $this->timestamp('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        if ($this->driver !== 'sqlite') {
            $this->timestamp('updated_at')->nullable()->default('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        } else {
            $this->timestamp('updated_at')->nullable()->default('CURRENT_TIMESTAMP');
        }
        return $this;
    }

    public function softDeletes(): self
    {
        return $this->timestamp('deleted_at')->nullable();
    }

    public function json(string $name): self
    {
        return $this->addColumn("`{$name}`", 'JSON');
    }

    public function enum(string $name, array $values): self
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("ENUM column '{$name}' requires at least one value");
        }
        $escaped = array_map(fn($v) => $this->escapeQuote((string) $v), $values);
        $quoted = implode("','", $escaped);
        return $this->addColumn("`{$name}`", "ENUM('{$quoted}')");
    }

    public function morphs(string $name): self
    {
        $this->bigInteger("{$name}_id");
        $this->string("{$name}_type");
        return $this;
    }

    // ─── 列修饰符 ───

    public function nullable(): self
    {
        return $this->modifyColumn('NULL');
    }

    public function notNull(): self
    {
        return $this->modifyColumn('NOT NULL');
    }

    public function default(mixed $value): self
    {
        if ($value === null) {
            return $this->modifyColumn('DEFAULT NULL');
        }
        if (is_bool($value)) {
            return $this->modifyColumn('DEFAULT ' . ($value ? '1' : '0'));
        }
        if (is_string($value)) {
            $upper = strtoupper($value);
            if (in_array($upper, ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'], true)) {
                return $this->modifyColumn("DEFAULT {$upper}");
            }
            $value = "'" . $this->escapeQuote($value) . "'";
            return $this->modifyColumn("DEFAULT {$value}");
        }
        return $this->modifyColumn("DEFAULT {$value}");
    }

    public function unsigned(): self
    {
        return $this->modifyColumn('UNSIGNED');
    }

    public function unique(): self
    {
        if ($this->lastColumn === null) {
            throw new \RuntimeException('unique() must be called after a column definition (e.g., $table->string("name")->unique()).');
        }
        $col = trim($this->lastColumn, '`');
        // SQLite 不支持表内的 `UNIQUE KEY name (col)` 子句，
        // 只能写成列级约束 UNIQUE(col)；MySQL 保留原语法。
        if ($this->driver === 'sqlite') {
            $this->commands[] = "UNIQUE (`{$col}`)";
        } else {
            $this->commands[] = "UNIQUE KEY `uk_{$col}` (`{$col}`)";
        }
        return $this;
    }

    public function index(): self
    {
        if ($this->lastColumn === null) {
            throw new \RuntimeException('index() must be called after a column definition (e.g., $table->string("name")->index()).');
        }
        $col = trim($this->lastColumn, '`');
        // SQLite 的 CREATE TABLE 子句不接受 `KEY idx_x (x)`（语法错误）。
        // 改为登记到独立索引列表，由 Schema::create() 在建表后单独发 CREATE INDEX。
        // 索引名必须带表名前缀：SQLite 的索引名是数据库全局唯一的，
        // 否则 users.email 与 orders.email 会争抢同一个 idx_email（实测报冲突）。
        if ($this->driver === 'sqlite') {
            $this->indexes[] = "idx_{$this->table}_{$col}";
            $this->indexColumns[] = $col;
        } else {
            $this->commands[] = "KEY `idx_{$col}` (`{$col}`)";
        }
        return $this;
    }

    public function comment(string $comment): self
    {
        return $this->modifyColumn("COMMENT '" . $this->escapeQuote($comment) . "'");
    }

    public function after(string $column): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name for AFTER: {$column}");
        }
        return $this->modifyColumn("AFTER `{$column}`");
    }

    // ─── 索引命令 ───

    public function primary(string|array $columns): self
    {
        if (is_array($columns)) {
            foreach ($columns as $col) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
                    throw new \InvalidArgumentException("Invalid primary key column: {$col}");
                }
            }
            $cols = implode('`, `', $columns);
        } else {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $columns)) {
                throw new \InvalidArgumentException("Invalid primary key column: {$columns}");
            }
            $cols = $columns;
        }
        $this->commands[] = "PRIMARY KEY (`{$cols}`)";
        return $this;
    }

    public function foreign(string $column): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid foreign key column: {$column}");
        }
        $this->lastForeignKey = $column;
        $this->lastForeignRef = '';
        return $this;
    }

    public function references(string $column): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid reference column: {$column}");
        }
        $this->lastForeignRef = $column;
        return $this;
    }

    public function on(string $table): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid reference table: {$table}");
        }
        if ($this->lastForeignKey === '' || $this->lastForeignRef === '') {
            throw new \RuntimeException('foreign() and references() must be called before on()');
        }
        $this->commands[] = "FOREIGN KEY (`{$this->lastForeignKey}`) REFERENCES `{$table}` (`{$this->lastForeignRef}`)";
        return $this;
    }

    public function onDelete(string $action): self
    {
        $allowed = ['CASCADE', 'SET NULL', 'NO ACTION', 'RESTRICT', 'SET DEFAULT'];
        $upperAction = strtoupper($action);
        if (!in_array($upperAction, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid ON DELETE action: {$action}");
        }
        if (empty($this->commands) || !str_contains($this->commands[count($this->commands) - 1], 'FOREIGN KEY')) {
            throw new \RuntimeException('onDelete() must be called after foreign()->references()->on() to attach to a FOREIGN KEY constraint.');
        }
        $this->commands[count($this->commands) - 1] .= " ON DELETE {$upperAction}";
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $allowed = ['CASCADE', 'SET NULL', 'NO ACTION', 'RESTRICT', 'SET DEFAULT'];
        $upperAction = strtoupper($action);
        if (!in_array($upperAction, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid ON UPDATE action: {$action}");
        }
        if (empty($this->commands) || !str_contains($this->commands[count($this->commands) - 1], 'FOREIGN KEY')) {
            throw new \RuntimeException('onUpdate() must be called after foreign()->references()->on() to attach to a FOREIGN KEY constraint.');
        }
        $this->commands[count($this->commands) - 1] .= " ON UPDATE {$upperAction}";
        return $this;
    }

    // ─── 列变更命令 ───

    public function change(): self
    {
        if ($this->lastColumn === null || empty($this->columns)) {
            throw new \RuntimeException('Cannot call change() without a preceding column definition.');
        }
        $idx = count($this->columns) - 1;
        $this->columns[$idx] = 'MODIFY COLUMN ' . $this->columns[$idx];
        return $this;
    }

    public function dropColumn(string $column): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name: {$column}");
        }
        $this->columns[] = "DROP COLUMN `{$column}`";
        return $this;
    }

    public function renameColumn(string $from, string $to): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $from)) {
            throw new \InvalidArgumentException("Invalid column name: {$from}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $to)) {
            throw new \InvalidArgumentException("Invalid column name: {$to}");
        }
        $this->commands[] = "RENAME COLUMN `{$from}` TO `{$to}`";
        return $this;
    }

    public function dropIndex(string $name): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid index name: {$name}");
        }
        $this->commands[] = "DROP INDEX `{$name}`";
        return $this;
    }

    public function dropForeign(string $name): self
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid foreign key name: {$name}");
        }
        $this->commands[] = "DROP FOREIGN KEY `{$name}`";
        return $this;
    }

    // ─── 内部方法 ───

    private function addColumn(string $name, string $type): self
    {
        // 验证列名（去除反引号后检查）
        $bareName = trim($name, '`');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $bareName)) {
            throw new \InvalidArgumentException("Invalid column name: {$bareName}");
        }
        $this->lastColumn = $name;
        $this->columns[] = "{$name} {$type}";
        return $this;
    }

    private function modifyColumn(string $modifier, bool $insertAfterName = false): self
    {
        if ($this->lastColumn !== null) {
            $idx = count($this->columns) - 1;
            if ($insertAfterName) {
                $this->columns[$idx] = str_replace($this->lastColumn, "{$this->lastColumn} {$modifier}", $this->columns[$idx]);
            } else {
                $this->columns[$idx] .= " {$modifier}";
            }
        }
        return $this;
    }

    private function escapeQuote(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "''"], $value);
    }

    /** @return string[] */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** @return string[] */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * 获取需在建表后单独创建的索引（仅 SQLite 使用）
     *
     * @return array<int, array{name: string, columns: string}>
     */
    public function getIndexes(): array
    {
        $indexes = [];
        foreach ($this->indexes as $i => $name) {
            $indexes[] = ['name' => $name, 'columns' => $this->indexColumns[$i] ?? ''];
        }
        return $indexes;
    }

    private string $lastForeignKey = '';
    private string $lastForeignRef = '';
}

/**
 * 迁移管理器
 */
class Migration
{
    private \PDO $pdo;
    private string $migrationsPath;

    public function __construct(\PDO $pdo, string $migrationsPath)
    {
        $this->pdo = $pdo;
        $this->migrationsPath = rtrim($migrationsPath, '/') . '/';
    }

    public function run(): array
    {
        $this->ensureTable();
        $ran = $this->getRan();
        $files = $this->getMigrationFiles();
        $batch = $this->getNextBatch();
        $migrated = [];

        foreach ($files as $file) {
            if (in_array($file, $ran, true)) {
                continue;
            }

            if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_\w+\.php$/', $file) && !preg_match('/^\d+_\w+\.php$/', $file)) {
                continue;
            }

            require_once $this->migrationsPath . $file;
            $class = $this->getClassFromFile($file);

            if (!class_exists($class)) {
                continue;
            }

            $instance = new $class($this->pdo);
            if (method_exists($instance, 'up')) {
                $instance->up();
            }

            $this->record($file, $batch);
            $migrated[] = $file;
        }

        return $migrated;
    }

    public function rollback(int $steps = 1): array
    {
        $this->ensureTable();
        $latest = $this->getLastBatch($steps);
        $rolledBack = [];

        foreach (array_reverse($latest) as $row) {
            $file = $row['migration'];
            $filePath = $this->migrationsPath . $file;

            if (!file_exists($filePath)) continue;

            require_once $filePath;
            $class = $this->getClassFromFile($file);

            if (class_exists($class)) {
                $instance = new $class($this->pdo);
                if (method_exists($instance, 'down')) {
                    $instance->down();
                }
            }

            $this->delete($file);
            $rolledBack[] = $file;
        }

        return $rolledBack;
    }

    public function reset(): array
    {
        $all = $this->getAll();
        if (empty($all)) return [];
        return $this->rollback(count(array_unique(array_column($all, 'batch'))));
    }

    public function fresh(): array
    {
        $this->reset();
        return $this->run();
    }

    public function status(): array
    {
        $this->ensureTable();
        $ran = $this->getRan();
        $files = $this->getMigrationFiles();
        $status = [];

        foreach ($files as $file) {
            $status[$file] = in_array($file, $ran, true) ? 'Ran' : 'Pending';
        }

        return $status;
    }

    // ─── 内部方法 ───

    private function ensureTable(): void
    {
        $driver = strtolower($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    /** @return string[] */
    private function getRan(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM migrations ORDER BY batch, migration");
        if ($stmt === false) return [];
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] */
    private function getMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . '*.php');
        if ($files === false) return [];
        return array_map('basename', $files);
    }

    private function getNextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM migrations");
        if ($stmt === false) return 1;
        $max = $stmt->fetchColumn();
        return ($max !== false ? (int) $max : 0) + 1;
    }

    private function record(string $file, int $batch): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$file, $batch]);
    }

    private function delete(string $file): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM migrations WHERE migration = ?");
        $stmt->execute([$file]);
    }

    /** @return array<int, array<string, mixed>> */
    private function getLastBatch(int $steps): array
    {
        $steps = max(1, $steps);
        $batches = $this->pdo->query("SELECT DISTINCT batch FROM migrations ORDER BY batch DESC LIMIT {$steps}");
        if ($batches === false) return [];
        $batchNums = $batches->fetchAll(\PDO::FETCH_COLUMN);
        if (empty($batchNums)) return [];

        $placeholders = implode(',', array_fill(0, count($batchNums), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM migrations WHERE batch IN ({$placeholders}) ORDER BY batch DESC, id DESC");
        $stmt->execute($batchNums);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function getAll(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM migrations ORDER BY batch, id");
        if ($stmt === false) return [];
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getClassFromFile(string $file): string
    {
        $content = file_get_contents($this->migrationsPath . $file);
        if ($content === false) return '';
        // 移除注释块和单行注释，避免误匹配
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        $content = preg_replace('/\/\/.*$/m', '', $content);
        // 匹配 namespace 和 class 声明
        $namespace = '';
        if (preg_match('/namespace\s+([\w\\\\]+)/', $content, $nm)) {
            $namespace = $nm[1] . '\\';
        }
        if (preg_match('/class\s+(\w+)/', $content, $m)) {
            return $namespace . $m[1];
        }
        return '';
    }
}