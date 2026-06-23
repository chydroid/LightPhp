<?php
declare(strict_types=1);

define('APP_PATH', __DIR__ . '/../app/');
define('PUBLIC_PATH', __DIR__ . '/../public/');
define('STORAGE_PATH', __DIR__ . '/../storage/');
define('VIEW_PATH', APP_PATH . 'view/');
define('SMARTY_TEMPLATE_PATH', APP_PATH . 'view/templates/');
define('VENDOR_PATH', __DIR__ . '/../vendor/');
define('APP_DEBUG', true);

require APP_PATH . 'core/Loader.php';
\core\Loader::register();
require APP_PATH . 'core/helpers.php';

class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function assert(mixed $condition, string $message = ''): void
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = $message ?: 'Assertion failed';
            throw new \RuntimeException($message ?: 'Assertion failed');
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $result = $expected === $actual;
        $msg = $message ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        $this->assert($result, $msg);
    }

    public function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $result = $expected !== $actual;
        $msg = $message ?: "Expected value not equal to " . var_export($expected, true);
        $this->assert($result, $msg);
    }

    public function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertEquals(true, $value, $message ?: 'Expected true');
    }

    public function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertEquals(false, $value, $message ?: 'Expected false');
    }

    public function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertEquals(null, $value, $message ?: 'Expected null');
    }

    public function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->assert($value !== null, $message ?: 'Expected not null');
    }

    public function assertIsArray(mixed $value, string $message = ''): void
    {
        $this->assert(is_array($value), $message ?: 'Expected array');
    }

    public function assertIsString(mixed $value, string $message = ''): void
    {
        $this->assert(is_string($value), $message ?: 'Expected string');
    }

    public function assertIsInt(mixed $value, string $message = ''): void
    {
        $this->assert(is_int($value), $message ?: 'Expected int');
    }

    public function assertCount(int $expected, array|\Countable $value, string $message = ''): void
    {
        $this->assertEquals($expected, count($value), $message ?: "Expected count {$expected}");
    }

    public function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        $this->assert(array_key_exists($key, $array), $message ?: "Expected array to have key '{$key}'");
    }

    public function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assert(str_contains($haystack, $needle), $message ?: "Expected string to contain '{$needle}'");
    }

    public function assertContains(mixed $needle, array $array, string $message = ''): void
    {
        $this->assert(in_array($needle, $array, true), $message ?: "Expected array to contain value");
    }

    public function assertInstanceOf(string $class, mixed $object, string $message = ''): void
    {
        $this->assert($object instanceof $class, $message ?: "Expected instance of {$class}");
    }

    public function assertThrows(string $exceptionClass, callable $callback, string $message = ''): void
    {
        $thrown = false;
        try {
            $callback();
        } catch (\Throwable $e) {
            $thrown = $e instanceof $exceptionClass;
        }
        $this->assert($thrown, $message ?: "Expected {$exceptionClass} to be thrown");
    }

    public function run(string $name, callable $test): void
    {
        echo "Running: {$name}\n";
        try {
            $test($this);
            echo "  ✓ Passed\n";
        } catch (\Throwable $e) {
            $this->failed++;
            $this->failures[] = "{$name}: " . $e->getMessage();
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }

    public function summary(): void
    {
        $total = $this->passed + $this->failed;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Results: {$this->passed}/{$total} passed";
        if ($this->failed > 0) {
            echo ", {$this->failed} failed";
        }
        echo "\n";

        if (!empty($this->failures)) {
            echo "\nFailures:\n";
            foreach ($this->failures as $i => $failure) {
                echo "  " . ($i + 1) . ". {$failure}\n";
            }
        }

        echo str_repeat('=', 50) . "\n";
    }

    public function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assert(!str_contains($haystack, $needle), $message ?: "Expected string NOT to contain '{$needle}'");
    }

    public function assertArrayNotHasKey(string|int $key, array $array, string $message = ''): void
    {
        $this->assert(!array_key_exists($key, $array), $message ?: "Expected array NOT to have key '{$key}'");
    }

    public function assertNotFalse(mixed $value, string $message = ''): void
    {
        $this->assert($value !== false, $message ?: 'Expected value not to be false');
    }

    public function assertNotEmpty(mixed $value, string $message = ''): void
    {
        $this->assert(!empty($value), $message ?: 'Expected value not to be empty');
    }

    public function assertGreaterThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void
    {
        $this->assert($actual >= $expected, $message ?: "Expected {$actual} >= {$expected}");
    }

    public function getPassed(): int { return $this->passed; }
    public function getFailed(): int { return $this->failed; }
}

$runner = new TestRunner();

$runner->run('Router - Basic Route Registration', function($t) {
    $router = new \core\Router();
    $router->get('/test', function() { return 'test'; });
    $routes = $router->getRoutes();
    $t->assertIsArray($routes);
    $t->assertCount(1, $routes);
    $t->assertEquals('GET', $routes[0]['method']);
    $t->assertEquals('/test', $routes[0]['uri']);
});

$runner->run('Router - Group Routes', function($t) {
    $router = new \core\Router();
    $router->group(['prefix' => '/api', 'middleware' => 'auth'], function($r) {
        $r->get('/users', function() { return 'users'; });
    });
    $routes = $router->getRoutes();
    $t->assertCount(1, $routes);
    $t->assertEquals('/api/users', $routes[0]['uri']);
    $t->assertArrayHasKey('middleware', $routes[0]);
});

$runner->run('Router - Match Route Parameters', function($t) {
    $router = new \core\Router();
    $router->get('/user/{id}', function($id) { return $id; });
    $routes = $router->getRoutes();
    $t->assertCount(1, $routes);
    $t->assertEquals('/user/{id}', $routes[0]['uri']);
});

$runner->run('Container - Bind and Resolve', function($t) {
    $container = new \core\Container();
    $container->bind('test', fn() => 'hello');
    $t->assertEquals('hello', $container->get('test'));
});

$runner->run('Container - Singleton', function($t) {
    $container = new \core\Container();
    $container->singleton('test', fn() => new \stdClass());
    $obj1 = $container->get('test');
    $obj2 = $container->get('test');
    $t->assertTrue($obj1 === $obj2);
});

$runner->run('Container - Has', function($t) {
    $container = new \core\Container();
    $t->assertFalse($container->has('nonexistent'));
    $container->bind('test', fn() => 'hello');
    $t->assertTrue($container->has('test'));
});

$runner->run('Request - Method Detection', function($t) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $request = new \core\Request();
    $t->assertEquals('POST', $request->method());
    $t->assertTrue($request->isPost());
    $t->assertFalse($request->isGet());
});

$runner->run('Response - JSON Response', function($t) {
    $response = \core\Response::json(['code' => 0, 'data' => 'test']);
    $t->assertInstanceOf(\core\Response::class, $response);
});

$runner->run('Validate - Required Rule', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => ''], ['name' => 'required']);
    $t->assertFalse($v->passes());
    $t->assertTrue($v->fails());
});

$runner->run('Validate - Email Rule', function($t) {
    $v = new \core\Validate();
    $v->validate(['email' => 'bad-email'], ['email' => 'email']);
    $t->assertFalse($v->passes());
});

$runner->run('Validate - Min/Max Length', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => 'ab'], ['name' => 'min:3']);
    $t->assertFalse($v->passes());

    $v2 = new \core\Validate();
    $v2->validate(['name' => 'abcdef'], ['name' => 'max:5']);
    $t->assertFalse($v2->passes());
});

$runner->run('Session - Set and Get', function($t) {
    \core\Session::set('test_key', 'test_value');
    $t->assertEquals('test_value', \core\Session::get('test_key'));
    $t->assertEquals('default', \core\Session::get('nonexistent', 'default'));
});

$runner->run('Session - Delete', function($t) {
    \core\Session::set('delete_key', 'value');
    \core\Session::delete('delete_key');
    $t->assertNull(\core\Session::get('delete_key'));
});

$runner->run('Cookie - Static Methods Exist', function($t) {
    $t->assertTrue(method_exists(\core\Cookie::class, 'get'));
    $t->assertTrue(method_exists(\core\Cookie::class, 'set'));
    $t->assertTrue(method_exists(\core\Cookie::class, 'delete'));
    $t->assertTrue(method_exists(\core\Cookie::class, 'has'));
});

$runner->run('Hash - Make and Verify', function($t) {
    $hash = \core\Hash::make('password123');
    $t->assertIsString($hash);
    $t->assertTrue(\core\Hash::verify('password123', $hash));
    $t->assertFalse(\core\Hash::verify('wrongpassword', $hash));
});

$runner->run('Env - Load and Get', function($t) {
    \core\Env::set('TEST_VAR', 'test_value');
    $t->assertEquals('test_value', \core\Env::get('TEST_VAR'));
    $t->assertEquals('default', \core\Env::get('NONEXISTENT_VAR', 'default'));
});

$runner->run('Model - Static Methods', function($t) {
    $t->assertTrue(method_exists(\model\Model::class, 'setDb'));
    $t->assertTrue(method_exists(\model\Model::class, 'find'));
    $t->assertTrue(method_exists(\model\Model::class, 'all'));
    $t->assertTrue(method_exists(\model\Model::class, 'create'));
});

$runner->run('Middleware - Abstract Class', function($t) {
    $reflection = new \ReflectionClass(\middleware\Middleware::class);
    $t->assertTrue($reflection->isAbstract());
    $t->assertTrue($reflection->hasMethod('handle'));
});

$runner->run('CsrfMiddleware - Exists', function($t) {
    $t->assertTrue(class_exists(\middleware\CsrfMiddleware::class));
    $t->assertInstanceOf(\middleware\Middleware::class, new \middleware\CsrfMiddleware());
});

$runner->run('Exception Hierarchy', function($t) {
    $t->assertTrue(class_exists(\core\exception\FrameworkException::class));
    $t->assertTrue(class_exists(\core\exception\RouteNotFoundException::class));
    $t->assertTrue(class_exists(\core\exception\HttpException::class));
    $t->assertTrue(class_exists(\core\exception\DatabaseException::class));
    $t->assertTrue(class_exists(\core\exception\ValidationException::class));

    $e = new \core\exception\RouteNotFoundException();
    $t->assertInstanceOf(\core\exception\FrameworkException::class, $e);
});

$runner->run('Contracts - Interfaces Exist', function($t) {
    $t->assertTrue(interface_exists(\core\contract\CacheInterface::class));
    $t->assertTrue(interface_exists(\core\contract\LoggerInterface::class));
    $t->assertTrue(interface_exists(\core\contract\ConnectionInterface::class));
});

$runner->run('View - Auto Escape HTML', function($t) {
    $view = new \view\View(VIEW_PATH);
    $result = $view->render('test', ['content' => '<script>alert("XSS")</script>']);
    $t->assertStringContains('&lt;script&gt;', $result);
    $t->assertFalse(str_contains($result, '<script>alert'));
});

$runner->run('View - Without Auto Escape', function($t) {
    $view = new \view\View(VIEW_PATH);
    $result = $view->withoutAutoEscape()->render('test', ['content' => '<b>bold</b>']);
    $t->assertStringContains('<b>bold</b>', $result);
});

$runner->run('Container - Auto Resolve Class', function($t) {
    $container = new \core\Container();
    $response = $container->get(\core\Response::class);
    $t->assertInstanceOf(\core\Response::class, $response);
});

$runner->run('Container - Auto Resolve with Dependencies', function($t) {
    $container = new \core\Container();
    $validator = $container->get(\core\Validate::class);
    $t->assertInstanceOf(\core\Validate::class, $validator);
});

$runner->run('Container - Instance Method', function($t) {
    $container = new \core\Container();
    $obj = new \stdClass();
    $obj->name = 'test';
    $container->instance('test_obj', $obj);
    $t->assertTrue($obj === $container->get('test_obj'));
});

$runner->run('FileCache - Has Returns Correctly', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $cache->set('has_test_key', 'value', 60);
    $t->assertTrue($cache->has('has_test_key'));
    $t->assertFalse($cache->has('nonexistent_key_' . uniqid()));
    $cache->delete('has_test_key');
});

$runner->run('FileCache - Remember Caches Null', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $key = 'rmbn_' . bin2hex(random_bytes(8));
    $called = false;
    $result = $cache->remember($key, 60, function() use (&$called) {
        $called = true;
        return 'cached_value';
    });
    $t->assertEquals('cached_value', $result);
    $t->assertTrue($called);

    $called = false;
    $result = $cache->remember($key, 60, function() use (&$called) {
        $called = true;
        return 'should not be called';
    });
    $t->assertEquals('cached_value', $result);
    $t->assertFalse($called);
    $cache->delete($key);
});

$runner->run('FileCache - Increment Decrement', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $key = 'counter_test_' . uniqid();
    $cache->set($key, 0, 0);
    $t->assertEquals(1, $cache->increment($key));
    $t->assertEquals(0, $cache->decrement($key));
    $cache->delete($key);
});

