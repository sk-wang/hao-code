<?php

namespace Tests\Unit;

use App\Services\Agent\MessageHistory;
use PHPUnit\Framework\TestCase;

class MessageHistoryTest extends TestCase
{
    public function test_count_starts_at_zero(): void
    {
        $history = new MessageHistory;
        $this->assertSame(0, $history->count());
    }

    public function test_add_user_message_increments_count(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('hello');
        $this->assertSame(1, $history->count());
    }

    public function test_add_assistant_message_increments_count(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'hi']);
        $this->assertSame(1, $history->count());
    }

    public function test_add_tool_result_message_increments_count(): void
    {
        $history = new MessageHistory;
        $history->addToolResultMessage([
            ['tool_use_id' => 'toolu_1', 'content' => 'result', 'is_error' => false],
        ]);
        $this->assertSame(1, $history->count());
    }

    public function test_clear_resets_count_to_zero(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('a');
        $history->addUserMessage('b');
        $history->clear();
        $this->assertSame(0, $history->count());
    }

    public function test_get_messages_returns_raw_history_without_cache_control_mutation(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('first');
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'response']);
        $history->addUserMessage('second');

        $messages = $history->getMessages();

        $this->assertSame('response', $messages[1]['content']);
    }

    public function test_get_messages_for_api_returns_all_messages(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('prompt');

        $messages = $history->getMessagesForApi();
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
    }

    public function test_get_messages_for_api_adds_cache_control_to_penultimate_message(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('first');
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'response']);
        $history->addUserMessage('second');

        $messages = $history->getMessagesForApi();

        // Penultimate = index 1 (the assistant message)
        $penultimateContent = $messages[1]['content'];
        $this->assertIsArray($penultimateContent);
        $this->assertArrayHasKey('cache_control', $penultimateContent[count($penultimateContent) - 1]);
    }

    public function test_cache_control_not_added_when_fewer_than_three_messages(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('only one message');

        $messages = $history->getMessagesForApi();

        // With only 1 message, no cache_control should be injected
        $content = $messages[0]['content'];
        $this->assertIsString($content); // stays as plain string
    }

    public function test_cache_control_converts_string_content_to_block_array(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('msg1');
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'plain text response']);
        $history->addUserMessage('msg3');

        $messages = $history->getMessagesForApi();

        // The penultimate assistant message had string content; it should be converted
        $penultimate = $messages[1];
        $this->assertIsArray($penultimate['content']);
        $this->assertSame('text', $penultimate['content'][0]['type']);
        $this->assertSame('plain text response', $penultimate['content'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $penultimate['content'][0]['cache_control']);
    }

    public function test_tool_result_message_formats_content_blocks(): void
    {
        $history = new MessageHistory;
        $history->addToolResultMessage([
            ['tool_use_id' => 'toolu_abc', 'content' => 'file contents', 'is_error' => false],
            ['tool_use_id' => 'toolu_xyz', 'content' => 'error msg', 'is_error' => true],
        ]);

        $messages = $history->getMessagesForApi();
        $this->assertSame('user', $messages[0]['role']);
        $this->assertIsArray($messages[0]['content']);

        $blocks = $messages[0]['content'];
        $this->assertSame('tool_result', $blocks[0]['type']);
        $this->assertSame('toolu_abc', $blocks[0]['tool_use_id']);
        $this->assertFalse($blocks[0]['is_error']);
        $this->assertTrue($blocks[1]['is_error']);
    }

    public function test_empty_tool_input_is_normalized_to_object_for_api(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage([
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ExitPlanMode', 'input' => []],
            ],
        ]);

        $messages = $history->getMessagesForApi();

        $this->assertSame('{}', json_encode($messages[0]['content'][0]['input']));
    }

    // ─── getLastAssistantText ───────────────────────────────────────────────

    public function test_get_last_assistant_text_returns_null_when_empty(): void
    {
        $this->assertNull((new MessageHistory)->getLastAssistantText());
    }

    public function test_get_last_assistant_text_returns_string_content(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'hello world']);

        $this->assertSame('hello world', $history->getLastAssistantText());
    }

    public function test_get_last_assistant_text_reads_from_text_block(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage([
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Read', 'input' => []],
                ['type' => 'text', 'text' => 'here is the answer'],
            ],
        ]);

        $this->assertSame('here is the answer', $history->getLastAssistantText());
    }

    public function test_get_last_assistant_text_returns_null_when_no_text_block(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage([
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => []],
            ],
        ]);

        $this->assertNull($history->getLastAssistantText());
    }

    public function test_get_last_assistant_text_skips_user_messages(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'first answer']);
        $history->addUserMessage('follow-up question');

        // Most recent is a user message; fall back to the assistant message
        $this->assertSame('first answer', $history->getLastAssistantText());
    }

    public function test_get_last_assistant_text_returns_most_recent_when_multiple(): void
    {
        $history = new MessageHistory;
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'answer one']);
        $history->addUserMessage('continue');
        $history->addAssistantMessage(['role' => 'assistant', 'content' => 'answer two']);

        $this->assertSame('answer two', $history->getLastAssistantText());
    }

    public function test_get_last_assistant_text_returns_null_for_empty_string_content(): void
    {
        // An assistant message with empty string content should return null,
        // not the empty string itself — null signals "no text to display"
        $history = new MessageHistory;
        $history->addAssistantMessage(['role' => 'assistant', 'content' => '']);

        $this->assertNull($history->getLastAssistantText());
    }
}
