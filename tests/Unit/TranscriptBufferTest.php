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
}
