<?php

namespace Tests\Unit;

use App\Support\Terminal\TranscriptRenderer;
use PHPUnit\Framework\TestCase;

class TranscriptRendererTest extends TestCase
{
    public function test_it_renders_user_and_assistant_messages(): void
    {
        $renderer = new TranscriptRenderer;

        $text = $renderer->render([
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi there'],
        ]);

        $this->assertStringContainsString("You\n  hello", $text);
        $this->assertStringContainsString("Hao\n  hi there", $text);
    }

    public function test_it_renders_tool_use_and_tool_result_blocks(): void
    {
        $renderer = new TranscriptRenderer;

        $text = $renderer->render([
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
                    ['type' => 'text', 'text' => 'done'],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'file-a', 'is_error' => false],
                ],
            ],
        ]);

        $this->assertStringContainsString('[Tool: Bash]', $text);
        $this->assertStringContainsString('"command":"ls"', $text);
        $this->assertStringContainsString('Tool result (toolu_1)', $text);
        $this->assertStringContainsString('done', $text);
    }

    public function test_it_renders_tool_error_result(): void
    {
        $renderer = new TranscriptRenderer;

        $text = $renderer->render([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => 'Failed', 'is_error' => true],
                ],
            ],
        ]);

        $this->assertStringContainsString('Tool error (toolu_2)', $text);
        $this->assertStringContainsString('Failed', $text);
    }

    public function test_it_renders_unknown_role(): void
    {
        $renderer = new TranscriptRenderer;

        $text = $renderer->render([
            ['role' => 'system', 'content' => 'System message'],
        ]);

        $this->assertStringContainsString("Message\n  System message", $text);
    }

    public function test_it_skips_empty_content(): void
    {
        $renderer = new TranscriptRenderer;

        $text = $renderer->render([
            ['role' => 'assistant', 'content' => ''],
            ['role' => 'user', 'content' => 'hello'],
        ]);

        $this->assertStringNotContainsString("Hao\n  ", $text);
        $this->assertStringContainsString("You\n  hello", $text);
    }

    public function test_it_renders_array_content_without_tool_results(): void
    {
        $renderer = new TranscriptRenderer;

        $text = $renderer->render([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'ignored']]],
        ]);

        $this->assertSame('', $text);
    }

    public function test_it_indents_multiline_text(): void
    {
        $renderer = new TranscriptRenderer;

        $text = $renderer->render([
            ['role' => 'user', 'content' => "line1\nline2\nline3"],
        ]);

        $this->assertStringContainsString("  line1\n  line2\n  line3", $text);
    }

    public function test_it_truncates_long_tool_input(): void
    {
        $renderer = new TranscriptRenderer;

        $longInput = ['data' => str_repeat('x', 300)];
        $text = $renderer->render([
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'tool_use', 'id' => 't1', 'name' => 'Write', 'input' => $longInput],
                ],
            ],
        ]);

        $this->assertStringContainsString('...', $text);
    }
}
