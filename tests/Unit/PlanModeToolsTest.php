<?php

namespace Tests\Unit;

use App\Tools\PlanMode\EnterPlanModeTool;
use App\Tools\PlanMode\ExitPlanModeTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class PlanModeToolsTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    // ─── EnterPlanModeTool ────────────────────────────────────────────────

    public function test_enter_name(): void
    {
        $this->assertSame('EnterPlanMode', (new EnterPlanModeTool)->name());
    }

    public function test_enter_description_mentions_plan(): void
    {
        $this->assertStringContainsString('plan', strtolower((new EnterPlanModeTool)->description()));
    }

    public function test_enter_is_read_only(): void
    {
        $this->assertTrue((new EnterPlanModeTool)->isReadOnly([]));
    }

    public function test_enter_is_concurrency_safe(): void
    {
        $this->assertTrue((new EnterPlanModeTool)->isConcurrencySafe([]));
    }

    public function test_enter_call_returns_success(): void
    {
        $result = (new EnterPlanModeTool)->call([], $this->context);
        $this->assertFalse($result->isError);
    }

    public function test_enter_call_mentions_plan_mode(): void
    {
        $result = (new EnterPlanModeTool)->call([], $this->context);
        $this->assertStringContainsString('plan mode', strtolower($result->output));
    }

    public function test_enter_call_mentions_exit_plan_mode(): void
    {
        $result = (new EnterPlanModeTool)->call([], $this->context);
        $this->assertStringContainsString('ExitPlanMode', $result->output);
    }

    // ─── ExitPlanModeTool ─────────────────────────────────────────────────

    public function test_exit_name(): void
    {
        $this->assertSame('ExitPlanMode', (new ExitPlanModeTool)->name());
    }

    public function test_exit_description_mentions_plan(): void
    {
        $this->assertStringContainsString('plan', strtolower((new ExitPlanModeTool)->description()));
    }

    public function test_exit_is_read_only(): void
    {
        $this->assertTrue((new ExitPlanModeTool)->isReadOnly([]));
    }

    public function test_exit_is_concurrency_safe(): void
    {
        $this->assertTrue((new ExitPlanModeTool)->isConcurrencySafe([]));
    }

    public function test_exit_call_returns_success(): void
    {
        $result = (new ExitPlanModeTool)->call([], $this->context);
        $this->assertFalse($result->isError);
    }

    public function test_exit_call_mentions_implement(): void
    {
        $result = (new ExitPlanModeTool)->call([], $this->context);
        $this->assertStringContainsString('implement', strtolower($result->output));
    }

    public function test_exit_call_tells_user_to_run_plan_off(): void
    {
        $result = (new ExitPlanModeTool)->call([], $this->context);
        $this->assertStringContainsString('/plan off', $result->output);
    }
}
