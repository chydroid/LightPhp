<?php
declare(strict_types=1);

namespace core;

use core\Request;
use core\Response;
use core\exception\HttpException;

/**
 * 异常处理器
 * 
 * 实现异常的 report/render 分离机制。
 * report 负责日志记录（可配置不报告的异常类型），
 * render 负责将异常转换为 HTTP 响应（区分调试与生产环境、JSON 与 HTML）。
 */
class ExceptionHandler
{
    /** @var array 不需要记录日志的异常类名列表 */
    protected array $dontReport = [];

    /** @var array 不应在响应中暴露的敏感数据字段列表 */
    protected array $dontFlash = [];

    /** @var \log\Logger|null 日志记录器实例 */
    private ?\log\Logger $logger = null;

    /** @var bool 是否处于调试模式 */
    private bool $debug = false;

    /** @var Request|null 当前请求实例 */
    private ?Request $currentRequest = null;

    /**
     * 构造函数
     * 
     * @param \log\Logger|null $logger 日志记录器实例
     * @param bool $debug 是否开启调试模式
     */
    public function __construct(?\log\Logger $logger, bool $debug)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * 报告/记录异常
     * 
     * 将异常写入日志，跳过 $dontReport 中配置的异常类型。
     * 
     * @param \Throwable $e 待报告的异常
     */
    public function report(\Throwable $e): void
    {
        if (!$this->shouldReport($e)) {
            return;
        }

        if ($this->logger === null) {
            return;
        }

        $context = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        $this->logger->error($e->getMessage(), $context);
    }

    /**
     * 判断异常是否应该被报告
     * 
     * 检查异常是否属于 $dontReport 列表中的类型。
     * 
     * @param \Throwable $e 待检查的异常
     * @return bool 是否应该报告
     */
    public function shouldReport(\Throwable $e): bool
    {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * 将异常渲染为 HTTP 响应
     * 
     * 根据异常类型和请求特征，选择合适的渲染方式。
     * HttpException 走专用渲染，其他异常走通用渲染。
     * 
     * @param \core\Request $request 当前请求实例
     * @param \Throwable $e 待渲染的异常
     * @return \core\Response HTTP 响应
     */
    public function render(Request $request, \Throwable $e): Response
    {
        $this->currentRequest = $request;

        if ($e instanceof HttpException) {
            return $this->renderHttpException($e);
        }

        return $this->renderException($e);
    }

    /**
     * 渲染 HTTP 异常
     * 
     * 针对 HttpException 生成对应状态码的响应，
     * 支持 JSON 和 HTML 两种输出格式。
     * 
     * @param \core\exception\HttpException $e HTTP 异常实例
     * @return \core\Response HTTP 响应
     */
    /** @var array<int, string> HTTP 状态码对应的默认安全消息 */
    private const SAFE_MESSAGES = [
        400 => '请求无效',
        401 => '未授权',
        403 => '禁止访问',
        404 => '资源未找到',
        405 => '请求方法不允许',
        422 => '请求参数无效',
        429 => '请求过于频繁',
    ];

    public function renderHttpException(HttpException $e): Response
    {
        $statusCode = $e->getHttpStatusCode();
        $message = $this->debug ? $e->getMessage() : (self::SAFE_MESSAGES[$statusCode] ?? '请求处理失败');

        if ($this->currentRequest !== null && $this->shouldReturnJson($this->currentRequest)) {
            return Response::json([
                'error' => [
                    'code' => $statusCode,
                    'message' => $message,
                ],
            ], $statusCode);
        }

        $content = $this->debug
            ? $this->buildDebugHtml($statusCode, $e->getMessage(), $e)
            : $this->buildProductionHtml($statusCode, $message);

        return new Response($content, $statusCode);
    }

    /**
     * 渲染通用异常
     * 
     * 调试模式下显示详细错误信息（文件、行号、堆栈），
     * 生产模式下仅显示 500 服务器错误。
     * 
     * @param \Throwable $e 待渲染的异常
     * @return \core\Response HTTP 响应
     */
    public function renderException(\Throwable $e): Response
    {
        if ($this->currentRequest !== null && $this->shouldReturnJson($this->currentRequest)) {
            $data = [
                'error' => [
                    'code' => 500,
                    'message' => $this->debug ? $e->getMessage() : '服务器内部错误',
                ],
            ];

            if ($this->debug) {
                $data['error']['exception'] = get_class($e);
                $data['error']['file'] = $e->getFile();
                $data['error']['line'] = $e->getLine();
                $data['error']['trace'] = explode("\n", $e->getTraceAsString());
            }

            return Response::json($data, 500);
        }

        if ($this->debug) {
            $content = $this->buildDebugHtml(500, $e->getMessage(), $e);
        } else {
            $content = $this->buildProductionHtml(500, '服务器内部错误');
        }

        return new Response($content, 500);
    }

    /**
     * 判断是否应返回 JSON 响应
     * 
     * 根据 AJAX 请求头或 Accept 头判断客户端是否期望 JSON 格式响应。
     * 
     * @param \core\Request $request 当前请求实例
     * @return bool 是否返回 JSON
     */
    public function shouldReturnJson(Request $request): bool
    {
        if ($request->isAjax()) {
            return true;
        }

        $accept = $request->header('Accept') ?? '';
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }

        return false;
    }

    /**
     * 构建调试模式 HTML 错误页面
     * 
     * @param int $statusCode HTTP 状态码
     * @param string $message 错误消息
     * @param \Throwable $e 异常实例
     * @return string HTML 内容
     */
    private function buildDebugHtml(int $statusCode, string $message, \Throwable $e): string
    {
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $escapedFile = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $escapedTrace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        $exceptionClass = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - 调试错误</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 960px; margin: 0 auto; }
        .header { background: #dc3545; color: #fff; padding: 20px; border-radius: 6px 6px 0 0; }
        .header h1 { margin: 0 0 5px; font-size: 24px; }
        .header p { margin: 0; opacity: 0.9; }
        .body { background: #fff; padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; }
        .exception-class { font-family: monospace; font-size: 14px; color: #dc3545; margin-bottom: 10px; }
        .location { font-family: monospace; font-size: 13px; color: #666; margin-bottom: 20px; }
        .trace { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
        .trace pre { margin: 0; font-size: 12px; line-height: 1.6; white-space: pre-wrap; word-break: break-all; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$statusCode}</h1>
            <p>{$escapedMessage}</p>
        </div>
        <div class="body">
            <div class="exception-class">{$exceptionClass}</div>
            <div class="location">在 {$escapedFile} 第 {$e->getLine()} 行</div>
            <div class="trace"><pre>{$escapedTrace}</pre></div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * 构建生产模式 HTML 错误页面
     * 
     * @param int $statusCode HTTP 状态码
     * @param string $message 错误消息
     * @return string HTML 内容
     */
    private function buildProductionHtml(int $statusCode, string $message): string
    {
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { text-align: center; padding: 40px; }
        .status-code { font-size: 72px; font-weight: 700; color: #dc3545; line-height: 1; }
        .message { font-size: 18px; color: #666; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="status-code">{$statusCode}</div>
        <div class="message">{$escapedMessage}</div>
    </div>
</body>
</html>
HTML;
    }
}
