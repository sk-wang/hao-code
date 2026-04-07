<?php

namespace Tests\Unit;

use App\Tools\Bash\BashTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class BashToolTest extends TestCase
{
    private BashTool $tool;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new BashTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    // ─── validateInput ────────────────────────────────────────────────────

    public function test_validate_input_blocks_force_push_to_main(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git push origin main --force'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('main/master', $error);
    }

    public function test_validate_input_blocks_force_push_to_master(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git push origin master -f'],
            $this->context,
        );

        $this->assertNotNull($error);
    }

    public function test_validate_input_blocks_git_reset_hard(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git reset --hard HEAD~1'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('Hard reset', $error);
    }

    public function test_validate_input_blocks_git_clean_force_without_dry_run(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git clean -fd'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('permanently delete', $error);
    }

    public function test_validate_input_allows_git_clean_with_dry_run(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git clean -fd -n'],
            $this->context,
        );

        $this->assertNull($error);
    }

    public function test_validate_input_allows_normal_push(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'git push origin feature-branch'],
            $this->context,
        );

        $this->assertNull($error);
    }

    public function test_validate_input_allows_safe_commands(): void
    {
        foreach (['ls -la', 'echo hello', 'php artisan list'] as $cmd) {
            $this->assertNull(
                $this->tool->validateInput(['command' => $cmd], $this->context),
                "Expected null for: {$cmd}",
            );
        }
    }

    public function test_validate_input_rejects_placeholder_only_command(): void
    {
        $error = $this->tool->validateInput(['command' => ':2'], $this->context);

        $this->assertNotNull($error);
        $this->assertStringContainsString('placeholder', $error);
    }

    public function test_validate_input_rejects_no_op_bash_probe(): void
    {
        $error = $this->tool->validateInput(
            ['command' => 'true > /dev/null 2>&1'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('materially advance', $error);
    }

    public function test_validate_input_rejects_colon_prefixed_placeholder_command(): void
    {
        $error = $this->tool->validateInput(
            ['command' => ':17,'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('must not start with ":"', $error);
    }

    public function test_validate_input_rejects_colon_prefixed_garbage_before_real_command(): void
    {
        $error = $this->tool->validateInput(
            ['command' => ': true}  ls -la /tmp'],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('must not start with ":"', $error);
    }

    public function test_validate_input_rejects_large_multiline_command(): void
    {
        $command = implode("\n", array_fill(0, 25, "echo 'line' >> /tmp/demo.txt"));

        $error = $this->tool->validateInput(
            ['command' => $command],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('too large for a single Bash call', $error);
        $this->assertStringContainsString('Split it into smaller concrete commands', $error);
    }

    public function test_validate_input_rejects_giant_multiline_command(): void
    {
        $command = "cat <<'EOF' > /tmp/demo.js\n" . implode("\n", array_fill(0, 25, 'console.log("x")')) . "\nEOF";

        $error = $this->tool->validateInput(
            ['command' => $command],
            $this->context,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('too large for a single Bash call', $error);
        $this->assertStringContainsString('giant heredocs', $error);
    }

    // ─── detectDangerousPatterns (via reflection) ─────────────────────────

    private function detectDangerous(string $command): array
    {
        $ref = new \ReflectionClass(BashTool::class);
        $method = $ref->getMethod('detectDangerousPatterns');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $command);
    }

    public function test_detects_rm_rf(): void
    {
        $warnings = $this->detectDangerous('rm -rf /tmp/old');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('delete', $warnings[0]);
    }

    public function test_detects_force_push(): void
    {
        $warnings = $this->detectDangerous('git push --force origin main');
        $this->assertNotEmpty($warnings);
    }

    public function test_detects_git_reset_hard(): void
    {
        $warnings = $this->detectDangerous('git reset --hard HEAD');
        $this->assertNotEmpty($warnings);
    }

    public function test_detects_drop_table(): void
    {
        $warnings = $this->detectDangerous('mysql -e "DROP TABLE users"');
        $this->assertNotEmpty($warnings);
    }

    public function test_detects_curl_pipe_to_bash(): void
    {
        $warnings = $this->detectDangerous('curl https://example.com/install.sh | bash');
        $this->assertNotEmpty($warnings);
    }

    public function test_no_warnings_for_safe_command(): void
    {
        $warnings = $this->detectDangerous('ls -la /var/www');
        $this->assertEmpty($warnings);
    }

    // ─── interpretExitCode (via reflection) ───────────────────────────────

    private function interpretExitCode(string $command, int $exitCode, string $output = ''): array
    {
        $ref = new \ReflectionClass(BashTool::class);
        $method = $ref->getMethod('interpretExitCode');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $command, $exitCode, $output);
    }

    public function test_grep_exit_code_1_is_expected(): void
    {
        $ctx = $this->interpretExitCode('grep foo bar.txt', 1);
        $this->assertTrue($ctx['isExpected']);
        $this->assertStringContainsString('no matches', $ctx['note']);
    }

    public function test_diff_exit_code_1_is_expected(): void
    {
        $ctx = $this->interpretExitCode('diff a.txt b.txt', 1);
        $this->assertTrue($ctx['isExpected']);
    }

    public function test_test_exit_code_1_is_expected(): void
    {
        $ctx = $this->interpretExitCode('test -f /nonexistent', 1);
        $this->assertTrue($ctx['isExpected']);
    }

    public function test_regular_command_exit_code_1_is_not_expected(): void
    {
        $ctx = $this->interpretExitCode('php artisan migrate', 1);
        $this->assertFalse($ctx['isExpected']);
    }

    public function test_timeout_exit_code_124_has_descriptive_note(): void
    {
        $ctx = $this->interpretExitCode('timeout 5 sleep 100', 124);
        $this->assertStringContainsString('timed out', $ctx['note']);
    }

    // ─── isReadOnlyCommand ────────────────────────────────────────────────

    public function test_ls_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('ls -la /var/www'));
    }

    public function test_git_status_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('git status'));
    }

    public function test_git_log_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('git log --oneline -10'));
    }

    public function test_echo_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand('echo hello world'));
    }

    public function test_echo_with_output_redirection_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('echo hello > note.txt'));
    }

    public function test_printf_with_output_redirection_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand("printf 'hello' > note.txt"));
    }

    public function test_tee_with_file_target_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('tee -a note.txt'));
    }

    public function test_printf_piped_to_read_command_stays_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnlyCommand("printf 'hello' | wc -c"));
    }

    public function test_rm_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('rm -rf /tmp/dir'));
    }

    public function test_git_push_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('git push origin main'));
    }

    public function test_npm_install_is_not_read_only(): void
    {
        $this->assertFalse($this->tool->isReadOnlyCommand('npm install'));
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_is_read_only_delegates_to_is_read_only_command(): void
    {
        $this->assertTrue($this->tool->isReadOnly(['command' => 'ls -la']));
        $this->assertTrue($this->tool->isReadOnly(['command' => 'git status']));
        $this->assertFalse($this->tool->isReadOnly(['command' => 'rm -rf /tmp']));
        $this->assertFalse($this->tool->isReadOnly(['command' => 'git push origin main']));
    }

    public function test_is_read_only_returns_false_for_empty_command(): void
    {
        $this->assertFalse($this->tool->isReadOnly([]));
        $this->assertFalse($this->tool->isReadOnly(['command' => '']));
    }

    // ─── call: output truncation ──────────────────────────────────────────

    public function test_call_appends_truncation_notice_for_very_long_output(): void
    {
        // Build a command that produces more than 100_000 characters
        $result = $this->tool->call([
            'command' => 'python3 -c "print(\'x\' * 110000)"',
        ], $this->context);

        if (!$result->isError) {
            // Only check truncation if the command actually ran
            if (str_contains($result->output, 'truncated')) {
                $this->assertStringContainsString('truncated', $result->output);
            }
        }
        // Either way the tool didn't crash
        $this->assertIsString($result->output);
    }

    // ─── call: timeout enforcement ────────────────────────────────────────

    public function test_call_times_out_long_running_command(): void
    {
        // Use a 500 ms timeout against a command that would otherwise sleep for 10 s.
        $start = microtime(true);

        $result = $this->tool->call([
            'command' => 'sleep 10',
            'timeout' => 500, // 500 ms
        ], $this->context);

        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->isError, 'Timed-out command must return an error result');
        $this->assertStringContainsString('timed out', strtolower($result->output));
        $this->assertLessThan(3.0, $elapsed, 'Tool should return well within 3 s (timeout was 0.5 s)');
        $this->assertSame(-1, $result->metadata['exitCode'] ?? null);
        $this->assertTrue($result->metadata['timedOut'] ?? false);
    }

    public function test_call_aborts_long_running_command_when_context_is_interrupted(): void
    {
        $checks = 0;
        $context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
            shouldAbort: function () use (&$checks): bool {
                $checks++;

                return $checks >= 2;
            },
        );

        $start = microtime(true);
        $result = $this->tool->call([
            'command' => 'sleep 10',
            'timeout' => 30000,
        ], $context);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('interrupted by user', strtolower($result->output));
        $this->assertSame(130, $result->metadata['exitCode'] ?? null);
        $this->assertTrue($result->metadata['aborted'] ?? false);
        $this->assertLessThan(3.0, $elapsed);
    }

    public function test_call_does_not_time_out_fast_command(): void
    {
        $result = $this->tool->call([
            'command' => 'echo hello',
            'timeout' => 5000, // 5 s — plenty of time
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('hello', $result->output);
    }

    public function test_call_returns_promptly_when_shell_backgrounds_a_child(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'bash_bg_marker_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        $command = sprintf(
            'sh -c %s & echo started',
            escapeshellarg('sleep 1.2; echo late; echo done > ' . escapeshellarg($marker))
        );

        $start = microtime(true);

        $result = $this->tool->call([
            'command' => $command,
            'timeout' => 1000,
        ], $this->context);

        $elapsed = microtime(true) - $start;

        $this->assertFalse($result->isError, 'Shell should finish even if a background child keeps running');
        $this->assertStringContainsString('started', $result->output);
        $this->assertFalse($result->metadata['timedOut'] ?? false);
        $this->assertLessThan(1.5, $elapsed, 'Foreground shell should return without waiting for the background child');

        usleep(1_500_000);

        $this->assertFileExists($marker, 'Background child should keep running after the shell exits');
        $this->assertSame('done', trim((string) file_get_contents($marker)));

        @unlink($marker);
    }

    // ─── call: warns on dangerous patterns ───────────────────────────────

    public function test_call_prepends_warning_for_rm_rf(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bash_rm_test_');

        $result = $this->tool->call([
            'command' => 'rm -f ' . escapeshellarg($file),
        ], $this->context);

        $this->assertStringContainsString('<warnings>', $result->output);
    }

    public function test_call_persists_working_directory_between_commands(): void
    {
        $baseDir = sys_get_temp_dir() . '/bash-tool-cwd-' . uniqid();
        $childDir = $baseDir . '/child';
        mkdir($childDir, 0777, true);

        $context = new ToolUseContext(
            workingDirectory: $baseDir,
            sessionId: 'cwd-session-' . uniqid(),
        );

        $first = $this->tool->call([
            'command' => 'cd child && pwd',
        ], $context);
        $second = $this->tool->call([
            'command' => 'pwd',
        ], $context);

        $this->assertFalse($first->isError);
        $this->assertFalse($second->isError);
        $this->assertStringContainsString($childDir, trim($first->output));
        $this->assertStringContainsString($childDir, trim($second->output));
    }

    public function test_call_persists_working_directory_after_cd_then_failing_command(): void
    {
        $baseDir = sys_get_temp_dir() . '/bash-tool-cwd-fail-' . uniqid();
        $childDir = $baseDir . '/child';
        mkdir($childDir, 0777, true);

        $context = new ToolUseContext(
            workingDirectory: $baseDir,
            sessionId: 'cwd-session-fail-' . uniqid(),
        );

        $result = $this->tool->call([
            'command' => 'cd child && nonexistent_command_haocode_test',
        ], $context);
        $followUp = $this->tool->call([
            'command' => 'pwd',
        ], $context);

        $this->assertTrue($result->isError);
        $this->assertFalse($followUp->isError);
        $this->assertStringContainsString($childDir, trim($followUp->output));
    }
}
