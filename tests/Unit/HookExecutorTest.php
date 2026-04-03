<?php

namespace Tests\Unit;

use App\Services\Hooks\HookDefinition;
use App\Services\Hooks\HookExecutor;
use App\Services\Hooks\HookResult;
use PHPUnit\Framework\TestCase;

class HookExecutorTest extends TestCase
{
    /**
     * Build an executor with no hooks loaded from disk, then inject hooks manually.
     */
    private function makeExecutor(array $hooks = []): HookExecutor
    {
        $executor = new HookExecutor;

        $ref = new \ReflectionClass($executor);
        $prop = $ref->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue($executor, $hooks);

        return $executor;
    }

    private function makeHook(string $event, string $command): HookDefinition
    {
        return new HookDefinition(event: $event, command: $command);
    }

    // ─── execute() — no hooks ─────────────────────────────────────────────

    public function test_execute_with_no_hooks_returns_allowed(): void
    {
        $executor = $this->makeExecutor();
        $result = $executor->execute('PreToolUse', ['tool' => 'Bash']);

        $this->assertTrue($result->allowed);
    }

    public function test_execute_with_no_hooks_returns_empty_output(): void
    {
        $executor = $this->makeExecutor();
        $result = $executor->execute('PreToolUse');

        $this->assertSame('', $result->output);
        $this->assertNull($result->modifiedInput);
    }

    // ─── execute() — hook succeeds ────────────────────────────────────────

    public function test_successful_hook_returns_allowed(): void
    {
        $hook = $this->makeHook('PostToolUse', 'echo "hook ran"');
        $executor = $this->makeExecutor(['PostToolUse' => [$hook]]);

        $result = $executor->execute('PostToolUse');

        $this->assertTrue($result->allowed);
    }

    public function test_successful_hook_captures_stdout(): void
    {
        $hook = $this->makeHook('TestEvent', 'echo "hello from hook"');
        $executor = $this->makeExecutor(['TestEvent' => [$hook]]);

        $result = $executor->execute('TestEvent');

        $this->assertStringContainsString('hello from hook', $result->output);
    }

    // ─── execute() — hook fails (non-zero exit) ───────────────────────────

