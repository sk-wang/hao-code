<?php

namespace App\Services\Compact;

use App\Services\Agent\MessageHistory;
use App\Services\Agent\QueryEngine;
use App\Services\Hooks\HookExecutor;

class ContextCompactor
{
    /** Claude context window (200k tokens) */
    private const CONTEXT_WINDOW = 200_000;

    /** Reserve for compaction summary output (~p99 of LLM summary) */
    private const MAX_OUTPUT_TOKENS_FOR_SUMMARY = 20_000;

    /** Effective usable context = CONTEXT_WINDOW - MAX_OUTPUT_TOKENS_FOR_SUMMARY */
    private const EFFECTIVE_CONTEXT_WINDOW = self::CONTEXT_WINDOW - self::MAX_OUTPUT_TOKENS_FOR_SUMMARY;

    /**
     * Trigger levels (tokens remaining before hitting EFFECTIVE_CONTEXT_WINDOW).
     * Ported from claude-code autoCompact.ts constants.
     */
    private const AUTOCOMPACT_BUFFER_TOKENS  = 13_000; // fire auto-compact
    private const WARNING_BUFFER_TOKENS      = 30_000; // yellow warning
    private const ERROR_BUFFER_TOKENS        = 20_000; // red warning (= effective window - this)
    private const BLOCKING_BUFFER_TOKENS     =  3_000; // hard block if compaction fails

    private const AUTO_COMPACT_THRESHOLD = self::EFFECTIVE_CONTEXT_WINDOW - self::AUTOCOMPACT_BUFFER_TOKENS;
    private const MICRO_COMPACT_THRESHOLD = 40000;

    private const COMPACTABLE_TOOLS = ['Read', 'Bash', 'Grep', 'Glob', 'WebSearch', 'WebFetch', 'Edit', 'Write'];

    private int $compactFailures = 0;
    private const MAX_COMPACT_FAILURES = 3;

    public function __construct(
        private readonly QueryEngine $queryEngine,
        private readonly ?HookExecutor $hookExecutor = null,
    ) {}

    /**
     * Compact message history using LLM-generated 9-section structured summary.
     */
    public function compact(MessageHistory $history, int $keepLast = 6, ?string $customInstructions = null): string
    {

        $messages = $history->getMessagesForApi();
        $count = count($messages);

        if ($count <= $keepLast) {
            return "No compaction needed ({$count} messages).";
        }

        $removed = $count - $keepLast;
        $oldMessages = array_slice($messages, 0, $removed);

        // PreCompact hook
        if ($this->hookExecutor) {
            $this->hookExecutor->execute('PreCompact', [
                'trigger' => 'auto',
                'removed_messages' => $removed,
            ]);
        }

        $summary = $this->generateLlmSummary($oldMessages, $customInstructions ?? null);

        if ($summary === null) {
            $summary = $this->generateBasicSummary($oldMessages);
            $this->compactFailures++;
        } else {
            $this->compactFailures = 0;
            $summary = $this->stripAnalysisBlock($summary);
        }

        // PostCompact hook
        if ($this->hookExecutor) {
            $this->hookExecutor->execute('PostCompact', [
                'trigger' => 'auto',
                'compact_summary' => mb_substr($summary, 0, 500),
            ]);
        }

        // Clear and rebuild with continuation message
        $history->clear();

        $transcriptNote = "[Context Compaction Summary — {$removed} messages compacted]\n\n{$summary}\n\n[End of Summary. Continue the conversation from where it left off without asking further questions.]";
        $history->addUserMessage($transcriptNote);

        $remaining = array_slice($messages, $removed);

        // If the first remaining message is a user message, insert a bridge assistant
        // acknowledgement so the history never has two consecutive user messages
        // (which the Anthropic API rejects). This can happen when the history ends
        // with an un-replied user turn (e.g., the current input in AgentLoop).
        if (!empty($remaining) && ($remaining[0]['role'] ?? '') === 'user') {
            $history->addAssistantMessage([
                'role' => 'assistant',
                'content' => '[Acknowledged. Continuing from where we left off.]',
            ]);
        }

        $this->replayMessages($history, $remaining);

        return "Compacted {$removed} messages into structured summary. Kept last {$keepLast}.";
    }

    public function shouldAutoCompact(int $totalInputTokens): bool
    {
        return $totalInputTokens > self::AUTO_COMPACT_THRESHOLD
            && $this->compactFailures < self::MAX_COMPACT_FAILURES;
    }

