<?php

namespace Tests\Feature;

use App\Console\Commands\HaoCodeCommand;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class HaoCodeInitFeatureTest extends TestCase
{
    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($target);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($target, ...$args);
    }

    private function withWorkingDirectory(string $directory, callable $callback): mixed
    {
        $original = getcwd();
        chdir($directory);

        try {
            return $callback();
        } finally {
            if ($original !== false) {
                chdir($original);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function makeCommand(BufferedOutput $buffer): HaoCodeCommand
    {
        $command = new HaoCodeCommand;
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        return $command;
    }

    public function test_init_generates_full_stack_haocode_instructions_for_split_project(): void
    {
        $root = sys_get_temp_dir() . '/haocode-init-fullstack-' . bin2hex(random_bytes(4));
        mkdir($root . '/backend', 0755, true);
        mkdir($root . '/frontend', 0755, true);

        file_put_contents($root . '/backend/artisan', "#!/usr/bin/env php\n");
        file_put_contents($root . '/backend/composer.json', json_encode([
            'require' => ['laravel/framework' => '^12.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/frontend/package.json', json_encode([
            'dependencies' => ['react' => '^19.0.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/frontend/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        try {
            $buffer = new BufferedOutput;
            $command = $this->makeCommand($buffer);

            $this->withWorkingDirectory($root, function () use ($command): void {
                $this->invoke($command, 'handleInit', '');
            });

            $haocodeDir = $root . '/.haocode';
            $this->assertDirectoryExists($haocodeDir);
            $this->assertFileExists($haocodeDir . '/settings.json');
            $this->assertDirectoryExists($haocodeDir . '/skills');
            $this->assertDirectoryExists($haocodeDir . '/output-styles');
            $this->assertFileExists($haocodeDir . '/HAOCODE.md');

            $content = (string) file_get_contents($haocodeDir . '/HAOCODE.md');
            $output = $buffer->fetch();

            $this->assertStringContainsString('# Full-stack (Laravel + React/Next.js) Project Instructions', $content);
            $this->assertStringContainsString('- This is a Full-stack (Laravel + React/Next.js) project.', $content);
            $this->assertStringContainsString('- Repository structure: full-stack.', $content);
            $this->assertStringContainsString('- Package manager(s): Composer + pnpm.', $content);
            $this->assertStringContainsString('- Run tests with: `(cd backend && php artisan test) && (cd frontend && pnpm test)`', $content);

            $this->assertStringContainsString('Initialized project at:', $output);
            $this->assertStringContainsString('Detected framework:', $output);
            $this->assertStringContainsString('Full-stack (Laravel + React/Next.js)', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }
}
