<?php

namespace Tests\Unit;

use App\Services\Agent\AgentLoopFactory;
use App\Services\Agent\ContextBuilder;
use App\Services\Agent\QueryEngine;
use App\Services\Agent\ToolOrchestrator;
use App\Services\Hooks\HookExecutor;
use App\Services\Permissions\PermissionChecker;
use Illuminate\Contracts\Container\Container;
use App\Tools\ToolRegistry;
use Tests\TestCase;

class AgentLoopFactoryTest extends TestCase
{
    public function test_it_creates_isolated_agent_loops(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $permissionChecker = $this->createMock(PermissionChecker::class);
        $toolRegistry = new ToolRegistry();
        $hookExecutor = $this->createMock(HookExecutor::class);

        $container = $this->createMock(Container::class);
        $container->method('make')
            ->willReturnCallback(function (string $abstract) use (
                $queryEngine,
                $toolOrchestrator,
                $contextBuilder,
                $permissionChecker,
                $toolRegistry,
                $hookExecutor,
            ) {
                return match ($abstract) {
                    QueryEngine::class => $queryEngine,
                    ToolOrchestrator::class => $toolOrchestrator,
                    ContextBuilder::class => $contextBuilder,
                    PermissionChecker::class => $permissionChecker,
                    ToolRegistry::class => $toolRegistry,
                    HookExecutor::class => $hookExecutor,
                    default => throw new \RuntimeException("Unexpected container resolution: {$abstract}"),
                };
            });

        $factory = new AgentLoopFactory(
            container: $container,
        );

        $first = $factory->createIsolated();
        $second = $factory->createIsolated();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->getMessageHistory(), $second->getMessageHistory());
        $this->assertNotSame($first->getSessionManager(), $second->getSessionManager());
        $this->assertNotSame($first->getCostTracker(), $second->getCostTracker());
    }
}
