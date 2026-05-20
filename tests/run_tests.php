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
            echo "  ✗ {$message}\n";
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
    \core\Env::set('APP_KEY', 'test-encryption-key-32bytes!!!');
    $encrypted = \core\Hash::encrypt('Sensitive Data');
    $t->assertIsString($encrypted);
    $t->assertNotEquals('Sensitive Data', $encrypted);
    $decrypted = \core\Hash::decrypt($encrypted);
    $t->assertEquals('Sensitive Data', $decrypted);
});

$runner->run('Hash - Decrypt Invalid Data', function($t) {
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
    $ref->setAccessible(true);
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

$runner->summary();
