<?php

namespace Tests\Unit;

use App\Tools\Glob\GlobTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class GlobToolTest extends TestCase
{
    private GlobTool $tool;
    private string $tmpDir;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new GlobTool;
        $this->tmpDir = sys_get_temp_dir() . '/glob_test_' . getmypid();
        mkdir($this->tmpDir, 0755, true);
        $this->context = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'test',
        );
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $item) {
            is_dir($item) ? $this->rmdirRecursive($item) : unlink($item);
        }
        @rmdir($dir);
    }

    private function touch(string $relative, string $content = ''): void
    {
        $full = $this->tmpDir . '/' . $relative;
        $dir = dirname($full);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($full, $content);
    }

    private function call(array $input): \App\Tools\ToolResult
    {
        return $this->tool->call($input, $this->context);
    }

    // ─── non-existent directory ───────────────────────────────────────────

    public function test_it_returns_error_for_nonexistent_directory(): void
    {
        $result = $this->call([
            'pattern' => '*.php',
            'path' => '/tmp/no_such_dir_haocode_test',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->output);
    }

    // ─── basic patterns ───────────────────────────────────────────────────

    public function test_it_matches_files_by_extension(): void
    {
        $this->touch('foo.php', '<?php');
        $this->touch('bar.js', 'console.log()');

        $result = $this->call(['pattern' => '*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('foo.php', $result->output);
        $this->assertStringNotContainsString('bar.js', $result->output);
    }

    public function test_it_returns_no_match_message_when_nothing_matches(): void
    {
        $this->touch('foo.txt');

        $result = $this->call(['pattern' => '*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No files matched', $result->output);
    }

    public function test_it_matches_files_in_subdirectories_with_double_star(): void
    {
        $this->touch('app/Services/Foo.php', '<?php');
        $this->touch('app/Controllers/Bar.php', '<?php');
        $this->touch('config/app.php', '<?php');

        $result = $this->call(['pattern' => '**/*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Foo.php', $result->output);
        $this->assertStringContainsString('Bar.php', $result->output);
        $this->assertStringContainsString('app.php', $result->output);
    }

    public function test_it_limits_matches_to_specific_subdirectory_with_path(): void
    {
        $this->touch('src/a.php', '<?php');
        $this->touch('lib/b.php', '<?php');

        $result = $this->call([
            'pattern' => '*.php',
            'path' => $this->tmpDir . '/src',
        ]);

        $this->assertStringContainsString('a.php', $result->output);
        $this->assertStringNotContainsString('b.php', $result->output);
    }

    public function test_it_matches_single_character_wildcard(): void
    {
        $this->touch('foo1.txt');
        $this->touch('foo2.txt');
        $this->touch('foobar.txt');

        $result = $this->call(['pattern' => 'foo?.txt']);

        $this->assertStringContainsString('foo1.txt', $result->output);
        $this->assertStringContainsString('foo2.txt', $result->output);
        $this->assertStringNotContainsString('foobar.txt', $result->output);
    }

    public function test_it_counts_matches_correctly(): void
    {
        $this->touch('a.php', '<?php');
        $this->touch('b.php', '<?php');
        $this->touch('c.php', '<?php');

        $result = $this->call(['pattern' => '*.php']);

        $this->assertStringContainsString('3 file(s)', $result->output);
    }

    public function test_it_uses_working_directory_as_default_path(): void
    {
        $this->touch('default.txt');

        $result = $this->call(['pattern' => '*.txt']);

        $this->assertStringContainsString('default.txt', $result->output);
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    // ─── globToRegex (via reflection) ─────────────────────────────────────

    private function globToRegex(string $pattern): string
    {
        $ref = new \ReflectionClass(GlobTool::class);
        $method = $ref->getMethod('globToRegex');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $pattern);
    }

    public function test_glob_to_regex_converts_star_to_non_slash_match(): void
    {
        $regex = $this->globToRegex('*.php');
        $this->assertMatchesRegularExpression($regex, 'foo.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'foo/bar.php');
    }

    public function test_glob_to_regex_converts_double_star_to_any(): void
    {
        $regex = $this->globToRegex('**/*.php');
        // ** matches one-or-more path segments
        $this->assertMatchesRegularExpression($regex, 'src/Controllers/FooController.php');
        $this->assertMatchesRegularExpression($regex, 'a/b.php');
        // Root-level files don't match **/*.php (use *.php instead)
        $this->assertDoesNotMatchRegularExpression($regex, 'foo.php');
    }

    public function test_glob_to_regex_converts_question_mark(): void
    {
        $regex = $this->globToRegex('foo?.txt');
        $this->assertMatchesRegularExpression($regex, 'fooa.txt');
        $this->assertDoesNotMatchRegularExpression($regex, 'fooab.txt');
    }
}
