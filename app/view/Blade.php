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

        $cacheFile = $this->getCachePath($template);

        if (!$this->isCacheFresh($template, $cacheFile)) {
            $this->compile($template, $cacheFile);
        }

        if (!file_exists($cacheFile)) {
            trigger_error("Blade: Failed to compile template '{$template}', cache file missing", E_USER_WARNING);
            return '';
        }

        if (!ob_start()) {
            return '';
        }
        $__blade = $this;
        extract($data, EXTR_SKIP);
        require $cacheFile;
        $content = ob_get_clean();
        if ($content === false) {
            $content = '';
        }

        if (isset($this->sections['__layout'])) {
            $layout = $this->sections['__layout'];
            $this->sections['__layout'] = null;
            unset($this->sections['__layout']);
            $content = $this->render($layout, $data);
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
        $realSourceDir = realpath(dirname($sourcePath));
        $realTemplateDir = realpath($this->templatePath);
        if ($realSourceDir === false || $realTemplateDir === false || !str_starts_with($realSourceDir, $realTemplateDir)) {
            trigger_error("Blade: Template path traversal detected: {$template}", E_USER_WARNING);
            return;
        }

        $content = file_get_contents($sourcePath);
        if ($content === false) {
            trigger_error("Blade: Template source not found: {$sourcePath}", E_USER_WARNING);
            return;
        }

        $compiled = $this->compileString($content);
        if (file_put_contents($cacheFile, $compiled, LOCK_EX) === false) {
            trigger_error("Blade: Failed to write compiled template cache: {$cacheFile}", E_USER_WARNING);
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

        $content = $this->compileStatements($content);
        $content = $this->compileEchos($content);
        $content = $this->compileDirectives($content);
        $content = $this->compileIncludes($content);

        // 还原 verbatim 块
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
        $content = (string) preg_replace('/\{\{(.+?)\}\}/s', '<?= htmlspecialchars($1, ENT_QUOTES, \'UTF-8\') ?>', $content);
        $content = (string) preg_replace('/@json\((.+?)\)/s', '<?= json_encode($1, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>', $content);
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
        $statements = [
            '@extends\((.+?)\)'            => '<?php $__blade->startLayout($1); ?>',
            '@section\((.+?)\)'            => '<?php $__blade->startSection($1); ?>',
            '@endsection'                  => '<?php $__blade->endSection(); ?>',
            '@yield\((.+?)\)'              => '<?= $__blade->yieldSection($1) ?>',
            '@csrf'                        => '<?= \core\Session::token() ?>',
            '@method\((.+?)\)'             => '<input type="hidden" name="_method" value="$1">',
            '@php'                         => '<?php ',
            '@endphp'                      => '?>',
            '@if\((.+?)\)'                 => '<?php if ($1): ?>',
            '@elseif\((.+?)\)'             => '<?php elseif ($1): ?>',
            '@else(?!\w)'                    => '<?php else: ?>',
            '@endif'                       => '<?php endif; ?>',
            '@unless\((.+?)\)'             => '<?php if (!($1)): ?>',
            '@endunless'                   => '<?php endif; ?>',
            '@foreach\((.+?)\)'            => '<?php foreach ($1): ?>',
            '@endforeach'                  => '<?php endforeach; ?>',
            '@for\((.+?)\)'                => '<?php for ($1): ?>',
            '@endfor'                      => '<?php endfor; ?>',
            '@while\((.+?)\)'              => '<?php while ($1): ?>',
            '@endwhile'                    => '<?php endwhile; ?>',
            '@isset\((.+?)\)'              => '<?php if (isset($1)): ?>',
            '@endisset'                    => '<?php endif; ?>',
            '@empty\((.+?)\)'              => '<?php if (empty($1)): ?>',
            '@endempty'                    => '<?php endif; ?>',
            '@switch\((.+?)\)'             => '<?php switch ($1): ?>',
            '@case\((.+?)\)'              => '<?php case $1: ?>',
            '@break'                       => '<?php break; ?>',
            '@default'                     => '<?php default: ?>',
            '@endswitch'                   => '<?php endswitch; ?>',
            '@continue'                    => '<?php continue; ?>',
            '@verbatim'                    => '',
            '@endverbatim'                 => '',
        ];

        foreach ($statements as $pattern => $replacement) {
            $content = (string) preg_replace('/' . $pattern . '/', $replacement, $content);
        }

        return $content;
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
            '/@(\w+)\s*\((.+?)\)/',
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
        return (string) preg_replace_callback(
            '/@include\((.+?)\)/',
            function ($match) {
                $parts = array_map('trim', explode(',', $match[1], 2));
                $view = trim($parts[0], "'\"");
                $vars = $parts[1] ?? '[]';

                $cacheFile = $this->getCachePath($view);
                $sourcePath = $this->templatePath . $view . '.blade.php';

                // 防止路径遍历：验证 include 路径在模板目录内
                $realIncludeDir = realpath(dirname($sourcePath));
                $realTemplateDir = realpath($this->templatePath);
                if ($realIncludeDir === false || $realTemplateDir === false || !str_starts_with($realIncludeDir, $realTemplateDir)) {
                    trigger_error("Blade: Include path traversal detected: {$view}", E_USER_WARNING);
                    return '';
                }

                if (file_exists($sourcePath) && (!$this->isCacheFresh($view, $cacheFile))) {
                    $this->compile($view, $cacheFile);
                }

                if (file_exists($cacheFile)) {
                    // 验证缓存文件路径在预期目录内
                    $realCachePath = realpath(dirname($cacheFile));
                    $expectedCacheDir = realpath($this->cachePath);
                    if ($realCachePath === false || $expectedCacheDir === false || !str_starts_with($realCachePath, $expectedCacheDir)) {
                        trigger_error("Blade: Invalid include cache path: {$cacheFile}", E_USER_WARNING);
                        return '';
                    }
                    return '<?php extract(' . $vars . ', EXTR_SKIP); require \'' . str_replace('\\', '/', $cacheFile) . '\'; ?>';
                }

                trigger_error("Blade: Failed to compile include '{$view}', cache file not found: {$cacheFile}", E_USER_WARNING);
                return '';
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

    public function includeView(string $view): void
    {
        echo $this->render($view, []);
    }

    public function clear(): void
    {
        $this->sections = [];
        $this->stack = [];
    }
}