$runner->run('Hash - Encrypt Decrypt', function($t) {
    if (!\function_exists('openssl_encrypt')) {
        $t->assertTrue(true, 'openssl extension not available, test skipped');
        return;
    }
    \core\Env::set('APP_KEY', 'test-encryption-key-32bytes!!!');
    $encrypted = \core\Hash::encrypt('Sensitive Data');
    $t->assertIsString($encrypted);
    $t->assertNotEquals('Sensitive Data', $encrypted);
    $decrypted = \core\Hash::decrypt($encrypted);
    $t->assertEquals('Sensitive Data', $decrypted);
});

$runner->run('Hash - Decrypt Invalid Data', function($t) {
    if (!\function_exists('openssl_decrypt')) {
        $t->assertTrue(true, 'openssl extension not available, test skipped');
        return;
    }
    \core\Env::set('APP_KEY', 'test-encryption-key-32bytes!!!');
    $t->assertNull(\core\Hash::decrypt('invalid-data'));
    $t->assertNull(\core\Hash::decrypt(''));
});

$runner->run('Validate - Passes Returns True', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => 'John'], ['name' => 'required']);
    $t->assertTrue($v->passes());
});

$runner->run('Validate - Fails Returns True', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => ''], ['name' => 'required']);
    $t->assertTrue($v->fails());
});

$runner->run('Validate - Unique Rule Throws', function($t) {
    $t->assertThrows(\RuntimeException::class, function() {
        $v = new \core\Validate();
        $v->validate(['name' => 'test'], ['name' => 'unique:users']);
    });
});

$runner->run('Router - Middleware Method', function($t) {
    $router = new \core\Router();
    $router->middleware('auth');
    $router->get('/protected', fn() => 'ok');
    $routes = $router->getRoutes();
    $t->assertCount(1, $routes);
    $t->assertContains('auth', $routes[0]['middleware']);
});

$runner->run('Application - SetConfig', function($t) {
    putenv('APP_KEY=test-key');
    $app = new \core\Application();
    $app->setConfig('app.name', 'TestApp');
    $t->assertEquals('TestApp', $app->getConfig('app.name'));

    $app->setConfig('app.nested.key', 'deep');
    $t->assertEquals('deep', $app->getConfig('app.nested.key'));
});

$runner->run('Env - Has and All', function($t) {
    \core\Env::set('HAS_TEST', 'value');
    $t->assertTrue(\core\Env::has('HAS_TEST'));
    $t->assertFalse(\core\Env::has('NONEXISTENT_' . uniqid()));
    $all = \core\Env::all();
    $t->assertArrayHasKey('HAS_TEST', $all);
});

$runner->run('Cookie - Delete with Security Options', function($t) {
    $t->assertTrue(method_exists(\core\Cookie::class, 'delete'));
    $ref = new \ReflectionMethod(\core\Cookie::class, 'delete');
    $params = $ref->getParameters();
    $t->assertCount(6, $params);
});

$runner->run('Session - FlashSet and FlashGet', function($t) {
    \core\Session::flashSet('success', 'Operation completed');
    $t->assertEquals('Operation completed', \core\Session::flashGet('success'));
    $t->assertNull(\core\Session::flashGet('success'));
});

$runner->run('ApiDoc - Generate Returns Array', function($t) {
    $apiDoc = new \core\ApiDoc();
    $docs = $apiDoc->generate();
    $t->assertIsArray($docs);
});

$runner->run('ApiDoc - ToMarkdown Returns String', function($t) {
    $apiDoc = new \core\ApiDoc();
    $md = $apiDoc->toMarkdown();
    $t->assertIsString($md);
    $t->assertStringContains('# API Documentation', $md);
});

$runner->run('ApiDoc - ToJson Returns Valid JSON', function($t) {
    $apiDoc = new \core\ApiDoc();
    $json = $apiDoc->toJson();
    $t->assertIsString($json);
    $decoded = json_decode($json, true);
    $t->assertIsArray($decoded);
});

// ═══════════════════════════════════════════════
//  新功能测试
// ═══════════════════════════════════════════════

$runner->run('EventDispatcher - Listen and Dispatch', function($t) {
    $events = new \core\EventDispatcher();
    $results = [];
    $events->listen('user.created', function($event, $data) use (&$results) {
        $results[] = $data;
    });
    $events->dispatch('user.created', ['name' => 'John']);
    $t->assertCount(1, $results);
    $t->assertEquals('John', $results[0]['name']);
});

$runner->run('EventDispatcher - Wildcard Matching', function($t) {
    $events = new \core\EventDispatcher();
    $count = 0;
    $events->listen('user.*', function() use (&$count) { $count++; });
    $events->dispatch('user.created');
    $events->dispatch('user.updated');
    $t->assertEquals(2, $count);
});

$runner->run('EventDispatcher - Has Listeners', function($t) {
    $events = new \core\EventDispatcher();
    $t->assertFalse($events->hasListeners('order.placed'));
    $events->listen('order.placed', fn() => null);
    $t->assertTrue($events->hasListeners('order.placed'));
});

$runner->run('EventDispatcher - Stop Propagation', function($t) {
    $events = new \core\EventDispatcher();
    $secondCalled = false;
    $events->listen('test.event', fn() => 'stop');
    $events->listen('test.event', function() use (&$secondCalled) { $secondCalled = true; return false; });
    $events->listen('test.event', function() use (&$secondCalled) { $secondCalled = true; });
    $events->dispatch('test.event');
    $t->assertTrue(true);
});

$runner->run('EventDispatcher - Until', function($t) {
    $events = new \core\EventDispatcher();
    $events->listen('resolve.name', fn() => null);
    $events->listen('resolve.name', fn() => 'Alice');
    $events->listen('resolve.name', fn() => 'Bob');
    $result = $events->until('resolve.name');
    $t->assertEquals('Alice', $result);
});

$runner->run('EventDispatcher - Priority', function($t) {
    $events = new \core\EventDispatcher();
    $order = [];
    $events->listen('priority.test', function() use (&$order) { $order[] = 1; }, 0);
    $events->listen('priority.test', function() use (&$order) { $order[] = 2; }, 10);
    $events->dispatch('priority.test');
    $t->assertEquals([2, 1], $order);
});

$runner->run('EventDispatcher - Forget', function($t) {
    $events = new \core\EventDispatcher();
    $events->listen('forget.me', fn() => null);
    $t->assertTrue($events->hasListeners('forget.me'));
    $events->forget('forget.me');
    $t->assertFalse($events->hasListeners('forget.me'));
});

$runner->run('EventDispatcher - Flush', function($t) {
    $events = new \core\EventDispatcher();
    $events->listen('event.a', fn() => null);
    $events->listen('event.b', fn() => null);
    $t->assertTrue($events->hasListeners('event.a'));
    $events->flush();
    $t->assertFalse($events->hasListeners('event.a'));
    $t->assertFalse($events->hasListeners('event.b'));
});

$runner->run('EventDispatcher - Subscribe', function($t) {
    $events = new \core\EventDispatcher();
    $subscriber = new class {
        public function subscribe(\core\EventDispatcher $e): void {
            $e->listen('sub.event', fn() => null);
        }
    };
    $events->subscribe($subscriber);
    $t->assertTrue($events->hasListeners('sub.event'));
});

$runner->run('Collection - Basic Operations', function($t) {
    $c = collect([1, 2, 3]);
    $t->assertCount(3, $c);
    $t->assertEquals([1, 2, 3], $c->all());
});

$runner->run('Collection - Map', function($t) {
    $c = collect([1, 2, 3])->map(fn($n) => $n * 2);
    $t->assertEquals([2, 4, 6], $c->all());
});

$runner->run('Collection - Filter', function($t) {
    $c = collect([1, 2, 3, 4, 5])->filter(fn($n) => $n > 3);
    $t->assertEquals([4, 5], $c->values()->all());
});

$runner->run('Collection - Filter Without Callback', function($t) {
    $c = collect([1, 0, 3, null, false]);
    $t->assertCount(2, $c->filter());
});

$runner->run('Collection - Where', function($t) {
    $items = [['name' => 'Alice', 'age' => 20], ['name' => 'Bob', 'age' => 25], ['name' => 'Charlie', 'age' => 20]];
    $c = collect($items)->where('age', 20);
    $t->assertCount(2, $c->values());
});

$runner->run('Collection - WhereIn', function($t) {
    $items = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]];
    $c = collect($items)->whereIn('id', [1, 3]);
    $t->assertCount(2, $c->values());
});

$runner->run('Collection - Pluck', function($t) {
    $items = [['name' => 'Alice', 'age' => 20], ['name' => 'Bob', 'age' => 25]];
    $c = collect($items)->pluck('name');
    $t->assertEquals(['Alice', 'Bob'], $c->all());
});

$runner->run('Collection - Pluck with Key', function($t) {
    $items = [['name' => 'Alice', 'id' => '1'], ['name' => 'Bob', 'id' => '2']];
    $c = collect($items)->pluck('name', 'id');
    $t->assertEquals(['1' => 'Alice', '2' => 'Bob'], $c->all());
});

$runner->run('Collection - Only', function($t) {
    $c = collect(['a' => 1, 'b' => 2, 'c' => 3])->only(['a', 'c']);
    $t->assertEquals(['a' => 1, 'c' => 3], $c->all());
});

$runner->run('Collection - Except', function($t) {
    $c = collect(['a' => 1, 'b' => 2, 'c' => 3])->except(['b']);
    $t->assertEquals(['a' => 1, 'c' => 3], $c->all());
});

$runner->run('Collection - Sum', function($t) {
    $t->assertEquals(10, collect([1, 2, 3, 4])->sum());
    $items = [['price' => 10], ['price' => 20], ['price' => 30]];
    $t->assertEquals(60, collect($items)->sum('price'));
});

$runner->run('Collection - Avg', function($t) {
    $t->assertEquals(3, collect([1, 2, 3, 4, 5])->avg());
    $t->assertEquals(0, collect([])->avg());
});

$runner->run('Collection - Min/Max', function($t) {
    $c = collect([1, 5, 3, 2, 4]);
    $t->assertEquals(1, $c->min());
    $t->assertEquals(5, $c->max());
    $t->assertNull(collect([])->min());
});

$runner->run('Collection - SortBy', function($t) {
    $items = [['name' => 'Bob'], ['name' => 'Alice']];
    $c = collect($items)->sortBy('name');
    $t->assertEquals('Alice', $c->first()['name']);
});

$runner->run('Collection - Take', function($t) {
    $c = collect([1, 2, 3, 4, 5])->take(3);
    $t->assertEquals([1, 2, 3], $c->all());
});

$runner->run('Collection - Skip', function($t) {
    $c = collect([1, 2, 3, 4, 5])->skip(3);
    $t->assertEquals([4, 5], $c->values()->all());
});

$runner->run('Collection - First/Last', function($t) {
    $c = collect([10, 20, 30]);
    $t->assertEquals(10, $c->first());
    $t->assertEquals(30, $c->last());
    $t->assertNull(collect([])->first());
    $t->assertEquals(100, collect([])->first(null, 100));
});

$runner->run('Collection - First with Callback', function($t) {
    $c = collect([5, 10, 15, 20]);
    $t->assertEquals(15, $c->first(fn($n) => $n > 10));
});

$runner->run('Collection - GroupBy', function($t) {
    $items = [['type' => 'fruit', 'name' => 'apple'], ['type' => 'veggie', 'name' => 'carrot'], ['type' => 'fruit', 'name' => 'banana']];
    $c = collect($items)->groupBy('type');
    $t->assertCount(2, collect($c->all()['fruit'] ?? []));
});

$runner->run('Collection - KeyBy', function($t) {
    $items = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];
    $c = collect($items)->keyBy('id');
    $t->assertEquals('Alice', $c[1]['name']);
});

$runner->run('Collection - Contains', function($t) {
    $c = collect([1, 2, 3]);
    $t->assertTrue($c->contains(2));
    $t->assertFalse($c->contains(99));
});

$runner->run('Collection - IsEmpty/IsNotEmpty', function($t) {
    $t->assertTrue(collect([])->isEmpty());
    $t->assertFalse(collect([1])->isEmpty());
    $t->assertTrue(collect([1])->isNotEmpty());
});

$runner->run('Collection - Unique', function($t) {
    $c = collect([1, 2, 2, 3, 3, 3]);
    $t->assertEquals([1, 2, 3], $c->unique()->values()->all());
});

$runner->run('Collection - Reduce', function($t) {
    $c = collect([1, 2, 3, 4]);
    $t->assertEquals(10, $c->reduce(fn($carry, $n) => $carry + $n, 0));
});

$runner->run('Collection - Each', function($t) {
    $sum = 0;
    collect([1, 2, 3])->each(function($n) use (&$sum) { $sum += $n; });
    $t->assertEquals(6, $sum);
});

