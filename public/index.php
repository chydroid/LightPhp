<?php
declare(strict_types=1);

if (defined('APP_PATH')) return;

define('ROOT_PATH', dirname(__DIR__) . '/');
define('APP_PATH', __DIR__ . '/../app/');
define('PUBLIC_PATH', __DIR__);
define('STORAGE_PATH', __DIR__ . '/../storage/');
define('VIEW_PATH', APP_PATH . 'view/');
define('SMARTY_TEMPLATE_PATH', APP_PATH . 'view/templates/');
define('VENDOR_PATH', __DIR__ . '/../vendor/');

require APP_PATH . 'core/Loader.php';
require APP_PATH . 'core/helpers.php';
\core\Loader::register();

// Load app config for APP_DEBUG before Application construction
$appConfig = [];
if (file_exists(APP_PATH . 'config/app.php')) {
    $appConfig = require APP_PATH . 'config/app.php';
}
define('APP_DEBUG', $appConfig['debug'] ?? false);

try {
    $app = new \core\Application();
    $app->run();
} catch (\Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        throw $e;
    }
    http_response_code(500);
    echo 'Internal Server Error';
    error_log('LightPHP: Uncaught exception - ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}