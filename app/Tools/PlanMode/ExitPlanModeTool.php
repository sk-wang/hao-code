<?php

namespace App\Tools\PlanMode;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class ExitPlanModeTool extends BaseTool
{
    public function name(): string
    {
        return 'ExitPlanMode';
    }

    public function description(): string
    {
        return 'Exit plan mode and return to normal implementation mode. The plan has been presented to the user for approval.';
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
        return ToolResult::success(
            "Exiting plan mode. Ready to implement the plan."
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
