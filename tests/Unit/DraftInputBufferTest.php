<?php

namespace Tests\Unit;

use App\Support\Terminal\DraftInputBuffer;
use PHPUnit\Framework\TestCase;

class DraftInputBufferTest extends TestCase
{
    public function test_it_supports_cursor_navigation_and_insertion_within_the_current_line(): void
    {
        $buffer = new DraftInputBuffer('abcd');

        $buffer->moveLeft();
        $buffer->moveLeft();
        $buffer->insert('X');

        $this->assertSame('abXcd', $buffer->text());
        $this->assertSame('abXcd', $buffer->currentLine());
        $this->assertSame(3, $buffer->cursorPosition());
        $this->assertSame(['abXcd'], $buffer->visibleLines());
    }

    public function test_it_supports_backspace_delete_home_and_end_on_the_current_line(): void
    {
        $buffer = new DraftInputBuffer('abcd');

        $buffer->moveLeft();
        $buffer->backspace();
        $this->assertSame('abd', $buffer->text());
        $this->assertSame(2, $buffer->cursorPosition());

        $buffer->moveHome();
        $buffer->delete();
        $this->assertSame('bd', $buffer->text());
        $this->assertSame(0, $buffer->cursorPosition());

        $buffer->moveEnd();
        $this->assertSame(2, $buffer->cursorPosition());
    }

    public function test_it_pastes_multiline_text_into_the_draft_and_keeps_the_suffix_on_the_last_line(): void
    {
        $buffer = new DraftInputBuffer('world');

        $buffer->moveHome();
        $buffer->paste("hello\nbig ");

        $this->assertSame("hello\nbig world", $buffer->text());
        $this->assertSame(['hello', 'big world'], $buffer->visibleLines());
        $this->assertSame(['hello'], $buffer->committedLines());
        $this->assertSame('big world', $buffer->currentLine());
        $this->assertSame(4, $buffer->cursorPosition());
    }

    public function test_it_commits_a_trailing_backslash_as_a_continuation_line(): void
    {
        $buffer = new DraftInputBuffer('first \\');

        $this->assertTrue($buffer->commitContinuationLine());
        $this->assertSame(['first'], $buffer->committedLines());
        $this->assertSame('', $buffer->currentLine());
        $this->assertSame(['first', ''], $buffer->visibleLines());
        $this->assertSame(0, $buffer->cursorPosition());
    }

    public function test_it_can_load_existing_multiline_text_into_the_draft(): void
    {
        $buffer = new DraftInputBuffer;

        $buffer->replaceWith("alpha\nbeta");

        $this->assertSame(['alpha'], $buffer->committedLines());
        $this->assertSame('beta', $buffer->currentLine());
        $this->assertSame(['alpha', 'beta'], $buffer->visibleLines());
        $this->assertSame(4, $buffer->cursorPosition());
    }
}
