#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

define('ROOT_PATH', dirname(__DIR__) . '/');
define('APP_PATH', __DIR__ . '/../app/');
define('VIEW_PATH', APP_PATH . 'view/');
define('SMARTY_TEMPLATE_PATH', APP_PATH . 'view/templates/');
define('STORAGE_PATH', __DIR__ . '/../storage/');
define('PUBLIC_PATH', __DIR__ . '/../public/');
define('VENDOR_PATH', __DIR__ . '/../vendor/');

require APP_PATH . 'core/Loader.php';
require APP_PATH . 'core/helpers.php';
\core\Loader::register();

use core\Generator;

if (!isset($argv[1])) {
    echo "LightPHP Code Generator\n";
    echo "=======================\n\n";
    echo "Usage:\n";
    echo "  php bin/make.php make:model <table_name> [ModelName]\n";
    echo "  php bin/make.php make:controller <table_name> [ControllerName]\n";
    echo "  php bin/make.php make:all <table_name>\n";
    echo "  php bin/make.php list:tables\n";
    echo "  php bin/make.php routes <table_name>\n\n";
    echo "Examples:\n";
    echo "  php bin/make.php make:model users\n";
    echo "  php bin/make.php make:model users User\n";
    echo "  php bin/make.php make:controller products\n";
    echo "  php bin/make.php make:all orders\n";
    echo "  php bin/make.php list:tables\n";
    exit(1);
}

$command = $argv[1];
$generator = new Generator();

$dbConfig = [];
$dbConfigFile = APP_PATH . 'config/database.php';
if (file_exists($dbConfigFile)) {
    $dbConfig = require $dbConfigFile;
}
$generator->setDbConfig($dbConfig);

switch ($command) {
    case 'make:model':
        if (!isset($argv[2])) {
            echo "Error: Table name is required\n";
            echo "Usage: php bin/make.php make:model <table_name> [ModelName]\n";
            exit(1);
        }
        $table = $argv[2];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            echo "Error: Invalid table name: {$table}\n";
            exit(1);
        }
        $modelName = $argv[3] ?? null;

        echo "Generating Model for table '{$table}'...\n";
        $content = $generator->generateModel($table, $modelName);

        $name = $modelName ?: $generator->tableToModelName($table);
        $path = $generator->saveModel($table, $modelName);

        echo "\nModel generated successfully!\n";
        echo "File: {$path}\n\n";
        echo "Content preview:\n";
        echo str_repeat('-', 50) . "\n";
        echo $content . "\n";
        break;

    case 'make:controller':
        if (!isset($argv[2])) {
            echo "Error: Table name is required\n";
            echo "Usage: php bin/make.php make:controller <table_name> [ControllerName]\n";
            exit(1);
        }
        $table = $argv[2];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            echo "Error: Invalid table name: {$table}\n";
            exit(1);
        }
        $controllerName = $argv[3] ?? null;

        echo "Generating Controller for table '{$table}'...\n";
        $content = $generator->generateController($table, $controllerName);

        $name = $controllerName ?: $generator->tableToControllerName($table);
        $path = $generator->saveController($table, $controllerName);

        echo "\nController generated successfully!\n";
        echo "File: {$path}\n\n";
        echo "Content preview:\n";
        echo str_repeat('-', 50) . "\n";
        echo $content . "\n";
        break;

    case 'make:all':
        if (!isset($argv[2])) {
            echo "Error: Table name is required\n";
            echo "Usage: php bin/make.php make:all <table_name>\n";
            exit(1);
        }
        $table = $argv[2];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            echo "Error: Invalid table name: {$table}\n";
            exit(1);
        }

        echo "Generating Model and Controller for table '{$table}'...\n\n";
        $result = $generator->generateAll($table);

        echo "Model: {$result['model']['name']}\n";
        echo "Path: {$result['model']['path']}\n\n";
        echo "Controller: {$result['controller']['name']}\n";
        echo "Path: {$result['controller']['path']}\n\n";

        echo "Suggested routes:\n";
        echo str_repeat('-', 50) . "\n";
        echo $generator->generateResourceRoutes($table) . "\n";
        break;

    case 'list:tables':
        echo "Tables in database:\n";
        echo str_repeat('-', 50) . "\n";
        $tables = $generator->getTables();
        foreach ($tables as $table) {
            $modelName = $generator->tableToModelName($table);
            $controllerName = $generator->tableToControllerName($table);
            echo "  {$table}\n";
            echo "    -> Model: {$modelName}\n";
            echo "    -> Controller: {$controllerName}\n";
            echo "\n";
        }
        break;

    case 'routes':
        if (!isset($argv[2])) {
            echo "Error: Table name is required\n";
            echo "Usage: php bin/make.php routes <table_name>\n";
            exit(1);
        }
        $table = $argv[2];
        echo "Routes for '{$table}':\n";
        echo str_repeat('-', 50) . "\n";
        echo $generator->generateResourceRoutes($table) . "\n";
        break;

    default:
        echo "Unknown command: {$command}\n";
        echo "Available commands:\n";
        echo "  make:model <table> [name]    - Generate a model\n";
        echo "  make:controller <table> [name] - Generate a controller\n";
        echo "  make:all <table>             - Generate both model and controller\n";
        echo "  list:tables                  - List all database tables\n";
        echo "  routes <table>               - Generate routes for a table\n";
        exit(1);
}
