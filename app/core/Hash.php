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
        $key = $key ?? self::getKey();
        $ivLength = openssl_cipher_iv_length('aes-256-gcm');
        $iv = random_bytes($ivLength);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(string $encrypted, ?string $key = null): ?string
    {
        $key = $key ?? self::getKey();
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length('aes-256-gcm');
        $tagLength = 16;

        if (strlen($decoded) < $ivLength + $tagLength) {
            return null;
        }

        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, $tagLength);
        $ciphertext = substr($decoded, $ivLength + $tagLength);

        $result = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
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
}
