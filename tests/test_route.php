<?php
use core\Router;

$router = new Router();
$router->get('/loaded-route', function() { return 'loaded'; });
return $router;
