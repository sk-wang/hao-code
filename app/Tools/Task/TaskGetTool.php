<?php

namespace App\Tools\Task;

use App\Services\Task\TaskManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TaskGetTool extends BaseTool
{
    public function name(): string { return 'TaskGet'; }

    public function description(): string
    {
        return 'Get details of a specific task by its ID.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The task ID'],
            ],
            'required' => ['id'],
        ], ['id' => 'required|string']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $manager = app(TaskManager::class);
        $task = $manager->get($input['id']);

        if (!$task) {
            return ToolResult::error("Task not found: {$input['id']}");
        }

        $age = time() - $task->createdAt;
        return ToolResult::success(
            "Task: {$task->id}\n" .
            "Subject: {$task->subject}\n" .
            "Status: {$task->status}\n" .
            "Active: {$task->activeForm}\n" .
            "Age: {$age}s\n" .
            ($task->description ? "Description: {$task->description}\n" : '') .
            ($task->result ? "Result: {$task->result}\n" : '')
        );
    }

    public function isReadOnly(array $input): bool { return true; }
}