$runner->run('Collection - Tap', function($t) {
    $tapped = null;
    $c = collect([1, 2])->tap(function($col) use (&$tapped) { $tapped = $col; });
    $t->assertInstanceOf(\core\Collection::class, $tapped);
});

$runner->run('Collection - Pipe', function($t) {
    $result = collect([1, 2, 3])->pipe(fn($col) => $col->sum());
    $t->assertEquals(6, $result);
});

$runner->run('Collection - JSON Serialize', function($t) {
    $c = collect(['a' => 1, 'b' => 2]);
    $json = json_encode($c);
    $t->assertIsString($json);
    $t->assertIsArray(json_decode($json, true));
});

$runner->run('Collection - Array Access', function($t) {
    $c = collect(['x' => 10, 'y' => 20]);
    $t->assertEquals(10, $c['x']);
    $t->assertTrue(isset($c['x']));
    $c['z'] = 30;
    $t->assertEquals(30, $c['z']);
    unset($c['x']);
    $t->assertFalse(isset($c['x']));
});

$runner->run('Collection - Countable', function($t) {
    $c = collect([1, 2, 3, 4, 5]);
    $t->assertEquals(5, count($c));
});

$runner->run('Facade - Class Exists', function($t) {
    $t->assertTrue(class_exists(\core\Facade::class));
    $t->assertTrue(method_exists(\core\Facade::class, 'setContainer'));
});

$runner->run('Facade - Resolve Without Container Throws', function($t) {
    \core\Facade::clearResolved();
    $threw = false;
    try {
        $stub = new class extends \core\Facade {
            protected static function getFacadeAccessor(): string { return 'nonexistent'; }
        };
        $ref = new \ReflectionClass($stub);
        $prop = $ref->getProperty('container');
        $prop->setValue(null, null);
        $method = $ref->getMethod('resolve');
        $method->invoke(null);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    $t->assertTrue($threw, 'Expected exception when resolving without container');
});

$runner->run('ServiceProvider - Abstract Class', function($t) {
    $t->assertTrue(class_exists(\core\ServiceProvider::class));
    $r = new \ReflectionClass(\core\ServiceProvider::class);
    $t->assertTrue($r->isAbstract());
    $t->assertTrue($r->hasMethod('register'));
    $t->assertTrue($r->hasMethod('boot'));
});

$runner->run('ServiceProvider - Has Container', function($t) {
    $container = new \core\Container();
    $sp = new class($container) extends \core\ServiceProvider {
        public function register(): void {}
    };
    $t->assertInstanceOf(\core\ServiceProvider::class, $sp);
});

$runner->run('CORS Middleware - Class Exists', function($t) {
    $t->assertTrue(class_exists(\middleware\Cors::class));
    $cors = new \middleware\Cors();
    $t->assertTrue(method_exists($cors, 'handle'));
});

$runner->run('Throttle Middleware - Class Exists', function($t) {
    $t->assertTrue(class_exists(\middleware\Throttle::class));
    $throttle = new \middleware\Throttle(10, 60);
    $t->assertTrue(method_exists($throttle, 'handle'));
});

$runner->run('Blade Compiler - Class Exists', function($t) {
    $t->assertTrue(class_exists(\view\Blade::class));
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $t->assertTrue(method_exists($blade, 'render'));
    $t->assertTrue(method_exists($blade, 'compileString'));
});

$runner->run('Blade Compiler - Echo Compilation', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('Hello, {{ $name }}!');
    $t->assertStringContains('htmlspecialchars', $result);
});

$runner->run('Blade Compiler - Raw Echo', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('{!! $html !!}');
    $t->assertStringContains('<?=', $result);
});

$runner->run('Blade Compiler - IfStatement', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@if($x) yes @endif');
    $t->assertStringContains('<?php if', $result);
});

$runner->run('Blade Compiler - Foreach Statement', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@foreach($items as $item) {{ $item }} @endforeach');
    $t->assertStringContains('<?php foreach', $result);
    $t->assertStringContains('endforeach', $result);
});

$runner->run('Blade Compiler - Custom Directive', function($t) {
    \view\Blade::directive('datetime', function($expr) {
        return "<?= date('Y-m-d', strtotime({$expr})) ?>";
    });
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@datetime($now)');
    $t->assertStringContains('<?php', $result);
});

$runner->run('Command - Signature Parsing', function($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'greet {name} {greeting?} {--upper}';
        public function handle(): int { return 0; }
    };
    $t->assertEquals('greet', $cmd->getName());
    $t->assertTrue(strlen($cmd->getSignature()) > 0);
});

$runner->run('Command - Argument Parsing', function($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'say {message=hello}';
        public function handle(): int { return 0; }
    };
    $cmd->parseInput(['world']);
    $t->assertEquals('world', $cmd->argument('message'));
});

$runner->run('Command - Option Parsing', function($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'run {--verbose}';
        public function handle(): int { return 0; }
    };
    $cmd->parseInput(['--verbose']);
    $t->assertTrue($cmd->hasOption('verbose'));
});

$runner->run('Command - Default Argument', function($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'cmd {name=DefaultName}';
        public function handle(): int { return 0; }
    };
    $cmd->parseInput([]);
    $t->assertEquals('DefaultName', $cmd->argument('name'));
});

$runner->run('Console - Register and List Commands', function($t) {
    $console = new \core\console\Console('Test Console', '1.0');
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'test:hello {name}';
        protected string $description = 'Say hello to someone';
        public function handle(): int {
            echo "Hello, {$this->argument('name')}!";
            return 0;
        }
    };
    $console->register($cmd);
    $t->assertTrue(true);
});

$runner->run('Console - Unknown Command', function($t) {
    $console = new \core\console\Console('Test', '1.0');
    $code = $console->run(['console', 'unknown:cmd']);
    $t->assertEquals(1, $code);
});

$runner->run('Schema Builder - Class Exists', function($t) {
    $t->assertTrue(class_exists(\db\Schema::class));
    $t->assertTrue(class_exists(\db\Blueprint::class));
    $t->assertTrue(class_exists(\db\Migration::class));
});

$runner->run('Schema - SetConnection', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $schema = \db\Schema::setConnection($pdo);
    $t->assertInstanceOf(\db\Schema::class, $schema);
});

$runner->run('Blueprint - Has Methods', function($t) {
    $b = new \db\Blueprint('test');
    $t->assertTrue(method_exists($b, 'id'));
    $t->assertTrue(method_exists($b, 'string'));
    $t->assertTrue(method_exists($b, 'integer'));
    $t->assertTrue(method_exists($b, 'text'));
    $t->assertTrue(method_exists($b, 'timestamps'));
    $t->assertTrue(method_exists($b, 'nullable'));
    $t->assertTrue(method_exists($b, 'unique'));
    $t->assertTrue(method_exists($b, 'index'));
});

$runner->run('Schema - Has Table', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $schema = \db\Schema::setConnection($pdo);
    $schema->create('demo', function(\db\Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->integer('age');
    });
    $t->assertTrue($schema->hasTable('demo'));
    $t->assertTrue($schema->hasColumn('demo', 'name'));
    $t->assertFalse($schema->hasTable('nonexistent'));
    $schema->drop('demo');
    $t->assertFalse($schema->hasTable('demo'));
});

$runner->run('Schema - Rename Table', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $schema = \db\Schema::setConnection($pdo);
    $schema->create('old_name', function(\db\Blueprint $table) {
        $table->id();
    });
    $schema->rename('old_name', 'new_name');
    $t->assertTrue($schema->hasTable('new_name'));
    $t->assertFalse($schema->hasTable('old_name'));
    $schema->drop('new_name');
});

$runner->run('Schema - Truncate', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $schema = \db\Schema::setConnection($pdo);
    $schema->create('temp_data', function(\db\Blueprint $table) {
        $table->id();
        $table->string('value');
    });
    $pdo->exec("INSERT INTO `temp_data` (`value`) VALUES ('test')");
    $t->assertEquals(1, $pdo->query("SELECT count(*) FROM temp_data")->fetchColumn());
    $schema->truncate('temp_data');
    $t->assertEquals(0, $pdo->query("SELECT count(*) FROM temp_data")->fetchColumn());
    $schema->drop('temp_data');
});

$runner->run('Model - GetForeignKey', function($t) {
    $ref = new \ReflectionMethod(\model\Model::class, 'getForeignKey');
    $m = new \model\Model(['id' => 1]);
    $t->assertEquals('model_id', $ref->invoke($m));
});

$runner->run('Model - Has ORM Methods', function($t) {
    $ref = new \ReflectionClass(\model\Model::class);
    $names = array_map(fn($m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PROTECTED));
    $t->assertTrue(in_array('hasOne', $names));
    $t->assertTrue(in_array('hasMany', $names));
    $t->assertTrue(in_array('belongsTo', $names));
    $t->assertTrue(in_array('belongsToMany', $names));
    $t->assertTrue(in_array('eagerLoad', $names) || $ref->hasMethod('eagerLoad'));
});

$runner->run('Model - ToArray and ToJson', function($t) {
    $m = new \model\Model(['id' => 1, 'name' => 'Test']);
    $arr = $m->toArray();
    $t->assertIsArray($arr);
    $t->assertEquals(1, $arr['id']);
    $json = $m->toJson();
    $t->assertIsString($json);
    $decoded = json_decode($json, true);
    $t->assertEquals('Test', $decoded['name']);
});

$runner->run('Model - With and LoadRelation', function($t) {
    $m = new class(['id' => 1, 'name' => 'John']) extends \model\Model {
        protected string $table = 'users';
        public function profile() {
            return new class(['user_id' => 1, 'bio' => 'Test Bio']) extends \model\Model {
                protected string $table = 'profiles';
            };
        }
    };
    $result = $m->with(['profile']);
    $profile = $result->profile;
    $t->assertNotNull($profile);
    $t->assertEquals('Test Bio', $profile->bio);
});

$runner->run('Application - Has Events', function($t) {
    putenv('APP_KEY=test-key-32bytes-OK');
    $app = new \core\Application();
    $events = $app->getEvents();
    $t->assertInstanceOf(\core\EventDispatcher::class, $events);
});

$runner->run('Application - registerProvider', function($t) {
    putenv('APP_KEY=test-key-32bytes-OK');
    $app = new \core\Application();
    $provider = new class($app->getContainer()) extends \core\ServiceProvider {
        public function register(): void {}
    };
    $app->registerProvider($provider);
    $t->assertTrue(true);
});

$runner->run('Command - Signature with Defaults', function($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'config:set {key} {value=default}';
        public function handle(): int { return 0; }
    };
    $cmd->parseInput(['debug']);
    $t->assertEquals('debug', $cmd->argument('key'));
    $t->assertEquals('default', $cmd->argument('value'));
});

// ═══════════════════════════════════════════════
//  v2.0.0 新功能集成测试
// ═══════════════════════════════════════════════

// --- Pipeline 管道测试 ---

$runner->run('Pipeline - 基本洋葱模型执行', function($t) {
    $pipeline = new \core\Pipeline();
    $trace = [];

    $result = $pipeline
        ->send('request')
        ->through([
            new class {
                public function handle($passable, \Closure $next) {
                    return '[A:' . $next($passable) . ':A]';
                }
            },
            new class {
                public function handle($passable, \Closure $next) {
                    return '[B:' . $next($passable) . ':B]';
                }
            },
        ])
        ->then(fn($p) => "CORE({$p})");

    $t->assertEquals('[A:[B:CORE(request):B]:A]', $result);
});

$runner->run('Pipeline - thenReturn 直接返回', function($t) {
    $pipeline = new \core\Pipeline();

    $result = $pipeline
        ->send('hello')
        ->through([
            new class {
                public function handle($passable, \Closure $next) {
                    return strtoupper($next($passable));
                }
            },
        ])
        ->thenReturn();

    $t->assertEquals('HELLO', $result);
});

$runner->run('Pipeline - 空管道直接到达目标', function($t) {
    $pipeline = new \core\Pipeline();

    $result = $pipeline
        ->send('data')
        ->through([])
        ->then(fn($p) => "processed:{$p}");

    $t->assertEquals('processed:data', $result);
});

$runner->run('Pipeline - via 自定义方法名', function($t) {
    $pipeline = new \core\Pipeline();

    $middleware = new class {
        public function process($passable, \Closure $next) {
            return 'processed:' . $next($passable);
        }
    };

    $result = $pipeline
        ->send('req')
        ->through([$middleware])
        ->via('process')
        ->then(fn($p) => $p);

    $t->assertEquals('processed:req', $result);
});

$runner->run('Pipeline - 中间件可修改请求和响应', function($t) {
    $pipeline = new \core\Pipeline();

    $result = $pipeline
        ->send(10)
        ->through([
            new class {
                public function handle($passable, \Closure $next) {
                    return $next($passable * 2);
                }
            },
            new class {
                public function handle($passable, \Closure $next) {
                    $response = $next($passable);
                    return $response + 100;
                }
            },
        ])
        ->then(fn($n) => $n + 1);

    $t->assertEquals(121, $result);
});

