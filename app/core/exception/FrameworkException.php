<?php
declare(strict_types=1);

namespace core\exception;

class FrameworkException extends \RuntimeException
{
    protected int $statusCode = 500;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
