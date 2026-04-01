<?php

namespace App\Tools\Task;

use App\Services\Task\TaskManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TaskUpdateTool extends BaseTool
{
    public function name(): string { return 'TaskUpdate'; }

    public function description(): string
    {
        return 'Update a task status and optional result.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Task ID'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed'], 'description' => 'New status'],
                'result' => ['type' => 'string', 'description' => 'Optional result/output text'],
            ],
            'required' => ['id', 'status'],
        ], [
            'id' => 'required|string',
            'status' => 'required|string|in:pending,in_progress,completed',
            'result' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $manager = app(TaskManager::class);
        $task = $manager->update(
            id: $input['id'],
            status: $input['status'],
            result: $input['result'] ?? null,
        );

        if (!$task) {
            return ToolResult::error("Task not found: {$input['id']}");
        }

        return ToolResult::success("Task {$task->id} updated: status={$task->status}");
    }

    public function isReadOnly(array $input): bool { return false; }
}
