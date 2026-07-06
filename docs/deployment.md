# LightPHP 部署指南

本文档详细描述将 LightPHP 部署到生产环境的完整流程。

---

## 目录

1. [环境要求](#1-环境要求)
2. [获取代码](#2-获取代码)
3. [配置](#3-配置)
4. [Web 服务器配置](#4-web-服务器配置)
5. [权限设置](#5-权限设置)
6. [PHP 配置](#6-php-配置)
7. [配置缓存](#7-配置缓存)
8. [HTTPS](#8-https)
9. [部署检查清单](#9-部署检查清单)

---

## 1. 环境要求

- PHP 8.0+
- 必要扩展：PDO（MySQL 或 SQLite）、openssl、mbstring
- 可选扩展：redis、memcached、gd（验证码）、json
- Web 服务器：Nginx + PHP-FPM（推荐）或 Apache

---

## 2. 获取代码

```bash
git clone https://github.com/chydroid/LightPhp.git /var/www/lightphp
cd /var/www/lightphp
```

无需 Composer — 框架零依赖，使用自定义 PSR-4 自动加载器。

---

## 3. 配置

### 3.1 应用密钥

**必须设置**。用于 AES-256-GCM 加密/解密功能：

```bash
php -r "echo 'base64:' . base64_encode(random_bytes(32));"
```

### 3.2 环境变量

复制 `.env.example` 为 `.env` 并填写生产配置：

```bash
cp .env.example .env
```

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:your-generated-key
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

也可直接编辑 `app/config/` 下的配置文件，无需 `.env`。

### 3.3 数据库配置

`app/config/database.php` 支持 MySQL 和 SQLite：

```php
// 使用 SQLite（轻量场景）
'default' => 'sqlite',

// 使用 MySQL（生产推荐）
'default' => 'mysql',
```

MySQL 连接支持 SSL/TLS、持久连接、连接超时等选项，见配置文件注释。

---

## 4. Web 服务器配置

### Nginx + PHP-FPM（推荐）

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/lightphp/public;
    index index.php;

    # 阻止访问敏感文件
    location ~ /\.(env|git|htaccess) {
        deny all;
    }

    # 静态资源直接返回
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 1d;
        try_files $uri =404;
    }

    # 所有请求路由到 index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Apache

确保启用了 `mod_rewrite`，在项目根目录创建 `.htaccess`：

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

`public/` 目录下已有 `.htaccess` 处理前端控制器。

> **重要**：文档根目录必须指向 `public/`，不能指向项目根目录，否则 `.env` 和 `app/` 源码会暴露。

---

## 5. 权限设置

`storage/` 目录需要 Web 服务器用户可写权限：

```bash
chown -R www-data:www-data storage
chmod -R 755 storage
```

包含子目录：`cache/`、`log/`、`temp/`、`uploads/`、`views/`。

---

## 6. PHP 配置

生产环境 `php.ini` 建议：

```ini
; 错误处理
display_errors = Off
log_errors = On
error_log = /var/log/php_errors.log

; 性能
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0  ; 生产环境关闭时间戳校验

; 上传
upload_max_filesize = 10M
post_max_size = 12M

; 会话
session.cookie_httponly = 1
session.cookie_secure = 1    ; HTTPS 环境下开启
session.cookie_samesite = Strict
```

---

## 7. 配置缓存

生产环境运行配置缓存以提升性能：

```bash
php bin/console config:cache
```

这会将所有配置文件合并为单个缓存文件，减少每次请求的文件 I/O。修改配置后需重新运行。

---

## 8. HTTPS

生产环境应配置 TLS 证书。使用 Let's Encrypt 免费证书：

```bash
sudo certbot --nginx -d your-domain.com
```

在 Nginx 中强制 HTTPS 重定向：

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}
```

---

## 9. 部署检查清单

部署前逐项确认：

- [ ] **APP_KEY** 已设置（非空）
- [ ] **APP_DEBUG** 设为 `false`
- [ ] **APP_ENV** 设为 `production`
- [ ] **数据库凭证** 已正确配置
- [ ] **storage/** 目录权限正确（Web 服务器可写）
- [ ] **文档根目录** 指向 `public/`
- [ ] **display_errors** 设为 `Off`
- [ ] **OPcache** 已启用
- [ ] **HTTPS** 已配置
- [ ] **config:cache** 已运行
- [ ] **session.cookie_secure** 设为 `1`（HTTPS 环境）
- [ ] `.env` 文件不可通过 Web 访问

运行测试确认框架正常：

```bash
php bin/console test
```
