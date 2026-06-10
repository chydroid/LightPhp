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
        'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'pl', 'py',
        'sh', 'bash', 'bat', 'cmd', 'ps1',
    ];

    public static function file(string $name): ?self
    {
        if (!isset($_FILES[$name]) || $_FILES[$name]['error'] === UPLOAD_ERR_NO_FILE) {
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

        // 始终拒绝危险扩展名（无论 allowedExtensions 配置）
        $ext = strtolower($this->getExtension());
        if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            $this->error = 'Dangerous file extension not allowed';
            return false;
        }

        if ($this->maxSize > 0 && $this->file['size'] > $this->maxSize) {
            $this->error = 'File size exceeds maximum allowed size';
            return false;
        }

        return true;
    }

    public function save(?string $path = null): ?string
    {
        if (!$this->validate()) {
            return null;
        }

        $path = $this->sanitizePath($path ?? $this->uploadPath ?: '/uploads/');

        $fullPath = PUBLIC_PATH . $path;

        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $realBase = realpath(PUBLIC_PATH);
        $resolvedPath = realpath($fullPath);

        if ($realBase === false || $resolvedPath === false || !str_starts_with($resolvedPath, $realBase)) {
            $this->error = 'Invalid upload path';
            return null;
        }

        $extension = strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
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
        return pathinfo($this->file['name'] ?? '', PATHINFO_EXTENSION);
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
