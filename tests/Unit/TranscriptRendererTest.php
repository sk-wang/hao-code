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
}
