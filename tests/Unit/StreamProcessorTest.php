<?php

namespace Tests\Unit;

use App\Services\Agent\StreamProcessor;
use App\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;

class StreamProcessorTest extends TestCase
{
    // ─── helpers ──────────────────────────────────────────────────────────

    private function event(string $type, array $data = []): StreamEvent
    {
        return new StreamEvent($type, $data);
    }

    private function startText(int $index = 0): StreamEvent
    {
        return $this->event('content_block_start', [
            'index' => $index,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]);
    }

    private function textDelta(string $text, int $index = 0): StreamEvent
    {
        return $this->event('content_block_delta', [
            'index' => $index,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);
    }

    private function blockStop(int $index = 0): StreamEvent
    {
        return $this->event('content_block_stop', ['index' => $index]);
    }

    // ─── message_start ────────────────────────────────────────────────────

    public function test_message_start_sets_message_id(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_start', [
            'message' => ['id' => 'msg_abc', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]],
        ]));
        $this->assertSame('msg_abc', $p->getMessageId());
    }

    public function test_message_start_sets_usage(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_start', [
            'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 100, 'output_tokens' => 0]],
        ]));
        $this->assertSame(100, $p->getUsage()['input_tokens']);
    }

    // ─── text accumulation ────────────────────────────────────────────────

    public function test_text_delta_accumulates(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->startText());
        $p->processEvent($this->textDelta('Hello '));
        $p->processEvent($this->textDelta('world'));
        $this->assertSame('Hello world', $p->getAccumulatedText());
    }

    public function test_text_delta_ignored_for_unknown_index(): void
    {
        $p = new StreamProcessor;
        // No content_block_start, so delta should be ignored
        $p->processEvent($this->textDelta('orphan', 99));
        $this->assertSame('', $p->getAccumulatedText());
    }

    // ─── thinking blocks ──────────────────────────────────────────────────

    public function test_thinking_delta_accumulates(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'thinking...'],
        ]));
        $this->assertSame('thinking...', $p->getAccumulatedThinking());
        $this->assertTrue($p->hasThinking());
    }

    public function test_thinking_callback_fired(): void
    {
        $p = new StreamProcessor;
        $received = [];
        $p->setOnThinkingDelta(function (string $t) use (&$received) { $received[] = $t; });

        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'idea'],
        ]));
        $this->assertSame(['idea'], $received);
    }

    public function test_has_thinking_false_when_empty(): void
    {
        $p = new StreamProcessor;
        $this->assertFalse($p->hasThinking());
    }

    // ─── getStopReason ────────────────────────────────────────────────────

    public function test_stop_reason_set_from_message_delta(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 5],
        ]));
        $this->assertSame('end_turn', $p->getStopReason());
    }

    public function test_stop_reason_null_initially(): void
    {
        $p = new StreamProcessor;
        $this->assertNull($p->getStopReason());
    }

    public function test_has_final_message_event_false_without_message_delta_or_stop(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_start', [
            'message' => ['id' => 'msg_1', 'usage' => []],
        ]));
        $p->processEvent($this->startText());
        $p->processEvent($this->textDelta('partial'));

        $this->assertFalse($p->hasFinalMessageEvent());
    }

    public function test_has_final_message_event_true_after_message_delta(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        $this->assertTrue($p->hasFinalMessageEvent());
    }

    public function test_has_final_message_event_true_after_message_stop(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_stop'));

        $this->assertTrue($p->hasFinalMessageEvent());
    }

    // ─── message_delta merges usage ────────────────────────────────────────

    public function test_message_delta_merges_usage(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_start', [
            'message' => ['id' => 'x', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]],
        ]));
        $p->processEvent($this->event('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 20],
        ]));
        $usage = $p->getUsage();
        $this->assertSame(10, $usage['input_tokens']);
        $this->assertSame(20, $usage['output_tokens']);
    }

    // ─── toAssistantMessage ───────────────────────────────────────────────

    public function test_to_assistant_message_with_text_block(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->startText());
        $p->processEvent($this->textDelta('Answer here'));
        $msg = $p->toAssistantMessage();
        $this->assertSame('assistant', $msg['role']);
        $this->assertSame('text', $msg['content'][0]['type']);
        $this->assertSame('Answer here', $msg['content'][0]['text']);
    }

    public function test_to_assistant_message_with_tool_use_block(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Bash'],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"command":"ls"}'],
        ]));
        $msg = $p->toAssistantMessage();
        $block = $msg['content'][0];
        $this->assertSame('tool_use', $block['type']);
        $this->assertSame('tid', $block['id']);
        $this->assertSame('Bash', $block['name']);
        $this->assertSame('ls', $block['input']['command']);
    }

    public function test_invalid_tool_use_json_is_reported_without_dropping_context(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Write'],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => ':{"file_path":"/tmp/demo.txt"}'],
        ]));

        $blocks = $p->getIndexedToolUseBlocks();

        $this->assertSame([], $blocks[0]['input']);
        $this->assertSame(':{"file_path":"/tmp/demo.txt"}', $blocks[0]['raw_input']);
        $this->assertStringContainsString('could not be parsed', $blocks[0]['input_json_error']);
        $this->assertSame([], $p->toAssistantMessage()['content'][0]['input']);
    }

    public function test_tool_use_json_with_literal_newlines_in_strings_is_repaired(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Write'],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => "{\"file_path\":\"/tmp/demo.txt\",\"content\":\"line1\nline2\"}",
            ],
        ]));

        $blocks = $p->getIndexedToolUseBlocks();

        $this->assertNull($blocks[0]['input_json_error']);
        $this->assertSame('/tmp/demo.txt', $blocks[0]['input']['file_path']);
        $this->assertSame("line1\nline2", $blocks[0]['input']['content']);
    }

    public function test_tool_use_json_with_other_control_characters_in_strings_is_repaired(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Write'],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => "{\"file_path\":\"/tmp/demo.txt\",\"content\":\"line1" . chr(12) . "line2\"}",
            ],
        ]));

        $blocks = $p->getIndexedToolUseBlocks();

        $this->assertNull($blocks[0]['input_json_error']);
        $this->assertSame("line1" . chr(12) . "line2", $blocks[0]['input']['content']);
    }

    public function test_to_assistant_message_with_thinking_block(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'my reasoning'],
        ]));
        $msg = $p->toAssistantMessage();
        $this->assertSame('thinking', $msg['content'][0]['type']);
        $this->assertSame('my reasoning', $msg['content'][0]['thinking']);
    }

    // ─── error event throws ───────────────────────────────────────────────

    public function test_error_event_throws_runtime_exception(): void
    {
        $p = new StreamProcessor;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/API Error/i');
        $p->processEvent($this->event('error', ['error' => ['message' => 'rate limit exceeded']]));
    }

    // ─── ping / message_stop ignored ──────────────────────────────────────

    public function test_ping_does_not_change_state(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('ping'));
        $this->assertSame('', $p->getAccumulatedText());
        $this->assertNull($p->getStopReason());
    }

    public function test_message_stop_does_not_change_state(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_stop'));
        $this->assertNull($p->getStopReason());
    }

    // ─── reset ────────────────────────────────────────────────────────────

    public function test_reset_clears_all_state(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('message_start', [
            'message' => ['id' => 'msg_x', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]],
        ]));
        $p->processEvent($this->startText());
        $p->processEvent($this->textDelta('some text'));

        $p->reset();

        $this->assertSame('', $p->getAccumulatedText());
        $this->assertNull($p->getMessageId());
        $this->assertNull($p->getStopReason());
        $this->assertEmpty($p->getUsage());
        $this->assertFalse($p->hasToolUse());
    }

    // ─── getIndexedToolUseBlocks ──────────────────────────────────────────

    public function test_indexed_tool_use_blocks_preserves_index(): void
    {
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 2,
            'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Read'],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 2,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"file_path":"/a"}'],
        ]));
        $indexed = $p->getIndexedToolUseBlocks();
        $this->assertArrayHasKey(2, $indexed);
        $this->assertSame('Read', $indexed[2]['name']);
    }

    // ─── null data event ignored ──────────────────────────────────────────

    public function test_event_with_null_data_is_ignored(): void
    {
        $p = new StreamProcessor;
        $event = new StreamEvent('text_delta', null);
        $p->processEvent($event); // should not throw
        $this->assertSame('', $p->getAccumulatedText());
    }

    // ─── existing tests below ─────────────────────────────────────────────

    public function test_it_treats_detected_tool_use_blocks_as_follow_up_even_without_stop_reason(): void
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => 'Glob',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '{"pattern":"**/*.php"}',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_stop', [
            'index' => 0,
        ]));

        $this->assertNotEmpty($processor->getToolUseBlocks());
        $this->assertTrue($processor->hasToolUse());
    }

    public function test_it_completes_a_trailing_tool_block_when_message_delta_stops_for_tool_use(): void
    {
        $processor = new StreamProcessor;
        $completed = [];

        $processor->setOnToolBlockComplete(function (array $block, int $index) use (&$completed): void {
            $completed[] = [$index, $block];
        });

        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 1,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_456',
                'name' => 'Read',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 1,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '{"file_path":"/tmp/demo.txt"}',
            ],
        ]));

        $processor->processEvent(new StreamEvent('message_delta', [
            'delta' => [
                'stop_reason' => 'tool_use',
            ],
        ]));

        $this->assertCount(1, $completed);
        $this->assertSame(1, $completed[0][0]);
        $this->assertSame('toolu_456', $completed[0][1]['id']);
        $this->assertSame('Read', $completed[0][1]['name']);
        $this->assertSame('/tmp/demo.txt', $completed[0][1]['input']['file_path'] ?? null);
    }

    public function test_it_does_not_complete_the_same_tool_block_twice(): void
    {
        $processor = new StreamProcessor;
        $completedCount = 0;

        $processor->setOnToolBlockComplete(function () use (&$completedCount): void {
            $completedCount++;
        });

        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_789',
                'name' => 'Glob',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '{"pattern":"**/*.php"}',
            ],
        ]));

        $processor->processEvent(new StreamEvent('message_delta', [
            'delta' => [
                'stop_reason' => 'tool_use',
            ],
        ]));

        $processor->processEvent(new StreamEvent('content_block_stop', [
            'index' => 0,
        ]));

        $this->assertSame(1, $completedCount);
    }

    // ─── thinking block signature (extended thinking) ─────────────────────

    public function test_signature_delta_is_captured_for_thinking_block(): void
    {
        // The Anthropic API sends a signature_delta for thinking blocks.
        // It must be stored so that toAssistantMessage() can include it
        // when the block is passed back in subsequent turns.
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'my reasoning'],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'signature_delta', 'signature' => 'sig_abc123'],
        ]));

        $msg = $p->toAssistantMessage();
        $block = $msg['content'][0];
        $this->assertSame('thinking', $block['type']);
        $this->assertSame('my reasoning', $block['thinking']);
        $this->assertArrayHasKey('signature', $block, 'signature must be present for multi-turn conversations');
        $this->assertSame('sig_abc123', $block['signature']);
    }

    public function test_thinking_block_without_signature_delta_has_no_signature_key(): void
    {
        // When no signature_delta arrives (e.g., non-thinking blocks or incomplete stream),
        // the thinking block in the assistant message must NOT include a 'signature' key
        // (an explicit null would also be rejected by the API).
        $p = new StreamProcessor;
        $p->processEvent($this->event('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'thinking' => ''],
        ]));
        $p->processEvent($this->event('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'brief thought'],
        ]));

        $msg = $p->toAssistantMessage();
        $block = $msg['content'][0];
        $this->assertSame('thinking', $block['type']);
        $this->assertArrayNotHasKey('signature', $block, 'signature must not be present when no signature_delta arrived');
    }
}
