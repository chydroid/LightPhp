<?php
declare(strict_types=1);

define('APP_PATH', __DIR__ . '/../app/');
define('PUBLIC_PATH', __DIR__);
define('STORAGE_PATH', __DIR__ . '/../storage/');
define('VIEW_PATH', APP_PATH . 'view/');
define('SMARTY_TEMPLATE_PATH', APP_PATH . 'view/templates/');
define('VENDOR_PATH', __DIR__ . '/../vendor/');

require APP_PATH . 'core/Loader.php';
require APP_PATH . 'core/helpers.php';

\core\Loader::register();

$app = new \core\Application();

define('APP_DEBUG', $app->getConfig('app.debug', false));

$app->run();