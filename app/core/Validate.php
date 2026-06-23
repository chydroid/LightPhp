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
                $r = trim($r);
                if ($r === '') {
                    continue;
                }
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
            $placeholders = [':min', ':max', ':length'];
            $search = [];
            $replace = [];
            foreach ($params as $i => $param) {
                if (isset($placeholders[$i])) {
                    $search[] = $placeholders[$i];
                    $replace[] = (string) $param;
                }
            }
            if (!empty($search)) {
                $message = str_replace($search, $replace, $message);
            }
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
        if (is_string($value)) return \strlen($value) >= $min;
        if (is_array($value)) return \count($value) >= $min;
        return false;
    }

    private function validateMax(string $field, $value, array $params): bool
    {
        if (empty($params)) {
            return false;
        }
        $max = (int) $params[0];
        if (is_numeric($value)) return $value <= $max;
        if (is_string($value)) return \strlen($value) <= $max;
        if (is_array($value)) return \count($value) <= $max;
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
        return in_array((string)$value, $params, true);
    }

    private function validateNotIn(string $field, $value, array $params): bool
    {
        return !in_array((string)$value, $params, true);
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
        throw new \RuntimeException("The 'unique' rule requires a database connection, which is not available in the validator.");
    }

    private function validateExists(string $field, $value, array $params): bool
    {
        throw new \RuntimeException("The 'exists' rule requires a database connection, which is not available in the validator.");
    }

    private function validateArray(string $field, $value, array $params): bool
    {
        return is_array($value);
    }

    private function validateString(string $field, $value, array $params): bool
    {
        return is_string($value);
    }

    private function validateSize(string $field, $value, array $params): bool
    {
        if (empty($params)) {
            return false;
        }
        $size = (int) $params[0];
        // 类型检查顺序需与 validateMin/validateMax/validateBetween 保持一致：
        // is_numeric 优先，避免数字字符串（如 "10"）被当作字符串按长度校验
        if (is_numeric($value)) return $value == $size;
        if (is_string($value)) return \strlen($value) === $size;
        if (is_array($value)) return \count($value) === $size;
        return false;
    }

    private function validateBetween(string $field, $value, array $params): bool
    {
        if (\count($params) < 2) {
            return false;
        }
        $min = (int) $params[0];
        $max = (int) $params[1];
        if (is_numeric($value)) return $value >= $min && $value <= $max;
        if (is_string($value)) {
            $len = \strlen($value);
            return $len >= $min && $len <= $max;
        }
        if (is_array($value)) {
            $cnt = \count($value);
            return $cnt >= $min && $cnt <= $max;
        }
        return false;
    }

    private function validateBoolean(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null;
    }

    private function validateBefore(string $field, $value, array $params): bool
    {
        if (empty($params) || !is_string($value)) {
            return false;
        }
        $dateValue = strtotime($value);
        $dateCompare = strtotime($params[0]);
        if ($dateValue === false || $dateCompare === false) {
            return false;
        }
        return $dateValue < $dateCompare;
    }

    private function validateAfter(string $field, $value, array $params): bool
    {
        if (empty($params) || !is_string($value)) {
            return false;
        }
        $dateValue = strtotime($value);
        $dateCompare = strtotime($params[0]);
        if ($dateValue === false || $dateCompare === false) {
            return false;
        }
        return $dateValue > $dateCompare;
    }

    private function validateDifferent(string $field, $value, array $params): bool
    {
        if (empty($params)) {
            return false;
        }
        $otherField = $params[0];
        return $value !== ($this->data[$otherField] ?? null);
    }

    private function validateSame(string $field, $value, array $params): bool
    {
        if (empty($params)) {
            return false;
        }
        $otherField = $params[0];
        return $value === ($this->data[$otherField] ?? null);
    }

    private function validateNullable(string $field, $value, array $params): bool
    {
        return true;
    }

    private function validateOptional(string $field, $value, array $params): bool
    {
        return true;
    }

    private function validateDigits(string $field, $value, array $params): bool
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $digits = (string) $value;
        if (!preg_match('/^\d+$/', $digits)) {
            return false;
        }
        if (!empty($params)) {
            $length = (int) $params[0];
            return strlen($digits) === $length;
        }
        return true;
    }

    private function validateDigitsBetween(string $field, $value, array $params): bool
    {
        if (\count($params) < 2 || (!is_string($value) && !is_int($value))) {
            return false;
        }
        $min = (int) $params[0];
        $max = (int) $params[1];
        $digits = (string) $value;
        return (bool) preg_match('/^\d+$/', $digits) && \strlen($digits) >= $min && \strlen($digits) <= $max;
    }

    /**
     * 获取验证通过的字段数据
     * 
     * @return array 验证通过的数据
     */
    public function validated(): array
    {
        $data = [];
        foreach (array_keys($this->rules) as $field) {
            if (!isset($this->errors[$field]) && array_key_exists($field, $this->data)) {
                $data[$field] = $this->data[$field];
            }
        }
        return $data;
    }
}
