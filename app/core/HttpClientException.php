<?php
declare(strict_types=1);

namespace core;

/**
 * HTTP 客户端异常 - 网络错误或 HTTP >= 400 时（throw=true）抛出
 */
class HttpClientException extends \RuntimeException
{
    private ?HttpResponse $response;

    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, ?HttpResponse $response = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): ?HttpResponse
    {
        return $this->response;
    }
}
