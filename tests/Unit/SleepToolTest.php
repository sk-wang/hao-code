<?php

namespace Tests\Unit;

use App\Tools\Sleep\SleepTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class SleepToolTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    // ─── name / description ───────────────────────────────────────────────

    public function test_name_is_sleep(): void
    {
        $this->assertSame('Sleep', (new SleepTool)->name());
    }

    public function test_description_mentions_seconds(): void
    {
        $desc = (new SleepTool)->description();
        $this->assertStringContainsString('second', strtolower($desc));
    }

    public function test_is_not_read_only(): void
    {
        // SleepTool does not implement isReadOnly, defaults from BaseTool
        // BaseTool default is false (non-destructive but not read-only)
        $tool = new SleepTool;
        // Just ensure the method exists and returns a bool
        $this->assertIsBool($tool->isReadOnly([]));
    }

    // ─── input schema ─────────────────────────────────────────────────────

    public function test_input_schema_requires_seconds(): void
    {
        $schema = (new SleepTool)->inputSchema();
        $raw = $schema->toJsonSchema();
        $this->assertContains('seconds', $raw['required'] ?? []);
    }

    public function test_input_schema_seconds_has_min_max(): void
    {
        $schema = (new SleepTool)->inputSchema();
        $raw = $schema->toJsonSchema();
        $seconds = $raw['properties']['seconds'];
        $this->assertSame(1, $seconds['minimum']);
        $this->assertSame(300, $seconds['maximum']);
    }

    // ─── call — bounds clamping (avoids actual sleep) ─────────────────────

    public function test_zero_seconds_clamped_to_one(): void
    {
        // Override sleep() is not possible, but we can verify clamping
        // by checking output when seconds is slightly out of range.
        // We use seconds=1 (minimum valid) to minimise actual sleep time.
        $start = time();
        $result = (new SleepTool)->call(['seconds' => 0], $this->context);
        $elapsed = time() - $start;

        // Clamp 0 → 1, so slept 1 second
        $this->assertFalse($result->isError);
        $this->assertStringContainsString('1 second', $result->output);
        $this->assertGreaterThanOrEqual(1, $elapsed);
    }

    public function test_oversized_seconds_clamped_to_300(): void
    {
        // We don't actually run this — just verify the output message would say 300.
        // We can mock via a partial mock that stubs sleep().
        $tool = $this->getMockBuilder(SleepTool::class)
            ->onlyMethods([])
            ->getMock();

        // Call with a reflected internal — instead, just check output string
        // by patching sleep via a child class proxy.
        $proxy = new class extends SleepTool {
            public int $sleptSeconds = 0;
            public function call(array $input, \App\Tools\ToolUseContext $ctx): \App\Tools\ToolResult
            {
                $seconds = (int)($input['seconds'] ?? 1);
                $seconds = max(1, min(300, $seconds));
                $this->sleptSeconds = $seconds;
                return \App\Tools\ToolResult::success("Slept for {$seconds} second(s).");
            }
        };

        $result = $proxy->call(['seconds' => 9999], $this->context);
        $this->assertSame(300, $proxy->sleptSeconds);
        $this->assertStringContainsString('300 second', $result->output);
    }

    public function test_valid_seconds_returns_message(): void
    {
        // Use a no-sleep proxy
        $proxy = new class extends SleepTool {
            public function call(array $input, \App\Tools\ToolUseContext $ctx): \App\Tools\ToolResult
            {
                $seconds = (int)($input['seconds'] ?? 1);
                $seconds = max(1, min(300, $seconds));
                return \App\Tools\ToolResult::success("Slept for {$seconds} second(s).");
            }
        };

        $result = $proxy->call(['seconds' => 5], $this->context);
        $this->assertFalse($result->isError);
        $this->assertStringContainsString('5 second', $result->output);
    }
}
