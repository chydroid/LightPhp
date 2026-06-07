# LightPHP 电商系统 - 后台管理开发教程

> ⚠️ **模型访问模式说明**：本教程中 `Model::find($id)`、`Model::findBy(...)` 等方法返回 **模型实例**（支持 `__get`），请使用 `$model->id` 而非 `$model['id']`；而 `Model::where(...)->fetchAll()` / `->fetch()` / `->first()` 返回的是 **关联数组**，可使用 `$row['id']`。当需要把模型实例转为数组时，调用 `->toArray()`。

## 目录

1. [后台功能概述](#1-后台功能概述)
2. [管理员认证](#2-管理员认证)
3. [后端开发-管理员模块](#3-后端开发-管理员模块)
4. [后端开发-商品管理API](#4-后端开发-商品管理api)
5. [后端开发-分类管理API](#5-后端开发-分类管理api)
6. [后端开发-订单管理API](#6-后端开发-订单管理api)
7. [前端开发-管理后台结构](#7-前端开发-管理后台结构)
8. [前端开发-管理员页面](#8-前端开发-管理员页面)
9. [前端开发-商品管理页面](#9-前端开发-商品管理页面)
10. [前端开发-分类管理页面](#10-前端开发-分类管理页面)
11. [前端开发-订单管理页面](#11-前端开发-订单管理页面)
12. [测试与运行](#12-测试与运行)

---

## 1. 后台功能概述

> 💡 **新手提示**：本章将带你从零开始构建一个电商后台管理系统。你将学会如何创建管理员登录认证、商品管理、分类管理、订单管理等核心功能。每个步骤都配有完整的代码示例，可以直接复制使用。

### 1.1 后台功能模块

| 模块 | 功能 |
|------|------|
| 管理员认证 | 管理员登录、退出、权限验证 |
| 仪表盘 | 今日数据统计、待处理订单、库存预警 |
| 商品管理 | 商品增删改查、上架/下架、库存管理 |
| 分类管理 | 分类增删改查、排序 |
| 订单管理 | 订单列表、订单详情、发货/取消 |
| 用户管理 | 用户列表、启用/禁用 |

### 1.2 系统架构

```
┌─────────────────┐     ┌─────────────────┐
│   用户端 Vue    │     │   管理后台 Vue   │
│   (8081)       │     │   (8082)        │
└────────┬────────┘     └────────┬────────┘
         │                       │
         │     ┌─────────────────┤
         │     │                 │
         ▼     ▼                 ▼
┌─────────────────────────────────────┐
│          LightPHP 后端 API           │
│  /api/auth/*  - 用户认证             │
│  /api/admin/* - 管理后台 API         │
│  /api/*       - 用户端 API           │
└─────────────────────────────────────┘
```

---

## 2. 管理员认证

### 2.1 创建管理员表

> **提示**：可使用框架内置的 Schema Builder 替代手写 SQL，参见 [Schema Builder 文档](#)。

```sql
CREATE TABLE `admins` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '管理员用户名',
    `password` VARCHAR(255) NOT NULL COMMENT '密码（加密）',
    `nickname` VARCHAR(50) DEFAULT NULL COMMENT '昵称',
    `role` VARCHAR(20) DEFAULT 'admin' COMMENT '角色：admin超级管理员 operator运营',
    `status` TINYINT DEFAULT 1 COMMENT '状态：0禁用 1启用',
    `last_login_at` DATETIME DEFAULT NULL COMMENT '最后登录时间',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- 插入超级管理员
INSERT INTO `admins` (`username`, `password`, `nickname`, `role`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '超级管理员', 'admin');
-- 密码: password
```

### 2.2 创建管理员认证中间件 app/middleware/AdminAuth.php

```php
<?php
declare(strict_types=1);

namespace middleware;

class AdminAuth
{
    protected array $except = [
        '/api/admin/auth/login',
    ];

    public function handle(\core\Request $request, callable $next): mixed
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);

        foreach ($this->except as $exceptPath) {
            if ($path === $exceptPath || str_starts_with($path, $exceptPath)) {
                return $next($request);
            }
        }

        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        if (empty($token)) {
            return \core\Response::json([
                'code' => 401,
                'message' => 'Unauthorized',
                'data' => [],
            ], 401);
        }

        $raw = base64_decode($token, true);
        if ($raw === false) {
            return \core\Response::json([
                'code' => 401,
                'message' => 'Unauthorized',
                'data' => [],
            ], 401);
        }

        $parts = explode('.', $raw);
        if (count($parts) !== 2) {
            return \core\Response::json([
                'code' => 401,
                'message' => 'Unauthorized',
                'data' => [],
            ], 401);
        }

        [$payloadJson, $signature] = $parts;

        if (!\core\Hash::verify($payloadJson, $signature)) {
            return \core\Response::json([
                'code' => 401,
                'message' => 'Unauthorized',
                'data' => [],
            ], 401);
        }

        $payload = json_decode($payloadJson, true);

        if (!$payload || !isset($payload['admin_id']) || $payload['exp'] < time()) {
            return \core\Response::json([
                'code' => 401,
                'message' => 'Unauthorized',
                'data' => [],
            ], 401);
        }

        $_REQUEST['admin_id'] = $payload['admin_id'];
        $_REQUEST['admin_role'] = $payload['role'] ?? 'admin';

        return $next($request);
    }
}
```

> 💡 **v2.0 中间件别名**：v2.0 新增了中间件别名注册功能，可以在路由中使用短名称代替完整类名：
> ```php
> $router->aliasMiddleware('admin.auth', \middleware\AdminAuth::class);
>
> // 使用别名
> $router->group(['middleware' => ['admin.auth']], function($router) {
>     // ...
> });
> ```

---

## 3. 后端开发-管理员模块

### 3.1 创建管理员模型 app/model/Admin.php

```php
<?php
declare(strict_types=1);

namespace model;

class Admin extends Model
{
    protected string $table = 'admins';
    protected array $fillable = ['username', 'password', 'nickname', 'role', 'status', 'last_login_at'];
    protected array $casts = [
        'status' => 'int',
    ];
    protected array $hidden = ['password'];

    // v2.0 提示：可使用修改器自动哈希密码
    // public function setPasswordAttribute(string $value): void
    // {
    //     $this->attributes['password'] = \core\Hash::make($value);
    // }

    public function verifyPassword(string $password): bool
    {
        return \core\Hash::verify($password, $this->attributes['password']);
    }

    public static function updateLoginTime(int $id): void
    {
        self::update($id, ['last_login_at' => date('Y-m-d H:i:s')]);
    }
}
```

### 3.2 创建管理员控制器 app/controller/Api/Admin/AuthController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api\Admin;

use core\Controller;
use core\Request;
use core\Response;
use model\Admin;

class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        $username = $request->input('username');
        $password = $request->input('password');

        if (empty($username) || empty($password)) {
            return $this->error('Username and password are required', 422);
        }

        $admin = Admin::where('username', '=', $username)->first();

        if (!$admin || !$admin->verifyPassword($password)) {
            return $this->error('Invalid credentials', 401);
        }

        if ($admin->status !== 1) {
            return $this->error('Account is disabled', 403);
        }

        Admin::updateLoginTime($admin['id']);

        $token = $this->generateToken($admin['id'], $admin['role']);

        return $this->success([
            'admin' => [
                'id' => $admin['id'],
                'username' => $admin['username'],
                'nickname' => $admin['nickname'],
                'role' => $admin['role'],
            ],
            'token' => $token,
        ], 'Login successful');
    }

    public function logout(Request $request): Response
    {
        return $this->success([], 'Logout successful');
    }

    public function profile(Request $request): Response
    {
        $adminId = $request->admin_id;

        $admin = Admin::find($adminId);

        if (!$admin) {
            return $this->error('Admin not found', 404);
        }

        return $this->success([
            'id' => $admin['id'],
            'username' => $admin['username'],
            'nickname' => $admin['nickname'],
            'role' => $admin['role'],
            'last_login_at' => $admin['last_login_at'],
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $adminId = $request->admin_id;
        $data = $request->only(['nickname']);

        if (!empty($data['nickname'])) {
            Admin::update($adminId, $data);
        }

        return $this->success([], 'Profile updated');
    }

    public function changePassword(Request $request): Response
    {
        $adminId = $request->admin_id;
        $oldPassword = $request->input('old_password');
        $newPassword = $request->input('new_password');

        if (empty($oldPassword) || empty($newPassword)) {
            return $this->error('Password is required', 422);
        }

        if (strlen($newPassword) < 6) {
            return $this->error('Password must be at least 6 characters', 422);
        }

        $admin = Admin::find($adminId);

        if (!$admin->verifyPassword($oldPassword)) {
            return $this->error('Old password is incorrect', 422);
        }

        Admin::update($adminId, ['password' => \core\Hash::make($newPassword)]);

        return $this->success([], 'Password changed');
    }

    private function generateToken(int $adminId, string $role): string
    {
        $payload = [
            'admin_id' => $adminId,
            'role' => $role,
            'exp' => time() + 86400 * 7,
            'rand' => bin2hex(random_bytes(16)),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = \core\Hash::make($payloadJson);
        return base64_encode($payloadJson . '.' . $signature);
    }
}
```

### 3.3 创建仪表盘控制器 app/controller/Api/Admin/DashboardController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api\Admin;

use core\Controller;
use core\Request;
use core\Response;
use model\Order;
use model\Product;
use model\User;
use model\OrderItem;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $today = date('Y-m-d');

        $db = app(\db\Connection::class);

        $todayOrders = $db->query(
            "SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = ?",
            [$today]
        );

        $todaySales = $db->query(
            "SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(created_at) = ? AND status = 2",
            [$today]
        );

        $pendingOrders = $db->query(
            "SELECT COUNT(*) as count FROM orders WHERE status = 1"
        );

        $totalUsers = $db->query(
            "SELECT COUNT(*) as count FROM users"
        );

        $totalProducts = $db->query(
            "SELECT COUNT(*) as count FROM products"
        );

        $lowStock = $db->query(
            "SELECT COUNT(*) as count FROM products WHERE stock < 10 AND status = 1"
        );

        $salesTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $daySales = $db->query(
                "SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(created_at) = ? AND status = 2",
                [$date]
            );
            $salesTrend[] = [
                'date' => $date,
                'amount' => (float) ($daySales[0]['total'] ?? 0),
            ];
        }

        $orderTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dayOrders = $db->query(
                "SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = ?",
                [$date]
            );
            $orderTrend[] = [
                'date' => $date,
                'count' => (int) ($dayOrders[0]['count'] ?? 0),
            ];
        }

        $topProducts = $db->query(
            "SELECT oi.product_id, oi.product_name, SUM(oi.quantity) as total_quantity, SUM(oi.subtotal) as total_amount
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE o.status = 2
             GROUP BY oi.product_id, oi.product_name
             ORDER BY total_quantity DESC
             LIMIT 10"
        );

        return $this->success([
            'today_orders' => (int) ($todayOrders[0]['count'] ?? 0),
            'today_sales' => (float) ($todaySales[0]['total'] ?? 0),
            'pending_orders' => (int) ($pendingOrders[0]['count'] ?? 0),
            'total_users' => (int) ($totalUsers[0]['count'] ?? 0),
            'total_products' => (int) ($totalProducts[0]['count'] ?? 0),
            'low_stock' => (int) ($lowStock[0]['count'] ?? 0),
            'sales_trend' => $salesTrend,
            'order_trend' => $orderTrend,
            'top_products' => $topProducts,
        ]);
     }
 }
```

---

## 4. 后端开发-商品管理API

### 4.1 创建商品管理控制器 app/controller/Api/Admin/ProductController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api\Admin;

use core\Controller;
use core\Request;
use core\Response;
use model\Product;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        $keyword = $request->input('keyword');
        $categoryId = $request->input('category_id');
        $status = $request->input('status');

        $db = app(\db\Connection::class);
        $query = $db->table('products');

        if ($keyword) {
            $query->where('name', 'LIKE', "%{$keyword}%");
        }

        if ($categoryId) {
            $query->where('category_id', '=', $categoryId);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', '=', $status);
        }

        $result = $query->orderBy('id', 'DESC')->paginate($pageSize, $page);

        return $this->success($result);
    }

    public function store(Request $request): Response
    {
        $data = $request->only([
            'name', 'slug', 'category_id', 'description',
            'price', 'stock', 'images', 'status'
        ]);

        if (empty($data['name']) || empty($data['price'])) {
            return $this->error('Name and price are required', 422);
        }

        if (empty($data['slug'])) {
            $data['slug'] = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($data['name']));
        }

        $data['status'] = $data['status'] ?? 1;

        $id = Product::create($data);

        return $this->success(['id' => $id], 'Product created', 201);
    }

    public function show(int $id): Response
    {
        $product = Product::find($id);

        if (!$product) {
            return $this->error('Product not found', 404);
        }

        return $this->success($product);
    }

    public function update(int $id, Request $request): Response
    {
        $product = Product::find($id);

        if (!$product) {
            return $this->error('Product not found', 404);
        }

        $data = $request->only([
            'name', 'slug', 'category_id', 'description',
            'price', 'stock', 'images', 'status'
        ]);

        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($data['name']));
        }

        Product::update($id, $data);

        return $this->success([], 'Product updated');
    }

    public function destroy(int $id): Response
    {
        $product = Product::find($id);

        if (!$product) {
            return $this->error('Product not found', 404);
        }

        Product::deleteById($id);

        return $this->success([], 'Product deleted');
    }

    public function batchDelete(Request $request): Response
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return $this->error('IDs are required', 422);
        }

        $db = app(\db\Connection::class);
        $db->table('products')->whereIn('id', $ids)->delete();

        return $this->success([], 'Products deleted');
    }

    public function batchUpdateStatus(Request $request): Response
    {
        $ids = $request->input('ids', []);
        $status = (int) $request->input('status', 1);

        if (empty($ids)) {
            return $this->error('IDs are required', 422);
        }

        $db = app(\db\Connection::class);
        $db->table('products')->whereIn('id', $ids)->update(['status' => $status]);

        return $this->success([], 'Status updated');
    }
}
```

---

## 5. 后端开发-分类管理API

### 5.1 创建分类管理控制器 app/controller/Api/Admin/CategoryController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api\Admin;

use core\Controller;
use core\Request;
use core\Response;
use model\Category;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $categories = Category::orderBy('sort', 'ASC')->fetchAll();

        $tree = $this->buildTree($categories);

        return $this->success($tree);
    }

    public function all(): Response
    {
        $categories = Category::orderBy('sort', 'ASC')->fetchAll();

        return $this->success($categories);
    }

    public function store(Request $request): Response
    {
        $data = $request->only(['name', 'slug', 'parent_id', 'sort']);

        if (empty($data['name'])) {
            return $this->error('Name is required', 422);
        }

        if (empty($data['slug'])) {
            $data['slug'] = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($data['name']));
        }

        $data['sort'] = $data['sort'] ?? 0;
        $data['parent_id'] = $data['parent_id'] ?? 0;

        $id = Category::create($data);

        return $this->success(['id' => $id], 'Category created', 201);
    }

    public function update(int $id, Request $request): Response
    {
        $category = Category::find($id);

        if (!$category) {
            return $this->error('Category not found', 404);
        }

        $data = $request->only(['name', 'slug', 'parent_id', 'sort']);

        if (!empty($data['name']) && empty($data['slug'])) {
            $data['slug'] = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($data['name']));
        }

        Category::update($id, $data);

        return $this->success([], 'Category updated');
    }

    public function destroy(int $id): Response
    {
        $category = Category::find($id);

        if (!$category) {
            return $this->error('Category not found', 404);
        }

        // 检查是否有子分类
        $children = Category::where('parent_id', '=', $id)->fetchAll();
        if (!empty($children)) {
            return $this->error('Cannot delete category with children', 422);
        }

        // 检查是否有商品
        $db = app(\db\Connection::class);
        $count = $db->table('products')->where('category_id', '=', $id)->count();
        if ($count > 0) {
            return $this->error('Cannot delete category with products', 422);
        }

        Category::deleteById($id);

        return $this->success([], 'Category deleted');
    }

    public function updateSort(Request $request): Response
    {
        $sortData = $request->input('sort_data', []);

        if (empty($sortData)) {
            return $this->error('Sort data is required', 422);
        }

        foreach ($sortData as $item) {
            if (isset($item['id']) && isset($item['sort'])) {
                Category::update($item['id'], ['sort' => $item['sort']]);
            }
        }

        return $this->success([], 'Sort updated');
    }

    private function buildTree(array $categories, int $parentId = 0): array
    {
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = $this->buildTree($categories, $category['id']);
                $item = [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'sort' => $category['sort'],
                    'parent_id' => $category['parent_id'],
                ];
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }
}
```

---

## 6. 后端开发-订单管理API

### 6.1 创建订单管理控制器 app/controller/Api/Admin/OrderController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api\Admin;

use core\Controller;
use core\Request;
use core\Response;
use model\Order;
use model\OrderItem;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        $status = $request->input('status');
        $keyword = $request->input('keyword');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $db = app(\db\Connection::class);
        $query = $db->table('orders');

        if ($status !== null && $status !== '') {
            $query->where('status', '=', $status);
        }

        if ($keyword) {
            $query->where('order_no', 'LIKE', "%{$keyword}%");
        }

        if ($startDate) {
            $query->where('created_at', '>=', $startDate . ' 00:00:00');
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate . ' 23:59:59');
        }

        $result = $query->orderBy('id', 'DESC')->paginate($pageSize, $page);

        // 加载订单商品
        foreach ($result['items'] as &$order) {
            $items = OrderItem::where('order_id', '=', $order['id'])->fetchAll();
            $order['items'] = $items;
        }

        return $this->success($result);
    }

    public function show(int $id): Response
    {
        $order = Order::find($id);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $items = OrderItem::where('order_id', '=', $id)->fetchAll();
        $order['items'] = $items;

        return $this->success($order);
    }

    public function updateStatus(int $id, Request $request): Response
    {
        $order = Order::find($id);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $status = (int) $request->input('status');

        if (!in_array($status, [1, 2, 3, 4, 5])) {
            return $this->error('Invalid status', 422);
        }

        // 状态流转验证
        $currentStatus = $order['status'];
        $allowedTransitions = [
            1 => [2, 5],  // 待支付 -> 已支付/已取消
            2 => [3],     // 已支付 -> 已发货
            3 => [4],     // 已发货 -> 已完成
            4 => [],      // 已完成 -> 不可变更
            5 => [],      // 已取消 -> 不可变更
        ];

        if (!in_array($status, $allowedTransitions[$currentStatus] ?? [])) {
            return $this->error('Invalid status transition', 422);
        }

        Order::update($id, ['status' => $status]);

        return $this->success([], 'Status updated');
    }

    public function ship(int $id, Request $request): Response
    {
        $order = Order::find($id);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        if ($order['status'] != 2) {
            return $this->error('Only paid orders can be shipped', 422);
        }

        Order::update($id, ['status' => 3]);

        return $this->success([], 'Order shipped');
    }

    public function cancel(int $id, Request $request): Response
    {
        $order = Order::find($id);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        if (!in_array($order['status'], [1, 2])) {
            return $this->error('Order cannot be cancelled', 422);
        }

        // 如果已支付，需要恢复库存
        if ($order['status'] == 2) {
            $items = OrderItem::where('order_id', '=', $id)->fetchAll();
            $db = app(\db\Connection::class);

            foreach ($items as $item) {
                $product = $db->table('products')->where('id', '=', $item['product_id'])->fetch();
                if ($product) {
                    $db->table('products')->where('id', '=', $item['product_id'])->update([
                        'stock' => $product['stock'] + $item['quantity'],
                    ]);
                }
            }
        }

        Order::update($id, ['status' => 5]);

        return $this->success([], 'Order cancelled');
    }

    public function statistics(Request $request): Response
    {
        $startDate = $request->input('start_date', date('Y-m-01'));
        $endDate = $request->input('end_date', date('Y-m-d'));

        $db = app(\db\Connection::class);

        $orderStats = $db->query(
            "SELECT
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as shipped_orders,
                SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status = 5 THEN 1 ELSE 0 END) as cancelled_orders
             FROM orders
             WHERE created_at >= ? AND created_at <= ?",
            ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]
        );

        $salesStats = $db->query(
            "SELECT
                COUNT(*) as total_items,
                SUM(subtotal) as total_amount,
                AVG(subtotal) as avg_amount
             FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE o.status = 2 AND o.created_at >= ? AND o.created_at <= ?",
            ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]
        );

        return $this->success([
            'orders' => [
                'total' => (int) ($orderStats[0]['total_orders'] ?? 0),
                'pending' => (int) ($orderStats[0]['pending_orders'] ?? 0),
                'paid' => (int) ($orderStats[0]['paid_orders'] ?? 0),
                'shipped' => (int) ($orderStats[0]['shipped_orders'] ?? 0),
                'completed' => (int) ($orderStats[0]['completed_orders'] ?? 0),
                'cancelled' => (int) ($orderStats[0]['cancelled_orders'] ?? 0),
            ],
            'sales' => [
                'total_items' => (int) ($salesStats[0]['total_items'] ?? 0),
                'total_amount' => (float) ($salesStats[0]['total_amount'] ?? 0),
                'avg_amount' => (float) ($salesStats[0]['avg_amount'] ?? 0),
            ],
        ]);
    }
}
```

---

## 7. 前端开发-管理后台结构

### 7.1 创建管理后台项目

```bash
# 创建管理后台项目目录
mkdir -p shop-admin-panel
cd shop-admin-panel

# 初始化
npm init -y

# 安装依赖
npm install vue@3 axios vue-router@4
npm install -D vite @vitejs/plugin-vue
```

### 7.2 项目目录结构

```
shop-admin-panel/
├── public/
│   └── index.html
├── src/
│   ├── api/              # API 请求
│   │   ├── index.js
│   │   ├── admin.js      # 管理员接口
│   │   ├── dashboard.js   # 仪表盘接口
│   │   ├── product.js     # 商品接口
│   │   ├── category.js    # 分类接口
│   │   └── order.js       # 订单接口
│   ├── components/        # 公共组件
│   │   ├── Sidebar.vue
│   │   ├── Header.vue
│   │   ├── Pagination.vue
│   │   └── Modal.vue
│   ├── views/            # 页面
│   │   ├── Login.vue
│   │   ├── Dashboard.vue
│   │   ├── product/
│   │   │   ├── List.vue
│   │   │   ├── Create.vue
│   │   │   └── Edit.vue
│   │   ├── category/
│   │   │   └── List.vue
│   │   ├── order/
│   │   │   ├── List.vue
│   │   │   └── Detail.vue
│   │   └── user/
│   │       └── List.vue
│   ├── router/
│   │   └── index.js
│   ├── utils/
│   │   └── request.js
│   ├── App.vue
│   └── main.js
├── package.json
└── vite.config.js
```

### 7.3 API 请求封装 src/utils/request.js

```javascript
import axios from 'axios'
import router from '../router'

const request = axios.create({
  baseURL: '/api/admin',
  timeout: 10000
})

request.interceptors.request.use(config => {
  const token = localStorage.getItem('admin_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

request.interceptors.response.use(
  response => response.data,
  error => {
    if (error.response?.status === 401) {
      localStorage.removeItem('admin_token')
      localStorage.removeItem('admin_user')
      router.push('/login')
    }
    return Promise.reject(error)
  }
)

export default request
```

---

## 8. 前端开发-管理员页面

### 8.1 管理员登录 src/views/Login.vue

```vue
<template>
  <div class="login-page">
    <div class="login-box">
      <h1>后台管理登录</h1>
      <form @submit.prevent="handleLogin">
        <div class="form-item">
          <label>用户名</label>
          <input v-model="form.username" type="text" placeholder="请输入管理员用户名" required />
        </div>
        <div class="form-item">
          <label>密码</label>
          <input v-model="form.password" type="password" placeholder="请输入密码" required />
        </div>
        <div class="error" v-if="error">{{ error }}</div>
        <button type="submit" :disabled="loading">
          {{ loading ? '登录中...' : '登录' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import request from '../utils/request'

const router = useRouter()

const form = reactive({
  username: '',
  password: ''
})

const loading = ref(false)
const error = ref('')

const handleLogin = async () => {
  error.value = ''
  loading.value = true

  try {
    const res = await request.post('/auth/login', form)
    localStorage.setItem('admin_token', res.data.token)
    localStorage.setItem('admin_user', JSON.stringify(res.data.admin))
    router.push('/')
  } catch (e) {
    error.value = e.response?.data?.message || '登录失败'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.login-box {
  background: white;
  padding: 40px;
  border-radius: 10px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.2);
  width: 400px;
}

.login-box h1 {
  text-align: center;
  margin-bottom: 30px;
  color: #333;
}

.form-item {
  margin-bottom: 20px;
}

.form-item label {
  display: block;
  margin-bottom: 5px;
  font-weight: bold;
  color: #555;
}

.form-item input {
  width: 100%;
  padding: 12px;
  border: 1px solid #ddd;
  border-radius: 5px;
  font-size: 14px;
}

button {
  width: 100%;
  padding: 12px;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 5px;
  font-size: 16px;
  cursor: pointer;
}

button:disabled {
  background: #ccc;
}

.error {
  color: #e74c3c;
  margin-bottom: 15px;
  text-align: center;
}
</style>
```

### 8.2 仪表盘 src/views/Dashboard.vue

```vue
<template>
  <div class="dashboard">
    <h1>仪表盘</h1>

    <div class="stats-cards">
      <div class="stat-card">
        <div class="stat-icon orders"></div>
        <div class="stat-info">
          <p class="stat-label">今日订单</p>
          <p class="stat-value">{{ stats.today_orders }}</p>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon sales"></div>
        <div class="stat-info">
          <p class="stat-label">今日销售额</p>
          <p class="stat-value">¥{{ stats.today_sales.toFixed(2) }}</p>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon pending"></div>
        <div class="stat-info">
          <p class="stat-label">待处理订单</p>
          <p class="stat-value">{{ stats.pending_orders }}</p>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon users"></div>
        <div class="stat-info">
          <p class="stat-label">用户总数</p>
          <p class="stat-value">{{ stats.total_users }}</p>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon products"></div>
        <div class="stat-info">
          <p class="stat-label">商品总数</p>
          <p class="stat-value">{{ stats.total_products }}</p>
        </div>
      </div>

      <div class="stat-card warning">
        <div class="stat-icon stock"></div>
        <div class="stat-info">
          <p class="stat-label">库存预警</p>
          <p class="stat-value">{{ stats.low_stock }}</p>
        </div>
      </div>
    </div>

    <div class="charts-row">
      <div class="chart-card">
        <h3>销售趋势（近7天）</h3>
        <div class="chart">
          <div v-for="item in stats.sales_trend" :key="item.date" class="bar">
            <div class="bar-fill" :style="{ height: getBarHeight(item.amount) + '%' }"></div>
            <span class="bar-label">{{ formatDate(item.date) }}</span>
          </div>
        </div>
      </div>

      <div class="chart-card">
        <h3>热销商品 TOP 5</h3>
        <table class="top-products">
          <thead>
            <tr>
              <th>商品</th>
              <th>销量</th>
              <th>销售额</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in stats.top_products" :key="item.product_id">
              <td>{{ item.product_name }}</td>
              <td>{{ item.total_quantity }}</td>
              <td>¥{{ item.total_amount }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import request from '../utils/request'

const stats = reactive({
  today_orders: 0,
  today_sales: 0,
  pending_orders: 0,
  total_users: 0,
  total_products: 0,
  low_stock: 0,
  sales_trend: [],
  order_trend: [],
  top_products: []
})

const maxSales = ref(0)

const loadDashboard = async () => {
  try {
    const res = await request.get('/dashboard')
    Object.assign(stats, res.data)
    maxSales.value = Math.max(...stats.sales_trend.map(s => s.amount), 1)
  } catch (e) {
    console.error('Failed to load dashboard:', e)
  }
}

const getBarHeight = (amount) => {
  return (amount / maxSales.value) * 100
}

const formatDate = (date) => {
  return date.slice(5)
}

onMounted(() => {
  loadDashboard()
})
</script>

<style scoped>
.dashboard h1 {
  margin-bottom: 20px;
}

.stats-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.stat-card {
  background: white;
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
  display: flex;
  align-items: center;
  gap: 15px;
}

.stat-card.warning {
  border-left: 4px solid #e74c3c;
}

.stat-icon {
  width: 50px;
  height: 50px;
  border-radius: 10px;
}

.stat-icon.orders { background: #3498db; }
.stat-icon.sales { background: #2ecc71; }
.stat-icon.pending { background: #f39c12; }
.stat-icon.users { background: #9b59b6; }
.stat-icon.products { background: #1abc9c; }
.stat-icon.stock { background: #e74c3c; }

.stat-label {
  color: #7f8c8d;
  font-size: 14px;
  margin-bottom: 5px;
}

.stat-value {
  font-size: 24px;
  font-weight: bold;
  color: #2c3e50;
}

.charts-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

.chart-card {
  background: white;
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.chart-card h3 {
  margin-bottom: 20px;
  color: #2c3e50;
}

.chart {
  display: flex;
  justify-content: space-around;
  align-items: flex-end;
  height: 200px;
}

.bar {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 40px;
}

.bar-fill {
  width: 30px;
  background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
  border-radius: 5px 5px 0 0;
  transition: height 0.3s;
}

.bar-label {
  margin-top: 10px;
  font-size: 12px;
  color: #7f8c8d;
}

.top-products {
  width: 100%;
  border-collapse: collapse;
}

.top-products th,
.top-products td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid #ecf0f1;
}

.top-products th {
  background: #f8f9fa;
  font-weight: bold;
  color: #2c3e50;
}
</style>
```

---

## 9. 前端开发-商品管理页面

### 9.1 商品列表 src/views/product/List.vue

```vue
<template>
  <div class="product-list">
    <div class="header">
      <h1>商品管理</h1>
      <router-link to="/products/create" class="btn-primary">添加商品</router-link>
    </div>

    <div class="filters">
      <input v-model="filters.keyword" placeholder="搜索商品名称..." @keyup.enter="search" />
      <select v-model="filters.category_id">
        <option value="">全部分类</option>
        <option v-for="cat in categories" :key="cat.id" :value="cat.id">
          {{ cat.name }}
        </option>
      </select>
      <select v-model="filters.status">
        <option value="">全部状态</option>
        <option value="1">上架</option>
        <option value="0">下架</option>
      </select>
      <button @click="search">筛选</button>
    </div>

    <table class="data-table">
      <thead>
        <tr>
          <th><input type="checkbox" @change="toggleSelectAll" /></th>
          <th>ID</th>
          <th>商品名称</th>
          <th>分类</th>
          <th>价格</th>
          <th>库存</th>
          <th>状态</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in products" :key="item.id">
          <td><input type="checkbox" :value="item.id" v-model="selected" /></td>
          <td>{{ item.id }}</td>
          <td>{{ item.name }}</td>
          <td>{{ item.category_name }}</td>
          <td>¥{{ item.price }}</td>
          <td :class="{ 'low-stock': item.stock < 10 }">{{ item.stock }}</td>
          <td>
            <span :class="['status-badge', item.status === 1 ? 'active' : 'inactive']">
              {{ item.status === 1 ? '上架' : '下架' }}
            </span>
          </td>
          <td>
            <router-link :to="`/products/${item.id}/edit`" class="btn-link">编辑</router-link>
            <button @click="deleteItem(item.id)" class="btn-link danger">删除</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="batch-actions" v-if="selected.length > 0">
      <span>已选择 {{ selected.length }} 项</span>
      <button @click="batchUpdateStatus(1)">批量上架</button>
      <button @click="batchUpdateStatus(0)">批量下架</button>
      <button @click="batchDelete" class="danger">批量删除</button>
    </div>

    <div class="pagination">
      <button @click="prevPage" :disabled="page === 1">上一页</button>
      <span>{{ page }} / {{ totalPages }}</span>
      <button @click="nextPage" :disabled="page >= totalPages">下一页</button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import request from '../../utils/request'

const products = ref([])
const categories = ref([])
const selected = ref([])
const page = ref(1)
const pageSize = ref(15)
const total = ref(0)

const filters = reactive({
  keyword: '',
  category_id: '',
  status: ''
})

const totalPages = computed(() => Math.ceil(total.value / pageSize.value))

const loadProducts = async () => {
  try {
    const params = {
      page: page.value,
      page_size: pageSize.value,
      ...filters
    }
    const res = await request.get('/products', { params })
    products.value = res.data.items
    total.value = res.data.total
  } catch (e) {
    console.error('Failed to load products:', e)
  }
}

const loadCategories = async () => {
  try {
    const res = await request.get('/categories/all')
    categories.value = res.data
  } catch (e) {
    console.error('Failed to load categories:', e)
  }
}

const search = () => {
  page.value = 1
  loadProducts()
}

const prevPage = () => {
  if (page.value > 1) {
    page.value--
    loadProducts()
  }
}

const nextPage = () => {
  if (page.value < totalPages.value) {
    page.value++
    loadProducts()
  }
}

const toggleSelectAll = (e) => {
  if (e.target.checked) {
    selected.value = products.value.map(p => p.id)
  } else {
    selected.value = []
  }
}

const deleteItem = async (id) => {
  if (!confirm('确定要删除这个商品吗？')) return
  try {
    await request.delete(`/products/${id}`)
    loadProducts()
  } catch (e) {
    alert(e.response?.data?.message || '删除失败')
  }
}

const batchUpdateStatus = async (status) => {
  try {
    await request.post('/products/batch-update-status', {
      ids: selected.value,
      status
    })
    selected.value = []
    loadProducts()
  } catch (e) {
    alert('操作失败')
  }
}

const batchDelete = async () => {
  if (!confirm('确定要删除选中的商品吗？')) return
  try {
    await request.post('/products/batch-delete', { ids: selected.value })
    selected.value = []
    loadProducts()
  } catch (e) {
    alert('删除失败')
  }
}

onMounted(() => {
  loadProducts()
  loadCategories()
})
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.filters {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
}

.filters input,
.filters select {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.filters button {
  padding: 8px 20px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.data-table {
  width: 100%;
  background: white;
  border-radius: 8px;
  overflow: hidden;
}

.data-table th,
.data-table td {
  padding: 12px 15px;
  text-align: left;
  border-bottom: 1px solid #ecf0f1;
}

.data-table th {
  background: #f8f9fa;
  font-weight: bold;
}

.low-stock {
  color: #e74c3c;
}

.status-badge {
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
}

.status-badge.active {
  background: #d4edda;
  color: #155724;
}

.status-badge.inactive {
  background: #f8d7da;
  color: #721c24;
}

.btn-primary {
  padding: 10px 20px;
  background: #3498db;
  color: white;
  text-decoration: none;
  border-radius: 4px;
}

.btn-link {
  padding: 5px 10px;
  background: none;
  color: #3498db;
  border: none;
  cursor: pointer;
  text-decoration: none;
}

.btn-link.danger {
  color: #e74c3c;
}

.batch-actions {
  margin-top: 20px;
  padding: 15px;
  background: #f8f9fa;
  border-radius: 8px;
  display: flex;
  gap: 10px;
  align-items: center;
}

.batch-actions button {
  padding: 8px 15px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.batch-actions button.danger {
  background: #e74c3c;
}

.pagination {
  margin-top: 20px;
  display: flex;
  justify-content: center;
  gap: 15px;
  align-items: center;
}

.pagination button {
  padding: 8px 15px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.pagination button:disabled {
  background: #ccc;
}
</style>
```

### 9.2 商品编辑 src/views/product/Edit.vue

```vue
<template>
  <div class="product-form">
    <div class="header">
      <h1>{{ isEdit ? '编辑商品' : '添加商品' }}</h1>
      <router-link to="/products" class="btn-back">返回列表</router-link>
    </div>

    <form @submit.prevent="handleSubmit" class="form">
      <div class="form-row">
        <div class="form-group">
          <label>商品名称 *</label>
          <input v-model="form.name" type="text" required />
        </div>
        <div class="form-group">
          <label>商品别名（URL）</label>
          <input v-model="form.slug" type="text" />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>商品分类 *</label>
          <select v-model="form.category_id" required>
            <option value="">请选择</option>
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
              {{ cat.name }}
            </option>
          </select>
        </div>
        <div class="form-group">
          <label>商品价格 *</label>
          <input v-model.number="form.price" type="number" step="0.01" required />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>库存数量 *</label>
          <input v-model.number="form.stock" type="number" required />
        </div>
        <div class="form-group">
          <label>商品状态</label>
          <select v-model="form.status">
            <option :value="1">上架</option>
            <option :value="0">下架</option>
          </select>
        </div>
      </div>

      <div class="form-group full">
        <label>商品描述</label>
        <textarea v-model="form.description" rows="5"></textarea>
      </div>

      <div class="form-group full">
        <label>商品图片（JSON数组）</label>
        <textarea v-model="form.images" rows="3" placeholder='["/images/1.jpg", "/images/2.jpg"]'></textarea>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-submit" :disabled="loading">
          {{ loading ? '保存中...' : '保存' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { reactive, ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '../../utils/request'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const loading = ref(false)
const categories = ref([])

const form = reactive({
  name: '',
  slug: '',
  category_id: '',
  price: 0,
  stock: 0,
  description: '',
  images: '[]',
  status: 1
})

const loadCategories = async () => {
  try {
    const res = await request.get('/categories/all')
    categories.value = res.data
  } catch (e) {
    console.error('Failed to load categories:', e)
  }
}

const loadProduct = async () => {
  try {
    const res = await request.get(`/products/${route.params.id}`)
    Object.assign(form, res.data)
    form.images = JSON.stringify(res.data.images || [])
  } catch (e) {
    alert('加载商品失败')
    router.push('/products')
  }
}

const handleSubmit = async () => {
  loading.value = true

  try {
    let data = { ...form }
    data.images = JSON.parse(data.images || '[]')

    if (isEdit.value) {
      await request.put(`/products/${route.params.id}`, data)
    } else {
      await request.post('/products', data)
    }

    router.push('/products')
  } catch (e) {
    alert(e.response?.data?.message || '保存失败')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadCategories()
  if (isEdit.value) {
    loadProduct()
  }
})
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.btn-back {
  padding: 10px 20px;
  background: #95a5a6;
  color: white;
  text-decoration: none;
  border-radius: 4px;
}

.form {
  background: white;
  padding: 30px;
  border-radius: 8px;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

.form-group.full {
  grid-column: 1 / -1;
}

.form-group label {
  margin-bottom: 5px;
  font-weight: bold;
  color: #555;
}

.form-group input,
.form-group select,
.form-group textarea {
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.form-actions {
  margin-top: 20px;
}

.btn-submit {
  padding: 12px 40px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 16px;
}

.btn-submit:disabled {
  background: #ccc;
}
</style>
```

---

## 10. 前端开发-分类管理页面

### 10.1 分类管理 src/views/category/List.vue

```vue
<template>
  <div class="category-list">
    <div class="header">
      <h1>分类管理</h1>
      <button @click="showModal = true" class="btn-primary">添加分类</button>
    </div>

    <table class="data-table">
      <thead>
        <tr>
          <th>排序</th>
          <th>ID</th>
          <th>分类名称</th>
          <th>别名</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="cat in categories" :key="cat.id">
          <td>
            <input type="number" :value="cat.sort" @change="updateSort(cat.id, $event)" style="width: 60px;" />
          </td>
          <td>{{ cat.id }}</td>
          <td>{{ cat.name }}</td>
          <td>{{ cat.slug }}</td>
          <td>
            <button @click="editCategory(cat)" class="btn-link">编辑</button>
            <button @click="deleteCategory(cat.id)" class="btn-link danger">删除</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- 添加/编辑弹窗 -->
    <div class="modal" v-if="showModal">
      <div class="modal-content">
        <h3>{{ editingCategory ? '编辑分类' : '添加分类' }}</h3>
        <div class="form-group">
          <label>分类名称</label>
          <input v-model="form.name" type="text" />
        </div>
        <div class="form-group">
          <label>别名</label>
          <input v-model="form.slug" type="text" />
        </div>
        <div class="form-group">
          <label>排序</label>
          <input v-model.number="form.sort" type="number" />
        </div>
        <div class="modal-actions">
          <button @click="closeModal">取消</button>
          <button @click="saveCategory" class="btn-primary">保存</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import request from '../../utils/request'

const categories = ref([])
const showModal = ref(false)
const editingCategory = ref(null)

const form = reactive({
  name: '',
  slug: '',
  sort: 0
})

const loadCategories = async () => {
  try {
    const res = await request.get('/categories')
    categories.value = res.data
  } catch (e) {
    console.error('Failed to load categories:', e)
  }
}

const editCategory = (cat) => {
  editingCategory.value = cat
  form.name = cat.name
  form.slug = cat.slug
  form.sort = cat.sort
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  editingCategory.value = null
  form.name = ''
  form.slug = ''
  form.sort = 0
}

const saveCategory = async () => {
  try {
    if (editingCategory.value) {
      await request.put(`/categories/${editingCategory.value.id}`, form)
    } else {
      await request.post('/categories', form)
    }
    closeModal()
    loadCategories()
  } catch (e) {
    alert(e.response?.data?.message || '保存失败')
  }
}

const updateSort = async (id, event) => {
  const sort = parseInt(event.target.value)
  try {
    await request.post('/categories/update-sort', {
      sort_data: [{ id, sort }]
    })
    loadCategories()
  } catch (e) {
    alert('更新排序失败')
  }
}

const deleteCategory = async (id) => {
  if (!confirm('确定要删除这个分类吗？')) return
  try {
    await request.delete(`/categories/${id}`)
    loadCategories()
  } catch (e) {
    alert(e.response?.data?.message || '删除失败')
  }
}

onMounted(() => {
  loadCategories()
})
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.btn-primary {
  padding: 10px 20px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.data-table {
  width: 100%;
  background: white;
  border-radius: 8px;
  overflow: hidden;
}

.data-table th,
.data-table td {
  padding: 12px 15px;
  text-align: left;
  border-bottom: 1px solid #ecf0f1;
}

.data-table th {
  background: #f8f9fa;
  font-weight: bold;
}

.btn-link {
  padding: 5px 10px;
  background: none;
  color: #3498db;
  border: none;
  cursor: pointer;
}

.btn-link.danger {
  color: #e74c3c;
}

.modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  justify-content: center;
  align-items: center;
}

.modal-content {
  background: white;
  padding: 30px;
  border-radius: 8px;
  width: 400px;
}

.modal-content h3 {
  margin-bottom: 20px;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  margin-bottom: 5px;
  font-weight: bold;
}

.form-group input {
  width: 100%;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
}

.modal-actions button {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.modal-actions button:first-child {
  background: #ecf0f1;
}
</style>
```

---

## 11. 前端开发-订单管理页面

### 11.1 订单列表 src/views/order/List.vue

```vue
<template>
  <div class="order-list">
    <h1>订单管理</h1>

    <div class="filters">
      <input v-model="filters.keyword" placeholder="搜索订单号..." @keyup.enter="search" />
      <select v-model="filters.status">
        <option value="">全部状态</option>
        <option value="1">待支付</option>
        <option value="2">已支付</option>
        <option value="3">已发货</option>
        <option value="4">已完成</option>
        <option value="5">已取消</option>
      </select>
      <input v-model="filters.start_date" type="date" />
      <input v-model="filters.end_date" type="date" />
      <button @click="search">筛选</button>
    </div>

    <table class="data-table">
      <thead>
        <tr>
          <th>订单号</th>
          <th>用户</th>
          <th>收货人</th>
          <th>总金额</th>
          <th>状态</th>
          <th>下单时间</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="order in orders" :key="order.id">
          <td>{{ order.order_no }}</td>
          <td>{{ order.user_id }}</td>
          <td>{{ order.receiver_name }}</td>
          <td>¥{{ order.total_amount }}</td>
          <td>
            <span :class="['status-badge', statusClass(order.status)]">
              {{ statusText(order.status) }}
            </span>
          </td>
          <td>{{ formatDate(order.created_at) }}</td>
          <td>
            <router-link :to="`/orders/${order.id}`" class="btn-link">详情</router-link>
            <button v-if="order.status === 2" @click="shipOrder(order.id)" class="btn-link">发货</button>
            <button v-if="[1, 2].includes(order.status)" @click="cancelOrder(order.id)" class="btn-link danger">取消</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="pagination">
      <button @click="prevPage" :disabled="page === 1">上一页</button>
      <span>{{ page }} / {{ totalPages }}</span>
      <button @click="nextPage" :disabled="page >= totalPages">下一页</button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import request from '../../utils/request'

const orders = ref([])
const page = ref(1)
const pageSize = ref(15)
const total = ref(0)

const filters = reactive({
  keyword: '',
  status: '',
  start_date: '',
  end_date: ''
})

const totalPages = computed(() => Math.ceil(total.value / pageSize.value))

const statusText = (status) => {
  const map = { 1: '待支付', 2: '已支付', 3: '已发货', 4: '已完成', 5: '已取消' }
  return map[status] || '未知'
}

const statusClass = (status) => {
  const map = { 1: 'pending', 2: 'paid', 3: 'shipped', 4: 'completed', 5: 'cancelled' }
  return map[status] || ''
}

const formatDate = (date) => {
  return new Date(date).toLocaleString()
}

const loadOrders = async () => {
  try {
    const params = { page: page.value, page_size: pageSize.value, ...filters }
    const res = await request.get('/orders', { params })
    orders.value = res.data.items
    total.value = res.data.total
  } catch (e) {
    console.error('Failed to load orders:', e)
  }
}

const search = () => {
  page.value = 1
  loadOrders()
}

const prevPage = () => {
  if (page.value > 1) {
    page.value--
    loadOrders()
  }
}

const nextPage = () => {
  if (page.value < totalPages.value) {
    page.value++
    loadOrders()
  }
}

const shipOrder = async (id) => {
  if (!confirm('确定要发货吗？')) return
  try {
    await request.post(`/orders/${id}/ship`)
    loadOrders()
  } catch (e) {
    alert(e.response?.data?.message || '操作失败')
  }
}

const cancelOrder = async (id) => {
  if (!confirm('确定要取消订单吗？')) return
  try {
    await request.post(`/orders/${id}/cancel`)
    loadOrders()
  } catch (e) {
    alert(e.response?.data?.message || '操作失败')
  }
}

onMounted(() => {
  loadOrders()
})
</script>

<style scoped>
.filters {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
}

.filters input,
.filters select {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.filters button {
  padding: 8px 20px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.data-table {
  width: 100%;
  background: white;
  border-radius: 8px;
  overflow: hidden;
}

.data-table th,
.data-table td {
  padding: 12px 15px;
  text-align: left;
  border-bottom: 1px solid #ecf0f1;
}

.data-table th {
  background: #f8f9fa;
  font-weight: bold;
}

.status-badge {
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
}

.pending { background: #fff3cd; color: #856404; }
.paid { background: #d4edda; color: #155724; }
.shipped { background: #cce5ff; color: #004085; }
.completed { background: #d4edda; color: #155724; }
.cancelled { background: #f8d7da; color: #721c24; }

.btn-link {
  padding: 5px 10px;
  background: none;
  color: #3498db;
  border: none;
  cursor: pointer;
}

.btn-link.danger {
  color: #e74c3c;
}

.pagination {
  margin-top: 20px;
  display: flex;
  justify-content: center;
  gap: 15px;
}

.pagination button {
  padding: 8px 15px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.pagination button:disabled {
  background: #ccc;
}
</style>
```

---

## 12. 测试与运行

### 12.1 启动管理后台

```bash
# 先启动后端 API 服务（如尚未启动）
cd shop-api
php bin/console serve 8080

# 启动管理后台前端
cd shop-admin-panel
npm run dev
```

访问 http://localhost:8082

### 12.2 默认管理员账号

| 用户名 | 密码 |
|--------|------|
| admin | password |

### 12.3 后台管理 API 路由

```php
// 管理员认证
POST /api/admin/auth/login      // 登录
POST /api/admin/auth/logout      // 退出
GET  /api/admin/auth/profile     // 个人信息

// 仪表盘
GET  /api/admin/dashboard        // 统计数据

// 商品管理
GET    /api/admin/products       // 商品列表
POST   /api/admin/products       // 添加商品
GET    /api/admin/products/{id}  // 商品详情
PUT    /api/admin/products/{id}  // 更新商品
DELETE /api/admin/products/{id}  // 删除商品
POST   /api/admin/products/batch-delete          // 批量删除
POST   /api/admin/products/batch-update-status    // 批量更新状态

// 分类管理
GET    /api/admin/categories     // 分类列表（树形）
GET    /api/admin/categories/all // 全部分类
POST   /api/admin/categories     // 添加分类
PUT    /api/admin/categories/{id} // 更新分类
DELETE /api/admin/categories/{id} // 删除分类
POST   /api/admin/categories/update-sort // 更新排序

// 订单管理
GET    /api/admin/orders         // 订单列表
GET    /api/admin/orders/{id}   // 订单详情
POST   /api/admin/orders/{id}/ship    // 发货
POST   /api/admin/orders/{id}/cancel  // 取消
GET    /api/admin/orders/statistics   // 订单统计
```

### 12.4 完整功能测试流程

1. **管理员登录**
   - 访问 http://localhost:8082/login
   - 使用 admin / password 登录

2. **仪表盘**
   - 查看今日订单、销售额统计
   - 查看销售趋势图
   - 查看热销商品排行

3. **商品管理**
   - 添加新商品
   - 编辑商品信息
   - 上架/下架商品
   - 批量操作

4. **分类管理**
   - 添加/编辑/删除分类
   - 拖拽排序

5. **订单管理**
   - 查看所有订单
   - 按状态/日期筛选
   - 订单发货/取消

---

后台管理开发教程完成！
