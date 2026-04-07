<?php

namespace App\Services\Agent;

use App\Services\Api\StreamingClient;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Session\SessionManager;
use App\Tools\ToolRegistry;
use Illuminate\Contracts\Container\Container;

class AgentLoopFactory
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Create an isolated AgentLoop for sub-agents or SDK usage.
     *
     * @param callable|null $toolFilter If provided, only tools where $toolFilter(toolName) returns true are included
     * @param string|null $workingDirectory Override working directory (e.g., for worktree isolation)
     * @param array<int, \App\Contracts\ToolInterface> $additionalTools Extra tools to register (e.g., SDK custom tools)
     * @param StreamingClient|null $streamingClient Custom API client (e.g., SDK config overrides)
     */
    public function createIsolated(
        ?callable $toolFilter = null,
        ?string $workingDirectory = null,
        array $additionalTools = [],
        ?StreamingClient $streamingClient = null,
    ): AgentLoop {
        $contextBuilder = $this->container->make(ContextBuilder::class);
        $permissionChecker = $this->container->make(\App\Services\Permissions\PermissionChecker::class);
        $hookExecutor = $this->container->make(HookExecutor::class);

        // Build tool registry with optional filtering
        $parentRegistry = $this->container->make(ToolRegistry::class);
        $toolRegistry = $this->buildToolRegistry($parentRegistry, $toolFilter);

        // Use custom StreamingClient if provided, otherwise resolve from container
        if ($streamingClient !== null) {
            $queryEngine = new QueryEngine($streamingClient, $toolRegistry);
        } else {
            $queryEngine = $this->container->make(QueryEngine::class);
        }

        // Register additional tools (SDK custom tools)
        foreach ($additionalTools as $tool) {
            $toolRegistry->register($tool);
        }

        $toolOrchestrator = new ToolOrchestrator(
            toolRegistry: $toolRegistry,
            permissionChecker: $permissionChecker,
            hookExecutor: $hookExecutor,
        );

        $loop = new AgentLoop(
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

        if ($workingDirectory !== null) {
            $loop->setWorkingDirectory($workingDirectory);
        }

        return $loop;
    }

    /**
     * Build a filtered ToolRegistry from the parent registry.
     */
    private function buildToolRegistry(ToolRegistry $parent, ?callable $filter): ToolRegistry
    {
        if ($filter === null) {
            return $parent;
        }

        $filtered = new ToolRegistry();

        foreach ($parent->getAllTools() as $tool) {
            if ($filter($tool->name())) {
                $filtered->register($tool);
            }
        }

        return $filtered;
    }
}
