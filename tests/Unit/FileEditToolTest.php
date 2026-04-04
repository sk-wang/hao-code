<?php

namespace Tests\Unit;

use App\Tools\FileEdit\FileEditTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class FileEditToolTest extends TestCase
{
    private FileEditTool $tool;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new FileEditTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    private function makeTmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'edit_test_');
        file_put_contents($path, $content);
        // Record file as read so edit read-before-write enforcement passes
        $this->context->recordFileRead($path);
        return $path;
    }

    // ─── Basic edits ───────────────────────────────────────────────────────

    public function test_it_replaces_old_string_with_new_string(): void
    {
        $file = $this->makeTmpFile("hello world\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'hello',
            'new_string' => 'goodbye',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertSame("goodbye world\n", file_get_contents($file));

        unlink($file);
    }

    public function test_it_returns_error_when_file_does_not_exist(): void
    {
        $result = $this->tool->call([
            'file_path' => '/tmp/definitely_nonexistent_haocode.txt',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->output);
    }

    public function test_it_returns_error_when_old_string_not_found(): void
    {
        $file = $this->makeTmpFile("line one\nline two\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'line three',
            'new_string' => 'replaced',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);

        unlink($file);
    }

    public function test_it_returns_error_when_old_string_not_unique(): void
    {
        $file = $this->makeTmpFile("foo\nfoo\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'foo',
            'new_string' => 'bar',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not unique', $result->output);
        $this->assertStringContainsString('2', $result->output); // reports count
        // File must NOT be changed
        $this->assertSame("foo\nfoo\n", file_get_contents($file));

        unlink($file);
    }

    public function test_it_returns_error_when_old_and_new_strings_are_identical(): void
    {
        $file = $this->makeTmpFile("same content\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'same content',
            'new_string' => 'same content',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('identical', $result->output);

        unlink($file);
    }

    public function test_replace_all_replaces_every_occurrence(): void
    {
        $file = $this->makeTmpFile("foo bar foo baz foo\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'foo',
            'new_string' => 'qux',
            'replace_all' => true,
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertSame("qux bar qux baz qux\n", file_get_contents($file));

        unlink($file);
    }

    public function test_replace_all_false_replaces_only_first_occurrence(): void
    {
        // Use a string that appears exactly once (uniqueness check enforced when replace_all=false)
        $file = $this->makeTmpFile("alpha beta gamma\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => 'alpha',
            'new_string' => 'ALPHA',
            'replace_all' => false,
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertSame("ALPHA beta gamma\n", file_get_contents($file));

        unlink($file);
    }

    // ─── Multi-line edits ──────────────────────────────────────────────────

    public function test_it_replaces_multiline_old_string(): void
    {
        $content = "function foo() {\n    return 1;\n}\n";
        $file = $this->makeTmpFile($content);

        $result = $this->tool->call([
            'file_path' => $file,
            'old_string' => "return 1;\n}",
            'new_string' => "return 2;\n}",
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('return 2;', file_get_contents($file));

        unlink($file);
    }

    // ─── validateInput (sensitive file blocking) ───────────────────────────

    public function test_validate_input_blocks_env_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/var/www/.env',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNotNull($error);
        $this->assertStringContainsString('sensitive', $error);
    }

    public function test_validate_input_blocks_pem_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/home/user/server.pem',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNotNull($error);
    }

    public function test_validate_input_blocks_key_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/home/user/id_rsa',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNotNull($error);
    }

    public function test_validate_input_allows_regular_php_files(): void
    {
        $error = $this->tool->validateInput([
            'file_path' => '/var/www/app/Controllers/UserController.php',
            'old_string' => 'x',
            'new_string' => 'y',
        ], $this->context);

        $this->assertNull($error);
    }
}
