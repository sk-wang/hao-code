<?php

namespace App\Tools\Team;

use App\Services\Agent\AgentLoopFactory;
use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\TeamManager;
use App\Services\Task\TaskManager;
use App\Tools\Agent\AgentDefinition;
use App\Tools\Agent\AgentLoader;
use App\Tools\Agent\BuiltInAgents;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class TeamCreateTool extends BaseTool
{
    public function __construct(
        private readonly AgentLoopFactory $agentLoopFactory,
        private readonly TeamManager $teamManager,
        private readonly BackgroundAgentManager $backgroundAgentManager,
        private readonly TaskManager $taskManager,
    ) {}

    public function name(): string
    {
        return 'TeamCreate';
    }

    public function description(): string
    {
        return <<<'DESC'
Create a team of multiple background agents that work together on a shared objective.

Each team member is a background agent with a specific role and prompt. Members can
communicate via SendMessage using their agent IDs (format: {teamName}_{role}).
Use `to: "team:{name}"` with SendMessage to broadcast to all members.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Unique team name (lowercase alphanumeric with hyphens, e.g., "backend-team")',
                ],
                'task' => [
                    'type' => 'string',
                    'description' => 'The overall objective for the team to work on',
                ],
                'members' => [
                    'type' => 'array',
                    'description' => 'Team members to create (max 10)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'role' => [
                                'type' => 'string',
                                'description' => 'Role name (e.g., "architect", "reviewer", "implementer")',
                            ],
                            'agent_type' => [
                                'type' => 'string',
                                'description' => 'Agent type (e.g., "general-purpose", "Plan", "code-reviewer")',
                            ],
                            'prompt' => [
                                'type' => 'string',
                                'description' => 'Role-specific instructions for this member',
                            ],
                            'model' => [
                                'type' => 'string',
                                'description' => 'Optional model override',
                                'enum' => ['sonnet', 'opus', 'haiku'],
                            ],
                        ],
                        'required' => ['role', 'prompt'],
                    ],
                ],
            ],
            'required' => ['name', 'task', 'members'],
        ], [
            'name' => 'required|string|regex:/^[a-z0-9][a-z0-9_-]*$/|max:32',
            'task' => 'required|string|min:5',
            'members' => 'required|array|min:1|max:10',
            'members.*.role' => 'required|string',
            'members.*.agent_type' => 'nullable|string',
            'members.*.prompt' => 'required|string',
            'members.*.model' => 'nullable|string|in:sonnet,opus,haiku',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'];
        $task = $input['task'];
        $members = $input['members'];

        // Check team doesn't already exist
        if ($this->teamManager->get($name) !== null) {
            return ToolResult::error("Team '{$name}' already exists. Delete it first or choose a different name.");
        }

        // Check for duplicate roles
        $roles = array_column($members, 'role');
        $duplicates = array_diff_assoc($roles, array_unique($roles));
        if (!empty($duplicates)) {
            return ToolResult::error('Duplicate roles found: ' . implode(', ', array_unique($duplicates)));
        }

        // Check for agent ID collisions with existing background agents
        foreach ($members as $member) {
            $agentId = TeamManager::memberAgentId($name, $member['role']);
            if ($this->backgroundAgentManager->get($agentId) !== null) {
                return ToolResult::error("Background agent '{$agentId}' already exists. Delete it or choose a different role name.");
            }
        }

        // Persist team manifest
        $team = $this->teamManager->create($name, $members);

        // Build teammate roster for preamble injection
        $roster = $this->buildRoster($team['members']);

        // Spawn each member
        $allAgents = AgentLoader::loadAll($context->workingDirectory);
        $spawned = [];
        $failed = [];

        foreach ($team['members'] as $member) {
            $agentId = $member['agent_id'];
            $agentTypeName = $member['agent_type'] ?? 'general-purpose';
            $agentDef = $allAgents[$agentTypeName] ?? BuiltInAgents::get('general-purpose');

            // Build composite prompt with team context
            $fullPrompt = $this->buildMemberPrompt($member, $name, $task, $roster, $agentDef);

            // Create background agent state
            $this->backgroundAgentManager->create(
                id: $agentId,
                prompt: $fullPrompt,
                agentType: $agentDef->agentType,
                description: "Team '{$name}' member: {$member['role']}",
            );

            // Create task for tracking
            $this->taskManager->createWithId(
                id: $agentId,
                subject: "[{$name}] {$member['role']}",
                activeForm: "Running as team member",
                description: $fullPrompt,
            );

            // Fork the background agent process
            $result = $this->forkMember($agentId, $fullPrompt, $agentDef);
            if ($result['success']) {
                $spawned[] = ['role' => $member['role'], 'agent_id' => $agentId, 'pid' => $result['pid']];
                $this->backgroundAgentManager->attachProcess($agentId, $result['pid']);
                $this->taskManager->update($agentId, 'in_progress', 'Background agent is running.');
            } else {
                $failed[] = ['role' => $member['role'], 'agent_id' => $agentId, 'error' => $result['error']];
                $this->backgroundAgentManager->markError($agentId, $result['error']);
                $this->taskManager->update($agentId, 'completed', 'Failed to spawn: ' . $result['error']);
            }
        }

        // Build result summary
        $lines = ["Team '{$name}' created with " . count($team['members']) . " members.\n"];
        $lines[] = "Objective: {$task}\n";

        if (!empty($spawned)) {
            $lines[] = 'Spawned:';
            foreach ($spawned as $s) {
                $lines[] = "  - {$s['role']} [{$s['agent_id']}] PID {$s['pid']}";
            }
        }

        if (!empty($failed)) {
            $lines[] = "\nFailed to spawn:";
            foreach ($failed as $f) {
                $lines[] = "  - {$f['role']} [{$f['agent_id']}]: {$f['error']}";
            }
        }

        $lines[] = "\nTo message a member: SendMessage with `to: \"{agentId}\"`";
        $lines[] = "To broadcast to all: SendMessage with `to: \"team:{$name}\"`";
        $lines[] = "To inspect: TeamList with `name: \"{$name}\"`";

        return ToolResult::success(
            implode("\n", $lines),
            ['teamName' => $name, 'spawned' => count($spawned), 'failed' => count($failed)],
        );
    }

    /**
     * @param  array<int, array{role: string, agent_id: string, agent_type: string}>  $members
     */
    private function buildRoster(array $members): string
    {
        $lines = [];
        foreach ($members as $m) {
            $lines[] = "- {$m['role']} (agent_id: {$m['agent_id']}, type: {$m['agent_type']})";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function buildMemberPrompt(
        array $member,
        string $teamName,
        string $task,
        string $roster,
        AgentDefinition $agentDef,
    ): string {
        $preamble = <<<PREAMBLE
You are the "{$member['role']}" member of team "{$teamName}".

Your teammates:
{$roster}

Team objective: {$task}

Your role-specific instructions:
{$member['prompt']}
PREAMBLE;

        // Prepend agent definition system prompt if available
        if (trim($agentDef->systemPrompt) !== '') {
            return $agentDef->systemPrompt . "\n\n" . $preamble;
        }

        return $preamble;
    }

    /**
     * @return array{success: bool, pid?: int, error?: string}
     */
    private function forkMember(string $agentId, string $prompt, AgentDefinition $agentDef): array
    {
        if (!function_exists('pcntl_fork')) {
            return ['success' => false, 'error' => 'pcntl_fork not available'];
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return ['success' => false, 'error' => 'pcntl_fork() failed'];
        }

        if ($pid === 0) {
            // Child process: run the background agent
            try {
                $this->executeBackgroundAgent($agentId, $prompt, $agentDef);
            } catch (\Throwable $e) {
                $this->backgroundAgentManager->markError($agentId, $e->getMessage());
                $this->taskManager->update($agentId, 'completed', 'Agent error: ' . $e->getMessage());
            }
            exit(0);
        }

        return ['success' => true, 'pid' => $pid];
    }

    private function executeBackgroundAgent(string $agentId, string $prompt, AgentDefinition $agentDef): void
    {
        $subLoop = $this->agentLoopFactory->createIsolated(
            toolFilter: fn (string $toolName) => $agentDef->isToolAllowed($toolName),
        );
        $subLoop->setPermissionPromptHandler(fn () => true);

        if ($agentDef->maxTurns !== null) {
            $subLoop->setMaxTurns($agentDef->maxTurns);
        }

        $this->backgroundAgentManager->markRunning($agentId);
        $this->taskManager->update($agentId, 'in_progress', 'Processing initial task.');

        $lastResponse = $this->runTurn($subLoop, $agentId, $prompt);
        $idleSince = time();
        $idleTimeout = max(30, (int) config('haocode.background_agent_idle_timeout', 300));
        $pollMicros = max(100_000, ((int) config('haocode.background_agent_poll_interval_ms', 250)) * 1000);

        while (true) {
            if ($this->backgroundAgentManager->isStopRequested($agentId)) {
                $this->backgroundAgentManager->markCompleted($agentId, $lastResponse);
                $this->taskManager->update($agentId, 'completed', 'Stopped.');

                return;
            }

            $message = $this->backgroundAgentManager->popNextMessage($agentId);
            if ($message !== null) {
                $idleSince = time();
                $header = 'Follow-up from ' . ($message['from'] ?? 'controller');
                if (!empty($message['summary'])) {
                    $header .= " ({$message['summary']})";
                }
                $response = $this->runTurn($subLoop, $agentId, $header . ":\n" . trim((string) ($message['message'] ?? '')));
                if ($response !== null) {
                    $lastResponse = $response;
                }

                continue;
            }

            if ((time() - $idleSince) >= $idleTimeout) {
                $this->backgroundAgentManager->markCompleted($agentId, $lastResponse);
                $this->taskManager->update($agentId, 'completed', 'Idle timeout reached.');

                return;
            }

            usleep($pollMicros);
        }
    }

    private function runTurn(object $subLoop, string $agentId, string $prompt): ?string
    {
        $response = $subLoop->run(userInput: $prompt, onTextDelta: null);

        if ($response === '(aborted)') {
            return null;
        }

        $preview = mb_strlen($response) > 4000
            ? mb_substr($response, 0, 4000) . "\n\n[Truncated]"
            : $response;

        $this->backgroundAgentManager->recordResult($agentId, $preview);
        $this->taskManager->update($agentId, 'in_progress', $preview);

        return $response;
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function userFacingName(array $input): string
    {
        return 'Create team ' . ($input['name'] ?? 'agents');
    }
}
