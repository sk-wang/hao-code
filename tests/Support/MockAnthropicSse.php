<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MockAnthropicSse
{
    /**
     * @param  array<int, MockResponse|callable(array, int, array): MockResponse>  $responses
     * @param  array<int, array{method: string, url: string, headers: array<string, mixed>, payload: array<string, mixed>}>  $requests
     */
    public static function client(array $responses, array &$requests): MockHttpClient
    {
        $requestNumber = 0;

        return new MockHttpClient(function (string $method, string $url, array $options) use ($responses, &$requests, &$requestNumber): MockResponse {
            $payload = self::decodePayload($options);
            $request = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'payload' => $payload,
            ];
            $requests[] = $request;

            if (! array_key_exists($requestNumber, $responses)) {
                throw new \RuntimeException('No mocked SSE response defined for request #'.($requestNumber + 1));
            }

            $response = $responses[$requestNumber];
            $requestNumber++;

            if ($response instanceof MockResponse) {
                return $response;
            }

            return $response($payload, $requestNumber, $request);
        });
    }

    public static function textResponse(
        string $text,
        string $messageId = 'msg_text_1',
        string $model = 'claude-test',
    ): MockResponse {
        return self::response([
            self::event('message_start', [
                'message' => [
                    'id' => $messageId,
                    'model' => $model,
                    'usage' => [
                        'input_tokens' => 32,
                        'output_tokens' => 0,
                    ],
                ],
            ]),
            self::event('content_block_start', [
                'index' => 0,
                'content_block' => [
                    'type' => 'text',
                    'text' => '',
                ],
            ]),
            self::event('content_block_delta', [
                'index' => 0,
                'delta' => [
                    'type' => 'text_delta',
                    'text' => $text,
                ],
            ]),
            self::event('content_block_stop', [
                'index' => 0,
            ]),
            self::event('message_delta', [
                'delta' => [
                    'stop_reason' => 'end_turn',
                ],
                'usage' => [
                    'output_tokens' => max(1, strlen($text)),
                ],
            ]),
            self::event('message_stop', []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function toolUseResponse(
        string $toolUseId,
        string $toolName,
        array $input,
        string $messageId = 'msg_tool_1',
        string $model = 'claude-test',
    ): MockResponse {
        return self::response([
            self::event('message_start', [
                'message' => [
                    'id' => $messageId,
                    'model' => $model,
                    'usage' => [
                        'input_tokens' => 64,
                        'output_tokens' => 0,
                    ],
                ],
            ]),
            self::event('content_block_start', [
                'index' => 0,
                'content_block' => [
                    'type' => 'tool_use',
                    'id' => $toolUseId,
                    'name' => $toolName,
                ],
            ]),
            self::event('content_block_delta', [
                'index' => 0,
                'delta' => [
                    'type' => 'input_json_delta',
                    'partial_json' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ]),
            self::event('content_block_stop', [
                'index' => 0,
            ]),
            self::event('message_delta', [
                'delta' => [
                    'stop_reason' => 'tool_use',
                ],
                'usage' => [
                    'output_tokens' => 1,
                ],
            ]),
            self::event('message_stop', []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function messageCount(array $payload): int
    {
        $messages = $payload['messages'] ?? [];

        return is_array($messages) ? count($messages) : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function lastUserText(array $payload): ?string
    {
        $messages = $payload['messages'] ?? [];
        if (! is_array($messages)) {
            return null;
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            return self::extractText($message['content'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function hasToolResult(array $payload): bool
    {
        return self::lastToolResultText($payload) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function lastToolResultText(array $payload): ?string
    {
        $messages = $payload['messages'] ?? [];
        if (! is_array($messages)) {
            return null;
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = $message['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }

            $results = [];
            foreach ($content as $block) {
                if (($block['type'] ?? null) !== 'tool_result') {
                    continue;
                }

                $results[] = (string) ($block['content'] ?? '');
            }

            if ($results !== []) {
                return implode("\n", $results);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function decodePayload(array $options): array
    {
        $body = (string) ($options['body'] ?? '');
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Failed to decode mocked request payload.');
        }

        return $decoded;
    }

    /**
     * @param  array<int, array{event: string, data: array<string, mixed>|array<int, mixed>}>  $events
     */
    private static function response(array $events): MockResponse
    {
        $chunks = [];

        foreach ($events as $event) {
            $chunks[] = 'event: '.$event['event']."\n";
            $chunks[] = 'data: '.json_encode($event['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
        }

        return new MockResponse($chunks, ['http_code' => 200]);
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $data
     * @return array{event: string, data: array<string, mixed>|array<int, mixed>}
     */
    private static function event(string $event, array $data): array
    {
        return [
            'event' => $event,
            'data' => $data,
        ];
    }

    private static function extractText(mixed $content): ?string
    {
        if (is_string($content)) {
            $text = trim($content);

            return $text === '' ? null : $text;
        }

        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text = trim((string) ($block['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
