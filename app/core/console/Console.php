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
    private string $version = '2.11.0';

    public function __construct(string $name = 'LightPHP Console', string $version = '2.11.0')
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

        if ($commandName === 'list') {
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
            return $command->handle();
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