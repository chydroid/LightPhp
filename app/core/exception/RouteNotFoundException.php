<?php
declare(strict_types=1);

namespace core\exception;

class RouteNotFoundException extends FrameworkException
{
    protected int $statusCode = 404;

    public function __construct(string $message = 'Route not found', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
