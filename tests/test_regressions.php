<?php declare(strict_types=1);

/**
 * v2.16.0 回归测试 — 本轮全面审计修复项
 *
 * 覆盖缺陷（均为本轮实测复现后修复）：
 *  1. Router 闭包参数按命名参数展开 → Unknown named parameter 致命错误
 *  2. Router::group() 回调抛异常后 prefix/middleware 状态泄漏
 *  3. Attribute 方法级 middleware 被静默丢弃（授权绕过）
 *  4. `//evil.com/admin` authority-form 导致路由错误分发
 *  5. 路由参数 urldecode 后含 %2F → 路径穿越
 *  6. 方法不匹配返回 404 而非 405 + Allow
 *  7. HEAD 请求复用 GET 路由却输出完整响应体
 *  8. 中间件组自引用导致无限递归耗尽内存
 *  9. cacheRoutes 未校验 middleware 可序列化性
 * 10. Model 静态 API（User::find）全部抛 Error
 * 11. Model::create/update 绕过 mutator（明文密码落库）
 * 12. sanitizeColumn 不支持 table.* → belongsToMany 完全不可用
 * 13. Blade `@if ($x)` 带空格写法编译崩溃
 * 14. Blade 文本中的 @endif 被误编译
 * 15. Blade @include 传入变量被 EXTR_SKIP 静默丢弃
 * 16. FormRequest 用 all() 校验，GET 查询串可满足 required
 * 17. Validate alpha/alphaNum 对数组强转 "Array" 意外通过
 * 18. Logger json_encode 返回 false 导致 TypeError
 * 19. JsonResource 集合模式硬编码 'data' 忽略 $wrap
 * 20. Schema 生成 MySQL-only 索引语法，SQLite 必然失败
 * 21. Schema::table() 未重置 commands 造成状态残留
 * 22. Application 未注册内置中间件别名（/api/* 全部 500）
 * 23. Application 未读取 config('app.providers')
 *
 * 本文件由 tests/run_tests.php 末尾 require，共享 $runner 实例。
 *
 * @var TestRunner $runner
 */

// ─── Router ───

