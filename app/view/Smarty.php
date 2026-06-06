<?php
declare(strict_types=1);

namespace view;

class Smarty
{
    private \Smarty $smarty;
    private string $templatePath;
    private string $compilePath;
    private string $cachePath;
    private string $configPath;
    private array $assignData = [];

    public function __construct(
        string $templatePath = VIEW_PATH,
        string $compilePath = STORAGE_PATH . 'cache/smarty/compile/',
        string $cachePath = STORAGE_PATH . 'cache/smarty/cache/'
    ) {
        if (!class_exists('\Smarty')) {
            throw new \Exception('Smarty class not found. Please install Smarty or ensure Smarty library is available.');
        }

        $this->templatePath = rtrim($templatePath, '/') . '/';
        $this->compilePath = rtrim($compilePath, '/') . '/';
        $this->cachePath = rtrim($cachePath, '/') . '/';
        $this->configPath = $this->compilePath . 'configs/';

        if (!is_dir($this->compilePath)) {
            mkdir($this->compilePath, 0755, true);
        }
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
        if (!is_dir($this->configPath)) {
            mkdir($this->configPath, 0755, true);
        }

        $this->smarty = new \Smarty();

        $this->smarty->setTemplateDir($this->templatePath);
        $this->smarty->setCompileDir($this->compilePath);
        $this->smarty->setCacheDir($this->cachePath);
        $this->smarty->setConfigDir($this->configPath);

        $this->smarty->setCompileCheck(\Smarty::COMPILECHECK_ON);
        $this->smarty->setCaching(\Smarty::CACHING_OFF);
        $this->smarty->setForceCompile(false);
    }

    public function assign(string|array $key, $value = null): self
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->smarty->assign($k, $v);
                $this->assignData[$k] = $v;
            }
        } else {
            $this->smarty->assign($key, $value);
            $this->assignData[$key] = $value;
        }
        return $this;
    }

    public function display(string $template): void
    {
        $this->smarty->display($template);
    }

    public function fetch(string $template): string
    {
        return $this->smarty->fetch($template);
    }

    public function exists(string $template): bool
    {
        return file_exists($this->templatePath . $template);
    }

    public function setTemplateDir(string $path): self
    {
        $this->smarty->setTemplateDir(rtrim($path, '/') . '/');
        $this->templatePath = rtrim($path, '/') . '/';
        return $this;
    }

    public function setCompileDir(string $path): self
    {
        $this->smarty->setCompileDir(rtrim($path, '/') . '/');
        $this->compilePath = rtrim($path, '/') . '/';
        return $this;
    }

    public function setCacheDir(string $path): self
    {
        $this->smarty->setCacheDir(rtrim($path, '/') . '/');
        $this->cachePath = rtrim($path, '/') . '/';
        return $this;
    }

    public function enableCache(): self
    {
        $this->smarty->setCaching(\Smarty::CACHING_ON);
        return $this;
    }

    public function disableCache(): self
    {
        $this->smarty->setCaching(\Smarty::CACHING_OFF);
        return $this;
    }

    public function setCacheLifetime(int $seconds): self
    {
        $this->smarty->setCacheLifetime($seconds);
        return $this;
    }

    public function clearCache(string $template = null): self
    {
        if ($template) {
            $this->smarty->clearCache($template);
        } else {
            $this->smarty->clearAllCache();
        }
        return $this;
    }

    public function clearAssign(string $key = null): self
    {
        if ($key) {
            $this->smarty->clearAssign($key);
            unset($this->assignData[$key]);
        } else {
            $this->smarty->clearAllAssign();
            $this->assignData = [];
        }
        return $this;
    }

    public function getTemplatePath(): string
    {
        return $this->templatePath;
    }

    public function getCompilePath(): string
    {
        return $this->compilePath;
    }

    public function getAssignedData(): array
    {
        return $this->assignData;
    }

    public function getSmarty(): \Smarty
    {
        return $this->smarty;
    }
}
