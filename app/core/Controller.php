<?php
declare(strict_types=1);

namespace core;

/**
 * 控制器基类
 * 
 * 所有控制器的父类，提供常用的响应方法。
 */
class Controller
{
    /** @var Response|null 响应对象 */
    protected ?Response $response = null;

    /**
     * 构造函数 - 初始化响应对象
     */
    public function __construct()
    {
        $this->response = new Response();
    }

    /**
     * 渲染视图模板
     * 
     * @param string $template 模板路径
     * @param array $data 模板变量
     * @return Response 响应对象
     */
    protected function view(string $template, array $data = []): Response
    {
        $view = new \view\View();
        $content = $view->render($template, $data);
        return $this->response->content($content);
    }

    /**
     * 使用 Smarty 渲染视图
     * 
     * @param string $template 模板路径
     * @param array $data 模板变量
     * @return Response 响应对象
     */
    protected function smartyView(string $template, array $data = []): Response
    {
        $view = new \view\SmartyView();
        return $view->display($template, $data);
    }

    /**
     * 返回 JSON 响应
     * 
     * @param array $data 数据数组
     * @param int $statusCode HTTP 状态码
     * @return Response JSON 响应对象
     */
    protected function json(array $data, int $statusCode = 200): Response
    {
        return Response::json($data, $statusCode);
    }

    /**
     * 返回重定向响应
     * 
     * @param string $url 重定向地址
     * @param int $statusCode HTTP 状态码
     * @return Response 重定向响应对象
     */
    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return Response::redirect($url, $statusCode);
    }

    /**
     * 返回成功响应（统一格式）
     * 
     * @param array $data 数据数组
     * @param string $message 提示消息
     * @return Response JSON 响应对象
     */
    protected function success(array $data = [], string $message = 'success'): Response
    {
        return Response::json([
            'code' => 0,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * 返回错误响应（统一格式）
     * 
     * @param string $message 错误消息
     * @param int $code 错误码
     * @param array $data 附加数据
     * @return Response JSON 响应对象
     */
    protected function error(string $message = 'error', int $code = -1, array $data = []): Response
    {
        return Response::json([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * 返回 404 响应
     * 
     * @param string $message 错误消息
     * @return Response 404 响应对象
     */
    protected function notFound(string $message = 'Not Found'): Response
    {
        return Response::make('<h1>' . htmlspecialchars($message) . '</h1>', 404);
    }
}
