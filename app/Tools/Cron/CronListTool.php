<?php

namespace App\Tools\Cron;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class CronListTool extends BaseTool
{
    public function name(): string { return 'CronList'; }

    public function description(): string
    {
        return 'List all scheduled cron jobs.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => (object) [],
        ], []);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $jobs = CronScheduler::getAllJobs();

        if (empty($jobs)) {
            return ToolResult::success('No scheduled tasks. Use CronCreate to schedule one.');
        }

        $lines = ["Scheduled tasks (" . count($jobs) . "):"];
        foreach ($jobs as $id => $job) {
            $status = ($job['status'] ?? 'active') === 'active' ? 'active' : 'completed';
            $fires = $job['fire_count'] ?? 0;
            $recurring = ($job['recurring'] ?? true) ? 'recurring' : 'one-shot';
            $prompt = mb_substr($job['prompt'], 0, 60);

            $lines[] = "  {$id} [{$status}] [{$recurring}] [{$fires}x fired]";
            $lines[] = "    cron: {$job['cron']}  prompt: {$prompt}";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    public function isReadOnly(array $input): bool { return true; }
    public function isConcurrencySafe(array $input): bool { return true; }
}
