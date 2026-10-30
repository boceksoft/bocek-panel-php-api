<?php

declare(strict_types=1);

namespace App\Support;

/*
 * Sunucunun tek dosya yukleme siniri: min(upload_max_filesize, post_max_size) bayt.
 * Frontend fotograflari bu sinirin altina kucultup gonderir (asilinca PHP dosyayi sessizce dusurur).
 */
final class UploadLimits
{
    public static function maxBytes(): int
    {
        $limits = array_filter([
            self::toBytes((string) ini_get('upload_max_filesize')),
            self::toBytes((string) ini_get('post_max_size')),
        ], static function (int $value): bool {
            return $value > 0;
        });

        return $limits ? min($limits) : 0;
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        switch ($unit) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }

        return (int) $number;
    }
}
