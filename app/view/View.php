<?php
declare(strict_types=1);

namespace view;

/**
 * 视图渲染器
 * 
 * 提供 PHP 模板渲染功能，支持模板继承、区块定义、数据共享等特性。
 * 默认开启 HTML 自动转义，防止 XSS 攻击。
 */
class View
{
    /** @var string 视图文件目录 */
    private string $viewPath;

    /** @var array 共享数据（所有视图可访问） */
    private array $sharedData = [];

    /** @var array 区块内容 */
    private array $sections = [];

    /** @var string 当前区块名称 */
    private string $currentSection = '';

    /** @var array 视图合成器 */
    private array $composers = [];

    /** @var bool 是否自动转义 HTML */
    private bool $autoEscape = true;

    /** @var array<string, mixed> 当前渲染的视图数据，供 extend() 访问 */
    private array $renderData = [];

    /**
     * 构造函数
     * 
     * @param string $viewPath 视图目录路径
     */
    public function __construct(string $viewPath = VIEW_PATH)
    {
        $this->viewPath = rtrim($viewPath, '/') . '/';
    }

    /**
     * 共享数据到所有视图
     * 
     * @param string $key 数据键名
     * @param mixed $value 数据值
     */
    public function share(string $key, mixed $value): void
    {
        $this->sharedData[$key] = $value;
    }

    /**
     * 注册视图合成器
     * 
     * @param string $view 视图名（支持通配符 *）
     * @param callable $callback 回调函数
     */
    public function composer(string $view, callable $callback): void
    {
        $this->composers[$view][] = $callback;
    }

    /**
     * 渲染视图模板
     * 
     * @param string $template 模板路径
     * @param array $data 视图数据
     * @return string 渲染结果
     * @throws \RuntimeException 当视图文件不存在时
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->viewPath . $this->normalizePath($template);
        if (!str_ends_with($file, '.php')) {
            $file .= '.php';
        }

        if (!file_exists($file)) {
            throw new \RuntimeException("View [{$template}] not found at: {$file}");
        }

        $this->applyComposer($template);

        $data = array_merge($this->sharedData, $data);

        if ($this->autoEscape) {
            $data = $this->escapeArray($data);
        }

        $__file = $file;
        $__view = $this;
        $__data = $data;
        $this->renderData = $data;
        unset($file, $data, $template);

        ob_start();
        extract($__data, EXTR_SKIP);
        require $__file;
        return ob_get_clean() ?: '';
    }

    /**
     * 禁用自动转义
     * 
     * @return self
     */
    public function withoutAutoEscape(): self
    {
        $this->autoEscape = false;
        return $this;
    }

    /**
     * 启用自动转义
     * 
     * @return self
     */
    public function withAutoEscape(): self
    {
        $this->autoEscape = true;
        return $this;
    }

    /**
     * 递归转义数组中的字符串
     * 
     * @param array $data 数据数组
     * @return array 转义后的数组
     */
    private function escapeArray(array $data): array
    {
        $escaped = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $escaped[$key] = $this->escapeArray($value);
            } elseif (is_string($value)) {
                $escaped[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            } else {
                $escaped[$key] = $value;
            }
        }
        return $escaped;
    }

    /**
     * 开始定义区块
     * 
     * @param string $name 区块名称
     */
    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /**
     * 结束定义区块
     */
    public function endSection(): void
    {
        if ($this->currentSection === '') {
            return;
        }

        $content = ob_get_clean() ?: '';
        if (!isset($this->sections[$this->currentSection])) {
            $this->sections[$this->currentSection] = '';
        }
        $this->sections[$this->currentSection] .= $content;
        $this->currentSection = '';
    }

    /**
     * 输出区块内容
     * 
     * @param string $name 区块名称
     * @param string $default 默认内容
     * @return string 区块内容
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * 继承布局模板
     * 
     * @param string $layout 布局模板路径
     */
    public function extend(string $layout): void
    {
        $layoutFile = $this->viewPath . $this->normalizePath($layout);
        if (!str_ends_with($layoutFile, '.php')) {
            $layoutFile .= '.php';
        }

        if (file_exists($layoutFile)) {
            if (ob_get_level() > 0) {
                ob_clean();
            }
            extract($this->renderData, EXTR_SKIP);
            $__view = $this;
            require $layoutFile;
        }
    }

    /**
     * 包含子视图
     * 
     * @param string $view 子视图路径
     * @param array $data 视图数据
     */
    public function include(string $view, array $data = []): void
    {
        echo $this->render($view, $data);
    }

    /**
     * 应用视图合成器
     * 
     * @param string $view 视图名
     */
    private function applyComposer(string $view): void
    {
        foreach ($this->composers as $pattern => $callbacks) {
            if ($this->matchView($pattern, $view)) {
                foreach ($callbacks as $callback) {
                    $callback($this);
                }
            }
        }
    }

    /**
     * 匹配视图模式
     * 
     * @param string $pattern 模式（支持通配符 *）
     * @param string $view 视图名
     * @return bool 是否匹配
     */
    private function matchView(string $pattern, string $view): bool
    {
        if ($pattern === $view) {
            return true;
        }

        if (str_ends_with($pattern, '*')) {
            return str_starts_with($view, rtrim($pattern, '*'));
        }

        return false;
    }

    /**
     * 规范化路径（安全处理）
     * 
     * @param string $path 路径
     * @return string 规范化后的路径
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = urldecode($path);
        $path = str_replace("\0", '', $path);
        // 仅移除路径遍历模式 ../ 和 /..
        while (str_contains($path, '../') || str_contains($path, '/..')) {
            $path = str_replace(['../', '/..'], '', $path);
        }
        return ltrim($path, '/');
    }

    /**
     * 输出原始 HTML（绕过转义）
     * 
     * @param string $raw 原始 HTML
     * @return string 原始 HTML
     */
    public function html(string $raw): string
    {
        return $raw;
    }
}