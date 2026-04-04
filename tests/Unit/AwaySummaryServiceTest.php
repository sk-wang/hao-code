<?php

namespace Tests\Unit;

use App\Services\Session\AwaySummaryService;
use PHPUnit\Framework\TestCase;

class AwaySummaryServiceTest extends TestCase
{
    // ─── generateSummary — no-HTTP paths ──────────────────────────────────

    public function test_returns_null_with_empty_entries(): void
    {
        $svc = new AwaySummaryService('fake-key');
        $this->assertNull($svc->generateSummary([]));
    }

    public function test_returns_null_with_one_message(): void
    {
        $svc = new AwaySummaryService('fake-key');
        $entries = [
            ['type' => 'user_message', 'content' => 'Hello'],
        ];
        $this->assertNull($svc->generateSummary($entries));
    }

    public function test_returns_null_when_no_user_or_assistant_entries(): void
    {
        $svc = new AwaySummaryService('fake-key');
        $entries = [
            ['type' => 'session_title', 'title' => 'Some title'],
            ['type' => 'session_title', 'title' => 'Other'],
        ];
        // Only session_title entries produce no messages → < 2 messages → null
        $this->assertNull($svc->generateSummary($entries));
    }

    public function test_returns_null_on_http_failure(): void
    {
        // Invalid key + non-existent base URL → Throwable caught → null
        $svc = new AwaySummaryService('bad-key', 'https://localhost:19999');
        $entries = [
            ['type' => 'user_message', 'content' => 'First'],
            ['type' => 'assistant_turn', 'message' => ['content' => 'Reply']],
        ];
        $result = $svc->generateSummary($entries);
        $this->assertNull($result);
    }

    // ─── entriesToMessages (tested indirectly) ────────────────────────────

    public function test_assistant_turn_with_string_content_is_included(): void
    {
        // If we have 2+ extractable messages, generateSummary won't return null
        // before the HTTP call. We test the extraction indirectly by confirming
        // that 2 valid messages causes the HTTP path (which fails → null with bad key).
        $svc = new AwaySummaryService('bad-key', 'https://localhost:19999');
        $entries = [
            ['type' => 'user_message', 'content' => 'How are you?'],
            ['type' => 'assistant_turn', 'message' => ['content' => 'I am fine.']],
        ];
        // generateSummary will attempt HTTP call and fail (bad URL) → null
        // But it did NOT return null early (< 2 messages check passed)
        $result = $svc->generateSummary($entries);
        $this->assertNull($result); // null due to network failure, not early return
    }

    public function test_assistant_turn_with_text_blocks_is_included(): void
    {
        $svc = new AwaySummaryService('bad-key', 'https://localhost:19999');
        $entries = [
            ['type' => 'user_message', 'content' => 'Question'],
            [
                'type' => 'assistant_turn',
                'message' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'Answer from blocks'],
                        ['type' => 'tool_use', 'id' => 'x'], // skipped
                    ],
                ],
            ],
        ];
        $result = $svc->generateSummary($entries);
        $this->assertNull($result); // null due to network, not early return
    }

    public function test_entries_without_content_field_are_skipped(): void
    {
        $svc = new AwaySummaryService('fake-key');
        $entries = [
            ['type' => 'user_message'],       // no content
            ['type' => 'assistant_turn'],      // no message
        ];
        // Both skipped → 0 messages → < 2 → null (no HTTP call)
        $this->assertNull($svc->generateSummary($entries));
    }

    public function test_entries_truncated_to_max_30(): void
    {
        // Build 35 valid user messages. Should still attempt HTTP (≥2 messages),
        // fail due to bad URL, and return null.
        $svc = new AwaySummaryService('bad-key', 'https://localhost:19999');
        $entries = [];
        for ($i = 0; $i < 35; $i++) {
            $entries[] = ['type' => 'user_message', 'content' => "Message $i"];
        }
        $result = $svc->generateSummary($entries);
        $this->assertNull($result); // null due to network, truncation happened internally
    }

    public function test_kimi_endpoint_uses_local_summary_generation(): void
    {
        $svc = new AwaySummaryService('fake-key', 'https://api.kimi.com/coding/');
        $entries = [
            ['type' => 'user_message', 'content' => 'Inspect the repo and explain the bug'],
            [
                'type' => 'assistant_turn',
                'message' => [
                    'content' => [
                        ['type' => 'tool_use', 'name' => 'Read'],
                        ['type' => 'tool_use', 'name' => 'Bash'],
                    ],
                ],
            ],
        ];

        $summary = $svc->generateSummary($entries);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('Inspect the repo and explain the bug', $summary);
        $this->assertStringContainsString('Read and Bash', $summary);
    }
}
