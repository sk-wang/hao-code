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
        return <<<DESC
Launch a specialized sub-agent to handle a specific task autonomously.

Agent types:
- "Explore": Fast agent for searching and understanding the codebase. Use for "find X", "where is Y defined", "how does Z work".
- "Plan": Agent that explores and designs implementation plans. Use for architecture decisions, multi-file changes.
- "general-purpose": Full-featured agent for complex multi-step tasks. Use for implementation, debugging, refactoring.

The sub-agent runs in isolation with its own context and returns a final result.
Use agents to parallelize work, keep the main context clean, or delegate focused tasks.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The task description for the sub-agent',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Short 3-5 word description of what the agent will do',
                ],
                'subagent_type' => [
                    'type' => 'string',
                    'description' => 'Agent type: "Explore", "Plan", "general-purpose"',
                    'enum' => ['Explore', 'Plan', 'general-purpose'],
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional model override for the sub-agent',
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Run the agent in the background',
                ],
            ],
            'required' => ['prompt'],
        ], [
            'prompt' => 'required|string|min:5',
            'description' => 'nullable|string',
            'subagent_type' => 'nullable|string|in:Explore,Plan,general-purpose',
            'model' => 'nullable|string',
            'run_in_background' => 'nullable|boolean',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $prompt = $input['prompt'];
        $agentType = $input['subagent_type'] ?? 'general-purpose';
        $background = $input['run_in_background'] ?? false;

        $systemPrompt = $this->getSystemPrompt($agentType);

        if ($background) {
            return $this->runInBackground(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                agentType: $agentType,
                description: $input['description'] ?? null,
                context: $context,
            );
        }

        return $this->runSync($prompt, $systemPrompt, $context);
    }

    private function runSync(string $prompt, array $systemPrompt, ToolUseContext $context): ToolResult
    {
        try {
            $subLoop = $this->agentLoopFactory->createIsolated();
            // Sub-agents don't prompt for permissions
            $subLoop->setPermissionPromptHandler(fn() => true);

            $result = $subLoop->run(
                userInput: $this->buildSubAgentPrompt($prompt, $systemPrompt),
                onTextDelta: null, // No streaming for sub-agents
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
        string $agentType,
        ?string $description,
        ToolUseContext $context,
    ): ToolResult
    {
        if (!function_exists('pcntl_fork')) {
            return $this->runSync($prompt, $systemPrompt, $context);
        }

        $taskId = 'agent_' . bin2hex(random_bytes(4));
        $subject = $description ?: ucfirst($agentType) . ' background agent';

        $this->tasks()->createWithId(
            id: $taskId,
            subject: $subject,
            activeForm: 'Running background agent',
            description: $prompt,
        );

        $this->backgroundAgents()->create(
            id: $taskId,
            prompt: $prompt,
            agentType: $agentType,
            description: $description,
        );

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->tasks()->remove($taskId);
            $this->backgroundAgents()->delete($taskId);

            return $this->runSync($prompt, $systemPrompt, $context);
        }

        if ($pid === 0) {
            try {
                $this->executeBackgroundAgent($taskId, $prompt, $systemPrompt);
            } catch (\Throwable $e) {
                $this->backgroundAgents()->markError($taskId, $e->getMessage());
                $this->tasks()->update($taskId, 'completed', 'Background agent error: ' . $e->getMessage());
            }
            exit(0);
        }

        $this->backgroundAgents()->attachProcess($taskId, $pid);
        $this->tasks()->update($taskId, 'in_progress', 'Background agent is running.');

        return ToolResult::success("Background agent started: {$taskId} (PID: {$pid})\nPrompt: {$prompt}\nUse SendMessage with `to: {$taskId}` to continue it.\nUse TaskGet or /tasks to inspect it.", [
            'taskId' => $taskId,
            'agentId' => $taskId,
            'pid' => $pid,
        ]);
    }

    private function getSystemPrompt(string $agentType): array
    {
        return match ($agentType) {
            'Explore' => [[
                'type' => 'text',
                'text' => "You are a fast codebase exploration agent. Your job is to search and understand code quickly. Use Glob, Grep, and Read tools to find what's needed. Be thorough but concise. Focus on answering the specific question. Do not modify any files.",
            ]],
            'Plan' => [[
                'type' => 'text',
                'text' => "You are a planning agent. Explore the codebase, understand the architecture, and design a detailed implementation plan. Do not modify any files. Use Read, Glob, Grep to understand the code. Output a clear, step-by-step plan with file paths and specific changes needed.",
            ]],
            default => [[
                'type' => 'text',
                'text' => "You are a general-purpose coding agent. Complete the task autonomously using available tools. Be thorough and handle errors gracefully.",
            ]],
        };
    }

    public function isReadOnly(array $input): bool
    {
        return ($input['subagent_type'] ?? '') === 'Explore' || ($input['subagent_type'] ?? '') === 'Plan';
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
            array_filter($systemPrompt, fn(array $block) => ($block['type'] ?? '') === 'text')
        )));

        if ($instruction === '') {
            return $prompt;
        }

        return $instruction . "\n\nTask:\n" . $prompt;
    }

    private function executeBackgroundAgent(string $taskId, string $prompt, array $systemPrompt): void
    {
        $subLoop = $this->agentLoopFactory->createIsolated();
        $subLoop->setPermissionPromptHandler(fn() => true);

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

    /**
     * @param array<string, mixed> $message
     */
    private function buildMailboxPrompt(array $message): string
    {
        $header = 'Follow-up instruction received';
        if (! empty($message['from'])) {
            $header .= " from {$message['from']}";
        }
        if (! empty($message['summary'])) {
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

    private function backgroundAgents(): BackgroundAgentManager
    {
        return $this->backgroundAgentManager ?? app(BackgroundAgentManager::class);
    }

    private function tasks(): TaskManager
    {
        return $this->taskManager ?? app(TaskManager::class);
    }
}
