<?php

namespace Tests\Unit;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\AgentLoopFactory;
use App\Tools\Agent\AgentTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class AgentToolTest extends TestCase
{
    private function makeFactory(?AgentLoop $loop = null): AgentLoopFactory
    {
        $factory = $this->createMock(AgentLoopFactory::class);
        if ($loop !== null) {
            $factory->method('createIsolated')->willReturn($loop);
        }
        return $factory;
    }

    private function makeLoop(string $result = 'result'): AgentLoop
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willReturn($result);
        $loop->method('getTotalInputTokens')->willReturn(0);
        $loop->method('getTotalOutputTokens')->willReturn(0);
        $loop->method('getEstimatedCost')->willReturn(0.0);
        return $loop;
    }

    private function context(): ToolUseContext
    {
        return new ToolUseContext('/tmp', 'test');
    }

    // ─── success path ─────────────────────────────────────────────────────

    public function test_returns_sub_agent_output(): void
    {
        $tool = new AgentTool($this->makeFactory($this->makeLoop('answer from agent')));
        $result = $tool->call(['prompt' => 'Do something useful'], $this->context());
        $this->assertFalse($result->isError);
        $this->assertSame('answer from agent', $result->output);
    }

    // ─── error handling ───────────────────────────────────────────────────

    public function test_sub_agent_exception_returns_error(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willThrowException(new \RuntimeException('sub crashed'));

        $tool = new AgentTool($this->makeFactory($loop));
        $result = $tool->call(['prompt' => 'Do something'], $this->context());
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('sub crashed', $result->output);
    }

    // ─── metadata ─────────────────────────────────────────────────────────

    public function test_metadata_contains_token_counts_and_cost(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('run')->willReturn('ok');
        $loop->method('getTotalInputTokens')->willReturn(100);
        $loop->method('getTotalOutputTokens')->willReturn(50);
        $loop->method('getEstimatedCost')->willReturn(0.005);

        $tool = new AgentTool($this->makeFactory($loop));
        $result = $tool->call(['prompt' => 'Analyze this'], $this->context());
        $this->assertSame(100, $result->metadata['inputTokens']);
        $this->assertSame(50, $result->metadata['outputTokens']);
        $this->assertSame(0.005, $result->metadata['cost']);
    }

    // ─── default agent type ───────────────────────────────────────────────

    public function test_default_agent_type_is_general_purpose(): void
    {
        $loop = $this->createMock(AgentLoop::class);
        $loop->method('getTotalInputTokens')->willReturn(0);
        $loop->method('getTotalOutputTokens')->willReturn(0);
        $loop->method('getEstimatedCost')->willReturn(0.0);

        $promptPassed = '';
        $loop->method('run')->willReturnCallback(function (string $p) use (&$promptPassed) {
            $promptPassed = $p;
            return 'done';
        });

        $tool = new AgentTool($this->makeFactory($loop));
        $tool->call(['prompt' => 'Fix the bug'], $this->context());

        // general-purpose has no special system prompt prepended — just the prompt
        $this->assertStringContainsString('Fix the bug', $promptPassed);
    }

    // ─── tool metadata ────────────────────────────────────────────────────

    public function test_name_is_agent(): void
    {
        $tool = new AgentTool($this->createMock(AgentLoopFactory::class));
        $this->assertSame('Agent', $tool->name());
    }

    public function test_is_not_read_only(): void
    {
        $tool = new AgentTool($this->createMock(AgentLoopFactory::class));
        $this->assertFalse($tool->isReadOnly([]));
    }

    // ─── existing test ────────────────────────────────────────────────────

    public function test_it_runs_sub_agents_via_an_isolated_loop_from_the_factory(): void
    {
        $subLoop = $this->createMock(AgentLoop::class);
        $subLoop->expects($this->once())
            ->method('setPermissionPromptHandler');
        $subLoop->expects($this->once())
            ->method('run')
            ->with($this->callback(function (string $prompt): bool {
                return str_contains($prompt, 'You are a fast codebase exploration agent.')
                    && str_contains($prompt, 'Explore this repository');
            }))
            ->willReturn('sub-agent result');
        $subLoop->method('getTotalInputTokens')->willReturn(123);
        $subLoop->method('getTotalOutputTokens')->willReturn(45);
        $subLoop->method('getEstimatedCost')->willReturn(0.0123);

        $factory = $this->createMock(AgentLoopFactory::class);
        $factory->expects($this->once())
            ->method('createIsolated')
            ->willReturn($subLoop);

        $tool = new AgentTool($factory);

        $result = $tool->call([
            'prompt' => 'Explore this repository',
            'subagent_type' => 'Explore',
        ], new ToolUseContext('/tmp', 'session-1'));

        $this->assertFalse($result->isError);
        $this->assertSame('sub-agent result', $result->output);
        $this->assertSame(123, $result->metadata['inputTokens'] ?? null);
        $this->assertSame(45, $result->metadata['outputTokens'] ?? null);
        $this->assertSame(0.0123, $result->metadata['cost'] ?? null);
    }
}
