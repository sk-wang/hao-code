<?php

namespace App\Services\Agent;

use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Permissions\PermissionChecker;
use App\Services\Session\SessionManager;
use App\Services\ToolResult\ToolResultStorage;
use App\Tools\ToolRegistry;
use App\Tools\ToolUseContext;

class AgentLoop
{
    private int $maxTurns = 50;

    private int $maxMalformedToolInputRetries = 4;

    private int $maxIncompleteResponseRetries = 2;

    private bool $aborted = false;

    private bool $sessionStarted = false;

    private int $totalInputTokens = 0;

    private int $totalOutputTokens = 0;

    private int $totalCacheCreationTokens = 0;

    private int $totalCacheReadTokens = 0;

    /** Tracks the most recent API call's input token count for auto-compact decisions. */
    private int $lastTurnInputTokens = 0;

    private bool $autoTitleGenerated = false;

    private ?string $workingDirectory = null;

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

    public function setMaxTurns(int $maxTurns): void
    {
        $this->maxTurns = $maxTurns;
    }

    public function setWorkingDirectory(string $dir): void
    {
        $this->workingDirectory = $dir;
        $this->sessionManager->setCurrentWorkingDirectory($dir);
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
     * @param  string|array  $userInput  Plain text, or array of content blocks for mixed text+image
     * @return string The final assistant response text
     */
    public function run(
        string|array $userInput,
        ?callable $onTextDelta = null,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
        ?callable $onTurnStart = null,
    ): string {
        $this->aborted = false;
        $this->messageHistory->addUserMessage($userInput);
        $this->sessionManager->recordEntry([
            'type' => 'user_message',
            'content' => is_string($userInput) ? $userInput : '[multi-content message with images]',
        ]);

        // Fire SessionStart hook on the very first user turn
        if (! $this->sessionStarted) {
            $this->sessionStarted = true;

            // Wire up tool result persistence storage
            $toolResultStorage = new ToolResultStorage($this->sessionManager->getSessionId());
            $this->toolOrchestrator->setToolResultStorage($toolResultStorage);

            $this->hookExecutor?->execute('SessionStart', [
                'session_id' => $this->sessionManager->getSessionId(),
            ]);
        }

        $turnCount = 0;
        $malformedToolInputRetries = 0;
        $incompleteResponseRetries = 0;

        while ($turnCount < $this->maxTurns && ! $this->aborted) {
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
            } elseif ($this->contextCompactor->shouldMicroCompact($this->lastTurnInputTokens)) {
                $this->contextCompactor->microCompact($this->messageHistory);
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
                workingDirectory: $this->workingDirectory ?? getcwd(),
                sessionId: $this->sessionManager->getSessionId(),
                shouldAbort: fn (): bool => $this->aborted,
            );
            $streamingExecutor->setContext($context, $onToolStart, $onToolComplete);

            try {
                // 4. Call Anthropic API with streaming — tools execute as they arrive
                $processor = $this->queryEngine->query(
                    systemPrompt: $systemPrompt,
                    messages: $messages,
                    onTextDelta: $onTextDelta,
                    onToolBlockComplete: fn (array $block, int $index) => $this->aborted ? null : $streamingExecutor->onToolBlockReady($block, $index),
                    shouldAbort: fn (): bool => $this->aborted,
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

                // 5b. Cost tracking — set model for per-model pricing
                $responseModel = $processor->getModel();
                if ($responseModel !== null) {
                    $this->costTracker->setModel($responseModel);
                }
                $this->costTracker->addUsage(
                    $usage['input_tokens'] ?? 0,
                    $usage['output_tokens'] ?? 0,
                    $usage['cache_creation_input_tokens'] ?? 0,
                    $usage['cache_read_input_tokens'] ?? 0,
                );

                if ($this->costTracker->shouldStop()) {
                    $streamingExecutor->cleanup();

                    return '(Cost limit reached: '.$this->costTracker->getSummary().')';
                }

                $assistantMessage = $processor->toAssistantMessage();
                $toolUseBlocks = $processor->getIndexedToolUseBlocks();
                $stopReason = $processor->getStopReason();

                // 6. Check if we need to execute tools
                if ($toolUseBlocks === []) {
                    $skipIncompleteAssistantHistory = $this->shouldSkipIncompleteAssistantHistory($assistantMessage);
                    if ($this->shouldRetryIncompleteAssistantResponse(
                        $processor,
                        $assistantMessage,
                        $stopReason,
                        $incompleteResponseRetries,
                    )) {
                        $incompleteResponseRetries++;
                        $this->recordIncompleteAssistantResponse($assistantMessage, $skipIncompleteAssistantHistory);
                        $this->messageHistory->addUserMessage(
                            $this->buildIncompleteResponseRetryInstruction(
                                $stopReason,
                                $incompleteResponseRetries,
                                $skipIncompleteAssistantHistory,
                            ),
                        );
                        $turnCount--;

                        continue;
                    }

                    $incompleteResponseRetries = 0;
                    $this->messageHistory->addAssistantMessage($assistantMessage);
                    $this->sessionManager->recordTurn($assistantMessage, []);
                    $this->hookExecutor?->execute('Stop', [
                        'session_id' => $this->sessionManager->getSessionId(),
                        'turn' => $turnCount,
                    ]);

                    return $processor->getAccumulatedText();
                }

                $malformedToolUseFailures = $this->findMalformedToolUseFailures($toolUseBlocks, $context);
                if ($malformedToolUseFailures !== []) {
                    $streamingExecutor->cleanup();

                    if ($malformedToolInputRetries < $this->maxMalformedToolInputRetries) {
                        $malformedToolInputRetries++;
                        $assistantMessage = $this->sanitizeMalformedToolAssistantMessage(
                            $assistantMessage,
                            $malformedToolUseFailures,
                        );
                        $toolResults = $this->buildMalformedToolRetryResults($malformedToolUseFailures);
                        $this->messageHistory->addAssistantMessage($assistantMessage);
                        $this->messageHistory->addToolResultMessage(
                            $toolResults,
                            $this->buildMalformedToolRetryInstruction(
                                $malformedToolUseFailures,
                                $malformedToolInputRetries,
                            ),
                        );
                        $this->sessionManager->recordTurn($assistantMessage, $toolResults);
                        $turnCount--;

                        continue;
                    }

                    throw new \RuntimeException(
                        'Model returned malformed tool input repeatedly: '.implode(
                            '; ',
                            array_map(
                                fn (array $failure): string => $failure['name'].': '.$failure['error'],
                                $malformedToolUseFailures,
                            ),
                        ),
                    );
                }
                $malformedToolInputRetries = 0;
                $incompleteResponseRetries = 0;

                $this->messageHistory->addAssistantMessage($assistantMessage);

                // Kimi's SSE stream can omit the trailing content_block_stop for the last tool_use block.
                // Reconcile against the finalized assistant message so every tool_use gets a matching tool_result.
                foreach ($toolUseBlocks as $index => $block) {
                    $streamingExecutor->onToolBlockReady($block, $index);
                }

                // 7. Collect tool results (early-forked safe tools + queued unsafe tools)
                $toolResults = $streamingExecutor->collectResults();

                // 7b. Enforce per-message aggregate budget for large results
                $storage = $this->toolOrchestrator->getToolResultStorage();
                if ($storage !== null) {
                    $toolResults = $storage->enforceMessageBudget($toolResults);
                }

                // 8. Feed tool results back
                $this->messageHistory->addToolResultMessage($toolResults);

                // 9. Record transcript
                $this->sessionManager->recordTurn($assistantMessage, $toolResults);

                // 10. Auto-generate session title after first turn
                if (! $this->autoTitleGenerated && $this->sessionManager->getTitle() === null) {
                    $this->autoTitleGenerated = true;
                    $firstInput = mb_substr($userInput, 0, 80);
                    $title = preg_replace('/\s+/', ' ', trim($firstInput));
                    if ($title !== '') {
                        $this->sessionManager->setTitle($title);
                    }
                }
            } catch (\Throwable $e) {
                $streamingExecutor->cleanup();
                throw $e;
            }
        }

        if ($this->aborted) {
            return '(aborted)';
        }

        return "Reached maximum turn limit ({$this->maxTurns}). Stopping.";
    }

    public function getTotalInputTokens(): int
    {
        return $this->totalInputTokens;
    }

    public function getLastTurnInputTokens(): int
    {
        return $this->lastTurnInputTokens;
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

    public function resetSessionMetrics(): void
    {
        $this->aborted = false;
        $this->sessionStarted = false;
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;
        $this->totalCacheCreationTokens = 0;
        $this->totalCacheReadTokens = 0;
        $this->lastTurnInputTokens = 0;
        $this->costTracker->reset();
    }

    /**
     * @param  array<int, array{id: string, name: string, input: array, raw_input?: string, input_json_error?: ?string}>  $toolUseBlocks
     * @return array<int, array{id: string, name: string, error: string}>
     */
    private function findMalformedToolUseFailures(array $toolUseBlocks, ToolUseContext $context): array
    {
        $failures = [];

        foreach ($toolUseBlocks as $block) {
            $tool = $this->toolRegistry->getTool($block['name']);
            if ($tool === null) {
                continue;
            }

            $inputJsonError = $block['input_json_error'] ?? null;
            if (is_string($inputJsonError) && $inputJsonError !== '') {
                $rawInputSnippet = $this->summarizeMalformedToolInput($block['raw_input'] ?? '');
                $failures[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'error' => $inputJsonError.($rawInputSnippet !== null ? ' Raw input: '.$rawInputSnippet : ''),
                ];

                continue;
            }

            $rawInput = $block['input'] ?? [];
            if (! is_array($rawInput)) {
                $failures[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'error' => 'Tool input must decode to an object.',
                ];

                continue;
            }

            try {
                $validatedInput = $tool->inputSchema()->validate($rawInput);
            } catch (\InvalidArgumentException $e) {
                $failures[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'error' => $e->getMessage(),
                ];

                continue;
            } catch (\TypeError $e) {
                $failures[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $semanticError = $tool->validateInput($validatedInput, $context);
            if ($semanticError !== null) {
                $failures[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'error' => $semanticError,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    private function sanitizeMalformedToolAssistantMessage(array $assistantMessage, array $failures): array
    {
        $failedToolIds = [];
        foreach ($failures as $failure) {
            $failedToolIds[$failure['id']] = true;
        }

        $sanitizedContent = [];
        foreach ($assistantMessage['content'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $toolUseId = $block['id'] ?? null;
            if (! is_string($toolUseId) || ! isset($failedToolIds[$toolUseId])) {
                continue;
            }

            if (! is_array($block['input'] ?? null)) {
                $block['input'] = [];
            }

            $sanitizedContent[] = $block;
        }

        $assistantMessage['content'] = array_values($sanitizedContent);

        return $assistantMessage;
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     * @return array<int, array{tool_use_id: string, content: string, is_error: bool}>
     */
    private function buildMalformedToolRetryResults(array $failures): array
    {
        return array_map(function (array $failure): array {
            return [
                'tool_use_id' => $failure['id'],
                'content' => $this->buildMalformedToolRetryMessage($failure['name'], $failure['error']),
                'is_error' => true,
            ];
        }, $failures);
    }

    private function buildMalformedToolRetryMessage(string $toolName, string $error): string
    {
        $lines = [
            'Tool input validation failed. This tool call was not executed.',
            $error,
            'Retry with corrected input.',
        ];

        if ($error === 'Tool input must decode to an object.') {
            $lines[] = 'Tool inputs must be JSON objects that match the tool schema.';
        }

        if ($toolName === 'Write') {
            $lines[] = 'For Write: include an absolute file_path, send the complete file contents in content, and do not prefix JSON or file contents with stray ":" placeholder text.';
            if (str_contains($error, 'Tool input JSON could not be parsed')) {
                $lines[] = 'If the file content is large or multiline, stop resending the same broken JSON blob. Split the file into smaller writes or create it in smaller Bash heredoc chunks.';
                $lines[] = 'For large source files, create a tiny scaffold first, then use Edit in small chunks no larger than about 8 lines or 400 characters.';
            }
        }

        if ($toolName === 'TodoWrite') {
            $lines[] = 'For TodoWrite: send a todos array with real tasks, or skip TodoWrite entirely if there is nothing useful to track.';
        }

        if ($toolName === 'Bash') {
            $lines[] = 'For Bash: do not send shell no-ops or probes such as ": > /dev/null 2>&1" or "true". If you need context first, run a real command like "pwd" or "ls".';
            if (str_contains($error, 'Tool input JSON could not be parsed')) {
                $lines[] = 'If the command is large or multiline, split it into smaller concrete commands instead of resending one giant heredoc payload.';
                $lines[] = 'Do not send large heredocs, inline python/node scripts, base64 blobs, or long printf command lists in one Bash call.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    private function buildMalformedToolRetryInstruction(array $failures, int $retryCount): string
    {
        $lines = [
            'Retry with corrected tool input only. Do not repeat the same malformed call.',
            'If you are unsure, inspect the repo or directory first instead of sending an empty or placeholder tool input.',
        ];

        if ($retryCount >= 2) {
            $lines[] = 'You have already repeated this invalid tool pattern. Stop and correct it now.';
        }

        $hasJsonParseFailure = false;
        foreach ($failures as $failure) {
            if (str_contains($failure['error'], 'Tool input JSON could not be parsed')) {
                $hasJsonParseFailure = true;
                break;
            }
        }
        if ($hasJsonParseFailure) {
            $lines[] = 'If a large multiline payload keeps breaking tool JSON, stop resending the same blob. Split it into smaller commands or smaller file writes.';
            $lines[] = 'Do not narrate a new strategy and then resend another large blob. The next turn must begin with a small concrete tool call.';
            $lines[] = 'Do not use Agent or Skill as a fallback for ordinary file creation or editing. Stay in this thread and use the local tools directly.';
        }

        $toolNames = array_values(array_unique(array_map(
            fn (array $failure): string => $failure['name'],
            $failures,
        )));

        foreach ($toolNames as $toolName) {
            if ($toolName === 'Write') {
                $lines[] = 'For Write: send a valid JSON object with both absolute file_path and full content strings.';
                $lines[] = 'Prefer a tiny initial Write followed by Edit chunks for long files.';
            } elseif ($toolName === 'TodoWrite') {
                $lines[] = 'For TodoWrite: send {"todos":[...]} with real tasks, or omit TodoWrite if there is nothing useful to track.';
            } elseif ($toolName === 'Bash') {
                $lines[] = 'For Bash: send a real shell command in command. Never send ":" placeholders or no-op probes like ": > /dev/null 2>&1" or "true"; use "pwd" or "ls" if you need to inspect the directory first.';
                $lines[] = 'Keep Bash commands short and concrete; avoid giant multiline file-generation commands.';
            }
        }

        return implode("\n", $lines);
    }

    private function summarizeMalformedToolInput(string $rawInput): ?string
    {
        $rawInput = trim($rawInput);
        if ($rawInput === '') {
            return null;
        }

        $snippet = preg_replace('/\s+/', ' ', $rawInput);
        if ($snippet === null || $snippet === '') {
            return null;
        }

        if (mb_strlen($snippet) > 120) {
            $snippet = mb_substr($snippet, 0, 120).'...';
        }

        return $snippet;
    }

    private function shouldRetryIncompleteAssistantResponse(
        StreamProcessor $processor,
        array $assistantMessage,
        ?string $stopReason,
        int $retryCount,
    ): bool {
        if ($retryCount >= $this->maxIncompleteResponseRetries) {
            return false;
        }

        if ($stopReason === 'max_tokens') {
            return true;
        }

        if ($this->isNarrationOnlyAssistantMessage($assistantMessage)) {
            return true;
        }

        if ($processor->hasFinalMessageEvent()) {
            return false;
        }

        return $this->assistantMessageHasVisibleContent($assistantMessage);
    }

    private function assistantMessageHasVisibleContent(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content)) {
            return false;
        }

        foreach ($content as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                return true;
            }

            if ($type === 'thinking' && trim((string) ($block['thinking'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function recordIncompleteAssistantResponse(array $assistantMessage, bool $skipHistory = false): void
    {
        if ($skipHistory || ! $this->assistantMessageHasVisibleContent($assistantMessage)) {
            return;
        }

        $this->messageHistory->addAssistantMessage($assistantMessage);
        $this->sessionManager->recordTurn($assistantMessage, []);
    }

    private function buildIncompleteResponseRetryInstruction(
        ?string $stopReason,
        int $retryCount,
        bool $skipHistory = false,
    ): string {
        $lines = [];

        if ($stopReason === 'max_tokens') {
            $lines[] = 'Your previous response hit the output limit before you finished.';
        } else {
            $lines[] = 'Your previous response ended before a final completion signal arrived.';
        }

        $lines[] = 'Continue exactly from where you left off.';
        $lines[] = 'Do not restart from scratch.';
        $lines[] = 'If the task requires file changes, commands, or verification, keep using tools until the requested work is actually complete.';

        if ($skipHistory) {
            $lines[] = 'Do not narrate progress or announce the next step. Take the next concrete action immediately.';
        }

        if ($retryCount >= 2) {
            $lines[] = 'You have already been cut off once. Finish the task now instead of narrating the next step.';
        }

        return implode("\n", $lines);
    }

    private function shouldSkipIncompleteAssistantHistory(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content) || count($content) !== 1) {
            return false;
        }

        $block = $content[0];
        if (($block['type'] ?? null) !== 'text') {
            return false;
        }

        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '' || str_contains($text, "\n") || mb_strlen($text) > 160) {
            return false;
        }

        if ($this->isLowValueNarrationText($text)) {
            return true;
        }

        if (preg_match('/[：:]$/u', $text) === 1) {
            return true;
        }

        return preg_match(
            '/^(现在|接下来|继续|然后|下一步|接着|Now\\b|Next\\b|I(?:\'ll| will)\\b|Let\'s\\b)/iu',
            $text,
        ) === 1;
    }

    private function isNarrationOnlyAssistantMessage(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content) || count($content) !== 1) {
            return false;
        }

        $block = $content[0];
        if (($block['type'] ?? null) !== 'text') {
            return false;
        }

        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '' || str_contains($text, "\n") || mb_strlen($text) > 220) {
            return false;
        }

        return $this->isLowValueNarrationText($text);
    }

    private function isLowValueNarrationText(string $text): bool
    {
        return preg_match(
            '/^(现在|接下来|继续|然后|下一步|接着|让我|我(?:先|会|将|正在|要|需要|尝试|打算|使用)|I(?:\'ll| will| need to| am going to)|Let me|Using\\b|Now\\b|Next\\b)/iu',
            trim($text),
        ) === 1;
    }
}