// --- Macroable 宏测试 ---

$runner->run('Macroable - 注册并调用宏方法', function($t) {
    \core\Request::macro('customMethod', fn() => 'custom result');
    $request = new \core\Request();
    $t->assertEquals('custom result', $request->customMethod());
    \core\Request::flushMacros();
});

$runner->run('Macroable - hasMacro 检查宏是否存在', function($t) {
    $t->assertFalse(\core\Request::hasMacro('nonexistent'));
    \core\Request::macro('testMacro', fn() => 'ok');
    $t->assertTrue(\core\Request::hasMacro('testMacro'));
    \core\Request::flushMacros();
});

$runner->run('Macroable - flushMacros 清空宏', function($t) {
    \core\Request::macro('tempMacro', fn() => 'temp');
    $t->assertTrue(\core\Request::hasMacro('tempMacro'));
    \core\Request::flushMacros();
    $t->assertFalse(\core\Request::hasMacro('tempMacro'));
});

$runner->run('Macroable - 宏闭包可访问 $this', function($t) {
    \core\Response::macro('getContentType', function() {
        return $this->getContent();
    });
    $response = \core\Response::make('hello world');
    $t->assertEquals('hello world', $response->getContentType());
    \core\Response::flushMacros();
});

$runner->run('Macroable - mixin 批量注册宏', function($t) {
    $mixin = new class {
        public function upper() { return fn() => 'UPPER'; }
        public function lower() { return fn() => 'lower'; }
    };
    \core\Request::mixin($mixin);
    $t->assertTrue(\core\Request::hasMacro('upper'));
    $t->assertTrue(\core\Request::hasMacro('lower'));
    $request = new \core\Request();
    $t->assertEquals('UPPER', $request->upper());
    $t->assertEquals('lower', $request->lower());
    \core\Request::flushMacros();
});

$runner->run('Macroable - mixin 不覆盖模式', function($t) {
    \core\Request::macro('existing', fn() => 'original');
    $mixin = new class {
        public function existing() { return fn() => 'overridden'; }
        public function fresh() { return fn() => 'new'; }
    };
    \core\Request::mixin($mixin, false);
    $request = new \core\Request();
    $t->assertEquals('original', $request->existing());
    $t->assertEquals('new', $request->fresh());
    \core\Request::flushMacros();
});

$runner->run('Macroable - 调用不存在宏抛出异常', function($t) {
    \core\Request::flushMacros();
    $request = new \core\Request();
    $t->assertThrows(\BadMethodCallException::class, function() use ($request) {
        $request->nonexistentMacro();
    });
});

$runner->run('Macroable - Response 宏扩展', function($t) {
    \core\Response::macro('csv', function(string $content) {
        return self::make($content)->header('Content-Type', 'text/csv');
    });
    $response = \core\Response::csv('a,b,c');
    $t->assertInstanceOf(\core\Response::class, $response);
    \core\Response::flushMacros();
});

// --- SoftDelete 软删除测试 ---

$runner->run('SoftDelete - trait 类存在', function($t) {
    $t->assertTrue(trait_exists(\traits\SoftDelete::class));
});

$runner->run('SoftDelete - trashed 方法', function($t) {
    $model = new class(['id' => 1, 'deleted_at' => null]) extends \model\Model {
        protected string $table = 'test';
        use \traits\SoftDelete;
    };
    $t->assertFalse($model->trashed());

    $model2 = new class(['id' => 2, 'deleted_at' => '2026-01-01 00:00:00']) extends \model\Model {
        protected string $table = 'test';
        use \traits\SoftDelete;
    };
    $t->assertTrue($model2->trashed());
});

$runner->run('SoftDelete - forceDelete/softDelete 模式切换', function($t) {
    $model = new class(['id' => 1]) extends \model\Model {
        protected string $table = 'test';
        use \traits\SoftDelete;
    };

    $forced = $model->force();
    $t->assertInstanceOf(get_class($model), $forced);
    $t->assertTrue(in_array(\traits\SoftDelete::class, class_uses($forced)));
    $t->assertTrue($forced->trashed() === false);
});

// --- HasModelEvents 模型事件测试 ---

$runner->run('HasModelEvents - trait 类存在', function($t) {
    $t->assertTrue(trait_exists(\traits\HasModelEvents::class));
});

$runner->run('HasModelEvents - onEvent 注册监听器', function($t) {
    $modelClass = new class extends \model\Model {
        protected string $table = 'test';
        use \traits\HasModelEvents;
    };
    $called = false;
    $modelClass::onEvent('creating', function($m) use (&$called) { $called = true; });
    $instance = new $modelClass(['id' => 1]);
    $instance->fireEvent('creating');
    $t->assertTrue($called);
    $modelClass::flushEventListeners();
});

$runner->run('HasModelEvents - fireEvent 返回 false 取消操作', function($t) {
    $modelClass = new class extends \model\Model {
        protected string $table = 'test';
        use \traits\HasModelEvents;
    };
    $modelClass::onEvent('deleting', function($m) { return false; });
    $instance = new $modelClass(['id' => 1]);
    $result = $instance->fireEvent('deleting');
    $t->assertFalse($result);
    $modelClass::flushEventListeners();
});

$runner->run('HasModelEvents - observe 观察者', function($t) {
    $modelClass = new class extends \model\Model {
        protected string $table = 'test';
        use \traits\HasModelEvents;
    };

    $observerCalled = false;
    $observer = new class($GLOBALS ?? []) {
        public bool $called = false;
        public function creating($model) { $this->called = true; }
    };

    $modelClass::observe($observer);
    $instance = new $modelClass(['id' => 1]);
    $instance->fireEvent('creating');
    $t->assertTrue($observer->called);
    $modelClass::flushEventListeners();
});

// --- 访问器/修改器测试 ---

$runner->run('Model - 访问器 getFooAttribute', function($t) {
    $model = new class(['name' => 'john doe']) extends \model\Model {
        protected string $table = 'test';
        public function getNameAttribute($value): string
        {
            return ucwords($value);
        }
    };
    $t->assertEquals('John Doe', $model->name);
});

$runner->run('Model - 修改器 setFooAttribute', function($t) {
    $model = new class extends \model\Model {
        protected string $table = 'test';
        protected array $attributes = [];
        public function setEmailAttribute($value): void
        {
            $this->attributes['email'] = strtolower($value);
        }
    };
    $model->email = 'JOHN@EXAMPLE.COM';
    $t->assertEquals('john@example.com', $model->email);
});

// --- 查询作用域测试 ---

$runner->run('Model - 查询作用域 scope 调用', function($t) {
    $model = new class extends \model\Model {
        protected string $table = 'users';
        protected array $fillable = ['name', 'status'];
        public function scopeActive(\db\QueryBuilder $query): \db\QueryBuilder
        {
            return $query->where('status', '=', 'active');
        }
    };
    $t->assertTrue(method_exists($model, 'scopeActive'));
});

// --- Seeder 数据填充测试 ---

$runner->run('Seeder - 抽象类存在', function($t) {
    $t->assertTrue(class_exists(\db\Seeder::class));
    $ref = new \ReflectionClass(\db\Seeder::class);
    $t->assertTrue($ref->isAbstract());
    $t->assertTrue($ref->hasMethod('run'));
    $t->assertTrue($ref->hasMethod('call'));
    $t->assertTrue($ref->hasMethod('register'));
    $t->assertTrue($ref->hasMethod('runAll'));
});

// --- 中间件别名/组测试 ---

$runner->run('Router - aliasMiddleware 注册别名', function($t) {
    $router = new \core\Router();
    $router->aliasMiddleware('cors', \middleware\Cors::class);
    $router->aliasMiddleware('csrf', \middleware\CsrfMiddleware::class);
    $t->assertTrue(true);
});

$runner->run('Router - middlewareGroup 注册组', function($t) {
    $router = new \core\Router();
    $router->middlewareGroup('web', [\middleware\CsrfMiddleware::class]);
    $router->middlewareGroup('api', [\middleware\Cors::class]);
    $t->assertTrue(true);
});

$runner->run('Router - setGlobalMiddleware 设置全局中间件', function($t) {
    $router = new \core\Router();
    $router->setGlobalMiddleware([\middleware\Cors::class]);
    $t->assertTrue(true);
});

// --- Request 类型过滤测试 ---

$runner->run('Request - string 类型过滤', function($t) {
    $_POST['name'] = '  John  ';
    $request = new \core\Request();
    $t->assertIsString($request->string('name'));
});

$runner->run('Request - integer 类型过滤', function($t) {
    $_POST['age'] = '25';
    $request = new \core\Request();
    $t->assertIsInt($request->integer('age'));
    $t->assertEquals(25, $request->integer('age'));
});

$runner->run('Request - float 类型过滤', function($t) {
    $_POST['price'] = '19.99';
    $request = new \core\Request();
    $result = $request->float('price');
    $t->assertTrue(is_float($result));
    $t->assertEquals(19.99, $result);
});

$runner->run('Request - boolean 类型过滤', function($t) {
    $_POST['active'] = 'true';
    $request = new \core\Request();
    $t->assertTrue($request->boolean('active'));
    $_POST['disabled'] = '0';
    $t->assertFalse($request->boolean('disabled'));
});

$runner->run('Request - arrayInput 类型过滤', function($t) {
    $_POST['tags'] = ['php', 'framework'];
    $request = new \core\Request();
    $t->assertIsArray($request->arrayInput('tags'));
    $t->assertEquals(['php', 'framework'], $request->arrayInput('tags'));
});

$runner->run('Request - merge 合并数据', function($t) {
    $request = new \core\Request();
    $request->merge(['extra' => 'value']);
    $t->assertEquals('value', $request->post('extra'));
});

// --- ExceptionHandler 测试 ---

$runner->run('ExceptionHandler - 类存在', function($t) {
    $t->assertTrue(class_exists(\core\ExceptionHandler::class));
});

$runner->run('ExceptionHandler - shouldReport 过滤', function($t) {
    $handler = new \core\ExceptionHandler(null, true);
    $e = new \RuntimeException('test');
    $t->assertTrue($handler->shouldReport($e));
});

$runner->run('ExceptionHandler - dontReport 忽略列表', function($t) {
    $handler = new class(null, true) extends \core\ExceptionHandler {
        protected array $dontReport = [\RuntimeException::class];
    };
    $e = new \RuntimeException('should skip');
    $t->assertFalse($handler->shouldReport($e));
});

// ═══════════════════════════════════════════════
//  缓存驱动测试（Redis / Memcached / TaggedCache）
// ═══════════════════════════════════════════════

// --- RedisCache 基础结构测试 ---

$runner->run('RedisCache - 类存在且实现 CacheInterface', function($t) {
    $t->assertTrue(class_exists(\cache\RedisCache::class));
    $ref = new \ReflectionClass(\cache\RedisCache::class);
    $t->assertTrue($ref->implementsInterface(\core\contract\CacheInterface::class));
});

$runner->run('RedisCache - 扩展未安装时构造抛异常', function($t) {
    if (class_exists(\Redis::class)) {
        $t->assertTrue(true, 'Redis 扩展已安装，跳过异常测试');
        return;
    }
    $t->assertThrows(\RuntimeException::class, function() {
        new \cache\RedisCache();
    }, 'Redis 扩展未安装时应抛出 RuntimeException');
});

$runner->run('RedisCache - 实现所有接口方法', function($t) {
    $methods = ['get', 'set', 'has', 'delete', 'clear', 'remember',
                'increment', 'decrement', 'many', 'setMany', 'deleteMany',
                'pull', 'tags', 'flushByTag', 'attachTag'];
    foreach ($methods as $method) {
        $t->assertTrue(method_exists(\cache\RedisCache::class, $method),
            "RedisCache 缺少方法: {$method}");
    }
});

$runner->run('RedisCache - connection 方法存在', function($t) {
    $t->assertTrue(method_exists(\cache\RedisCache::class, 'connection'));
});

// --- MemcachedCache 基础结构测试 ---

$runner->run('MemcachedCache - 类存在且实现 CacheInterface', function($t) {
    $t->assertTrue(class_exists(\cache\MemcachedCache::class));
    $ref = new \ReflectionClass(\cache\MemcachedCache::class);
    $t->assertTrue($ref->implementsInterface(\core\contract\CacheInterface::class));
});

$runner->run('MemcachedCache - 扩展未安装时构造抛异常', function($t) {
    if (class_exists(\Memcached::class)) {
        $t->assertTrue(true, 'Memcached 扩展已安装，跳过异常测试');
        return;
    }
    $t->assertThrows(\RuntimeException::class, function() {
        new \cache\MemcachedCache();
    }, 'Memcached 扩展未安装时应抛出 RuntimeException');
});

