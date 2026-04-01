<?php

namespace App\Tools\Cron;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class CronDeleteTool extends BaseTool
{
    public function name(): string { return 'CronDelete'; }

    public function description(): string
    {
        return 'Cancel a scheduled cron job by its ID.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The job ID to cancel'],
            ],
            'required' => ['id'],
        ], ['id' => 'required|string']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $id = $input['id'];
        $job = CronScheduler::getJob($id);

        if (!$job) {
            return ToolResult::error("Job not found: {$id}");
        }

        CronScheduler::removeJob($id);
        return ToolResult::success("Cancelled job: {$id}\nPrompt was: {$job['prompt']}");
    }

    public function isReadOnly(array $input): bool { return false; }
    public function isConcurrencySafe(array $input): bool { return true; }
}