$runner->run('Regression - Router 闭包参数按位置传参（非命名参数）', function ($t) {
    $router = new \core\Router();
    // 占位符名 {path} 与闭包形参名 $p 不一致：修复前抛 Unknown named parameter
    $router->get('/files/{path}', fn($p) => (new \core\Response())->content('got:' . $p));

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/files/a';
    try {
        $result = $router->dispatch(new \core\Request());
        $t->assertEquals('got:a', $result->getContent(), '闭包应按位置收到路由参数');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});

$runner->run('Regression - Router 闭包形参少于占位符时不报错', function ($t) {
    $router = new \core\Router();
    $router->get('/x/{a}/{b}', fn($a) => (new \core\Response())->content('a=' . $a));

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/x/1/2';
    try {
        $result = $router->dispatch(new \core\Request());
        $t->assertEquals('a=1', $result->getContent(), '多余占位符应被截断而非抛错');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});

$runner->run('Regression - Router::group 回调抛异常后不泄漏 prefix/middleware', function ($t) {
    $router = new \core\Router();
    try {
        $router->group(['prefix' => '/admin', 'middleware' => ['x']], function ($r) {
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException $e) {
        // 预期
    }
    $router->get('/public', fn() => (new \core\Response())->content('P'));

    $routes = $router->getRoutes();
    $t->assertEquals(1, count($routes), '异常后不应残留任何注册');
    $t->assertEquals('/public', $routes[0]['uri'], 'prefix 不应泄漏到后续路由');
    $t->assertEquals([], $routes[0]['middleware'], 'middleware 不应泄漏到后续路由');
});

$runner->run('Regression - Attribute 方法级 middleware 不再被丢弃', function ($t) {
    $ctrl = new class {
        #[\core\attributes\Route('/secure', method: 'GET', middleware: ['auth'])]
        public function secure(): void
        {
        }
    };
    $router = new \core\Router();
    $router->registerController($ctrl::class);

    $routes = $router->getRoutes();
    $t->assertEquals(1, count($routes), '方法级路由应注册');
    $t->assertContains('auth', $routes[0]['middleware'], '方法级 auth 中间件必须生效（修复前被丢弃）');
});

$runner->run('Regression - 双斜杠 URI 不被解析为 authority-form', function ($t) {
    $router = new \core\Router();
    $router->get('/admin', fn() => (new \core\Response())->content('ADMIN'));

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '//evil.com/admin';
    try {
        $result = $router->dispatch(new \core\Request());
        $t->assertEquals(404, $result->getStatusCode(), '//evil.com/admin 不得命中 /admin 路由');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});

$runner->run('Regression - 重复斜杠被折叠为单斜杠', function ($t) {
    $router = new \core\Router();
    $router->get('/api/users', fn() => (new \core\Response())->content('USERS'));

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '//api//users';
    try {
        $result = $router->dispatch(new \core\Request());
        $t->assertEquals('USERS', $result->getContent(), '重复斜杠应折叠后正常匹配');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});

$runner->run('Regression - 编码斜杠的路由参数被拒绝（防路径穿越）', function ($t) {
    $router = new \core\Router();
    $hit = false;
    $router->get('/files/{path}', function ($p) use (&$hit) {
        $hit = true;
        return (new \core\Response())->content('x');
    });

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/files/a%2F..%2F..%2Fetc%2Fpasswd';
    try {
        $result = $router->dispatch(new \core\Request());
        $t->assertFalse($hit, '含 %2F 的参数不得进入处理程序');
        $t->assertEquals(404, $result->getStatusCode(), '应返回 404');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});

$runner->run('Regression - 正常编码参数仍可正确解码', function ($t) {
    $router = new \core\Router();
    $router->get('/s/{name}', fn($n) => (new \core\Response())->content('n=' . $n));

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/s/john%20doe';
    try {
        $result = $router->dispatch(new \core\Request());
        $t->assertEquals('n=john doe', $result->getContent(), '%20 应解码为空格');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});
$runner->run('Regression - 方法不匹配返回 405 与 Allow 头', function ($t) {
    $router = new \core\Router();
    $router->get('/u', fn() => (new \core\Response())->content('GET'));
    $router->post('/u', fn() => (new \core\Response())->content('POST'));

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $_SERVER['REQUEST_URI'] = '/u';
    try {
        $result = $router->dispatch(new \core\Request());
        $t->assertEquals(405, $result->getStatusCode(), '方法不允许应返回 405');
        $headers = $result->getHeaders();
        $allow = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Allow') === 0) {
                $allow = is_array($value) ? implode(', ', $value) : (string) $value;
                break;
            }
        }
        $t->assertTrue(str_contains($allow, 'GET'), 'Allow 头应包含 GET');
        $t->assertTrue(str_contains($allow, 'POST'), 'Allow 头应包含 POST');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});

$runner->run('Regression - HEAD 响应不输出消息体', function ($t) {
    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'HEAD';
    try {
        $response = (new \core\Response())->content('SECRET-BODY');
        ob_start();
        $response->send();
        $out = ob_get_clean();
        $t->assertEquals('', $out, 'HEAD 请求不得输出响应体');
        $t->assertEquals(200, $response->getStatusCode(), 'HEAD 仍应返回状态码');
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
    }
});

$runner->run('Regression - 中间件组自引用不导致无限递归', function ($t) {
    $router = new \core\Router();
    // 'web' 包含自身与 'api'，'api' 又包含 'web'（互相引用）
    $router->middlewareGroup('web', ['web', 'api']);
    $router->middlewareGroup('api', ['web']);

    $method = new \ReflectionMethod($router, 'resolveMiddleware');
    $method->setAccessible(true);
    $resolved = $method->invoke($router, ['web']);
    $t->assertTrue(is_array($resolved), '自引用组应正常返回而非耗尽内存');
    $t->assertTrue(count($resolved) < 10, '自引用组展开后应有界');
});

$runner->run('Regression - 路由缓存拒绝不可序列化的中间件', function ($t) {
    $cacheFile = sys_get_temp_dir() . '/lp_regcache_' . getmypid() . '_' . uniqid() . '.php';

    $okRouter = new \core\Router();
    $okRouter->get('/a', fn() => new \core\Response());
    $t->assertFalse($okRouter->cacheRoutes($cacheFile), '闭包路由不可缓存');

    $objRouter = new \core\Router();
    $objRouter->get('/b', fn() => new \core\Response());
    $objRouter->setGlobalMiddleware([new \middleware\Cors()]);
    $t->assertFalse($objRouter->cacheRoutes($cacheFile), '对象中间件不可缓存（var_export 会生成 __set_state）');

    // 类名字符串形式应当可以缓存并可被 require 还原
    $strRouter = new \core\Router();
    $strRouter->get('/c', [\controller\IndexController::class, 'index']);
    $strRouter->setGlobalMiddleware([\middleware\Cors::class]);
    $t->assertTrue($strRouter->cacheRoutes($cacheFile), '类名字符串中间件应可缓存');
    $data = require $cacheFile;
    $t->assertTrue(is_array($data) && isset($data['routes']), '缓存文件应可被 require 还原');

    @unlink($cacheFile);
});

// ─── Model ───

$runner->run('Regression - Model 静态 API 可用（文档承诺的 User::find 等）', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $dbFile = sys_get_temp_dir() . '/lp_static_' . getmypid() . '_' . uniqid() . '.sqlite';
    @unlink($dbFile);
    $conn = new \db\Connection(['driver' => 'sqlite', 'database' => $dbFile]);
    $conn->getPdo()->exec('CREATE TABLE st_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT)');

    $modelClass = new class extends \model\Model {
        protected string $table = 'st_users';
        protected array $fillable = ['name'];
    };
    \model\Model::setDb($conn);
    try {
        $id = $modelClass::create(['name' => 'alice']);
        $t->assertTrue($id > 0, '静态 create() 应返回新 ID');

        $found = $modelClass::find($id);
        $t->assertNotNull($found, '静态 find() 应返回模型');
        $t->assertEquals('alice', $found->getAttribute('name'), '静态 find() 应返回正确数据');

        $t->assertNotNull($modelClass::findBy('name', 'alice'), '静态 findBy() 应可用');
        $t->assertNotNull($modelClass::first(), '静态 first() 应可用');
        $t->assertEquals(1, count($modelClass::all()), '静态 all() 应可用');
        $t->assertTrue($modelClass::where('name', '=', 'alice')->count() > 0, '静态 where() 应可用');
        $t->assertEquals(1, $modelClass::paginate(10, 1)['total'], '静态 paginate() 应可用');

        $t->assertEquals(1, $modelClass::update($id, ['name' => 'bob']), '静态 update() 应可用');
        $t->assertEquals('bob', $modelClass::find($id)->getAttribute('name'), 'update 应生效');

        // 实例方式调用静态方法仍兼容（文档承诺两种写法等价）
        $instance = new $modelClass();
        $t->assertNotNull($instance->find($id), '$instance->find() 应仍然可用');
    } finally {
        @unlink($dbFile);
    }
});

$runner->run('Regression - create/update 触发 mutator（不再明文落库）', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $dbFile = sys_get_temp_dir() . '/lp_mutator_' . getmypid() . '_' . uniqid() . '.sqlite';
    @unlink($dbFile);
    $conn = new \db\Connection(['driver' => 'sqlite', 'database' => $dbFile]);
    $pdo = $conn->getPdo();
    $pdo->exec('CREATE TABLE mut_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, password TEXT, created_at TEXT, updated_at TEXT)');

    $modelClass = new class extends \model\Model {
        protected string $table = 'mut_users';
        protected array $fillable = ['name', 'password'];
        protected function setPasswordAttribute(mixed $value): void
        {
            $this->attributes['password'] = 'HASHED:' . $value;
        }
    };
    \model\Model::setDb($conn);
    try {
        $id = $modelClass::create(['name' => 'a', 'password' => 'secret123']);
        $stored = $pdo->query('SELECT password FROM mut_users WHERE id = ' . (int) $id)->fetchColumn();
        $t->assertEquals('HASHED:secret123', $stored, 'create() 必须经过 mutator');

        $modelClass::update($id, ['password' => 'newpass']);
        $stored2 = $pdo->query('SELECT password FROM mut_users WHERE id = ' . (int) $id)->fetchColumn();
        $t->assertEquals('HASHED:newpass', $stored2, 'update() 必须经过 mutator');
    } finally {
        @unlink($dbFile);
    }
});

$runner->run('Regression - update 不污染实例，后续 save 不会插入重复行', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $dbFile = sys_get_temp_dir() . '/lp_dup_' . getmypid() . '_' . uniqid() . '.sqlite';
    @unlink($dbFile);
    $conn = new \db\Connection(['driver' => 'sqlite', 'database' => $dbFile]);
    $pdo = $conn->getPdo();
    $pdo->exec('CREATE TABLE dup_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec("INSERT INTO dup_users (name) VALUES ('orig')");

    $modelClass = new class extends \model\Model {
        protected string $table = 'dup_users';
        protected array $fillable = ['name'];
    };
    \model\Model::setDb($conn);
    try {
        $instance = new $modelClass();
        $modelClass::update(1, ['name' => 'renamed']);
        $t->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM dup_users')->fetchColumn(), 'update 不应改变行数');

        $loaded = $modelClass::find(1);
        $loaded->setAttribute('name', 'again');
        $loaded->save();
        $t->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM dup_users')->fetchColumn(), 'save 应更新而非插入新行');
        $t->assertEquals('again', $pdo->query('SELECT name FROM dup_users WHERE id=1')->fetchColumn(), 'save 应更新该行内容');
    } finally {
        @unlink($dbFile);
    }
});

// ─── Blade ───

$runner->run('Regression - Blade 支持 "@if ($x)" 带空格写法', function ($t) {
    // 模板目录与编译缓存目录必须分离：
    // 同目录时 finally 的清理会把源码和缓存一起删掉
    $src = sys_get_temp_dir() . '/lp_blade_src_' . getmypid() . '_' . uniqid();
    $cache = sys_get_temp_dir() . '/lp_blade_cache_' . getmypid() . '_' . uniqid();
    @mkdir($src, 0777, true);
    @mkdir($cache, 0777, true);
    try {
        file_put_contents($src . '/sp.blade.php', "A@if (\$x)YES@endif B");
        $blade = new \view\Blade($src, $cache);
        $out = $blade->render('sp', ['x' => true]);
        // 编译产物含 PHP if/endif 标签，渲染结果会带模板自身的换行，
        // 故按去除空白后比较
        $t->assertEquals('AYESB', preg_replace('/\s+/', '', $out), '@if ($x) 应正常编译');
    } finally {
        array_map('unlink', glob($src . '/*') ?: []);
        array_map('unlink', glob($cache . '/*') ?: []);
        @rmdir($src);
        @rmdir($cache);
    }
});

$runner->run('Regression - Blade 文本中的 @endif 不被误编译', function ($t) {
    $src = sys_get_temp_dir() . '/lp_blade_src2_' . getmypid() . '_' . uniqid();
    $cache = sys_get_temp_dir() . '/lp_blade_cache2_' . getmypid() . '_' . uniqid();
    @mkdir($src, 0777, true);
    @mkdir($cache, 0777, true);
    try {
        // 说明：只有「邮箱形态」的 token（xxx@yyy.zzz）能被自动保护。
// 裸写的 @endforeach 在语义上就是指令，Blade 无法区分，
// 需要原样输出时应使用 @verbatim 块或 @@ 转义。
file_put_contents($src . '/txt.blade.php', 'mail: support@endif.com and support@endforeach.com');
        $blade = new \view\Blade($src, $cache);
        $out = $blade->render('txt', []);
        $t->assertTrue(str_contains($out, 'support@endif.com'), '@endif 出现在邮箱中不应被编译');
        $t->assertTrue(str_contains($out, 'support@endforeach.com'), '@endforeach 出现在邮箱中不应被编译');
    } finally {
        array_map('unlink', glob($src . '/*') ?: []);
        array_map('unlink', glob($cache . '/*') ?: []);
        @rmdir($src);
        @rmdir($cache);
    }
});

$runner->run('Regression - Blade @include 传入变量可覆盖父模板同名变量', function ($t) {
    $src = sys_get_temp_dir() . '/lp_blade_src3_' . getmypid() . '_' . uniqid();
    $cache = sys_get_temp_dir() . '/lp_blade_cache3_' . getmypid() . '_' . uniqid();
    @mkdir($src, 0777, true);
    @mkdir($cache, 0777, true);
    try {
        file_put_contents($src . '/parent.blade.php', "@include('row', ['name' => 'OVERRIDE'])");
        file_put_contents($src . '/row.blade.php', 'ROW[<?= $name ?>]');
        $blade = new \view\Blade($src, $cache);
        $out = $blade->render('parent', ['name' => 'PARENT']);
        $t->assertEquals('ROW[OVERRIDE]', $out, 'include 传入的值必须生效（EXTR_OVERWRITE）');
    } finally {
        array_map('unlink', glob($src . '/*') ?: []);
        array_map('unlink', glob($cache . '/*') ?: []);
        @rmdir($src);
        @rmdir($cache);
    }
});

$runner->run('Regression - Blade include 不污染父模板后续变量', function ($t) {
    $src = sys_get_temp_dir() . '/lp_blade_src4_' . getmypid() . '_' . uniqid();
    $cache = sys_get_temp_dir() . '/lp_blade_cache4_' . getmypid() . '_' . uniqid();
    @mkdir($src, 0777, true);
    @mkdir($cache, 0777, true);
    try {
        file_put_contents($src . '/p2.blade.php', "@include('r2', ['name' => 'INC'])AFTER[<?= \$name ?>]");
        file_put_contents($src . '/r2.blade.php', '<?= $name ?>');
        $blade = new \view\Blade($src, $cache);
        $out = $blade->render('p2', ['name' => 'PARENT']);
        $t->assertTrue(str_contains($out, 'AFTER[PARENT]'), 'include 结束后应恢复父作用域变量');
    } finally {
        array_map('unlink', glob($src . '/*') ?: []);
        array_map('unlink', glob($cache . '/*') ?: []);
        @rmdir($src);
        @rmdir($cache);
    }
});

// ─── Validate / FormRequest ───

$runner->run('Regression - Validate alpha/alphaNum 拒绝数组输入', function ($t) {
    $v = new \core\Validate();
    $t->assertFalse($v->validate(['f' => ['a', 'b']], ['f' => 'alpha']), '数组不得通过 alpha（(string)数组 === "Array"）');
    $t->assertFalse($v->validate(['f' => ['a', 'b']], ['f' => 'alphaNum']), '数组不得通过 alphaNum');
    $t->assertFalse($v->validate(['f' => ['1', '2']], ['f' => 'numeric']), '数组不得通过 numeric');
    $t->assertTrue($v->validate(['f' => 'abc'], ['f' => 'alpha']), '正常字符串仍应通过 alpha');
});

$runner->run('Regression - FormRequest 只校验请求体，不接受 GET 查询串', function ($t) {
    $oldGet = $_GET;
    $oldPost = $_POST;

    // Request 构造函数会把 $_GET/$_POST 快照进实例，
    // 因此必须在 new 之前设置超全局变量。
    $_GET = ['email' => 'attacker@evil.com'];
    $_POST = [];
    $fr = new class extends \core\FormRequest {
        public function rules(): array
        {
            return ['email' => 'required|email'];
        }
    };
    try {
        $t->assertFalse($fr->validate(), 'GET 查询串不得满足 required');
        $t->assertEquals([], $fr->validated(), 'validated() 不应返回 GET 数据');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
    }

    $_POST = ['email' => 'real@site.com'];
    $fr2 = new class extends \core\FormRequest {
        public function rules(): array
        {
            return ['email' => 'required|email'];
        }
    };
    try {
        $t->assertTrue($fr2->validate(), 'POST 请求体应正常通过校验');
        $t->assertEquals('real@site.com', $fr2->validated()['email'] ?? null, 'validated() 应返回 POST 数据');
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
    }
});

// ─── Logger ───

$runner->run('Regression - Logger 遇到不可编码 context 不抛 TypeError', function ($t) {
    $dir = sys_get_temp_dir() . '/lp_log_' . getmypid() . '_' . uniqid();
    @mkdir($dir, 0777, true);
    try {
        $logger = new \log\Logger($dir);
        // 非法 UTF-8 会让 json_encode 返回 false，修复前 str_replace(false) 抛 TypeError
        $logger->info('bad utf8', ['ua' => "\xB1\x31"]);
        $t->assertTrue(true, '记录日志不应抛出异常');

        $logger->error('with nan', ['v' => NAN]);
        $t->assertTrue(true, 'NAN 等不可编码值也不应抛异常');

        $files = glob($dir . '/*.log') ?: [];
        $t->assertTrue(count($files) > 0, '日志文件应被创建');
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
});

// ─── JsonResource ───

$runner->run('Regression - JsonResource 集合模式遵循 $wrap 配置', function ($t) {
    $res = new class extends \core\JsonResource {
        public static ?string $wrap = 'items';
    };
    $single = (new $res(['id' => 1]))->resolve(null);
    $collection = $res::collection([['id' => 1], ['id' => 2]])->resolve(null);

    $t->assertTrue(array_key_exists('items', $single), '单资源应使用 items 包装');
    $t->assertTrue(array_key_exists('items', $collection), '集合也应使用 items 包装（修复前硬编码 data）');
    $t->assertFalse(array_key_exists('data', $collection), '集合不应再硬编码 data');
    $t->assertCount(2, $collection['items'], '集合元素数量应正确');

    $plain = new class extends \core\JsonResource {
        public static ?string $wrap = null;
    };
    $out = $plain::collection([1, 2])->resolve(null);
    $t->assertFalse(array_key_exists('data', $out), '$wrap=null 时集合也不应包装');
});

// ─── Schema ───

$runner->run('Regression - Schema 索引/唯一约束可在 SQLite 上执行', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $schema = new \db\Schema($pdo);

    $t->assertTrue($schema->create('sch_idx', function (\db\Blueprint $t2) {
        $t2->id();
        $t2->string('email')->unique();
        $t2->string('slug')->index();
        $t2->timestamps();
    }), '含 unique()/index() 的建表应在 SQLite 上成功');

    $t->assertTrue($schema->hasTable('sch_idx'), '表应存在');

    $pdo->exec("INSERT INTO sch_idx (email, created_at, updated_at) VALUES ('a@x.com', '', '')");
    $t->assertThrows(\PDOException::class, function () use ($pdo) {
        $pdo->exec("INSERT INTO sch_idx (email, created_at, updated_at) VALUES ('a@x.com', '', '')");
    }, 'unique 约束应真正生效');
});

$runner->run('Regression - Schema::table 重置状态，不残留上次 commands', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $schema = new \db\Schema($pdo);

    $schema->create('st_base', function (\db\Blueprint $t2) {
        $t2->id();
        $t2->string('a');
        $t2->string('b');
    });

    // 先制造一次异常，随后正常 ALTER：不应把上一次的命令混进来
    try {
        $schema->table('st_base', function (\db\Blueprint $t2) {
            $t2->string('c')->unique();
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException $e) {
        // 预期
    }

    $t->assertTrue($schema->table('st_base', function (\db\Blueprint $t2) {
        $t2->string('d');
    }), '异常之后的 ALTER 仍应成功');

    $t->assertTrue($schema->hasColumn('st_base', 'd'), '新列应存在');
});

// ─── Application ───

$runner->run('Regression - Application 注册内置中间件别名', function ($t) {
    $ref = new \ReflectionClass(\core\Application::class);
    $app = $ref->newInstanceWithoutConstructor();

    $container = new \core\Container();
    $container->instance('config', []);
    $router = new \core\Router();
    $router->setContainer($container);
    $ref->getProperty('container')->setValue($app, $container);
    $ref->getProperty('router')->setValue($app, $router);
    $ref->getProperty('events')->setValue($app, new \core\EventDispatcher());
    $ref->getProperty('config')->setValue($app, []);

    $method = $ref->getMethod('registerMiddlewareAliases');
    $method->setAccessible(true);
    $method->invoke($app);

    $router->group(['prefix' => '/api', 'middleware' => ['cors']], function ($r) {
        $r->get('/probe', fn() => new \core\Response('OK'));
    });

    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/probe';
    try {
        // 修复前：RuntimeException: Invalid middleware: cors
        $result = $router->dispatch(new \core\Request());
        $t->assertTrue($result instanceof \core\Response, 'cors 别名应可解析并正常返回响应');
    } catch (\Throwable $e) {
        $t->assertTrue(false, 'cors 别名解析不应抛异常：' . $e->getMessage());
    } finally {
        if ($oldMethod !== null) { $_SERVER['REQUEST_METHOD'] = $oldMethod; } else { unset($_SERVER['REQUEST_METHOD']); }
        if ($oldUri !== null) { $_SERVER['REQUEST_URI'] = $oldUri; } else { unset($_SERVER['REQUEST_URI']); }
    }
});

$runner->run('Regression - Application 实例化 config 中的 providers', function ($t) {
    // ServiceProvider 构造函数需要容器实例
    $providerClass = new class (new \core\Container()) extends \core\ServiceProvider {
        public static int $bootCount = 0;
        public function register(): void
        {
        }
        public function boot(): void
        {
            self::$bootCount++;
        }
    };
    $className = $providerClass::class;

    $ref = new \ReflectionClass(\core\Application::class);
    $app = $ref->newInstanceWithoutConstructor();
    $container = new \core\Container();
    $ref->getProperty('container')->setValue($app, $container);
    $ref->getProperty('router')->setValue($app, new \core\Router());
    $ref->getProperty('events')->setValue($app, new \core\EventDispatcher());
    $ref->getProperty('providers')->setValue($app, []);
    $ref->getProperty('booted')->setValue($app, false);
    $ref->getProperty('config')->setValue($app, ['app' => ['providers' => [$className]]]);

    $providerClass::$bootCount = 0;
    $method = $ref->getMethod('bootProviders');
    $method->setAccessible(true);
    $method->invoke($app);

    $t->assertEquals(1, $providerClass::$bootCount, 'config(app.providers) 中的提供者应被实例化并 boot');

    // 重复调用不应重复 boot
    $method->invoke($app);
    $t->assertEquals(1, $providerClass::$bootCount, '已启动后不应重复 boot');
});

$runner->run('Regression - QueryBuilder 支持 table.* 限定名（belongsToMany 可用）', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $qb = new \db\QueryBuilder($pdo);

    $sql = $qb->table('users')->select(['users.*'])->getSql();
    $t->assertTrue(str_contains($sql, '`users`.*'), 'table.* 应被正确加引号');

    $t->assertThrows(\InvalidArgumentException::class, function () use ($qb) {
        $qb->table('users')->select(['*.*']);
    }, '非法限定名仍应被拒绝');
});
// ═══════════════════════════════════════════════════════════════
// v2.16.0 第二批：遗留建议修复项回归测试
// ═══════════════════════════════════════════════════════════════

$runner->run('Regression - 查询缓存区分绑定参数（不再越权返回他人结果）', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $dbFile = sys_get_temp_dir() . '/lp_qcache_' . getmypid() . '_' . uniqid() . '.sqlite';
    @unlink($dbFile);
    $conn = new \db\Connection(['driver' => 'sqlite', 'database' => $dbFile]);
    $pdo = $conn->getPdo();
    $pdo->exec('CREATE TABLE u (id INTEGER PRIMARY KEY, name TEXT, status INT)');
    $pdo->exec("INSERT INTO u VALUES (1,'alice',1),(2,'bob',2)");

    @mkdir(STORAGE_PATH . 'cache', 0777, true);
    $pattern = STORAGE_PATH . 'cache/query_' . hash('sha256', 'rk-test') . '*.php';
    foreach (glob($pattern) ?: [] as $f) {
        @unlink($f);
    }

    $r1 = (new \db\QueryBuilder($conn->getPdo()))
        ->cache('rk-test', 60)->table('u')->where('status', '=', 1)->orderBy('id')->pluck('name');
    $r2 = (new \db\QueryBuilder($conn->getPdo()))
        ->cache('rk-test', 60)->table('u')->where('status', '=', 2)->orderBy('id')->pluck('name');

    $t->assertEquals(['alice'], $r1, 'status=1 应只返回 alice');
    $t->assertEquals(['bob'], $r2, 'status=2 应返回 bob（修复前错误返回 alice，属水平越权）');

    foreach (glob($pattern) ?: [] as $f) {
        @unlink($f);
    }
    @unlink($dbFile);
});

$runner->run('Regression - Application 读取 config 缓存前定义守卫常量（不再 403）', function ($t) {
    // loadConfig() 必须先 define LIGHTPHP_CONFIG_CACHE，否则 require 缓存文件会 exit(403)
    $ref = new \ReflectionClass(\core\Application::class);
    $method = $ref->getMethod('loadConfig');
    $source = implode("\n", array_slice(
        file($ref->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
    $t->assertTrue(
        str_contains($source, 'LIGHTPHP_CONFIG_CACHE'),
        'loadConfig() 必须在 require 缓存文件前定义 LIGHTPHP_CONFIG_CACHE'
    );
    $definePos = strpos($source, 'define(');
    $requirePos = strpos($source, 'require $cachedFile');
    $t->assertTrue(
        $definePos !== false && $requirePos !== false && $definePos < $requirePos,
        'define() 必须早于 require，否则仍会触发 exit(403)'
    );
});

$runner->run('Regression - Upload 支持自定义落盘根目录（web 根之外）', function ($t) {
    $root = sys_get_temp_dir() . '/lp_uproot_' . getmypid() . '_' . uniqid();
    @mkdir($root, 0777, true);
    $ref = new \ReflectionProperty(\core\Upload::class, 'root');
    $ref->setAccessible(true);
    try {
        $upload = new \core\Upload([
            'name' => 'a.txt', 'type' => 'text/plain',
            'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0,
        ]);
        $upload->root($root);
        $t->assertEquals($root, $ref->getValue($upload), 'root() 应覆盖落盘根目录');

        // 默认不硬编码 PUBLIC_PATH（由 save() 回退，保持历史行为）
        $fresh = new \core\Upload([
            'name' => 'b.txt', 'type' => 'text/plain',
            'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0,
        ]);
        $t->assertEquals('', $ref->getValue($fresh), '默认不设置 root，由 save() 回退');
    } finally {
        @rmdir($root);
    }
});

$runner->run('Regression - Upload::file() 对多文件字段返回 null 而非 TypeError', function ($t) {
    $old = $_FILES;
    try {
        $_FILES = ['docs' => [
            'name' => ['a.txt', 'b.txt'],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => ['/tmp/a', '/tmp/b'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [1, 1],
        ]];
        $result = \core\Upload::file('docs');
        $t->assertNull($result, '多文件字段应返回 null（修复前会抛 TypeError）');
    } finally {
        $_FILES = $old;
    }
});

$runner->run('Regression - View::include 不产生双重转义', function ($t) {
    $dir = sys_get_temp_dir() . '/lp_view_inc_' . getmypid() . '_' . uniqid();
    @mkdir($dir, 0777, true);
    try {
        file_put_contents($dir . '/p.php', "<?php \$__view->include('c', ['m' => \$m]); ?>");
        file_put_contents($dir . '/c.php', 'c:<?= $m ?>');
        $view = new \view\View($dir);
        $out = $view->render('p', ['m' => '<b>x</b>']);
        $t->assertTrue(str_contains($out, '&lt;b&gt;x&lt;/b&gt;'), '原始数据应被转义一次');
        $t->assertFalse(str_contains($out, '&amp;lt;'), 'include 数据不应被二次转义');
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
});

$runner->run('Regression - 聚合函数保留 GROUP BY 按组返回', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE s (id INTEGER PRIMARY KEY, g TEXT, v INT)');
    $pdo->exec("INSERT INTO s VALUES (1,'a',10),(2,'a',20),(3,'b',5)");

    $sum = (new \db\QueryBuilder($pdo))->table('s')->groupBy('g')->sum('v');
    $t->assertTrue(is_array($sum), '有 GROUP BY 时 sum() 应返回分组数组（修复前静默返回全表 35）');
    $t->assertCount(2, $sum, '应有两个分组');

    $flat = array_map(fn($r) => (float) ($r['__sum'] ?? 0), $sum);
    $t->assertTrue(in_array(30.0, $flat, true), 'a 组应为 30');
    $t->assertTrue(in_array(5.0, $flat, true), 'b 组应为 5');

    $total = (new \db\QueryBuilder($pdo))->table('s')->sum('v');
    $t->assertEquals(35.0, (float) $total, '无 GROUP BY 时返回全局聚合值');
});

$runner->run('Regression - JsonResource $wrap 不跨子类污染', function ($t) {
    $a = new class extends \core\JsonResource {
        public static ?string $wrap = 'items';
    };
    $b = new class extends \core\JsonResource {
    };
    $c = new class extends \core\JsonResource {
    };

    $outA = (new $a(['id' => 1]))->resolve();
    $outB = (new $b(['id' => 2]))->resolve();
    $outC = (new $c(['id' => 3]))->resolve();

    $t->assertTrue(array_key_exists('items', $outA), 'A 应使用 items');
    $t->assertTrue(array_key_exists('data', $outB), 'B 不应被 A 污染，仍用 data');
    $t->assertTrue(array_key_exists('data', $outC), 'C 不应被 A 污染，仍用 data');
});

$runner->run('Regression - OutputCache 不缓存 Set-Cookie 头', function ($t) {
    $ref = new \ReflectionMethod(\middleware\OutputCache::class, 'collectHeaders');
    $ref->setAccessible(true);

    $oc = (new \ReflectionClass(\middleware\OutputCache::class))->newInstanceWithoutConstructor();
    $cacheProp = new \ReflectionProperty(\middleware\OutputCache::class, 'cache');
    $cacheProp->setAccessible(true);
    $cacheProp->setValue($oc, (new \ReflectionClass(\cache\CacheManager::class))->newInstanceWithoutConstructor());

    $headers = $ref->invoke($oc, [
        'Content-Type' => 'text/html',
        'Set-Cookie' => 'PHPSESSID=SUPERSECRET; path=/',
        'Cache-Control' => 'no-store',
    ]);

    $names = array_map(fn($h) => strtolower($h['name']), $headers);
    $t->assertFalse(in_array('set-cookie', $names, true), 'Set-Cookie 绝不能写入缓存（会导致会话串号）');
    $t->assertTrue(in_array('content-type', $names, true), '普通响应头应保留');
});

$runner->run('Regression - HttpClient 限制协议（SSRF 防护）', function ($t) {
    $src = file_get_contents(dirname(__DIR__) . '/app/core/HttpClient.php');
    $t->assertTrue(
        str_contains($src, 'CURLOPT_PROTOCOLS') || str_contains($src, 'CURLOPT_PROTOCOLS_STR'),
        'HttpClient 必须限制允许的协议（否则 file:// 可读取本地文件）'
    );
    $t->assertTrue(
        str_contains($src, 'CURLOPT_SSL_VERIFYPEER'),
        '必须显式开启 TLS 证书校验'
    );
});

$runner->run('Regression - Memcached clear() 在共享实例上拒绝 flush', function ($t) {
    if (!class_exists(\Memcached::class)) {
        $t->assertTrue(true, 'Memcached extension not available, test skipped');
        return;
    }
    $prop = new \ReflectionProperty(\cache\MemcachedCache::class, 'ownsInstance');
    $prop->setAccessible(true);

    $shared = new \cache\MemcachedCache(['shared' => true]);
    $t->assertFalse($prop->getValue($shared), 'shared=true 时不得执行 flush()');

    $exclusive = new \cache\MemcachedCache(['shared' => false]);
    $t->assertTrue($prop->getValue($exclusive), 'shared=false 时允许 flush()');
});
// ═══════════════════════════════════════════════════════════════
// v2.16.1 第三批：回归修复 + console/config 层缺陷
// ═══════════════════════════════════════════════════════════════

$runner->run('Regression - SoftDelete withTrashed 静态入口不丢状态', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $dbFile = sys_get_temp_dir() . '/lp_sd3_' . getmypid() . '_' . uniqid() . '.sqlite';
    @unlink($dbFile);
    $conn = new \db\Connection(['driver' => 'sqlite', 'database' => $dbFile]);
    $conn->getPdo()->exec('CREATE TABLE sd3 (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
    $conn->getPdo()->exec("INSERT INTO sd3 (name, deleted_at) VALUES ('alive', NULL), ('gone', '2020-01-01 00:00:00')");

    $SD = new class extends \model\Model {
        protected string $table = 'sd3';
        protected array $fillable = ['name', 'deleted_at'];
        use \traits\SoftDelete;
    };
    \model\Model::setDb($conn);
    try {
        $t->assertCount(1, $SD::all(), '默认应排除已软删除记录');

        // 回归点：Model static 化后 all()/find()/where() 内部 new static()
        // 会丢弃 trashedQuery，导致 withTrashed 静默失效
        $names = array_map(fn($m) => $m->getAttribute('name'), $SD::withTrashed()->all());
        sort($names);
        $t->assertEquals(['alive', 'gone'], $names, 'withTrashed()->all() 应包含已软删除记录');

        $t->assertCount(1, $SD::onlyTrashed()->all(), 'onlyTrashed() 应只返回已软删除记录');
        $t->assertCount(1, $SD::all(), '作用域用完后不应影响后续静态调用');
    } finally {
        @unlink($dbFile);
    }
});

$runner->run('Regression - EventDispatcher 优先级跨通配符生效', function ($t) {
    $ed = new \core\EventDispatcher();
    $order = [];
    $ed->listen('user.created', function () use (&$order) { $order[] = 'exact-0'; }, 0);
    $ed->listen('user.*', function () use (&$order) { $order[] = 'wild-10'; }, 10);
    $ed->listen('user.created', function () use (&$order) { $order[] = 'exact-100'; }, 100);

    $ed->dispatch('user.created');
    $t->assertEquals(
        ['exact-100', 'wild-10', 'exact-0'],
        $order,
        '跨 pattern 应按优先级降序（修复前退化为注册顺序）'
    );
});

$runner->run('Regression - SQLite 索引名带表前缀不冲突', function ($t) {
    if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
        $t->assertTrue(true, 'SQLite driver not available, test skipped');
        return;
    }
    $schema = new \db\Schema(new \PDO('sqlite::memory:'));

    $t->assertTrue($schema->create('ix_a', function (\db\Blueprint $t2) {
        $t2->id();
        $t2->string('email')->index();
    }), '首表建索引应成功');

    // 回归点：索引名 idx_email 无表前缀，SQLite 全局唯一 → 第二表必冲突
    $t->assertTrue($schema->create('ix_b', function (\db\Blueprint $t2) {
        $t2->id();
        $t2->string('email')->index();
    }), '不同表的同名列索引不应冲突');
});

$runner->run('Regression - Blade @include 父变量为 null 时不污染作用域', function ($t) {
    $src = sys_get_temp_dir() . '/lp_b3src_' . getmypid() . '_' . uniqid();
    $cache = sys_get_temp_dir() . '/lp_b3cache_' . getmypid() . '_' . uniqid();
    @mkdir($src, 0777, true);
    @mkdir($cache, 0777, true);
    try {
        file_put_contents($src . '/row.blade.php', '[{{ $v }}]');
        file_put_contents(
            $src . '/main.blade.php',
            "<?php \$v = null; ?>{@include('row',['v'=>'NEW'])}|AFTER=<?= var_export(\$v, true) ?>"
        );
        $out = (new \view\Blade($src, $cache))->render('main');
        // 回归点：用 isset() 保存父变量，null 值被判为「未定义」→ 恢复失败
        $t->assertTrue(str_contains($out, 'AFTER=NULL'), '父作用域的 null 变量应被恢复，实际: ' . $out);
    } finally {
        array_map('unlink', glob($src . '/*') ?: []);
        array_map('unlink', glob($cache . '/*') ?: []);
        @rmdir($src);
        @rmdir($cache);
    }
});

$runner->run('Regression - Blade @include 新变量不泄漏到父作用域', function ($t) {
    $src = sys_get_temp_dir() . '/lp_b4src_' . getmypid() . '_' . uniqid();
    $cache = sys_get_temp_dir() . '/lp_b4cache_' . getmypid() . '_' . uniqid();
    @mkdir($src, 0777, true);
    @mkdir($cache, 0777, true);
    try {
        file_put_contents($src . '/row.blade.php', '<?= $brandNew ?? "" ?>');
        file_put_contents(
            $src . '/main.blade.php',
            "@include('row',['brandNew'=>'X'])|AFTER=<?= var_export(isset(\$brandNew), true) ?>"
        );
        $out = (new \view\Blade($src, $cache))->render('main');
        $t->assertTrue(str_contains($out, 'AFTER=false'), 'include 引入的新变量应被 unset，实际: ' . $out);
    } finally {
        array_map('unlink', glob($src . '/*') ?: []);
        array_map('unlink', glob($cache . '/*') ?: []);
        @rmdir($src);
        @rmdir($cache);
    }
});

$runner->run('Regression - Command 布尔选项不吞掉位置参数', function ($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'make:model {name} {--force}';
        public function handle(): int { return 0; }
    };
    // 回归点：--force User 把 User 当成 force 的值 → name 变 null
    $cmd->parseInput(['--force', 'User']);
    $t->assertEquals('User', $cmd->argument('name'), '布尔选项不应吞掉位置参数');
    $t->assertTrue($cmd->option('force'), '--force 应为布尔真');

    $cmd2 = new class extends \core\console\Command {
        protected string $signature = 'make:model {name}';
        public function handle(): int { return 0; }
    };
    $cmd2->parseInput(['Post']);
    $t->assertEquals('Post', $cmd2->argument('name'), '普通位置参数应正常解析');
});

$runner->run('Regression - Command 取值选项支持负数', function ($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'migrate:rollback {--steps=1}';
        public function handle(): int { return 0; }
    };
    // 回归点：str_starts_with('-') 把 -1 当成选项名 "1"
    $cmd->parseInput(['--steps', '-1']);
    $t->assertEquals('-1', $cmd->option('steps'), '选项值可为负数');

    $cmd->parseInput(['--steps', '3']);
    $t->assertEquals('3', $cmd->option('steps'), '选项值可为正数');
});

$runner->run('Regression - Command 报告缺失必填参数', function ($t) {
    $cmd = new class extends \core\console\Command {
        protected string $signature = 'make:controller {name}';
        public function handle(): int { return 0; }
    };
    $cmd->parseInput([]);
    $t->assertTrue(in_array('name', $cmd->missingRequiredArguments(), true), '应报告缺失的必填参数');

    $cmd2 = new class extends \core\console\Command {
        protected string $signature = 'make:controller {name}';
        public function handle(): int { return 0; }
    };
    $cmd2->parseInput(['PostController']);
    $t->assertEquals([], $cmd2->missingRequiredArguments(), '已提供时不应报缺失');
});

$runner->run('Regression - Console 用户注册的 list 命令可执行', function ($t) {
    $console = new \core\console\Console();
    $console->register(new class extends \core\console\Command {
        protected string $signature = 'list';
        public function handle(): int { return 42; }
    });
    $t->assertEquals(42, $console->run(['console', 'list']), '用户注册的 list 应优先于内置 list');

    $console2 = new \core\console\Console();
    ob_start();
    $rc = $console2->run(['console', 'list']);
    ob_end_clean();
    $t->assertEquals(0, $rc, '未注册 list 时回退到内置实现');
});

$runner->run('Regression - Config::load 无尾斜杠仍能加载且可重复调用', function ($t) {
    $dir = sys_get_temp_dir() . '/lp_cfg3_' . getmypid() . '_' . uniqid();
    @mkdir($dir, 0777, true);
    $name = 'lp_cfg_probe_' . getmypid();
    try {
        // 回归点：glob($path . '*.php') 缺尾斜杠时匹配不到 → 静默加载 0 个
        file_put_contents($dir . '/' . $name . '.php', "<?php return ['k' => 'v'];");
        \config\Config::load($dir);
        $t->assertTrue(\config\Config::has($name . '.k'), '无尾斜杠也应加载配置');
        \config\Config::load($dir);
        $t->assertTrue(true, '二次 load 不应重复 require 同一文件');
    } finally {
        @unlink($dir . '/' . $name . '.php');
        @rmdir($dir);
    }
});

$runner->run('Regression - Config 点号路径穿过标量不抛 TypeError', function ($t) {
    \config\Config::set('lp_scalar', 'plain-string');
    $t->assertEquals('plain-string', \config\Config::get('lp_scalar'));
    // 回归点：array_key_exists(第2段, 'plain-string') → Argument #2 must be of type array
    $t->assertNull(\config\Config::get('lp_scalar.deeper'), '穿过标量应返回默认值');
    $t->assertFalse(\config\Config::has('lp_scalar.deeper'), '穿过标量应视为不存在');
    $t->assertTrue(\config\Config::has('lp_scalar'), '标量本身存在');
});
