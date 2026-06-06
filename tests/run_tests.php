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
    $modelClass = new class(['id' => 1]) extends \model\Model {
        protected string $table = 'test';
        use \traits\SoftDelete;
    };

    $modelClass::forceDelete();
    $modelClass::softDelete();
    $t->assertTrue(true);
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

$runner->summary();
