<?php

namespace App\Providers;

use App\Services\Api\StreamingClient;
use App\Services\Agent\StreamProcessor;
use App\Services\Git\GitContext;
use App\Services\Agent\AgentLoop;
use App\Services\Agent\QueryEngine;
use App\Services\Agent\ToolOrchestrator;
use App\Services\Agent\ContextBuilder;
use App\Services\Agent\MessageHistory;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Notification\Notifier;
use App\Services\OutputStyle\OutputStyleLoader;
use App\Services\Session\AwaySummaryService;
use App\Services\Session\SessionTitleService;
use App\Services\Memory\SessionMemory;
use App\Services\Permissions\DenialTracker;
use App\Services\FileHistory\FileHistoryManager;
use App\Services\Settings\SettingsManager;
use App\Services\Session\SessionManager;
use App\Services\Permissions\PermissionChecker;
use App\Tools\Skill\SkillLoader;
use App\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsManager::class);
        $this->app->singleton(SessionManager::class);

        $this->app->singleton(SessionTitleService::class, function ($app) {
            $settings = $app->make(SettingsManager::class);
            return new SessionTitleService(
                apiKey: $settings->getApiKey(),
                baseUrl: $settings->getBaseUrl(),
            );
        });

        $this->app->singleton(AwaySummaryService::class, function ($app) {
            $settings = $app->make(SettingsManager::class);
            return new AwaySummaryService(
                apiKey: $settings->getApiKey(),
                baseUrl: $settings->getBaseUrl(),
            );
        });
        $this->app->singleton(DenialTracker::class);
        $this->app->singleton(PermissionChecker::class);
        $this->app->singleton(HookExecutor::class);
        $this->app->singleton(OutputStyleLoader::class);
        $this->app->singleton(SessionMemory::class);

        $this->app->singleton(Notifier::class, function ($app) {
            return new Notifier(
                channel: null, // auto-detect
                hookExecutor: $app->make(HookExecutor::class),
            );
        });
        $this->app->singleton(SkillLoader::class);
        $this->app->singleton(CostTracker::class);
        $this->app->singleton(Notifier::class);
        $this->app->singleton(\App\Services\FileHistory\FileHistoryManager::class);
        $this->app->singleton(\App\Services\Task\TaskManager::class);
        $this->app->singleton(GitContext::class);

        // Register ContextBuilder with its dependencies
        $this->app->singleton(ContextBuilder::class, function ($app) {
            return new ContextBuilder(
                settings: $app->make(SettingsManager::class),
                toolRegistry: $app->make(ToolRegistry::class),
                sessionMemory: $app->make(SessionMemory::class),
                skillLoader: $app->make(SkillLoader::class),
                gitContext: $app->make(GitContext::class),
                outputStyleLoader: $app->make(OutputStyleLoader::class),
            );
        });

        $this->app->singleton(StreamingClient::class, function ($app) {
            $settings = $app->make(SettingsManager::class);
            return new StreamingClient(
                apiKey: $settings->getApiKey(),
                model: $settings->getModel(),
                baseUrl: $settings->getBaseUrl(),
                maxTokens: $settings->getMaxTokens(),
                thinkingEnabled: (bool) env('HAOCODE_THINKING', false),
                thinkingBudget: (int) env('HAOCODE_THINKING_BUDGET', 10000),
            );
        });

        $this->app->singleton(MessageHistory::class);

        $this->app->singleton(AgentLoop::class, function ($app) {
            return new AgentLoop(
                queryEngine: $app->make(QueryEngine::class),
                toolOrchestrator: $app->make(ToolOrchestrator::class),
                contextBuilder: $app->make(ContextBuilder::class),
                messageHistory: $app->make(MessageHistory::class),
                permissionChecker: $app->make(PermissionChecker::class),
                sessionManager: $app->make(SessionManager::class),
                contextCompactor: $app->make(ContextCompactor::class),
                costTracker: $app->make(CostTracker::class),
                toolRegistry: $app->make(ToolRegistry::class),
                hookExecutor: $app->make(HookExecutor::class),
            );
        });
    }
}
