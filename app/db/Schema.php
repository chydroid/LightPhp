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

    private static ?self $instance = null;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
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

        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->columns = $blueprint->getColumns();
        $this->commands = $blueprint->getCommands();

        $sql = $this->compileCreate();
        return $this->execute($sql);
    }

    public function table(string $table, callable $callback): bool
    {
        $table = $this->sanitizeName($table);
        $this->table = $table;
        $this->columns = [];

        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->columns = $blueprint->getColumns();
        $this->commands = $blueprint->getCommands();

        $sql = $this->compileAlter();
        return $this->execute($sql);
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
        // 转义 LIKE 通配符，防止 _ 和 % 被解释为通配符
        $escapedTable = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);
        try {
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
        // 转义 LIKE 通配符，防止 _ 和 % 被解释为通配符
        $escapedColumn = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $column);
        try {
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
        return $this->execute("RENAME TABLE `{$from}` TO `{$to}`");
    }

    public function truncate(string $table): bool
    {
        $table = $this->sanitizeName($table);
        return $this->execute("TRUNCATE TABLE `{$table}`");
    }

    // ─── 编译 ───

    private function compileCreate(): string
    {
        $parts = [];
        $parts[] = "CREATE TABLE `{$this->table}` (";
        $parts[] = implode(",\n  ", array_merge($this->columns, $this->commands));
        $parts[] = ") ENGINE={$this->engine} DEFAULT CHARSET={$this->charset}";
        if ($this->comment !== '') {
            $parts[2] .= " COMMENT='" . str_replace("'", "''", $this->comment) . "'";
        }
        return implode("\n", $parts);
    }

    private function compileAlter(): string
    {
        $changes = array_merge($this->columns, $this->commands);
        $lines = [];
        foreach ($changes as $change) {
            $lines[] = "  {$change}";
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
        $this->engine = $engine;
        return $this;
    }

    public function charset(string $charset): self
    {
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
    private ?string $lastColumn = null;

    public function __construct(string $table)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        $this->table = $table;
    }

    // ─── 列类型 ───

    public function id(string $name = 'id'): self
    {
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
        $this->timestamp('updated_at')->nullable()->default('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
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
        if ($this->lastColumn !== null) {
            $col = trim($this->lastColumn, '`');
            $this->commands[] = "UNIQUE KEY `uk_{$col}` (`{$col}`)";
        }
        return $this;
    }

    public function index(): self
    {
        if ($this->lastColumn !== null) {
            $col = trim($this->lastColumn, '`');
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
        if ($this->lastForeignKey !== '' && $this->lastForeignRef !== '') {
            $this->commands[] = "FOREIGN KEY (`{$this->lastForeignKey}`) REFERENCES `{$table}` (`{$this->lastForeignRef}`)";
        }
        return $this;
    }

    public function onDelete(string $action): self
    {
        $allowed = ['CASCADE', 'SET NULL', 'NO ACTION', 'RESTRICT', 'SET DEFAULT'];
        $upperAction = strtoupper($action);
        if (!in_array($upperAction, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid ON DELETE action: {$action}");
        }
        if (!empty($this->commands)) {
            $lastIdx = count($this->commands) - 1;
            if (str_contains($this->commands[$lastIdx], 'FOREIGN KEY')) {
                $this->commands[$lastIdx] .= " ON DELETE {$upperAction}";
            }
        }
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $allowed = ['CASCADE', 'SET NULL', 'NO ACTION', 'RESTRICT', 'SET DEFAULT'];
        $upperAction = strtoupper($action);
        if (!in_array($upperAction, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid ON UPDATE action: {$action}");
        }
        if (!empty($this->commands)) {
            $lastIdx = count($this->commands) - 1;
            if (str_contains($this->commands[$lastIdx], 'FOREIGN KEY')) {
                $this->commands[$lastIdx] .= " ON UPDATE {$upperAction}";
            }
        }
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
        $this->columns[] = "CHANGE COLUMN `{$from}` `{$to}`";
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

            require $filePath;
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
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL,
            `batch` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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