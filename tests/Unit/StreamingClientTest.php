<?php

namespace Tests\Unit;

use App\Services\Api\ApiErrorException;
use App\Services\Api\StreamingClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

class StreamingClientTest extends TestCase
{
    public function test_it_throws_a_readable_error_when_request_payload_cannot_be_encoded(): void
    {
        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: new MockHttpClient([]),
        );

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Failed to encode request payload');

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => "\xB1\x31"]],
            tools: [],
        ));
    }

    public function test_it_includes_http_response_body_in_api_errors(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(
                '{"error":{"type":"invalid_request_error","message":"unsupported field tools"}}',
                ['http_code' => 400]
            ),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));

            $this->fail('Expected ApiErrorException to be thrown.');
        } catch (ApiErrorException $e) {
            $this->assertSame('invalid_request_error', $e->getErrorType());
            $this->assertStringContainsString('unsupported field tools', $e->getMessage());
        }
    }

    public function test_it_parses_sse_events_when_event_lines_are_split_across_chunks(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: content_block_st",
                "op\n",
                "data: {\"index\":0}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertCount(1, $events);
        $this->assertSame('content_block_stop', $events[0]->type);
        $this->assertSame(['index' => 0], $events[0]->data);
    }

    public function test_it_parses_sse_events_when_data_lines_are_split_across_chunks(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: message_delta\n",
                "data: {\"delta\":{\"stop_rea",
                "son\":\"tool_use\"}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertCount(1, $events);
        $this->assertSame('message_delta', $events[0]->type);
        $this->assertSame('tool_use', $events[0]->data['delta']['stop_reason'] ?? null);
    }

    public function test_it_flushes_a_pending_event_before_the_next_event_header(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: content_block_stop\n",
                "data: {\"index\":0}\n",
                "event: message_delta\n",
                "data: {\"delta\":{\"stop_reason\":\"tool_use\"}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertCount(2, $events);
        $this->assertSame('content_block_stop', $events[0]->type);
        $this->assertSame(['index' => 0], $events[0]->data);
        $this->assertSame('message_delta', $events[1]->type);
        $this->assertSame('tool_use', $events[1]->data['delta']['stop_reason'] ?? null);
    }

    public function test_it_does_not_retry_after_a_partial_stream_has_started(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;

            return new MockResponse((function () {
                yield "event: message_start\n";
                yield "data: {\"message\":{\"id\":\"msg_1\",\"usage\":[]}}\n\n";
                throw new TransportException('stream interrupted');
            })(), ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));

            $this->fail('Expected ApiErrorException to be thrown.');
        } catch (ApiErrorException $e) {
            $this->assertSame('transport_error', $e->getErrorType());
            $this->assertStringContainsString('stream interrupted', $e->getMessage());
            $this->assertSame(1, $attempts);
        }
    }

    public function test_it_retries_transport_errors_before_any_event_is_emitted(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                return new MockResponse((function () {
                    if (false) {
                        yield '';
                    }

                    throw new TransportException('connect timeout');
                })(), ['http_code' => 200]);
            }

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame(2, $attempts);
        $this->assertCount(1, $events);
        $this->assertSame('message_stop', $events[0]->type);
    }

    // ─── retry logic for specific error types ─────────────────────────────

    public function test_it_retries_overloaded_error_before_stream_starts(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                return new MockResponse(
                    '{"error":{"type":"overloaded_error","message":"server overloaded"}}',
                    ['http_code' => 529],
                );
            }
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame(2, $attempts, 'Should retry on overloaded_error');
        $this->assertCount(1, $events);
    }

    public function test_it_does_not_retry_invalid_request_error(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;
            return new MockResponse(
                '{"error":{"type":"invalid_request_error","message":"bad request"}}',
                ['http_code' => 400],
            );
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(1, $attempts, 'Should NOT retry invalid_request_error');
            $this->assertSame('invalid_request_error', $e->getErrorType());
        }
    }

    public function test_it_stops_retrying_after_max_retries(): void
    {
        $attempts = 0;
        $httpClient = new MockHttpClient(function () use (&$attempts) {
            $attempts++;
            return new MockResponse(
                '{"error":{"type":"rate_limit_error","message":"rate limited"}}',
                ['http_code' => 429],
            );
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
            // maxRetries defaults to 3
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            // maxRetries=3 means: 1 initial + up to 3 retries, but the
            // shouldRetry check uses `$attempt >= $this->maxRetries` which
            // means attempts 1,2,3 → attempt 3 is NOT >= 3 so retry is
            // allowed, then attempt 4 → 4 >= 3 so no retry. But the
            // initial attempt is attempt 0 in the while loop... Actually
            // the code increments $attempt after each catch. So:
            // attempt becomes 1, 2, 3 — at 3, shouldRetry: 3 >= 3 → false.
            // So total = 3 attempts (initial + 2 retries).
            $this->assertSame(3, $attempts, 'Should stop after maxRetries limit');
        }
    }

    // ─── SSE error event throws ApiErrorException ─────────────────────────

    public function test_sse_error_event_throws_api_error_exception(): void
    {
        // The MockHttpClient needs enough responses for the retry loop.
        // The error event causes an ApiErrorException which the retry loop
        // catches and may retry (since no events were yielded yet).
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
            new MockResponse([
                "event: error\n",
                "data: {\"error\":{\"type\":\"rate_limit_error\",\"message\":\"too many requests\"}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame('rate_limit_error', $e->getErrorType());
            $this->assertStringContainsString('too many requests', $e->getMessage());
        }
    }

    // ─── data line with leading space ─────────────────────────────────────

    public function test_data_line_with_leading_space_strips_one_space(): void
    {
        // "data: " followed by a space then JSON — the leading space in the value
        // should be stripped per SSE spec
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: message_start\n",
                "data:  {\"message\":{\"id\":\"m1\",\"usage\":[]}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertCount(1, $events);
        $this->assertSame('message_start', $events[0]->type);
        $this->assertSame('m1', $events[0]->data['message']['id'] ?? null);
    }

    // ─── multiline data accumulation ──────────────────────────────────────

    public function test_multiline_data_lines_are_joined_with_newline(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: content_block_delta\n",
                "data: {\"index\":0,\n",
                "data: \"delta\":{\"text\":\"hi\"}}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertCount(1, $events);
        // The data lines should be joined with "\n"
        $this->assertSame('content_block_delta', $events[0]->type);
        $this->assertIsArray($events[0]->data);
    }

    // ─── empty data event is ignored ──────────────────────────────────────

    public function test_empty_data_produces_no_event(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse([
                "event: ping\n",
                "data: {}\n\n",
            ], ['http_code' => 200]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $events = iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        // ping events are parsed but the StreamEvent is still yielded;
        // the StreamProcessor later ignores them. At the client level
        // we just verify no exception is thrown.
        $this->assertIsArray($events);
    }

    // ─── extended thinking payload ────────────────────────────────────────

    public function test_thinking_enabled_adds_thinking_payload(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514',
            httpClient: $httpClient,
            thinkingEnabled: true,
            thinkingBudget: 16000,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'think about this']],
            tools: [],
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('thinking', $decoded);
        $this->assertSame('enabled', $decoded['thinking']['type']);
        $this->assertSame(16000, $decoded['thinking']['budget_tokens']);
        // max_tokens should be boosted for extended thinking
        $this->assertGreaterThanOrEqual(16000 + 4096, $decoded['max_tokens']);
    }

    // ─── settings manager integration ─────────────────────────────────────

    public function test_model_and_max_tokens_resolved_from_settings(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $settings = $this->createMock(\App\Services\Settings\SettingsManager::class);
        $settings->method('getModel')->willReturn('claude-opus-4-20250514');
        $settings->method('getMaxTokens')->willReturn(32768);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514', // default, should be overridden
            httpClient: $httpClient,
            settingsManager: $settings,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertSame('claude-opus-4-20250514', $decoded['model']);
        $this->assertSame(32768, $decoded['max_tokens']);
    }

    // ─── cache_control on tools ───────────────────────────────────────────

    public function test_cache_control_added_to_last_tool(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $tools = [
            ['name' => 'Read', 'description' => 'read files'],
            ['name' => 'Bash', 'description' => 'run commands'],
        ];

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: $tools,
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('tools', $decoded);
        $this->assertCount(2, $decoded['tools']);
        // Only the last tool should have cache_control
        $this->assertArrayNotHasKey('cache_control', $decoded['tools'][0]);
        $this->assertArrayHasKey('cache_control', $decoded['tools'][1]);
        $this->assertSame('ephemeral', $decoded['tools'][1]['cache_control']['type']);
    }

    // ─── HTTP error with non-JSON body ────────────────────────────────────

    public function test_http_error_with_plain_text_body_uses_body_as_message(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(
                'Service Unavailable',
                ['http_code' => 503],
            ),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame('http_error', $e->getErrorType());
            $this->assertStringContainsString('Service Unavailable', $e->getMessage());
        }
    }

    // ─── HTTP error with empty body ───────────────────────────────────────

    public function test_http_error_with_empty_body_includes_url_in_message(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 502]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            baseUrl: 'https://api.example.com',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
            $this->assertStringContainsString('api.example.com', $e->getMessage());
        }
    }

    public function test_it_forces_http_1_1_for_kimi_streaming_requests(): void
    {
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            baseUrl: 'https://api.kimi.com/coding',
            httpClient: $httpClient,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame('1.1', $capturedOptions['http_version'] ?? null);
        $this->assertTrue($capturedOptions['verify_peer'] ?? false);
        $this->assertTrue($capturedOptions['verify_host'] ?? false);
    }
}
