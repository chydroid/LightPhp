<?php
declare(strict_types=1);

namespace core;

class Hash
{
    public static function make(string $value, int $cost = 12): string
    {
        return password_hash($value, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public static function verify(string $value, string $hashedValue): bool
    {
        return password_verify($value, $hashedValue);
    }

    public static function needsRehash(string $hashedValue, int $cost = 12): bool
    {
        return password_needs_rehash($hashedValue, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public static function encrypt(string $value, ?string $key = null): string
    {
        if (!\function_exists('openssl_encrypt')) {
            throw new \RuntimeException('openssl extension is required for encryption');
        }
        $key = $key ?? self::getKey();
        $ivLength = \openssl_cipher_iv_length('aes-256-gcm');
        $iv = \random_bytes($ivLength);
        $tag = '';
        $encrypted = \openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return \base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(string $encrypted, ?string $key = null): ?string
    {
        if (!\function_exists('openssl_decrypt')) {
            throw new \RuntimeException('openssl extension is required for decryption');
        }
        $key = $key ?? self::getKey();
        $decoded = \base64_decode($encrypted, true);
        if ($decoded === false) {
            return null;
        }

        $ivLength = \openssl_cipher_iv_length('aes-256-gcm');
        $tagLength = 16;

        if (\strlen($decoded) < $ivLength + $tagLength) {
            return null;
        }

        $iv = \substr($decoded, 0, $ivLength);
        $tag = \substr($decoded, $ivLength, $tagLength);
        $ciphertext = \substr($decoded, $ivLength + $tagLength);

        $result = \openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $result !== false ? $result : null;
    }

    private static ?string $appKey = null;

    public static function setApplicationKey(string $key): void
    {
        self::$appKey = $key;
    }

    private static function getKey(): string
    {
        if (self::$appKey !== null) {
            return substr(hash('sha256', self::$appKey, true), 0, 32);
        }

        $key = Env::get('APP_KEY', '');
        if (empty($key)) {
            throw new \RuntimeException('APP_KEY is not set. Set it in app/config/app.php [key] or .env file.');
        }
        return substr(hash('sha256', $key, true), 0, 32);
    }

    /**
     * 生成一个安全的随机令牌
     *
     * @param int $length 令牌长度（字节数，实际输出为 2 倍 hex 长度）
     * @return string 十六进制令牌字符串
     */
    public static function makeToken(int $length = 32): string
    {
        return \bin2hex(\random_bytes($length));
    }

    /**
     * 生成一个安全的随机密钥
     *
     * @param int $length 密钥长度（字节数）
     * @return string Base64 编码的密钥
     */
    public static function makeKey(int $length = 32): string
    {
        return \base64_encode(\random_bytes($length));
    }
}
