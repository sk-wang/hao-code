<?php

namespace App\Tools\Agent;

use App\Services\Agent\AgentLoopFactory;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class AgentTool extends BaseTool
{
    public function __construct(
        private readonly AgentLoopFactory $agentLoopFactory,
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
            return $this->runInBackground($prompt, $systemPrompt, $context);
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

    private function runInBackground(string $prompt, array $systemPrompt, ToolUseContext $context): ToolResult
    {
        $taskId = 'agent_' . bin2hex(random_bytes(4));

        // Fork a child process to run the agent
        if (!function_exists('pcntl_fork')) {
            return $this->runSync($prompt, $systemPrompt, $context);
        }

        $outFile = sys_get_temp_dir() . '/haocode_agent_' . $taskId . '.out';
        $pid = pcntl_fork();

        if ($pid === -1) {
            return $this->runSync($prompt, $systemPrompt, $context);
        }

        if ($pid === 0) {
            // Child process
            try {
                $subLoop = $this->agentLoopFactory->createIsolated();
                $subLoop->setPermissionPromptHandler(fn() => true);
                $result = $subLoop->run(userInput: $this->buildSubAgentPrompt($prompt, $systemPrompt));
                file_put_contents($outFile, json_encode([
                    'status' => 'completed',
                    'result' => $result,
                    'tokens' => [
                        'input' => $subLoop->getTotalInputTokens(),
                        'output' => $subLoop->getTotalOutputTokens(),
                    ],
                    'cost' => $subLoop->getEstimatedCost(),
                ], JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                file_put_contents($outFile, json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ]));
            }
            exit(0);
        }

        return ToolResult::success("Background agent started: {$taskId} (PID: {$pid})\nPrompt: {$prompt}\nCheck status with /tasks", [
            'taskId' => $taskId,
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
}
