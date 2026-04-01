<?php

namespace App\Services\Api;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class StreamingClient
{
    private HttpClientInterface $httpClient;
    private int $maxRetries = 3;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly int $maxTokens = 16384,
        private readonly string $apiVersion = '2023-06-01',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => 300,
            'max_duration' => 600,
            'verify_peer' => false,
            'verify_host' => false,
        ]);
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
    ): \Generator {
        $attempt = 0;

        while (true) {
            try {
                foreach ($this->doStreamMessages($systemPrompt, $messages, $tools, $onRawEvent) as $event) {
                    yield $event;
                }
                return;
            } catch (\Throwable $e) {
                $attempt++;

                if (!$this->shouldRetry($e, $attempt)) {
                    throw $e;
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
    ): \Generator {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
            'stream' => true,
        ];
        if (count($tools) > 0) {
            $payload['tools'] = $tools;
        }

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'content-type' => 'application/json',
                'accept' => 'text/event-stream',
            ],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'buffer' => false,
        ]);

        $currentEvent = null;
        $currentData = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            try {
                $content = $chunk->getContent();
            } catch (\Throwable) {
                continue;
            }

            foreach (explode("\n", $content) as $line) {
                $line = rtrim($line);

                if (str_starts_with($line, 'event:')) {
                    $currentEvent = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $currentData .= substr($line, 5);
                } elseif ($line === '' && $currentEvent !== null && $currentData !== '') {
                    $event = StreamEvent::fromSse($currentEvent, $currentData);

                    if ($currentEvent === 'error') {
                        $errorMsg = $event->data['error']['message'] ?? 'Unknown API error';
                        $errorType = $event->data['error']['type'] ?? 'unknown';
                        throw new ApiErrorException($errorMsg, $errorType);
                    }

                    if ($onRawEvent) {
                        $onRawEvent($event);
                    }

                    yield $event;

                    $currentEvent = null;
                    $currentData = '';
                }
            }
        }
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
}
