<?php
declare(strict_types=1);

namespace core\console;

/**
 * CLI 命令基类 - 参考 Laravel Artisan
 * 提供命令行工具基础框架
 *
 * 使用方式:
 *   class HelloCommand extends Command {
 *       protected string $signature = 'hello {name}';
 *       public function handle(): int { echo "Hello, {$this->argument('name')}!\n"; return 0; }
 *   }
 */
abstract class Command
{
    protected string $signature = '';
    protected string $description = '';
    protected array $arguments = [];
    protected array $options = [];

    /**
     * 执行命令
     * @return int 0表示成功，非0表示失败
     */
    abstract public function handle(): int;

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getName(): string
    {
        $parts = explode(' ', $this->signature, 2);
        return $parts[0];
    }

    public function parseInput(array $args): void
    {
        $definition = $this->parseSignature();
        $this->arguments = [];
        $this->options = [];

        $positionalIdx = 0;
        $positionalDefs = [];

        foreach ($definition as $def) {
            if ($def['type'] === 'argument') {
                $positionalDefs[] = $def;
            }
        }

        $argCount = count($args);
        for ($i = 0; $i < $argCount; $i++) {
            $arg = $args[$i];

            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);
                $value = true;
                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                } elseif (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
                    $value = $args[++$i];
                }
                $this->options[$name] = $value;
            } elseif (str_starts_with($arg, '-')) {
                $name = substr($arg, 1);
                $value = true;
                if (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
                    $value = $args[++$i];
                }
                $this->options[$name] = $value;
            } else {
                if (isset($positionalDefs[$positionalIdx])) {
                    $this->arguments[$positionalDefs[$positionalIdx]['name']] = $arg;
                }
                $positionalIdx++;
            }
        }

        foreach ($positionalDefs as $def) {
            if (!isset($this->arguments[$def['name']])) {
                $this->arguments[$def['name']] = $def['default'] ?? null;
            }
        }
    }

    public function argument(string $name, mixed $default = null): mixed
    {
        return $this->arguments[$name] ?? $default;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    protected function parseSignature(): array
    {
        $parts = explode(' ', $this->signature);
        array_shift($parts);
        $definition = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            if (str_starts_with($part, '{--')) {
                $definition[] = $this->parseOption($part);
            } elseif (str_starts_with($part, '{')) {
                $definition[] = $this->parseArgument($part);
            }
        }

        return $definition;
    }

    private function parseArgument(string $definition): array
    {
        $name = trim($definition, '{}');
        $required = true;
        $default = null;

        if (str_ends_with($name, '?')) {
            $name = rtrim($name, '?');
            $required = false;
        }

        if (str_contains($name, '=')) {
            [$name, $default] = explode('=', $name, 2);
            $required = false;
        }

        return [
            'type' => 'argument',
            'name' => $name,
            'required' => $required,
            'default' => $default,
        ];
    }

    private function parseOption(string $definition): array
    {
        $definition = trim($definition, '{}');
        $name = substr($definition, 2);
        $default = false;

        if (str_contains($name, '=')) {
            [$name, $default] = explode('=', $name, 2);
        }

        return [
            'type' => 'option',
            'name' => $name,
            'default' => $default,
        ];
    }

    protected function info(string $message): void
    {
        echo "\033[32m{$message}\033[0m\n";
    }

    protected function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m\n";
    }

    protected function warn(string $message): void
    {
        echo "\033[33m{$message}\033[0m\n";
    }

    protected function line(string $message): void
    {
        echo "{$message}\n";
    }

    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        $headerLine = '| ' . implode(' | ', array_map(fn($h, $i) => str_pad($h, $widths[$i]), $headers, array_keys($headers))) . ' |';

        echo $separator . "\n";
        echo $headerLine . "\n";
        echo $separator . "\n";

        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = str_pad((string) $cell, $widths[$i]);
            }
            echo '| ' . implode(' | ', $cells) . " |\n";
        }

        echo $separator . "\n";
    }
}