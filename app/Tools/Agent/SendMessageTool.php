<?php

namespace App\Tools\Agent;

use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\TeamManager;
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
Send a follow-up message to a background agent or broadcast to an entire team.

Use the target agent's ID as `to`. Messages are queued and processed by the
background agent in order, preserving its prior context.

To broadcast to all running members of a team, use `to: "team:<team_name>"`.
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
        $to = $input['to'];

        // Team broadcast: "team:myteam"
        if (str_starts_with($to, 'team:')) {
            return $this->broadcastToTeam(
                teamName: substr($to, 5),
                message: $input['message'],
                summary: $input['summary'] ?? null,
                context: $context,
            );
        }

        $agent = $this->backgroundAgentManager->get($to);
        if ($agent === null) {
            return ToolResult::error("Background agent not found: {$to}");
        }

        if (in_array($agent['status'] ?? '', ['completed', 'error'], true)) {
            return ToolResult::error("Background agent {$to} is no longer running.");
        }

        $message = $this->backgroundAgentManager->queueMessage(
            id: $to,
            message: $input['message'],
            summary: $input['summary'] ?? null,
            from: $context->sessionId,
        );

        if ($message === null) {
            return ToolResult::error("Failed to queue a message for {$to}");
        }

        $summaryText = ! empty($message['summary']) ? "\nSummary: {$message['summary']}" : '';

        return ToolResult::success(
            "Queued message for {$to}.{$summaryText}\n".
            "Pending messages: {$message['pending_messages']}",
            [
                'agentId' => $to,
                'pendingMessages' => $message['pending_messages'],
            ],
        );
    }

    private function broadcastToTeam(
        string $teamName,
        string $message,
        ?string $summary,
        ToolUseContext $context,
    ): ToolResult {
        /** @var TeamManager $teamManager */
        $teamManager = app(TeamManager::class);
        $team = $teamManager->get($teamName);

        if ($team === null) {
            return ToolResult::error("Team not found: {$teamName}");
        }

        $sent = 0;
        $skipped = 0;

        foreach ($team['members'] ?? [] as $member) {
            $agentId = $member['agent_id'];
            $agent = $this->backgroundAgentManager->get($agentId);

            if ($agent === null || in_array($agent['status'] ?? '', ['completed', 'error'], true)) {
                $skipped++;

                continue;
            }

            $result = $this->backgroundAgentManager->queueMessage(
                id: $agentId,
                message: $message,
                summary: $summary,
                from: $context->sessionId,
            );

            if ($result !== null) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        $total = count($team['members'] ?? []);

        return ToolResult::success(
            "Broadcast to team '{$teamName}': {$sent}/{$total} delivered, {$skipped} skipped.",
            ['teamName' => $teamName, 'sent' => $sent, 'skipped' => $skipped],
        );
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
