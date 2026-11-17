<?php
declare(strict_types=1);

namespace core\exception;

class ValidationException extends FrameworkException
{
    // 与构造器默认 $code=422 及 Application 的 422 响应保持一致；
    // 否则继承的 getStatusCode() 恒为 500，与 getCode() 自相矛盾。
    protected int $statusCode = 422;

    private array $errors;

    public function __construct(array $errors = [], string $message = 'Validation failed', int $code = 422, ?\Throwable $previous = null)
    {
        $this->errors = $errors;
        parent::__construct($message, $code, $previous);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            // 字段的错误数组可能为空，跳过继续查找后续字段的真实错误
            if (is_array($fieldErrors) && isset($fieldErrors[0]) && is_string($fieldErrors[0])) {
                return $fieldErrors[0];
            }
            if (is_array($fieldErrors) && isset($fieldErrors[0]) && is_int($fieldErrors[0])) {
                return (string) $fieldErrors[0];
            }
            if (is_string($fieldErrors) && $fieldErrors !== '') {
                return $fieldErrors;
            }
        }
        return null;
    }
}
