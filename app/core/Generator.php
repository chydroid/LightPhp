<?php
declare(strict_types=1);

namespace core;

class Generator
{
    private ?\db\Connection $db = null;
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function setDbConfig(array $config): void
    {
        $this->config = $config;
    }

    private function getDb(): \db\Connection
    {
        if ($this->db === null) {
            $this->db = new \db\Connection($this->config);
        }
        return $this->db;
    }

    public function getTables(): array
    {
        try {
            $db = $this->getDb();
            $sql = "SHOW TABLES";
            $result = $db->query($sql);
            $key = 'Tables_in_' . $db->getDatabase();
            return array_column($result, $key);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getTableColumns(string $table): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }

        try {
            $sql = "SHOW FULL COLUMNS FROM `{$table}`";
            return $this->getDb()->query($sql);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function generateModel(string $table, string $modelName = null): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }

        $modelName = $modelName ?: $this->tableToModelName($table);
        $columns = $this->getTableColumns($table);
        $primaryKey = $this->getPrimaryKey($columns);
        $fillable = $this->getFillableColumns($columns);
        $casts = $this->getCasts($columns);

        $namespace = $this->guessNamespace('model');
        $fillableStr = $this->arrayToString($fillable);
        $castsStr = $this->arrayToString($casts, true);

        $template = <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

use core\Model;

class {$modelName} extends Model
{
    protected string \$table = '{$table}';
    protected string \$primaryKey = '{$primaryKey}';
    protected array \$fillable = {$fillableStr};
    protected array \$casts = {$castsStr};
}
PHP;

        return $template;
    }

    public function generateController(string $table, string $controllerName = null, bool $withModel = true): string
    {
        $controllerName = $controllerName ?: $this->tableToControllerName($table);
        $modelName = $this->tableToModelName($table);
        $columns = $this->getTableColumns($table);
        $fillable = $this->getFillableColumns($columns);

        $namespace = $this->guessNamespace('controller');
        $modelNamespace = $this->guessNamespace('model');

        $validationRules = $this->generateValidationRules($fillable, $columns);

        $storeValidation = $this->generateStoreValidation($validationRules);
        $updateValidation = $this->generateUpdateValidation($validationRules);

        $storeFields = $this->generateStoreFields($fillable);
        $updateFields = $this->generateUpdateFields($fillable);

        $template = <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

use core\Controller;
use core\Request;
use {$modelNamespace}\\{$modelName};

class {$controllerName} extends Controller
{
    public function index(): \\core\\Response
    {
        \$page = (int) (\$_GET['page'] ?? 1);
        \$perPage = (int) (\$_GET['per_page'] ?? 15);

        \${$modelName} = new {$modelName}();
        \$result = \${$modelName}->paginate(\$perPage, \$page);

        return \$this->json([
            'list' => \$result['items'],
            'total' => \$result['total'],
            'page' => \$page,
            'per_page' => \$perPage,
        ]);
    }

    public function show(int \$id): \\core\\Response
    {
        \${$modelName} = new {$modelName}();
        \$data = \${$modelName}->find(\$id);

        if (!\$data) {
            return \$this->error('{$modelName} not found', 404);
        }

        return \$this->json(['data' => \$data]);
    }

    public function store(Request \$request): \\core\\Response
    {
{$storeValidation}
{$storeFields}
        \${$modelName} = new {$modelName}();
        \$id = \${$modelName}->create(\$data);

        \$data['id'] = \$id;
        return \$this->success(\$data, '{$modelName} created successfully');
    }

    public function update(int \$id, Request \$request): \\core\\Response
    {
{$updateValidation}
{$updateFields}
        \${$modelName} = new {$modelName}();
        \$result = \${$modelName}->update(\$id, \$data);

        if (\$result === 0) {
            return \$this->error('{$modelName} not found or no changes', 404);
        }

        \$data['id'] = \$id;
        return \$this->success(\$data, '{$modelName} updated successfully');
    }

    public function destroy(int \$id): \\core\\Response
    {
        \${$modelName} = new {$modelName}();
        \$result = \${$modelName}->delete(\$id);

        if (\$result === 0) {
            return \$this->error('{$modelName} not found', 404);
        }

        return \$this->success(['id' => \$id], '{$modelName} deleted successfully');
    }
}
PHP;

        return $template;
    }

