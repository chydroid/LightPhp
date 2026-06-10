<?php
declare(strict_types=1);

namespace view;

use core\Response;

class SmartyView
{
    private Smarty $smarty;
    private string $layout = '';
    private array $sections = [];
    private ?string $sectionBlock = null;

    public function __construct(
        string $templatePath = VIEW_PATH,
        string $compilePath = STORAGE_PATH . 'cache/smarty/compile/',
        string $cachePath = STORAGE_PATH . 'cache/smarty/cache/'
    ) {
        $this->smarty = new \view\Smarty($templatePath, $compilePath, $cachePath);
    }

    public function assign(string|array $key, mixed $value = null): self
    {
        $this->smarty->assign($key, $value);
        return $this;
    }

    public function display(string $template, array $data = []): Response
    {
        foreach ($data as $key => $value) {
            $this->smarty->assign($key, $value);
        }

        if ($this->layout) {
            $this->smarty->assign('_content_', $this->smarty->fetch($template));
            $content = $this->smarty->fetch($this->layout);
        } else {
            $content = $this->smarty->fetch($template);
        }

        return Response::make($content);
    }

    public function fetch(string $template, array $data = []): string
    {
        foreach ($data as $key => $value) {
            $this->smarty->assign($key, $value);
        }
        return $this->smarty->fetch($template);
    }

    public function layout(string $layout): self
    {
        $this->layout = $layout;
        return $this;
    }

    public function section(string $name): void
    {
        $this->sectionBlock = $name;
        if (!ob_start()) {
            $this->sectionBlock = null;
            return;
        }
    }

    public function endsection(): void
    {
        if ($this->sectionBlock !== null) {
            $content = ob_get_clean();
            if ($content === false) {
                $content = '';
            }
            $this->sections[$this->sectionBlock] = $content;
            $this->smarty->assign($this->sectionBlock, $content);
            $this->sectionBlock = null;
        }
    }

    public function yield(string $section): string
    {
        return $this->sections[$section] ?? '';
    }

    public function extend(string $template): self
    {
        $this->layout = $template;
        return $this;
    }

    public function exists(string $template): bool
    {
        return $this->smarty->exists($template);
    }

    public function enableCache(int $lifetime = 3600): self
    {
        $this->smarty->enableCache()->setCacheLifetime($lifetime);
        return $this;
    }

    public function disableCache(): self
    {
        $this->smarty->disableCache();
        return $this;
    }

    public function clearCache(string $template = null): self
    {
        $this->smarty->clearCache($template);
        return $this;
    }

    public function getSmarty(): Smarty
    {
        return $this->smarty;
    }
}