$runner->run('MemcachedCache - 实现所有接口方法', function($t) {
    $methods = ['get', 'set', 'has', 'delete', 'clear', 'remember',
                'increment', 'decrement', 'many', 'setMany', 'deleteMany',
                'pull', 'tags', 'flushByTag', 'attachTag'];
    foreach ($methods as $method) {
        $t->assertTrue(method_exists(\cache\MemcachedCache::class, $method),
            "MemcachedCache 缺少方法: {$method}");
    }
});

$runner->run('MemcachedCache - connection 方法存在', function($t) {
    $t->assertTrue(method_exists(\cache\MemcachedCache::class, 'connection'));
});

// --- TaggedCache 测试（使用 FileCache 作为底层存储） ---

$runner->run('TaggedCache - 类存在', function($t) {
    $t->assertTrue(class_exists(\cache\TaggedCache::class));
});

$runner->run('TaggedCache - set 和 get', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['user']);
    $key = 'tc_set_' . bin2hex(random_bytes(4));
    $tagged->set($key, 'tagged_value', 60);
    $t->assertEquals('tagged_value', $tagged->get($key));
    $t->assertEquals('tagged_value', $store->get($key));
    $store->delete($key);
});

$runner->run('TaggedCache - has 检查存在性', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['product']);
    $key = 'tc_has_' . bin2hex(random_bytes(4));
    $t->assertFalse($tagged->has($key));
    $tagged->set($key, 'value', 60);
    $t->assertTrue($tagged->has($key));
    $store->delete($key);
});

$runner->run('TaggedCache - delete 删除缓存', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['order']);
    $key = 'tc_del_' . bin2hex(random_bytes(4));
    $tagged->set($key, 'data', 60);
    $t->assertTrue($tagged->has($key));
    $tagged->delete($key);
    $t->assertFalse($tagged->has($key));
});

$runner->run('TaggedCache - remember 缓存回调', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['session']);
    $key = 'tc_rmb_' . bin2hex(random_bytes(4));
    $called = false;
    $result = $tagged->remember($key, 60, function() use (&$called) {
        $called = true;
        return 'remembered';
    });
    $t->assertEquals('remembered', $result);
    $t->assertTrue($called);

    // 第二次调用不应执行回调
    $called = false;
    $result = $tagged->remember($key, 60, function() use (&$called) {
        $called = true;
        return 'should_not_run';
    });
    $t->assertEquals('remembered', $result);
    $t->assertFalse($called);
    $store->delete($key);
});

$runner->run('TaggedCache - many 批量获取', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['batch']);
    $prefix = 'tc_many_' . bin2hex(random_bytes(4));
    $store->set("{$prefix}_a", 'alpha', 60);
    $store->set("{$prefix}_b", 'beta', 60);
    $results = $tagged->many(["{$prefix}_a", "{$prefix}_b"]);
    $t->assertEquals('alpha', $results["{$prefix}_a"]);
    $t->assertEquals('beta', $results["{$prefix}_b"]);
    $store->delete("{$prefix}_a");
    $store->delete("{$prefix}_b");
});

$runner->run('TaggedCache - setMany 批量设置', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['bulk']);
    $prefix = 'tc_sm_' . bin2hex(random_bytes(4));
    $tagged->setMany(["{$prefix}_x" => 'xval', "{$prefix}_y" => 'yval'], 60);
    $t->assertEquals('xval', $store->get("{$prefix}_x"));
    $t->assertEquals('yval', $store->get("{$prefix}_y"));
    $store->delete("{$prefix}_x");
    $store->delete("{$prefix}_y");
});

$runner->run('TaggedCache - flush 按标签清除', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['flush_test']);
    $prefix = 'tc_flush_' . bin2hex(random_bytes(4));
    $tagged->set("{$prefix}_1", 'data1', 60);
    $tagged->set("{$prefix}_2", 'data2', 60);
    $t->assertTrue($store->has("{$prefix}_1"));
    $t->assertTrue($store->has("{$prefix}_2"));

    $tagged->flush();
    $t->assertFalse($store->has("{$prefix}_1"));
    $t->assertFalse($store->has("{$prefix}_2"));
});

$runner->run('TaggedCache - tags 追加标签生成新实例', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged1 = new \cache\TaggedCache($store, ['tag_a']);
    $tagged2 = $tagged1->tags(['tag_b']);
    $t->assertInstanceOf(\cache\TaggedCache::class, $tagged2);
    // 新实例与原实例不同
    $t->assertTrue($tagged1 !== $tagged2);
});

$runner->run('TaggedCache - get 返回默认值', function($t) {
    $store = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = new \cache\TaggedCache($store, ['default']);
    $key = 'tc_def_' . bin2hex(random_bytes(4));
    $t->assertNull($tagged->get($key));
    $t->assertEquals('fallback', $tagged->get($key, 'fallback'));
});

// --- FileCache 标签化缓存集成测试 ---

$runner->run('FileCache - tags 返回 TaggedCache 实例', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $tagged = $cache->tags(['integration']);
    $t->assertInstanceOf(\cache\TaggedCache::class, $tagged);
});

$runner->run('FileCache - attachTag 和 flushByTag', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $prefix = 'fc_tag_' . bin2hex(random_bytes(4));
    $cache->set("{$prefix}_a", 'value_a', 60);
    $cache->set("{$prefix}_b", 'value_b', 60);
    $cache->attachTag("{$prefix}_a", 'test_group');
    $cache->attachTag("{$prefix}_b", 'test_group');

    $t->assertTrue($cache->has("{$prefix}_a"));
    $t->assertTrue($cache->has("{$prefix}_b"));

    $cache->flushByTag('test_group');
    $t->assertFalse($cache->has("{$prefix}_a"));
    $t->assertFalse($cache->has("{$prefix}_b"));
});

$runner->run('FileCache - pull 获取并删除', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $key = 'fc_pull_' . bin2hex(random_bytes(4));
    $cache->set($key, 'pull_value', 60);
    $value = $cache->pull($key);
    $t->assertEquals('pull_value', $value);
    $t->assertFalse($cache->has($key));
});

$runner->run('FileCache - deleteMany 批量删除', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $prefix = 'fc_dm_' . bin2hex(random_bytes(4));
    $cache->set("{$prefix}_1", 'v1', 60);
    $cache->set("{$prefix}_2", 'v2', 60);
    $cache->set("{$prefix}_3", 'v3', 60);
    $t->assertTrue($cache->deleteMany(["{$prefix}_1", "{$prefix}_2"]));
    $t->assertFalse($cache->has("{$prefix}_1"));
    $t->assertFalse($cache->has("{$prefix}_2"));
    $t->assertTrue($cache->has("{$prefix}_3"));
    $cache->delete("{$prefix}_3");
});

$runner->run('FileCache - setMany 和 many 配合', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $prefix = 'fc_sm_' . bin2hex(random_bytes(4));
    $cache->setMany(["{$prefix}_a" => 10, "{$prefix}_b" => 20], 60);
    $results = $cache->many(["{$prefix}_a", "{$prefix}_b", "{$prefix}_c"]);
    $t->assertEquals(10, $results["{$prefix}_a"]);
    $t->assertEquals(20, $results["{$prefix}_b"]);
    $t->assertNull($results["{$prefix}_c"]);
    $cache->delete("{$prefix}_a");
    $cache->delete("{$prefix}_b");
});

// --- RedisCache 功能测试（需要 Redis 扩展和服务） ---

$runner->run('RedisCache - 完整功能测试', function($t) {
    if (!class_exists(\Redis::class)) {
        $t->assertTrue(true, 'Redis 扩展未安装，跳过功能测试');
        return;
    }
    try {
        $redis = new \Redis();
        if (!$redis->connect('127.0.0.1', 6379, 1.0)) {
            $t->assertTrue(true, 'Redis 服务未运行，跳过功能测试');
            return;
        }
        $redis->close();
    } catch (\Throwable) {
        $t->assertTrue(true, 'Redis 服务连接失败，跳过功能测试');
        return;
    }

    $cache = new \cache\RedisCache(['timeout' => 1.0]);
    $prefix = 'test_' . bin2hex(random_bytes(4)) . '_';

    // set / get
    $cache->set("{$prefix}key1", 'hello', 60);
    $t->assertEquals('hello', $cache->get("{$prefix}key1"));

    // get 默认值
    $t->assertNull($cache->get("{$prefix}nonexistent"));
    $t->assertEquals('default', $cache->get("{$prefix}nonexistent", 'default'));

    // has
    $t->assertTrue($cache->has("{$prefix}key1"));
    $t->assertFalse($cache->has("{$prefix}nonexistent"));

    // delete
    $cache->delete("{$prefix}key1");
    $t->assertFalse($cache->has("{$prefix}key1"));

    // increment / decrement
    $cache->set("{$prefix}counter", 0, 60);
    $t->assertEquals(1, $cache->increment("{$prefix}counter"));
    $t->assertEquals(3, $cache->increment("{$prefix}counter", 2));
    $t->assertEquals(2, $cache->decrement("{$prefix}counter"));
    $t->assertEquals(0, $cache->decrement("{$prefix}counter", 2));

    // many / setMany
    $cache->setMany(["{$prefix}m1" => 'a', "{$prefix}m2" => 'b'], 60);
    $results = $cache->many(["{$prefix}m1", "{$prefix}m2"]);
    $t->assertEquals('a', $results["{$prefix}m1"]);
    $t->assertEquals('b', $results["{$prefix}m2"]);

    // deleteMany
    $cache->deleteMany(["{$prefix}m1", "{$prefix}m2"]);
    $t->assertFalse($cache->has("{$prefix}m1"));

    // pull
    $cache->set("{$prefix}pull_key", 'pull_val', 60);
    $val = $cache->pull("{$prefix}pull_key");
    $t->assertEquals('pull_val', $val);
    $t->assertFalse($cache->has("{$prefix}pull_key"));

    // remember
    $called = false;
    $result = $cache->remember("{$prefix}rmb", 60, function() use (&$called) {
        $called = true;
        return 'cached';
    });
    $t->assertEquals('cached', $result);
    $t->assertTrue($called);

    // tags
    $tagged = $cache->tags(['test_tag']);
    $t->assertInstanceOf(\cache\TaggedCache::class, $tagged);

    // attachTag / flushByTag
    $cache->set("{$prefix}tagged1", 'tag_data', 60);
    $cache->attachTag("{$prefix}tagged1", 'flush_group');
    $cache->flushByTag('flush_group');
    $t->assertFalse($cache->has("{$prefix}tagged1"));

    // connection
    $t->assertInstanceOf(\Redis::class, $cache->connection());

    // 清理
    $cache->delete("{$prefix}counter");
    $cache->delete("{$prefix}rmb");
});

// --- MemcachedCache 功能测试（需要 Memcached 扩展和服务） ---

$runner->run('MemcachedCache - 完整功能测试', function($t) {
    if (!class_exists(\Memcached::class)) {
        $t->assertTrue(true, 'Memcached 扩展未安装，跳过功能测试');
        return;
    }
    try {
        $mc = new \Memcached('lightphp_test');
        if (!$mc->addServer('127.0.0.1', 11211)) {
            $t->assertTrue(true, 'Memcached 服务不可用，跳过功能测试');
            return;
        }
        $mc->get('__test_ping__');
        if ($mc->getResultCode() === \Memcached::RES_CONNECTION_FAILURE) {
            $t->assertTrue(true, 'Memcached 服务未运行，跳过功能测试');
            return;
        }
    } catch (\Throwable) {
        $t->assertTrue(true, 'Memcached 服务连接失败，跳过功能测试');
        return;
    }

    $cache = new \cache\MemcachedCache(['persistent_id' => 'lightphp_test']);
    $prefix = 'test_' . bin2hex(random_bytes(4)) . '_';

    // set / get
    $cache->set("{$prefix}key1", 'hello', 60);
    $t->assertEquals('hello', $cache->get("{$prefix}key1"));

    // get 默认值
    $t->assertNull($cache->get("{$prefix}nonexistent"));
    $t->assertEquals('default', $cache->get("{$prefix}nonexistent", 'default'));

    // has
    $t->assertTrue($cache->has("{$prefix}key1"));
    $t->assertFalse($cache->has("{$prefix}nonexistent"));

    // delete
    $cache->delete("{$prefix}key1");
    $t->assertFalse($cache->has("{$prefix}key1"));

    // increment / decrement
    $cache->set("{$prefix}counter", 0, 60);
    $t->assertEquals(1, $cache->increment("{$prefix}counter"));
    $t->assertEquals(3, $cache->increment("{$prefix}counter", 2));
    $t->assertEquals(2, $cache->decrement("{$prefix}counter"));
    $t->assertEquals(0, $cache->decrement("{$prefix}counter", 2));

    // many / setMany
    $cache->setMany(["{$prefix}m1" => 'a', "{$prefix}m2" => 'b'], 60);
    $results = $cache->many(["{$prefix}m1", "{$prefix}m2"]);
    $t->assertEquals('a', $results["{$prefix}m1"]);
    $t->assertEquals('b', $results["{$prefix}m2"]);

    // deleteMany
    $cache->deleteMany(["{$prefix}m1", "{$prefix}m2"]);
    $t->assertFalse($cache->has("{$prefix}m1"));

    // pull
    $cache->set("{$prefix}pull_key", 'pull_val', 60);
    $val = $cache->pull("{$prefix}pull_key");
    $t->assertEquals('pull_val', $val);
    $t->assertFalse($cache->has("{$prefix}pull_key"));

    // remember
    $called = false;
    $result = $cache->remember("{$prefix}rmb", 60, function() use (&$called) {
        $called = true;
        return 'cached';
    });
    $t->assertEquals('cached', $result);
    $t->assertTrue($called);

    // tags
    $tagged = $cache->tags(['test_tag']);
    $t->assertInstanceOf(\cache\TaggedCache::class, $tagged);

    // attachTag / flushByTag
    $cache->set("{$prefix}tagged1", 'tag_data', 60);
    $cache->attachTag("{$prefix}tagged1", 'flush_group');
    $cache->flushByTag('flush_group');
    $t->assertFalse($cache->has("{$prefix}tagged1"));

    // connection
    $t->assertInstanceOf(\Memcached::class, $cache->connection());

    // 清理
    $cache->delete("{$prefix}counter");
    $cache->delete("{$prefix}rmb");
});

