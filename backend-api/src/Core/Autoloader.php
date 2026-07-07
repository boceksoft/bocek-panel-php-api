<?php

declare(strict_types=1);

namespace App\Core;

/*
 * Composer'sız basit PSR-4 autoloader.
 * "App\" ön ekini src/ dizinine eşler.
 */
final class Autoloader
{
    public static function register(string $baseDir): void
    {
        $baseDir = rtrim($baseDir, '/');

        spl_autoload_register(static function (string $class) use ($baseDir): void {
            $prefix = 'App\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
    }
}
