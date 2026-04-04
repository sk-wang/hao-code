<?php

namespace App\Services\Api;

use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class StreamingClient
{
    private HttpClientInterface $httpClient;
    private int $maxRetries = 3;
    private array $lastRateLimitHeaders = [];

    public function __construct(
        private readonly string $apiKey,
        private string $model,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private int $maxTokens = 16384,
        private readonly string $apiVersion = '2023-06-01',
        private readonly bool $thinkingEnabled = false,
        private readonly int $thinkingBudget = 10000,
        ?HttpClientInterface $httpClient = null,
        private ?\App\Services\Settings\SettingsManager $settingsManager = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => 300,
            'max_duration' => 600,
        ]);
    }

    /**
     * Resolve the current model from settings (allows runtime changes via /model, /fast).
     */
    private function resolveModel(): string
    {
        if ($this->settingsManager) {
            return $this->settingsManager->getModel() ?? $this->model;
        }

        return $this->model;
    }

    /**
     * Resolve max tokens from settings if available.
     */
    private function resolveMaxTokens(): int
    {
        if ($this->settingsManager) {
            return (int) ($this->settingsManager->getMaxTokens() ?? $this->maxTokens);
        }

        return $this->maxTokens;
    }

    /**
     * Resolve API key from settings if available.
     */
    private function resolveApiKey(): string
    {
        if ($this->settingsManager) {
            return $this->settingsManager->getApiKey() ?: $this->apiKey;
        }

        return $this->apiKey;
    }

    /**
     * Resolve thinking enabled from settings (allows runtime changes via /effort).
     */
    private function resolveThinkingEnabled(): bool
    {
        if ($this->settingsManager) {
            return $this->settingsManager->isThinkingEnabled();
        }

        return $this->thinkingEnabled;
    }

    /**
     * Resolve thinking budget from settings (allows runtime changes via /effort).
     */
    private function resolveThinkingBudget(): int
    {
        if ($this->settingsManager) {
            return $this->settingsManager->getThinkingBudget();
        }

        return $this->thinkingBudget;
    }

    /**
     * Resolve base URL from settings if available.
     */
    private function resolveBaseUrl(): string
    {
        if ($this->settingsManager) {
            return $this->settingsManager->getBaseUrl() ?: $this->baseUrl;
        }

        return $this->baseUrl;
    }

    /**
     * Stream a messages.create call with retry logic.
     *
     * @return \Generator<StreamEvent>
     */
    public function streamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent = null,
        ?callable $shouldAbort = null,
    ): \Generator {
        $attempt = 0;

        while (true) {
            if ($shouldAbort && $shouldAbort()) {
                return;
            }

            $hasYieldedEvents = false;

            try {
                foreach ($this->doStreamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort) as $event) {
                    $hasYieldedEvents = true;
                    yield $event;
                }
                return;
            } catch (\Throwable $e) {
                if ($shouldAbort && $shouldAbort()) {
                    return;
                }

                // Once a streaming response has started, retrying inside this low-level
                // client is unsafe because the caller may already have rendered text or
                // started executing tools from the first attempt.
                if ($hasYieldedEvents) {
                    throw $this->normalizeTransportException($e);
                }

                $attempt++;

                if (!$this->shouldRetry($e, $attempt)) {
                    throw $this->normalizeTransportException($e);
                }

                $delay = $this->getRetryDelay($attempt, $e);
                usleep((int) ($delay * 1000000));
            }
        }
    }

    private function doStreamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent,
        ?callable $shouldAbort,
    ): \Generator {
        $baseUrl = $this->resolveBaseUrl();
        $payload = [
            'model' => $this->resolveModel(),
            'max_tokens' => $this->resolveMaxTokens(),
            'system' => $systemPrompt,
            'messages' => $messages,
            'stream' => true,
        ];

        // Enable extended thinking if configured (resolves at runtime for /effort)
        $thinkingEnabled = $this->resolveThinkingEnabled();
        if ($thinkingEnabled) {
            $thinkingBudget = $this->resolveThinkingBudget();
            $payload['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $thinkingBudget,
            ];
            // Extended thinking requires higher max_tokens
            $payload['max_tokens'] = max($this->resolveMaxTokens(), $thinkingBudget + 4096);
        }

        if (count($tools) > 0) {
            // Add cache_control breakpoint on the last tool for prompt caching
            $toolsWithCache = $tools;
            $lastIdx = count($toolsWithCache) - 1;
            $toolsWithCache[$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
            $payload['tools'] = $toolsWithCache;
        }

        $response = $this->httpClient->request('POST', rtrim($baseUrl, '/') . '/v1/messages', [
            'headers' => [
                'x-api-key' => $this->resolveApiKey(),
                'anthropic-version' => $this->apiVersion,
                'anthropic-beta' => 'prompt-caching-2024-07-31',
                'content-type' => 'application/json',
                'accept' => 'text/event-stream',
            ],
            'body' => $this->encodePayload($payload),
            'buffer' => false,
            'http_version' => $this->preferredHttpVersion($baseUrl),
            'verify_peer' => true,
            'verify_host' => true,
        ]);

        if ($shouldAbort && $shouldAbort()) {
            $this->cancelResponse($response);

            return;
        }

        $this->throwForHttpError($response);
        $this->extractRateLimitHeaders($response);

        $currentEvent = null;
        $currentDataLines = [];
        $lineBuffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($shouldAbort && $shouldAbort()) {
                $this->cancelResponse($response);

                return;
            }

            $content = $chunk->getContent();

            $lineBuffer .= $content;

            while (($newlinePos = strpos($lineBuffer, "\n")) !== false) {
                $line = substr($lineBuffer, 0, $newlinePos);
                $lineBuffer = substr($lineBuffer, $newlinePos + 1);

                $events = $this->processSseLine(
                    rtrim($line, "\r"),
                    $currentEvent,
                    $currentDataLines,
                    $onRawEvent,
                );

                foreach ($events as $event) {
                    if ($shouldAbort && $shouldAbort()) {
                        $this->cancelResponse($response);

                        return;
                    }

                    yield $event;
                }
            }
        }

        if ($shouldAbort && $shouldAbort()) {
            $this->cancelResponse($response);

            return;
        }

        if ($lineBuffer !== '') {
            $events = $this->processSseLine(
                rtrim($lineBuffer, "\r"),
                $currentEvent,
                $currentDataLines,
                $onRawEvent,
            );

            foreach ($events as $event) {
                if ($shouldAbort && $shouldAbort()) {
                    $this->cancelResponse($response);

                    return;
                }

                yield $event;
            }
        }

        $event = $this->emitCurrentEvent($currentEvent, $currentDataLines, $onRawEvent);
        if ($event !== null) {
            if ($shouldAbort && $shouldAbort()) {
                $this->cancelResponse($response);

                return;
            }

            yield $event;
        }
    }

    /**
     * @param array<int, string> $currentDataLines
     */
    private function processSseLine(
        string $line,
        ?string &$currentEvent,
        array &$currentDataLines,
        ?callable $onRawEvent,
    ): array {
        $events = [];

        if (str_starts_with($line, 'event:')) {
            $pendingEvent = $this->emitCurrentEvent($currentEvent, $currentDataLines, $onRawEvent);
            if ($pendingEvent !== null) {
                $events[] = $pendingEvent;
            }

            $currentEvent = trim(substr($line, 6));
            return $events;
        }

        if (str_starts_with($line, 'data:')) {
            $dataLine = substr($line, 5);
            if (str_starts_with($dataLine, ' ')) {
                $dataLine = substr($dataLine, 1);
            }
            $currentDataLines[] = $dataLine;

            return $events;
        }

        if ($line === '') {
            $event = $this->emitCurrentEvent($currentEvent, $currentDataLines, $onRawEvent);
            if ($event !== null) {
                $events[] = $event;
            }

            return $events;
        }

        return $events;
    }

    /**
     * @param array<int, string> $currentDataLines
     */
    private function emitCurrentEvent(
        ?string &$currentEvent,
        array &$currentDataLines,
        ?callable $onRawEvent,
    ): ?StreamEvent {
        if ($currentEvent === null || $currentDataLines === []) {
            $currentEvent = null;
            $currentDataLines = [];

            return null;
        }

        $event = StreamEvent::fromSse($currentEvent, implode("\n", $currentDataLines));

        if ($currentEvent === 'error') {
            $errorMsg = $event->data['error']['message'] ?? 'Unknown API error';
            $errorType = $event->data['error']['type'] ?? 'unknown';
            throw new ApiErrorException($errorMsg, $errorType);
        }

        if ($onRawEvent) {
            $onRawEvent($event);
        }

        $currentEvent = null;
        $currentDataLines = [];

        return $event;
    }

    private function shouldRetry(\Throwable $e, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        if ($e instanceof ApiErrorException) {
            return in_array($e->getErrorType(), [
                'overloaded_error',
                'rate_limit_error',
                'api_error',
            ]);
        }

        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface) {
            return true;
        }
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return true;
        }

        return false;
    }

    private function getRetryDelay(int $attempt, \Throwable $e): float
    {
        if ($e instanceof ApiErrorException && $e->getErrorType() === 'rate_limit_error') {
            return min(2 ** $attempt, 30);
        }
        return min(2 ** $attempt, 10);
    }

    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiErrorException(
                'Failed to encode request payload: ' . $e->getMessage(),
                'request_encoding_error',
                previous: $e,
            );
        }
    }

    private function throwForHttpError(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 400) {
            return;
        }

        $body = trim($response->getContent(false));
        $url = (string) $response->getInfo('url');
        $message = $body !== '' ? $body : "HTTP {$statusCode} returned for \"{$url}\".";
        $errorType = 'http_error';

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (is_array($decoded['error'] ?? null)) {
                $errorType = is_string($decoded['error']['type'] ?? null)
                    ? $decoded['error']['type']
                    : $errorType;
                $message = is_string($decoded['error']['message'] ?? null)
                    ? $decoded['error']['message']
                    : $message;
            } elseif (is_string($decoded['message'] ?? null)) {
                $message = $decoded['message'];
            } elseif (is_string($decoded['error'] ?? null)) {
                $message = $decoded['error'];
            }
        }

        throw new ApiErrorException($message, $errorType, $statusCode);
    }

    private function preferredHttpVersion(?string $baseUrl = null): ?string
    {
        $host = (string) parse_url($baseUrl ?? $this->resolveBaseUrl(), PHP_URL_HOST);

        if ($host === 'api.kimi.com') {
            return '1.1';
        }

        return null;
    }

    private function normalizeTransportException(\Throwable $e): \Throwable
    {
        if ($e instanceof ApiErrorException) {
            return $e;
        }

        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return new ApiErrorException(
                'Network transport error while streaming response: ' . $e->getMessage(),
                'transport_error',
                previous: $e,
            );
        }

        return $e;
    }

    /**
     * Extract rate limit headers from the API response.
     */
    private function extractRateLimitHeaders(ResponseInterface $response): void
    {
        $headers = $response->getHeaders(false);
        $this->lastRateLimitHeaders = [];

        $prefixes = [
            'anthropic-ratelimit-',
            'x-ratelimit-',
            'retry-after',
        ];

        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            foreach ($prefixes as $prefix) {
                if (str_starts_with($lower, $prefix) || $lower === 'retry-after') {
                    $this->lastRateLimitHeaders[$lower] = $values[0] ?? '';
                    break;
                }
            }
        }
    }

    /**
     * Get the rate limit headers from the last API response.
     *
     * @return array<string, string>
     */
    public function getLastRateLimitHeaders(): array
    {
        return $this->lastRateLimitHeaders;
    }

    private function cancelResponse(ResponseInterface $response): void
    {
        $response->cancel();
    }
}
