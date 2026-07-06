# LightPHP 安全指南

本文档描述 LightPHP 框架内置的安全机制和生产环境安全最佳实践。

---

## 目录

1. [SQL 注入防护](#1-sql-注入防护)
2. [XSS 防护](#2-xss-防护)
3. [CSRF 防护](#3-csrf-防护)
4. [文件上传安全](#4-文件上传安全)
5. [加密与哈希](#5-加密与哈希)
6. [会话安全](#6-会话安全)
7. [CORS 配置](#7-cors-配置)
8. [限流防护](#8-限流防护)
9. [路径遍历防护](#9-路径遍历防护)
10. [生产环境安全清单](#10-生产环境安全清单)

---

## 1. SQL 注入防护

LightPHP 全线使用 PDO 参数化查询，杜绝 SQL 注入：

```php
// QueryBuilder 自动参数化
$users = $db->table('users')
    ->where('email', '=', $userInput)  // 自动绑定参数
    ->get();

// 原生 SQL 也使用参数绑定
$results = $db->query('SELECT * FROM users WHERE id = ?', [$id]);
```

**禁止**拼接 SQL 字符串。如需动态列名/表名，使用白名单校验：

```php
$allowedSort = ['name', 'created_at', 'id'];
$sort = in_array($_GET['sort'] ?? 'id', $allowedSort, true) ? $_GET['sort'] : 'id';
```

---

## 2. XSS 防护

### 视图自动转义

原生 PHP 视图和 Blade 模板默认 HTML 转义输出：

```php
// View 自动转义
echo $view->render('profile', ['name' => $userInput]);
```

Blade 中 `{{ $var }}` 自动转义，`{!! $var !!}` 输出原始 HTML（仅在信任内容时使用）。

### 手动转义

```php
echo htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8');
```

### Response JSON

`Response::json()` 自动设置 `Content-Type: application/json`，浏览器不会解析为 HTML。

---

## 3. CSRF 防护

框架内置 CSRF 中间件，自动验证令牌：

```php
// 路由中应用 CSRF 中间件
$router->group(['middleware' => ['csrf']], function($router) {
    $router->post('/form', [FormController::class, 'store']);
});
```

表单中生成令牌：

```html
<input type="hidden" name="_token" value="<?= csrf_token() ?>">
```

API 场景可通过 Header 传递令牌：

```
X-CSRF-TOKEN: <token>
```

---

## 4. 文件上传安全

`Upload` 类内置多重安全防护：

```php
$upload = Upload::file('avatar');
if ($upload) {
    $upload->allowedTypes(['image/jpeg', 'image/png'])
           ->allowedExtensions(['jpg', 'png'])
           ->maxSize(2048 * 1024)  // 2MB
           ->path('/uploads/avatars/');

    $path = $upload->save();
    if ($path === null) {
        echo $upload->getError();
    }
}
```

**内置防护**：
- 危险扩展名黑名单（`.php`、`.phtml`、`.phar`、`.html`、`.svg` 等），始终拒绝，不可通过白名单绕过
- 双扩展名检测（`file.pdf.php` 会被拒绝）
- 文件名重命名为随机 hex，防止路径猜测
- `is_uploaded_file()` 校验确保为合法上传
- 路径遍历防护（`..` 被移除，`realpath` 校验）
- MIME 类型通过 `finfo` 检测（不信任客户端 `Content-Type`）

---

## 5. 加密与哈希

### AES-256-GCM 认证加密

```php
// 加密
$encrypted = Hash::encrypt('sensitive data');

// 解密
$decrypted = Hash::decrypt($encrypted);
```

使用 AES-256-GCM 认证加密，密钥来自 `APP_KEY`。

### 密码哈希

```php
// 哈希密码（bcrypt）
$hash = Hash::make('password123');

// 验证密码
if (Hash::verify('password123', $hash)) {
    // 登录成功
}
```

**重要**：`APP_KEY` 为空时框架会抛出异常，防止无密钥加密。

---

## 6. 会话安全

框架自动配置安全会话设置：

| 配置 | 值 | 说明 |
|------|------|------|
| `session.use_strict_mode` | 1 | 防止会话固定攻击 |
| `session.cookie_httponly` | 1 | JS 不可访问 Cookie |
| `session.cookie_samesite` | Strict | 防 CSRF |

**生产环境额外配置**：
```ini
session.cookie_secure = 1  ; HTTPS 环境下必须开启
```

---

## 7. CORS 配置

CORS 中间件防止跨域请求滥用：

```php
$cors = new Cors([
    'allowed_origins' => ['https://your-domain.com'],
    'allowed_methods' => ['GET', 'POST'],
    'allowed_headers' => ['Content-Type', 'Authorization'],
    'supports_credentials' => true,
]);
```

> **安全约束**：`allowed_origins: ['*']` 与 `supports_credentials: true` 不能同时使用（违反 W3C 规范），框架会抛出异常。

---

## 8. 限流防护

Throttle 中间件防止暴力破解和 DDoS：

```php
// 路由级限流：每分钟最多 60 次
$router->group(['middleware' => ['throttle:60,1']], function($router) {
    $router->get('/api/data', [ApiController::class, 'data']);
});

// 登录接口限流：每分钟 5 次
$router->post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
```

---

## 9. 路径遍历防护

框架在多处实施路径遍历防护：

- **Blade 模板**：`realpath()` + 目录前缀校验，防止 `/view-evil` 绕过
- **文件上传**：`..` 移除 + `realpath()` 校验，确保路径在 `public/` 内
- **PSR-4 加载器**：`realpath()` 校验，防止类名注入访问预期目录外文件
- **Response download()**：白名单拒绝所有带 scheme 的路径（如 `php://filter`）

---

## 10. 生产环境安全清单

- [ ] `APP_DEBUG` 设为 `false`
- [ ] `APP_KEY` 已设置（32 字节随机值）
- [ ] `display_errors` 设为 `Off`
- [ ] `session.cookie_secure` 设为 `1`（HTTPS 环境）
- [ ] 文档根目录指向 `public/`，`.env` 不可 Web 访问
- [ ] CORS 白名单不含通配符 `*`（使用凭证时）
- [ ] 文件上传目录不可执行 PHP（Nginx 配置 `location ~ \.php$` 限定）
- [ ] 登录/敏感接口启用 Throttle 限流
- [ ] 数据库用户使用最小权限原则
- [ ] 定期更新 PHP 版本和扩展