    /**
     * Return a tiered warning state based on current token usage.
     *
     * Mirrors claude-code's autoCompact.ts warning threshold logic.
     *
     * @return array{
     *   percentUsed: float,
     *   isWarning: bool,
     *   isError: bool,
     *   isBlocking: bool,
     *   message: string|null
     * }
     */
    public function getWarningState(int $totalInputTokens): array
    {
        $effectiveWindow = self::EFFECTIVE_CONTEXT_WINDOW;
        $percentUsed = round(($totalInputTokens / $effectiveWindow) * 100, 1);

        $tokensRemaining = $effectiveWindow - $totalInputTokens;

        $isBlocking = $tokensRemaining <= self::BLOCKING_BUFFER_TOKENS;
        $isError    = $tokensRemaining <= self::ERROR_BUFFER_TOKENS;
        $isWarning  = $tokensRemaining <= self::WARNING_BUFFER_TOKENS;

        $message = null;
        if ($isBlocking) {
            $message = "Context window critically full ({$percentUsed}%). Use /compact immediately.";
        } elseif ($isError) {
            $message = "Context window nearly full ({$percentUsed}%). Consider using /compact.";
        } elseif ($isWarning) {
            $message = "Context window at {$percentUsed}%. Auto-compact will trigger soon.";
        }

        return [
            'percentUsed' => $percentUsed,
            'isWarning'   => $isWarning,
            'isError'     => $isError,
            'isBlocking'  => $isBlocking,
            'message'     => $message,
        ];
    }

