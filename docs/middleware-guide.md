# LightPHP 中间件指南

本文档详细介绍中间件的创建、注册和使用。

---

## 目录

1. [中间件原理](#1-中间件原理)
2. [创建中间件](#2-创建中间件)
3. [注册中间件](#3-注册中间件)
4. [路由级中间件](#4-路由级中间件)
5. [中间件组](#5-中间件组)
6. [内置中间件](#6-内置中间件)
7. [自定义中间件示例](#7-自定义中间件示例)

---

## 1. 中间件原理

LightPHP 中间件采用**洋葱模型**（Onion Model）：请求穿过中间件链到达控制器，响应再反向穿过中间件链返回。每个中间件可以在请求前和响应后执行逻辑。

```
请求 → [Middleware1] → [Middleware2] → Controller → [Middleware2] → [Middleware1] → 响应
```

---

## 2. 创建中间件

中间件是包含 `handle(Request $request, callable $next): Response` 方法的类：

```php
<?php
declare(strict_types=1);

namespace middleware;

use core\Request;
use core\Response;

class LogRequest
{
    public function handle(Request $request, callable $next): Response
    {
        // 请求前逻辑
        $startTime = microtime(true);

        // 调用下一个中间件或控制器
        $response = $next($request);

        // 响应后逻辑
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        error_log("{$request->method()} {$request->uri()} - {$response->getStatusCode()} ({$duration}ms)");

        return $response;
    }
}
```

---

## 3. 注册中间件

### 3.1 别名注册

为中间件起短名，方便在路由中引用：

```php
$router = new \core\Router();
$router->aliasMiddleware('auth', \middleware\AuthMiddleware::class);
$router->aliasMiddleware('throttle', \middleware\Throttle::class);
```

### 3.2 全局中间件

全局中间件对所有请求生效：

```php
$router->setGlobalMiddleware([
    \middleware\Cors::class,
    \middleware\MaintenanceCheck::class,
]);
```

---

## 4. 路由级中间件

### 单个中间件

```php
$router->get('/profile', [ProfileController::class, 'show'])
    ->middleware('auth');
```

### 多个中间件

```php
$router->put('/posts/{id}', [PostController::class, 'update'])
    ->middleware(['auth', 'throttle:60,1']);
```

### 分组中间件

```php
$router->group(['prefix' => '/admin', 'middleware' => ['auth', 'admin']], function($router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
});
```

### 带参数的中间件

中间件参数通过冒号分隔传递：

```php
// throttle:60,1 表示 "每 60 秒最多 1 次"
$router->post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
```

在中间件中接收参数（通过构造函数或 handle 方法参数）。

---

## 5. 中间件组

将多个中间件打包为组，便于复用：

```php
$router->middlewareGroup('web', [
    \middleware\SessionStart::class,
    \middleware\CsrfMiddleware::class,
    \middleware\ShareErrors::class,
]);

$router->middlewareGroup('api', [
    \middleware\Cors::class,
    \middleware\Throttle::class,
]);
```

在路由中使用组名：

```php
$router->group(['middleware' => ['web']], function($router) {
    // Web 路由
});

$router->group(['prefix' => '/api', 'middleware' => ['api']], function($router) {
    // API 路由
});
```

---

## 6. 内置中间件

| 中间件 | 说明 | 用法 |
|--------|------|------|
| `CsrfMiddleware` | CSRF 令牌验证 | POST/PUT/DELETE 请求 |
| `Throttle` | 请求限流 | `throttle:次数,秒数` |
| `Cors` | 跨域资源共享 | API 接口 |
| `OutputCache` | 页面输出缓存 | `output_cache:ttl` |
| `SessionStart` | 启动会话 | Web 路由 |

---

## 7. 自定义中间件示例

### 7.1 认证中间件

```php
<?php
declare(strict_types=1);

namespace middleware;

use core\Request;
use core\Response;

class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null || !$this->validateToken($token)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }

    private function validateToken(string $token): bool
    {
        // 验证逻辑...
        return true;
    }
}
```

### 7.2 角色权限中间件

```php
<?php
declare(strict_types=1);

namespace middleware;

use core\Request;
use core\Response;

class RoleMiddleware
{
    public function handle(Request $request, callable $next, string $role = ''): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null || $user['role'] !== $role) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        return $next($request);
    }
}

// 用法：role:admin
```

### 7.3 JSON 响应格式化中间件

```php
<?php
declare(strict_types=1);

namespace middleware;

use core\Request;
use core\Response;

class JsonMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }
}
```
