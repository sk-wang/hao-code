<?php

namespace Tests\Unit;

use App\Tools\FileWrite\FileWriteTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class FileWriteToolTest extends TestCase
{
    private FileWriteTool $tool;
    private ToolUseContext $context;
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->tool = new FileWriteTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (file_exists($path)) unlink($path);
        }
    }

    private function tmpPath(string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . '/write_test_' . getmypid() . '_' . uniqid() . $suffix;
        $this->createdFiles[] = $path;
        return $path;
    }

    // ─── creating new files ───────────────────────────────────────────────

    public function test_it_creates_a_new_file(): void
    {
        $path = $this->tmpPath('.txt');

        $result = $this->tool->call([
            'file_path' => $path,
            'content' => 'hello world',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertFileExists($path);
        $this->assertSame('hello world', file_get_contents($path));
    }

    public function test_it_reports_created_for_new_file(): void
    {
        $path = $this->tmpPath('.txt');

        $result = $this->tool->call([
            'file_path' => $path,
            'content' => 'new content',
        ], $this->context);

        $this->assertStringContainsString('created', $result->output);
    }

    public function test_it_reports_overwritten_for_existing_file(): void
    {
        $path = $this->tmpPath('.txt');
        file_put_contents($path, 'old content');

        $result = $this->tool->call([
            'file_path' => $path,
            'content' => 'new content',
        ], $this->context);

        $this->assertStringContainsString('overwritten', $result->output);
    }

    public function test_it_overwrites_existing_file_content(): void
    {
        $path = $this->tmpPath('.txt');
        file_put_contents($path, 'old content');

        $this->tool->call([
            'file_path' => $path,
            'content' => 'new content',
        ], $this->context);

        $this->assertSame('new content', file_get_contents($path));
    }

    // ─── directory creation ───────────────────────────────────────────────

    public function test_it_creates_missing_parent_directories(): void
    {
        $dir = sys_get_temp_dir() . '/write_test_nested_' . getmypid() . '/deeply/nested';
        $path = $dir . '/file.txt';
        $this->createdFiles[] = $path;

        $result = $this->tool->call([
            'file_path' => $path,
            'content' => 'deep content',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertFileExists($path);

        // cleanup
        @unlink($path);
        @rmdir($dir);
        @rmdir(dirname($dir));
        @rmdir(sys_get_temp_dir() . '/write_test_nested_' . getmypid());
    }

    // ─── output contains line count and byte count ─────────────────────────

    public function test_it_reports_line_and_byte_counts(): void
    {
        $path = $this->tmpPath('.php');
        $content = "<?php\n\necho 'hello';\n";

        $result = $this->tool->call([
            'file_path' => $path,
            'content' => $content,
        ], $this->context);

        $this->assertStringContainsString('lines', $result->output);
        $this->assertStringContainsString('bytes', $result->output);
    }

    // ─── secret scanning ─────────────────────────────────────────────────

    public function test_it_warns_when_content_contains_an_api_key(): void
    {
        $path = $this->tmpPath('.env');

        // GitHub PAT format
        $result = $this->tool->call([
            'file_path' => $path,
            'content' => "GITHUB_TOKEN=ghp_" . str_repeat('A', 36) . "\n",
        ], $this->context);

        $this->assertFalse($result->isError); // still writes the file
        $this->assertStringContainsString('WARNING', $result->output);
        $this->assertStringContainsString('GitHub', $result->output);
    }

    public function test_it_does_not_warn_for_clean_content(): void
    {
        $path = $this->tmpPath('.php');

        $result = $this->tool->call([
            'file_path' => $path,
            'content' => "<?php\n\necho 'Hello World';\n",
        ], $this->context);

        $this->assertStringNotContainsString('WARNING', $result->output);
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnly([]));
    }
}
