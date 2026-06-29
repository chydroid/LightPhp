# LightPHP 电商系统开发实战

> ⚠️ **模型访问模式说明**：本教程中 `Model::find($id)`、`Model::findBy(...)` 等方法返回 **模型实例**（支持 `__get`），请使用 `$model->id` 而非 `$model['id']`；而 `Model::where(...)->fetchAll()` / `->fetch()` / `->first()` 返回的是 **关联数组**，可使用 `$row['id']`。当需要把模型实例转为数组时，调用 `->toArray()`。

## 目录

1. [项目概述](#1-项目概述)
2. [数据库设计](#2-数据库设计)
3. [项目初始化](#3-项目初始化)
4. [后端开发-用户模块](#4-后端开发-用户模块)
5. [后端开发-商品分类模块](#5-后端开发-商品分类模块)
6. [后端开发-商品模块](#6-后端开发-商品模块)
7. [后端开发-购物车模块](#7-后端开发-购物车模块)
8. [后端开发-订单模块](#8-后端开发-订单模块)
9. [前端开发-项目结构](#9-前端开发-项目结构)
10. [前端开发-用户与认证](#10-前端开发-用户与认证)
11. [前端开发-商品与分类](#11-前端开发-商品与分类)
12. [前端开发-购物车](#12-前端开发-购物车)
13. [前端开发-订单](#13-前端开发-订单)
14. [测试与运行](#14-测试与运行)

---

## 1. 项目概述

> 💡 **新手提示**：这是一个完整的实战教程，带你从零开始构建一个前后端分离的电商系统。你将学习到用户认证、商品管理、购物车、订单处理等核心功能。每个章节都包含完整的后端 API 代码和前端 Vue 代码，跟着做就可以跑起来。建议按顺序阅读，因为后面的章节会用到前面的代码。

> ⚠️ **前置知识**：阅读本教程前，建议先完成 [开发指南](guide.md) 中的基础章节，了解路由、控制器、模型的基本用法。

### 1.1 系统功能模块

本教程开发的是一个前后端分离的简易电商系统，包含以下功能模块：

| 模块 | 功能 |
|------|------|
| 用户模块 | 用户注册、登录、退出、个人信息管理 |
| 商品分类 | 分类列表、分类详情 |
| 商品模块 | 商品列表、商品详情、搜索 |
| 购物车 | 添加商品、查看购物车、修改数量、删除商品、清空购物车 |
| 订单模块 | 下单、订单列表、订单详情 |

### 1.2 系统架构

```
┌─────────────┐     ┌─────────────┐
│   前端 Vue  │────▶│   后端 API  │
│   (8081)   │◀────│   (8080)    │
└─────────────┘     └─────────────┘
                          │
                          ▼
                    ┌─────────────┐
                    │   MySQL     │
                    └─────────────┘
```

### 1.3 技术栈

- **后端**：LightPHP 框架
- **数据库**：MySQL
- **前端**：Vue 3 + Vite
- **HTTP 客户端**：Axios

---

## 2. 数据库设计

### 2.1 创建数据库

> **提示**：可使用框架内置的 Schema Builder + 迁移系统管理表结构，参见 [Schema Builder 文档](#)。

```sql
CREATE DATABASE IF NOT EXISTS shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE shop;
```

### 2.2 数据表结构

#### 2.2.1 用户表 (users)

```sql
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '用户名',
    `email` VARCHAR(100) NOT NULL UNIQUE COMMENT '邮箱',
    `password` VARCHAR(255) NOT NULL COMMENT '密码（加密）',
    `nickname` VARCHAR(50) DEFAULT NULL COMMENT '昵称',
    `avatar` VARCHAR(255) DEFAULT NULL COMMENT '头像URL',
    `status` TINYINT DEFAULT 1 COMMENT '状态：0禁用 1启用',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';
```

#### 2.2.2 分类表 (categories)

```sql
CREATE TABLE `categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL COMMENT '分类名称',
    `slug` VARCHAR(50) NOT NULL UNIQUE COMMENT '分类别名',
    `parent_id` INT UNSIGNED DEFAULT 0 COMMENT '父分类ID',
    `sort` INT DEFAULT 0 COMMENT '排序',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类表';
```

#### 2.2.3 商品表 (products)

```sql
CREATE TABLE `products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT '商品名称',
    `slug` VARCHAR(100) NOT NULL UNIQUE COMMENT '商品别名',
    `category_id` INT UNSIGNED NOT NULL COMMENT '分类ID',
    `description` TEXT COMMENT '商品描述',
    `price` DECIMAL(10,2) NOT NULL COMMENT '价格',
    `stock` INT UNSIGNED DEFAULT 0 COMMENT '库存',
    `images` JSON COMMENT '图片JSON数组',
    `status` TINYINT DEFAULT 1 COMMENT '状态：0下架 1上架',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品表';
```

#### 2.2.4 购物车表 (cart_items)

```sql
CREATE TABLE `cart_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
    `product_id` INT UNSIGNED NOT NULL COMMENT '商品ID',
    `quantity` INT UNSIGNED DEFAULT 1 COMMENT '数量',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    UNIQUE KEY `user_product` (`user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='购物车表';
```

#### 2.2.5 订单表 (orders)

```sql
CREATE TABLE `orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(32) NOT NULL UNIQUE COMMENT '订单号',
    `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
    `total_amount` DECIMAL(10,2) NOT NULL COMMENT '订单总金额',
    `status` TINYINT DEFAULT 1 COMMENT '状态：1待支付 2已支付 3已发货 4已完成 5已取消',
    `receiver_name` VARCHAR(50) NOT NULL COMMENT '收货人姓名',
    `receiver_phone` VARCHAR(20) NOT NULL COMMENT '收货人电话',
    `receiver_address` VARCHAR(255) NOT NULL COMMENT '收货地址',
    `remark` VARCHAR(255) DEFAULT NULL COMMENT '备注',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';
```

#### 2.2.6 订单商品表 (order_items)

```sql
CREATE TABLE `order_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL COMMENT '订单ID',
    `product_id` INT UNSIGNED NOT NULL COMMENT '商品ID',
    `product_name` VARCHAR(100) NOT NULL COMMENT '商品名称（冗余）',
    `price` DECIMAL(10,2) NOT NULL COMMENT '商品价格（冗余）',
    `quantity` INT UNSIGNED NOT NULL COMMENT '购买数量',
    `subtotal` DECIMAL(10,2) NOT NULL COMMENT '小计金额',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单商品表';
```

### 2.3 初始化数据

```sql
-- 插入分类
INSERT INTO `categories` (`name`, `slug`, `parent_id`, `sort`) VALUES
('数码产品', 'digital', 0, 1),
('服装', 'clothing', 0, 2),
('食品', 'food', 0, 3),
('图书', 'books', 0, 4);

-- 插入商品
INSERT INTO `products` (`name`, `slug`, `category_id`, `description`, `price`, `stock`, `images`, `status`) VALUES
('iPhone 15', 'iphone-15', 1, '苹果最新款手机', 6999.00, 100, '["/images/iphone15.jpg"]', 1),
('小米手机', 'xiaomi-14', 1, '小米旗舰手机', 3999.00, 200, '["/images/xiaomi14.jpg"]', 1),
('T恤', 'tshirt-basic', 2, '纯棉T恤', 99.00, 500, '["/images/tshirt.jpg"]', 1),
('牛仔裤', 'jeans-classic', 2, '经典牛仔裤', 199.00, 300, '["/images/jeans.jpg"]', 1),
('有机苹果', 'organic-apple', 3, '新鲜有机苹果5斤装', 49.90, 1000, '["/images/apple.jpg"]', 1);
```

---

## 3. 项目初始化

### 3.1 后端项目结构

```
shop-api/                    # 后端项目
├── app/
│   ├── config/
│   │   ├── app.php         # 应用配置
│   │   ├── database.php    # 数据库配置
│   │   └── cors.php        # CORS配置
│   ├── controller/
│   │   └── Api/
│   │       ├── AuthController.php
│   │       ├── CategoryController.php
│   │       ├── ProductController.php
│   │       ├── CartController.php
│   │       └── OrderController.php
│   ├── middleware/
│   │   ├── Auth.php        # 认证中间件
│   │   └── Cors.php        # 跨域中间件
│   ├── model/
│   │   ├── User.php
│   │   ├── Category.php
│   │   ├── Product.php
│   │   ├── CartItem.php
│   │   ├── Order.php
│   │   └── OrderItem.php
│   └── route/
│       └── api.php         # API路由
├── public/
│   └── index.php
└── storage/
```

### 3.2 数据库配置

打开 `app/config/database.php`，修改为你的数据库连接信息：

```php
<?php
// app/config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host'     => '127.0.0.1',   // 数据库地址
            'port'     => 3306,           // 端口号
            'database' => 'shop',         // ⚠️ 数据库名称
            'username' => 'root',         // ⚠️ 用户名
            'password' => '',             // ⚠️ 密码
            'charset'  => 'utf8mb4',      // 字符集
        ],
    ],
    'prefix' => '',
];
```

> 💡 **提示**：本框架推荐使用 `app/config/` 目录下的 PHP 配置文件。相比 `.env` 方式，PHP 配置支持 IDE 语法高亮、注释和嵌套数据结构，更直观易懂。

### 3.3 CORS 跨域配置

框架已内置 `middleware\Cors` 中间件，在路由中注册即可：

```php
// app/route/api.php
$router->group(['middleware' => [new \middleware\Cors([
    'allowed_origins'     => ['http://localhost:8081', 'http://localhost:5173'],
    'allowed_methods'     => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers'     => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'supports_credentials' => true,
    'max_age'              => 86400,
])]], function($router) {
    // API 路由
});
```

> 💡 **v2.0 中间件别名**：v2.0 新增了中间件别名注册功能，可以在路由中使用短名称代替完整类名：
> ```php
> $router->aliasMiddleware('cors', \middleware\Cors::class);
> $router->aliasMiddleware('auth', \middleware\Auth::class);
>
> // 使用别名
> $router->group(['middleware' => ['cors', 'auth']], function($router) {
>     // ...
> });
> ```

---

## 4. 后端开发-用户模块

### 4.1 创建用户模型 app/model/User.php

```php
<?php
declare(strict_types=1);

namespace model;

class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['username', 'email', 'password', 'nickname', 'avatar', 'status'];
    protected array $casts = [
        'status' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    protected array $hidden = ['password'];

    // v2.0 修改器：设置密码时自动哈希加密
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = \core\Hash::make($value);
    }

    public function verifyPassword(string $password): bool
    {
        return \core\Hash::verify($password, $this->attributes['password']);
    }
}
```

### 4.2 创建认证控制器 app/controller/Api/AuthController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api;

use core\Controller;
use core\Request;
use core\Response;
use model\User;

class AuthController extends Controller
{
    public function register(Request $request): Response
    {
        // v2.0 推荐使用类型过滤方法：$request->string('username')
        $data = $request->only(['username', 'email', 'password']);

        if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            return $this->error('Username, email and password are required', 422);
        }

        if (strlen($data['password']) < 6) {
            return $this->error('Password must be at least 6 characters', 422);
        }

        $existingUser = User::where('username', '=', $data['username'])->first();
        if ($existingUser) {
            return $this->error('Username already exists', 422);
        }

        $existingEmail = User::where('email', '=', $data['email'])->first();
        if ($existingEmail) {
            return $this->error('Email already exists', 422);
        }

        $user = new User();
        $user->username = $data['username'];
        $user->email = $data['email'];
        $user->password = \core\Hash::make($data['password']);
        $user->nickname = $data['username'];
        $user->status = 1;
        $userId = $user->save();

        $token = $this->generateToken($userId);

        return Response::json([
            'code' => 0,
            'message' => 'Registration successful',
            'data' => [
                'user' => [
                    'id' => $userId,
                    'username' => $data['username'],
                    'email' => $data['email'],
                ],
                'token' => $token,
            ],
        ], 201);
    }

    public function login(Request $request): Response
    {
        $username = $request->input('username');
        $password = $request->input('password');

        if (empty($username) || empty($password)) {
            return $this->error('Username and password are required', 422);
        }

        $user = User::where('username', '=', $username)
            ->whereOr(['email' => $username])
            ->first();

        if (!$user || !\core\Hash::verify($password, $user->password)) {
            return $this->error('Invalid credentials', 401);
        }

        if (($user->status ?? 0) !== 1) {
            return $this->error('Account is disabled', 403);
        }

        $token = $this->generateToken($user->id);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'nickname' => $user->nickname,
                'avatar' => $user->avatar,
            ],
            'token' => $token,
        ], 'Login successful');
    }

    public function logout(Request $request): Response
    {
        return $this->success([], 'Logout successful');
    }

    public function user(Request $request): Response
    {
        $userId = $request->input('user_id') ?? null;

        if (!$userId) {
            return $this->error('Unauthorized', 401);
        }

        $user = User::find($userId);

        if (!$user) {
            return $this->error('User not found', 404);
        }

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar,
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $userId = $request->input('user_id') ?? null;

        if (!$userId) {
            return $this->error('Unauthorized', 401);
        }

        $data = $request->only(['nickname', 'avatar']);

        User::update($userId, $data);

        return $this->success([], 'Profile updated');
    }

    private function generateToken(int $userId): string
    {
        $payload = [
            'user_id' => $userId,
            'exp' => time() + 86400 * 7,
            'rand' => bin2hex(random_bytes(16)),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = \core\Hash::make($payloadJson);
        return base64_encode($payloadJson . '.' . $signature);
    }
}
```

### 4.3 创建认证中间件 app/middleware/Auth.php

```php
<?php
declare(strict_types=1);

namespace middleware;

class Auth
{
    protected array $except = [
        '/api/auth/login',
        '/api/auth/register',
    ];

    public function handle($request, callable $next)
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

        if (!$payload || !isset($payload['user_id']) || $payload['exp'] < time()) {
            return \core\Response::json([
                'code' => 401,
                'message' => 'Unauthorized',
                'data' => [],
            ], 401);
        }

        $_REQUEST['user_id'] = $payload['user_id'];

        return $next($request);
    }
}
```

---

## 5. 后端开发-商品分类模块

### 5.1 创建分类模型 app/model/Category.php

```php
<?php
declare(strict_types=1);

namespace model;

class Category extends Model
{
    protected string $table = 'categories';
    protected array $fillable = ['name', 'slug', 'parent_id', 'sort'];
    protected array $casts = [
        'parent_id' => 'int',
        'sort' => 'int',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function children()
    {
        return self::where('parent_id', '=', $this->id ?? 0)->orderBy('sort', 'ASC')->fetchAll();
    }
}
```

### 5.2 创建分类控制器 app/controller/Api/CategoryController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api;

use core\Controller;
use core\Response;
use model\Category;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $categories = Category::orderBy('sort', 'ASC')->fetchAll();

        $tree = $this->buildTree($categories);

        return $this->success($tree);
    }

    public function show(int $id): Response
    {
        $category = Category::find($id);

        if (!$category) {
            return $this->error('Category not found', 404);
        }

        $children = Category::where('parent_id', '=', $id)->orderBy('sort', 'ASC')->fetchAll();

        return $this->success([
            'category' => $category,
            'children' => $children,
        ]);
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

## 6. 后端开发-商品模块

### 6.1 创建商品模型 app/model/Product.php

```php
<?php
declare(strict_types=1);

namespace model;

class Product extends Model
{
    protected string $table = 'products';
    protected array $fillable = [
        'name', 'slug', 'category_id', 'description',
        'price', 'stock', 'images', 'status'
    ];
    protected array $casts = [
        'category_id' => 'int',
        'price' => 'float',
        'stock' => 'int',
        'status' => 'int',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // v2.0 访问器：读取 images 时自动将 JSON 字符串转为数组
    public function getImagesAttribute($value)
    {
        return json_decode($value ?? '[]', true) ?: [];
    }
}
```

### 6.2 创建商品控制器 app/controller/Api/ProductController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api;

use core\Controller;
use core\Request;
use core\Response;
use model\Product;
use model\Category;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $categoryId = $request->input('category_id');
        $keyword = $request->input('keyword');
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);

        $query = Product::where('status', '=', 1);

        if ($categoryId) {
            $query->where('category_id', '=', $categoryId);
        }

        if ($keyword) {
            $query->where('name', 'LIKE', "%{$keyword}%");
        }

        $result = $query->orderBy('created_at', 'DESC')->paginate($pageSize, $page);

        foreach ($result['items'] as &$product) {
            $category = Category::find($product['category_id']);
            $product['category_name'] = $category ? $category->name : '';
        }

        return $this->success($result);
    }

    public function show(int $id): Response
    {
        $product = Product::find($id);

        if (!$product || $product->status != 1) {
            return $this->error('Product not found', 404);
        }

        $category = Category::find($product->category_id);
        $productData = $product->toArray();
        $productData['category_name'] = $category ? $category->name : '';

        return $this->success($productData);
    }

    public function featured(): Response
    {
        $products = Product::where('status', '=', 1)
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->fetchAll();

        return $this->success($products);
    }
}
```

---

## 7. 后端开发-购物车模块

### 7.1 创建购物车模型 app/model/CartItem.php

```php
<?php
declare(strict_types=1);

namespace model;

class CartItem extends Model
{
    protected string $table = 'cart_items';
    protected array $fillable = ['user_id', 'product_id', 'quantity'];
    protected array $casts = [
        'user_id' => 'int',
        'product_id' => 'int',
        'quantity' => 'int',
    ];
}
```

### 7.2 创建购物车控制器 app/controller/Api/CartController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api;

use core\Controller;
use core\Request;
use core\Response;
use model\CartItem;
use model\Product;

class CartController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->input('user_id');

        $cartItems = CartItem::where('user_id', '=', $userId)->fetchAll();

        $productIds = array_column($cartItems, 'product_id');
        $products = [];
        if (!empty($productIds)) {
            $productList = Product::whereIn('id', $productIds)->fetchAll();
            foreach ($productList as $product) {
                $products[$product['id']] = $product;
            }
        }

        $items = [];
        $totalAmount = 0;
        foreach ($cartItems as $item) {
            $product = $products[$item['product_id']] ?? null;
            if ($product) {
                $subtotal = $product['price'] * $item['quantity'];
                $totalAmount += $subtotal;
                $items[] = [
                    'id' => $item['id'],
                    'product_id' => $product['id'],
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'image' => json_decode($product['images'], true)[0] ?? '',
                    'quantity' => $item['quantity'],
                    'subtotal' => $subtotal,
                    'stock' => $product['stock'],
                ];
            }
        }

        return $this->success([
            'items' => $items,
            'total_count' => count($items),
            'total_amount' => round($totalAmount, 2),
        ]);
    }

    public function add(Request $request): Response
    {
        $userId = $request->input('user_id');
        $productId = (int) $request->input('product_id');
        $quantity = (int) $request->input('quantity', 1);

        if ($quantity < 1) {
            return $this->error('Quantity must be at least 1', 422);
        }

        $product = Product::find($productId);
        if (!$product || $product->status != 1) {
            return $this->error('Product not found', 404);
        }

        if ($product->stock < $quantity) {
            return $this->error('Insufficient stock', 422);
        }

        $existingItem = CartItem::where('user_id', '=', $userId)
            ->where('product_id', '=', $productId)
            ->first();

        if ($existingItem) {
            $newQuantity = $existingItem['quantity'] + $quantity;
            if ($product->stock < $newQuantity) {
                return $this->error('Insufficient stock', 422);
            }
            CartItem::update($existingItem['id'], ['quantity' => $newQuantity]);
        } else {
            CartItem::create([
                'user_id' => $userId,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        return $this->success([], 'Added to cart');
    }

    public function update(Request $request): Response
    {
        $userId = $request->input('user_id');
        $cartItemId = (int) $request->input('id');
        $quantity = (int) $request->input('quantity');

        if ($quantity < 1) {
            return $this->error('Quantity must be at least 1', 422);
        }

        $cartItem = CartItem::find($cartItemId);
        if (!$cartItem || $cartItem->user_id != $userId) {
            return $this->error('Cart item not found', 404);
        }

        $product = Product::find($cartItem->product_id);
        if (!$product || $product->stock < $quantity) {
            return $this->error('Insufficient stock', 422);
        }

        CartItem::update($cartItemId, ['quantity' => $quantity]);

        return $this->success([], 'Cart updated');
    }

    public function remove(Request $request): Response
    {
        $userId = $request->input('user_id');
        $cartItemId = (int) $request->input('id');

        $cartItem = CartItem::find($cartItemId);
        if (!$cartItem || $cartItem->user_id != $userId) {
            return $this->error('Cart item not found', 404);
        }

        CartItem::deleteById($cartItemId);

        return $this->success([], 'Item removed');
    }

    public function clear(Request $request): Response
    {
        $userId = $request->input('user_id');

        $db = \core\Application::getInstance()->getContainer()->get('db');
        $db->table('cart_items')->where('user_id', '=', $userId)->delete();

        return $this->success([], 'Cart cleared');
    }
}
```

---

## 8. 后端开发-订单模块

### 8.1 创建订单模型

#### app/model/Order.php

```php
<?php
declare(strict_types=1);

namespace model;

class Order extends Model
{
    protected string $table = 'orders';
    protected array $fillable = [
        'order_no', 'user_id', 'total_amount', 'status',
        'receiver_name', 'receiver_phone', 'receiver_address', 'remark'
    ];
    protected array $casts = [
        'user_id' => 'int',
        'total_amount' => 'float',
        'status' => 'int',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function generateOrderNo(): string
    {
        return date('YmdHis') . rand(1000, 9999);
    }
}
```

#### app/model/OrderItem.php

```php
<?php
declare(strict_types=1);

namespace model;

class OrderItem extends Model
{
    protected string $table = 'order_items';
    protected array $fillable = [
        'order_id', 'product_id', 'product_name', 'price', 'quantity', 'subtotal'
    ];
    protected array $casts = [
        'order_id' => 'int',
        'product_id' => 'int',
        'price' => 'float',
        'quantity' => 'int',
        'subtotal' => 'float',
    ];
}
```

### 8.2 创建订单控制器 app/controller/Api/OrderController.php

```php
<?php
declare(strict_types=1);

namespace controller\Api;

use core\Controller;
use core\Request;
use core\Response;
use model\Order;
use model\OrderItem;
use model\CartItem;
use model\Product;
use db\Connection;

class OrderController extends Controller
{
    public function create(Request $request): Response
    {
        $userId = $request->input('user_id');
        $receiverName = $request->input('receiver_name');
        $receiverPhone = $request->input('receiver_phone');
        $receiverAddress = $request->input('receiver_address');
        $remark = $request->input('remark', '');

        if (empty($receiverName) || empty($receiverPhone) || empty($receiverAddress)) {
            return $this->error('Receiver information is required', 422);
        }

        $cartItems = CartItem::where('user_id', '=', $userId)->fetchAll();

        if (empty($cartItems)) {
            return $this->error('Cart is empty', 422);
        }

        $productIds = array_column($cartItems, 'product_id');
        $products = [];
        $productList = Product::whereIn('id', $productIds)->fetchAll();
        foreach ($productList as $product) {
            $products[$product['id']] = $product;
        }

        foreach ($cartItems as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product || $product['stock'] < $item['quantity']) {
                return $this->error('Insufficient stock for product: ' . ($product['name'] ?? ''), 422);
            }
        }

        $db = \core\Application::getInstance()->getContainer()->get('db');
        try {
            $db->beginTransaction();

            $order = new Order();
            $orderNo = $order->generateOrderNo();
            $totalAmount = 0;

            foreach ($cartItems as $item) {
                $product = $products[$item['product_id']];
                $subtotal = $product['price'] * $item['quantity'];
                $totalAmount += $subtotal;
            }

            $orderData = [
                'order_no' => $orderNo,
                'user_id' => $userId,
                'total_amount' => $totalAmount,
                'status' => 1,
                'receiver_name' => $receiverName,
                'receiver_phone' => $receiverPhone,
                'receiver_address' => $receiverAddress,
                'remark' => $remark,
            ];

            $orderId = $db->table('orders')->insert($orderData);

            foreach ($cartItems as $item) {
                $product = $products[$item['product_id']];
                $subtotal = $product['price'] * $item['quantity'];

                $db->table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'product_name' => $product['name'],
                    'price' => $product['price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $subtotal,
                ]);

                $db->table('products')->where('id', '=', $item['product_id'])->update([
                    'stock' => $product['stock'] - $item['quantity'],
                ]);
            }

            $db->table('cart_items')->where('user_id', '=', $userId)->delete();

            $db->commit();

            return $this->success([
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'total_amount' => $totalAmount,
            ], 'Order created successfully', 201);

        } catch (\Exception $e) {
            $db->rollback();
            return $this->error('Order creation failed: ' . $e->getMessage(), 500);
        }
    }

    public function index(Request $request): Response
    {
        $userId = $request->input('user_id');
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 10);

        $result = Order::where('user_id', '=', $userId)
            ->orderBy('created_at', 'DESC')
            ->paginate($pageSize, $page);

        foreach ($result['items'] as &$order) {
            $items = OrderItem::where('order_id', '=', $order['id'])->fetchAll();
            $order['items'] = $items;
        }

        return $this->success($result);
    }

    public function show(int $id, Request $request): Response
    {
        $userId = $request->input('user_id');

        $order = Order::find($id);

        if (!$order || $order->user_id != $userId) {
            return $this->error('Order not found', 404);
        }

        $items = OrderItem::where('order_id', '=', $id)->fetchAll();
        $orderData = $order->toArray();
        $orderData['items'] = $items;

        return $this->success($orderData);
    }

    public function cancel(int $id, Request $request): Response
    {
        $userId = $request->input('user_id');

        $order = Order::find($id);

        if (!$order || $order->user_id != $userId) {
            return $this->error('Order not found', 404);
        }

        if ($order->status != 1) {
            return $this->error('Only pending orders can be cancelled', 422);
        }

        $db = \core\Application::getInstance()->getContainer()->get('db');
        try {
            $db->beginTransaction();

            $items = OrderItem::where('order_id', '=', $id)->fetchAll();
            foreach ($items as $item) {
                $product = Product::find($item['product_id']);
                $db->table('products')->where('id', '=', $item['product_id'])->update([
                    'stock' => $product->stock + $item['quantity'],
                ]);
            }

            Order::update($id, ['status' => 5]);

            $db->commit();

            return $this->success([], 'Order cancelled');

        } catch (\Exception $e) {
            $db->rollback();
            return $this->error('Cancel failed: ' . $e->getMessage(), 500);
        }
    }
}
```

### 8.3 API 路由配置 app/route/api.php

```php
<?php

use core\Router;

$router = new Router();

$router->group(['middleware' => [\middleware\Cors::class]], function($router) {

    $router->group(['prefix' => '/auth'], function($router) {
        $router->post('/register', [\controller\Api\AuthController::class, 'register']);
        $router->post('/login', [\controller\Api\AuthController::class, 'login']);
        $router->post('/logout', [\controller\Api\AuthController::class, 'logout']);
        $router->get('/user', [\controller\Api\AuthController::class, 'user']);
        $router->put('/user', [\controller\Api\AuthController::class, 'updateProfile']);
    });

    $router->group(['prefix' => '/categories'], function($router) {
        $router->get('/', [\controller\Api\CategoryController::class, 'index']);
        $router->get('/{id}', [\controller\Api\CategoryController::class, 'show']);
    });

    $router->group(['prefix' => '/products'], function($router) {
        $router->get('/', [\controller\Api\ProductController::class, 'index']);
        $router->get('/featured', [\controller\Api\ProductController::class, 'featured']);
        $router->get('/{id}', [\controller\Api\ProductController::class, 'show']);
    });

    $router->group(['prefix' => '/cart', 'middleware' => [\middleware\Auth::class]], function($router) {
        $router->get('/', [\controller\Api\CartController::class, 'index']);
        $router->post('/', [\controller\Api\CartController::class, 'add']);
        $router->put('/', [\controller\Api\CartController::class, 'update']);
        $router->delete('/', [\controller\Api\CartController::class, 'remove']);
        $router->delete('/clear', [\controller\Api\CartController::class, 'clear']);
    });

    $router->group(['prefix' => '/orders', 'middleware' => [\middleware\Auth::class]], function($router) {
        $router->post('/', [\controller\Api\OrderController::class, 'create']);
        $router->get('/', [\controller\Api\OrderController::class, 'index']);
        $router->get('/{id}', [\controller\Api\OrderController::class, 'show']);
        $router->put('/{id}/cancel', [\controller\Api\OrderController::class, 'cancel']);
    });

});

return $router;
```
## 9. 前端开发-项目结构

### 9.1 创建前端项目

```bash
# 创建前端项目目录
mkdir -p shop-admin
cd shop-admin

# 初始化项目
npm init -y

# 安装依赖
npm install vue@3 axios vue-router@4
npm install -D vite @vitejs/plugin-vue
```

### 9.2 项目目录结构

```
shop-admin/
├── public/
│   └── index.html
├── src/
│   ├── api/              # API 请求
│   │   ├── index.js     # axios 配置
│   │   ├── auth.js      # 认证接口
│   │   ├── product.js    # 商品接口
│   │   ├── category.js   # 分类接口
│   │   ├── cart.js       # 购物车接口
│   │   └── order.js      # 订单接口
│   ├── components/       # 公共组件
│   │   ├── Header.vue
│   │   ├── Footer.vue
│   │   └── CartIcon.vue
│   ├── views/            # 页面组件
│   │   ├── Home.vue
│   │   ├── Login.vue
│   │   ├── Register.vue
│   │   ├── ProductList.vue
│   │   ├── ProductDetail.vue
│   │   ├── Cart.vue
│   │   ├── Order.vue
│   │   ├── OrderList.vue
│   │   └── OrderDetail.vue
│   ├── router/
│   │   └── index.js     # 路由配置
│   ├── stores/           # 状态管理
│   │   ├── auth.js
│   │   └── cart.js
│   ├── App.vue
│   └── main.js
├── package.json
└── vite.config.js
```

### 9.3 配置文件

#### 9.3.1 vite.config.js

```javascript
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 8081,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      }
    }
  }
})
```

#### 9.3.2 package.json

```json
{
  "name": "shop-admin",
  "version": "1.0.0",
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "vue": "^3.4.0",
    "vue-router": "^4.2.0",
    "axios": "^1.6.0"
  },
  "devDependencies": {
    "vite": "^5.0.0",
    "@vitejs/plugin-vue": "^5.0.0"
  }
}
```

---

## 10. 前端开发-用户与认证

### 10.1 API 请求封装 src/api/index.js

```javascript
import axios from 'axios'
import router from '../router'

const api = axios.create({
  baseURL: '/api',
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json'
  }
})

api.interceptors.request.use(config => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  response => {
    const res = response.data
    if (res.code !== 0 && res.code !== 200) {
      if (res.code === 401) {
        localStorage.removeItem('token')
        localStorage.removeItem('user')
        router.push('/login')
      }
      return Promise.reject(new Error(res.message || 'Request failed'))
    }
    return res
  },
  error => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      router.push('/login')
    }
    return Promise.reject(error)
  }
)

export default api
```

### 10.2 认证接口 src/api/auth.js

```javascript
import api from './index'

export const authApi = {
  register(data) {
    return api.post('/auth/register', data)
  },

  login(data) {
    return api.post('/auth/login', data)
  },

  logout() {
    return api.post('/auth/logout')
  },

  getUser() {
    return api.get('/auth/user')
  },

  updateProfile(data) {
    return api.put('/auth/user', data)
  }
}
```

### 10.3 认证状态管理 src/stores/auth.js

```javascript
import { reactive, computed } from 'vue'

const state = reactive({
  user: JSON.parse(localStorage.getItem('user') || 'null'),
  token: localStorage.getItem('token') || null
})

export const useAuth = () => {
  const isLoggedIn = computed(() => !!state.token)

  const login = (user, token) => {
    state.user = user
    state.token = token
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.setItem('token', token)
  }

  const logout = () => {
    state.user = null
    state.token = null
    localStorage.removeItem('user')
    localStorage.removeItem('token')
  }

  const updateUser = (user) => {
    state.user = { ...state.user, ...user }
    localStorage.setItem('user', JSON.stringify(state.user))
  }

  return {
    state,
    isLoggedIn,
    login,
    logout,
    updateUser
  }
}
```

### 10.4 登录页面 src/views/Login.vue

```vue
<template>
  <div class="login-container">
    <div class="login-box">
      <h2>登录</h2>
      <form @submit.prevent="handleLogin">
        <div class="form-group">
          <label>用户名/邮箱</label>
          <input v-model="form.username" type="text" placeholder="请输入用户名或邮箱" required />
        </div>
        <div class="form-group">
          <label>密码</label>
          <input v-model="form.password" type="password" placeholder="请输入密码" required />
        </div>
        <div class="error" v-if="error">{{ error }}</div>
        <button type="submit" :disabled="loading">
          {{ loading ? '登录中...' : '登录' }}
        </button>
        <p class="switch">
          还没有账号？<router-link to="/register">立即注册</router-link>
        </p>
      </form>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { useAuth } from '../stores/auth'

const router = useRouter()
const { login } = useAuth()

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
    const res = await authApi.login(form)
    login(res.data.user, res.data.token)
    router.push('/')
  } catch (e) {
    error.value = e.message || '登录失败'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  background: #f5f5f5;
}

.login-box {
  background: white;
  padding: 40px;
  border-radius: 8px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
  width: 400px;
}

.login-box h2 {
  text-align: center;
  margin-bottom: 30px;
}

.form-group {
  margin-bottom: 20px;
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
  font-size: 14px;
}

button {
  width: 100%;
  padding: 12px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 16px;
}

button:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.error {
  color: #e74c3c;
  margin-bottom: 15px;
  text-align: center;
}

.switch {
  text-align: center;
  margin-top: 20px;
}

.switch a {
  color: #3498db;
}
</style>
```

### 10.5 注册页面 src/views/Register.vue

```vue
<template>
  <div class="register-container">
    <div class="register-box">
      <h2>注册</h2>
      <form @submit.prevent="handleRegister">
        <div class="form-group">
          <label>用户名</label>
          <input v-model="form.username" type="text" placeholder="请输入用户名" required />
        </div>
        <div class="form-group">
          <label>邮箱</label>
          <input v-model="form.email" type="email" placeholder="请输入邮箱" required />
        </div>
        <div class="form-group">
          <label>密码</label>
          <input v-model="form.password" type="password" placeholder="请输入密码（至少6位）" required />
        </div>
        <div class="form-group">
          <label>确认密码</label>
          <input v-model="form.password_confirmation" type="password" placeholder="请确认密码" required />
        </div>
        <div class="error" v-if="error">{{ error }}</div>
        <button type="submit" :disabled="loading">
          {{ loading ? '注册中...' : '注册' }}
        </button>
        <p class="switch">
          已有账号？<router-link to="/login">立即登录</router-link>
        </p>
      </form>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { useAuth } from '../stores/auth'

const router = useRouter()
const { login } = useAuth()

const form = reactive({
  username: '',
  email: '',
  password: '',
  password_confirmation: ''
})

const loading = ref(false)
const error = ref('')

const handleRegister = async () => {
  if (form.password !== form.password_confirmation) {
    error.value = '两次密码输入不一致'
    return
  }

  error.value = ''
  loading.value = true

  try {
    const res = await authApi.register(form)
    login(res.data.user, res.data.token)
    router.push('/')
  } catch (e) {
    error.value = e.message || '注册失败'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.register-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  background: #f5f5f5;
}

.register-box {
  background: white;
  padding: 40px;
  border-radius: 8px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
  width: 400px;
}

.register-box h2 {
  text-align: center;
  margin-bottom: 30px;
}

.form-group {
  margin-bottom: 20px;
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
  font-size: 14px;
}

button {
  width: 100%;
  padding: 12px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 16px;
}

button:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.error {
  color: #e74c3c;
  margin-bottom: 15px;
  text-align: center;
}

.switch {
  text-align: center;
  margin-top: 20px;
}

.switch a {
  color: #3498db;
}
</style>
```

---

## 11. 前端开发-商品与分类

### 11.1 分类接口 src/api/category.js

```javascript
import api from './index'

export const categoryApi = {
  getList() {
    return api.get('/categories')
  },

  getDetail(id) {
    return api.get(`/categories/${id}`)
  }
}
```

### 11.2 商品接口 src/api/product.js

```javascript
import api from './index'

export const productApi = {
  getList(params) {
    return api.get('/products', { params })
  },

  getDetail(id) {
    return api.get(`/products/${id}`)
  },

  getFeatured() {
    return api.get('/products/featured')
  }
}
```

### 11.3 商品列表页面 src/views/ProductList.vue

```vue
<template>
  <div class="product-list">
    <div class="header">
      <h1>商品列表</h1>
      <div class="search-box">
        <input v-model="keyword" placeholder="搜索商品..." @keyup.enter="search" />
        <button @click="search">搜索</button>
      </div>
    </div>

    <div class="categories">
      <span
        class="category-item"
        :class="{ active: !selectedCategory }"
        @click="selectCategory(null)"
      >
        全部
      </span>
      <span
        v-for="cat in categories"
        :key="cat.id"
        class="category-item"
        :class="{ active: selectedCategory === cat.id }"
        @click="selectCategory(cat.id)"
      >
        {{ cat.name }}
      </span>
    </div>

    <div class="products">
      <div v-if="loading" class="loading">加载中...</div>
      <div v-else-if="products.length === 0" class="empty">暂无商品</div>
      <div v-else class="product-grid">
        <div
          v-for="product in products"
          :key="product.id"
          class="product-card"
          @click="goDetail(product.id)"
        >
          <img :src="product.images[0] || '/placeholder.png'" :alt="product.name" />
          <div class="info">
            <h3>{{ product.name }}</h3>
            <p class="price">¥{{ product.price }}</p>
            <p class="stock">库存: {{ product.stock }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="pagination" v-if="totalPages > 1">
      <button @click="prevPage" :disabled="page === 1">上一页</button>
      <span>{{ page }} / {{ totalPages }}</span>
      <button @click="nextPage" :disabled="page >= totalPages">下一页</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { categoryApi } from '../api/category'
import { productApi } from '../api/product'

const router = useRouter()

const categories = ref([])
const products = ref([])
const loading = ref(false)
const keyword = ref('')
const selectedCategory = ref(null)
const page = ref(1)
const pageSize = ref(12)
const total = ref(0)

const totalPages = computed(() => Math.ceil(total.value / pageSize.value))

import { computed } from 'vue'

const loadCategories = async () => {
  try {
    const res = await categoryApi.getList()
    categories.value = res.data
  } catch (e) {
    console.error('Failed to load categories:', e)
  }
}

const loadProducts = async () => {
  loading.value = true
  try {
    const params = {
      page: page.value,
      page_size: pageSize.value
    }
    if (selectedCategory.value) {
      params.category_id = selectedCategory.value
    }
    if (keyword.value) {
      params.keyword = keyword.value
    }
    const res = await productApi.getList(params)
    products.value = res.data.items
    total.value = res.data.total
  } catch (e) {
    console.error('Failed to load products:', e)
  } finally {
    loading.value = false
  }
}

const selectCategory = (id) => {
  selectedCategory.value = id
  page.value = 1
  loadProducts()
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

const goDetail = (id) => {
  router.push(`/product/${id}`)
}

onMounted(() => {
  loadCategories()
  loadProducts()
})
</script>

<style scoped>
.product-list {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.search-box {
  display: flex;
  gap: 10px;
}

.search-box input {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  width: 200px;
}

.search-box button {
  padding: 8px 16px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.categories {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.category-item {
  padding: 8px 16px;
  background: #ecf0f1;
  border-radius: 20px;
  cursor: pointer;
  transition: all 0.3s;
}

.category-item:hover {
  background: #bdc3c7;
}

.category-item.active {
  background: #3498db;
  color: white;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 20px;
}

.product-card {
  background: white;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  cursor: pointer;
  transition: transform 0.3s;
}

.product-card:hover {
  transform: translateY(-5px);
}

.product-card img {
  width: 100%;
  height: 200px;
  object-fit: cover;
}

.product-card .info {
  padding: 15px;
}

.product-card h3 {
  font-size: 16px;
  margin-bottom: 10px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-card .price {
  color: #e74c3c;
  font-size: 20px;
  font-weight: bold;
}

.product-card .stock {
  color: #7f8c8d;
  font-size: 12px;
  margin-top: 5px;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px;
  margin-top: 30px;
}

.pagination button {
  padding: 8px 16px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.pagination button:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.loading, .empty {
  text-align: center;
  padding: 50px;
  color: #7f8c8d;
}
</style>
```

### 11.4 商品详情页面 src/views/ProductDetail.vue

```vue
<template>
  <div class="product-detail" v-if="product">
    <div class="breadcrumb">
      <router-link to="/">首页</router-link> /
      <router-link :to="`/products?category=${product.category_id}`">
        {{ product.category_name }}
      </router-link> /
      <span>{{ product.name }}</span>
    </div>

    <div class="content">
      <div class="images">
        <img :src="product.images[0] || '/placeholder.png'" :alt="product.name" />
      </div>

      <div class="info">
        <h1>{{ product.name }}</h1>
        <p class="description">{{ product.description }}</p>
        <p class="price">¥{{ product.price }}</p>
        <p class="stock">库存: {{ product.stock }}</p>

        <div class="quantity">
          <span>数量:</span>
          <button @click="quantity > 1 && quantity--">-</button>
          <input v-model.number="quantity" type="number" min="1" :max="product.stock" />
          <button @click="quantity < product.stock && quantity++">+</button>
        </div>

        <div class="actions">
          <button class="btn-buy" @click="handleBuy">立即购买</button>
          <button class="btn-cart" @click="handleAddCart">加入购物车</button>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="loading">加载中...</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { productApi } from '../api/product'
import { cartApi } from '../api/cart'
import { useAuth } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const { isLoggedIn } = useAuth()

const product = ref(null)
const quantity = ref(1)

const loadProduct = async () => {
  try {
    const res = await productApi.getDetail(route.params.id)
    product.value = res.data
  } catch (e) {
    console.error('Failed to load product:', e)
  }
}

const handleBuy = async () => {
  if (!isLoggedIn.value) {
    router.push('/login')
    return
  }
  await handleAddCart()
  router.push('/cart')
}

const handleAddCart = async () => {
  if (!isLoggedIn.value) {
    router.push('/login')
    return
  }
  try {
    await cartApi.add({
      product_id: product.value.id,
      quantity: quantity.value
    })
    alert('已加入购物车')
  } catch (e) {
    alert(e.message || '加入购物车失败')
  }
}

onMounted(() => {
  loadProduct()
})
</script>

<style scoped>
.product-detail {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
}

.breadcrumb {
  margin-bottom: 20px;
  color: #7f8c8d;
}

.breadcrumb a {
  color: #3498db;
  text-decoration: none;
}

.content {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 40px;
}

.images img {
  width: 100%;
  border-radius: 8px;
}

.info h1 {
  font-size: 24px;
  margin-bottom: 15px;
}

.description {
  color: #7f8c8d;
  margin-bottom: 20px;
}

.price {
  color: #e74c3c;
  font-size: 32px;
  font-weight: bold;
  margin-bottom: 10px;
}

.stock {
  color: #7f8c8d;
  margin-bottom: 20px;
}

.quantity {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
}

.quantity button {
  width: 30px;
  height: 30px;
  border: 1px solid #ddd;
  background: white;
  cursor: pointer;
}

.quantity input {
  width: 60px;
  height: 30px;
  text-align: center;
  border: 1px solid #ddd;
}

.actions {
  display: flex;
  gap: 20px;
}

.btn-buy, .btn-cart {
  padding: 12px 30px;
  border: none;
  border-radius: 4px;
  font-size: 16px;
  cursor: pointer;
}

.btn-buy {
  background: #e74c3c;
  color: white;
}

.btn-cart {
  background: #3498db;
  color: white;
}

.loading {
  text-align: center;
  padding: 100px;
}
</style>
```

---

## 12. 前端开发-购物车

### 12.1 购物车接口 src/api/cart.js

```javascript
import api from './index'

export const cartApi = {
  getList() {
    return api.get('/cart')
  },

  add(data) {
    return api.post('/cart', data)
  },

  update(data) {
    return api.put('/cart', data)
  },

  remove(data) {
    return api.delete('/cart', { data })
  },

  clear() {
    return api.delete('/cart/clear')
  }
}
```

### 12.2 购物车状态管理 src/stores/cart.js

```javascript
import { reactive, computed } from 'vue'
import { cartApi } from '../api/cart'

const state = reactive({
  items: [],
  totalCount: 0,
  totalAmount: 0
})

export const useCart = () => {
  const loadCart = async () => {
    try {
      const res = await cartApi.getList()
      state.items = res.data.items
      state.totalCount = res.data.total_count
      state.totalAmount = res.data.total_amount
    } catch (e) {
      console.error('Failed to load cart:', e)
    }
  }

  const addItem = async (productId, quantity = 1) => {
    await cartApi.add({ product_id: productId, quantity })
    await loadCart()
  }

  const updateItem = async (id, quantity) => {
    await cartApi.update({ id, quantity })
    await loadCart()
  }

  const removeItem = async (id) => {
    await cartApi.remove({ id })
    await loadCart()
  }

  const clearCart = async () => {
    await cartApi.clear()
    state.items = []
    state.totalCount = 0
    state.totalAmount = 0
  }

  return {
    state,
    loadCart,
    addItem,
    updateItem,
    removeItem,
    clearCart
  }
}
```

### 12.3 购物车页面 src/views/Cart.vue

```vue
<template>
  <div class="cart-container">
    <h1>购物车</h1>

    <div v-if="cart.state.items.length === 0" class="empty-cart">
      <p>购物车是空的</p>
      <router-link to="/products" class="btn">去购物</router-link>
    </div>

    <div v-else class="cart-content">
      <table class="cart-table">
        <thead>
          <tr>
            <th>商品</th>
            <th>单价</th>
            <th>数量</th>
            <th>小计</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in cart.state.items" :key="item.id">
            <td>
              <div class="product-info">
                <img :src="item.image || '/placeholder.png'" />
                <span>{{ item.name }}</span>
              </div>
            </td>
            <td>¥{{ item.price }}</td>
            <td>
              <div class="quantity-control">
                <button @click="decrease(item)">-</button>
                <input v-model.number="item.quantity" @change="updateQuantity(item)" />
                <button @click="increase(item)">+</button>
              </div>
            </td>
            <td>¥{{ item.subtotal }}</td>
            <td>
              <button class="btn-remove" @click="removeItem(item.id)">删除</button>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="cart-summary">
        <div class="summary-info">
          <p>共 {{ cart.state.totalCount }} 件商品</p>
          <p class="total">合计: ¥{{ cart.state.totalAmount }}</p>
        </div>
        <div class="actions">
          <button class="btn-clear" @click="clearCart">清空购物车</button>
          <button class="btn-checkout" @click="checkout">去结算</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCart } from '../stores/cart'
import { useAuth } from '../stores/auth'

const router = useRouter()
const cart = useCart()
const { isLoggedIn } = useAuth()

const increase = async (item) => {
  if (item.quantity < item.stock) {
    await cart.updateItem(item.id, item.quantity + 1)
  }
}

const decrease = async (item) => {
  if (item.quantity > 1) {
    await cart.updateItem(item.id, item.quantity - 1)
  }
}

const updateQuantity = async (item) => {
  if (item.quantity < 1) {
    item.quantity = 1
  }
  if (item.quantity > item.stock) {
    item.quantity = item.stock
  }
  await cart.updateItem(item.id, item.quantity)
}

const removeItem = async (id) => {
  if (confirm('确定要删除这件商品吗？')) {
    await cart.removeItem(id)
  }
}

const clearCart = async () => {
  if (confirm('确定要清空购物车吗？')) {
    await cart.clearCart()
  }
}

const checkout = () => {
  if (!isLoggedIn.value) {
    router.push('/login')
    return
  }
  router.push('/order')
}

onMounted(() => {
  if (isLoggedIn.value) {
    cart.loadCart()
  }
})
</script>

<style scoped>
.cart-container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
}

.cart-container h1 {
  margin-bottom: 30px;
}

.empty-cart {
  text-align: center;
  padding: 100px;
  background: white;
  border-radius: 8px;
}

.empty-cart p {
  margin-bottom: 20px;
  color: #7f8c8d;
}

.btn {
  display: inline-block;
  padding: 10px 30px;
  background: #3498db;
  color: white;
  text-decoration: none;
  border-radius: 4px;
}

.cart-table {
  width: 100%;
  background: white;
  border-radius: 8px;
  overflow: hidden;
}

.cart-table th,
.cart-table td {
  padding: 15px;
  text-align: center;
  border-bottom: 1px solid #ecf0f1;
}

.cart-table th {
  background: #f8f9fa;
  font-weight: bold;
}

.product-info {
  display: flex;
  align-items: center;
  gap: 10px;
}

.product-info img {
  width: 60px;
  height: 60px;
  object-fit: cover;
  border-radius: 4px;
}

.quantity-control {
  display: flex;
  align-items: center;
  justify-content: center;
}

.quantity-control button {
  width: 28px;
  height: 28px;
  border: 1px solid #ddd;
  background: white;
  cursor: pointer;
}

.quantity-control input {
  width: 50px;
  height: 28px;
  text-align: center;
  border: 1px solid #ddd;
  margin: 0 5px;
}

.btn-remove {
  color: #e74c3c;
  background: none;
  border: none;
  cursor: pointer;
}

.cart-summary {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 20px;
  padding: 20px;
  background: white;
  border-radius: 8px;
}

.total {
  font-size: 24px;
  color: #e74c3c;
  font-weight: bold;
  margin-top: 10px;
}

.actions {
  display: flex;
  gap: 15px;
}

.btn-clear {
  padding: 12px 30px;
  background: #95a5a6;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.btn-checkout {
  padding: 12px 50px;
  background: #e74c3c;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}
</style>
```

---

## 13. 前端开发-订单

### 13.1 订单接口 src/api/order.js

```javascript
import api from './index'

export const orderApi = {
  create(data) {
    return api.post('/orders', data)
  },

  getList(params) {
    return api.get('/orders', { params })
  },

  getDetail(id) {
    return api.get(`/orders/${id}`)
  },

  cancel(id) {
    return api.put(`/orders/${id}/cancel`)
  }
}
```

### 13.2 订单确认页面 src/views/Order.vue

```vue
<template>
  <div class="order-container">
    <h1>确认订单</h1>

    <div class="order-content">
      <div class="receiver-form">
        <h3>收货信息</h3>
        <div class="form-group">
          <label>收货人</label>
          <input v-model="form.receiver_name" type="text" placeholder="请输入收货人姓名" />
        </div>
        <div class="form-group">
          <label>联系电话</label>
          <input v-model="form.receiver_phone" type="tel" placeholder="请输入联系电话" />
        </div>
        <div class="form-group">
          <label>收货地址</label>
          <textarea v-model="form.receiver_address" placeholder="请输入详细地址"></textarea>
        </div>
        <div class="form-group">
          <label>备注</label>
          <textarea v-model="form.remark" placeholder="选填"></textarea>
        </div>
      </div>

      <div class="order-items">
        <h3>商品清单</h3>
        <div class="item" v-for="item in cart.state.items" :key="item.id">
          <img :src="item.image || '/placeholder.png'" />
          <div class="item-info">
            <p class="name">{{ item.name }}</p>
            <p class="price">¥{{ item.price }} x {{ item.quantity }}</p>
          </div>
          <p class="subtotal">¥{{ item.subtotal }}</p>
        </div>

        <div class="total">
          <span>合计:</span>
          <span class="amount">¥{{ cart.state.totalAmount }}</span>
        </div>
      </div>
    </div>

    <div class="submit-section">
      <button class="btn-submit" @click="handleSubmit" :disabled="loading">
        {{ loading ? '提交中...' : '提交订单' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { orderApi } from '../api/order'
import { useCart } from '../stores/cart'

const router = useRouter()
const cart = useCart()

const form = reactive({
  receiver_name: '',
  receiver_phone: '',
  receiver_address: '',
  remark: ''
})

const loading = ref(false)

const handleSubmit = async () => {
  if (!form.receiver_name || !form.receiver_phone || !form.receiver_address) {
    alert('请填写完整的收货信息')
    return
  }

  loading.value = true

  try {
    const res = await orderApi.create(form)
    await cart.loadCart()
    router.push(`/order/${res.data.order_id}`)
  } catch (e) {
    alert(e.message || '订单提交失败')
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.order-container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
}

.order-container h1 {
  margin-bottom: 30px;
}

.order-content {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 30px;
}

.receiver-form,
.order-items {
  background: white;
  padding: 20px;
  border-radius: 8px;
}

.receiver-form h3,
.order-items h3 {
  margin-bottom: 20px;
  padding-bottom: 10px;
  border-bottom: 1px solid #ecf0f1;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  margin-bottom: 5px;
  font-weight: bold;
}

.form-group input,
.form-group textarea {
  width: 100%;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.form-group textarea {
  min-height: 80px;
  resize: vertical;
}

.item {
  display: flex;
  align-items: center;
  padding: 15px 0;
  border-bottom: 1px solid #ecf0f1;
}

.item img {
  width: 60px;
  height: 60px;
  object-fit: cover;
  border-radius: 4px;
  margin-right: 15px;
}

.item-info {
  flex: 1;
}

.item-info .name {
  font-weight: bold;
  margin-bottom: 5px;
}

.item-info .price {
  color: #7f8c8d;
}

.subtotal {
  font-weight: bold;
  color: #e74c3c;
}

.total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 20px;
  font-size: 18px;
}

.total .amount {
  font-size: 24px;
  color: #e74c3c;
  font-weight: bold;
}

.submit-section {
  margin-top: 30px;
  text-align: right;
}

.btn-submit {
  padding: 15px 60px;
  background: #e74c3c;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 18px;
  cursor: pointer;
}

.btn-submit:disabled {
  background: #ccc;
  cursor: not-allowed;
}
</style>
```

### 13.3 订单列表页面 src/views/OrderList.vue

```vue
<template>
  <div class="order-list-container">
    <h1>我的订单</h1>

    <div v-if="loading" class="loading">加载中...</div>
    <div v-else-if="orders.length === 0" class="empty">
      <p>暂无订单</p>
      <router-link to="/products" class="btn">去购物</router-link>
    </div>

    <div v-else class="orders">
      <div v-for="order in orders" :key="order.id" class="order-card">
        <div class="order-header">
          <span class="order-no">订单号: {{ order.order_no }}</span>
          <span class="order-status" :class="statusClass(order.status)">
            {{ statusText(order.status) }}
          </span>
        </div>
        <div class="order-items">
          <div v-for="item in order.items" :key="item.id" class="order-item">
            <span>{{ item.product_name }}</span>
            <span>¥{{ item.price }} x {{ item.quantity }}</span>
            <span>¥{{ item.subtotal }}</span>
          </div>
        </div>
        <div class="order-footer">
          <span class="total">合计: ¥{{ order.total_amount }}</span>
          <div class="actions">
            <router-link :to="`/order/${order.id}`" class="btn-detail">查看详情</router-link>
            <button
              v-if="order.status === 1"
              class="btn-cancel"
              @click="cancelOrder(order.id)"
            >
              取消订单
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="pagination" v-if="totalPages > 1">
      <button @click="prevPage" :disabled="page === 1">上一页</button>
      <span>{{ page }} / {{ totalPages }}</span>
      <button @click="nextPage" :disabled="page >= totalPages">下一页</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { orderApi } from '../api/order'

const orders = ref([])
const loading = ref(false)
const page = ref(1)
const pageSize = ref(10)
const total = ref(0)

const totalPages = computed(() => Math.ceil(total.value / pageSize.value))

const statusText = (status) => {
  const map = {
    1: '待支付',
    2: '已支付',
    3: '已发货',
    4: '已完成',
    5: '已取消'
  }
  return map[status] || '未知'
}

const statusClass = (status) => {
  const map = {
    1: 'pending',
    2: 'paid',
    3: 'shipped',
    4: 'completed',
    5: 'cancelled'
  }
  return map[status] || ''
}

const loadOrders = async () => {
  loading.value = true
  try {
    const res = await orderApi.getList({ page: page.value, page_size: pageSize.value })
    orders.value = res.data.items
    total.value = res.data.total
  } catch (e) {
    console.error('Failed to load orders:', e)
  } finally {
    loading.value = false
  }
}

const cancelOrder = async (id) => {
  if (!confirm('确定要取消这个订单吗？')) return

  try {
    await orderApi.cancel(id)
    loadOrders()
  } catch (e) {
    alert(e.message || '取消失败')
  }
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

onMounted(() => {
  loadOrders()
})
</script>

<style scoped>
.order-list-container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
}

.order-list-container h1 {
  margin-bottom: 30px;
}

.loading,
.empty {
  text-align: center;
  padding: 100px;
  background: white;
  border-radius: 8px;
}

.empty p {
  margin-bottom: 20px;
  color: #7f8c8d;
}

.btn {
  display: inline-block;
  padding: 10px 30px;
  background: #3498db;
  color: white;
  text-decoration: none;
  border-radius: 4px;
}

.orders {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.order-card {
  background: white;
  border-radius: 8px;
  padding: 20px;
}

.order-header {
  display: flex;
  justify-content: space-between;
  padding-bottom: 15px;
  border-bottom: 1px solid #ecf0f1;
}

.order-no {
  font-weight: bold;
}

.order-status {
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
}

.order-status.pending {
  background: #fff3cd;
  color: #856404;
}

.order-status.paid {
  background: #d4edda;
  color: #155724;
}

.order-status.shipped {
  background: #cce5ff;
  color: #004085;
}

.order-status.completed {
  background: #d4edda;
  color: #155724;
}

.order-status.cancelled {
  background: #f8d7da;
  color: #721c24;
}

.order-items {
  padding: 15px 0;
}

.order-item {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  color: #7f8c8d;
}

.order-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-top: 15px;
  border-top: 1px solid #ecf0f1;
}

.total {
  font-size: 18px;
  font-weight: bold;
  color: #e74c3c;
}

.actions {
  display: flex;
  gap: 10px;
}

.btn-detail {
  padding: 8px 20px;
  background: #3498db;
  color: white;
  text-decoration: none;
  border-radius: 4px;
}

.btn-cancel {
  padding: 8px 20px;
  background: #e74c3c;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px;
  margin-top: 30px;
}

.pagination button {
  padding: 8px 16px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.pagination button:disabled {
  background: #ccc;
  cursor: not-allowed;
}
</style>
```

---

## 14. 测试与运行

### 14.1 启动后端服务

```bash
# 启动后端（8080端口）
cd shop-api
php bin/console serve 8080

# 或使用原生 PHP
php -S localhost:8080 -t public
```

### 14.2 启动前端服务

```bash
# 启动前端（8081端口）
cd shop-admin
npm run dev
```

### 14.3 访问系统

- 前端：http://localhost:8081
- 后端 API：http://localhost:8080/api

### 14.4 API 测试示例

```bash
# 注册用户
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"username":"test","email":"test@example.com","password":"123456"}'

# 登录
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"test","password":"123456"}'

# 获取商品列表（需要带上 token）
curl http://localhost:8080/api/products \
  -H "Authorization: Bearer <your_token>"
```

### 14.5 完整功能测试流程

1. **用户注册/登录**
   - 访问 http://localhost:8081/register 注册账号
   - 访问 http://localhost:8081/login 登录账号

2. **浏览商品**
   - 首页查看推荐商品
   - 进入商品列表页面，按分类筛选
   - 点击商品进入详情页

3. **购物车操作**
   - 在商品详情页点击"加入购物车"
   - 进入购物车页面，可以修改数量或删除商品

4. **下单流程**
   - 进入购物车，点击"去结算"
   - 填写收货信息
   - 提交订单

5. **订单管理**
   - 进入"我的订单"页面
   - 查看订单详情
   - 对未支付订单可以取消

---

## 附录：完整路由配置 src/router/index.js

```javascript
import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '../stores/auth'

const routes = [
  {
    path: '/',
    name: 'Home',
    component: () => import('../views/Home.vue')
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/Login.vue')
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('../views/Register.vue')
  },
  {
    path: '/products',
    name: 'ProductList',
    component: () => import('../views/ProductList.vue')
  },
  {
    path: '/product/:id',
    name: 'ProductDetail',
    component: () => import('../views/ProductDetail.vue')
  },
  {
    path: '/cart',
    name: 'Cart',
    component: () => import('../views/Cart.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/order',
    name: 'Order',
    component: () => import('../views/Order.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/orders',
    name: 'OrderList',
    component: () => import('../views/OrderList.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/order/:id',
    name: 'OrderDetail',
    component: () => import('../views/OrderDetail.vue'),
    meta: { requiresAuth: true }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to, from, next) => {
  const { isLoggedIn } = useAuth()

  if (to.meta.requiresAuth && !isLoggedIn.value) {
    next('/login')
  } else {
    next()
  }
})

export default router
```

---

教程完成！
