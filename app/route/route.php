<?php

use core\Router;
use core\Response;

$router = new Router();

$router->get('/', function() {
    return (new Response())->content('<h1>Welcome to LightPHP</h1><p>A lightweight PHP framework</p>');
});

$router->get('/hello/{name}', function($name) {
    return (new Response())->content('<h1>Hello, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '!</h1>');
});

$router->group(['prefix' => '/api', 'middleware' => ['cors']], function($router) {
    $router->get('/users', [\controller\UserController::class, 'index']);
    $router->get('/users/{id}', [\controller\UserController::class, 'show']);
    $router->post('/users', [\controller\UserController::class, 'store']);
    $router->put('/users/{id}', [\controller\UserController::class, 'update']);
    $router->delete('/users/{id}', [\controller\UserController::class, 'destroy']);
});

$router->get('/smarty/users', [\controller\SmartyUserController::class, 'index']);

return $router;
