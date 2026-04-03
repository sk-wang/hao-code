<?php

namespace Tests\Unit;

use App\Services\Agent\QueryEngine;
use App\Services\Agent\StreamProcessor;
use App\Services\Api\StreamEvent;
use App\Services\Api\StreamingClient;
use App\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class QueryEngineTest extends TestCase
{
    private function makeClient(array $events): StreamingClient
    {
        $client = $this->createMock(StreamingClient::class);
        $client->method('streamMessages')->willReturnCallback(
            function () use ($events) {
                yield from $events;
            }
        );
        return $client;
    }

    private function makeRegistry(array $tools = []): ToolRegistry
    {
        $r = $this->createMock(ToolRegistry::class);
        $r->method('toApiTools')->willReturn($tools);
        return $r;
    }

    // ─── query — returns StreamProcessor ──────────────────────────────────

    public function test_query_returns_stream_processor(): void
    {
        $qe = new QueryEngine($this->makeClient([]), $this->makeRegistry());
        $result = $qe->query([], []);
        $this->assertInstanceOf(StreamProcessor::class, $result);
    }

    public function test_query_accumulates_text_from_events(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello ']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'world']]),
            new StreamEvent('content_block_stop', ['index' => 0]),
            new StreamEvent('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]),
        ];

        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $processor = $qe->query([], []);

        $this->assertSame('Hello world', $processor->getAccumulatedText());
    }

    public function test_query_calls_on_text_delta_callback(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'chunk1']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'chunk2']]),
        ];

        $received = [];
        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $qe->query([], [], onTextDelta: function (string $text) use (&$received) {
            $received[] = $text;
        });

        $this->assertSame(['chunk1', 'chunk2'], $received);
    }

    public function test_query_does_not_call_text_delta_for_thinking_events(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'thinking...']]),
        ];

        $received = [];
        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $qe->query([], [], onTextDelta: function (string $text) use (&$received) {
            $received[] = $text;
        });

        $this->assertEmpty($received);
    }

    public function test_query_calls_on_tool_block_complete(): void
    {
        $events = [
            new StreamEvent('message_start', ['message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]),
            new StreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tid', 'name' => 'Bash', 'input' => '']]),
            new StreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"command":"ls"}']]),
            new StreamEvent('content_block_stop', ['index' => 0]),
            new StreamEvent('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]]),
        ];

        $completedBlocks = [];
        $qe = new QueryEngine($this->makeClient($events), $this->makeRegistry());
        $qe->query([], [], onToolBlockComplete: function (array $block) use (&$completedBlocks) {
            $completedBlocks[] = $block;
        });

        $this->assertNotEmpty($completedBlocks);
        $this->assertSame('Bash', $completedBlocks[0]['name'] ?? '');
    }

    public function test_query_passes_tools_from_registry(): void
    {
        $toolDef = ['name' => 'Bash', 'description' => '...', 'input_schema' => ['type' => 'object']];
        $registry = $this->makeRegistry([$toolDef]);

        $capturedTools = null;
        $client = $this->createMock(StreamingClient::class);
        $client->method('streamMessages')->willReturnCallback(
            function ($systemPrompt, $messages, $tools) use (&$capturedTools) {
                $capturedTools = $tools;
                return (function () { yield from []; })();
            }
        );

        $qe = new QueryEngine($client, $registry);
        $qe->query([], []);

        $this->assertSame([$toolDef], $capturedTools);
    }

    public function test_query_with_empty_event_stream(): void
    {
        $qe = new QueryEngine($this->makeClient([]), $this->makeRegistry());
        $processor = $qe->query([], []);
        $this->assertSame('', $processor->getAccumulatedText());
    }
}
