<?php

namespace Tests\Unit;

use App\Support\Runtime\StoragePathResolver;
use Tests\TestCase;

class StoragePathResolverTest extends TestCase
{
    public function test_it_returns_null_for_local_source_checkout(): void
    {
        $resolver = new StoragePathResolver;
        $packageRoot = '/tmp/hao-code';

        $this->assertNull($resolver->resolve($packageRoot, $packageRoot.'/vendor/autoload.php'));
    }

    public function test_it_returns_home_storage_path_for_installed_package(): void
    {
        $resolver = new StoragePathResolver;
        $packageRoot = '/tmp/hao-code';
        $autoloadPath = '/tmp/composer-home/vendor/autoload.php';

        $originalHome = $_SERVER['HOME'] ?? getenv('HOME');
        $_SERVER['HOME'] = '/tmp/test-home';
        putenv('HOME=/tmp/test-home');

        try {
            $this->assertSame(
                '/tmp/test-home/.haocode/storage',
                $resolver->resolve($packageRoot, $autoloadPath),
            );
            $this->assertSame(
                '/tmp/test-home/.haocode/bootstrap/cache',
                $resolver->resolveCacheDirectory($packageRoot, $autoloadPath),
            );
        } finally {
            if ($originalHome === false || $originalHome === null) {
                unset($_SERVER['HOME']);
                putenv('HOME');
            } else {
                $_SERVER['HOME'] = $originalHome;
                putenv("HOME={$originalHome}");
            }
        }
    }
}
