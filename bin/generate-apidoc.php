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

use core\ApiDoc;

echo "LightPHP API Documentation Generator\n";
echo "======================================\n\n";

$doc = new ApiDoc();

$format = $argv[1] ?? 'markdown';

if (!in_array($format, ['json', 'markdown'], true)) {
    echo "Unsupported format: {$format}. Use 'json' or 'markdown'.\n";
    exit(1);
}

switch ($format) {
    case 'json':
        $output = $doc->toJson();
        $filename = __DIR__ . '/../docs/api.json';
        break;
    case 'markdown':
    default:
        $output = $doc->toMarkdown();
        $filename = __DIR__ . '/../docs/api.md';
        break;
}

$dir = dirname($filename);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$result = file_put_contents($filename, $output);
if ($result === false) {
    echo "Error: Failed to write documentation to {$filename}\n";
    exit(1);
}

echo "API documentation generated: {$filename}\n";
