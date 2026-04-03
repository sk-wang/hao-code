<?php

namespace App\Services\Agent;

use App\Services\Api\ApiErrorException;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Permissions\PermissionChecker;
use App\Services\Session\SessionManager;
use App\Tools\ToolRegistry;
use App\Tools\ToolUseContext;

class AgentLoop
{
    private int $maxTurns = 50;
    private int $maxMalformedToolInputRetries = 4;
    private bool $aborted = false;
    private bool $sessionStarted = false;
    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;
    private int $totalCacheCreationTokens = 0;
    private int $totalCacheReadTokens = 0;
    /** Tracks the most recent API call's input token count for auto-compact decisions. */
    private int $lastTurnInputTokens = 0;

    public function __construct(
        private readonly QueryEngine $queryEngine,
        private readonly ToolOrchestrator $toolOrchestrator,
        private readonly ContextBuilder $contextBuilder,
        private readonly MessageHistory $messageHistory,
        private readonly PermissionChecker $permissionChecker,
        private readonly SessionManager $sessionManager,
        private readonly ContextCompactor $contextCompactor,
        private readonly CostTracker $costTracker,
        private readonly ToolRegistry $toolRegistry,
        private readonly ?HookExecutor $hookExecutor = null,
    ) {}

    public function setPermissionPromptHandler(callable $handler): void
    {
        $this->toolOrchestrator->setPermissionPromptHandler($handler);
    }

    public function abort(): void
    {
        $this->aborted = true;
    }

    public function isAborted(): bool
    {
        return $this->aborted;
    }

    /**
     * Run the agent loop for a user message.
     *
     * @return string The final assistant response text
     */
    public function run(
        string $userInput,
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
    ): string {
        $this->aborted = false;
        $this->messageHistory->addUserMessage($userInput);
        $this->sessionManager->recordEntry([
            'type' => 'user_message',
            'content' => $userInput,
        ]);

        // Fire SessionStart hook on the very first user turn
        if (!$this->sessionStarted) {
            $this->sessionStarted = true;
            $this->hookExecutor?->execute('SessionStart', [
                'session_id' => $this->sessionManager->getSessionId(),
            ]);
        }

        $turnCount = 0;
        $malformedToolInputRetries = 0;

        while ($turnCount < $this->maxTurns && !$this->aborted) {
            $turnCount++;

            if ($onTurnStart) {
                $onTurnStart($turnCount);
            }

            // 1. Auto-compact if context is getting large.
            // Use $lastTurnInputTokens (size of the most recent API call's context), NOT
            // $totalInputTokens (cumulative across all turns). Cumulative tokens only grow,
            // so once the threshold is crossed the auto-compact would otherwise fire on
            // every subsequent turn — even after compaction has already cut the context.
            if ($this->contextCompactor->shouldAutoCompact($this->lastTurnInputTokens)) {
                $this->contextCompactor->compact($this->messageHistory);
            }

            // 2. Build system prompt
            $systemPrompt = $this->contextBuilder->buildSystemPrompt();
            $messages = $this->messageHistory->getMessagesForApi();

            // 3. Set up streaming tool executor for early tool execution
            $streamingExecutor = new StreamingToolExecutor(
                toolOrchestrator: $this->toolOrchestrator,
                toolRegistry: $this->toolRegistry,
            );
            $context = new ToolUseContext(
                workingDirectory: getcwd(),
                sessionId: $this->sessionManager->getSessionId(),
                shouldAbort: fn(): bool => $this->aborted,
            );
            $streamingExecutor->setContext($context, $onToolStart, $onToolComplete);

            try {
                // 4. Call Anthropic API with streaming — tools execute as they arrive
                $processor = $this->queryEngine->query(
                    systemPrompt: $systemPrompt,
                    messages: $messages,
                    onTextDelta: $onTextDelta,
                    onToolBlockComplete: fn(array $block, int $index) =>
                        $this->aborted ? null : $streamingExecutor->onToolBlockReady($block, $index),
                    shouldAbort: fn(): bool => $this->aborted,
                );

                if ($this->aborted) {
                    $streamingExecutor->cleanup();

                    return '(aborted)';
                }

                // 5. Track usage
                $usage = $processor->getUsage();
                $this->lastTurnInputTokens = $usage['input_tokens'] ?? 0;
                $this->totalInputTokens += $this->lastTurnInputTokens;
                $this->totalOutputTokens += $usage['output_tokens'] ?? 0;
                $this->totalCacheCreationTokens += $usage['cache_creation_input_tokens'] ?? 0;
                $this->totalCacheReadTokens += $usage['cache_read_input_tokens'] ?? 0;

                // 5b. Cost tracking
                $this->costTracker->addUsage(
                    $usage['input_tokens'] ?? 0,
                    $usage['output_tokens'] ?? 0,
                    $usage['cache_creation_input_tokens'] ?? 0,
                    $usage['cache_read_input_tokens'] ?? 0,
                );

                if ($this->costTracker->shouldStop()) {
                    $streamingExecutor->cleanup();
                    return "(Cost limit reached: " . $this->costTracker->getSummary() . ")";
                }

                $assistantMessage = $processor->toAssistantMessage();
                $toolUseBlocks = $processor->getIndexedToolUseBlocks();

                // 6. Check if we need to execute tools
                if ($toolUseBlocks === []) {
                    $this->messageHistory->addAssistantMessage($assistantMessage);
                    $this->hookExecutor?->execute('Stop', [
                        'session_id' => $this->sessionManager->getSessionId(),
                        'turn' => $turnCount,
                    ]);
                    return $processor->getAccumulatedText();
                }

                $malformedToolUseErrors = $this->findMalformedToolUseErrors($toolUseBlocks, $context);
                if ($malformedToolUseErrors !== []) {
                    $streamingExecutor->cleanup();

                    if ($malformedToolInputRetries < $this->maxMalformedToolInputRetries) {
                        $malformedToolInputRetries++;
                        $turnCount--;
                        continue;
                    }

                    throw new \RuntimeException(
                        'Model returned malformed tool input repeatedly: ' . implode('; ', $malformedToolUseErrors),
                    );
                }
                $malformedToolInputRetries = 0;

                $this->messageHistory->addAssistantMessage($assistantMessage);

                // Kimi's SSE stream can omit the trailing content_block_stop for the last tool_use block.
                // Reconcile against the finalized assistant message so every tool_use gets a matching tool_result.
                foreach ($toolUseBlocks as $index => $block) {
                    $streamingExecutor->onToolBlockReady($block, $index);
                }

                // 7. Collect tool results (early-forked safe tools + queued unsafe tools)
                $toolResults = $streamingExecutor->collectResults();

                // 8. Feed tool results back
                $this->messageHistory->addToolResultMessage($toolResults);

                // 9. Record transcript
                $this->sessionManager->recordTurn($assistantMessage, $toolResults);
            } catch (\Throwable $e) {
                $streamingExecutor->cleanup();
                throw $e;
            }
        }

        if ($this->aborted) {
            return "(aborted)";
        }

        return "Reached maximum turn limit ({$this->maxTurns}). Stopping.";
    }

