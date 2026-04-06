<?php

namespace Tests\Unit;

use App\Tools\TodoWrite\TodoWriteTool;
use App\Tools\ToolUseContext;
use Tests\TestCase;

class TodoWriteToolTest extends TestCase
{
    private ToolUseContext $context;
    private TodoWriteTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
        $this->tool = new TodoWriteTool;
    }

    // ─── Basic output ─────────────────────────────────────────────────────

    public function test_call_returns_success(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'Write tests', 'status' => 'pending', 'activeForm' => 'Writing tests'],
            ],
        ], $this->context);

        $this->assertFalse($result->isError);
    }

    public function test_pending_status_uses_circle_symbol(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'Do thing', 'status' => 'pending', 'activeForm' => 'Doing thing'],
            ],
        ], $this->context);

        $this->assertStringContainsString('○', $result->output);
    }

    public function test_in_progress_uses_arrow_symbol(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'In progress task', 'status' => 'in_progress', 'activeForm' => 'Working'],
            ],
        ], $this->context);

        $this->assertStringContainsString('→', $result->output);
    }

    public function test_completed_uses_checkmark_symbol(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'Done task', 'status' => 'completed', 'activeForm' => 'Done'],
            ],
        ], $this->context);

        $this->assertStringContainsString('✓', $result->output);
    }

    public function test_output_includes_task_content(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'Fix the bug', 'status' => 'pending', 'activeForm' => 'Fixing'],
            ],
        ], $this->context);

        $this->assertStringContainsString('Fix the bug', $result->output);
    }

    public function test_output_includes_numbered_items(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'First', 'status' => 'pending', 'activeForm' => 'First-ing'],
                ['content' => 'Second', 'status' => 'pending', 'activeForm' => 'Second-ing'],
                ['content' => 'Third', 'status' => 'pending', 'activeForm' => 'Third-ing'],
            ],
        ], $this->context);

        $this->assertStringContainsString('1.', $result->output);
        $this->assertStringContainsString('2.', $result->output);
        $this->assertStringContainsString('3.', $result->output);
    }

    public function test_mixed_statuses_all_appear(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'Done task', 'status' => 'completed', 'activeForm' => 'x'],
                ['content' => 'Current task', 'status' => 'in_progress', 'activeForm' => 'x'],
                ['content' => 'Future task', 'status' => 'pending', 'activeForm' => 'x'],
            ],
        ], $this->context);

        $this->assertStringContainsString('✓', $result->output);
        $this->assertStringContainsString('→', $result->output);
        $this->assertStringContainsString('○', $result->output);
    }

    public function test_empty_todos_list_returns_success(): void
    {
        $result = $this->tool->call(['todos' => []], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Todo list updated', $result->output);
    }

    public function test_input_schema_allows_empty_todo_list(): void
    {
        $validated = $this->tool->inputSchema()->validate(['todos' => []]);

        $this->assertSame(['todos' => []], $validated);
    }

    public function test_output_header_says_updated(): void
    {
        $result = $this->tool->call([
            'todos' => [
                ['content' => 'X', 'status' => 'pending', 'activeForm' => 'Y'],
            ],
        ], $this->context);

        $this->assertStringStartsWith('Todo list updated', $result->output);
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_is_read_only_returns_true(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    // ─── Tool metadata ────────────────────────────────────────────────────

    public function test_name_is_todo_write(): void
    {
        $this->assertSame('TodoWrite', $this->tool->name());
    }

    public function test_description_is_not_empty(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }
}
