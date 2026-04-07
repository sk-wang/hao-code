<?php

namespace App\Tools\Team;

use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\TeamManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TeamListTool extends BaseTool
{
    public function __construct(
        private readonly TeamManager $teamManager,
        private readonly BackgroundAgentManager $backgroundAgentManager,
    ) {}

    public function name(): string
    {
        return 'TeamList';
    }

    public function description(): string
    {
        return <<<'DESC'
List all active teams, or show detailed status of a specific team.

Without arguments, shows a summary of all teams. With a team name, shows
each member's role, agent ID, status, and last result preview.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional: show details for a specific team',
                ],
            ],
        ], [
            'name' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'] ?? null;

        if ($name !== null) {
            return $this->showTeamDetail($name);
        }

        return $this->showAllTeams();
    }

    private function showTeamDetail(string $name): ToolResult
    {
        $team = $this->teamManager->get($name);
        if ($team === null) {
            return ToolResult::error("Team not found: {$name}");
        }

        $lines = ["Team: {$name} (created " . $this->ago($team['created_at'] ?? 0) . ')'];
        $members = $team['members'] ?? [];
        $lines[] = 'Members (' . count($members) . '):';

        foreach ($members as $member) {
            $agentId = $member['agent_id'];
            $agent = $this->backgroundAgentManager->get($agentId);
            $status = $this->resolveStatus($agent);
            $pending = $agent['pending_messages'] ?? 0;
            $pendingLabel = $pending > 0 ? "{$pending} msgs queued" : 'idle';

            $line = "  {$member['role']} [{$agentId}] {$status} · {$pendingLabel}";

            $lastResult = $agent['last_result'] ?? null;
            if ($lastResult !== null && trim($lastResult) !== '') {
                $preview = mb_substr(trim(str_replace("\n", ' ', $lastResult)), 0, 80);
                $line .= "\n    last: \"{$preview}\"";
            }

            $lines[] = $line;
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function showAllTeams(): ToolResult
    {
        $teams = $this->teamManager->list();

        if (empty($teams)) {
            return ToolResult::success('No teams found. Use TeamCreate to create one.');
        }

        $lines = ['Teams (' . count($teams) . '):'];

        foreach ($teams as $team) {
            $members = $team['members'] ?? [];
            $statuses = $this->countStatuses($members);
            $statusParts = [];
            foreach ($statuses as $status => $count) {
                $statusParts[] = "{$count} {$status}";
            }
            $statusStr = implode(', ', $statusParts);

            $lines[] = "  {$team['name']}  " . count($members) . " members · {$statusStr} · created " . $this->ago($team['created_at'] ?? 0);
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param  array<int, array<string, mixed>>  $members
     * @return array<string, int>
     */
    private function countStatuses(array $members): array
    {
        $counts = [];
        foreach ($members as $member) {
            $agent = $this->backgroundAgentManager->get($member['agent_id']);
            $status = $this->resolveStatus($agent);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>|null  $agent
     */
    private function resolveStatus(?array $agent): string
    {
        if ($agent === null) {
            return 'unknown';
        }

        $status = $agent['status'] ?? 'unknown';

        // Check if PID is still alive for running agents
        if ($status === 'running' && !empty($agent['pid'])) {
            if (!posix_kill((int) $agent['pid'], 0)) {
                return 'dead';
            }
        }

        return $status;
    }

    private function ago(int $timestamp): string
    {
        $diff = time() - $timestamp;
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . 'm ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . 'h ago';
        }

        return intdiv($diff, 86400) . 'd ago';
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function userFacingName(array $input): string
    {
        return 'List teams';
    }
}
