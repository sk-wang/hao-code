<?php

namespace App\Services\Session;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Generate an "While you were away" summary when resuming a session.
 *
 * Mirrors claude-code's awaySummary.ts: sends recent session messages to Haiku
 * and returns a 1-3 sentence recap focused on what was accomplished and what
 * the next step is.  Returns null on any failure.
 */
class AwaySummaryService
{
    private const HAIKU_MODEL = 'claude-haiku-4-20250514';
    private const MAX_MESSAGES = 30;

    private const PROMPT = <<<'PROMPT'
You are summarising a coding session for a user who is returning after being away.

Write a 1-3 sentence "While you were away" recap of what the AI assistant did.
- Focus on tasks completed and the current state
- Mention the immediate next step if clear
- Do NOT mention commit hashes, status summaries, or generic phrases like "the session"
- Write in past tense, second person ("The assistant fixed ...", "A new endpoint was added ...")
- Be concrete and specific

Return only the summary text — no JSON, no headers, no markdown.
PROMPT;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {}

    /**
     * Generate an away summary from session JSONL entries.
     *
     * @param array $entries  Raw JSONL entries from the session file
     * @return string|null  1-3 sentence recap, or null on failure
     */
    public function generateSummary(array $entries): ?string
    {
        $messages = $this->entriesToMessages($entries);

        if (count($messages) < 2) {
            return null;
        }

        // Take the most recent N messages
        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }

        try {
            return $this->callHaiku($messages);
        } catch (\Throwable) {
            return null;
        }
    }

    private function entriesToMessages(array $entries): array
    {
        $messages = [];

        foreach ($entries as $entry) {
            $type = $entry['type'] ?? '';

            if ($type === 'user_message' && isset($entry['content'])) {
                $messages[] = ['role' => 'user', 'content' => $entry['content']];
            } elseif ($type === 'assistant_turn' && isset($entry['message'])) {
                $msg = $entry['message'];
                $content = $msg['content'] ?? '';

                // Extract only text blocks
                $text = '';
                if (is_string($content)) {
                    $text = $content;
                } elseif (is_array($content)) {
                    foreach ($content as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $text .= ($block['text'] ?? '');
                        }
                    }
                }

                if (trim($text) !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => trim($text)];
                }
            }
        }

        return $messages;
    }

    private function callHaiku(array $messages): ?string
    {
        // Build a condensed transcript for Haiku
        $transcript = '';
        foreach ($messages as $msg) {
            $role = ucfirst($msg['role']);
            $content = mb_substr($msg['content'], 0, 500);
            $transcript .= "[{$role}]: {$content}\n\n";
        }

        $client = HttpClient::create(['timeout' => 15]);

        $response = $client->request('POST', $this->baseUrl . '/v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => self::HAIKU_MODEL,
                'max_tokens' => 150,
                'system' => self::PROMPT,
                'messages' => [
                    ['role' => 'user', 'content' => $transcript],
                ],
            ],
        ]);

        $body = $response->toArray();
        $summary = trim($body['content'][0]['text'] ?? '');

        return $summary !== '' ? $summary : null;
    }
}
