<?php
declare(strict_types=1);

namespace db;

/**
 * SQL 查询构建器
 * 
 * 提供链式调用的 SQL 查询构建功能，支持 SELECT、INSERT、UPDATE、DELETE 等操作。
 * 支持查询缓存、分页、事务等高级功能。
 */
class QueryBuilder
{
    /** @var \PDO PDO 数据库连接 */
    private \PDO $pdo;

    /** @var string 表名 */
    private string $table = '';

    /** @var string SELECT 字段 */
    private string $select = '*';

    /** @var array WHERE 条件 */
    private array $where = [];

    /** @var array 绑定参数 */
    private array $bindings = [];

    /** @var string ORDER BY 子句 */
    private string $orderBy = '';

    /** @var bool 是否使用 DISTINCT */
    private bool $distinct = false;

    /** @var string GROUP BY 子句 */
    private string $groupBy = '';

    /** @var array HAVING 子句 */
    private array $having = [];

    /** @var int LIMIT 数量 */
    private int $limit = 0;

    /** @var int OFFSET 偏移量 */
    private int $offset = 0;

    /** @var array JOIN 子句 */
    private array $joins = [];

    /** @var bool 是否使用 FOR UPDATE */
    private bool $forUpdate = false;

    /** @var string|null 锁类型 */
    private ?string $lock = null;

    /** @var string|null 缓存键 */
    private ?string $cacheKey = null;

    /** @var int|null 缓存有效期 */
    private ?int $cacheTtl = null;

    /** @var bool 是否启用缓存 */
    private bool $cacheEnabled = false;

    /** @var bool 是否为 raw SQL 模式 */
    private bool $isRaw = false;

