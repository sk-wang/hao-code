<?php

/**
 * PHP-DI container definitions — replaces AgentServiceProvider + ToolServiceProvider.
 *
 * All services are defined as singletons (shared by default in PHP-DI).
 */

use App\Services\Agent\AgentLoop;
use App\Services\Agent\AgentLoopFactory;
use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\ContextBuilder;
use App\Services\Agent\MessageHistory;
use App\Services\Agent\QueryEngine;
use App\Services\Agent\ToolOrchestrator;
use App\Services\Api\StreamingClient;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\FileHistory\FileHistoryManager;
use App\Services\Git\GitContext;
use App\Services\Hooks\HookExecutor;
use App\Services\Mcp\McpConnectionManager;
use App\Services\Mcp\McpServerConfigManager;
use App\Services\Memory\SessionMemory;
use App\Services\Notification\Notifier;
use App\Services\OutputStyle\OutputStyleLoader;
use App\Services\Permissions\DenialTracker;
use App\Services\Permissions\PermissionChecker;
use App\Services\Session\AwaySummaryService;
use App\Services\Session\SessionManager;
use App\Services\Session\SessionTitleService;
use App\Services\Settings\SettingsManager;
use App\Services\Task\TaskManager;
use App\Services\Agent\TeamManager;
use App\Support\Config\Config;
use App\Support\Terminal\PromptHudState;
use App\Tools\Skill\SkillLoader;
use App\Tools\ToolRegistry;
use Psr\Container\ContainerInterface;

use function DI\autowire;
use function DI\create;
use function DI\factory;
use function DI\get;

