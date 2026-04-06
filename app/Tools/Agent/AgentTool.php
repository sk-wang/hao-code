<?php

namespace App\Tools\Agent;

use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\AgentLoopFactory;
use App\Services\Task\TaskManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class AgentTool extends BaseTool
{
    public function __construct(
        private readonly AgentLoopFactory $agentLoopFactory,
        private readonly ?BackgroundAgentManager $backgroundAgentManager = null,
        private readonly ?TaskManager $taskManager = null,
    ) {}

    public function name(): string
    {
        return 'Agent';
    }

    public function description(): string
    {
        $agentDescriptions = BuiltInAgents::descriptionBlock();

        return <<<DESC
Launch a specialized sub-agent to handle a specific task autonomously.

Available agent types:
{$agentDescriptions}

The sub-agent runs in isolation with its own context and returns a final result.
Use agents to parallelize work, keep the main context clean, or delegate focused tasks.

Usage notes:
- Always include a short description (3-5 words) summarizing what the agent will do
- Launch multiple agents concurrently whenever possible, to maximize performance
- When the agent is done, it will return a single message back to you
- You can optionally run agents in the background using the run_in_background parameter
- To continue a previously spawned agent, use SendMessage with the agent's ID or name
- Provide clear, detailed prompts so the agent can work autonomously
- Set isolation: "worktree" to run the agent in an isolated git worktree copy
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        // Build enum from all known agent types
        $agentTypes = array_keys(AgentLoader::loadAll(getcwd()));

        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The task for the agent to perform',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'A short (3-5 word) description of the task',
                ],
                'subagent_type' => [
                    'type' => 'string',
                    'description' => 'The type of specialized agent to use for this task',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional model override for this agent (sonnet, opus, haiku)',
                    'enum' => ['sonnet', 'opus', 'haiku'],
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to run this agent in the background',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional name for addressing via SendMessage',
                ],
                'isolation' => [
                    'type' => 'string',
                    'description' => 'Isolation mode. "worktree" creates a temporary git worktree.',
                    'enum' => ['worktree'],
                ],
            ],
            'required' => ['description', 'prompt'],
        ], [
            'prompt' => 'required|string|min:5',
            'description' => 'required|string',
            'subagent_type' => 'nullable|string',
            'model' => 'nullable|string|in:sonnet,opus,haiku',
            'run_in_background' => 'nullable|boolean',
            'name' => 'nullable|string',
            'isolation' => 'nullable|string|in:worktree',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $prompt = $input['prompt'];
        $agentTypeName = $input['subagent_type'] ?? 'general-purpose';

        // Resolve agent definition
        $allAgents = AgentLoader::loadAll($context->workingDirectory);
        $agentDef = $allAgents[$agentTypeName] ?? BuiltInAgents::get('general-purpose');

        // Check if agent should always run in background
        $background = $input['run_in_background'] ?? $agentDef->background;

        // Build system prompt from definition
        $systemPrompt = $this->buildSystemPrompt($agentDef);

        // Handle worktree isolation
        $worktreePath = null;
        $worktreeBranch = null;
        if (($input['isolation'] ?? null) === 'worktree') {
            $worktreeResult = $this->createWorktree($context->workingDirectory);
            if ($worktreeResult instanceof ToolResult) {
                return $worktreeResult; // Error creating worktree
            }
            $worktreePath = $worktreeResult['path'];
            $worktreeBranch = $worktreeResult['branch'];
        }

        if ($background) {
            return $this->runInBackground(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                agentDef: $agentDef,
                description: $input['description'] ?? null,
                name: $input['name'] ?? null,
                context: $context,
                worktreePath: $worktreePath,
            );
        }

        $result = $this->runSync($prompt, $systemPrompt, $agentDef, $context, $worktreePath);

        // Clean up worktree if no changes were made
        if ($worktreePath !== null) {
            $hasChanges = $this->worktreeHasChanges($worktreePath);
            if (!$hasChanges) {
                $this->cleanupWorktree($worktreePath);
            } else {
                $result = ToolResult::success(
                    $result->output . "\n\nWorktree with changes at: {$worktreePath} (branch: {$worktreeBranch})",
                    $result->metadata,
                );
            }
        }

        return $result;
    }

    private function runSync(
        string $prompt,
        array $systemPrompt,
        AgentDefinition $agentDef,
        ToolUseContext $context,
        ?string $worktreePath = null,
    ): ToolResult {
        try {
            $subLoop = $this->agentLoopFactory->createIsolated(
                toolFilter: fn(string $toolName) => $agentDef->isToolAllowed($toolName),
                workingDirectory: $worktreePath,
            );
            // Sub-agents don't prompt for permissions
            $subLoop->setPermissionPromptHandler(fn() => true);

            if ($agentDef->maxTurns !== null) {
                $subLoop->setMaxTurns($agentDef->maxTurns);
            }

            $result = $subLoop->run(
                userInput: $this->buildSubAgentPrompt($prompt, $systemPrompt),
                onTextDelta: null,
            );

            return ToolResult::success($result, [
                'inputTokens' => $subLoop->getTotalInputTokens(),
                'outputTokens' => $subLoop->getTotalOutputTokens(),
                'cost' => $subLoop->getEstimatedCost(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error("Sub-agent error: " . $e->getMessage());
        }
    }

    private function runInBackground(
        string $prompt,
        array $systemPrompt,
        AgentDefinition $agentDef,
        ?string $description,
        ?string $name,
        ToolUseContext $context,
        ?string $worktreePath = null,
    ): ToolResult {
        if (!function_exists('pcntl_fork')) {
            return $this->runSync($prompt, $systemPrompt, $agentDef, $context, $worktreePath);
        }

        $taskId = $name ?? ('agent_' . bin2hex(random_bytes(4)));
        $subject = $description ?: ucfirst($agentDef->agentType) . ' background agent';

        $this->tasks()->createWithId(
            id: $taskId,
            subject: $subject,
            activeForm: 'Running background agent',
            description: $prompt,
        );

        $this->backgroundAgents()->create(
            id: $taskId,
            prompt: $prompt,
            agentType: $agentDef->agentType,
            description: $description,
        );

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->tasks()->remove($taskId);
            $this->backgroundAgents()->delete($taskId);

            return $this->runSync($prompt, $systemPrompt, $agentDef, $context, $worktreePath);
        }

        if ($pid === 0) {
            try {
                $this->executeBackgroundAgent($taskId, $prompt, $systemPrompt, $agentDef, $worktreePath);
            } catch (\Throwable $e) {
                $this->backgroundAgents()->markError($taskId, $e->getMessage());
                $this->tasks()->update($taskId, 'completed', 'Background agent error: ' . $e->getMessage());
            }
            exit(0);
        }

        $this->backgroundAgents()->attachProcess($taskId, $pid);
        $this->tasks()->update($taskId, 'in_progress', 'Background agent is running.');

        return ToolResult::success(
            "Background agent started: {$taskId} (PID: {$pid})\n" .
            "Type: {$agentDef->agentType}\n" .
            "Prompt: {$prompt}\n" .
            "Use SendMessage with `to: {$taskId}` to continue it.\n" .
            "Use TaskGet or /tasks to inspect it.",
            [
                'taskId' => $taskId,
                'agentId' => $taskId,
                'pid' => $pid,
            ],
        );
    }

    private function buildSystemPrompt(AgentDefinition $agentDef): array
    {
        return [[
            'type' => 'text',
            'text' => $agentDef->systemPrompt,
        ]];
    }

    public function isReadOnly(array $input): bool
    {
        $agentType = $input['subagent_type'] ?? 'general-purpose';
        $agentDef = BuiltInAgents::get($agentType);

        return $agentDef?->readOnly ?? false;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return $this->isReadOnly($input) && ($input['run_in_background'] ?? false) === true;
    }

    public function userFacingName(array $input): string
    {
        $name = $input['description'] ?? null;
        if (empty($name)) {
            $name = $input['subagent_type'] ?? 'Agent';
        }

        return $name . ' agent';
    }

    private function buildSubAgentPrompt(string $prompt, array $systemPrompt): string
    {
        $instruction = trim(implode("\n\n", array_map(
            fn(array $block) => (string) ($block['text'] ?? ''),
            array_filter($systemPrompt, fn(array $block) => ($block['type'] ?? '') === 'text'),
        )));

        if ($instruction === '') {
            return $prompt;
        }

        return $instruction . "\n\nTask:\n" . $prompt;
    }

    private function executeBackgroundAgent(
        string $taskId,
        string $prompt,
        array $systemPrompt,
        AgentDefinition $agentDef,
        ?string $worktreePath = null,
    ): void {
        $subLoop = $this->agentLoopFactory->createIsolated(
            toolFilter: fn(string $toolName) => $agentDef->isToolAllowed($toolName),
            workingDirectory: $worktreePath,
        );
        $subLoop->setPermissionPromptHandler(fn() => true);

        if ($agentDef->maxTurns !== null) {
            $subLoop->setMaxTurns($agentDef->maxTurns);
        }

        $initialPrompt = $this->buildSubAgentPrompt($prompt, $systemPrompt);
        $this->backgroundAgents()->markRunning($taskId);
        $this->tasks()->update($taskId, 'in_progress', 'Background agent is processing its initial task.');

        $lastResponse = $this->runBackgroundTurn($subLoop, $taskId, $initialPrompt);
        $idleSince = time();
        $idleTimeout = max(30, (int) config('haocode.background_agent_idle_timeout', 300));
        $pollMicros = max(100_000, ((int) config('haocode.background_agent_poll_interval_ms', 250)) * 1000);

        while (true) {
            if ($this->backgroundAgents()->isStopRequested($taskId)) {
                $stopMessage = 'Background agent stopped by user.';
                if ($lastResponse !== null && trim($lastResponse) !== '') {
                    $stopMessage .= "\n\nLast response:\n" . $this->truncateResult($lastResponse, 4000);
                }

                $this->backgroundAgents()->markCompleted($taskId, $lastResponse ?? $stopMessage);
                $this->tasks()->update($taskId, 'completed', $stopMessage);

                return;
            }

            $message = $this->backgroundAgents()->popNextMessage($taskId);
            if ($message !== null) {
                $idleSince = time();
                $response = $this->runBackgroundTurn(
                    $subLoop,
                    $taskId,
                    $this->buildMailboxPrompt($message),
                );
                if ($response !== null) {
                    $lastResponse = $response;
                }

                continue;
            }

            if ((time() - $idleSince) >= $idleTimeout) {
                $finalMessage = 'Background agent finished after waiting for follow-up messages.';
                if ($lastResponse !== null && trim($lastResponse) !== '') {
                    $finalMessage .= "\n\nLast response:\n" . $this->truncateResult($lastResponse, 4000);
                }

                $this->backgroundAgents()->markCompleted($taskId, $lastResponse ?? $finalMessage);
                $this->tasks()->update($taskId, 'completed', $finalMessage);

                return;
            }

            usleep($pollMicros);
        }
    }

    private function runBackgroundTurn(object $subLoop, string $taskId, string $prompt): ?string
    {
        $response = $subLoop->run(userInput: $prompt, onTextDelta: null);

        if ($response === '(aborted)') {
            return null;
        }

        $preview = $this->truncateResult($response, 4000);
        $this->backgroundAgents()->recordResult($taskId, $preview);
        $this->tasks()->update($taskId, 'in_progress', $preview);

        return $response;
    }

    private function buildMailboxPrompt(array $message): string
    {
        $header = 'Follow-up instruction received';
        if (!empty($message['from'])) {
            $header .= " from {$message['from']}";
        }
        if (!empty($message['summary'])) {
            $header .= " ({$message['summary']})";
        }

        return $header . ":\n" . trim((string) ($message['message'] ?? ''));
    }

    private function truncateResult(string $result, int $limit): string
    {
        if (mb_strlen($result) <= $limit) {
            return $result;
        }

        return mb_substr($result, 0, $limit) . "\n\n[Result truncated]";
    }

    // ─── Worktree support ───────────────────────────────────────────

    /**
     * @return array{path: string, branch: string}|ToolResult
     */
    private function createWorktree(string $projectDir): array|ToolResult
    {
        $inGit = trim(shell_exec("cd " . escapeshellarg($projectDir) . " && git rev-parse --is-inside-work-tree 2>/dev/null") ?? '');
        if ($inGit !== 'true') {
            return ToolResult::error("Cannot create worktree: not a git repository.");
        }

        $branch = 'agent-' . bin2hex(random_bytes(4));
        $worktreeDir = $projectDir . '/.claude/worktrees/' . $branch;

        $cmd = "cd " . escapeshellarg($projectDir)
            . " && mkdir -p " . escapeshellarg(dirname($worktreeDir))
            . " && git worktree add -b " . escapeshellarg($branch)
            . " " . escapeshellarg($worktreeDir) . " HEAD 2>&1";

        $output = shell_exec($cmd);

        if (!is_dir($worktreeDir)) {
            return ToolResult::error("Failed to create worktree: " . ($output ?? 'unknown error'));
        }

        return ['path' => $worktreeDir, 'branch' => $branch];
    }

    private function worktreeHasChanges(string $worktreePath): bool
    {
        $status = trim(shell_exec("cd " . escapeshellarg($worktreePath) . " && git status --porcelain 2>/dev/null") ?? '');

        return $status !== '';
    }

    private function cleanupWorktree(string $worktreePath): void
    {
        $parent = dirname($worktreePath, 3); // .claude/worktrees/<branch> -> project
        shell_exec("cd " . escapeshellarg($parent) . " && git worktree remove " . escapeshellarg($worktreePath) . " --force 2>/dev/null");
    }

    private function backgroundAgents(): BackgroundAgentManager
    {
        return $this->backgroundAgentManager ?? app(BackgroundAgentManager::class);
    }

    private function tasks(): TaskManager
    {
        return $this->taskManager ?? app(TaskManager::class);
    }
}