    /**
     * Replay messages into history, preserving all content block types.
     */
    private function replayMessages(MessageHistory $history, array $messages): void
    {
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';

            if ($role === 'user') {
                if (is_string($content)) {
                    $history->addUserMessage($content);
                } elseif (is_array($content)) {
                    $toolResults = array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_result');
                    $textBlocks = array_filter($content, fn($b) => ($b['type'] ?? '') === 'text');

                    if (!empty($textBlocks) && empty($toolResults)) {
                        // Pure text user message
                        $text = implode("\n", array_map(fn($b) => $b['text'] ?? '', $textBlocks));
                        $history->addUserMessage($text);
                    } elseif (!empty($toolResults)) {
                        $history->addToolResultMessage(array_values($toolResults));
                    }
                }
            } elseif ($role === 'assistant') {
                $history->addAssistantMessage($msg);
            }
        }
    }

    /**
     * Micro-compact: clear old tool result content without LLM call.
     */
    public function microCompact(MessageHistory $history, int $keepLastToolResults = 4): string
    {
        $messages = $history->getMessagesForApi();
        $modified = 0;
        $charsSaved = 0;

        $toolResultIndices = [];
        foreach ($messages as $idx => $msg) {
            $content = $msg['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $toolResultIndices[] = $idx;
                    }
                }
            }
        }

        if (count($toolResultIndices) <= $keepLastToolResults) {
            return "No micro-compact needed (only " . count($toolResultIndices) . " tool results).";
        }

        $resultsToKeep = array_slice($toolResultIndices, -$keepLastToolResults);
        $newMessages = [];

        foreach ($messages as $idx => $msg) {
            $content = $msg['content'] ?? '';
            $role = $msg['role'] ?? '';

            if (is_array($content) && $role === 'user' && !in_array($idx, $resultsToKeep)) {
                $newContent = [];
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'tool_result' && $this->isCompactableToolResult($block)) {
                        $oldLen = mb_strlen($block['content'] ?? '');
                        $newContent[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $block['tool_use_id'] ?? '',
                            'content' => '[Old tool result content cleared to save context]',
                            'is_error' => false,
                        ];
                        $charsSaved += $oldLen;
                        $modified++;
                        continue;
                    }
                    $newContent[] = $block;
                }
                $msg['content'] = $newContent;
            }

            $newMessages[] = $msg;
        }

        if ($modified === 0) {
            return "No compactable tool results found to clear.";
        }

        $history->clear();
        $this->replayMessages($history, $newMessages);

        return "Micro-compacted: cleared {$modified} old tool results, saved ~" . number_format($charsSaved) . " chars.";
    }

    private function isCompactableToolResult(array $block): bool
    {
        $content = $block['content'] ?? '';
        return is_string($content) && mb_strlen($content) >= 1000;
    }

    /**
     * Get the structured 9-section compact system prompt.
     */
    private function getCompactSystemPrompt(): array
    {
        return [[
            'type' => 'text',
            'text' => <<<'PROMPT'
<important>No tools may be used during this compact operation. Do not call any tools.</important>

You are a conversation compaction assistant. You MUST produce your response in exactly two blocks:

1. An `<analysis>` block where you draft your understanding
2. A `<summary>` block with the final structured output

Your <summary> MUST contain these 9 sections in this exact format:

<summary>
# Conversation Summary

## 1. Primary Request and Intent
[What the user asked for — their exact words if possible, plus inferred intent]

## 2. Key Technical Concepts
[Important technical concepts, frameworks, patterns, algorithms discussed]

## 3. Files and Code Sections
[All files read, edited, or created. For each file, note what was done and any important code patterns. Use file:line format.]

## 4. Errors and Fixes
[Any errors encountered and how they were fixed]

## 5. Problem Solving
[Key decisions made, approaches tried, and why they were chosen]

## 6. All User Messages
[Bulleted list of every user message in order]

## 7. Pending Tasks
[Tasks mentioned but not yet completed]

## 8. Current Work
[What was being actively worked on when compaction was triggered]

## 9. Optional Next Step
[What the next logical step would be based on the current state]
</summary>

Be specific. Include file paths, function names, exact error messages. Preserve all context needed to continue the work seamlessly.
PROMPT,
        ]];
    }

    private function generateLlmSummary(array $oldMessages, ?string $customInstructions = null): ?string
    {
        try {
            $conversationText = $this->messagesToText($oldMessages);

            if (mb_strlen($conversationText) > 50000) {
                $conversationText = mb_substr($conversationText, 0, 50000) . "\n[...truncated for compaction...]";
            }

            $prompt = "Please summarize the following conversation using the structured 9-section format:\n\n{$conversationText}";
            if ($customInstructions !== null) {
                $prompt .= "\n\nAdditional instructions for this compaction:\n{$customInstructions}";
            }

            $summaryMessages = [[
                'role' => 'user',
                'content' => $prompt,
            ]];

            $processor = $this->queryEngine->query(
                systemPrompt: $this->getCompactSystemPrompt(),
                messages: $summaryMessages,
            );

            $text = $processor->getAccumulatedText();
            return !empty($text) ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Strip <analysis>...</analysis> blocks from compact output, keep <summary>.
     */
    private function stripAnalysisBlock(string $text): string
    {
        // Extract content between <summary> tags if present
        if (preg_match('/<summary>(.*?)<\/summary>/s', $text, $m)) {
            return trim($m[1]);
        }

        // Remove <analysis> block if present but no <summary> tag
        $text = preg_replace('/<analysis>.*?<\/analysis>\s*/s', '', $text);

        return trim($text);
    }

    private function messagesToText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';

            if (is_string($content)) {
                $parts[] = "{$role}: {$content}";
            } elseif (is_array($content)) {
                $text = '';
                foreach ($content as $block) {
                    $type = $block['type'] ?? '';
                    if ($type === 'text') {
                        $text .= $block['text'] . "\n";
                    } elseif ($type === 'tool_use') {
                        $name = $block['name'] ?? 'unknown';
                        $input = json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE);
                        if (mb_strlen($input) > 300) {
                            $input = mb_substr($input, 0, 300) . '...';
                        }
                        $text .= "[Tool call: {$name}({$input})]\n";
                    } elseif ($type === 'tool_result') {
                        $result = $block['content'] ?? '';
                        if (is_string($result) && mb_strlen($result) > 800) {
                            $result = mb_substr($result, 0, 800) . '...';
                        }
                        $text .= "[Tool result: {$result}]\n";
                    }
                }
                if (!empty($text)) {
                    $parts[] = "{$role}: {$text}";
                }
            }
        }

        return implode("\n\n", $parts);
    }

    private function generateBasicSummary(array $oldMessages): string
    {
        $parts = [];
        $userMessages = [];
        $files = [];
        $errors = [];
        $commands = [];

        foreach ($oldMessages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';

            if ($role === 'user' && is_string($content)) {
                $userMessages[] = mb_substr($content, 0, 200);
            }

            if (is_array($content)) {
                foreach ($content as $block) {
                    $type = $block['type'] ?? '';

                    if ($type === 'text') {
                        $text = $block['text'] ?? '';
                        if ($role === 'assistant' && !empty($text)) {
                            $parts[] = "assistant: " . mb_substr($text, 0, 200);
                        }
                    } elseif ($type === 'tool_use') {
                        $name = $block['name'] ?? '';
                        $input = $block['input'] ?? [];
                        $commands[] = $name;

                        // Track files
                        foreach (['file_path', 'path', 'command'] as $key) {
                            if (isset($input[$key])) {
                                $files[] = "{$name}: " . $input[$key];
                            }
                        }
                    } elseif ($type === 'tool_result') {
                        $resultContent = $block['content'] ?? '';
                        $isError = $block['is_error'] ?? false;
                        if ($isError && is_string($resultContent)) {
                            $errors[] = mb_substr($resultContent, 0, 200);
                        }
                    }
                }
            }
        }

        $summary = "# Conversation Summary\n\n";
        $summary .= "## User Messages\n";
        foreach (array_slice($userMessages, -10) as $um) {
            $summary .= "- {$um}\n";
        }
        $summary .= "\n## Files Touched\n";
        foreach (array_slice(array_unique($files), -20) as $f) {
            $summary .= "- {$f}\n";
        }
        if (!empty($errors)) {
            $summary .= "\n## Errors\n";
            foreach (array_slice($errors, -5) as $e) {
                $summary .= "- {$e}\n";
            }
        }
        $summary .= "\n## Tools Used\n";
        $toolCounts = array_count_values($commands);
        foreach ($toolCounts as $tool => $count) {
            $summary .= "- {$tool}: {$count}x\n";
        }

        return $summary;
    }
}
