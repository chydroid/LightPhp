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
 * 强制删除用法：(new User)->force()->delete($id);
 */
trait SoftDelete
{
    private bool $forceDeleting = false;

    private string $trashedQuery = 'exclude';

    /**
     * 当本实例是通过 withTrashed()/onlyTrashed() 构造时，
     * 后续的静态查询入口（all/find/where/count...）应复用本实例，
     * 否则它们内部的 `new static()` 会把 trashedQuery 重置回 'exclude'，
     * 导致 SoftDelete::withTrashed()->all() 静默丢失已软删除记录
     * （实测返回 ["alive"] 而非 ["alive","gone"]）。
     *
     * PHP 允许以 $obj->staticMethod() 形式调用静态方法，但该调用
     * 不会携带 $this，因此需要借助静态属性记录「当前作用域实例」。
     *
     * @var static|null
     */
    private static ?object $queryScopeInstance = null;

    /**
     * 解析本次静态调用应使用的实例
     *
     * 作用域实例由 withTrashed()/onlyTrashed() 设置，
     * 在下一次静态入口取用后立即清空（作用域只生效一次），
     * 避免污染后续独立的静态调用。
     *
     * @return static
     */
    private static function resolveScopeInstance(): static
    {
        $scoped = self::$queryScopeInstance;
        if ($scoped instanceof static) {
            self::$queryScopeInstance = null;
            return $scoped;
        }
        return new static();
    }

    /**
     * 启用强制删除模式（返回新实例，不影响其他实例）
     *
     * 用法: $model->force()->delete($id);
     */
    public function force(): static
    {
        $pk = $this->attributes[$this->primaryKey] ?? null;
        $wasExists = $this->exists;
        $instance = clone $this;
        $instance->forceDeleting = true;
        $instance->exists = $wasExists;
        if ($pk !== null) {
            $instance->attributes[$this->primaryKey] = $pk;
        }
        return $instance;
    }

    /**
     * 检查当前模型实例是否已被软删除
     *
     * @return bool 如果 deleted_at 字段不为空则返回 true
     */
    public function trashed(): bool
    {
        return isset($this->attributes['deleted_at']) && $this->attributes['deleted_at'] !== null;
    }

    /**
     * 恢复一个被软删除的模型
     *
     * @return bool 恢复成功返回 true
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

        if (!$this->fireEvent('restoring')) {
            return false;
        }

        $result = $this->db()->where($this->primaryKey, '=', $pk)->update(['deleted_at' => null]);

        if ($result > 0) {
            $this->attributes['deleted_at'] = null;
            $this->fireEvent('restored');
            return true;
        }

        return false;
    }

    /**
     * 包含软删除的模型在内的查询
     */
    public static function withTrashed(): static
    {
        $instance = new static();
        $instance->trashedQuery = 'with';
        self::$queryScopeInstance = $instance;
        return $instance;
    }

    /**
     * 仅查询软删除的模型
     */
    public static function onlyTrashed(): static
    {
        $instance = new static();
        $instance->trashedQuery = 'only';
        self::$queryScopeInstance = $instance;
        return $instance;
    }

    /**
     * 静态查询入口使用的实例
     *
     * 若 withTrashed()/onlyTrashed() 刚被调用，复用其携带的
     * trashedQuery 状态的实例，否则新建。
     * 这是让 withTrashed()->all()/find()/where() 等正确工作的关键：
     * 它们内部若直接 new static()，trashedQuery 会退回 'exclude'，
     * 静默过滤掉已软删除记录。
     *
     * @return static
     */
    protected static function makeQueryInstance(): static
    {
        return self::resolveScopeInstance();
    }

    /**
     * 构建新的查询构建器，根据软删除状态添加过滤条件
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
     * - 传入 $id 时按主键删除指定记录
     * - $id 为 null 时删除当前实例（使用 $this->attributes[$primaryKey]）
     *
     * @param int|string|null $id 主键值，为 null 时删除当前实例
     * @return int 受影响行数
     * @throws \RuntimeException 当 $id 为 null 且当前实例无主键值时
     */
    public function delete(int|string|null $id = null): int
    {
        if ($id === null) {
            $id = $this->attributes[$this->primaryKey] ?? null;
            if ($id === null) {
                throw new \RuntimeException(
                    sprintf('Cannot delete model [%s] without a primary key value.', static::class)
                );
            }
        }

        // 若当前实例已表示一条被软删除的记录，则避免重复删除/更新
        if (!$this->forceDeleting && $this->exists && $this->trashed()) {
            return 0;
        }

        if (!$this->fireEvent('deleting')) {
            return 0;
        }

        if ($this->forceDeleting) {
            $result = $this->db()->where($this->primaryKey, '=', $id)->delete();
        } else {
            $result = $this->db()->where($this->primaryKey, '=', $id)->update(['deleted_at' => date($this->dateFormat)]);
        }

        if ($result > 0) {
            $this->fireEvent('deleted');
        }
        return $result;
    }
}
