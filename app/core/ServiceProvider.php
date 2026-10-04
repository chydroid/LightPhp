<?php
declare(strict_types=1);

namespace core;

/**
 * 服务提供者基类 - 参考 Laravel ServiceProvider
 * 用于模块化注册服务到容器和引导应用
 *
 * 使用方式:
 *   class AppServiceProvider extends ServiceProvider {
 *       public function register(): void {
 *           $this->app->singleton('myService', fn($app) => new MyService());
 *       }
 *       public function boot(): void {}
 *   }
 */
abstract class ServiceProvider
{
    /** @var Container */
    protected Container $app;

    /** @var Application|null */
    protected ?Application $application = null;

    public function __construct(?Container $app = null)
    {
        // 允许省略容器：Application 从 config('app.providers') 实例化提供者时
        // 直接 new $className()，此时容器随后由 setApplication()/container() 注入。
        // 强制要求参数会让该路径无法工作。
        $this->app = $app ?? Container::getInstance() ?? new Container();
    }

    public function setApplication(Application $application): void
    {
        $this->application = $application;
    }

    /**
     * 注册服务绑定 - 在应用启动初期调用
     */
    abstract public function register(): void;

    /**
     * 引导服务 - 在所有服务注册完成后调用
     */
    public function boot(): void
    {
    }

    /**
     * 获取容器
     */
    protected function container(): Container
    {
        return $this->app;
    }
}