    /** @var string[] 允许的 SQL 操作符 */
    private const ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    /** @var string[] 允许的 JOIN 类型 */
    private const ALLOWED_JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'CROSS'];

    /** @var string[] 允许的 HAVING 操作符 */
    private const ALLOWED_HAVING_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    /**
     * 构造函数
     * 
     * @param \PDO $pdo PDO 数据库连接
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 验证表名合法性
     * 
     * @param string $table 表名
     * @throws \InvalidArgumentException 当表名不合法时
     */
    private function validateTableName(string $table): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    /**
     * 设置查询表名
     * 
     * @param string $table 表名
     * @return self
     */
    public function table(string $table): self
    {
        $this->validateTableName($table);
        $this->table = $table;
        return $this;
    }

    /**
     * 清理列名（添加反引号）
     * 
     * @param string $column 列名
     * @return string 清理后的列名
     */
    private function sanitizeColumn(string $column): string
    {
        if ($column === '*') {
            return $column;
        }

        if (preg_match('/^[a-zA-Z0-9_\.]+$/', $column)) {
            if (str_contains($column, '.')) {
                [$alias, $col] = explode('.', $column, 2);
                if ($col === '*') {
                    return "`{$alias}`.*";
                }
                return "`{$alias}`.`{$col}`";
            }
            return "`{$column}`";
        }

        // 不再回退返回原始值，防止SQL注入
        throw new \InvalidArgumentException("Invalid column name: {$column}");
    }

    /**
     * 验证列名合法性
     * 
     * @param string $column 列名
     * @throws \InvalidArgumentException 当列名不合法时
     */
    private function validateColumnName(string $column): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\.\*]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name: {$column}");
        }
    }

    /**
     * 设置 SELECT 字段
     * 
     * @param string|array $columns 字段名或字段数组
     * @return self
     */
    public function select(string|array $columns = '*'): self
    {
        if (is_array($columns)) {
            foreach ($columns as $col) {
                $this->validateColumnName($col);
            }
            $this->select = implode(', ', array_map(fn($c) => $this->sanitizeColumn($c), $columns));
        } else {
            if ($columns !== '*') {
                $this->validateColumnName($columns);
            }
            $this->select = $this->sanitizeColumn($columns);
        }
        return $this;
    }

    /**
     * 添加 WHERE 条件
     * 
     * @param string $column 列名
     * @param mixed $operator 操作符（可选，默认为 '='）
     * @param mixed $value 值
     * @return self
     * @throws \InvalidArgumentException 当操作符不合法时
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        // 判断是否为三参数形式 where('col', '=', null)
        // 如果 $value 显式传入（即使是 null），则 $operator 是操作符
        // 如果 $value 未传入（默认 null）且 $operator 非 null，则是两参数简写 where('col', 'value')
        $isThreeArgForm = func_num_args() >= 3;

        if (!$isThreeArgForm && $operator !== null) {
            // 两参数简写: where('column', 'value')
            $value = $operator;
            $operator = '=';
        }
        if ($operator === null) {
            $operator = '=';
        }

        $this->validateColumnName($column);
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid SQL operator: {$operator}");
        }

        // 处理 null 值：SQL 中 = NULL 永远为 false，应使用 IS NULL
        if ($value === null) {
            if ($operator === '=' || $operator === 'LIKE') {
                $this->where[] = $this->sanitizeColumn($column) . " IS NULL";
            } elseif ($operator === '!=' || $operator === '<>' || $operator === 'NOT LIKE') {
                $this->where[] = $this->sanitizeColumn($column) . " IS NOT NULL";
            } else {
                throw new \InvalidArgumentException("Cannot use NULL value with operator {$operator}");
            }
            return $this;
        }

        $placeholder = ':w_' . count($this->bindings);
        $this->where[] = $this->sanitizeColumn($column) . " {$operator} {$placeholder}";
        $this->bindings[$placeholder] = $value;
        return $this;
    }

    public function whereOr(array $conditions): self
    {
        $orWheres = [];
        foreach ($conditions as $column => $operator) {
            $this->validateColumnName($column);
            if (is_array($operator)) {
                [$op, $value] = $operator;
                $op = strtoupper(trim($op));
                if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
                    throw new \InvalidArgumentException("Invalid SQL operator: {$op}");
                }
            } else {
                $op = '=';
                $value = $operator;
            }

            // 处理 null 值
            if ($value === null) {
                if ($op === '=' || $op === 'LIKE') {
                    $orWheres[] = $this->sanitizeColumn($column) . " IS NULL";
                } elseif ($op === '!=' || $op === '<>' || $op === 'NOT LIKE') {
                    $orWheres[] = $this->sanitizeColumn($column) . " IS NOT NULL";
                } else {
                    throw new \InvalidArgumentException("Cannot use NULL value with operator {$op}");
                }
                continue;
            }

            $placeholder = ':w_' . count($this->bindings) . '_or_' . count($orWheres);
            $orWheres[] = $this->sanitizeColumn($column) . " {$op} {$placeholder}";
            $this->bindings[$placeholder] = $value;
        }

        if (!empty($orWheres)) {
            $this->where[] = '(' . implode(' OR ', $orWheres) . ')';
        }

        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->validateColumnName($column);
        if (empty($values)) {
            $this->where[] = '0 = 1';
            return $this;
        }

        // 分离 null 值和非 null 值
        // SQL 中 IN (1, NULL) 对 NULL 的比较结果为 UNKNOWN，不会匹配任何行
        $nullValues = array_filter($values, fn($v) => $v === null);
        $nonNullValues = array_filter($values, fn($v) => $v !== null);

        $conditions = [];

        if (!empty($nonNullValues)) {
            $placeholders = [];
            foreach ($nonNullValues as $value) {
                $placeholder = ':w_' . count($this->bindings);
                $placeholders[] = $placeholder;
                $this->bindings[$placeholder] = $value;
            }
            $conditions[] = $this->sanitizeColumn($column) . " IN (" . implode(', ', $placeholders) . ")";
        }

        if (!empty($nullValues)) {
            $conditions[] = $this->sanitizeColumn($column) . " IS NULL";
        }

        if (count($conditions) === 1) {
            $this->where[] = $conditions[0];
        } else {
            $this->where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->validateColumnName($column);
        $this->where[] = $this->sanitizeColumn($column) . " IS NULL";
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->validateColumnName($column);
        $this->where[] = $this->sanitizeColumn($column) . " IS NOT NULL";
        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $this->validateColumnName($column);
        if ($min === null || $max === null) {
            throw new \InvalidArgumentException("BETWEEN clause does not support NULL values for column {$column}");
        }

        $minPlaceholder = ':w_' . count($this->bindings);
        $maxPlaceholder = ':w_' . (count($this->bindings) + 1);

        $this->bindings[$minPlaceholder] = $min;
        $this->bindings[$maxPlaceholder] = $max;

        $this->where[] = $this->sanitizeColumn($column) . " BETWEEN {$minPlaceholder} AND {$maxPlaceholder}";
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->validateTableName($table);
        $type = strtoupper(trim($type));
        if (!in_array($type, self::ALLOWED_JOIN_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid JOIN type: {$type}");
        }

        $operator = strtoupper(trim($operator));
        if (!in_array($operator, ['=', '<', '>', '<=', '>=', '<>'], true)) {
            throw new \InvalidArgumentException("Invalid JOIN operator: {$operator}");
        }

        $this->validateColumnName($first);
        $this->validateColumnName($second);
        $this->joins[] = "{$type} JOIN `{$table}` ON " . $this->sanitizeColumn($first) . " {$operator} " . $this->sanitizeColumn($second);
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $column = trim($column);
        $this->validateColumnName($column);
        $direction = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
        $clause = $this->sanitizeColumn($column) . " {$direction}";
        if ($this->orderBy !== '') {
            $this->orderBy .= ', ' . $clause;
        } else {
            $this->orderBy = $clause;
        }
        return $this;
    }

    public function orderByRaw(string $expression): self
    {
        if ($this->orderBy !== '') {
            $this->orderBy .= ', ' . $expression;
        } else {
            $this->orderBy = $expression;
        }
        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->validateColumnName($column);
        $this->groupBy = "GROUP BY " . $this->sanitizeColumn($column);
        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $this->validateColumnName($column);
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::ALLOWED_HAVING_OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid HAVING operator: {$operator}");
        }

        // 处理 null 值：与 where() 同理
        if ($value === null) {
            if ($operator === '=' || $operator === 'LIKE') {
                $this->having[] = $this->sanitizeColumn($column) . " IS NULL";
            } elseif ($operator === '!=' || $operator === '<>' || $operator === 'NOT LIKE') {
                $this->having[] = $this->sanitizeColumn($column) . " IS NOT NULL";
            } else {
                throw new \InvalidArgumentException("Cannot use NULL value with HAVING operator {$operator}");
            }
            return $this;
        }

        $placeholder = ':h_' . count($this->bindings);
        $this->bindings[$placeholder] = $value;
        $this->having[] = $this->sanitizeColumn($column) . " {$operator} {$placeholder}";
        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must be non-negative, got ' . $limit);
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be non-negative, got ' . $offset);
        }
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    public function forUpdate(): self
    {
        $this->forUpdate = true;
        return $this;
    }

    public function lock(string $lock): self
    {
        $allowed = ['FOR UPDATE', 'LOCK IN SHARE MODE', 'FOR SHARE'];
        $upperLock = strtoupper(trim($lock));
        if (!in_array($upperLock, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid lock clause: {$lock}");
        }
        $this->lock = $upperLock;
        return $this;
    }

    public function cache(string $key, int $ttl = 3600): self
    {
        $this->cacheKey = 'query_' . hash('sha256', $key);
        $this->cacheTtl = $ttl;
        $this->cacheEnabled = true;
        return $this;
    }

    private function getCacheFor(string $sql): ?array
    {
        if (!$this->cacheEnabled || $this->cacheKey === null) {
            return null;
        }

        $cacheFile = STORAGE_PATH . 'cache/' . $this->cacheKey . '.php';
        if (!file_exists($cacheFile)) {
            return null;
        }

        $fp = @fopen($cacheFile, 'rb');
        if ($fp === false) {
            return null;
        }

        try {
            flock($fp, LOCK_SH);
            $content = stream_get_contents($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        if ($content === false || $content === '') {
            return null;
        }

        $prefix = '<?php die; ?>';
        if (str_starts_with($content, $prefix)) {
            $content = substr($content, strlen($prefix));
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['sql'], $data['expire'], $data['value'])) {
            return null;
        }

        if ($data['sql'] !== hash('sha256', $sql)) {
            return null;
        }

        if ($data['expire'] > 0 && $data['expire'] < time()) {
            @unlink($cacheFile);
            return null;
        }

        return $data['value'];
    }

    private function setCacheFor(string $sql, array $result): void
    {
        if (!$this->cacheEnabled || $this->cacheKey === null) {
            return;
        }

        $cacheFile = STORAGE_PATH . 'cache/' . $this->cacheKey . '.php';
        $data = [
            'sql'    => hash('sha256', $sql),
            'expire' => ($this->cacheTtl ?? 3600) > 0 ? time() + ($this->cacheTtl ?? 3600) : 0,
            'value'  => $result,
        ];

        $content = '<?php die; ?>' . json_encode($data, JSON_UNESCAPED_UNICODE);
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpFile = $cacheFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($tmpFile, $content, LOCK_EX);
        rename($tmpFile, $cacheFile);
    }

    public function raw(string $sql, array $bindings = []): self
    {
        $this->isRaw = true;
        $this->where = [$sql];
        $this->bindings = $bindings;
        return $this;
    }

    private function buildSelect(): string
    {
        if ($this->isRaw) {
            return $this->where[0] ?? '';
        }

        if ($this->table === '') {
            throw new \RuntimeException('No table name specified for the query.');
        }

        $sql = "SELECT " . ($this->distinct ? "DISTINCT " : "") . "{$this->select} FROM `{$this->table}`";

        foreach ($this->joins as $join) {
            $sql .= " {$join}";
        }

        if (!empty($this->where)) {
            $sql .= " WHERE " . implode(' AND ', $this->where);
        }

        if ($this->groupBy) {
            $sql .= " {$this->groupBy}";
        }

        if (!empty($this->having)) {
            $sql .= " HAVING " . implode(' AND ', $this->having);
        }

        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
        }

        if ($this->limit > 0) {
            $sql .= " LIMIT {$this->limit}";
            if ($this->offset > 0) {
                $sql .= " OFFSET {$this->offset}";
            }
        }

        if ($this->forUpdate) {
            $sql .= " FOR UPDATE";
        } elseif ($this->lock) {
            $sql .= " {$this->lock}";
        }

        return $sql;
    }

    private function buildInsert(array $data): string
    {
        if ($this->table === '') {
            throw new \RuntimeException('No table name specified for the query.');
        }

        $columns = [];
        $placeholders = [];

        foreach ($data as $key => $value) {
            $this->validateColumnName((string)$key);
            $columns[] = "`{$key}`";
            $ph = ':i_' . count($this->bindings);
            $placeholders[] = $ph;
            $this->bindings[$ph] = $value;
        }

        return sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s)",
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
    }

    private function buildUpdate(array $data): string
    {
        if ($this->table === '') {
            throw new \RuntimeException('No table name specified for the query.');
        }

        if (empty($this->where)) {
            throw new \RuntimeException('UPDATE query requires at least one WHERE condition for safety.');
        }

        $sets = [];
        foreach ($data as $key => $value) {
            $this->validateColumnName((string)$key);
            $ph = ':u_' . count($this->bindings);
            $sets[] = "`{$key}` = {$ph}";
            $this->bindings[$ph] = $value;
        }

        return sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $this->table,
            implode(', ', $sets),
            implode(' AND ', $this->where)
        );
    }

    private function buildDelete(): string
    {
        if ($this->isRaw) {
            throw new \RuntimeException('Cannot build DELETE query in raw SQL mode.');
        }

        if ($this->table === '') {
            throw new \RuntimeException('No table name specified for the query.');
        }

        if (empty($this->where)) {
            throw new \RuntimeException('DELETE query requires at least one WHERE condition for safety.');
        }

        return sprintf(
            "DELETE FROM `%s` WHERE %s",
            $this->table,
            implode(' AND ', $this->where)
        );
    }

    public function fetchAll(): array
    {
        $sql = $this->buildSelect();
        $cached = $this->getCacheFor($sql);
        if ($cached !== null) {
            return $cached;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result = $stmt->fetchAll();
        if ($result === false) {
            $result = [];
        }
        $this->setCacheFor($sql, $result);
        return $result;
    }

    public function fetch(): ?array
    {
        $clone = clone $this;
        $clone->limit = 1;
        $clone->offset = 0;
        $sql = $clone->buildSelect();
        $cached = $this->getCacheFor($sql);
        if ($cached !== null) {
            return $cached[0] ?? null;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($clone->bindings);
        $result = $stmt->fetch();
        if ($result !== false) {
            $this->setCacheFor($sql, [$result]);
            return $result;
        }
        $this->setCacheFor($sql, []);
        return null;
    }

    public function first(): ?array
    {
        return $this->fetch();
    }

    public function value(string $column): mixed
    {
        $this->validateColumnName($column);
        $clone = clone $this;
        $clone->select = $this->sanitizeColumn($column);
        $result = $clone->fetch();
        $bareColumn = str_contains($column, '.') ? explode('.', $column, 2)[1] : $column;
        return $result !== null ? ($result[$bareColumn] ?? null) : null;
    }

    private function validateAggregateColumn(string $column): void
    {
        if ($column !== '*' && !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid aggregate column name: {$column}");
        }
    }

    private function assertNotRaw(string $method): void
    {
        if ($this->isRaw) {
            throw new \RuntimeException("Cannot call {$method}() in raw query mode.");
        }
    }

    public function count(string $column = '*'): int
    {
        $this->assertNotRaw('count');
        $this->validateAggregateColumn($column);
        $clone = clone $this;
        $clone->limit = 0;
        $clone->offset = 0;
        $clone->orderBy = '';
        $clone->forUpdate = false;
        $clone->lock = null;

        if ($this->groupBy !== '') {
            // GROUP BY 查询：用子查询包裹以正确计算分组数
            $innerSql = $clone->buildSelect();
            $sql = "SELECT COUNT(*) as __count FROM ({$innerSql}) AS __count_sub";
        } else {
            $clone->select = $column === '*'
                ? "COUNT(*) as __count"
                : "COUNT(`{$column}`) as __count";
            $clone->groupBy = '';
            $clone->having = [];
            $sql = $clone->buildSelect();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($clone->bindings);
        $result = $stmt->fetch();
        return (int) (is_array($result) ? ($result['__count'] ?? 0) : 0);
    }

    public function sum(string $column): float
    {
        $this->assertNotRaw('sum');
        $this->validateAggregateColumn($column);
        $clone = clone $this;
        $clone->select = "SUM(`{$column}`) as __sum";
        $clone->forUpdate = false;
        $clone->lock = null;
        $clone->limit = 0;
        $clone->offset = 0;
        $clone->orderBy = '';
        $clone->groupBy = '';
        $clone->having = [];
        $sql = $clone->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($clone->bindings);
        $result = $stmt->fetch();
        return (float) (is_array($result) ? ($result['__sum'] ?? 0) : 0);
    }

    public function avg(string $column): float
    {
        $this->assertNotRaw('avg');
        $this->validateAggregateColumn($column);
        $clone = clone $this;
        $clone->select = "AVG(`{$column}`) as __avg";
        $clone->forUpdate = false;
        $clone->lock = null;
        $clone->limit = 0;
        $clone->offset = 0;
        $clone->orderBy = '';
        $clone->groupBy = '';
        $clone->having = [];
        $sql = $clone->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($clone->bindings);
        $result = $stmt->fetch();
        return (float) (is_array($result) ? ($result['__avg'] ?? 0) : 0);
    }

    public function max(string $column): mixed
    {
        $this->assertNotRaw('max');
        $this->validateAggregateColumn($column);
        $clone = clone $this;
        $clone->select = "MAX(`{$column}`) as __max";
        $clone->forUpdate = false;
        $clone->lock = null;
        $clone->limit = 0;
        $clone->offset = 0;
        $clone->orderBy = '';
        $clone->groupBy = '';
        $clone->having = [];
        $sql = $clone->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($clone->bindings);
        $result = $stmt->fetch();
        return is_array($result) ? ($result['__max'] ?? null) : null;
    }

    public function min(string $column): mixed
    {
        $this->assertNotRaw('min');
        $this->validateAggregateColumn($column);
        $clone = clone $this;
        $clone->select = "MIN(`{$column}`) as __min";
        $clone->forUpdate = false;
        $clone->lock = null;
        $clone->limit = 0;
        $clone->offset = 0;
        $clone->orderBy = '';
        $clone->groupBy = '';
        $clone->having = [];
        $sql = $clone->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($clone->bindings);
        $result = $stmt->fetch();
        return is_array($result) ? ($result['__min'] ?? null) : null;
    }

    public function insert(array $data): int|string
    {
        if ($this->isRaw) {
            throw new \RuntimeException('Cannot call insert() in raw query mode.');
        }
        if (empty($data)) {
            throw new \InvalidArgumentException('Insert data cannot be empty.');
        }
        $sql = $this->buildInsert($data);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $this->pdo->lastInsertId();
    }

    public function update(array $data): int
    {
        if ($this->isRaw) {
            throw new \RuntimeException('Cannot call update() in raw query mode.');
        }
        if (empty($data)) {
            throw new \InvalidArgumentException('Update data cannot be empty.');
        }
        $sql = $this->buildUpdate($data);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $sql = $this->buildDelete();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->rowCount();
    }

    public function chunk(int $count, callable $callback): void
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('chunk size must be at least 1, got ' . $count);
        }
        $page = 1;
        do {
            $clone = clone $this;
            $clone->limit($count, ($page - 1) * $count);
            $results = $clone->fetchAll();

            if (!empty($results)) {
                $continue = $callback($results, $page);
                if ($continue === false) {
                    break;
                }
            } else {
                break;
            }

            $page++;
        } while (count($results) === $count);
    }

    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->where[] = $sql;
        foreach ($bindings as $key => $value) {
            $placeholder = is_int($key) ? ':wr_' . count($this->bindings) : $key;
            $this->bindings[$placeholder] = $value;
            $search = is_int($key) ? '?' : $key;
            $this->where[count($this->where) - 1] = preg_replace(
                '/' . preg_quote($search, '/') . '/',
                $placeholder,
                $this->where[count($this->where) - 1],
                1
            );
        }
        return $this;
    }

    public function pluck(string $column): array
    {
        $clone = clone $this;
        $clone->select($column);
        $rows = $clone->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[] = reset($row);
        }
        return $result;
    }

    public function chunkById(int $count, callable $callback, string $column = 'id'): void
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('chunk size must be at least 1, got ' . $count);
        }
        $lastId = 0;
        do {
            $clone = clone $this;
            $clone->where($column, '>', $lastId);
            $clone->orderBy($column, 'ASC');
            $clone->limit($count);
            $results = $clone->fetchAll();

            if (empty($results)) {
                break;
            }

            $continue = $callback($results);
            if ($continue === false) {
                break;
            }

            $lastId = end($results)[$column] ?? null;
            if ($lastId === null) {
                break;
            }
        } while (count($results) === $count);
    }

    public function when(mixed $condition, callable $callback, ?callable $default = null): self
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default !== null) {
            $default($this, $condition);
        }
        return $this;
    }

    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $total = $this->count();
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $clone = clone $this;
        $clone->limit($perPage, ($page - 1) * $perPage);
        $items = $clone->fetchAll();

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $totalPages,
            'has_more' => $page < $totalPages
        ];
    }

    public function execute(): int
    {
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($this->bindings) ? $stmt->rowCount() : 0;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getSql(): string
    {
        return $this->buildSelect();
    }

    public function reset(): self
    {
        $this->table = '';
        $this->select = '*';
        $this->distinct = false;
        $this->where = [];
        $this->bindings = [];
        $this->orderBy = '';
        $this->groupBy = '';
        $this->having = [];
        $this->limit = 0;
        $this->offset = 0;
        $this->joins = [];
        $this->forUpdate = false;
        $this->lock = null;
        $this->cacheKey = null;
        $this->cacheTtl = null;
        $this->cacheEnabled = false;
        $this->isRaw = false;
        return $this;
    }
}
