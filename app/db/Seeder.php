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

    /** @var array<string, string[]> 按类名分组的种子列表 */
    private static array $seeders = [];

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
     * @throws \InvalidArgumentException 当类不是Seeder子类时
     */
    public function call(string $seederClass): void
    {
        if (!is_subclass_of($seederClass, self::class)) {
            throw new \InvalidArgumentException("Class {$seederClass} is not a Seeder subclass.");
        }
        $seeder = new $seederClass($this->db);
        $seeder->run();
    }

    /**
     * 注册一个种子类
     *
     * @param string $seederClass 种子类名
     * @throws \InvalidArgumentException 当类不是Seeder子类时
     */
    public static function register(string $seederClass): void
    {
        if (!is_subclass_of($seederClass, self::class)) {
            throw new \InvalidArgumentException("Class {$seederClass} is not a Seeder subclass.");
        }
        $caller = static::class;
        if (!isset(self::$seeders[$caller])) {
            self::$seeders[$caller] = [];
        }
        if (!in_array($seederClass, self::$seeders[$caller], true)) {
            self::$seeders[$caller][] = $seederClass;
        }
    }

    /**
     * 运行所有已注册的种子类
     *
     * @param \db\Connection $db 数据库连接实例
     */
    public static function runAll(\db\Connection $db): void
    {
        $caller = static::class;
        $seeders = self::$seeders[$caller] ?? [];
        foreach ($seeders as $seederClass) {
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
        return self::$seeders[static::class] ?? [];
    }
}
