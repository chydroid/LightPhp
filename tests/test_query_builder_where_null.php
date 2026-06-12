<?php
declare(strict_types=1);

/**
 * QueryBuilder where() null 值处理测试
 *
 * 验证 v2.6.0 修复：where('column', null) 应生成 IS NULL 而非 = NULL
 * 纯 SQL 生成测试，不需要数据库驱动
 */

define('APP_PATH', __DIR__ . '/../app/');
define('PUBLIC_PATH', __DIR__ . '/../public/');
define('STORAGE_PATH', __DIR__ . '/../storage/');

require APP_PATH . 'core/Loader.php';
\core\Loader::register();
require APP_PATH . 'core/helpers.php';

// 使用 MySQL 内存模拟 PDO（不需要实际连接，只测试 SQL 生成）
$pdo = new class extends \PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
};

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS: {$message}\n";
    } else {
        $failed++;
        echo "  FAIL: {$message}\n";
    }
}

echo "=== QueryBuilder where() null 值测试 ===\n\n";

// --- 测试1: where('column', null) 生成 IS NULL ---
echo "测试1: where('column', null) 生成 IS NULL\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('email', null);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NULL'),
    "SQL 包含 IS NULL: {$sql}"
);
assert_test(
    !str_contains($sql, '= NULL'),
    "SQL 不包含 = NULL"
);
assert_test(
    !str_contains($sql, ':w_'),
    "IS NULL 不生成绑定参数"
);

echo "\n";

// --- 测试2: where('column', '=', null) 生成 IS NULL ---
echo "测试2: where('column', '=', null) 生成 IS NULL\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('deleted_at', '=', null);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NULL'),
    "SQL 包含 IS NULL: {$sql}"
);
assert_test(
    !str_contains($sql, ':w_'),
    "IS NULL 不生成绑定参数"
);

echo "\n";

// --- 测试3: where('column', '!=', null) 生成 IS NOT NULL ---
echo "测试3: where('column', '!=', null) 生成 IS NOT NULL\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('deleted_at', '!=', null);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NOT NULL'),
    "SQL 包含 IS NOT NULL: {$sql}"
);

echo "\n";

// --- 测试4: where('column', '<>', null) 生成 IS NOT NULL ---
echo "测试4: where('column', '<>', null) 生成 IS NOT NULL\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('email', '<>', null);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NOT NULL'),
    "SQL 包含 IS NOT NULL: {$sql}"
);

echo "\n";

// --- 测试5: whereOr 中 null 值处理 ---
echo "测试5: whereOr(['email' => null, 'name' => 'Alice']) 生成 IS NULL OR =\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->whereOr(['email' => null, 'name' => 'Alice']);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NULL'),
    "SQL 包含 IS NULL: {$sql}"
);
assert_test(
    str_contains($sql, 'OR'),
    "SQL 包含 OR: {$sql}"
);
assert_test(
    str_contains($sql, ':w_'),
    "非 null 条件生成绑定参数: {$sql}"
);

echo "\n";

// --- 测试6: whereOr 中 != null 生成 IS NOT NULL ---
echo "测试6: whereOr(['email' => ['!=', null]]) 生成 IS NOT NULL\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->whereOr(['email' => ['!=', null]]);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NOT NULL'),
    "SQL 包含 IS NOT NULL: {$sql}"
);

echo "\n";

// --- 测试7: 非法操作符 + null 抛异常 ---
echo "测试7: where('column', '>', null) 抛 InvalidArgumentException\n";
$exceptionThrown = false;
$exceptionMessage = '';
try {
    $qb = new \db\QueryBuilder($pdo);
    $qb->table('users')->where('id', '>', null);
} catch (\InvalidArgumentException $e) {
    $exceptionThrown = true;
    $exceptionMessage = $e->getMessage();
}
assert_test(
    $exceptionThrown,
    "使用 > 操作符与 null 值时抛出 InvalidArgumentException"
);
assert_test(
    str_contains($exceptionMessage, 'NULL'),
    "异常消息包含 NULL: {$exceptionMessage}"
);

echo "\n";

// --- 测试8: 正常值查询不受影响 ---
echo "测试8: where('column', 'value') 正常查询不受影响\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('name', 'Alice');
$sql = $qb->getSql();
assert_test(
    str_contains($sql, '= :w_'),
    "SQL 包含 = :w_ 占位符: {$sql}"
);
assert_test(
    !str_contains($sql, 'IS NULL'),
    "SQL 不包含 IS NULL"
);

echo "\n";

// --- 测试9: LIKE + null 生成 IS NULL ---
echo "测试9: where('column', 'LIKE', null) 生成 IS NULL\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('email', 'LIKE', null);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NULL'),
    "LIKE + null 生成 IS NULL: {$sql}"
);

echo "\n";

// --- 测试10: NOT LIKE + null 生成 IS NOT NULL ---
echo "测试10: where('column', 'NOT LIKE', null) 生成 IS NOT NULL\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('email', 'NOT LIKE', null);
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NOT NULL'),
    "NOT LIKE + null 生成 IS NOT NULL: {$sql}"
);

echo "\n";

// --- 测试11: 多条件混合 null 和非 null ---
echo "测试11: 多条件混合 null 和非 null\n";
$qb = new \db\QueryBuilder($pdo);
$qb->table('users')->where('deleted_at', null)->where('name', 'Alice');
$sql = $qb->getSql();
assert_test(
    str_contains($sql, 'IS NULL'),
    "SQL 包含 IS NULL: {$sql}"
);
assert_test(
    str_contains($sql, ':w_'),
    "非 null 条件生成绑定参数: {$sql}"
);

echo "\n";

// --- 测试12: whereOr 非法操作符 + null 抛异常 ---
echo "测试12: whereOr(['id' => ['>', null]]) 抛 InvalidArgumentException\n";
$exceptionThrown = false;
try {
    $qb = new \db\QueryBuilder($pdo);
    $qb->table('users')->whereOr(['id' => ['>', null]]);
} catch (\InvalidArgumentException $e) {
    $exceptionThrown = true;
}
assert_test(
    $exceptionThrown,
    "whereOr 中使用 > 操作符与 null 值时抛出 InvalidArgumentException"
);

echo "\n";

// --- 汇总 ---
echo str_repeat('=', 50) . "\n";
$total = $passed + $failed;
echo "结果: {$passed}/{$total} 通过";
if ($failed > 0) {
    echo ", {$failed} 失败";
}
echo "\n";
echo str_repeat('=', 50) . "\n";

exit($failed > 0 ? 1 : 0);