    public function saveModel(string $table, string $modelName = null): string
    {
        $content = $this->generateModel($table, $modelName);
        $modelName = $modelName ?: $this->tableToModelName($table);
        $path = $this->getFilePath('model', $modelName);
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write model file: {$path}");
        }
        return $path;
    }

    public function saveController(string $table, string $controllerName = null, bool $withModel = true): string
    {
        $content = $this->generateController($table, $controllerName, $withModel);
        $controllerName = $controllerName ?: $this->tableToControllerName($table);
        $path = $this->getFilePath('controller', $controllerName);
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write controller file: {$path}");
        }
        return $path;
    }

    public function generateAll(string $table): array
    {
        $modelName = $this->tableToModelName($table);
        $controllerName = $this->tableToControllerName($table);

        return [
            'model' => [
                'name' => $modelName,
                'path' => $this->saveModel($table, $modelName),
            ],
            'controller' => [
                'name' => $controllerName,
                'path' => $this->saveController($table, $controllerName),
            ],
        ];
    }

    public function generateResourceRoutes(string $table, string $controllerName = null): string
    {
        $controllerName = $controllerName ?: $this->tableToControllerName($table);
        $name = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $controllerName));
        $name = str_replace('-controller', '', $name);

        return <<<PHP
// {$controllerName} 路由
\$router->get('/{$name}', [\\controller\\{$controllerName}::class, 'index']);
\$router->get('/{$name}/{id}', [\\controller\\{$controllerName}::class, 'show']);
\$router->post('/{$name}', [\\controller\\{$controllerName}::class, 'store']);
\$router->put('/{$name}/{id}', [\\controller\\{$controllerName}::class, 'update']);
\$router->delete('/{$name}/{id}', [\\controller\\{$controllerName}::class, 'destroy']);
PHP;
    }

    private function getPrimaryKey(array $columns): string
    {
        foreach ($columns as $column) {
            if ($column['Key'] === 'PRI') {
                return $column['Field'];
            }
        }
        return 'id';
    }

    private function getFillableColumns(array $columns): array
    {
        $fillable = [];
        $pk = $this->getPrimaryKey($columns);
        foreach ($columns as $column) {
            $field = $column['Field'];
            if ($field === $pk || $field === 'created_at' || $field === 'updated_at') {
                continue;
            }
            $fillable[] = $field;
        }
        return $fillable;
    }

    private function getCasts(array $columns): array
    {
        $casts = [];
        foreach ($columns as $column) {
            $type = $this->mapColumnType($column['Type']);
            if ($type !== 'string') {
                $casts[$column['Field']] = $type;
            }
        }
        return $casts;
    }

    private function mapColumnType(string $type): string
    {
        $type = strtolower($type);

        // tinyint(1) is commonly used for boolean in MySQL
        if ($type === 'tinyint(1)') {
            return 'bool';
        }
        if (strpos($type, 'int') !== false) {
            return 'int';
        }
        if (strpos($type, 'float') !== false || strpos($type, 'decimal') !== false || strpos($type, 'double') !== false) {
            return 'float';
        }
        if (strpos($type, 'bool') !== false) {
            return 'bool';
        }
        if (strpos($type, 'json') !== false) {
            return 'json';
        }

        return 'string';
    }

    private function generateValidationRules(array $fillable, array $columns): array
    {
        $rules = [];
        $columnTypes = [];
        foreach ($columns as $column) {
            $columnTypes[$column['Field']] = $column;
        }

        foreach ($fillable as $field) {
            if (!isset($columnTypes[$field])) {
                continue;
            }
            $column = $columnTypes[$field];
            $type = $this->mapColumnType($column['Type']);
            $isNull = $column['Null'] === 'YES';
            $fieldRules = [];

            if (!$isNull && $column['Default'] === null) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'optional';
            }

            switch ($type) {
                case 'int':
                case 'float':
                    $fieldRules[] = 'number';
                    break;
                case 'string':
                    if (preg_match('/varchar\((\d+)\)/', $column['Type'], $m)) {
                        $fieldRules[] = "max:{$m[1]}";
                    }
                    break;
            }

            $rules[$field] = implode('|', $fieldRules);
        }

        return $rules;
    }

    private function generateStoreValidation(array $rules): string
    {
        if (empty($rules)) {
            return '        // No validation rules defined';
        }

        $lines = ['        $validator = (new \\core\\Validate())->rules(['];
        foreach ($rules as $field => $rule) {
            $lines[] = "            '{$field}' => '{$rule}',";
        }
        $lines[] = "        ]);";
        $lines[] = "";
        $lines[] = '        if (!$validator->validate($request->all())) {';
        $lines[] = '            return $this->error($validator->firstError(), 422);';
        $lines[] = "        }";
        $lines[] = "";

        return implode("\n", $lines);
    }

    private function generateUpdateValidation(array $rules): string
    {
        if (empty($rules)) {
            return '        // No validation rules defined';
        }

        $lines = ['        $validator = (new \\core\\Validate())->rules(['];
        foreach ($rules as $field => $rule) {
            $rule = str_replace('required', 'optional', $rule);
            $lines[] = "            '{$field}' => '{$rule}',";
        }
        $lines[] = "        ]);";
        $lines[] = "";
        $lines[] = '        if (!$validator->validate($request->all())) {';
        $lines[] = '            return $this->error($validator->firstError(), 422);';
        $lines[] = "        }";
        $lines[] = "";

        return implode("\n", $lines);
    }

    private function generateStoreFields(array $fillable): string
    {
        if (empty($fillable)) {
            return '        $data = [];';
        }

        $lines = ['        $data = $request->only(['];
        $fieldStr = implode(', ', array_map(fn($f) => "'{$f}'", $fillable));
        $lines[] = "            {$fieldStr}";
        $lines[] = "        ]);";
        $lines[] = "";

        return implode("\n", $lines);
    }

    private function generateUpdateFields(array $fillable): string
    {
        if (empty($fillable)) {
            return '        $data = [];';
        }

        $lines = ['        $data = $request->only(['];
        $fieldStr = implode(', ', array_map(fn($f) => "'{$f}'", $fillable));
        $lines[] = "            {$fieldStr}";
        $lines[] = "        ]);";
        $lines[] = "";
        $lines[] = "        if (empty(\$data)) {";
        $lines[] = "            return \$this->error('No data to update', 422);";
        $lines[] = "        }";
        $lines[] = "";

        return implode("\n", $lines);
    }

    public function tableToModelName(string $table): string
    {
        $modelName = str_replace('_', ' ', $table);
        $modelName = ucwords($modelName);
        $modelName = str_replace(' ', '', $modelName);
        return $modelName;
    }

    public function tableToControllerName(string $table): string
    {
        return $this->tableToModelName($table) . 'Controller';
    }

    private function guessNamespace(string $type): string
    {
        return $type;
    }

    private function getFilePath(string $type, string $name): string
    {
        $basePath = defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 2) . '/app';

        return match($type) {
            'model' => $basePath . '/model/' . $name . '.php',
            'controller' => $basePath . '/controller/' . $name . '.php',
            default => $basePath . '/' . $type . '/' . $name . '.php',
        };
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function arrayToString(array $arr, bool $assoc = false): string
    {
        if (empty($arr)) {
            return '[]';
        }

        $items = [];
        foreach ($arr as $key => $value) {
            $escapedValue = str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value);
            if (is_int($key) && !$assoc) {
                $items[] = "'{$escapedValue}'";
            } else {
                $items[] = "'{$key}' => '{$escapedValue}'";
            }
        }

        return '[' . implode(', ', $items) . ']';
    }
}
