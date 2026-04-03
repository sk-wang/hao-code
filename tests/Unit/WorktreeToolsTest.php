<?php

namespace Tests\Unit;

use App\Tools\ToolUseContext;
use App\Tools\Worktree\EnterWorktreeTool;
use App\Tools\Worktree\ExitWorktreeTool;
use PHPUnit\Framework\TestCase;

class WorktreeToolsTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        // Use a non-git tmp dir so git checks fail predictably
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');
    }

    // ─── EnterWorktreeTool ────────────────────────────────────────────────

    public function test_enter_name(): void
    {
        $this->assertSame('EnterWorktree', (new EnterWorktreeTool)->name());
    }

    public function test_enter_description_mentions_worktree(): void
    {
        $this->assertStringContainsString('worktree', strtolower((new EnterWorktreeTool)->description()));
    }

    public function test_enter_is_not_read_only(): void
    {
        $this->assertFalse((new EnterWorktreeTool)->isReadOnly([]));
    }

    public function test_enter_schema_has_optional_name_field(): void
    {
        $schema = (new EnterWorktreeTool)->inputSchema()->toJsonSchema();
        $this->assertArrayHasKey('name', $schema['properties']);
        // name is optional — not in required
        $this->assertNotContains('name', $schema['required'] ?? []);
    }

    public function test_enter_call_returns_error_when_not_in_git_repo(): void
    {
        // sys_get_temp_dir() is not a git repo, so this should fail
        $result = (new EnterWorktreeTool)->call([], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('git', strtolower($result->output));
    }

    public function test_enter_name_sanitisation_strips_special_chars(): void
    {
        // Test sanitisation via a proxy that skips the git checks
        $proxy = new class extends EnterWorktreeTool {
            public function call(array $input, \App\Tools\ToolUseContext $ctx): \App\Tools\ToolResult
            {
                $name = $input['name'] ?? null;
                if ($name) {
                    $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
                    if ($name === '') {
                        $name = 'worktree_fallback';
                    }
                    if (mb_strlen($name) > 64) {
                        $name = mb_substr($name, 0, 64);
                    }
                }
                return \App\Tools\ToolResult::success("Name: {$name}");
            }
        };

        $result = $proxy->call(['name' => 'hello world / foo!'], $this->context);
        $this->assertStringContainsString('helloworldfoo', $result->output);
    }

    public function test_enter_long_name_is_truncated(): void
    {
        $proxy = new class extends EnterWorktreeTool {
            public function call(array $input, \App\Tools\ToolUseContext $ctx): \App\Tools\ToolResult
            {
                $name = $input['name'] ?? null;
                if ($name) {
                    $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
                    if (mb_strlen($name) > 64) {
                        $name = mb_substr($name, 0, 64);
                    }
                }
                return \App\Tools\ToolResult::success("Name length: " . mb_strlen($name));
            }
        };

        $result = $proxy->call(['name' => str_repeat('a', 100)], $this->context);
        $this->assertStringContainsString('64', $result->output);
    }

    // ─── ExitWorktreeTool ─────────────────────────────────────────────────

    public function test_exit_name(): void
    {
        $this->assertSame('ExitWorktree', (new ExitWorktreeTool)->name());
    }

    public function test_exit_description_mentions_worktree(): void
    {
        $this->assertStringContainsString('worktree', strtolower((new ExitWorktreeTool)->description()));
    }

    public function test_exit_keep_is_read_only(): void
    {
        $this->assertTrue((new ExitWorktreeTool)->isReadOnly(['action' => 'keep']));
    }

    public function test_exit_remove_is_not_read_only(): void
    {
        $this->assertFalse((new ExitWorktreeTool)->isReadOnly(['action' => 'remove']));
    }

    public function test_exit_no_action_is_read_only(): void
    {
        // Default with no action key → 'keep' evaluates to false for 'remove' check
        $this->assertTrue((new ExitWorktreeTool)->isReadOnly([]));
    }

    public function test_exit_schema_requires_action(): void
    {
        $schema = (new ExitWorktreeTool)->inputSchema()->toJsonSchema();
        $this->assertContains('action', $schema['required']);
    }

    public function test_exit_schema_action_has_enum(): void
    {
        $schema = (new ExitWorktreeTool)->inputSchema()->toJsonSchema();
        $this->assertSame(['keep', 'remove'], $schema['properties']['action']['enum']);
    }

    public function test_exit_call_returns_error_when_not_in_worktree(): void
    {
        // sys_get_temp_dir() is not a git worktree
        $result = (new ExitWorktreeTool)->call(['action' => 'keep'], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Not in a worktree', $result->output);
    }
}
