<?php

namespace App\Tools\Agent;

use App\Services\Agent\BackgroundAgentManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class SendMessageTool extends BaseTool
{
    public function __construct(
        private readonly BackgroundAgentManager $backgroundAgentManager,
    ) {}

    public function name(): string
    {
        return 'SendMessage';
    }

    public function description(): string
    {
        return <<<DESC
Send a follow-up message to a background agent started via the Agent tool.

Use the target agent's ID as `to`. Messages are queued and processed by the
background agent in order, preserving its prior context.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type' => 'string',
                    'description' => 'Background agent ID returned by the Agent tool (for example: agent_ab12cd34)',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'The follow-up instruction to deliver to the agent',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Optional short summary shown alongside the queued message',
                ],
            ],
            'required' => ['to', 'message'],
        ], [
            'to' => 'required|string',
            'message' => 'required|string|min:1',
            'summary' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $agent = $this->backgroundAgentManager->get($input['to']);
        if ($agent === null) {
            return ToolResult::error("Background agent not found: {$input['to']}");
        }

        if (in_array($agent['status'] ?? '', ['completed', 'error'], true)) {
            return ToolResult::error("Background agent {$input['to']} is no longer running.");
        }

        $message = $this->backgroundAgentManager->queueMessage(
            id: $input['to'],
            message: $input['message'],
            summary: $input['summary'] ?? null,
            from: $context->sessionId,
        );

        if ($message === null) {
            return ToolResult::error("Failed to queue a message for {$input['to']}");
        }

        $summaryText = ! empty($message['summary']) ? "\nSummary: {$message['summary']}" : '';

        return ToolResult::success(
            "Queued message for {$input['to']}.{$summaryText}\n".
            "Pending messages: {$message['pending_messages']}",
            [
                'agentId' => $input['to'],
                'pendingMessages' => $message['pending_messages'],
            ],
        );
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