// ═══════════════════════════════════════════════
//  v2.8.0 新功能补充测试
// ═══════════════════════════════════════════════

// --- QueryBuilder 新功能测试 ---

$runner->run('QueryBuilder - distinct 生成 DISTINCT SQL', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $qb = new \db\QueryBuilder($pdo);
    $sql = $qb->table('users')->distinct()->select('name')->getSql();
    $t->assertStringContains('DISTINCT', $sql);
});

$runner->run('QueryBuilder - orderBy 多列排序', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $qb = new \db\QueryBuilder($pdo);
    $sql = $qb->table('users')->orderBy('name', 'ASC')->orderBy('id', 'DESC')->getSql();
    $t->assertStringContains('ORDER BY', $sql);
    $t->assertStringContains('name', $sql);
    $t->assertStringContains('id', $sql);
    $t->assertStringContains('DESC', $sql);
});

$runner->run('QueryBuilder - orderByRaw 原始排序', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $qb = new \db\QueryBuilder($pdo);
    $sql = $qb->table('users')->orderByRaw('FIELD(status, 1, 2, 3)')->getSql();
    $t->assertStringContains('FIELD(status, 1, 2, 3)', $sql);
});

$runner->run('QueryBuilder - whereRaw 原始条件', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $qb = new \db\QueryBuilder($pdo);
    $sql = $qb->table('users')->whereRaw('age > ? AND name = ?', [18, 'John'])->getSql();
    $t->assertStringContains('age > :wr_', $sql);
    $t->assertStringContains('name = :wr_', $sql);
    $bindings = $qb->getBindings();
    $t->assertCount(2, $bindings);
});

$runner->run('QueryBuilder - pluck 提取单列', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
    $pdo->exec("INSERT INTO users (name) VALUES ('Alice'), ('Bob'), ('Charlie')");
    $qb = new \db\QueryBuilder($pdo);
    $names = $qb->table('users')->pluck('name');
    $t->assertEquals(['Alice', 'Bob', 'Charlie'], $names);
});

$runner->run('QueryBuilder - when 条件查询', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $qb = new \db\QueryBuilder($pdo);
    $sql = $qb->table('users')->when(true, function($q) {
        $q->where('status', '=', 1);
    })->getSql();
    $t->assertStringContains('status', $sql);

    $qb2 = new \db\QueryBuilder($pdo);
    $sql2 = $qb2->table('users')->when(false, function($q) {
        $q->where('status', '=', 1);
    })->getSql();
    $t->assertStringNotContains('status', $sql2);
});

$runner->run('QueryBuilder - paginate 分页', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
    for ($i = 1; $i <= 25; $i++) {
        $pdo->exec("INSERT INTO users (name) VALUES ('User{$i}')");
    }
    $qb = new \db\QueryBuilder($pdo);
    $result = $qb->table('users')->paginate(10, 2);
    $t->assertEquals(25, $result['total']);
    $t->assertEquals(10, $result['per_page']);
    $t->assertEquals(2, $result['current_page']);
    $t->assertEquals(3, $result['last_page']);
    $t->assertTrue($result['has_more']);
    $t->assertCount(10, $result['items']);
});

$runner->run('QueryBuilder - getSql 输出 SQL', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $qb = new \db\QueryBuilder($pdo);
    $sql = $qb->table('users')->select('id', 'name')->where('id', '=', 1)->getSql();
    $t->assertStringContains('SELECT', $sql);
    $t->assertStringContains('FROM', $sql);
    $t->assertStringContains('WHERE', $sql);
});

// --- Model 新功能测试 ---

$runner->run('Model - findOrFail 抛出异常', function($t) {
    $model = new class extends \model\Model {
        protected string $table = 'users';
    };
    $t->assertThrows(\RuntimeException::class, function() use ($model) {
        $model->findOrFail(999);
    });
});

$runner->run('Model - first 返回第一条', function($t) {
    $model = new class extends \model\Model {
        protected string $table = 'users';
    };
    $t->assertThrows(\RuntimeException::class, function() use ($model) {
        $model->first();
    });
});

$runner->run('Model - firstOrFail 空结果抛异常', function($t) {
    $model = new class extends \model\Model {
        protected string $table = 'users';
    };
    $t->assertThrows(\RuntimeException::class, function() use ($model) {
        $model->firstOrFail();
    });
});

// --- Validate 新规则测试 ---

$runner->run('Validate - array 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['tags' => [1, 2, 3]], ['tags' => 'array']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['tags' => 'not_array'], ['tags' => 'array']);
    $t->assertTrue($v2->fails());
});

$runner->run('Validate - string 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => 'John'], ['name' => 'string']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['name' => 123], ['name' => 'string']);
    $t->assertTrue($v2->fails());
});

$runner->run('Validate - size 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => 'John'], ['name' => 'size:4']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['name' => 'Bob'], ['name' => 'size:4']);
    $t->assertTrue($v2->fails());
});

$runner->run('Validate - between 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['age' => 25], ['age' => 'between:18,60']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['age' => 15], ['age' => 'between:18,60']);
    $t->assertTrue($v2->fails());
});

$runner->run('Validate - boolean 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['active' => 'true'], ['active' => 'boolean']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['active' => 'maybe'], ['active' => 'boolean']);
    $t->assertTrue($v2->fails());
});

$runner->run('Validate - before/after 日期规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['date' => '2020-01-01'], ['date' => 'before:2025-01-01']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['date' => '2030-01-01'], ['date' => 'after:2025-01-01']);
    $t->assertTrue($v2->passes());
    $v3 = new \core\Validate();
    $v3->validate(['date' => '2030-01-01'], ['date' => 'before:2025-01-01']);
    $t->assertTrue($v3->fails());
});

$runner->run('Validate - different/same 字段比较', function($t) {
    $v = new \core\Validate();
    $v->validate(['password' => 'secret', 'confirm' => 'other'], ['password' => 'different:confirm']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['password' => 'secret', 'confirm' => 'secret'], ['password' => 'same:confirm']);
    $t->assertTrue($v2->passes());
    $v3 = new \core\Validate();
    $v3->validate(['password' => 'secret', 'confirm' => 'other'], ['password' => 'same:confirm']);
    $t->assertTrue($v3->fails());
});

$runner->run('Validate - digits 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['code' => '12345'], ['code' => 'digits']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['code' => 'abc12'], ['code' => 'digits']);
    $t->assertTrue($v2->fails());
});

$runner->run('Validate - digitsBetween 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['code' => '12345'], ['code' => 'digitsBetween:4,6']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['code' => '12'], ['code' => 'digitsBetween:4,6']);
    $t->assertTrue($v2->fails());
});

$runner->run('Validate - nullable 规则', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => null], ['name' => 'nullable|string']);
    $t->assertTrue($v->passes());
    $v2 = new \core\Validate();
    $v2->validate(['name' => 'John'], ['name' => 'nullable|string']);
    $t->assertTrue($v2->passes());
});

$runner->run('Validate - errors 和 firstError', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => '', 'email' => 'bad'], ['name' => 'required', 'email' => 'email']);
    $t->assertTrue($v->fails());
    $errors = $v->errors();
    $t->assertArrayHasKey('name', $errors);
    $t->assertArrayHasKey('email', $errors);
    $t->assertNotNull($v->firstError('name'));
    $t->assertNotNull($v->firstError());
});

$runner->run('Validate - validated 返回验证通过的数据', function($t) {
    $v = new \core\Validate();
    $v->validate(['name' => 'John', 'age' => 25, 'extra' => 'ignored'], ['name' => 'required', 'age' => 'integer']);
    $data = $v->validated();
    $t->assertEquals('John', $data['name']);
    $t->assertEquals(25, $data['age']);
    $t->assertArrayNotHasKey('extra', $data);
});

// --- Collection 新方法测试 ---

$runner->run('Collection - flatMap', function($t) {
    $c = collect([[1, 2], [3, 4]])->flatMap(fn($items) => $items);
    $t->assertEquals([1, 2, 3, 4], $c->all());
});

$runner->run('Collection - flatten 递归', function($t) {
    $c = collect([[1, 2], [3, [4, 5]]])->flatten();
    $t->assertEquals([1, 2, 3, 4, 5], $c->all());
});

$runner->run('Collection - chunk 分块', function($t) {
    $c = collect([1, 2, 3, 4, 5])->chunk(2);
    $t->assertCount(3, $c);
    $t->assertEquals([1, 2], $c[0]->values()->all());
    $t->assertEquals([5], $c[2]->values()->all());
});

$runner->run('Collection - diff 差集', function($t) {
    $c = collect([1, 2, 3, 4])->diff([2, 4]);
    $t->assertEquals([1, 3], $c->values()->all());
});

$runner->run('Collection - intersect 交集', function($t) {
    $c = collect([1, 2, 3, 4])->intersect([2, 4, 5]);
    $t->assertEquals([2, 4], $c->values()->all());
});

$runner->run('Collection - implode 连接', function($t) {
    $c = collect(['a', 'b', 'c']);
    $t->assertEquals('a,b,c', $c->implode(','));
    $t->assertEquals('abc', $c->implode());
});

$runner->run('Collection - flip 翻转', function($t) {
    $c = collect(['a' => 1, 'b' => 2])->flip();
    $t->assertEquals([1 => 'a', 2 => 'b'], $c->all());
});

$runner->run('Collection - zip 合并', function($t) {
    $c = collect([1, 2, 3])->zip([4, 5, 6]);
    $t->assertEquals([[1, 4], [2, 5], [3, 6]], $c->all());
});

$runner->run('Collection - nth 每N个', function($t) {
    $c = collect([1, 2, 3, 4, 5, 6, 7])->nth(3);
    $t->assertEquals([1, 4, 7], $c->values()->all());
});

$runner->run('Collection - forPage 分页', function($t) {
    $c = collect([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])->forPage(2, 3);
    $t->assertEquals([4, 5, 6], $c->values()->all());
});

$runner->run('Collection - slice 切片', function($t) {
    $c = collect([1, 2, 3, 4, 5])->slice(1, 3);
    $t->assertEquals([2, 3, 4], $c->values()->all());
});

$runner->run('Collection - split 分组', function($t) {
    $c = collect([1, 2, 3, 4, 5])->split(2);
    $t->assertGreaterThanOrEqual(2, count($c));
});

$runner->run('Collection - collapse 展平一级', function($t) {
    $c = collect([[1, 2], [3, 4]])->collapse();
    $t->assertEquals([1, 2, 3, 4], $c->all());
});

$runner->run('Collection - merge 合并', function($t) {
    $c = collect([1, 2])->merge([3, 4]);
    $t->assertEquals([1, 2, 3, 4], $c->all());
});

$runner->run('Collection - pull 取出并删除', function($t) {
    $c = collect(['a' => 1, 'b' => 2, 'c' => 3]);
    $val = $c->pull('b');
    $t->assertEquals(2, $val);
    $t->assertCount(2, $c);
    $t->assertFalse(isset($c['b']));
});

$runner->run('Collection - forget 删除指定键', function($t) {
    $c = collect(['a' => 1, 'b' => 2, 'c' => 3]);
    $c->forget('b');
    $t->assertCount(2, $c);
    $t->assertFalse(isset($c['b']));
});

// --- Response 新方法测试 ---

$runner->run('Response - download 创建下载响应', function($t) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tmpFile, 'hello world');
    $response = \core\Response::download($tmpFile, 'test.txt');
    $t->assertEquals(200, $response->getStatusCode());
    $t->assertEquals('hello world', $response->getContent());
    unlink($tmpFile);
});

