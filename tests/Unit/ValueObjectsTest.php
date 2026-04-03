<?php

namespace Tests\Unit;

use App\Services\Api\ApiErrorException;
use App\Services\Api\StreamEvent;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ValueObjectsTest extends TestCase
{
    // ─── StreamEvent ──────────────────────────────────────────────────────

    public function test_stream_event_stores_type(): void
    {
        $event = new StreamEvent('content_block_delta');
        $this->assertSame('content_block_delta', $event->type);
    }

    public function test_stream_event_data_defaults_to_null(): void
    {
        $event = new StreamEvent('ping');
        $this->assertNull($event->data);
    }

    public function test_stream_event_stores_data(): void
    {
        $data = ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'hello']];
        $event = new StreamEvent('content_block_delta', $data);
        $this->assertSame($data, $event->data);
    }

    public function test_from_sse_parses_json_data(): void
    {
        $raw = '{"index": 0, "delta": {"type": "text_delta", "text": "hi"}}';
        $event = StreamEvent::fromSse('content_block_delta', $raw);

        $this->assertSame('content_block_delta', $event->type);
        $this->assertSame(0, $event->data['index']);
        $this->assertSame('text_delta', $event->data['delta']['type']);
    }

    public function test_from_sse_handles_invalid_json(): void
    {
        $event = StreamEvent::fromSse('unknown', 'not-json');
        $this->assertNull($event->data);
    }

    public function test_from_sse_handles_empty_string(): void
    {
        $event = StreamEvent::fromSse('ping', '');
        $this->assertNull($event->data);
    }

    // ─── ApiErrorException ────────────────────────────────────────────────

    public function test_api_error_is_runtime_exception(): void
    {
        $e = new ApiErrorException('Rate limited');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function test_api_error_stores_message(): void
    {
        $e = new ApiErrorException('Service unavailable');
        $this->assertSame('Service unavailable', $e->getMessage());
    }

    public function test_api_error_defaults_to_unknown_type(): void
    {
        $e = new ApiErrorException('Error');
        $this->assertSame('unknown', $e->getErrorType());
    }

    public function test_api_error_stores_error_type(): void
    {
        $e = new ApiErrorException('Rate limited', 'rate_limit_error');
        $this->assertSame('rate_limit_error', $e->getErrorType());
    }

    public function test_api_error_stores_code(): void
    {
        $e = new ApiErrorException('Server error', 'api_error', 500);
        $this->assertSame(500, $e->getCode());
    }

    public function test_api_error_stores_previous(): void
    {
        $prev = new \Exception('original');
        $e = new ApiErrorException('Wrapped', 'unknown', 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // ─── ToolUseContext ───────────────────────────────────────────────────

    public function test_context_stores_working_directory(): void
    {
        $ctx = new ToolUseContext('/my/dir', 'sess_123');
        $this->assertSame('/my/dir', $ctx->workingDirectory);
    }

    public function test_context_stores_session_id(): void
    {
        $ctx = new ToolUseContext('/tmp', 'sess_abc');
        $this->assertSame('sess_abc', $ctx->sessionId);
    }

    public function test_context_on_progress_defaults_to_null(): void
    {
        $ctx = new ToolUseContext('/tmp', 'test');
        $this->assertNull($ctx->onProgress);
    }

    public function test_context_stores_on_progress_callback(): void
    {
        $fn = fn($x) => $x;
        $ctx = new ToolUseContext('/tmp', 'test', $fn);
        $this->assertSame($fn, $ctx->onProgress);
    }
}