return [
    // ─── Settings & Config ──────────────────────────────────────
    SettingsManager::class => autowire(),
    SessionManager::class => autowire(),
    DenialTracker::class => autowire(),
    PermissionChecker::class => autowire(),
    HookExecutor::class => autowire(),
    OutputStyleLoader::class => autowire(),
    SessionMemory::class => autowire(),
    PromptHudState::class => autowire(),
    SkillLoader::class => autowire(),
    CostTracker::class => autowire(),
    FileHistoryManager::class => autowire(),
    TaskManager::class => autowire(),
    BackgroundAgentManager::class => autowire(),
    GitContext::class => autowire(),
    McpServerConfigManager::class => autowire(),
    TeamManager::class => autowire(),
    MessageHistory::class => autowire(),

    // ─── Factory-built services ─────────────────────────────────
    SessionTitleService::class => factory(function (ContainerInterface $c) {
        $settings = $c->get(SettingsManager::class);
        return new SessionTitleService(
            apiKey: $settings->getApiKey(),
            baseUrl: $settings->getBaseUrl(),
            settingsManager: $settings,
        );
    }),

    AwaySummaryService::class => factory(function (ContainerInterface $c) {
        $settings = $c->get(SettingsManager::class);
        return new AwaySummaryService(
            apiKey: $settings->getApiKey(),
            baseUrl: $settings->getBaseUrl(),
            settingsManager: $settings,
        );
    }),

    Notifier::class => factory(function (ContainerInterface $c) {
        return new Notifier(
            channel: null,
            hookExecutor: $c->get(HookExecutor::class),
        );
    }),

    McpConnectionManager::class => factory(function (ContainerInterface $c) {
        return new McpConnectionManager(
            configManager: $c->get(McpServerConfigManager::class),
        );
    }),

    ContextBuilder::class => factory(function (ContainerInterface $c) {
        return new ContextBuilder(
            settings: $c->get(SettingsManager::class),
            toolRegistry: $c->get(ToolRegistry::class),
            sessionMemory: $c->get(SessionMemory::class),
            skillLoader: $c->get(SkillLoader::class),
            gitContext: $c->get(GitContext::class),
            outputStyleLoader: $c->get(OutputStyleLoader::class),
        );
    }),

    StreamingClient::class => factory(function (ContainerInterface $c) {
        $settings = $c->get(SettingsManager::class);
        return new StreamingClient(
            apiKey: $settings->getApiKey(),
            model: $settings->getModel(),
            baseUrl: $settings->getBaseUrl(),
            maxTokens: $settings->getMaxTokens(),
            thinkingEnabled: (bool) ($_ENV['HAOCODE_THINKING'] ?? getenv('HAOCODE_THINKING') ?: false),
            thinkingBudget: (int) ($_ENV['HAOCODE_THINKING_BUDGET'] ?? getenv('HAOCODE_THINKING_BUDGET') ?: 10000),
            settingsManager: $settings,
            idleTimeoutSeconds: (int) Config::get('api_stream_idle_timeout', 60),
            streamPollTimeoutSeconds: (float) Config::get('api_stream_poll_timeout', 1.0),
        );
    }),

    QueryEngine::class => autowire(),
    ToolOrchestrator::class => autowire(),
    ContextCompactor::class => autowire(),
    AgentLoopFactory::class => autowire(),

    AgentLoop::class => factory(function (ContainerInterface $c) {
        return new AgentLoop(
            queryEngine: $c->get(QueryEngine::class),
            toolOrchestrator: $c->get(ToolOrchestrator::class),
            contextBuilder: $c->get(ContextBuilder::class),
            messageHistory: $c->get(MessageHistory::class),
            permissionChecker: $c->get(PermissionChecker::class),
            sessionManager: $c->get(SessionManager::class),
            contextCompactor: $c->get(ContextCompactor::class),
            costTracker: $c->get(CostTracker::class),
            toolRegistry: $c->get(ToolRegistry::class),
            hookExecutor: $c->get(HookExecutor::class),
        );
    }),

    // ─── Tool Registry ──────────────────────────────────────────
    ToolRegistry::class => factory(function (ContainerInterface $c) {
        $registry = new ToolRegistry();

        $toolClasses = [
            \App\Tools\Bash\BashTool::class,
            \App\Tools\FileRead\FileReadTool::class,
            \App\Tools\FileEdit\FileEditTool::class,
            \App\Tools\FileWrite\FileWriteTool::class,
            \App\Tools\Glob\GlobTool::class,
            \App\Tools\Grep\GrepTool::class,
            \App\Tools\TodoWrite\TodoWriteTool::class,
            \App\Tools\AskUserQuestion\AskUserQuestionTool::class,
            \App\Tools\WebFetch\WebFetchTool::class,
            \App\Tools\WebSearch\WebSearchTool::class,
            \App\Tools\Agent\AgentTool::class,
            \App\Tools\Agent\SendMessageTool::class,
            \App\Tools\Skill\SkillTool::class,
            \App\Tools\Lsp\LspTool::class,
            \App\Tools\Notebook\NotebookEditTool::class,
            \App\Tools\PlanMode\EnterPlanModeTool::class,
            \App\Tools\PlanMode\ExitPlanModeTool::class,
            \App\Tools\Config\ConfigTool::class,
            \App\Tools\Cron\CronCreateTool::class,
            \App\Tools\Cron\CronDeleteTool::class,
            \App\Tools\Cron\CronListTool::class,
            \App\Tools\Worktree\EnterWorktreeTool::class,
            \App\Tools\Worktree\ExitWorktreeTool::class,
            \App\Tools\Task\TaskCreateTool::class,
            \App\Tools\Task\TaskGetTool::class,
            \App\Tools\Task\TaskListTool::class,
            \App\Tools\Task\TaskUpdateTool::class,
            \App\Tools\Task\TaskStopTool::class,
            \App\Tools\Team\TeamCreateTool::class,
            \App\Tools\Team\TeamListTool::class,
            \App\Tools\Team\TeamDeleteTool::class,
            \App\Tools\ToolSearch\ToolSearchTool::class,
            \App\Tools\Mcp\ListMcpResourcesTool::class,
            \App\Tools\Mcp\ReadMcpResourceTool::class,
            \App\Tools\Sleep\SleepTool::class,
        ];

        foreach ($toolClasses as $class) {
            $registry->register($c->get($class));
        }

        return $registry;
    }),
];
