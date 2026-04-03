<?php

namespace Tests\Unit;

use App\Services\Agent\MessageHistory;
use App\Services\Agent\QueryEngine;
use App\Services\Agent\StreamProcessor;
use App\Services\Compact\ContextCompactor;
use App\Services\Hooks\HookExecutor;
use App\Services\Hooks\HookResult;
use PHPUnit\Framework\TestCase;

class ContextCompactorTest extends TestCase
{
    private function makeCompactor(?QueryEngine $qe = null, ?HookExecutor $hooks = null): ContextCompactor
    {
        $qe ??= $this->makeQueryEngine('');
        return new ContextCompactor($qe, $hooks);
    }

    private function makeQueryEngine(string $summaryText): QueryEngine
    {
        $processor = $this->createMock(StreamProcessor::class);
        $processor->method('getAccumulatedText')->willReturn($summaryText);

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($processor);
        return $qe;
    }

    private function makeHistory(array $turns = []): MessageHistory
    {
        $h = new MessageHistory;
        foreach ($turns as $turn) {
            if ($turn['role'] === 'user') {
                $h->addUserMessage($turn['content']);
            } else {
                $h->addAssistantMessage(['role' => 'assistant', 'content' => $turn['content']]);
            }
        }
        return $h;
    }

    private function invoke(string $method, ContextCompactor $c, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass($c))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($c, ...$args);
    }

    // ─── compact — few messages ────────────────────────────────────────────

    public function test_compact_no_op_when_messages_lte_keepLast(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi'],
        ]);

        $result = $this->makeCompactor()->compact($history, keepLast: 6);
        $this->assertStringContainsString('No compaction needed', $result);
    }

    public function test_compact_returns_count_message(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'msg1'],
            ['role' => 'assistant', 'content' => 'resp1'],
            ['role' => 'user', 'content' => 'msg2'],
            ['role' => 'assistant', 'content' => 'resp2'],
            ['role' => 'user', 'content' => 'msg3'],
            ['role' => 'assistant', 'content' => 'resp3'],
            ['role' => 'user', 'content' => 'msg4'],
            ['role' => 'assistant', 'content' => 'resp4'],
        ]);

        $qe = $this->makeQueryEngine('Summary text from LLM');
        $result = (new ContextCompactor($qe))->compact($history, keepLast: 2);
        $this->assertStringContainsString('Compacted', $result);
        $this->assertStringContainsString('6', $result); // 8-2=6 removed
    }

    public function test_compact_clears_and_adds_summary_message(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'old1'],
            ['role' => 'assistant', 'content' => 'old2'],
            ['role' => 'user', 'content' => 'old3'],
            ['role' => 'assistant', 'content' => 'old4'],
            ['role' => 'user', 'content' => 'keep1'],
            ['role' => 'assistant', 'content' => 'keep2'],
        ]);

        $qe = $this->makeQueryEngine('LLM summary here');
        (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $messages = $history->getMessagesForApi();
        // First message should be the transcript note
        $first = $messages[0];
        $this->assertSame('user', $first['role']);
        $this->assertStringContainsString('Context Compaction Summary', $first['content']);
    }

    public function test_compact_calls_pre_and_post_hooks(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
            ['role' => 'assistant', 'content' => 'd'],
            ['role' => 'user', 'content' => 'e'],
            ['role' => 'assistant', 'content' => 'f'],
            ['role' => 'user', 'content' => 'g'],
        ]);

        $calls = [];
        $hooks = $this->createMock(HookExecutor::class);
        $hooks->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $event, array $data) use (&$calls) {
                $calls[] = $event;
                return new HookResult(true);
            });

        $qe = $this->makeQueryEngine('Summary');
        (new ContextCompactor($qe, $hooks))->compact($history, keepLast: 2);

        $this->assertSame(['PreCompact', 'PostCompact'], $calls);
    }

    public function test_compact_falls_back_to_basic_summary_on_empty_llm_response(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'Fix the bug'],
            ['role' => 'assistant', 'content' => 'Sure'],
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
        ]);

        // Empty LLM response → basic summary fallback
        $qe = $this->makeQueryEngine('');
        $result = (new ContextCompactor($qe))->compact($history, keepLast: 2);

        $this->assertStringContainsString('Compacted', $result);

        $messages = $history->getMessagesForApi();
        $summaryMsg = $messages[0]['content'];
        $this->assertStringContainsString('Conversation Summary', $summaryMsg);
    }

    // ─── stripAnalysisBlock ────────────────────────────────────────────────

    public function test_strip_extracts_summary_tag_content(): void
    {
        $text = "<analysis>Draft stuff</analysis>\n<summary>\n# Final\nContent\n</summary>";
        $result = $this->invoke('stripAnalysisBlock', $this->makeCompactor(), $text);
        $this->assertStringContainsString('# Final', $result);
        $this->assertStringNotContainsString('<analysis>', $result);
        $this->assertStringNotContainsString('<summary>', $result);
    }

    public function test_strip_removes_analysis_when_no_summary_tag(): void
    {
        $text = "<analysis>Internal draft here</analysis>\nFinal output";
        $result = $this->invoke('stripAnalysisBlock', $this->makeCompactor(), $text);
        $this->assertStringNotContainsString('Internal draft', $result);
        $this->assertStringContainsString('Final output', $result);
    }

    public function test_strip_passes_through_plain_text(): void
    {
        $text = "Just plain text, no tags";
        $result = $this->invoke('stripAnalysisBlock', $this->makeCompactor(), $text);
        $this->assertSame($text, $result);
    }

    // ─── messagesToText ────────────────────────────────────────────────────

    public function test_messages_to_text_string_content(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello there'],
            ['role' => 'assistant', 'content' => 'Hi back'],
        ];
        $result = $this->invoke('messagesToText', $this->makeCompactor(), $messages);
        $this->assertStringContainsString('user: Hello there', $result);
        $this->assertStringContainsString('assistant: Hi back', $result);
    }

    public function test_messages_to_text_tool_use_blocks(): void
    {
        $messages = [[
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'name' => 'Read',
                'input' => ['file_path' => '/src/app.php'],
            ]],
        ]];
        $result = $this->invoke('messagesToText', $this->makeCompactor(), $messages);
        $this->assertStringContainsString('[Tool call: Read(', $result);
    }

    public function test_messages_to_text_tool_result_blocks(): void
    {
        $messages = [[
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'content' => 'File contents here',
                'tool_use_id' => 'x',
            ]],
        ]];
        $result = $this->invoke('messagesToText', $this->makeCompactor(), $messages);
        $this->assertStringContainsString('[Tool result:', $result);
    }

    public function test_messages_to_text_truncates_long_tool_input(): void
    {
        $longInput = str_repeat('x', 500);
        $messages = [[
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'name' => 'Write',
                'input' => ['content' => $longInput],
            ]],
        ]];
        $result = $this->invoke('messagesToText', $this->makeCompactor(), $messages);
        $this->assertStringContainsString('...', $result);
    }

    // ─── generateBasicSummary ──────────────────────────────────────────────

    public function test_basic_summary_includes_user_messages(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Fix the login bug'],
            ['role' => 'assistant', 'content' => 'Done'],
        ];
        $result = $this->invoke('generateBasicSummary', $this->makeCompactor(), $messages);
        $this->assertStringContainsString('Fix the login bug', $result);
    }

    public function test_basic_summary_includes_tools_used(): void
    {
        $messages = [[
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => '/a.php']],
                ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => '/b.php']],
                ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls']],
            ],
        ]];
        $result = $this->invoke('generateBasicSummary', $this->makeCompactor(), $messages);
        $this->assertStringContainsString('Read', $result);
        $this->assertStringContainsString('Bash', $result);
    }

    public function test_basic_summary_includes_errors(): void
    {
        $messages = [[
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'is_error' => true,
                'content' => 'File not found: /missing.php',
                'tool_use_id' => 'x',
            ]],
        ]];
        $result = $this->invoke('generateBasicSummary', $this->makeCompactor(), $messages);
        $this->assertStringContainsString('Errors', $result);
        $this->assertStringContainsString('File not found', $result);
    }

    // ─── microCompact ─────────────────────────────────────────────────────

    public function test_micro_compact_no_op_with_few_tool_results(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('Hello');

        $result = $this->makeCompactor()->microCompact($history, keepLastToolResults: 4);
        $this->assertStringContainsString('No micro-compact needed', $result);
    }

    public function test_micro_compact_clears_old_large_tool_results(): void
    {
        $history = new MessageHistory;

        // Add 6 tool results (more than keepLastToolResults=4)
        for ($i = 0; $i < 6; $i++) {
            $history->addToolResultMessage([[
                'type' => 'tool_result',
                'tool_use_id' => "id_{$i}",
                'content' => str_repeat("content {$i} ", 200), // >1000 chars
                'is_error' => false,
            ]]);
        }

        $result = $this->makeCompactor()->microCompact($history, keepLastToolResults: 4);
        $this->assertStringContainsString('Micro-compacted', $result);
        $this->assertStringContainsString('cleared', $result);
    }

    public function test_micro_compact_no_op_when_tool_results_too_small(): void
    {
        $history = new MessageHistory;

        // Add 6 small tool results (below 1000 chars threshold)
        for ($i = 0; $i < 6; $i++) {
            $history->addToolResultMessage([[
                'type' => 'tool_result',
                'tool_use_id' => "id_{$i}",
                'content' => "short result {$i}",
                'is_error' => false,
            ]]);
        }

        $result = $this->makeCompactor()->microCompact($history, keepLastToolResults: 4);
        $this->assertStringContainsString('No compactable', $result);
    }

    // ─── shouldAutoCompact ────────────────────────────────────────────────

    public function test_should_auto_compact_false_below_threshold(): void
    {
        $compactor = $this->makeCompactor();
        // AUTO_COMPACT_THRESHOLD = 180_000 - 13_000 = 167_000
        $this->assertFalse($compactor->shouldAutoCompact(100_000));
    }

    public function test_should_auto_compact_true_above_threshold(): void
    {
        $compactor = $this->makeCompactor();
        $this->assertTrue($compactor->shouldAutoCompact(168_000));
    }

    public function test_should_auto_compact_false_after_3_failures(): void
    {
        // QueryEngine returning empty text causes compact failure + increment
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
            ['role' => 'assistant', 'content' => 'd'],
            ['role' => 'user', 'content' => 'e'],
        ]);

        $qe = $this->makeQueryEngine(''); // always fails
        $compactor = new ContextCompactor($qe);

        // Force 3 compact failures
        for ($i = 0; $i < 3; $i++) {
            $h = clone $history;
            $compactor->compact($h, keepLast: 2);
        }

        // After 3 failures, shouldAutoCompact returns false
        $this->assertFalse($compactor->shouldAutoCompact(200_000));
    }

    // ─── getWarningState ──────────────────────────────────────────────────

    public function test_warning_state_no_warning_at_low_usage(): void
    {
        $compactor = $this->makeCompactor();
        $state = $compactor->getWarningState(10_000);
        $this->assertFalse($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertFalse($state['isBlocking']);
        $this->assertNull($state['message']);
    }

    public function test_warning_state_is_warning_when_tokens_near_limit(): void
    {
        $compactor = $this->makeCompactor();
        // WARNING threshold: tokensRemaining <= 30_000 → effectiveWindow - 30_000 = 150_000
        $state = $compactor->getWarningState(155_000);
        $this->assertTrue($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertNotNull($state['message']);
        $this->assertStringContainsString('Auto-compact will trigger soon', $state['message']);
    }

    public function test_warning_state_is_error_near_critical(): void
    {
        $compactor = $this->makeCompactor();
        // ERROR threshold: tokensRemaining <= 20_000 → effectiveWindow - 20_000 = 160_000
        $state = $compactor->getWarningState(165_000);
        $this->assertTrue($state['isError']);
        $this->assertStringContainsString('nearly full', $state['message']);
    }

    public function test_warning_state_is_blocking_at_critical(): void
    {
        $compactor = $this->makeCompactor();
        // BLOCKING threshold: tokensRemaining <= 3_000 → effectiveWindow - 3_000 = 177_000
        $state = $compactor->getWarningState(178_000);
        $this->assertTrue($state['isBlocking']);
        $this->assertStringContainsString('critically full', $state['message']);
    }

    public function test_warning_state_percent_used_is_correct(): void
    {
        $compactor = $this->makeCompactor();
        // effectiveWindow = 180_000, 90_000 tokens = 50%
        $state = $compactor->getWarningState(90_000);
        $this->assertSame(50.0, $state['percentUsed']);
    }

    // ─── consecutive user messages after compact ──────────────────────────

    public function test_compact_does_not_produce_consecutive_user_messages_when_remaining_starts_with_user(): void
    {
        // Reproduces AgentLoop scenario: history ends with the current user input
        // that hasn't been responded to yet. compact() with keepLast=1 would keep
        // only that last user message. The summary transcript note is also a user
        // message — so without a fix, we'd get two consecutive user messages.
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'first message'],
            ['role' => 'assistant', 'content' => 'first reply'],
            ['role' => 'user', 'content' => 'second message — current turn input'],
        ]);

        $qe = $this->makeQueryEngine('Summary of earlier conversation');
        (new ContextCompactor($qe))->compact($history, keepLast: 1);

        $messages = $history->getMessagesForApi();

        // Ensure no two consecutive messages have the same role
        for ($i = 1; $i < count($messages); $i++) {
            $this->assertNotSame(
                $messages[$i - 1]['role'],
                $messages[$i]['role'],
                "Consecutive messages at positions " . ($i - 1) . " and {$i} have the same role '{$messages[$i]['role']}'"
            );
        }
    }

    // ─── isCompactableToolResult ──────────────────────────────────────────

    public function test_is_compactable_tool_result_false_for_short_content(): void
    {
        $block = ['content' => 'short', 'tool_use_id' => 'x'];
        $result = $this->invoke('isCompactableToolResult', $this->makeCompactor(), $block);
        $this->assertFalse($result);
    }

    public function test_is_compactable_tool_result_true_for_long_content(): void
    {
        $block = ['content' => str_repeat('x', 1500), 'tool_use_id' => 'x'];
        $result = $this->invoke('isCompactableToolResult', $this->makeCompactor(), $block);
        $this->assertTrue($result);
    }

    public function test_is_compactable_tool_result_false_for_non_string_content(): void
    {
        $block = ['content' => 123, 'tool_use_id' => 'x'];
        $result = $this->invoke('isCompactableToolResult', $this->makeCompactor(), $block);
        $this->assertFalse($result);
    }

    public function test_is_compactable_tool_result_false_for_missing_content(): void
    {
        $block = ['tool_use_id' => 'x'];
        $result = $this->invoke('isCompactableToolResult', $this->makeCompactor(), $block);
        $this->assertFalse($result);
    }

    // ─── replayMessages ───────────────────────────────────────────────────

    public function test_replay_messages_handles_user_message_with_text_and_tool_result_blocks(): void
    {
        $history = new MessageHistory;
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Here is the result:'],
                ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'output'],
            ],
        ]];

        $this->invoke('replayMessages', $this->makeCompactor(), $history, $messages);

        $apiMessages = $history->getMessagesForApi();
        $this->assertCount(1, $apiMessages);
        $this->assertSame('user', $apiMessages[0]['role']);
        $this->assertIsArray($apiMessages[0]['content']);
    }

    public function test_replay_messages_skips_unknown_block_types(): void
    {
        $history = new MessageHistory;
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'unknown_type', 'data' => 'ignored'],
            ],
        ]];

        $this->invoke('replayMessages', $this->makeCompactor(), $history, $messages);

        // Unknown block types (not 'text' or 'tool_result') are skipped.
        // The text blocks filter produces empty array, tool_results filter
        // produces empty array, so the message is not added.
        $apiMessages = $history->getMessagesForApi();
        $this->assertCount(0, $apiMessages);
    }

    public function test_successful_compact_resets_failure_count(): void
    {
        $history = $this->makeHistory([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
            ['role' => 'assistant', 'content' => 'd'],
            ['role' => 'user', 'content' => 'e'],
        ]);

        // First compact fails, then succeeds
        $processor1 = $this->createMock(StreamProcessor::class);
        $processor1->method('getAccumulatedText')->willReturn('');
        $processor2 = $this->createMock(StreamProcessor::class);
        $processor2->method('getAccumulatedText')->willReturn('Good summary');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($processor1, $processor2);

        $compactor = new ContextCompactor($qe);

        $h1 = clone $history;
        $compactor->compact($h1, keepLast: 2); // failure

        $h2 = clone $history;
        $compactor->compact($h2, keepLast: 2); // success → resets failures

        // After reset, should auto compact again
        $this->assertTrue($compactor->shouldAutoCompact(200_000));
    }
}
