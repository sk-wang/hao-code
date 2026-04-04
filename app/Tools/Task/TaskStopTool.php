<?php

namespace App\Tools\Task;

use App\Services\Agent\BackgroundAgentManager;
use App\Services\Task\TaskManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TaskStopTool extends BaseTool
{
    public function name(): string { return 'TaskStop'; }

    public function description(): string
    {
        return 'Stop a running task by its ID.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The task ID to stop'],
            ],
            'required' => ['id'],
        ], ['id' => 'required|string']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $backgroundAgentManager = app(BackgroundAgentManager::class);
        $agent = $backgroundAgentManager->get($input['id']);

        if ($agent !== null && !in_array($agent['status'] ?? '', ['completed', 'error'], true)) {
            $backgroundAgentManager->requestStop($input['id']);
            app(TaskManager::class)->update($input['id'], 'in_progress', 'Stop requested by user.');

            return ToolResult::success("Stop requested for background agent {$input['id']}.");
        }

        $manager = app(TaskManager::class);
        $task = $manager->stop($input['id']);

        if (!$task) {
            return ToolResult::error("Task not found: {$input['id']}");
        }

        return ToolResult::success("Task {$task->id} stopped.");
    }

    public function isReadOnly(array $input): bool { return false; }
}
