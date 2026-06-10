<?php
declare(strict_types=1);

namespace core\contract;

use db\QueryBuilder;

interface ConnectionInterface
{
    public function getPdo(): \PDO;
    public function table(string $table): QueryBuilder;
    public function query(string $sql, array $bindings = []): array;
    public function execute(string $sql, array $bindings = []): int;
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollback(): bool;
    public function inTransaction(): bool;
    public function getDatabase(): string;
}
