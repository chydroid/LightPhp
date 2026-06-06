<?php
declare(strict_types=1);

use core\Router;
use controller\IndexController;
use controller\UserController;

$router = new Router();

$router->get('/', [IndexController::class, 'index']);
$router->get('/about', [IndexController::class, 'about']);
$router->get('/contact', [IndexController::class, 'contact']);

$router->group(['prefix' => '/api'], function($router) {
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->post('/users', [UserController::class, 'store']);
    $router->put('/users/{id}', [UserController::class, 'update']);
    $router->delete('/users/{id}', [UserController::class, 'destroy']);
});

return $router;