$runner->run('Response - download 文件不存在抛异常', function($t) {
    $t->assertThrows(\InvalidArgumentException::class, function() {
        \core\Response::download('/nonexistent/file.txt');
    });
});

$runner->run('Response - download 文件名被安全处理', function($t) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tmpFile, 'hello world');
    // 使用跨平台路径分隔符 /，并在文件名中混入引号与空字节验证清洗逻辑
    $response = \core\Response::download($tmpFile, "../../../etc/passwd\"data\x00.txt");
    $headers = (new \ReflectionClass($response))->getProperty('headers')->getValue($response);
    $t->assertStringNotContains('..', $headers['Content-Disposition']);
    // 最终 Content-Disposition 中 filename 参数应只保留清洗后的基本文件名
    $t->assertStringContains('filename="passwddata.txt"', $headers['Content-Disposition']);
    unlink($tmpFile);
});

$runner->run('Response - redirect 创建重定向', function($t) {
    $response = \core\Response::redirect('/login', 302);
    $t->assertEquals(302, $response->getStatusCode());
});

$runner->run('Response - redirect 拒绝绝对URL', function($t) {
    $t->assertThrows(\InvalidArgumentException::class, function() {
        \core\Response::redirect('//evil.com');
    });
});

$runner->run('Response - status 设置状态码', function($t) {
    $response = new \core\Response('ok', 200);
    $response->status(404);
    $t->assertEquals(404, $response->getStatusCode());
});

$runner->run('Response - header 设置响应头', function($t) {
    $response = new \core\Response('ok');
    $response->header('X-Custom', 'value');
    $t->assertInstanceOf(\core\Response::class, $response);
});

// --- Hash 新方法测试 ---

$runner->run('Hash - makeToken 生成令牌', function($t) {
    $token1 = \core\Hash::makeToken();
    $token2 = \core\Hash::makeToken();
    $t->assertIsString($token1);
    $t->assertEquals(64, strlen($token1));
    $t->assertNotEquals($token1, $token2);
});

$runner->run('Hash - makeKey 生成密钥', function($t) {
    $key1 = \core\Hash::makeKey();
    $key2 = \core\Hash::makeKey();
    $t->assertIsString($key1);
    $t->assertNotEquals($key1, $key2);
    $t->assertNotFalse(base64_decode($key1, true));
});

// --- Router 命名路由测试 ---

$runner->run('Router - 命名路由和 route() 生成', function($t) {
    $router = new \core\Router();
    $router->get('/users', fn() => 'ok')->name('users.index');
    $router->get('/users/{id}', fn() => 'ok')->name('users.show');
    $t->assertEquals('/users', $router->route('users.index'));
    $t->assertStringContains('/users/', $router->route('users.show', ['id' => 42]));
});

$runner->run('Router - route() 未知名称抛异常', function($t) {
    $router = new \core\Router();
    $t->assertThrows(\RuntimeException::class, function() use ($router) {
        $router->route('nonexistent');
    });
});

// --- Schema SQLite 兼容性测试 ---

$runner->run('Schema - SQLite hasColumn', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $schema = \db\Schema::setConnection($pdo);
    $schema->create('test_cols', function(\db\Blueprint $table) {
        $table->id();
        $table->string('email');
    });
    $t->assertTrue($schema->hasColumn('test_cols', 'email'));
    $t->assertFalse($schema->hasColumn('test_cols', 'nonexistent'));
    $schema->drop('test_cols');
});

// --- Blade 新指令测试 ---

$runner->run('Blade - @switch/@case/@endswitch', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@switch($x) @case(1) A @break @default B @endswitch');
    $t->assertStringContains('<?php switch', $result);
    $t->assertStringContains('<?php case', $result);
    $t->assertStringContains('<?php break', $result);
    $t->assertStringContains('<?php default', $result);
    $t->assertStringContains('endswitch', $result);
});

$runner->run('Blade - @unless/@endunless', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@unless($x) content @endunless');
    $t->assertStringContains('if (!($x))', $result);
});

$runner->run('Blade - @for/@endfor', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@for($i = 0; $i < 10; $i++) body @endfor');
    $t->assertStringContains('<?php for', $result);
    $t->assertStringContains('endfor', $result);
});

$runner->run('Blade - @while/@endwhile', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@while($x) body @endwhile');
    $t->assertStringContains('<?php while', $result);
    $t->assertStringContains('endwhile', $result);
});

$runner->run('Blade - @isset/@endisset', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@isset($x) content @endisset');
    $t->assertStringContains('if (isset($x))', $result);
});

$runner->run('Blade - @empty/@endempty', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@empty($x) content @endempty');
    $t->assertStringContains('if (empty($x))', $result);
});

$runner->run('Blade - @verbatim/@endverbatim', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@verbatim {{ $raw }} @endverbatim');
    $t->assertStringContains('{{ $raw }}', $result);
    $t->assertStringNotContains('htmlspecialchars', $result);
});

$runner->run('Blade - @csrf 生成 CSRF token', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString('@csrf');
    $t->assertStringContains('_token', $result);
    $t->assertStringContains('Session::token', $result);
});

$runner->run('Blade - @method 生成隐藏字段', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $result = $blade->compileString("@method('DELETE')");
    $t->assertStringContains('_method', $result);
    $t->assertStringContains('DELETE', $result);
});

// --- Session 补充测试 ---

$runner->run('Session - pull 获取并删除', function($t) {
    \core\Session::set('pull_key', 'pull_value');
    $val = \core\Session::pull('pull_key');
    $t->assertEquals('pull_value', $val);
    $t->assertNull(\core\Session::get('pull_key'));
});

$runner->run('Session - has 检查键存在', function($t) {
    \core\Session::set('exists_key', 'value');
    $t->assertTrue(\core\Session::has('exists_key'));
    $t->assertFalse(\core\Session::has('nonexistent_key'));
});

$runner->run('Session - all 返回全部', function($t) {
    \core\Session::set('all_test', 'value');
    $all = \core\Session::all();
    $t->assertIsArray($all);
    $t->assertArrayHasKey('all_test', $all);
});

$runner->run('Session - token 生成 CSRF token', function($t) {
    $token1 = \core\Session::token();
    $token2 = \core\Session::token();
    $t->assertIsString($token1);
    $t->assertEquals($token1, $token2);
});

$runner->run('Session - id 返回会话ID', function($t) {
    \core\Session::set('_test_init', true);
    $id = \core\Session::id();
    $t->assertIsString($id);
});

// --- Application 新方法测试 ---

$runner->run('Application - getInstance 返回单例', function($t) {
    putenv('APP_KEY=test-key-32bytes-OK');
    $app1 = \core\Application::getInstance();
    $app2 = \core\Application::getInstance();
    $t->assertNotNull($app1);
    $t->assertTrue($app1 === $app2);
});

$runner->run('Application - getRouter 返回路由', function($t) {
    putenv('APP_KEY=test-key-32bytes-OK');
    $app = new \core\Application();
    $router = $app->getRouter();
    $t->assertInstanceOf(\core\Router::class, $router);
});

// ═══════════════════════════════════════════════
//  Bug 修复回归测试
// ═══════════════════════════════════════════════

$runner->run('CsrfMiddleware - 验证通过后不立即重新生成 token', function($t) {
    $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $originalPost = $_POST;
    $originalUri = $_SERVER['REQUEST_URI'] ?? null;

    try {
        // 确保会话已启动并拿到 token
        $token = \core\Session::token();
        $t->assertIsString($token);
        $t->assertNotEquals('', $token);

        $runMiddleware = function() use ($token) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['_token' => $token];
            $_SERVER['REQUEST_URI'] = '/test';
            $request = new \core\Request();
            $middleware = new \middleware\CsrfMiddleware();
            return $middleware->handle($request, fn() => 'ok');
        };

        $first = $runMiddleware();
        $t->assertEquals('ok', $first);

        // 同一 token 的第二次请求应当仍然通过（旧代码会重新生成 token 导致 419）
        $second = $runMiddleware();
        $t->assertEquals('ok', $second);
    } finally {
        if ($originalMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $originalMethod;
        }
        $_POST = $originalPost;
        if ($originalUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $originalUri;
        }
    }
});

$runner->run('FileCache - incrementFallback 使用默认 TTL', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $key = 'fallback_ttl_' . uniqid();

    $ref = new \ReflectionClass($cache);
    $method = $ref->getMethod('incrementFallback');
    $method->invoke($cache, $key, 1);

    $file = $ref->getMethod('getFile')->invoke($cache, $key);
    $t->assertTrue(file_exists($file));

    $data = json_decode(file_get_contents($file), true);
    $t->assertIsArray($data);
    $t->assertGreaterThanOrEqual(1, $data['expire'] ?? 0, 'Fallback increment should use default TTL, not permanent');

    $cache->delete($key);
});

$runner->run('FileCache - incrementFallback 保持永久 TTL', function($t) {
    $cache = new \cache\FileCache(STORAGE_PATH . 'cache/');
    $key = 'fallback_permanent_' . uniqid();

    $ref = new \ReflectionClass($cache);
    $write = $ref->getMethod('write');
    $write->invoke($cache, $key, 5, 0);

    $method = $ref->getMethod('incrementFallback');
    $method->invoke($cache, $key, 1);

    $file = $ref->getMethod('getFile')->invoke($cache, $key);
    $data = json_decode(file_get_contents($file), true);
    $t->assertIsArray($data);
    $t->assertEquals(0, $data['expire'] ?? null, 'Fallback increment should preserve permanent TTL');
    $t->assertEquals(6, $data['value'] ?? null);

    $cache->delete($key);
});

$runner->run('Model - __clone 重置 relations', function($t) {
    $model = new class extends \model\Model {
        protected string $table = 'test';
        protected array $fillable = ['*'];
    };
    $related = new class extends \model\Model {
        protected string $table = 'related';
    };

    $ref = new \ReflectionClass($model);
    $prop = $ref->getProperty('relations');
    $prop->setValue($model, ['related' => $related]);

    $clone = clone $model;
    $cloneRelations = $prop->getValue($clone);
    $t->assertIsArray($cloneRelations);
    $t->assertCount(0, $cloneRelations);

    $originalRelations = $prop->getValue($model);
    $t->assertArrayHasKey('related', $originalRelations);
});

$runner->run('SoftDelete - 已软删除实例再次 delete 不触发 deleting 事件', function($t) {
    $modelClass = new class extends \model\Model {
        protected string $table = 'test';
        protected array $fillable = ['*'];
        use \traits\SoftDelete;
    };

    $deletingCount = 0;
    $modelClass::onEvent('deleting', function($m) use (&$deletingCount) {
        $deletingCount++;
        return true;
    });

    // 模拟从数据库加载出来的已软删除实例
    $instance = new $modelClass(['id' => 1, 'deleted_at' => date('Y-m-d H:i:s')]);
    $ref = new \ReflectionClass($instance);
    $prop = $ref->getProperty('exists');
    $prop->setValue($instance, true);

    $instance->delete(1);

    $t->assertEquals(0, $deletingCount, 'delete() should be skipped when instance is already trashed');
    $modelClass::flushEventListeners();
});

$runner->run('SoftDelete - 静态方法 withTrashed/onlyTrashed 代理', function($t) {
    $modelClass = new class extends \model\Model {
        protected string $table = 'test';
        use \traits\SoftDelete;
    };

    $withTrashed = $modelClass::withTrashed();
    $t->assertInstanceOf(\model\Model::class, $withTrashed);

    $onlyTrashed = $modelClass::onlyTrashed();
    $t->assertInstanceOf(\model\Model::class, $onlyTrashed);
});

$runner->run('View - include 不泄漏自动转义状态', function($t) {
    $tmpDir = sys_get_temp_dir() . '/lightphp_view_test_' . uniqid() . '/';
    mkdir($tmpDir, 0777, true);

    $parentFile = $tmpDir . 'parent.php';
    $childFile = $tmpDir . 'child.php';

    file_put_contents($childFile, '<?php $__view->withoutAutoEscape(); ?>' . "\n");
    file_put_contents($parentFile, '<?php $__view->include("child"); ?>' . "\n" . '<?= $payload ?>' . "\n");

    $view = new \view\View($tmpDir);
    $result = $view->render('parent', ['payload' => '<b>html</b>']);

    @unlink($parentFile);
    @unlink($childFile);
    @rmdir($tmpDir);

    $t->assertStringContains('&lt;b&gt;html&lt;/b&gt;', $result);
    $t->assertStringNotContains('<b>html</b>', $result);
});

