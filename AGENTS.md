# AGENTS.md

## Project Overview

LightPHP — zero-dependency PHP 8.0+ MVC framework. No Composer autoload at runtime; uses a custom PSR-4 loader (`app/core/Loader.php`).

## Critical: Do Not Modify

`app/core/` is framework internals. Business logic goes in `app/controller/`, `app/model/`, `app/route/`, `app/middleware/`.

## Entry Points

- **Web**: `public/index.php` — defines path constants, boots `Application`, calls `run()`
- **CLI**: `bin/console` — all commands (serve, test, migrate, make:*, config:*)
- **Routes**: `app/route/web.php` and `app/route/route.php`

## Commands

```bash
php bin/console serve [port]           # Dev server (default 8080)
php bin/console test                   # Run all tests
php bin/console migrate                # Run migrations
php bin/console config:cache           # Cache config for production
php bin/console make:model <Name>      # Scaffold a new model class
```

## Testing

No PHPUnit. Custom test runner at `tests/run_tests.php`.

```bash
php bin/console test                   # Runs all tests
php tests/run_tests.php                # Direct run
```

300+ tests covering Router, Container, ORM, Blade, Cache, Collection, etc. Add new tests as `$runner->run('Name', function($t) { ... })` in `tests/run_tests.php`. The `$t` object has: `assertEquals`, `assertIsString`, `assertTrue`, `assertThrows`, `assertContains`, etc.

Schema tests use SQLite in-memory (`new \PDO('sqlite::memory:')`). Skip if driver unavailable:
```php
if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
    $t->assertTrue(true, 'SQLite driver not available, test skipped');
    return;
}
```

## Autoloading

Custom PSR-4 loader in `app/core/Loader.php`. Namespace-to-directory mapping:

| Namespace | Directory |
|-----------|-----------|
| `core\` | `app/core/` |
| `controller\` | `app/controller/` |
| `model\` | `app/model/` |
| `db\` | `app/db/` |
| `cache\` | `app/cache/` |
| `middleware\` | `app/middleware/` |
| `view\` | `app/view/` |
| `traits\` | `app/traits/` |

Adding a new top-level namespace requires updating both `Loader.php::$prefixes` and `composer.json autoload`.

## Code Conventions

- Every PHP file: `<?php declare(strict_types=1);`
- PSR-12 style
- Controllers extend `core\Controller`, return `\core\Response`
- Models extend `model\Model`, define `$table`, `$fillable`, `$primaryKey`
- Middleware: class with `handle($request, callable $next)` method
- Routes in `app/route/web.php` using `$router->get()`, `$router->group()`, etc.
- Use `core\Container` for DI (PSR-11 compatible)
- Config files in `app/config/` return arrays, use `env()` helper for env vars

## Key Architecture

- **IoC Container** (`core\Container`) — auto-resolves class dependencies
- **Pipeline** (`core\Pipeline`) — onion-model middleware execution
- **EventDispatcher** (`core\EventDispatcher`) — wildcard support, priority listeners
- **Macroable** (`core\traits\Macroable`) — extend Request/Response at runtime via `::macro()`
- **SoftDelete** (`traits\SoftDelete`) — model soft deletes
- **HasModelEvents** (`traits\HasModelEvents`) — model lifecycle hooks (creating, created, updating, etc.)

## Environment

- `.env` file optional; config defaults in `app/config/`
- `APP_KEY` required for encryption/hash features
- `storage/` must be writable by web process
- Database config: `app/config/database.php` (defaults to MySQL)
