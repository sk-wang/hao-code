<?php

namespace App\Services\Agent;

use App\Services\Api\ApiErrorException;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Permissions\PermissionChecker;
use App\Services\Session\SessionManager;
use App\Tools\Bash\BashTool;
use App\Tools\ToolRegistry;
use App\Tools\ToolUseContext;

class AgentLoop
{
    private int $maxTurns = 50;
    private bool $aborted = false;
    private bool $sessionStarted = false;
    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;
    private int $totalCacheCreationTokens = 0;
    private int $totalCacheReadTokens = 0;

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

        while ($turnCount < $this->maxTurns && !$this->aborted) {
            $turnCount++;

            if ($onTurnStart) {
                $onTurnStart($turnCount);
            }

            // 1. Auto-compact if context is getting large
            if ($this->contextCompactor->shouldAutoCompact($this->totalInputTokens)) {
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
            );
            $streamingExecutor->setContext($context, $onToolStart, $onToolComplete);

            // 4. Call Anthropic API with streaming — tools execute as they arrive
            $processor = $this->queryEngine->query(
                systemPrompt: $systemPrompt,
                messages: $messages,
                onTextDelta: $onTextDelta,
                onToolBlockComplete: fn(array $block, int $index) =>
                    $streamingExecutor->onToolBlockReady($block, $index),
            );

            // 5. Track usage
            $usage = $processor->getUsage();
            $this->totalInputTokens += $usage['input_tokens'] ?? 0;
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

            // 6. Store assistant response
            $assistantMessage = $processor->toAssistantMessage();
            $this->messageHistory->addAssistantMessage($assistantMessage);

            // 7. Check if we need to execute tools
            if (!$processor->hasToolUse()) {
                $this->hookExecutor?->execute('Stop', [
                    'session_id' => $this->sessionManager->getSessionId(),
                    'turn' => $turnCount,
                ]);
                return $processor->getAccumulatedText();
            }

            // 8. Collect tool results (early-forked safe tools + queued unsafe tools)
            $toolResults = $streamingExecutor->collectResults();

            // 9. Feed tool results back
            $this->messageHistory->addToolResultMessage($toolResults);

            // 10. Check background tasks and append status if any completed
            $bgResults = BashTool::checkAllTasks();
            foreach ($bgResults as $taskId => $bgResult) {
                $toolResults[] = [
                    'tool_use_id' => "bg_check_{$taskId}",
                    'content' => $bgResult->output,
                    'is_error' => $bgResult->isError,
                ];
            }
            if (!empty($bgResults)) {
                $this->messageHistory->addToolResultMessage(array_map(
                    fn($r) => $r->toApiFormat("bg_update_" . array_search($r, $bgResults, true)),
                    $bgResults
                ));
            }

            // 11. Record transcript
            $this->sessionManager->recordTurn($assistantMessage, $toolResults);
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
}