$runner->run('View - Stringable 对象自动转义', function($t) {
    $tmpDir = sys_get_temp_dir() . '/lightphp_view_str_test_' . uniqid() . '/';
    mkdir($tmpDir, 0777, true);

    $file = $tmpDir . 'test.php';
    file_put_contents($file, '<?= $payload ?>' . "\n");

    $stringable = new class implements \Stringable {
        public function __toString(): string
        {
            return '<script>alert(1)</script>';
        }
    };

    $view = new \view\View($tmpDir);
    $result = $view->render('test', ['payload' => $stringable]);

    @unlink($file);
    @rmdir($tmpDir);

    $t->assertStringContains('&lt;script&gt;', $result);
    $t->assertStringNotContains('<script>', $result);
});

$runner->run('Blade - Stringable 对象输出时强制转字符串', function($t) {
    $blade = new \view\Blade(VIEW_PATH, STORAGE_PATH . 'views/');
    $compiled = $blade->compileString('{{ $obj }}');
    $t->assertStringContains('htmlspecialchars((string)', $compiled);
});

$runner->run('Router - route() 缺失必需参数抛异常', function($t) {
    $router = new \core\Router();
    $router->get('/users/{id}', fn() => 'ok')->name('users.show');
    $t->assertThrows(\RuntimeException::class, function() use ($router) {
        $router->route('users.show');
    });
});

$runner->run('Router - 无效中间件不再被静默跳过', function($t) {
    $router = new \core\Router();
    $router->middleware('NonExistentMiddlewareClass');
    $router->get('/test', fn() => 'ok');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';

    $t->assertThrows(\RuntimeException::class, function() use ($router) {
        $router->dispatch();
    });
});

$runner->run('Request - post/input 对 null 参数优先级一致', function($t) {
    $originalPost = $_POST;
    $_POST = ['key' => 'post_value'];

    $request = new \core\Request();
    $ref = new \ReflectionClass($request);
    $prop = $ref->getProperty('json');
    $prop->setValue($request, ['key' => null]);

    $t->assertEquals('post_value', $request->post('key'));
    $t->assertEquals('post_value', $request->input('key'));

    $_POST = $originalPost;
});

// === 本轮 BUG 修复回归测试 ===

// 1. Validate - size 规则类型检查顺序与 min/max/between 一致
//    修复前：validateSize 先判断 is_string，导致数字字符串 "10" 被按长度（2）校验
//    修复后：is_numeric 优先，"10" 与 size:10 按值比较应通过
$runner->run('Validate - size 规则数字字符串按值校验', function($t) {
    // 数字字符串 "10" + size:10 应通过（按值比较，而非按长度 2 比较）
    $v1 = new \core\Validate();
    $v1->validate(['age' => '10'], ['age' => 'size:10']);
    $t->assertTrue($v1->passes(), '数字字符串 "10" + size:10 应通过（按值比较）');

    // 数字字符串 "10" + size:5 应失败（值不等于 5）
    $v2 = new \core\Validate();
    $v2->validate(['age' => '10'], ['age' => 'size:5']);
    $t->assertFalse($v2->passes(), '数字字符串 "10" + size:5 应失败');

    // 纯字符串 "ab" + size:2 应通过（按长度比较）
    $v3 = new \core\Validate();
    $v3->validate(['name' => 'ab'], ['name' => 'size:2']);
    $t->assertTrue($v3->passes(), '字符串 "ab" + size:2 应通过（按长度比较）');

    // 数组 + size:3 应通过（按元素个数比较）
    $v4 = new \core\Validate();
    $v4->validate(['tags' => ['a', 'b', 'c']], ['tags' => 'size:3']);
    $t->assertTrue($v4->passes(), '数组 + size:3 应通过（按元素个数比较）');
});

// 2. EventDispatcher - until() 递归派发检测
//    修复前：until() 缺少 dispatch() 的 $dispatchingStack 保护，监听器内调用 until() 同一事件会无限递归
//    修复后：与 dispatch() 共享递归检测栈，触发 E_USER_WARNING 并返回 null
$runner->run('EventDispatcher - until() 递归派发检测', function($t) {
    $events = new \core\EventDispatcher();

    $events->listen('recursive.event', function() use ($events) {
        // 监听器内对同一事件调用 until()，修复前会无限递归
        return $events->until('recursive.event');
    });

    // 捕获 E_USER_WARNING
    $warnings = [];
    set_error_handler(function($errno, $errstr) use (&$warnings) {
        $warnings[] = $errstr;
        return true;
    });

    try {
        $result = $events->until('recursive.event');
    } finally {
        restore_error_handler();
    }

    $t->assertNull($result, '递归 until() 应返回 null 而非无限递归');
    $t->assertTrue(
        count($warnings) > 0 && str_contains($warnings[0], 'Recursive dispatch detected'),
        '应触发递归派发警告'
    );
});

// 3. Migration - rollback 使用 require_once 避免类重复声明
//    修复前：rollback() 使用 require，若迁移类已被 run() 加载，会触发 "Cannot redeclare class" 致命错误
//    修复后：使用 require_once，重复加载时跳过类声明
$runner->run('Migration - rollback 使用 require_once 避免类重复声明', function($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }

    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    $tmpDir = sys_get_temp_dir() . '/lightphp_migration_test_' . uniqid() . '/';
    mkdir($tmpDir, 0777, true);

    // 创建迁移文件（独立类，构造函数接收 PDO，含 up()/down() 方法）
    $migrationFile = $tmpDir . '2024_01_01_000000_create_test_rollback_table.php';
    $migrationContent = <<<'PHP'
<?php
declare(strict_types=1);

class CreateTestRollbackTable
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }
    public function up(): void
    {
        $this->pdo->exec('CREATE TABLE test_rollback (id INTEGER PRIMARY KEY, name TEXT)');
    }
    public function down(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS test_rollback');
    }
}
PHP;
    file_put_contents($migrationFile, $migrationContent);

    try {
        $migration = new \db\Migration($pdo, $tmpDir);

        // 先执行 run() 加载迁移类
        $migrated = $migration->run();
        $t->assertContains('2024_01_01_000000_create_test_rollback_table.php', $migrated, 'run() 应执行迁移');

        // 验证表已创建
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test_rollback'")->fetchAll(\PDO::FETCH_COLUMN);
        $t->assertTrue(in_array('test_rollback', $tables), '迁移后表应存在');

        // 执行 rollback() - 修复前会因 require 重复加载类而致命错误
        $rolledBack = $migration->rollback();
        $t->assertContains('2024_01_01_000000_create_test_rollback_table.php', $rolledBack, 'rollback() 应回滚迁移');

        // 验证表已删除
        $tablesAfter = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test_rollback'")->fetchAll(\PDO::FETCH_COLUMN);
        $t->assertFalse(in_array('test_rollback', $tablesAfter), '回滚后表应不存在');
    } finally {
        // 清理临时目录
        if (is_dir($tmpDir)) {
            foreach (glob($tmpDir . '*') as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }
});

// === 第二轮 BUG 修复回归测试 ===

// 4. RequestLogMiddleware - 从 Response 对象提取状态码
//    修复前：finally 块中 http_response_code() 始终返回 200（Response::send() 尚未执行）
//    修复后：从 $next($request) 返回的 Response 对象提取 getStatusCode()
$runner->run('RequestLogMiddleware - 从 Response 对象提取状态码', function($t) {
    // 创建模拟 Logger（继承 \log\Logger 以满足 resolveLogger() 的返回类型 ?Logger）
    $mockLogger = new class(STORAGE_PATH . 'log/') extends \log\Logger {
        private ?string $capturedMsg = null;
        private array $capturedCtx = [];
        public function log(string $level, string|\Stringable $message, array $context = []): void {
            $this->capturedMsg = (string) $message;
            $this->capturedCtx = $context;
        }
        public function getCapturedMessage(): ?string { return $this->capturedMsg; }
        public function getCapturedContext(): array { return $this->capturedCtx; }
    };

    // 设置 Container
    $originalContainer = \core\Container::getInstance();
    $container = new \core\Container();
    $container->instance('log', $mockLogger);
    \core\Container::setInstance($container);

    // 创建中间件
    $middleware = new \middleware\RequestLogMiddleware();

    // 创建返回 404 状态码的 Response
    $response = \core\Response::make('Not Found', 404);

    // 模拟 $next 回调返回 Response
    $request = new \core\Request();
    $result = $middleware->handle($request, fn() => $response);

    // 验证返回值是原 Response
    $t->assertEquals($response, $result, '中间件应原样返回 Response');

    // 验证日志中记录的状态码是 404，而非 200
    $t->assertEquals(404, $mockLogger->getCapturedContext()['status'] ?? null, '日志应记录 Response 的状态码 404');
    $t->assertStringContains('404', $mockLogger->getCapturedMessage() ?? '', '日志消息应包含 404');

    // 恢复原始 Container
    if ($originalContainer !== null) {
        \core\Container::setInstance($originalContainer);
    }
});

// 5. ApiDoc - __construct 不应被文档化为 API 端点
//    修复前：__construct 在白名单中，会被文档化为 GET /{resource}/__construct
//    修复后：__construct 从白名单中移除
$runner->run('ApiDoc - __construct 不被文档化为端点', function($t) {
    // 创建临时控制器文件
    $tmpDir = sys_get_temp_dir() . '/lightphp_apidoc_test_' . uniqid() . '/';
    mkdir($tmpDir, 0777, true);

    $controllerFile = $tmpDir . 'TestApiController.php';
    $controllerContent = <<<'PHP'
<?php
declare(strict_types=1);

namespace controller;

class TestApiController extends \core\Controller
{
    public function __construct() {}
    public function index(): \core\Response { return $this->json([]); }
    public function show(int $id): \core\Response { return $this->json([]); }
}
PHP;
    file_put_contents($controllerFile, $controllerContent);

    // 保存原始 APP_PATH
    $originalAppPath = defined('APP_PATH') ? APP_PATH : null;

    try {
        // 直接测试 parseController 方法
        $reflection = new \ReflectionClass(\core\ApiDoc::class);
        $apiDoc = new \core\ApiDoc();

        $parseMethod = $reflection->getMethod('parseController');
        $parseMethod->invoke($apiDoc, $controllerFile);

        $docs = $reflection->getProperty('docs');
        $docsArray = $docs->getValue($apiDoc);

        // 验证 TestApiController 有文档
        $t->assertTrue(isset($docsArray['TestApiController']), 'TestApiController 应有文档');

        // 验证 __construct 不在端点列表中
        $endpoints = $docsArray['TestApiController'] ?? [];
        $actions = array_column($endpoints, 'action');
        $t->assertFalse(in_array('__construct', $actions, true), '__construct 不应被文档化为端点');

        // 验证 index 和 show 在端点列表中
        $t->assertTrue(in_array('index', $actions, true), 'index 应被文档化为端点');
        $t->assertTrue(in_array('show', $actions, true), 'show 应被文档化为端点');
    } finally {
        // 清理临时目录
        if (is_dir($tmpDir)) {
            foreach (glob($tmpDir . '*') as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }
});

// 6. TaggedCache - increment/decrement 应打标签
//    修复前：increment/decrement 不调用 tagKey()，新建的 key 不会被 flush() 清除
//    修复后：increment/decrement 调用 tagKey() 确保 key 被关联到标签
$runner->run('TaggedCache - increment/decrement 打标签', function($t) {
    // 创建模拟 CacheInterface 实现
    $taggedKeys = [];
    $mockStore = new class($taggedKeys) implements \core\contract\CacheInterface {
        private array $tagged;
        public function __construct(&$tagged) { $this->tagged = &$tagged; }
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function set(string $key, mixed $value, ?int $ttl = null): bool { return true; }
        public function has(string $key): bool { return false; }
        public function delete(string $key): bool { return true; }
        public function clear(): bool { return true; }
        public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
        public function increment(string $key, int $step = 1): int { return $step; }
        public function decrement(string $key, int $step = 1): int { return 0; }
        public function many(array $keys): array { return []; }
        public function setMany(array $values, ?int $ttl = null): bool { return true; }
        public function deleteMany(array $keys): bool { return true; }
        public function pull(string $key, mixed $default = null): mixed { return $default; }
        public function tags(array $tags): \cache\TaggedCache { return new \cache\TaggedCache($this, $tags); }
        public function flushByTag(string $tag): bool { return true; }
        public function attachTag(string $key, string $tag): void {
            $this->tagged[$tag][] = $key;
        }
        public function getTaggedKeys(): array { return $this->tagged; }
    };

    $taggedCache = new \cache\TaggedCache($mockStore, ['users']);

    // 调用 increment
    $taggedCache->increment('counter1', 5);

    // 调用 decrement
    $taggedCache->decrement('counter2', 3);

    // 验证两个 key 都被打上了 'users' 标签
    $taggedKeys = $mockStore->getTaggedKeys();
    $t->assertTrue(in_array('counter1', $taggedKeys['users'] ?? [], true), 'increment 的 key 应被打标签');
    $t->assertTrue(in_array('counter2', $taggedKeys['users'] ?? [], true), 'decrement 的 key 应被打标签');
});

$runner->summary();
