<?php

namespace App\Tools\PlanMode;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class EnterPlanModeTool extends BaseTool
{
    public function name(): string
    {
        return 'EnterPlanMode';
    }

    public function description(): string
    {
        return 'Enter plan mode. In plan mode, the agent explores the codebase and designs an implementation approach without making changes. Use this when you need to plan before implementing.';
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
        // Signal to the agent loop that we're entering plan mode
        // This is handled via the permission system
        return ToolResult::success(
            "Entering plan mode. I will explore the codebase and design an implementation plan without making changes. " .
            "Use ExitPlanMode when ready to implement."
        );
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}
