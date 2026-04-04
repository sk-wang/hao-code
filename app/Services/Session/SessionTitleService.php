<?php

namespace App\Services\Session;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Generate a concise AI-powered session title via the Haiku model.
 *
 * Mirrors claude-code's sessionTitle.ts: extracts up to 1000 chars from the
 * conversation, sends a single request to Haiku, and returns a 3-7 word
 * sentence-case title.  Returns null on any failure so callers can degrade
 * gracefully.
 */
class SessionTitleService
{
    private const MAX_TEXT = 1000;
    private const HAIKU_MODEL = 'claude-haiku-4-20250514';

    private const PROMPT = <<<'PROMPT'
Generate a concise, sentence-case title (3-7 words) that captures the main topic or goal of this coding session. The title should be clear enough that the user recognises the session in a list. Use sentence case: capitalise only the first word and proper nouns.

Return JSON with a single "title" field.

Good examples:
{"title": "Fix login button on mobile"}
{"title": "Add OAuth authentication"}
{"title": "Debug failing CI tests"}
{"title": "Refactor API client error handling"}

Bad (too vague): {"title": "Code changes"}
Bad (too long): {"title": "Investigate and fix the issue where the login button does not respond on mobile devices"}
Bad (wrong case): {"title": "Fix Login Button On Mobile"}
PROMPT;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {}

    /**
     * Generate a session title from API-format message history.
     *
     * @param array $messages  API-format messages [{role, content}]
     * @return string|null  3-7 word title, or null on failure
     */
    public function generateTitle(array $messages): ?string
    {
        $text = $this->extractText($messages);

        if (trim($text) === '') {
            return null;
        }

        if ($this->shouldPreferLocalGeneration()) {
            return $this->buildLocalTitle($messages);
        }

        try {
            return $this->callHaiku($text);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractText(array $messages): string
    {
        $parts = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content)) {
                $parts[] = $content;
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $parts[] = $block['text'] ?? '';
                    }
                }
            }
        }

        $text = implode("\n", $parts);

        return mb_strlen($text) > self::MAX_TEXT
            ? mb_substr($text, -self::MAX_TEXT)
            : $text;
    }

    private function callHaiku(string $conversationText): ?string
    {
        $client = HttpClient::create(['timeout' => 15]);

        $response = $client->request('POST', $this->baseUrl . '/v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $this->resolveModel(),
                'max_tokens' => 64,
                'system' => self::PROMPT,
                'messages' => [
                    ['role' => 'user', 'content' => $conversationText],
                ],
            ],
        ]);

        $body = $response->toArray();
        $raw = $body['content'][0]['text'] ?? '';

        // Strip markdown code fences if present
        $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $raw = preg_replace('/\s*```$/', '', $raw);

        $decoded = json_decode(trim($raw), true);

        return isset($decoded['title']) && is_string($decoded['title'])
            ? trim($decoded['title'])
            : null;
    }

    private function shouldPreferLocalGeneration(): bool
    {
        return $this->isKimiCodingEndpoint();
    }

    private function isKimiCodingEndpoint(): bool
    {
        return str_contains(strtolower($this->baseUrl), 'api.kimi.com/coding');
    }

    private function resolveModel(): string
    {
        return $this->isKimiCodingEndpoint()
            ? 'kimi-for-coding'
            : self::HAIKU_MODEL;
    }

    private function buildLocalTitle(array $messages): ?string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $text = $this->extractMessageText($message['content'] ?? '');
            if ($text === '') {
                continue;
            }

            return $this->truncateTitle($text);
        }

        return $this->truncateTitle($this->extractText($messages));
    }

    private function extractMessageText(mixed $content): string
    {
        $parts = [];

        if (is_string($content)) {
            $parts[] = $content;
        } elseif (is_array($content)) {
            foreach ($content as $block) {
                if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $parts[] = $block['text'];
                }
            }
        }

        $text = preg_replace('/\s+/u', ' ', trim(implode(' ', $parts))) ?? '';
        $text = trim($text, " \t\n\r\0\x0B\"'`“”‘’");

        return rtrim($text, ".!?\x{3002}\x{FF01}\x{FF1F}");
    }

    private function truncateTitle(string $title): ?string
    {
        $title = $this->extractMessageText($title);
        if ($title === '') {
            return null;
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($title, 0, 48, '…', 'UTF-8');
        }

        return strlen($title) > 48
            ? substr($title, 0, 45) . '...'
            : $title;
    }
}
