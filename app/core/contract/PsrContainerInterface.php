<?php
declare(strict_types=1);

namespace core\contract;

interface PsrContainerInterface
{
    /**
     * @throws PsrNotFoundExceptionInterface
     * @throws PsrContainerExceptionInterface
     */
    public function get(string $id): mixed;

    public function has(string $id): bool;
}