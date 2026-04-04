<?php

namespace Tests\Unit;

use App\Support\Terminal\Autocomplete\FilePathSuggestionProvider;
use PHPUnit\Framework\TestCase;

class FilePathSuggestionProviderTest extends TestCase
{
    private FilePathSuggestionProvider $provider;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->provider = new FilePathSuggestionProvider();
        $this->tmpDir = sys_get_temp_dir() . '/path_suggest_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->tmpDir . '/subdir', 0755, true);
        file_put_contents($this->tmpDir . '/file1.txt', 'content');
        file_put_contents($this->tmpDir . '/file2.php', '<?php');
        file_put_contents($this->tmpDir . '/another.md', '# Title');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function test_suggest_returns_entries_for_directory(): void
    {
        $results = $this->provider->suggest('', $this->tmpDir);
        $this->assertNotEmpty($results);

        $names = array_column($results, 'name');
        $this->assertContains('file1.txt', $names);
        $this->assertContains('file2.php', $names);
        $this->assertContains('subdir/', $names);
    }

    public function test_suggest_filters_by_prefix(): void
    {
        $results = $this->provider->suggest('file', $this->tmpDir);
        $names = array_column($results, 'name');
        $this->assertContains('file1.txt', $names);
        $this->assertContains('file2.php', $names);
        $this->assertNotContains('another.md', $names);
    }

    public function test_suggest_shows_directories_first(): void
    {
        $results = $this->provider->suggest('', $this->tmpDir);
        // First result should be the directory
        $this->assertSame('directory', $results[0]['type']);
    }

    public function test_suggest_adds_slash_to_directories(): void
    {
        $results = $this->provider->suggest('', $this->tmpDir);
        $dirs = array_filter($results, fn($r) => $r['type'] === 'directory');
        foreach ($dirs as $dir) {
            $this->assertStringEndsWith('/', $dir['name']);
        }
    }

    public function test_suggest_skips_hidden_files(): void
    {
        file_put_contents($this->tmpDir . '/.hidden', 'secret');
        $results = $this->provider->suggest('', $this->tmpDir);
        $names = array_column($results, 'name');
        $this->assertNotContains('.hidden', $names);
        @unlink($this->tmpDir . '/.hidden');
    }

    public function test_suggest_handles_nonexistent_directory(): void
    {
        $results = $this->provider->suggest('/nonexistent/path');
        $this->assertEmpty($results);
    }

    public function test_is_path_like_detects_relative_path(): void
    {
        $this->assertTrue($this->provider->isPathLike('./foo'));
        $this->assertTrue($this->provider->isPathLike('../bar'));
        $this->assertTrue($this->provider->isPathLike('/absolute'));
        $this->assertTrue($this->provider->isPathLike('~/home'));
        $this->assertFalse($this->provider->isPathLike('regular_word'));
    }

    public function test_suggest_limits_results(): void
    {
        // Create many files
        for ($i = 0; $i < 20; $i++) {
            file_put_contents($this->tmpDir . "/extra_{$i}.txt", 'x');
        }

        $results = $this->provider->suggest('', $this->tmpDir);
        $this->assertLessThanOrEqual(8, count($results));

        // Clean up
        for ($i = 0; $i < 20; $i++) {
            @unlink($this->tmpDir . "/extra_{$i}.txt");
        }
    }

    public function test_suggest_handles_path_with_directory(): void
    {
        $results = $this->provider->suggest('subdir/', $this->tmpDir);
        $this->assertIsArray($results);
    }

    public function test_suggest_returns_empty_for_nonexistent_directory(): void
    {
        $results = $this->provider->suggest('', '/this/does/not/exist');
        $this->assertEmpty($results);
    }

    public function test_is_path_like_detects_various_path_patterns(): void
    {
        $this->assertTrue($this->provider->isPathLike('./foo'));
        $this->assertTrue($this->provider->isPathLike('../bar'));
        $this->assertTrue($this->provider->isPathLike('/absolute'));
        $this->assertTrue($this->provider->isPathLike('~/home'));
        $this->assertFalse($this->provider->isPathLike('hello'));
        $this->assertFalse($this->provider->isPathLike('@file'));
        $this->assertTrue($this->provider->isPathLike('/usr/bin/php'));
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
