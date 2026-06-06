#!/usr/bin/env php
<?php
declare(strict_types=1);

define('APP_PATH', __DIR__ . '/../app/');
define('STORAGE_PATH', __DIR__ . '/../storage/');
define('VENDOR_PATH', __DIR__ . '/../vendor/');

require APP_PATH . 'core/Loader.php';
require APP_PATH . 'core/helpers.php';
\core\Loader::register();

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

use core\ApiDoc;

echo "LightPHP API Documentation Generator\n";
echo "======================================\n\n";

$doc = new ApiDoc();

$format = $argv[1] ?? 'markdown';

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

file_put_contents($filename, $output);

echo "API documentation generated: {$filename}\n";
echo "\n--- Preview ---\n\n";
echo $output;
