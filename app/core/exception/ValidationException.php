<?php
declare(strict_types=1);

namespace core\exception;

class ValidationException extends FrameworkException
{
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
            if (is_array($fieldErrors) && isset($fieldErrors[0])) {
                return $fieldErrors[0];
            }
            if (is_string($fieldErrors) && $fieldErrors !== '') {
                return $fieldErrors;
            }
        }
        return null;
    }
}
