<?php

namespace App\Services\Agent;

use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Session\SessionManager;
use Illuminate\Contracts\Container\Container;

class AgentLoopFactory
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function createIsolated(): AgentLoop
    {
        $queryEngine = $this->container->make(QueryEngine::class);
        $toolOrchestrator = $this->container->make(ToolOrchestrator::class);
        $contextBuilder = $this->container->make(ContextBuilder::class);
        $permissionChecker = $this->container->make(\App\Services\Permissions\PermissionChecker::class);
        $toolRegistry = $this->container->make(\App\Tools\ToolRegistry::class);
        $hookExecutor = $this->container->make(HookExecutor::class);

        return new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory(),
            permissionChecker: $permissionChecker,
            sessionManager: new SessionManager(),
            contextCompactor: new ContextCompactor($queryEngine, $hookExecutor),
            costTracker: new CostTracker(),
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );
    }
}
