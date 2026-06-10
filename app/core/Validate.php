<?php
declare(strict_types=1);

namespace core;

/**
 * 表单验证器
 * 
 * 提供多种验证规则，支持链式调用和自定义错误消息。
 * 
 * 支持的验证规则：
 * - required: 必填
 * - email: 邮箱格式
 * - min: 最小值/最小长度
 * - max: 最大值/最大长度
 * - numeric: 数值
 * - integer: 整数
 * - float: 浮点数
 * - url: URL格式
 * - ip: IP地址格式
 * - alpha: 字母
 * - alphaNum: 字母数字
 * - in: 在指定列表中
 * - notIn: 不在指定列表中
 * - regex: 正则匹配
 * - date: 日期格式
 * - confirmed: 确认字段匹配
 */
class Validate
{
    /** @var array 验证规则 */
    private array $rules = [];

    /** @var array 自定义错误消息 */
    private array $messages = [];

    /** @var array 验证错误信息 */
    private array $errors = [];

    /** @var array 当前验证的数据 */
    private array $data = [];

    /**
     * 设置验证规则
     * 
     * @param array $rules 规则数组
     * @return self
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * 设置自定义错误消息
     * 
     * @param array $messages 消息数组
     * @return self
     */
    public function messages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * 执行验证
     * 
     * @param array $data 待验证数据
     * @param array|null $rules 验证规则（可选）
     * @return bool 是否通过验证
     */
    public function validate(array $data, ?array $rules = null): bool
    {
        $this->errors = [];
        $this->data = $data;

        if ($rules !== null) {
            $this->rules = $rules;
        }

        foreach ($this->rules as $field => $rule) {
            $ruleList = is_array($rule) ? $rule : explode('|', $rule);

            foreach ($ruleList as $r) {
                $this->applyRule($field, $r);
            }
        }

        return empty($this->errors);
    }

    /**
     * 验证是否通过
     * 
     * @return bool 是否通过
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * 验证是否失败
     * 
     * @return bool 是否失败
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * 应用单个验证规则
     * 
     * @param string $field 字段名
     * @param string $rule 规则名（支持参数，如 min:10）
     */
    private function applyRule(string $field, string $rule): void
    {
        $params = [];
        
        if (str_contains($rule, ':')) {
            [$rule, $paramStr] = explode(':', $rule, 2);
            // regex 规则的参数不按逗号分割（正则中可能包含逗号）
            if ($rule === 'regex') {
                $params = [$paramStr];
            } else {
                $params = explode(',', $paramStr);
            }
        }

        $value = $this->data[$field] ?? null;

        // 非必填字段值为 null/空字符串时，跳过除 required 外的所有验证规则
        if ($rule !== 'required' && ($value === null || $value === '')) {
            return;
        }

        $ruleMethod = 'validate' . ucfirst($rule);

        if (method_exists($this, $ruleMethod)) {
            if (!$this->$ruleMethod($field, $value, $params)) {
                $this->addError($field, $rule, $params);
            }
        } else {
            trigger_error("Validate: Unknown validation rule '{$rule}' for field '{$field}'", E_USER_WARNING);
        }
    }

    /**
     * 添加错误信息
     * 
     * @param string $field 字段名
     * @param string $rule 规则名
     * @param array $params 规则参数
     */
    private function addError(string $field, string $rule, array $params = []): void
    {
        $key = $field . '.' . $rule;
        $message = $this->messages[$key] ?? $this->messages[$field] ?? "{$field} is invalid";
        
        if (!empty($params)) {
            $message = str_replace([':min', ':max', ':length'], $params, $message);
        }

        $this->errors[$field][] = $message;
    }

    /**
     * 获取所有错误信息
     * 
     * @return array 错误信息数组
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 获取第一个错误信息
     * 
     * @param string|null $field 字段名，为 null 时返回任意第一个错误
     * @return string|null 错误信息
     */
    public function firstError(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field][0] ?? null;
        }
        
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0];
        }
        
        return null;
    }

    private function validateRequired(string $field, $value, array $params): bool
    {
        if (is_null($value)) return false;
        if (is_string($value) && trim($value) === '') return false;
        if (is_array($value) && count($value) === 0) return false;
        return true;
    }

    private function validateEmail(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateMin(string $field, $value, array $params): bool
    {
        if (empty($params)) {
            return false;
        }
        $min = (int) $params[0];
        if (is_numeric($value)) return $value >= $min;
        if (is_string($value)) return mb_strlen($value) >= $min;
        if (is_array($value)) return count($value) >= $min;
        return false;
    }

    private function validateMax(string $field, $value, array $params): bool
    {
        if (empty($params)) {
            return false;
        }
        $max = (int) $params[0];
        if (is_numeric($value)) return $value <= $max;
        if (is_string($value)) return mb_strlen($value) <= $max;
        if (is_array($value)) return count($value) <= $max;
        return false;
    }

    private function validateNumeric(string $field, $value, array $params): bool
    {
        return is_numeric($value);
    }

    private function validateInteger(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function validateFloat(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    private function validateUrl(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function validateIp(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function validateAlpha(string $field, $value, array $params): bool
    {
        return preg_match('/^[a-zA-Z]+$/', (string) $value) === 1;
    }

    private function validateAlphaNum(string $field, $value, array $params): bool
    {
        return preg_match('/^[a-zA-Z0-9]+$/', (string) $value) === 1;
    }

    private function validateIn(string $field, $value, array $params): bool
    {
        return in_array($value, $params, true);
    }

    private function validateNotIn(string $field, $value, array $params): bool
    {
        return !in_array($value, $params, true);
    }

    private function validateRegex(string $field, $value, array $params): bool
    {
        if (empty($params)) {
            return false;
        }

        $pattern = $params[0];
        set_error_handler(fn() => true);
        $result = preg_match($pattern, (string) $value);
        restore_error_handler();

        if ($result === false) {
            return false;
        }

        return $result === 1;
    }

    private function validateDate(string $field, $value, array $params): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $format = $params[0] ?? 'Y-m-d';
        $d = \DateTime::createFromFormat($format, $value);
        return $d && $d->format($format) === $value;
    }

    private function validateConfirmed(string $field, $value, array $params): bool
    {
        $confirmedField = $field . '_confirmation';
        return $value === ($this->data[$confirmedField] ?? null);
    }

    private function validateUnique(string $field, $value, array $params): bool
    {
        $this->addError($field, 'unique', $params);
        return false;
    }

    private function validateExists(string $field, $value, array $params): bool
    {
        $this->addError($field, 'exists', $params);
        return false;
    }

    /**
     * 获取验证通过的字段数据
     * 
     * @return array 验证通过的数据
     */
    public function validated(): array
    {
        $data = $this->data;
        foreach ($this->errors as $field => $errors) {
            unset($data[$field]);
        }
        return $data;
    }
}
