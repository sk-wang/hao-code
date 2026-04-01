<?php

namespace App\Providers;

use App\Tools\ToolRegistry;
use App\Tools\Bash\BashTool;
use App\Tools\FileRead\FileReadTool;
use App\Tools\FileEdit\FileEditTool;
use App\Tools\FileWrite\FileWriteTool;
use App\Tools\Glob\GlobTool;
use App\Tools\Grep\GrepTool;
use App\Tools\TodoWrite\TodoWriteTool;
use App\Tools\AskUserQuestion\AskUserQuestionTool;
use App\Tools\WebFetch\WebFetchTool;
use App\Tools\WebSearch\WebSearchTool;
use App\Tools\PlanMode\EnterPlanModeTool;
use App\Tools\PlanMode\ExitPlanModeTool;
use App\Tools\Lsp\LspTool;
use App\Tools\Agent\AgentTool;
use App\Tools\Skill\SkillTool;
use App\Tools\Notebook\NotebookEditTool;
use App\Tools\Config\ConfigTool;
use App\Tools\Cron\CronCreateTool;
use App\Tools\Cron\CronDeleteTool;
use App\Tools\Cron\CronListTool;
use App\Tools\Worktree\EnterWorktreeTool;
use App\Tools\Worktree\ExitWorktreeTool;
use App\Tools\Task\TaskCreateTool;
use App\Tools\Task\TaskGetTool;
use App\Tools\Task\TaskListTool;
use App\Tools\Task\TaskUpdateTool;
use App\Tools\Task\TaskStopTool;
use App\Tools\Sleep\SleepTool;
use App\Tools\ToolSearch\ToolSearchTool;
use Illuminate\Support\ServiceProvider;

class ToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry();

            // Core tools
            $registry->register($app->make(BashTool::class));
            $registry->register($app->make(FileReadTool::class));
            $registry->register($app->make(FileEditTool::class));
            $registry->register($app->make(FileWriteTool::class));
            $registry->register($app->make(GlobTool::class));
            $registry->register($app->make(GrepTool::class));
            $registry->register($app->make(TodoWriteTool::class));
            $registry->register($app->make(AskUserQuestionTool::class));

            // Web tools
            $registry->register($app->make(WebFetchTool::class));
            $registry->register($app->make(WebSearchTool::class));

            // Agent tools
            $registry->register($app->make(AgentTool::class));
            $registry->register($app->make(SkillTool::class));

            // Code intelligence
            $registry->register($app->make(LspTool::class));

            // Notebook editing
            $registry->register($app->make(NotebookEditTool::class));

            // Plan mode
            $registry->register($app->make(EnterPlanModeTool::class));
            $registry->register($app->make(ExitPlanModeTool::class));

            // Configuration
            $registry->register($app->make(ConfigTool::class));

            // Scheduled tasks
            $registry->register($app->make(CronCreateTool::class));
            $registry->register($app->make(CronDeleteTool::class));
            $registry->register($app->make(CronListTool::class));

            // Worktree
            $registry->register($app->make(EnterWorktreeTool::class));
            $registry->register($app->make(ExitWorktreeTool::class));

            // Task management
            $registry->register($app->make(TaskCreateTool::class));
            $registry->register($app->make(TaskGetTool::class));
            $registry->register($app->make(TaskListTool::class));
            $registry->register($app->make(TaskUpdateTool::class));
            $registry->register($app->make(TaskStopTool::class));

            // Tool search
            $registry->register($app->make(ToolSearchTool::class));

            // Utility
            $registry->register($app->make(SleepTool::class));

            return $registry;
        });
    }
}
