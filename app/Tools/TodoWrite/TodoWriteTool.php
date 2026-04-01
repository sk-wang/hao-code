<?php

namespace App\Tools\TodoWrite;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TodoWriteTool extends BaseTool
{
    /** @var array<int, array{content: string, status: string, activeForm: string}> */
    private array $todos = [];

    public function name(): string
    {
        return 'TodoWrite';
    }

    public function description(): string
    {
        return <<<DESC
Use this tool to create and manage a structured task list for your current coding session.

Usage notes:
- Use this tool proactively for complex multi-step tasks
- Each task should have both a content (imperative) and activeForm (present continuous) form
- Only ONE task should be in_progress at a time
- Mark tasks complete IMMEDIATELY after finishing
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'todos' => [
                    'type' => 'array',
                    'description' => 'The updated todo list',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string', 'description' => 'What needs to be done'],
                            'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed'], 'description' => 'Task status'],
                            'activeForm' => ['type' => 'string', 'description' => 'Present continuous form'],
                        ],
                        'required' => ['content', 'status', 'activeForm'],
                    ],
                ],
            ],
            'required' => ['todos'],
        ], [
            'todos' => 'required|array',
            'todos.*.content' => 'required|string',
            'todos.*.status' => 'required|string|in:pending,in_progress,completed',
            'todos.*.activeForm' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $this->todos = $input['todos'];

        $output = "Todo list updated:\n";
        foreach ($this->todos as $i => $todo) {
            $num = $i + 1;
            $status = match ($todo['status']) {
                'completed' => '✓',
                'in_progress' => '→',
                default => '○',
            };
            $output .= "  {$status} {$num}. {$todo['content']}\n";
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
