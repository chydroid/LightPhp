<?php
declare(strict_types=1);

namespace core\exception;

class DatabaseException extends FrameworkException
{
    public function __construct(string $message = 'Database error', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
