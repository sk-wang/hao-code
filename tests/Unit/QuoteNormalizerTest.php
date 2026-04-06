<?php

namespace Tests\Unit;

use App\Tools\FileEdit\QuoteNormalizer;
use PHPUnit\Framework\TestCase;

class QuoteNormalizerTest extends TestCase
{
    public function test_normalize_replaces_curly_quotes(): void
    {
        $input = "\u{201C}hello\u{201D} and \u{2018}world\u{2019}";
        $this->assertSame('"hello" and \'world\'', QuoteNormalizer::normalize($input));
    }

    public function test_normalize_leaves_straight_quotes_alone(): void
    {
        $this->assertSame('"hello"', QuoteNormalizer::normalize('"hello"'));
    }

    public function test_find_actual_string_exact_match(): void
    {
        $result = QuoteNormalizer::findActualString('hello world', 'world');
        $this->assertSame('world', $result);
    }

    public function test_find_actual_string_curly_quote_fallback(): void
    {
        $fileContent = "She said \u{201C}hello\u{201D}";
        $search = 'She said "hello"'; // straight quotes from model

        $result = QuoteNormalizer::findActualString($fileContent, $search);
        $this->assertNotNull($result);
        $this->assertStringContainsString("\u{201C}", $result); // preserves curly quotes
    }

    public function test_find_actual_string_returns_null_when_not_found(): void
    {
        $this->assertNull(QuoteNormalizer::findActualString('hello', 'world'));
    }

    public function test_count_occurrences_exact(): void
    {
        $this->assertSame(2, QuoteNormalizer::countOccurrences('abc abc def', 'abc'));
    }

    public function test_count_occurrences_via_normalization(): void
    {
        $file = "\u{201C}a\u{201D} and \u{201C}b\u{201D}";
        $count = QuoteNormalizer::countOccurrences($file, '"'); // straight double quote
        // The normalized file has 4 straight double quotes
        // But we're counting the normalized search in normalized file
        $this->assertGreaterThan(0, $count);
    }

    public function test_preserve_quote_style_no_change(): void
    {
        $result = QuoteNormalizer::preserveQuoteStyle('same', 'same', 'new');
        $this->assertSame('new', $result);
    }

    public function test_preserve_quote_style_applies_curly_doubles(): void
    {
        $old = '"hello"';
        $actual = "\u{201C}hello\u{201D}";
        $new = '"world"';

        $result = QuoteNormalizer::preserveQuoteStyle($old, $actual, $new);
        $this->assertStringContainsString("\u{201C}", $result);
        $this->assertStringContainsString("\u{201D}", $result);
    }

    public function test_preserve_quote_style_handles_apostrophes(): void
    {
        $old = "don't";
        $actual = "don\u{2019}t"; // curly apostrophe
        $new = "won't";

        $result = QuoteNormalizer::preserveQuoteStyle($old, $actual, $new);
        $this->assertStringContainsString("\u{2019}", $result); // curly apostrophe preserved
    }
}
