<?php
declare(strict_types=1);

namespace log;

use core\contract\LoggerInterface;

/**
 * 文件日志记录器
 * 
 * 实现 PSR-3 日志接口，支持多种日志级别和上下文信息。
 * 日志按日期分割存储，支持消息模板插值。
 */
class Logger implements LoggerInterface
{
    /** @var string 日志文件存储目录 */
    private string $logPath;

    /** @var string 当前日志级别 */
    private string $level = 'info';

    /** @var array 日志级别优先级映射 */
    private array $levels = [
        'debug' => 0,
        'info'  => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /**
     * 构造函数
     * 
     * @param string $logPath 日志目录路径
     */
    public function __construct(string $logPath = STORAGE_PATH . 'log/')
    {
        $this->logPath = rtrim($logPath, '/') . '/';
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * 记录日志（PSR-3）
     * 
     * @param string $level 日志级别
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;
        if (!isset($this->levels[$level]) || $this->levels[$level] < $this->levels[$this->level]) {
            return;
        }

        $interpolated = $this->interpolate($message, $context);
        $remaining = $this->remainingContext($message, $context);
        $logLine = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $interpolated,
            !empty($remaining) ? ' ' . json_encode($remaining, JSON_UNESCAPED_UNICODE) : ''
        );

        $filename = $this->logPath . date('Y-m-d') . '.log';
        file_put_contents($filename, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * 记录 DEBUG 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * 记录 INFO 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * 记录 NOTICE 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * 记录 WARNING 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * 记录 ERROR 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * 记录 CRITICAL 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * 记录 ALERT 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * 记录 EMERGENCY 级别日志
     * 
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文信息
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * 设置日志级别
     * 
     * @param string $level 日志级别
     */
    public function setLevel(string $level): void
    {
        $this->level = $level;
    }

    /**
     * 消息模板插值
     * 
     * 将上下文中的值替换到消息模板的 {key} 占位符中。
     * 
     * @param string $message 消息模板
     * @param array $context 上下文信息
     * @return string 插值后的消息
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * 获取未插值的剩余上下文
     * 
     * @param string $message 消息模板
     * @param array $context 上下文信息
     * @return array 剩余上下文
     */
    private function remainingContext(string $message, array $context): array
    {
        $remaining = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
                $remaining[$key] = is_object($value) ? get_class($value) : 'array';
            } elseif (str_contains($message, '{' . $key . '}')) {
                continue;
            } else {
                $remaining[$key] = $value;
            }
        }
        return $remaining;
    }

    /**
     * 清除指定日期的日志文件
     * 
     * @param string|null $date 日期（格式：Y-m-d），默认为今天
     */
    public function clear(string $date = null): void
    {
        $date = $date ?? date('Y-m-d');
        $file = $this->logPath . $date . '.log';
        if (file_exists($file)) {
            unlink($file);
        }
    }
}