    public function test_failing_hook_returns_not_allowed(): void
    {
        // `false` exits with code 1 on all POSIX shells
        $hook = $this->makeHook('PreToolUse', 'false');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    public function test_failing_hook_output_mentions_exit_code(): void
    {
        $hook = $this->makeHook('PreToolUse', 'false');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertStringContainsString('exit code', $result->output);
    }

    public function test_failing_hook_short_circuits_remaining_hooks(): void
    {
        // First hook fails, second should not run
        $hooks = [
            $this->makeHook('TestEvent', 'false'),
            $this->makeHook('TestEvent', 'echo "should not run"'),
        ];
        $executor = $this->makeExecutor(['TestEvent' => $hooks]);

        $result = $executor->execute('TestEvent');

        $this->assertFalse($result->allowed);
        $this->assertStringNotContainsString('should not run', $result->output);
    }

    // ─── execute() — hook outputs "deny" keyword ─────────────────────────

    public function test_hook_output_deny_blocks_execution(): void
    {
        $hook = $this->makeHook('PreToolUse', 'echo deny');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    public function test_hook_output_block_blocks_execution(): void
    {
        $hook = $this->makeHook('PreToolUse', 'echo block');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    public function test_hook_output_no_blocks_execution(): void
    {
        $hook = $this->makeHook('PreToolUse', 'echo no');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse');

        $this->assertFalse($result->allowed);
    }

    // ─── execute() — hook outputs JSON ────────────────────────────────────

    public function test_hook_json_output_with_allow_true(): void
    {
        $json = json_encode(['allow' => true, 'message' => 'all good']);
        $hook = $this->makeHook('TestEvent', "echo '{$json}'");
        $executor = $this->makeExecutor(['TestEvent' => [$hook]]);

        $result = $executor->execute('TestEvent');

        $this->assertTrue($result->allowed);
        $this->assertSame('all good', $result->output);
    }

    public function test_hook_json_output_with_allow_false(): void
    {
        $json = json_encode(['allow' => false, 'message' => 'blocked by hook']);
        $hook = $this->makeHook('TestEvent', "echo '{$json}'");
        $executor = $this->makeExecutor(['TestEvent' => [$hook]]);

        $result = $executor->execute('TestEvent');

        $this->assertFalse($result->allowed);
    }

    public function test_hook_json_output_with_modified_input(): void
    {
        $json = json_encode(['allow' => true, 'input' => ['key' => 'modified']]);
        $hook = $this->makeHook('TestEvent', "echo '{$json}'");
        $executor = $this->makeExecutor(['TestEvent' => [$hook]]);

        $result = $executor->execute('TestEvent');

        $this->assertTrue($result->allowed);
        $this->assertSame(['key' => 'modified'], $result->modifiedInput);
    }

    // ─── execute() — multiple hooks ───────────────────────────────────────

    public function test_multiple_hooks_outputs_are_joined_with_newline(): void
    {
        $hooks = [
            $this->makeHook('TestEvent', 'echo "line1"'),
            $this->makeHook('TestEvent', 'echo "line2"'),
        ];
        $executor = $this->makeExecutor(['TestEvent' => $hooks]);

        $result = $executor->execute('TestEvent');

        $this->assertStringContainsString('line1', $result->output);
        $this->assertStringContainsString('line2', $result->output);
    }

    // ─── execute() — context passed as env vars ───────────────────────────

    public function test_context_passed_as_hook_env_variables(): void
    {
        // The env var name should be HOOK_TOOL_NAME
        $hook = $this->makeHook('PreToolUse', 'echo "tool=$HOOK_TOOL_NAME"');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse', ['tool_name' => 'Bash']);

        $this->assertStringContainsString('tool=Bash', $result->output);
    }

    // ─── HookResult value object ──────────────────────────────────────────

    public function test_hook_result_allowed_true(): void
    {
        $result = new HookResult(allowed: true);
        $this->assertTrue($result->allowed);
        $this->assertSame('', $result->output);
        $this->assertNull($result->modifiedInput);
    }

    public function test_hook_result_allowed_false(): void
    {
        $result = new HookResult(allowed: false, output: 'blocked');
        $this->assertFalse($result->allowed);
        $this->assertSame('blocked', $result->output);
    }

    // ─── matcher filtering ────────────────────────────────────────────────

    public function test_hook_with_matching_matcher_runs(): void
    {
        $executor = $this->makeExecutor([
            'PreToolUse' => [new HookDefinition('PreToolUse', 'echo matched', 'Bash')],
        ]);

        $result = $executor->execute('PreToolUse', ['tool' => 'Bash']);
        $this->assertTrue($result->allowed);
        $this->assertSame('matched', $result->output);
    }

    public function test_hook_with_non_matching_matcher_is_skipped(): void
    {
        $executor = $this->makeExecutor([
            'PreToolUse' => [new HookDefinition('PreToolUse', 'echo ran', 'Bash')],
        ]);

        // Execute with a different tool — hook should be skipped
        $result = $executor->execute('PreToolUse', ['tool' => 'Read']);
        $this->assertTrue($result->allowed);
        $this->assertSame('', $result->output);
    }

    public function test_hook_with_wildcard_matcher_matches_multiple_tools(): void
    {
        $executor = $this->makeExecutor([
            'PreToolUse' => [new HookDefinition('PreToolUse', 'echo wildcard', 'File*')],
        ]);

        $result1 = $executor->execute('PreToolUse', ['tool' => 'FileRead']);
        $this->assertSame('wildcard', $result1->output);

        $result2 = $executor->execute('PreToolUse', ['tool' => 'FileEdit']);
        $this->assertSame('wildcard', $result2->output);

        $result3 = $executor->execute('PreToolUse', ['tool' => 'Bash']);
        $this->assertSame('', $result3->output);
    }

    public function test_hook_without_matcher_runs_for_all_tools(): void
    {
        $executor = $this->makeExecutor([
            'PreToolUse' => [new HookDefinition('PreToolUse', 'echo always')],
        ]);

        $result1 = $executor->execute('PreToolUse', ['tool' => 'Bash']);
        $this->assertSame('always', $result1->output);

        $result2 = $executor->execute('PreToolUse', ['tool' => 'Read']);
        $this->assertSame('always', $result2->output);
    }

    // ─── non-string context values are skipped for env ────────────────────

    public function test_non_string_context_values_are_not_passed_as_env_vars(): void
    {
        // Only string/numeric context values should become env vars.
        // Arrays and other non-string types must be skipped to avoid
        // "Array to string conversion" warnings.
        $hook = $this->makeHook('PreToolUse', 'echo "count=${HOOK_COUNT:-unset}"');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        // Pass an array and an int in context — only the int should become an env var
        $result = $executor->execute('PreToolUse', [
            'tool' => 'Bash',
            'input' => ['command' => 'ls'], // array — should NOT become env var
            'count' => 42,                   // int — should become HOOK_COUNT=42
        ]);

        $this->assertStringContainsString('count=42', $result->output);
        // No assertion failure means no "Array to string" warning was emitted
    }

    public function test_execute_with_empty_context(): void
    {
        $hook = $this->makeHook('PreToolUse', 'echo "no context"');
        $executor = $this->makeExecutor(['PreToolUse' => [$hook]]);

        $result = $executor->execute('PreToolUse', []);

        $this->assertTrue($result->allowed);
        $this->assertStringContainsString('no context', $result->output);
    }

    // ─── context isolation fix ────────────────────────────────────────────

    public function test_second_hook_still_receives_tool_key_after_first_hook_modifies_input(): void
    {
        // Hook 1: modifies input via JSON — BUG was that this overwrote the full $context
        // Hook 2: has a matcher — relies on $context['tool'] being present for the check
        $json = json_encode(['allow' => true, 'input' => ['command' => 'ls -la']]);
        $hooks = [
            $this->makeHook('PreToolUse', "echo '{$json}'"),
            new HookDefinition('PreToolUse', 'echo "second ran"', 'Bash'),
        ];
        $executor = $this->makeExecutor(['PreToolUse' => $hooks]);

        $result = $executor->execute('PreToolUse', ['tool' => 'Bash', 'input' => ['command' => 'ls']]);

        // Both hooks should have run — the second hook's matcher should still see 'Bash'
        $this->assertTrue($result->allowed);
        $this->assertStringContainsString('second ran', $result->output);
    }

    public function test_second_hook_is_skipped_when_tool_does_not_match_after_first_hook_modification(): void
    {
        $json = json_encode(['allow' => true, 'input' => ['command' => 'ls']]);
        $hooks = [
            $this->makeHook('PreToolUse', "echo '{$json}'"),
            new HookDefinition('PreToolUse', 'echo "should not run"', 'Write'), // matcher = Write
        ];
        $executor = $this->makeExecutor(['PreToolUse' => $hooks]);

        // tool is Bash, but second hook matcher requires Write
        $result = $executor->execute('PreToolUse', ['tool' => 'Bash', 'input' => ['command' => 'ls']]);

        $this->assertStringNotContainsString('should not run', $result->output);
    }

    // ─── HookDefinition value object ─────────────────────────────────────

    public function test_hook_definition_properties(): void
    {
        $def = new HookDefinition(event: 'PreToolUse', command: 'echo ok', matcher: 'Bash');
        $this->assertSame('PreToolUse', $def->event);
        $this->assertSame('echo ok', $def->command);
        $this->assertSame('Bash', $def->matcher);
    }

    public function test_hook_definition_matcher_defaults_to_null(): void
    {
        $def = new HookDefinition(event: 'PostToolUse', command: 'echo ok');
        $this->assertNull($def->matcher);
    }
}
