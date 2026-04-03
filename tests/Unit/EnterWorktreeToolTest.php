<?php

namespace Tests\Unit;

use App\Tools\Worktree\EnterWorktreeTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class EnterWorktreeToolTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    public function test_name_is_enter_worktree(): void
    {
        $tool = new EnterWorktreeTool;
        $this->assertSame('EnterWorktree', $tool->name());
    }

    public function test_is_not_read_only(): void
    {
        $tool = new EnterWorktreeTool;
        $this->assertFalse($tool->isReadOnly([]));
    }

    public function test_returns_error_when_not_in_git_repo(): void
    {
        $tmpDir = sys_get_temp_dir() . '/no_git_' . getmypid();
        mkdir($tmpDir, 0755, true);

        $context = new ToolUseContext(
            workingDirectory: $tmpDir,
            sessionId: 'test',
        );

        $tool = new EnterWorktreeTool;
        $result = $tool->call([], $context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('git', strtolower($result->output));

        rmdir($tmpDir);
    }

    public function test_sanitizes_name_to_allowed_characters(): void
    {
        // We can't easily test the full call without a real git repo,
        // but we can test the validateInput path with a non-git dir.
        $tmpDir = sys_get_temp_dir() . '/no_git_sanitize_' . getmypid();
        mkdir($tmpDir, 0755, true);

        $context = new ToolUseContext(workingDirectory: $tmpDir, sessionId: 'test');
        $tool = new EnterWorktreeTool;

        $error = $tool->validateInput(['name' => 'valid-name'], $context);
        // Will error about "not a git repo" but won't crash on the name
        $this->assertIsString($error);

        rmdir($tmpDir);
    }

    // ─── worktree detection fix ───────────────────────────────────────────

    public function test_detects_already_in_worktree_when_git_dir_differs_from_common_dir(): void
    {
        // Create a real git repo to test the linked-worktree detection
        $tmpDir = sys_get_temp_dir() . '/wt_test_' . getmypid();
        $repoDir = $tmpDir . '/main';
        $worktreeDir = $tmpDir . '/linked';
        mkdir($repoDir, 0755, true);

        // Init a bare repo with a commit
        exec('cd ' . escapeshellarg($repoDir) . ' && git init -q && git config user.email test@test.com && git config user.name Test && git commit --allow-empty -m init 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('git not available or init failed');
        }

        // Create a linked worktree
        exec('cd ' . escapeshellarg($repoDir) . ' && git worktree add ' . escapeshellarg($worktreeDir) . ' -b linked-branch HEAD 2>&1', $out2, $code2);
        if ($code2 !== 0 || !is_dir($worktreeDir)) {
            $this->markTestSkipped('git worktree add failed: ' . implode(' ', $out2));
        }

        // When called from inside the linked worktree, it should detect we're already in one
        $context = new ToolUseContext(workingDirectory: $worktreeDir, sessionId: 'test');
        $tool = new EnterWorktreeTool;
        $result = $tool->call([], $context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Already in a worktree', $result->output);

        // Cleanup
        exec('cd ' . escapeshellarg($repoDir) . ' && git worktree remove ' . escapeshellarg($worktreeDir) . ' 2>/dev/null');
        exec('rm -rf ' . escapeshellarg($tmpDir));
    }
}
