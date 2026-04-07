<?php

namespace Tests\Unit;

use App\Support\Terminal\ToolResultRenderer;
use PHPUnit\Framework\TestCase;

class ToolResultRendererTest extends TestCase
{
    private ToolResultRenderer $renderer;

    protected function setUp(): void
    {
        // Force color off in tests for predictable output
        putenv('NO_COLOR=1');
        $this->renderer = new ToolResultRenderer(120);
    }

    protected function tearDown(): void
    {
        putenv('NO_COLOR');
    }

    // ─── error rendering ────────────────────────────────────────────────

    public function test_error_renders_failure_message(): void
    {
        $result = $this->renderer->render('Bash', ['command' => 'ls'], 'command not found', true);
        $this->assertNotNull($result);
        $this->assertStringContainsString('Bash', $result);
        $this->assertStringContainsString('command not found', $result);
    }

    // ─── Edit tool ──────────────────────────────────────────────────────

    public function test_edit_renders_file_name(): void
    {
        $result = $this->renderer->render('Edit', [
            'file_path' => '/src/App.php',
            'old_string' => 'old',
            'new_string' => 'new',
        ], 'Successfully edited /src/App.php (+1 -1 lines)', false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('App.php', $result);
    }

    public function test_edit_shows_git_diff_when_present(): void
    {
        $output = "Successfully edited file.php (+1 lines)\n\nGit diff:\n--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new";

        $result = $this->renderer->render('Edit', [
            'file_path' => '/src/file.php',
        ], $output, false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('+new', $result);
        $this->assertStringContainsString('-old', $result);
    }

    // ─── Write tool ─────────────────────────────────────────────────────

    public function test_write_shows_created(): void
    {
        $result = $this->renderer->render('Write', [
            'file_path' => '/tmp/new.txt',
        ], 'Successfully created /tmp/new.txt (5 lines, 100 bytes)', false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Created', $result);
        $this->assertStringContainsString('new.txt', $result);
    }

    public function test_write_shows_updated(): void
    {
        $result = $this->renderer->render('Write', [
            'file_path' => '/tmp/exist.txt',
        ], 'Successfully updated /tmp/exist.txt (5 lines, 100 bytes) [+2 lines]', false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Updated', $result);
    }

    // ─── Bash tool ──────────────────────────────────────────────────────

    public function test_bash_shows_description(): void
    {
        $result = $this->renderer->render('Bash', [
            'command' => 'git status',
            'description' => 'Show git status',
        ], 'On branch main', false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Show git status', $result);
    }

    public function test_bash_truncates_long_output(): void
    {
        $longOutput = str_repeat("line\n", 50);
        $result = $this->renderer->render('Bash', [
            'command' => 'cat bigfile',
        ], $longOutput, false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('more lines', $result);
    }

    public function test_bash_truncation_uses_utf8_safe_ellipsis_instead_of_replacement_characters(): void
    {
        $renderer = new ToolResultRenderer(20);
        $output = str_repeat('你好世界', 8);

        $result = $renderer->render('Bash', [
            'command' => 'echo "' . $output . '"',
        ], $output, false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('…', $result);
        $this->assertStringNotContainsString('��', $result);
    }

    // ─── Read tool ──────────────────────────────────────────────────────

    public function test_read_shows_file_info(): void
    {
        $result = $this->renderer->render('Read', [
            'file_path' => '/src/model.php',
        ], 'File: /src/model.php (150 lines total)', false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('model.php', $result);
        $this->assertStringContainsString('150 lines', $result);
    }

    // ─── Glob tool ──────────────────────────────────────────────────────

    public function test_glob_shows_pattern_and_count(): void
    {
        $result = $this->renderer->render('Glob', [
            'pattern' => '**/*.php',
        ], "src/A.php\nsrc/B.php\nsrc/C.php", false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('**/*.php', $result);
        $this->assertStringContainsString('3 files', $result);
    }

    public function test_glob_no_match_message_does_not_count_as_a_file(): void
    {
        $result = $this->renderer->render('Glob', [
            'pattern' => '**/*.{js,jsx,json,html,md}',
        ], 'No files matched pattern: **/*.{js,jsx,json,html,md}', false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('0 files', $result);
    }

    // ─── Grep tool ──────────────────────────────────────────────────────

    public function test_grep_shows_pattern(): void
    {
        $result = $this->renderer->render('Grep', [
            'pattern' => 'TODO',
        ], "file1.php\nfile2.php", false);

        $this->assertNotNull($result);
        $this->assertStringContainsString('TODO', $result);
    }

    // ─── Unknown tools return null ──────────────────────────────────────

    public function test_unknown_tool_returns_null(): void
    {
        $result = $this->renderer->render('CustomTool', [], 'output', false);
        $this->assertNull($result);
    }
}
