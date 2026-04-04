<?php

namespace App\Support\Runtime;

class StoragePathResolver
{
    public function resolve(string $packageRoot, ?string $autoloadPath): ?string
    {
        if (! $this->isInstalledPackage($packageRoot, $autoloadPath)) {
            return null;
        }

        return $this->homeDirectory().'/.haocode/storage';
    }

    public function resolveCacheDirectory(string $packageRoot, ?string $autoloadPath): ?string
    {
        if (! $this->isInstalledPackage($packageRoot, $autoloadPath)) {
            return null;
        }

        return $this->homeDirectory().'/.haocode/bootstrap/cache';
    }

    private function isInstalledPackage(string $packageRoot, ?string $autoloadPath): bool
    {
        $localAutoload = $packageRoot.'/vendor/autoload.php';

        return is_string($autoloadPath) && $autoloadPath !== '' && $autoloadPath !== $localAutoload;
    }

    private function homeDirectory(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return rtrim($home, '/');
    }
}
