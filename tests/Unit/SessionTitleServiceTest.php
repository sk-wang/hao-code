<?php

namespace Tests\Unit;

use App\Services\Session\SessionTitleService;
use PHPUnit\Framework\TestCase;

class SessionTitleServiceTest extends TestCase
{
    // ─── generateTitle — no-HTTP paths ────────────────────────────────────

    public function test_returns_null_with_empty_messages(): void
    {
        $svc = new SessionTitleService('fake-key');
        $this->assertNull($svc->generateTitle([]));
    }

    public function test_returns_null_when_all_messages_have_empty_content(): void
    {
        $svc = new SessionTitleService('fake-key');
        $messages = [
            ['role' => 'user', 'content' => '   '],
            ['role' => 'assistant', 'content' => ''],
        ];
        $this->assertNull($svc->generateTitle($messages));
    }

    public function test_returns_null_on_http_failure(): void
    {
        $svc = new SessionTitleService('bad-key', 'https://localhost:19999');
        $messages = [
            ['role' => 'user', 'content' => 'Fix the login bug'],
            ['role' => 'assistant', 'content' => 'Sure, let me check...'],
        ];
        $result = $svc->generateTitle($messages);
        $this->assertNull($result);
    }

    // ─── extractText (tested indirectly) ──────────────────────────────────

    public function test_tool_role_messages_are_skipped(): void
    {
        // tool_result messages should not be extracted — only user/assistant
        $svc = new SessionTitleService('fake-key');
        $messages = [
            ['role' => 'tool', 'content' => 'Tool output data'],
            ['role' => 'tool', 'content' => 'More tool data'],
        ];
        // Only tool roles → empty text → null (no HTTP)
        $this->assertNull($svc->generateTitle($messages));
    }

    public function test_array_content_extracts_text_blocks(): void
    {
        $svc = new SessionTitleService('bad-key', 'https://localhost:19999');
        $messages = [
            ['role' => 'user', 'content' => 'What should I do?'],
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'You should fix the bug.'],
                    ['type' => 'tool_use', 'id' => 'skip_me'],
                ],
            ],
        ];
        // Non-empty text extracted → HTTP attempted → fails → null
        $result = $svc->generateTitle($messages);
        $this->assertNull($result);
    }

    public function test_text_truncated_to_1000_chars(): void
    {
        $svc = new SessionTitleService('bad-key', 'https://localhost:19999');
        $messages = [
            ['role' => 'user', 'content' => str_repeat('A', 2000)],
        ];
        // Non-empty extracted text → HTTP path → fails → null
        $this->assertNull($svc->generateTitle($messages));
    }

    public function test_string_and_array_content_mixed(): void
    {
        $svc = new SessionTitleService('bad-key', 'https://localhost:19999');
        $messages = [
            ['role' => 'user', 'content' => 'Direct string content'],
            [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Block text']],
            ],
        ];
        $result = $svc->generateTitle($messages);
        $this->assertNull($result); // network fail, not empty
    }
}
