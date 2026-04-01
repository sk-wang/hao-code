<?php

namespace App\Tools\Task;

use App\Services\Task\TaskManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TaskListTool extends BaseTool
{
    public function name(): string { return 'TaskList'; }

    public function description(): string
    {
        return 'List all tasks, optionally filtered by status.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Filter by status: pending, in_progress, completed'],
            ],
        ], ['status' => 'nullable|string']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $manager = app(TaskManager::class);
        $tasks = $manager->list($input['status'] ?? null);

        if (empty($tasks)) {
            return ToolResult::success('No tasks found.');
        }

        $lines = ["Tasks (" . count($tasks) . "):"];
        foreach ($tasks as $task) {
            $age = time() - $task->createdAt;
            $status = $task->status;
            $lines[] = "  {$task->id} [{$status}] {$task->subject} ({$age}s)";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    public function isReadOnly(array $input): bool { return true; }
}
