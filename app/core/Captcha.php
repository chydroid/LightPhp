<?php
declare(strict_types=1);

namespace core;

class Captcha
{
    private static int $width = 120;
    private static int $height = 40;
    private static int $length = 4;
    private static string $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private static string $key = '_captcha_code';

    public static function generate(): array
    {
        $code = '';
        $chars = self::$chars;
        $max = strlen($chars) - 1;

        for ($i = 0; $i < self::$length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        Session::set(self::$key, strtolower($code));
        Session::set(self::$key . '_time', time());

        $image = self::createImage($code);

        return ['image' => $image];
    }

    private static function hasGD(): bool
    {
        return function_exists('imagecreate') && function_exists('imagepng');
    }

    public static function verify(string $input, ?string $sessionCode = null): bool
    {
        $code = $sessionCode ?? Session::get(self::$key, '');
        $result = hash_equals($code, strtolower($input));

        if ($result && $sessionCode === null) {
            self::clear();
        }

        return $result;
    }

    public static function width(int $width): void
    {
        self::$width = $width;
    }

    public static function height(int $height): void
    {
        self::$height = $height;
    }

    public static function length(int $length): void
    {
        self::$length = $length;
    }

    public static function chars(string $chars): void
    {
        self::$chars = $chars;
    }

    private static function createImage(string $code): string
    {
        if (!self::hasGD()) {
            throw new \RuntimeException('GD library is required for captcha generation. Please install php-gd extension.');
        }

        $width = self::$width;
        $height = self::$height;

        $image = imagecreate($width, $height);

        $bgColor = imagecolorallocate($image, 255, 255, 255);
        $textColor = imagecolorallocate($image, 0, 0, 0);
        $lineColor = imagecolorallocate($image, 200, 200, 200);

        imagefill($image, 0, 0, $bgColor);

        for ($i = 0; $i < 5; $i++) {
            imageline($image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $lineColor
            );
        }

        $fontSize = $height / 1.5;
        $x = ($width - ($fontSize * self::$length * 0.6)) / 2;
        $y = ($height - $fontSize) / 2 + $fontSize * 0.8;

        $fontPath = self::getFontPath();

        for ($i = 0; $i < strlen($code); $i++) {
            $angle = random_int(-15, 15);
            if ($fontPath) {
                imagettftext($image, $fontSize, $angle, (int)$x, (int)$y, $textColor, $fontPath, $code[$i]);
            } else {
                imagestring($image, (int)($fontSize / 4), (int)$x, (int)($y - $fontSize), $code[$i], $textColor);
            }
            $x += $fontSize * 0.8;
        }

        for ($i = 0; $i < 30; $i++) {
            imagesetpixel($image, random_int(0, $width), random_int(0, $height), $lineColor);
        }

        if (ob_start() === false) {
            imagedestroy($image);
            return '';
        }
        imagepng($image);
        $data = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($data);
    }

    private static function getFontPath(): string
    {
        $fonts = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/verdana.ttf',
        ];

        foreach ($fonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return '';
    }

    public static function clear(): void
    {
        Session::delete(self::$key);
        Session::delete(self::$key . '_time');
    }
}
