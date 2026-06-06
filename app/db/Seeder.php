<?php
declare(strict_types=1);

namespace db;

/**
 * 数据库种子抽象类
 *
 * 提供数据库种子数据的注册与执行机制，支持在子类中定义种子数据逻辑，
 * 并通过注册机制批量运行所有种子类。
 */
abstract class Seeder
{
    /** @var \db\Connection 数据库连接 */
    protected \db\Connection $db;

    /** @var array 已注册的种子类列表 */
    protected static array $seeders = [];

    /**
     * 构造函数
     *
     * @param \db\Connection $db 数据库连接实例
     */
    public function __construct(\db\Connection $db)
    {
        $this->db = $db;
    }

    /**
     * 执行种子数据填充
     */
    abstract public function run(): void;

    /**
     * 调用另一个种子类执行
     *
     * @param string $seederClass 种子类名
     */
    public function call(string $seederClass): void
    {
        $seeder = new $seederClass($this->db);
        $seeder->run();
    }

    /**
     * 注册一个种子类
     *
     * @param string $seederClass 种子类名
     */
    public static function register(string $seederClass): void
    {
        if (!in_array($seederClass, static::$seeders, true)) {
            static::$seeders[] = $seederClass;
        }
    }

    /**
     * 运行所有已注册的种子类
     *
     * @param \db\Connection $db 数据库连接实例
     */
    public static function runAll(\db\Connection $db): void
    {
        foreach (static::$seeders as $seederClass) {
            $seeder = new $seederClass($db);
            $seeder->run();
        }
    }

    /**
     * 获取所有已注册的种子类
     *
     * @return array 种子类列表
     */
    public static function getSeeders(): array
    {
        return static::$seeders;
    }
}
