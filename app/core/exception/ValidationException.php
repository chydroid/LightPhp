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
            return $fieldErrors[0] ?? null;
        }
        return null;
    }
}
