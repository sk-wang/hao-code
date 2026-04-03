<?php

namespace App\Tools\Sleep;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

/**
 * SleepTool — pause execution for N seconds.
 *
 * Mirrors claude-code's SleepTool used in proactive/cron workflows to
 * introduce deliberate delays between automated steps.
 */
class SleepTool extends BaseTool
{
    public function name(): string
    {
        return 'Sleep';
    }

    public function description(): string
    {
        return 'Pause execution for a specified number of seconds. Useful in proactive workflows or scheduled tasks that need to wait before proceeding.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'seconds' => [
                    'type' => 'number',
                    'description' => 'Number of seconds to sleep (1–300).',
                    'minimum' => 1,
                    'maximum' => 300,
                ],
            ],
            'required' => ['seconds'],
        ], [
            'seconds' => 'required|integer|min:1|max:300',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $seconds = (int) ($input['seconds'] ?? 1);
        $seconds = max(1, min(300, $seconds));

        sleep($seconds);

        return ToolResult::success("Slept for {$seconds} second(s).");
    }
}
