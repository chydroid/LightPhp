<?php
declare(strict_types=1);

namespace db;

use core\contract\ConnectionInterface;

/**
 * 数据库连接类
 * 
 * 封装 PDO 数据库连接，提供查询构建器入口和事务管理功能。
 */
class Connection implements ConnectionInterface
{
    /** @var \PDO PDO 数据库连接 */
    private \PDO $pdo;

    /** @var array 数据库配置 */
    private array $config;

    /** @var string 数据库名称 */
    private string $database;

    /**
     * 构造函数
     * 
     * @param array $config 数据库配置数组
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * 建立数据库连接
     */
    private function connect(): void
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 3306);
        $database = $this->config['database'] ?? 'test';
        $username = $this->config['username'] ?? 'root';
        $password = $this->config['password'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $host)) {
            throw new \InvalidArgumentException("Invalid database host: {$host}");
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Invalid database port: {$port}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new \InvalidArgumentException("Invalid database name: {$database}");
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $charset)) {
            throw new \InvalidArgumentException("Invalid charset: {$charset}");
        }

        $this->database = $database;
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new \PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new \core\exception\DatabaseException(
                'Database connection failed. Please check your configuration.',
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * 获取 PDO 连接实例
     * 
     * @return \PDO PDO 连接
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * 检查是否在事务中
     *
     * @return bool 是否在事务中
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * 获取查询构建器
     * 
     * @param string $table 表名
     * @return QueryBuilder 查询构建器实例
     */
    public function table(string $table): QueryBuilder
    {
        return (new QueryBuilder($this->pdo))->table($table);
    }

    /**
     * 执行 SQL 查询
     * 
     * @param string $sql SQL 语句
     * @param array $bindings 绑定参数
     * @return array 查询结果
     */
    public function query(string $sql, array $bindings = []): array
    {
        return (new QueryBuilder($this->pdo))->raw($sql, $bindings)->fetchAll();
    }

    /**
     * 执行 SQL 语句（无返回结果）
     * 
     * @param string $sql SQL 语句
     * @param array $bindings 绑定参数
     * @return int 受影响行数
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return (new QueryBuilder($this->pdo))->raw($sql, $bindings)->execute();
    }

    /**
     * 开始事务
     * 
     * @return bool 是否成功
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * 提交事务
     * 
     * @return bool 是否成功
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * 回滚事务
     * 
     * @return bool 是否成功
     */
    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * 获取数据库名称
     * 
     * @return string 数据库名称
     */
    public function getDatabase(): string
    {
        return $this->database;
    }
}
