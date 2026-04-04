<?php

namespace Tests\Unit;

use App\Services\Agent\StreamingToolExecutor;
use App\Services\Agent\ToolOrchestrator;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class StreamingToolExecutorTest extends TestCase
{
    // ─── helpers ──────────────────────────────────────────────────────────

    private function makeRegistry(bool $readOnly = false, bool $concurrencySafe = false): ToolRegistry
    {
        $registry = new ToolRegistry;
        $tool = new class($readOnly, $concurrencySafe) extends BaseTool {
            public function __construct(private bool $ro, private bool $cs) {}
            public function name(): string { return 'MockTool'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object']); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success('ok'); }
            public function isReadOnly(array $input): bool { return $this->ro; }
            public function isConcurrencySafe(array $input): bool { return $this->cs; }
        };
        $registry->register($tool);
        return $registry;
    }

    private function makeBlock(string $id = 'toolu_1'): array
    {
        return ['id' => $id, 'name' => 'MockTool', 'input' => []];
    }

    private function makeOrchestratorWithResult(array $result): ToolOrchestrator
    {
        $mock = $this->createMock(ToolOrchestrator::class);
        $mock->method('executeToolBlock')->willReturn($result);
        return $mock;
    }

    // ─── no context set — ignores blocks ──────────────────────────────────

    public function test_without_context_blocks_are_ignored(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(),
        );

        $executor->onToolBlockReady($this->makeBlock(), 0);
        $results = $executor->collectResults();
        $this->assertEmpty($results);
    }

    // ─── unsafe tools queued for sequential execution ─────────────────────

    public function test_unsafe_tool_queued_and_executed_in_collect(): void
    {
        $expectedResult = ['tool_use_id' => 'toolu_1', 'content' => 'done', 'is_error' => false];

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturn($expectedResult);

        // Non-read-only tool → always queued
        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry(readOnly: false));
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);

        $executor->onToolBlockReady($this->makeBlock(), 0);
        $results = $executor->collectResults();

        $this->assertCount(1, $results);
        $this->assertSame('done', $results[0]['content']);
    }

    // ─── results sorted by block index ────────────────────────────────────

    public function test_results_sorted_by_original_block_index(): void
    {
        $results = [
            ['tool_use_id' => 'a', 'content' => 'first', 'is_error' => false],
            ['tool_use_id' => 'b', 'content' => 'second', 'is_error' => false],
        ];

        $call = 0;
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->method('executeToolBlock')->willReturnCallback(
            function () use ($results, &$call) {
                return $results[$call++];
            }
        );

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);

        // Register block index 3 first, then index 1
        $executor->onToolBlockReady(['id' => 'b', 'name' => 'MockTool', 'input' => []], 3);
        $executor->onToolBlockReady(['id' => 'a', 'name' => 'MockTool', 'input' => []], 1);
        $out = $executor->collectResults();

        // After ksort: index 1 (2nd executed = 'second') comes before index 3 (1st executed = 'first')
        $this->assertCount(2, $out);
        $this->assertSame('second', $out[0]['content']); // original block index 1
        $this->assertSame('first', $out[1]['content']);  // original block index 3
    }

    // ─── hasEarlyExecutions / earlyExecutionCount ─────────────────────────

    public function test_has_early_executions_false_when_no_forks(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(),
        );
        $this->assertFalse($executor->hasEarlyExecutions());
    }

    // ─── cleanup resets state ─────────────────────────────────────────────

    public function test_cleanup_empties_queued_blocks(): void
    {
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->never())->method('executeToolBlock');

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->cleanup();

        $results = $executor->collectResults();
        $this->assertEmpty($results);
    }

    // ─── on_complete callback passed through for queued blocks ───────────

    public function test_on_complete_passed_to_orchestrator_for_queued_block(): void
    {
        $onComplete = fn(string $n, ToolResult $r) => null;

        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->identicalTo($onComplete),
            )
            ->willReturn(['tool_use_id' => 'toolu_1', 'content' => 'ok', 'is_error' => false]);

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, $onComplete);
        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->collectResults();
    }

    // ─── existing test ─────────────────────────────────────────────────────

    public function test_it_does_not_schedule_the_same_block_twice(): void
    {
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'TestTool';
            }

            public function description(): string
            {
                return 'Test tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => [],
                ]);
            }

            public function call(array $input, ToolUseContext $context): \App\Tools\ToolResult
            {
                return \App\Tools\ToolResult::success('ok');
            }
        });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->once())
            ->method('executeToolBlock')
            ->willReturn([
                'tool_use_id' => 'toolu_123',
                'content' => 'ok',
                'is_error' => false,
            ]);

        $executor = new StreamingToolExecutor($toolOrchestrator, $toolRegistry);
        $executor->setContext(new ToolUseContext('/tmp', 'test-session'), null, null);

        $block = [
            'id' => 'toolu_123',
            'name' => 'TestTool',
            'input' => [],
        ];

        $executor->onToolBlockReady($block, 5);
        $executor->onToolBlockReady($block, 5);

        $results = $executor->collectResults();

        $this->assertCount(1, $results);
        $this->assertSame('toolu_123', $results[0]['tool_use_id']);
    }

    public function test_early_execution_count_returns_count_of_forks(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );

        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock('a'), 0);
        $executor->onToolBlockReady($this->makeBlock('b'), 1);

        $this->assertSame(2, $executor->earlyExecutionCount());
    }

    public function test_early_execution_count_zero_when_no_forks(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(),
        );
        $this->assertSame(0, $executor->earlyExecutionCount());
    }

    public function test_read_only_concurrency_safe_tool_registers_early_execution(): void
    {
        $executor = new StreamingToolExecutor(
            $this->createMock(ToolOrchestrator::class),
            $this->makeRegistry(readOnly: true, concurrencySafe: true),
        );
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);

        $executor->onToolBlockReady($this->makeBlock(), 0);

        // Should register as an early execution (forked or queued depending on pcntl availability)
        $this->assertTrue($executor->hasEarlyExecutions() || $executor->collectResults() !== []);
    }

    public function test_cleanup_prevents_queued_execution(): void
    {
        $orchestrator = $this->createMock(ToolOrchestrator::class);
        $orchestrator->expects($this->never())->method('executeToolBlock');

        $executor = new StreamingToolExecutor($orchestrator, $this->makeRegistry());
        $executor->setContext(new ToolUseContext('/tmp', 'test'), null, null);
        $executor->onToolBlockReady($this->makeBlock(), 0);
        $executor->cleanup();
        $executor->collectResults();
    }
}
