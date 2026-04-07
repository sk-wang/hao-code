<?php

namespace App\Support\Config;

/**
 * Path resolution replacing Laravel's storage_path(), base_path(), resource_path().
 */
class PathHelper
{
    private static string $packageRoot = '';

    private static ?string $storagePath = null;

    public static function init(string $packageRoot, ?string $storagePath = null): void
    {
        self::$packageRoot = rtrim($packageRoot, '/');
        self::$storagePath = $storagePath ? rtrim($storagePath, '/') : null;
    }

    public static function basePath(string $append = ''): string
    {
        $base = self::$packageRoot ?: getcwd();

        return $append !== '' ? $base . '/' . ltrim($append, '/') : $base;
    }

    public static function storagePath(string $append = ''): string
    {
        // Fall back to Laravel's storage_path() during coexistence
        if (self::$packageRoot === '' && function_exists('storage_path')) {
            return storage_path($append);
        }

        $storage = self::$storagePath ?? self::basePath('storage');

        if (!is_dir($storage)) {
            @mkdir($storage, 0755, true);
        }

        return $append !== '' ? $storage . '/' . ltrim($append, '/') : $storage;
    }

    public static function resourcePath(string $append = ''): string
    {
        // Fall back to Laravel's resource_path() during coexistence
        if (self::$packageRoot === '' && function_exists('resource_path')) {
            return resource_path($append);
        }

        return self::basePath('resources' . ($append !== '' ? '/' . ltrim($append, '/') : ''));
    }

    public static function getPackageRoot(): string
    {
        return self::$packageRoot;
    }
}