    public function getTotalInputTokens(): int
    {
        return $this->totalInputTokens;
    }

    public function getTotalOutputTokens(): int
    {
        return $this->totalOutputTokens;
    }

    public function getEstimatedCost(): float
    {
        return $this->costTracker->getTotalCost();
    }

    public function getCostTracker(): CostTracker
    {
        return $this->costTracker;
    }

    public function getCacheCreationTokens(): int
    {
        return $this->totalCacheCreationTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->totalCacheReadTokens;
    }

    public function getMessageHistory(): MessageHistory
    {
        return $this->messageHistory;
    }

    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }

    /**
     * @param array<int, array{id: string, name: string, input: array}> $toolUseBlocks
     * @return array<int, string>
     */
    private function findMalformedToolUseErrors(array $toolUseBlocks, ToolUseContext $context): array
    {
        $errors = [];

        foreach ($toolUseBlocks as $block) {
            $tool = $this->toolRegistry->getTool($block['name']);
            if ($tool === null) {
                continue;
            }

            $rawInput = $block['input'] ?? [];
            if (!is_array($rawInput)) {
                $errors[] = $block['name'] . ': Tool input must decode to an object.';
                continue;
            }

            try {
                $validatedInput = $tool->inputSchema()->validate($rawInput);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $block['name'] . ': ' . $e->getMessage();
                continue;
            } catch (\TypeError $e) {
                $errors[] = $block['name'] . ': ' . $e->getMessage();
                continue;
            }

            $semanticError = $tool->validateInput($validatedInput, $context);
            if ($semanticError !== null) {
                $errors[] = $block['name'] . ': ' . $semanticError;
            }
        }

        return $errors;
    }
}
