<?php

namespace Tests\Unit;

use App\Services\Agent\StreamProcessor;
use App\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;

class StreamProcessorExtendedTest extends TestCase
{
    private function makeProcessor(): StreamProcessor
    {
        return new StreamProcessor;
    }

    private function sendTextBlock(StreamProcessor $processor, string $text, int $index = 0): void
    {
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => $index,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => $index,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]));
        $processor->processEvent(new StreamEvent('content_block_stop', ['index' => $index]));
    }

    private function sendToolBlock(StreamProcessor $processor, string $id, string $name, string $inputJson, int $index = 0): void
    {
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => $index,
            'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => $index,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => $inputJson],
        ]));
        $processor->processEvent(new StreamEvent('content_block_stop', ['index' => $index]));
    }

    // ─── text accumulation ────────────────────────────────────────────────

    public function test_accumulated_text_combines_multiple_deltas(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'Hello '],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'World'],
        ]));

        $this->assertSame('Hello World', $processor->getAccumulatedText());
    }

    public function test_accumulated_text_starts_empty(): void
    {
        $this->assertSame('', $this->makeProcessor()->getAccumulatedText());
    }

    // ─── thinking blocks ──────────────────────────────────────────────────

    public function test_thinking_block_accumulates_text(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'text' => ''],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'Step 1...'],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => ' Step 2'],
        ]));

        $this->assertSame('Step 1... Step 2', $processor->getAccumulatedThinking());
        $this->assertTrue($processor->hasThinking());
    }

    public function test_thinking_delta_callback_fires(): void
    {
        $processor = $this->makeProcessor();
        $received = [];
        $processor->setOnThinkingDelta(function (string $chunk) use (&$received): void {
            $received[] = $chunk;
        });

        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'text' => ''],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'thought A'],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'thought B'],
        ]));

        $this->assertSame(['thought A', 'thought B'], $received);
    }

    public function test_has_thinking_false_when_no_thinking(): void
    {
        $processor = $this->makeProcessor();
        $this->sendTextBlock($processor, 'some text');
        $this->assertFalse($processor->hasThinking());
    }

    // ─── toAssistantMessage ───────────────────────────────────────────────

    public function test_to_assistant_message_has_assistant_role(): void
    {
        $processor = $this->makeProcessor();
        $msg = $processor->toAssistantMessage();
        $this->assertSame('assistant', $msg['role']);
    }

    public function test_to_assistant_message_includes_text_block(): void
    {
        $processor = $this->makeProcessor();
        $this->sendTextBlock($processor, 'Hello there');

        $msg = $processor->toAssistantMessage();
        $content = $msg['content'];

        $this->assertCount(1, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('Hello there', $content[0]['text']);
    }

    public function test_to_assistant_message_includes_tool_use_block(): void
    {
        $processor = $this->makeProcessor();
        $this->sendToolBlock($processor, 'toolu_abc', 'Read', '{"file_path":"/tmp/x.php"}');

        $msg = $processor->toAssistantMessage();
        $content = $msg['content'];

        $this->assertCount(1, $content);
        $this->assertSame('tool_use', $content[0]['type']);
        $this->assertSame('toolu_abc', $content[0]['id']);
        $this->assertSame('Read', $content[0]['name']);
        $this->assertSame('/tmp/x.php', $content[0]['input']['file_path']);
    }

    public function test_to_assistant_message_includes_thinking_block(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'text' => ''],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'My thought'],
        ]));

        $msg = $processor->toAssistantMessage();
        $content = $msg['content'];

        $this->assertCount(1, $content);
        $this->assertSame('thinking', $content[0]['type']);
        $this->assertSame('My thought', $content[0]['thinking']);
    }

    public function test_to_assistant_message_with_mixed_blocks(): void
    {
        $processor = $this->makeProcessor();
        // thinking at index 0, text at index 1, tool_use at index 2
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'text' => ''],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'thought'],
        ]));

        $this->sendTextBlock($processor, 'Hello', index: 1);
        $this->sendToolBlock($processor, 'toolu_1', 'Glob', '{"pattern":"*.php"}', index: 2);

        $msg = $processor->toAssistantMessage();
        $content = $msg['content'];

        $this->assertCount(3, $content);
        $this->assertSame('thinking', $content[0]['type']);
        $this->assertSame('text', $content[1]['type']);
        $this->assertSame('tool_use', $content[2]['type']);
    }

    public function test_to_assistant_message_returns_empty_content_when_no_blocks(): void
    {
        $processor = $this->makeProcessor();
        $msg = $processor->toAssistantMessage();

        $this->assertSame('assistant', $msg['role']);
        $this->assertEmpty($msg['content']);
    }

    // ─── reset() ──────────────────────────────────────────────────────────

    public function test_reset_clears_accumulated_text(): void
    {
        $processor = $this->makeProcessor();
        $this->sendTextBlock($processor, 'some text');

        $processor->reset();

        $this->assertSame('', $processor->getAccumulatedText());
    }

    public function test_reset_clears_accumulated_thinking(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'thinking', 'text' => ''],
        ]));
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'thought'],
        ]));

        $processor->reset();

        $this->assertSame('', $processor->getAccumulatedThinking());
        $this->assertFalse($processor->hasThinking());
    }

    public function test_reset_clears_tool_use_blocks(): void
    {
        $processor = $this->makeProcessor();
        $this->sendToolBlock($processor, 'toolu_1', 'Read', '{}');

        $processor->reset();

        $this->assertEmpty($processor->getToolUseBlocks());
        $this->assertFalse($processor->hasToolUse());
    }

    public function test_reset_clears_stop_reason(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        $processor->reset();

        $this->assertNull($processor->getStopReason());
    }

    public function test_reset_clears_message_id(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('message_start', [
            'message' => ['id' => 'msg_123', 'usage' => []],
        ]));

        $processor->reset();

        $this->assertNull($processor->getMessageId());
    }

    public function test_reset_allows_reuse_for_new_stream(): void
    {
        $processor = $this->makeProcessor();
        $this->sendTextBlock($processor, 'first');
        $processor->reset();

        $this->sendTextBlock($processor, 'second');

        $this->assertSame('second', $processor->getAccumulatedText());
    }

    // ─── getIndexedToolUseBlocks ──────────────────────────────────────────

    public function test_indexed_tool_use_blocks_preserves_indices(): void
    {
        $processor = $this->makeProcessor();
        // Tool at index 2, not 0
        $this->sendToolBlock($processor, 'toolu_1', 'Grep', '{"pattern":"foo"}', index: 2);

        $indexed = $processor->getIndexedToolUseBlocks();

        $this->assertArrayHasKey(2, $indexed);
        $this->assertSame('Grep', $indexed[2]['name']);
    }

    public function test_get_tool_use_blocks_returns_flat_array(): void
    {
        $processor = $this->makeProcessor();
        $this->sendToolBlock($processor, 'toolu_1', 'Read', '{}', index: 0);
        $this->sendToolBlock($processor, 'toolu_2', 'Glob', '{}', index: 1);

        $blocks = $processor->getToolUseBlocks();

        $this->assertCount(2, $blocks);
        $this->assertArrayHasKey(0, $blocks);
        $this->assertArrayHasKey(1, $blocks);
    }

    // ─── message_start / usage ────────────────────────────────────────────

    public function test_message_start_sets_message_id_and_usage(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('message_start', [
            'message' => [
                'id' => 'msg_xyz',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 0],
            ],
        ]));

        $this->assertSame('msg_xyz', $processor->getMessageId());
        $this->assertSame(100, $processor->getUsage()['input_tokens']);
    }

    public function test_message_delta_merges_usage(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('message_start', [
            'message' => [
                'id' => 'msg_1',
                'usage' => ['input_tokens' => 100],
            ],
        ]));
        $processor->processEvent(new StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 50],
        ]));

        $usage = $processor->getUsage();
        $this->assertSame(100, $usage['input_tokens']);
        $this->assertSame(50, $usage['output_tokens']);
    }

    // ─── error handling ───────────────────────────────────────────────────

    public function test_error_event_throws_runtime_exception(): void
    {
        $processor = $this->makeProcessor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/API Error/');

        $processor->processEvent(new StreamEvent('error', [
            'error' => ['message' => 'Rate limit exceeded'],
        ]));
    }

    public function test_error_message_included_in_exception(): void
    {
        $processor = $this->makeProcessor();

        try {
            $processor->processEvent(new StreamEvent('error', [
                'error' => ['message' => 'Invalid API key'],
            ]));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid API key', $e->getMessage());
        }
    }

    // ─── unknown / ignored event types ────────────────────────────────────

    public function test_ping_event_is_ignored(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('ping', null));
        $this->assertSame('', $processor->getAccumulatedText());
    }

    public function test_message_stop_event_is_ignored(): void
    {
        $processor = $this->makeProcessor();
        $processor->processEvent(new StreamEvent('message_stop', []));
        $this->assertNull($processor->getStopReason());
    }

    public function test_unknown_event_type_is_ignored(): void
    {
        $processor = $this->makeProcessor();
        // Should not throw
        $processor->processEvent(new StreamEvent('some_future_event', ['data' => 'x']));
        $this->assertSame('', $processor->getAccumulatedText());
    }

    public function test_delta_for_unknown_index_is_ignored(): void
    {
        $processor = $this->makeProcessor();
        // No content_block_start for index 99
        $processor->processEvent(new StreamEvent('content_block_delta', [
            'index' => 99,
            'delta' => ['type' => 'text_delta', 'text' => 'orphan'],
        ]));
        $this->assertSame('', $processor->getAccumulatedText());
    }
}
