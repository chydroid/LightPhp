<?php
declare(strict_types=1);

namespace core\console;

/**
 * CLI 应用 - 命令注册和调度
 */
class Console
{
    /** @var array<string, Command> */
    private array $commands = [];

    private string $name = 'LightPHP Console';
    private string $version = '2.15.9';

    public function __construct(string $name = 'LightPHP Console', string $version = '2.15.9')
    {
        $this->name = $name;
        $this->version = $version;
    }

    public function register(Command $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        // 用户显式注册的同名命令优先；否则回退到内置 list。
        // 此前无条件拦截 'list'，即使用户注册了自己的 list 命令也永远不会被执行。
        if ($commandName === 'list' && !isset($this->commands[$commandName])) {
            return $this->listCommands();
        }

        if ($commandName === '--help' || $commandName === '-h') {
            return $this->showHelp();
        }

        if ($commandName === '--version' || $commandName === '-V') {
            return $this->showVersion();
        }

        if (isset($this->commands[$commandName])) {
            $command = $this->commands[$commandName];
            $command->parseInput($args);

            // 缺必填参数时给出可读提示。
            // 此前直接把 null 传给命令体内的 preg_match()/Config::get() 等，
            // 抛出 TypeError 并打印完整堆栈（实测 make:controller 退出码 255）。
            $missing = $command->missingRequiredArguments();
            if ($missing !== []) {
                echo "\033[31mMissing required argument(s): " . implode(', ', $missing) . "\033[0m\n";
                echo 'Usage: php console ' . $command->getSignature() . "\n";
                return 1;
            }

            try {
                return $command->handle();
            } catch (\TypeError $e) {
                // 兜底：命令体对参数类型处理不当时给出可读信息，而非 PHP 堆栈
                echo "\033[31mInvalid arguments for '{$commandName}': " . $e->getMessage() . "\033[0m\n";
                echo 'Usage: php console ' . $command->getSignature() . "\n";
                return 1;
            }
        }

        echo "\033[31mCommand '{$commandName}' not found.\033[0m\n";
        echo "Run 'php console list' to see available commands.\n";
        return 1;
    }

    private function listCommands(): int
    {
        $cmd = new class extends Command {
            protected string $signature = 'list';
            public function handle(): int {
                return 0;
            }
        };

        echo "\033[36m{$this->name} v{$this->version}\033[0m\n\n";
        echo "Usage:\n  php console <command> [arguments] [options]\n\n";
        echo "Available commands:\n";

        $headers = ['Command', 'Description'];
        $rows = [];
        foreach ($this->commands as $name => $command) {
            $rows[] = [$name, $command->getDescription()];
        }
        $rows[] = ['list', 'List all available commands'];

        $cmd->table($headers, $rows);

        return 0;
    }

    private function showHelp(): int
    {
        $cmd = new class extends Command {
            protected string $signature = 'help';
            public function handle(): int { return 0; }
        };
        echo "{$this->name} v{$this->version}\n\n";
        echo "Usage:\n";
        echo "  php console <command> [arguments] [options]\n\n";
        echo "  php console list           List all commands\n";
        echo "  php console --help         Show this help\n";
        echo "  php console --version      Show version\n";
        return 0;
    }

    private function showVersion(): int
    {
        echo "{$this->name} v{$this->version}\n";
        return 0;
    }
}