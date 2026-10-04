<?php
declare(strict_types=1);

namespace core;

class Upload
{
    private ?array $file = null;
    private string $error = '';
    private array $allowedTypes = [];
    private array $allowedExtensions = [];
    private int $maxSize = 0;
    private string $uploadPath = '';

    public function __construct(array $file)
    {
        $this->file = $file;
    }

    /** @var string[] 危险扩展名黑名单（即使未配置 allowedExtensions 也始终拒绝） */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar',
        'pht', 'phps', 'shtml', 'htaccess', 'htpasswd',
        // .user.ini 在 PHP-FPM/CGI 下可设置 auto_prepend_file 等，
        // 上传后被 web 服务器解析即等同于远程代码执行
        'user', 'ini',
        'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'pl', 'py',
        'sh', 'bash', 'bat', 'cmd', 'ps1',
        // 可承载脚本/XSS 的内容型扩展名，上传到 PUBLIC_PATH 会被浏览器执行
        'html', 'htm', 'xhtml', 'svg', 'xml', 'swf',
    ];

    /** @var string[] 必须整体按文件名匹配（无点分隔），否则按扩展名拆分检查会漏掉 */
    private const DANGEROUS_FILENAMES = [
        '.htaccess', '.htpasswd', '.user.ini', '.env', 'web.config',
    ];

    /**
     * 检查文件名（含多段扩展名）是否命中危险名单
     *
     * @param string $filename 原始文件名
     * @return bool 是否危险
     */
    private static function isDangerousFilename(string $filename): bool
    {
        $lower = strtolower(basename(trim($filename)));
        if ($lower === '') {
            return false;
        }

        // 整体匹配（.htaccess / .user.ini 等以点开头的特殊文件）
        if (in_array($lower, self::DANGEROUS_FILENAMES, true)) {
            return true;
        }

        // 多段扩展名检查：a.php.jpg 的每一段都不得命中危险名单（防双扩展名绕过）
        $parts = explode('.', $lower);
        if (count($parts) > 1) {
            array_shift($parts); // 去掉主文件名
            foreach ($parts as $ext) {
                if (in_array(strtolower($ext), self::DANGEROUS_EXTENSIONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function file(string $name): ?self
    {
        if (!isset($_FILES[$name]) || !is_array($_FILES[$name])) {
            return null;
        }

        // 多文件字段（name 为数组）不能当作单个文件处理：
        // 直接取 ['name'] 会拿到数组，is_uploaded_file(array) 抛 TypeError。
        // 此时返回 null，由 files() 处理。
        if (is_array($_FILES[$name]['name'] ?? null)) {
            return null;
        }

        if (($_FILES[$name]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return new self($_FILES[$name]);
    }

    public static function files(string $name): array
    {
        if (!isset($_FILES[$name])) {
            return [];
        }

        // 处理单文件（name 为字符串）和多文件（name 为数组）两种情况
        if (!is_array($_FILES[$name]['name'])) {
            if ($_FILES[$name]['error'] === UPLOAD_ERR_OK) {
                return [new self($_FILES[$name])];
            }
            return [];
        }

        $files = [];
        $count = count($_FILES[$name]['name']);

        for ($i = 0; $i < $count; $i++) {
            if ($_FILES[$name]['error'][$i] === UPLOAD_ERR_OK) {
                $files[] = new self([
                    'name' => $_FILES[$name]['name'][$i],
                    'type' => $_FILES[$name]['type'][$i],
                    'tmp_name' => $_FILES[$name]['tmp_name'][$i],
                    'error' => $_FILES[$name]['error'][$i],
                    'size' => $_FILES[$name]['size'][$i],
                ]);
            }
        }

        return $files;
    }

    public function allowedTypes(array $types): self
    {
        $this->allowedTypes = $types;
        return $this;
    }

    public function allowedExtensions(array $extensions): self
    {
        $this->allowedExtensions = array_map('strtolower', $extensions);
        return $this;
    }

    public function maxSize(int $size): self
    {
        $this->maxSize = $size;
        return $this;
    }

    public function path(string $path): self
    {
        $this->uploadPath = rtrim($path, '/') . '/';
        return $this;
    }

    public function validate(): bool
    {
        if ($this->file === null) {
            $this->error = 'No file uploaded';
            return false;
        }

        if ($this->file['error'] !== UPLOAD_ERR_OK) {
            $this->error = $this->getErrorMessage($this->file['error']);
            return false;
        }

        if (!is_uploaded_file($this->file['tmp_name'])) {
            $this->error = 'Invalid upload';
            return false;
        }

        if ($this->file['size'] === 0) {
            $this->error = 'Empty file uploaded';
            return false;
        }

        $realMimeType = $this->getRealMimeType();
        if (!empty($this->allowedTypes) && !in_array($realMimeType, $this->allowedTypes, true)) {
            $this->error = 'File type not allowed';
            return false;
        }

        if (!empty($this->allowedExtensions)) {
            $ext = strtolower($this->getExtension());
            if (!in_array($ext, $this->allowedExtensions, true)) {
                $this->error = 'File extension not allowed';
                return false;
            }
        }

        // 始终拒绝危险文件名/扩展名（无论 allowedExtensions 配置）
        if (self::isDangerousFilename($this->file['name'] ?? '')) {
            $this->error = 'Dangerous file extension not allowed';
            return false;
        }

        if ($this->maxSize > 0 && $this->file['size'] > $this->maxSize) {
            $this->error = 'File size exceeds maximum allowed size';
            return false;
        }

        return true;
    }

    /**
     * 落盘根目录
     *
     * 默认 PUBLIC_PATH（保持历史行为，兼容性优先）。
     * 需要把用户上传存到 web 根之外时，用 disk('local') 或 root() 显式指定。
     *
     * @var string
     */
    private string $root = '';

    /**
     * 设置落盘根目录（绝对路径）
     *
     * 传入 STORAGE_PATH.'app/' 等私有目录即可避免上传文件被直接 HTTP 访问。
     *
     * @param string $root 绝对路径根目录
     * @return self
     */
    public function root(string $root): self
    {
        $this->root = rtrim($root, '/\\');
        return $this;
    }

    /**
     * 使用 storage.php 中配置的磁盘作为落盘位置
     *
     * 例：$upload->disk('local')->save('avatars/');   // 私有，不对外暴露
     *     $upload->disk('public')->save('avatars/');  // 公开，可通过 URL 访问
     *
     * @param string $disk 磁盘名（storage.php 中的 disks 键）
     * @return self
     */
    public function disk(string $disk): self
    {
        $config = \core\Application::getInstance()?->getConfig("storage.disks.{$disk}");
        $root = is_array($config) ? ($config['root'] ?? null) : null;
        if (!is_string($root) || $root === '') {
            throw new \InvalidArgumentException(
                "Upload disk [{$disk}] is not configured in storage.php or has no root."
            );
        }
        return $this->root($root);
    }

    public function save(?string $path = null): ?string
    {
        if (!$this->validate()) {
            return null;
        }

        // 落盘根目录：显式 root()/disk() 优先，否则回退 PUBLIC_PATH
        $root = $this->root !== '' ? $this->root : (defined('PUBLIC_PATH') ? PUBLIC_PATH : '');
        if ($root === '') {
            $this->error = 'Upload root directory is not configured';
            return null;
        }

        $path = $this->sanitizePath($path ?? ($this->uploadPath ?: '/uploads/'));
        $fullPath = $root . $path;

        if (!is_dir($fullPath)) {
            if (!mkdir($fullPath, 0755, true) && !is_dir($fullPath)) {
                $this->error = 'Failed to create upload directory';
                return null;
            }
        }

        $realBase = realpath($root);
        $resolvedPath = realpath($fullPath);

        if ($realBase === false || $resolvedPath === false || ($resolvedPath !== $realBase && !str_starts_with($resolvedPath, $realBase . DIRECTORY_SEPARATOR))) {
            $this->error = 'Invalid upload path';
            return null;
        }

        $extension = strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
        // 即使 validate() 已检查，save() 中也再次确认扩展名不在危险列表中（防御性编程）
        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            $extension = '';
        }
        $filename = bin2hex(random_bytes(16));
        if ($extension !== '') {
            $filename .= '.' . $extension;
        }
        $destination = $resolvedPath . DIRECTORY_SEPARATOR . $filename;

        if (move_uploaded_file($this->file['tmp_name'], $destination)) {
            return $path . $filename;
        }

        $this->error = 'Failed to move uploaded file';
        return null;
    }

    private function sanitizePath(string $path): string
    {
        $path = str_replace(['\\', '..'], ['/', ''], $path);
        $path = '/' . trim($path, '/') . '/';
        return preg_replace('#/+#', '/', $path);
    }

    private function getRealMimeType(): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                return 'application/octet-stream';
            }
            try {
                $mimeType = finfo_file($finfo, $this->file['tmp_name']);
            } finally {
                finfo_close($finfo);
            }
            return $mimeType ?: 'application/octet-stream';
        }

        return 'application/octet-stream';
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getClientName(): string
    {
        return $this->file['name'] ?? '';
    }

    public function getSize(): int
    {
        return $this->file['size'] ?? 0;
    }

    public function getType(): string
    {
        return $this->getRealMimeType();
    }

    public function getExtension(): string
    {
        $filename = $this->file['name'] ?? '';
        // 检查所有扩展名，拒绝包含危险扩展名的文件（防止双扩展名绕过）
        $parts = explode('.', $filename);
        if (count($parts) > 1) {
            array_shift($parts); // 移除主文件名部分
            foreach ($parts as $ext) {
                if (in_array(strtolower($ext), self::DANGEROUS_EXTENSIONS, true)) {
                    return ''; // 返回空扩展名，使验证失败
                }
            }
        }
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    private function getErrorMessage(int $errorCode): string
    {
        return match($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error'
        };
    }
}
