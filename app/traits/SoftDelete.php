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
    public function withTrashed(): static
    {
        $pk = $this->attributes[$this->primaryKey] ?? null;
        $wasExists = $this->exists;
        $instance = clone $this;
        $instance->trashedQuery = 'with';
        $instance->exists = $wasExists;
        if ($pk !== null) {
            $instance->attributes[$this->primaryKey] = $pk;
        }
        return $instance;
    }

    /**
     * 仅查询软删除的模型
     */
    public function onlyTrashed(): static
    {
        $pk = $this->attributes[$this->primaryKey] ?? null;
        $wasExists = $this->exists;
        $instance = clone $this;
        $instance->trashedQuery = 'only';
        $instance->exists = $wasExists;
        if ($pk !== null) {
            $instance->attributes[$this->primaryKey] = $pk;
        }
        return $instance;
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
     * @param int|string $id 主键值
     * @return int 受影响行数
     */
    public function delete(int|string $id): int
    {
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
