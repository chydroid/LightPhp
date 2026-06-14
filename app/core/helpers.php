<?php
declare(strict_types=1);

namespace {
    if (!function_exists('env')) {
        function env(string $key, mixed $default = null): mixed
        {
            return \core\Env::get($key, $default);
        }
    }

    if (!function_exists('collect')) {
        function collect(array $items = []): \core\Collection
        {
            return new \core\Collection($items);
        }
    }

    if (!function_exists('value')) {
        function value(mixed $value): mixed
        {
            return $value instanceof \Closure ? $value() : $value;
        }
    }

    if (!function_exists('route')) {
        function route(string $name, array $parameters = []): string
        {
            $app = \core\Application::getInstance();
            if ($app === null) {
                throw new \RuntimeException('Application not initialized.');
            }
            return $app->getRouter()->route($name, $parameters);
        }
    }
}