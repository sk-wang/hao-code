<?php

namespace App\Tools\Team;

use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\TeamManager;
use App\Services\Task\TaskManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TeamDeleteTool extends BaseTool
{
    public function __construct(
        private readonly TeamManager $teamManager,
        private readonly BackgroundAgentManager $backgroundAgentManager,
        private readonly TaskManager $taskManager,
    ) {}

    public function name(): string
    {
        return 'TeamDelete';
    }

    public function description(): string
    {
        return <<<'DESC'
Delete a team, stopping all its running members and cleaning up their state.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name of the team to delete',
                ],
            ],
            'required' => ['name'],
        ], [
            'name' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'];
        $team = $this->teamManager->get($name);

        if ($team === null) {
            return ToolResult::error("Team not found: {$name}");
        }

        $members = $team['members'] ?? [];
        $stopped = [];
        $alreadyStopped = [];

        foreach ($members as $member) {
            $agentId = $member['agent_id'];
            $agent = $this->backgroundAgentManager->get($agentId);

            if ($agent === null) {
                $alreadyStopped[] = $member['role'];

                continue;
            }

            $status = $agent['status'] ?? 'unknown';

            // Signal the agent to stop if it's running
            if (in_array($status, ['running', 'pending'], true)) {
                $this->backgroundAgentManager->requestStop($agentId);

                // Also send SIGTERM if the PID is alive
                $pid = (int) ($agent['pid'] ?? 0);
                if ($pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0)) {
                    posix_kill($pid, SIGTERM);
                }

                $stopped[] = $member['role'];
            } else {
                $alreadyStopped[] = $member['role'];
            }

            // Clean up background agent state and mailbox files
            $this->backgroundAgentManager->delete($agentId);

            // Clean up task entry
            $this->taskManager->update($agentId, 'completed', 'Team deleted.');
            $this->taskManager->remove($agentId);
        }

        // Delete team manifest
        $this->teamManager->delete($name);

        $lines = ["Team '{$name}' deleted."];
        if (!empty($stopped)) {
            $lines[] = 'Stopped: ' . implode(', ', $stopped);
        }
        if (!empty($alreadyStopped)) {
            $lines[] = 'Already stopped: ' . implode(', ', $alreadyStopped);
        }

        return ToolResult::success(implode("\n", $lines));
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function userFacingName(array $input): string
    {
        return 'Delete team ' . ($input['name'] ?? '');
    }
}
