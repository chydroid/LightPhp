<?php
declare(strict_types=1);

namespace core\exception;

class HttpException extends FrameworkException
{
    private int $httpStatusCode;

    public function __construct(int $httpStatusCode = 500, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->httpStatusCode = $httpStatusCode;
        parent::__construct($message ?: "HTTP Error {$httpStatusCode}", $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
