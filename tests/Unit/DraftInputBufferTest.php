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

    public function test_it_marks_large_pastes_for_collapsed_preview(): void
    {
        $buffer = new DraftInputBuffer('Explain: ');

        $buffer->paste(str_repeat('x', 900));

        $this->assertSame('Explain: ' . str_repeat('x', 900), $buffer->text());
        $this->assertSame([
            'prefix' => 'Explain: ',
            'suffix' => '',
            'char_count' => 900,
            'line_count' => 0,
            'byte_count' => 900,
        ], $buffer->collapsedPastePreview());
    }

    public function test_it_clears_the_collapsed_paste_preview_after_follow_up_edits(): void
    {
        $buffer = new DraftInputBuffer('Explain: ');
        $buffer->paste(str_repeat('x', 900));

        $this->assertNotNull($buffer->collapsedPastePreview());

        $buffer->moveLeft();

        $this->assertNull($buffer->collapsedPastePreview());
    }

    public function test_it_moves_between_lines_and_edits_a_multiline_draft(): void
    {
        $buffer = new DraftInputBuffer("first line\nsecond line");

        $this->assertTrue($buffer->moveUp());
        $this->assertSame(0, $buffer->currentLineIndex());
        $this->assertSame('first line', $buffer->currentLine());
        $this->assertSame(10, $buffer->cursorPosition());

        $buffer->insert('!');
        $this->assertSame("first line!\nsecond line", $buffer->text());

        $this->assertTrue($buffer->moveDown());
        $this->assertSame(1, $buffer->currentLineIndex());
        $this->assertSame('second line', $buffer->currentLine());

        $buffer->moveHome();
        $buffer->insert('> ');

        $this->assertSame("first line!\n> second line", $buffer->text());
        $this->assertSame(['first line!', '> second line'], $buffer->visibleLines());
    }

    public function test_backspace_and_delete_can_join_adjacent_lines(): void
    {
        $backspaceBuffer = new DraftInputBuffer("alpha\nbeta");
        $backspaceBuffer->moveHome();

        $this->assertTrue($backspaceBuffer->backspace());
        $this->assertSame('alphabeta', $backspaceBuffer->text());
        $this->assertSame(0, $backspaceBuffer->currentLineIndex());
        $this->assertSame(5, $backspaceBuffer->cursorPosition());

        $deleteBuffer = new DraftInputBuffer("alpha\nbeta");
        $deleteBuffer->moveUp();
        $deleteBuffer->moveEnd();

        $this->assertTrue($deleteBuffer->delete());
        $this->assertSame('alphabeta', $deleteBuffer->text());
        $this->assertSame(0, $deleteBuffer->currentLineIndex());
        $this->assertSame(5, $deleteBuffer->cursorPosition());
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
