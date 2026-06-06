<?php
declare(strict_types=1);

namespace traits;

use db\QueryBuilder;

/**
 * 软删除 Trait
 *
 * 为模型提供软删除功能，使用 deleted_at 字段标记删除状态，
 * 而非真正从数据库中移除记录。默认情况下，查询会自动排除已软删除的记录。
 *
 * 使用方式：在模型类中 use SoftDelete 即可启用软删除功能。
 * 软删除的模型在执行 delete 操作时，会将 deleted_at 设置为当前时间戳，
 * 而非真正从数据库中删除记录。查询时默认过滤已软删除的记录。
 */
trait SoftDelete
{
    private static bool $forceDelete = false;

    private string $trashedQuery = 'exclude';

    /**
     * 启用强制删除模式
     *
     * 启用后，调用 delete 方法将真正从数据库中删除记录，
     * 而不是设置 deleted_at 字段。此设置为静态属性，影响当前模型类的所有实例。
     */
    public static function forceDelete(): void
    {
        self::$forceDelete = true;
    }

    /**
     * 恢复软删除模式（默认）
     *
     * 调用 delete 方法时将设置 deleted_at 字段而非真正删除记录。
     * 此方法用于在调用 forceDelete() 后恢复默认的软删除行为。
     */
    public static function softDelete(): void
    {
        self::$forceDelete = false;
    }

    /**
     * 检查当前模型实例是否已被软删除
     *
     * 通过判断 deleted_at 字段是否不为空来确定模型是否已被软删除。
     *
     * @return bool 如果 deleted_at 字段不为空则返回 true，否则返回 false
     */
    public function trashed(): bool
    {
        return isset($this->attributes['deleted_at']) && $this->attributes['deleted_at'] !== null;
    }

    /**
     * 恢复一个被软删除的模型
     *
     * 将数据库中对应记录的 deleted_at 字段设置为 null，
     * 同时更新当前模型实例的 attributes，使模型恢复为未删除状态。
     * 仅对已存在于数据库中的模型实例有效。
     *
     * @return bool 恢复成功返回 true，模型不存在或恢复失败返回 false
     */
    public function restore(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $pk = $this->attributes[$this->primaryKey] ?? null;
        if ($pk === null) {
            return false;
        }

        $result = $this->db()->where($this->primaryKey, '=', $pk)->update(['deleted_at' => null]);

        if ($result > 0) {
            $this->attributes['deleted_at'] = null;
            return true;
        }

        return false;
    }

    /**
     * 包含软删除的模型在内的查询
     *
     * 返回一个新的模型实例，该实例在构建查询时不会添加 deleted_at 的过滤条件，
     * 从而可以查询到所有记录，包括已软删除的记录。
     *
     * 用法示例：
     *   $model->withTrashed()->fetchAll();  // 查询所有记录（含已软删除）
     *   $model->withTrashed()->find($id);   // 查找记录（含已软删除）
     *
     * @return static 新的模型实例，查询时包含软删除记录
     */
    public function withTrashed(): static
    {
        $wasExists = $this->exists;
        $instance = clone $this;
        $instance->trashedQuery = 'with';
        $instance->exists = $wasExists;
        return $instance;
    }

    /**
     * 仅查询软删除的模型
     *
     * 返回一个新的模型实例，该实例在构建查询时只会包含 deleted_at 不为空的记录，
     * 即仅查询已被软删除的记录。
     *
     * 用法示例：
     *   $model->onlyTrashed()->fetchAll();  // 仅查询已软删除的记录
     *   $model->onlyTrashed()->count();     // 统计已软删除的记录数
     *
     * @return static 新的模型实例，查询时仅包含软删除记录
     */
    public function onlyTrashed(): static
    {
        $wasExists = $this->exists;
        $instance = clone $this;
        $instance->trashedQuery = 'only';
        $instance->exists = $wasExists;
        return $instance;
    }

    /**
     * 构建新的查询构建器，根据软删除状态添加过滤条件
     *
     * 重写模型的 newQuery 方法，在查询构建器上自动添加软删除过滤条件：
     * - exclude 模式（默认）：添加 whereNull('deleted_at')，排除已软删除的记录
     * - with 模式：不添加任何过滤条件，包含所有记录
     * - only 模式：添加 whereNotNull('deleted_at')，仅包含已软删除的记录
     *
     * @return QueryBuilder 查询构建器实例
     */
    protected function newQuery(): QueryBuilder
    {
        $query = $this->db();

        if ($this->trashedQuery === 'exclude') {
            $query->whereNull('deleted_at');
        } elseif ($this->trashedQuery === 'only') {
            $query->whereNotNull('deleted_at');
        }

        return $query;
    }

    /**
     * 删除模型记录（软删除或强制删除）
     *
     * 根据当前删除模式执行不同的删除操作：
     * - 软删除模式（默认）：将 deleted_at 设置为当前时间戳，记录仍保留在数据库中
     * - 强制删除模式：真正从数据库中删除记录
     *
     * 注意：此方法直接通过主键定位记录，不受软删除过滤条件影响，
     * 以确保可以删除（软删除或强制删除）任何状态的记录。
     *
     * @param int|string $id 主键值
     * @return int 受影响行数
     */
    public function delete(int|string $id): int
    {
        if (self::$forceDelete) {
            return $this->db()->where($this->primaryKey, '=', $id)->delete();
        }

        return $this->db()->where($this->primaryKey, '=', $id)->update(['deleted_at' => date($this->dateFormat)]);
    }
}
