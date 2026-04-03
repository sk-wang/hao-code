<?php

namespace Tests\Unit;

use App\Tools\FileRead\FileReadTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class FileReadToolTest extends TestCase
{
    private FileReadTool $tool;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new FileReadTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    private function makeTmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'read_test_');
        file_put_contents($path, $content);
        return $path;
    }

    // ─── Basic reads ───────────────────────────────────────────────────────

    public function test_it_reads_a_text_file(): void
    {
        $file = $this->makeTmpFile("line one\nline two\nline three\n");

        $result = $this->tool->call(['file_path' => $file], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('line one', $result->output);
        $this->assertStringContainsString('line two', $result->output);

        unlink($file);
    }

    public function test_it_includes_line_numbers_in_output(): void
    {
        $file = $this->makeTmpFile("alpha\nbeta\n");

        $result = $this->tool->call(['file_path' => $file], $this->context);

        $this->assertStringContainsString('1', $result->output);
        $this->assertStringContainsString('2', $result->output);

        unlink($file);
    }

    public function test_it_returns_error_for_nonexistent_file(): void
    {
        $result = $this->tool->call(['file_path' => '/tmp/no_such_file_haocode.txt'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->output);
    }

    public function test_it_returns_error_for_directory_path(): void
    {
        $result = $this->tool->call(['file_path' => sys_get_temp_dir()], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('directory', $result->output);
    }

    // ─── offset and limit ─────────────────────────────────────────────────

    public function test_it_respects_line_offset(): void
    {
        $file = $this->makeTmpFile("line1\nline2\nline3\nline4\nline5\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'offset' => 3,
        ], $this->context);

        $this->assertStringNotContainsString('line1', $result->output);
        $this->assertStringNotContainsString('line2', $result->output);
        $this->assertStringContainsString('line3', $result->output);

        unlink($file);
    }

    public function test_it_respects_line_limit(): void
    {
        $file = $this->makeTmpFile("line1\nline2\nline3\nline4\nline5\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'limit' => 2,
        ], $this->context);

        $this->assertStringContainsString('line1', $result->output);
        $this->assertStringContainsString('line2', $result->output);
        $this->assertStringNotContainsString('line3', $result->output);

        unlink($file);
    }

    public function test_it_combines_offset_and_limit(): void
    {
        $file = $this->makeTmpFile("a\nb\nc\nd\ne\n");

        $result = $this->tool->call([
            'file_path' => $file,
            'offset' => 2,
            'limit' => 2,
        ], $this->context);

        $this->assertStringNotContainsString("\ta\n", $result->output);
        $this->assertStringContainsString('b', $result->output);
        $this->assertStringContainsString('c', $result->output);
        $this->assertStringNotContainsString("\td\n", $result->output);

        unlink($file);
    }

    // ─── isReadOnly ────────────────────────────────────────────────────────

    public function test_is_read_only_returns_true(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    // ─── maxResultSizeChars ────────────────────────────────────────────────

    public function test_max_result_size_is_unlimited_to_prevent_truncation_loop(): void
    {
        $this->assertSame(PHP_INT_MAX, $this->tool->maxResultSizeChars());
    }

    // ─── Total line count appears in header ────────────────────────────────

    public function test_header_includes_total_line_count(): void
    {
        $file = $this->makeTmpFile("one\ntwo\nthree\n");

        $result = $this->tool->call(['file_path' => $file], $this->context);

        $this->assertStringContainsString('3 lines', $result->output);

        unlink($file);
    }

    public function test_it_returns_error_when_offset_exceeds_file_length(): void
    {
        // File has 3 lines; requesting offset 10 should return an error,
        // not a nonsensical "Lines 10-9" header with empty content.
        $file = $this->makeTmpFile("one\ntwo\nthree\n");

        $result = $this->tool->call(['file_path' => $file, 'offset' => 10], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Offset', $result->output);
        $this->assertStringContainsString('3', $result->output); // mentions actual line count

        unlink($file);
    }
}
