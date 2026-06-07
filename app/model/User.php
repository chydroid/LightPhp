<?php
declare(strict_types=1);

namespace model;

class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password', 'status'];
    protected array $hidden = ['password'];
    protected array $casts = [
        'status' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 密码修改器：自动哈希密码
     */
    protected function setPasswordAttribute(mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $this->attributes['password'] = password_hash((string) $value, PASSWORD_DEFAULT);
        }
    }
}
