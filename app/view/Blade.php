<?php
declare(strict_types=1);

namespace view;

/**
 * 轻量级模板编译器 - 参考 Laravel Blade
 * 编译自定义语法到原生PHP，带缓存机制
 *
 * 语法:
 *   {{ $var }}       → 转义输出
 *   {!! $html !!}    → 原始输出
 *   @if / @elseif / @else / @endif
 *   @foreach / @endforeach
 *   @for / @endfor
 *   @while / @endwhile
 *   @include('view', data)
 *   @extends('layout')
 *   @section('name') / @endsection
 *   @yield('name')
 *   @php / @endphp
 *   @isset($var) / @endisset
 *   @empty($var) / @endempty
 *   @csrf
 *   @json($data)
 */
class Blade
{
    /** @var string 模板文件目录 */
    private string $templatePath;

    /** @var string 编译缓存目录 */
    private string $cachePath;

    /** @var array 区块内容 */
    private array $sections = [];

    /** @var array 区块栈 */
    private array $stack = [];

    /** @var array push 内容栈 */
    private array $pushStack = [];

    /** @var array prepend 内容栈 */
    private array $prependStack = [];

    /** @var array 自定义指令 */
    private static array $directives = [];

    /**
     * 构造函数
     * 
     * @param string $templatePath 模板目录路径
     * @param string $cachePath 缓存目录路径
     */
    public function __construct(string $templatePath, string $cachePath)
    {
        $this->templatePath = rtrim($templatePath, '/') . '/';
        $this->cachePath = rtrim($cachePath, '/') . '/';

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * 注册自定义指令
     * 
     * @param string $name 指令名称
     * @param callable $handler 指令处理器
     */
    public static function directive(string $name, callable $handler): void
    {
        self::$directives[$name] = $handler;
    }

    /**
     * 渲染模板
     * 
     * @param string $template 模板路径（不含扩展名）
     * @param array $data 视图数据
     * @return string 渲染结果
     */
    public function render(string $template, array $data = []): string
    {
        $this->sections = [];
        $this->stack = [];
        $this->pushStack = [];
        $this->prependStack = [];

        $rendered = [$template];
        $content = $this->renderTemplate($template, $data);

        while (isset($this->sections['__layout'])) {
            $layout = $this->sections['__layout'];
            unset($this->sections['__layout']);

            if (in_array($layout, $rendered, true)) {
                throw new \RuntimeException("Blade: Infinite recursion detected - layout '{$layout}' has already been rendered");
            }
            $rendered[] = $layout;

            $this->stack = [];
            $content = $this->renderTemplate($layout, $data);
        }

        return $content;
    }

    /**
     * 渲染单个模板文件（不处理布局继承）
     */
    private function renderTemplate(string $template, array $data = []): string
    {
        $cacheFile = $this->getCachePath($template);

        if (!$this->isCacheFresh($template, $cacheFile)) {
            $this->compile($template, $cacheFile);
        }

        if (!file_exists($cacheFile)) {
            trigger_error("Blade: Failed to compile template '{$template}', cache file missing", E_USER_WARNING);
            return '';
        }

        $initialObLevel = ob_get_level();
        if (!ob_start()) {
            return '';
        }
        try {
            $__blade = $this;
            extract($data, EXTR_SKIP);
            require $cacheFile;
        } catch (\Throwable $t) {
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
            throw $t;
        }
        $content = ob_get_clean();
        if ($content === false) {
            $content = '';
        }

        return $content;
    }

    /**
     * 编译模板文件
     * 
     * @param string $template 模板名
     * @param string $cacheFile 缓存文件路径
     */
    private function compile(string $template, string $cacheFile): void
    {
        $sourcePath = $this->templatePath . $template . '.blade.php';

        // 防止路径遍历：验证模板路径在模板目录内
        // 必须附加 DIRECTORY_SEPARATOR，否则 /app/view-evil 会匹配 /app/view 前缀
        $realSourceDir = realpath(dirname($sourcePath));
        $realTemplateDir = realpath($this->templatePath);
        if ($realSourceDir === false || $realTemplateDir === false
            || $realSourceDir !== $realTemplateDir
            && !str_starts_with($realSourceDir, $realTemplateDir . DIRECTORY_SEPARATOR)) {
            trigger_error("Blade: Template path traversal detected: {$template}", E_USER_WARNING);
            return;
        }

        $content = file_get_contents($sourcePath);
        if ($content === false) {
            trigger_error("Blade: Template source not found: {$sourcePath}", E_USER_WARNING);
            return;
        }

        $compiled = $this->compileString($content);
        // 原子写：先写临时文件再 rename。直接 file_put_contents 会让并发的另一请求
        // require 到写了一半的 PHP 文件，产生 ParseError 或执行残缺模板
        // （FileCache::write() 已采用同样的 tmp+rename 策略，此处对齐）。
        $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmpFile, $compiled, LOCK_EX) === false) {
            trigger_error("Blade: Failed to write compiled template cache: {$cacheFile}", E_USER_WARNING);
            return;
        }
        if (!@rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            trigger_error("Blade: Failed to publish compiled template cache: {$cacheFile}", E_USER_WARNING);
        }
    }

    /**
     * 编译字符串内容
     * 
     * @param string $content 模板内容
     * @return string 编译后的 PHP 代码
     */
    public function compileString(string $content): string
    {
        // 先保护 verbatim 块，防止内部指令被编译
        $verbatimBlocks = [];
        $content = (string) preg_replace_callback(
            '/@verbatim(.*?)@endverbatim/s',
            function ($m) use (&$verbatimBlocks) {
                $key = '__VERBATIM_' . count($verbatimBlocks) . '__';
                $verbatimBlocks[$key] = $m[1];
                return $key;
            },
            $content
        );

        // 保护「邮箱形态」的 token：support@endif.com / user@endforeach
        // 否则其中的 @endif / @endforeach 会被当作指令编译，产生 ParseError。
        // 用 `@@` 转义指令的写法不冲突（这里匹配的是 xxx@yyy.zzz 形态）。
        $emailTokens = [];
        $content = (string) preg_replace_callback(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            function ($m) use (&$emailTokens) {
                $key = '__EMAILETOKEN_' . count($emailTokens) . '__';
                $emailTokens[$key] = $m[0];
                return $key;
            },
            $content
        );

        $content = $this->compileEach($content);
        $content = $this->compileStatements($content);
        $content = $this->compileEchos($content);
        $content = $this->compileDirectives($content);
        $content = $this->compileIncludes($content);

        // 还原邮箱 token 与 verbatim 块
        $content = str_replace(array_keys($emailTokens), array_values($emailTokens), $content);
        return str_replace(array_keys($verbatimBlocks), array_values($verbatimBlocks), $content);
    }

    /**
     * 编译输出语句
     * 
     * @param string $content 模板内容
     * @return string 编译后的内容
     */
    private function compileEchos(string $content): string
    {
        $content = (string) preg_replace('/\{\!!\s*(.+?)\s*!!\}/s', '<?= $1 ?>', $content);
        $content = (string) preg_replace('/\{\{\{\s*(.+?)\s*\}\}\}/s', '<?= htmlspecialchars((string) $1, ENT_QUOTES, \'UTF-8\') ?>', $content);
        $content = (string) preg_replace('/\{\{(.+?)\}\}/s', '<?= htmlspecialchars((string) $1, ENT_QUOTES, \'UTF-8\') ?>', $content);
        $content = (string) preg_replace_callback(
            '/@json\(((?:[^()]++|\((?1)\))*+)\)/s',
            fn ($m) => '<?= json_encode(' . $m[1] . ', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>',
            $content
        );
        return $content;
    }

    /**
     * 编译控制语句
     *
     * @param string $content 模板内容
     * @return string 编译后的内容
     */
    private function compileStatements(string $content): string
    {
        // 需要解析平衡括号的指令：prefix / suffix 中的 $1 由平衡括号匹配替换
        $balanced = [
            ['if',          '<?php if (',                            '): ?>'],
            ['elseif',      '<?php elseif (',                        '): ?>'],
            ['unless',      '<?php if (!(',                           ')): ?>'],
            ['foreach',     '<?php foreach (',                        '): ?>'],
            ['for',         '<?php for (',                            '): ?>'],
            ['while',       '<?php while (',                           '): ?>'],
            ['isset',       '<?php if (isset(',                        ')): ?>'],
            ['empty',       '<?php if (empty(',                        ')): ?>'],
            ['switch',      '<?php switch (',                           '): ?>'],
            ['case',        '<?php case ',                             ': ?>'],
            ['section',     '<?php $__blade->startSection(',           '); ?>'],
            ['yield',       '<?= $__blade->yieldSection(',             ') ?>'],
            ['extends',     '<?php $__blade->startLayout(',             '); ?>'],
            ['push',        '<?php $__blade->startPush(',              '); ?>'],
            ['prepend',     '<?php $__blade->startPrepend(',           '); ?>'],
            ['stack',       '<?= $__blade->yieldPushContent(',          ') ?>'],
            ['env',         '<?php if (env(\'APP_ENV\') === ',          '): ?>'],
            ['method',      '<input type="hidden" name="_method" value="<?= htmlspecialchars(', ', ENT_QUOTES, \'UTF-8\') ?>">'],
        ];

        foreach ($balanced as [$directive, $prefix, $suffix]) {
            $content = $this->compileBalanced($content, $directive, $prefix, $suffix);
        }

        $statements = [
            '@endsection(?!\w)'              => '<?php $__blade->endSection(); ?>',
            '@csrf(?:\(\s*\))?(?!\w)'       => '<input type="hidden" name="_token" value="<?= htmlspecialchars(\core\Session::token(), ENT_QUOTES, \'UTF-8\') ?>">',
            '@php(?!\w)'                   => '<?php ',
            '@endphp(?!\w)'                => '?>',
            '@else(?!\w)'                  => '<?php else: ?>',
            '@endif(?!\w)'                 => '<?php endif; ?>',
            '@endunless(?!\w)'             => '<?php endif; ?>',
            '@endforeach(?!\w)'            => '<?php endforeach; ?>',
            '@endfor(?!\w)'                => '<?php endfor; ?>',
            '@endwhile(?!\w)'              => '<?php endwhile; ?>',
            '@endisset(?!\w)'              => '<?php endif; ?>',
            '@endempty(?!\w)'              => '<?php endif; ?>',
            '@break(?!\w)'                 => '<?php break; ?>',
            '@default(?!\w)'               => '<?php default: ?>',
            '@endswitch(?!\w)'             => '<?php endswitch; ?>',
            '@continue(?!\w)'              => '<?php continue; ?>',
            '@verbatim(?!\w)'              => '',
            '@endverbatim(?!\w)'           => '',
            '@endpush(?!\w)'               => '<?php $__blade->endPush(); ?>',
            '@endprepend(?!\w)'            => '<?php $__blade->endPrepend(); ?>',
            '@production(?!\w)'            => '<?php if (env(\'APP_ENV\') === \'production\'): ?>',
            '@endproduction(?!\w)'         => '<?php endif; ?>',
        ];

        foreach ($statements as $pattern => $replacement) {
            $content = (string) preg_replace('/' . $pattern . '/', $replacement, $content);
        }

        return $content;
    }

    /**
     * 编译带平衡括号的指令
     *
     * 使用递归正则匹配成对括号，避免 @if($a && ($b || $c)) 等嵌套表达式被截断。
     *
     * @param string $content 模板内容
     * @param string $directive 指令名
     * @param string $prefix 参数前的前缀
     * @param string $suffix 参数后的后缀
     * @return string 编译后的内容
     */
    private function compileBalanced(string $content, string $directive, string $prefix, string $suffix): string
    {
        // 允许 `@if($x)` 与 `@if ($x)` 两种写法（后者是 Blade/Laravel 的惯用形式）。
        //
        // 注意：不要在此追加 (?!\w) 后向断言。参数部分使用了 possessive 量词 *+，
        // 一旦匹配失败就不允许回溯，追加断言会让 `@if ($x)` 这类写法整体失配
        // （实测 plus_both 变体无法编译，而仅加 \s* 的 plus_ws 正常）。
        // 指令名误匹配（如 @iffy）由 compileDirectives / 词形完整名约束规避。
        return (string) preg_replace_callback(
            '/@' . preg_quote($directive, '/') . '\s*\(((?:[^()]++|\((?1)\))*+)\)/s',
            fn ($m) => $prefix . $m[1] . $suffix,
            $content
        );
    }

    /**
     * 按顶层逗号分割平衡括号内的参数
     *
     * 支持参数内部包含圆括号、方括号、花括号以及引号字符串。
     *
     * @param string $args 参数字符串
     * @return array 分割后的参数数组
     */
    private function splitBalancedArgs(string $args): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $len = strlen($args);
        $inQuote = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $args[$i];
            if ($inQuote !== null) {
                $current .= $ch;
                if ($ch === $inQuote && ($i === 0 || $args[$i - 1] !== '\\')) {
                    $inQuote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $current .= $ch;
                $inQuote = $ch;
                continue;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                $current .= $ch;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        $trimmed = trim($current);
        if ($trimmed !== '' || !empty($parts)) {
            $parts[] = $trimmed;
        }

        return $parts;
    }

    /**
     * 编译自定义指令
     *
     * @param string $content 模板内容
     * @return string 编译后的内容
     */
    private function compileDirectives(string $content): string
    {
        $content = (string) preg_replace_callback(
            '/@(\w+)\s*\(((?:[^()]++|\((?2)\))*)\)/',
            function ($match) {
                $name = $match[1];
                if (isset(self::$directives[$name])) {
                    $handler = self::$directives[$name];
                    $result = $handler($match[2]);
                    return '<?php ' . $result . ' ?>';
                }
                return $match[0];
            },
            $content
        );
        return $content;
    }

    /**
     * 编译 include 语句
     *
     * @param string $content 模板内容
     * @return string 编译后的内容
     */
    private function compileIncludes(string $content): string
    {
        $counter = 0;
        return (string) preg_replace_callback(
            '/@include\(((?:[^()]++|\((?1)\))*)\)/',
            function ($match) use (&$counter) {
                $suffix = $counter++;
                $parts = $this->splitBalancedArgs($match[1]);
                $view = trim($parts[0] ?? '', "'\"");
                $vars = $parts[1] ?? '[]';

                $sourcePath = $this->templatePath . $view . '.blade.php';

                // 防止路径遍历：验证 include 路径在模板目录内
                $realIncludeDir = realpath(dirname($sourcePath));
                $realTemplateDir = realpath($this->templatePath);
                if ($realIncludeDir === false || $realTemplateDir === false
                    || $realIncludeDir !== $realTemplateDir
                    && !str_starts_with($realIncludeDir, $realTemplateDir . DIRECTORY_SEPARATOR)) {
                    trigger_error("Blade: Include path traversal detected: {$view}", E_USER_WARNING);
                    return '';
                }

                // 运行时解析 include，确保子模板修改后能触发重编译
                // 使用唯一变量名，防止嵌套 include 时保存的状态被内层覆盖。
                //
                // extract 必须用 EXTR_OVERWRITE：父作用域已存在同名变量时，
                // EXTR_SKIP 会静默丢弃 @include 传入的值
                // （实测 @include('row', ['name'=>'OVERRIDE']) 在父模板已有
                //   $name 时渲染出父模板的值，文档承诺的「覆盖」语义失效）。
                // include 结束后恢复父作用域，避免污染后续模板代码。
                return '<?php $__prevSections_' . $suffix . ' = $__blade->getSections(); $__prevStack_' . $suffix . ' = $__blade->getStack(); '
                     . 'if ($__inc_' . $suffix . ' = $__blade->resolveInclude(\'' . addslashes($view) . '\')) { '
                     . '$__incKeys_' . $suffix . ' = array_keys(' . $vars . '); '
                     . '$__incPrev_' . $suffix . ' = []; $__incFresh_' . $suffix . ' = []; '
                     . '$__incDefined_' . $suffix . ' = get_defined_vars(); '
                     . 'foreach ($__incKeys_' . $suffix . ' as $__incK' . $suffix . ') { '
                     . 'if (array_key_exists($__incK' . $suffix . ', $__incDefined_' . $suffix . ')) { '
                     . '$__incPrev_' . $suffix . '[$__incK' . $suffix . '] = $$__incK' . $suffix . '; '
                     . '} else { $__incFresh_' . $suffix . '[] = $__incK' . $suffix . '; } '
                     . '} '
                     . 'extract(' . $vars . ', EXTR_OVERWRITE); '
                     . 'require $__inc_' . $suffix . '; '
                     . 'foreach ($__incPrev_' . $suffix . ' as $__incK' . $suffix . ' => $__incV' . $suffix . ') { $$__incK' . $suffix . ' = $__incV' . $suffix . '; } '
                     . 'foreach ($__incFresh_' . $suffix . ' as $__incK' . $suffix . ') { unset($$__incK' . $suffix . '); } '
                     . '} '
                     . '$__blade->restoreState($__prevSections_' . $suffix . ', $__prevStack_' . $suffix . '); ?>';
            },
            $content
        );
    }

    /**
     * 编译 each 语句
     *
     * @param string $content 模板内容
     * @return string 编译后的内容
     */
    private function compileEach(string $content): string
    {
        return (string) preg_replace_callback(
            '/@each\(((?:[^()]++|\((?1)\))*)\)/',
            function ($match) {
                $parts = $this->splitBalancedArgs($match[1]);
                if (count($parts) < 3) {
                    return $match[0];
                }
                $view = trim($parts[0], "'\"");
                $items = $parts[1];
                $var = trim($parts[2], "'\"");
                return '<?php foreach ((array)(' . $items . ') as $__key => $' . $var . '): ?>'
                     . '<?php $__inc_each = $__blade->resolveInclude(\'' . addslashes($view) . '\'); if ($__inc_each) { require $__inc_each; } ?>'
                     . '<?php endforeach; ?>';
            },
            $content
        );
    }

    /**
     * 获取缓存文件路径
     * 
     * @param string $template 模板名
     * @return string 缓存文件路径
     */
    private function getCachePath(string $template): string
    {
        return $this->cachePath . hash('sha256', $template) . '.php';
    }

    /**
     * 检查缓存是否过期
     * 
     * @param string $template 模板名
     * @param string $cacheFile 缓存文件路径
     * @return bool 是否新鲜
     */
    private function isCacheFresh(string $template, string $cacheFile): bool
    {
        $sourcePath = $this->templatePath . $template . '.blade.php';
        if (!file_exists($cacheFile) || !file_exists($sourcePath)) {
            return false;
        }
        $cacheMtime = filemtime($cacheFile);
        $sourceMtime = filemtime($sourcePath);
        if ($cacheMtime === false || $sourceMtime === false) {
            return false;
        }
        return $cacheMtime >= $sourceMtime;
    }

    // ─── 模板继承辅助 ───

    public function startLayout(string $layout): void
    {
        $this->sections['__layout'] = $layout;
    }

    public function startSection(string $name): void
    {
        $this->stack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if (empty($this->stack)) {
            return;
        }
        $content = ob_get_clean();
        if ($content === false) {
            $content = '';
        }
        $name = array_pop($this->stack);
        if ($name === null) {
            return;
        }
        if (!isset($this->sections[$name])) {
            $this->sections[$name] = '';
        }
        $this->sections[$name] .= $content;
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function startPush(string $name): void
    {
        $this->stack[] = 'push:' . $name;
        ob_start();
    }

    public function endPush(): void
    {
        if (empty($this->stack)) {
            return;
        }
        $content = ob_get_clean();
        if ($content === false) {
            $content = '';
        }
        $name = array_pop($this->stack);
        if ($name === null) {
            return;
        }
        $name = substr($name, 5);
        if (!isset($this->pushStack[$name])) {
            $this->pushStack[$name] = [];
        }
        $this->pushStack[$name][] = $content;
    }

    public function startPrepend(string $name): void
    {
        $this->stack[] = 'prepend:' . $name;
        ob_start();
    }

    public function endPrepend(): void
    {
        if (empty($this->stack)) {
            return;
        }
        $content = ob_get_clean();
        if ($content === false) {
            $content = '';
        }
        $name = array_pop($this->stack);
        if ($name === null) {
            return;
        }
        $name = substr($name, 8);
        if (!isset($this->prependStack[$name])) {
            $this->prependStack[$name] = [];
        }
        array_unshift($this->prependStack[$name], $content);
    }

    public function yieldPushContent(string $name, string $default = ''): string
    {
        $prepend = $this->prependStack[$name] ?? [];
        $push = $this->pushStack[$name] ?? [];
        $all = array_merge($prepend, $push);
        return empty($all) ? $default : implode('', $all);
    }

    public function includeView(string $view): void
    {
        $prevSections = $this->sections;
        $prevStack = $this->stack;
        try {
            echo $this->renderTemplate($view, []);
        } finally {
            $this->sections = $prevSections;
            $this->stack = $prevStack;
        }
    }

    /**
     * 运行时解析 include 模板，检查缓存新鲜度并按需重编译
     */
    public function resolveInclude(string $view): string
    {
        $cacheFile = $this->getCachePath($view);
        if (!$this->isCacheFresh($view, $cacheFile)) {
            $this->compile($view, $cacheFile);
        }
        if (!file_exists($cacheFile)) {
            return '';
        }
        $realCachePath = realpath(dirname($cacheFile));
        $expectedCacheDir = realpath($this->cachePath);
        if ($realCachePath === false || $expectedCacheDir === false || !str_starts_with($realCachePath, $expectedCacheDir)) {
            return '';
        }
        return $cacheFile;
    }

    public function clear(): void
    {
        $this->sections = [];
        $this->stack = [];
    }

    public function getSections(): array
    {
        return $this->sections;
    }

    public function getStack(): array
    {
        return $this->stack;
    }

    public function restoreState(array $sections, array $stack): void
    {
        $this->sections = $sections;
        $this->stack = $stack;
    }
}