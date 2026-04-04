<?php

namespace Tests\Unit;

use App\Support\Terminal\TranscriptBuffer;
use PHPUnit\Framework\TestCase;

class TranscriptBufferTest extends TestCase
{
    public function test_it_slices_lines_for_a_page(): void
    {
        $buffer = new TranscriptBuffer("a\nb\nc\nd");

        $this->assertSame(['b', 'c'], $buffer->slice(1, 2));
    }

    public function test_it_clamps_offsets_into_valid_range(): void
    {
        $buffer = new TranscriptBuffer("a\nb\nc\nd");

        $this->assertSame(0, $buffer->clampOffset(-5, 2));
        $this->assertSame(2, $buffer->clampOffset(20, 2));
    }

    public function test_it_finds_matching_lines_case_insensitively(): void
    {
        $buffer = new TranscriptBuffer("alpha\nBeta test\ngamma test");

        $this->assertSame([1, 2], $buffer->findMatches('TEST'));
    }

    public function test_it_highlights_matching_text(): void
    {
        $buffer = new TranscriptBuffer('hello world');

        $this->assertSame(
            'hello <fg=black;bg=yellow>world</>',
            $buffer->highlight('hello world', 'world'),
        );
    }

    public function test_line_count_counts_lines(): void
    {
        $buffer = new TranscriptBuffer("line1\nline2\nline3");
        $this->assertSame(3, $buffer->lineCount());
    }

    public function test_line_count_normalizes_line_endings(): void
    {
        $buffer = new TranscriptBuffer("line1\r\nline2\rline3");
        $this->assertSame(3, $buffer->lineCount());
    }

    public function test_line_count_empty_transcript(): void
    {
        $buffer = new TranscriptBuffer('');
        $this->assertSame(1, $buffer->lineCount());
    }

    public function test_slice_past_end(): void
    {
        $buffer = new TranscriptBuffer("a\nb\nc");
        $slice = $buffer->slice(2, 10);
        $this->assertSame(['c'], $slice);
    }

    public function test_slice_clamps_negative_height(): void
    {
        $buffer = new TranscriptBuffer("a\nb\nc");
        $slice = $buffer->slice(0, -1);
        $this->assertSame([], $slice);
    }

    public function test_clamp_offset_with_zero_height(): void
    {
        $buffer = new TranscriptBuffer("a\nb\nc");
        // With height=0, max(1, 0)=1, so maxOffset=3-1=2
        $this->assertSame(2, $buffer->clampOffset(10, 0));
    }

    public function test_find_matches_empty_query_returns_empty(): void
    {
        $buffer = new TranscriptBuffer("hello\nworld");
        $this->assertSame([], $buffer->findMatches(''));
    }

    public function test_find_matches_case_insensitive(): void
    {
        $buffer = new TranscriptBuffer("Hello\nWORLD");
        $matches = $buffer->findMatches('hello');
        $this->assertSame([0], $matches);
    }

    public function test_page_offset_for_line_clamps(): void
    {
        $buffer = new TranscriptBuffer("a\nb\nc\nd\ne");
        $this->assertSame(0, $buffer->pageOffsetForLine(0, 3));
        $this->assertSame(2, $buffer->pageOffsetForLine(4, 3));
    }

    public function test_highlight_empty_query_returns_line(): void
    {
        $buffer = new TranscriptBuffer("hello world");
        $result = $buffer->highlight('hello world', '');
        $this->assertSame('hello world', $result);
    }

    public function test_highlight_case_insensitive(): void
    {
        $buffer = new TranscriptBuffer("Hello World");
        $result = $buffer->highlight('Hello World', 'world');
        $this->assertStringContainsString('<fg=black;bg=yellow>World</>', $result);
    }

    public function test_highlight_multiple_occurrences(): void
    {
        $buffer = new TranscriptBuffer("foo foo foo");
        $result = $buffer->highlight('foo foo foo', 'foo');
        $count = substr_count($result, '<fg=black;bg=yellow>');
        $this->assertSame(3, $count);
    }
}
