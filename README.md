<div align="center">

<img src="docs/assets/logo.png" alt="LightPHP Logo" width="140" />

# LightPHP Framework

**轻量 · 高效 · 现代化**

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-22c55e?style=for-the-badge)](LICENSE)
[![Version](https://img.shields.io/badge/version-2.6.0-8b5cf6?style=for-the-badge)](CHANGELOG.md)
[![Tests](https://img.shields.io/badge/tests-300%2B-06b6d4?style=for-the-badge&logo=checkmarx)](tests/run_tests.php)
[![Security](https://img.shields.io/badge/audited-6_rounds-f43f5e?style=for-the-badge&logo=shield)](CHANGELOG.md)

受 ThinkPHP / Laravel 启发的现代化 PHP 框架，零依赖，可商用。  
适合 API、微服务、中小型应用与学习研究。

</div>

---

## ✨ 核心特性

<table>
<tr>
<td width="50%">

### 🏗️ 架构
- **MVC 分层** — Controller / Model / View 清晰解耦
- **IoC 容器** — 自动依赖注入，**PSR-11** 兼容
- **中间件管道** — 洋葱模型，灵活的请求生命周期
- **事件系统** — 解耦业务逻辑，支持通配符监听
- **服务提供者** — 标准化应用启动流程

</td>
<td width="50%">

### 🛠️ 功能
- **ORM + QueryBuilder** — 安全参数化查询
- **Schema / Migration** — 数据库迁移与版本控制
- **关联关系** — 一对一、一对多、关联预加载
- **多种模板** — 原生 PHP / Blade / Smarty
- **多驱动缓存** — File / Redis / Memcached + 标签

</td>
</tr>
<tr>
<td width="50%">

### 🔒 安全
- **CSRF 防护** — 令牌验证 + 路由白名单
- **XSS 防护** — Blade 默认转义 + JSON 编码
- **SQL 注入防护** — 全参数化绑定 + 列名白名单
- **路径遍历防护** — `realpath` 双重校验
- **会话安全** — `httponly` + `samesite` + HTTPS 探测

</td>
<td width="50%">

### ⚡ 工程
- **零依赖** — 仅需 PHP 8.0+，不强制安装 Composer
- **代码生成** — `make:model` / `make:controller` / `make:middleware`
- **配置缓存** — 生产环境启动加速
- **API 文档** — 自动生成 OpenAPI 格式
- **300+ 单元测试** — 全核心组件覆盖

</td>
</tr>
</table>

---

## 📦 环境要求

- PHP **8.0+**（完全兼容 8.4 / 8.5）
- 扩展：`pdo`、`mbstring`、`openssl`、`json`、`fileinfo`
- 可选扩展：`redis`、`memcached`、`gd`（验证码）、`curl`

---

## 🚀 5 分钟上手

### 1. 启动开发服务器

```bash
git clone https://github.com/yourname/lightphp.git
cd lightphp
php bin/console serve          # 默认 http://localhost:8080
php bin/console serve 9000     # 自定义端口
```

### 2. 一个最小的 Hello World

```php
// app/route/web.php
use core\Router;
use controller\IndexController;

$router = new Router();
$router->get('/', [IndexController::class, 'index']);
$router->get('/hello/{name}', fn($name) => "Hello, {$name}!");

return $router;
```

```php
// app/controller/IndexController.php
namespace controller;

use core\Controller;
use core\Response;

class IndexController extends Controller
{
    public function index(): Response
    {
        return $this->json(['message' => 'Welcome to LightPHP']);
    }
}
```

### 3. 一个最小的模型

```php
namespace model;

use core\Model;

class User extends Model
{
    protected string $table = 'users';
    protected array  $fillable = ['name', 'email', 'password'];
    protected array  $hidden   = ['password'];
    protected array  $casts    = ['created_at' => 'datetime'];
}
```

```php
$user  = User::create(['name' => 'Tom', 'email' => 'tom@x.com', 'password' => 'secret']);
$users = User::where('status', 1)->orderBy('id', 'desc')->get();
$user  = User::find(1);
```

### 4. 跑通测试

```bash
php bin/console test
```

---

## 📂 项目结构

```
lightphp/
├── app/                          # 应用代码
│   ├── cache/                    #   缓存驱动（File / Redis / Memcached / Tag）
│   ├── config/                   #   配置文件
│   ├── controller/               #   控制器
│   ├── core/                     #   框架核心
│   │   ├── console/              #     CLI 命令系统
│   │   ├── contract/             #     接口契约（PSR-11 / Cache / Logger）
│   │   ├── exception/            #     异常类
│   │   └── traits/               #     复用 trait
│   ├── db/                       #   数据库（Connection / QueryBuilder / Schema / Migration）
│   ├── log/                      #   日志
│   ├── middleware/               #   中间件（CORS / CSRF / Throttle / OutputCache）
│   ├── model/                    #   模型
│   ├── route/                    #   路由定义
│   ├── traits/                   #   业务 trait
│   └── view/                     #   视图与模板引擎
├── bin/
│   └── console                   #   CLI 入口
├── database/
│   └── migrations/               #   迁移文件
├── docs/                         #   文档
│   └── assets/                   #     静态资源（logo 等）
├── public/                       #   Web 入口（nginx/apache 指向）
├── storage/                      #   运行时产物（缓存 / 日志 / session）
└── tests/                        #   单元测试
```

> 💡 **使用提示**
> - 业务代码写在 `app/controller/`、`app/model/`、`app/route/`、`app/middleware/` 中
> - `app/core/` 是框架核心，请勿修改
> - Web 服务器根目录请指向 `public/`
> - `storage/` 目录需 Web 进程可写

---

## 🔐 安全审计

LightPHP 已完成 **6 轮** 系统性商用级代码审查，累计修复 **150+** 处问题。

### 累计修复统计

| 轮次 | 修复问题 | 关键改进 |
|:----:|:--------:|---------|
| 1 | ~40 | SQL 注入、会话安全、文件上传、缓存签名 |
| 2 | 28 | 接口合规、并发安全、状态隔离 |
| 3 | ~20 | CORS、CSRF、Blade XSS、Schema 安全 |
| 4 | ~30 | 运行时 Bug、缓存原子性、路由缓存 |
| 5 | ~25 | SQL 注入、文件缓存、Redis 分布式锁 |
| 6 | ~17 | Router / Generator / Collection / Session |
| **合计** | **150+** | 全方位商用级强化 |

### 关键安全特性

- ✅ **SQL 注入防护** — QueryBuilder 全参数化 + 列名白名单
- ✅ **XSS 防护** — Blade 默认转义 + `@json` 安全标志
- ✅ **CSRF 防护** — 中间件 + 令牌 + 路由白名单
- ✅ **路径遍历防护** — `realpath` 双重校验
- ✅ **会话安全** — `httponly` + `samesite=Lax` + HTTPS 自动探测
- ✅ **加密** — AES-256-GCM 认证加密 + bcrypt 哈希
- ✅ **缓存投毒防护** — HMAC 签名 + SHA-256 缓存键
- ✅ **CORS** — 通配符与凭证冲突检测
- ✅ **资源管理** — `try-finally` 保证资源释放

详细变更记录见 [CHANGELOG.md](CHANGELOG.md)

---

## 🧪 测试

```bash
php bin/console test
```

300+ 单元测试覆盖所有核心组件：

| 模块 | 内容 |
|------|------|
| Router | 路由注册、参数提取、分组、中间件链 |
| Container | 绑定、解析、单例、PSR-11 接口 |
| Request / Response | 输入获取、JSON 响应、状态码 |
| Model / ORM | CRUD、关联、类型转换、序列化 |
| QueryBuilder | 查询构建、参数绑定、聚合、安全 |
| Schema / Migration | 建表建列、修饰符、外键 |
| EventDispatcher | 监听、触发、优先级、通配符 |
| Collection | map / filter / pluck / sortBy / sum 等 30+ 方法 |
| Blade 模板 | 指令编译、布局继承、缓存 |
| Middleware | CORS / Throttle / CSRF / OutputCache |
| Console | 签名解析、参数绑定、表格输出 |
| Session / Cookie | 读写、Flash、SameSite |
| Validate | 规则校验、自定义消息 |
| Hash / Encrypt | bcrypt + AES-256-GCM |
| FileCache | 读写、过期、签名验证 |
| Logger | PSR-3 级别、上下文插值 |
| Upload | MIME 验证、路径安全 |
| Facade | 代理访问、FacadeAccessor |
| Generator | 代码生成、文件输出 |

---

## 📖 开发文档

| 文档 | 说明 |
|------|------|
| [开发指南](docs/guide.md) | 框架完整教程，从零开始构建应用 |
| [快速开始](docs/quick-start.md) | 5 分钟核心概念 |
| [API 文档](docs/api.md) | 所有类与方法的 API 参考 |
| [电商教程](docs/ecommerce-full-tutorial.md) | 完整电商系统开发 |
| [后台管理教程](docs/admin-panel-tutorial.md) | 后台管理系统开发 |
| [测试指南](docs/testing-guide.md) | 单元测试编写与运行 |
| [更新日志](CHANGELOG.md) | 历次版本变更与安全加固 |

---

## 🛠️ CLI 命令一览

```bash
php bin/console serve [port]            # 启动开发服务器
php bin/console test                    # 运行单元测试
php bin/console config                  # 查看配置概览
php bin/console config:show <section>   # 查看指定节配置
php bin/console config:cache            # 生成配置缓存
php bin/console config:clear            # 清除配置缓存
php bin/console cache:clear             # 清空应用缓存
php bin/console migrate                 # 执行数据库迁移
php bin/console migrate:rollback [n]    # 回滚最近 n 次迁移
php bin/console make:model <Name>       # 生成模型
php bin/console make:controller <Name>  # 生成控制器
php bin/console make:middleware <Name>  # 生成中间件
php bin/console make:migration <Name>   # 生成迁移文件
```

---

## 🤝 贡献

欢迎 Issue 和 PR！请遵循以下原则：

1. 所有代码必须保持 `declare(strict_types=1);`
2. 新增功能请附单元测试
3. 公共方法必须有 PHPDoc 与类型声明
4. 遵循 PSR-12 编码规范

---

## 📄 License

[MIT License](LICENSE) © LightPHP Contributors
