<?php

namespace Tests\Unit;

use App\Support\Terminal\InputSanitizer;
use PHPUnit\Framework\TestCase;

class InputSanitizerTest extends TestCase
{
    public function test_it_preserves_valid_utf8_input(): void
    {
        $input = '这个代码库是干嘛的';

        $this->assertSame($input, InputSanitizer::sanitize($input));
    }

    public function test_it_strips_invalid_utf8_bytes_without_losing_valid_text(): void
    {
        $input = "你好\xB1，世界";

        $this->assertSame('你好，世界', InputSanitizer::sanitize($input));
    }

    public function test_it_returns_empty_string_unchanged(): void
    {
        $this->assertSame('', InputSanitizer::sanitize(''));
    }

    public function test_it_preserves_ascii_input(): void
    {
        $this->assertSame('Hello, world!', InputSanitizer::sanitize('Hello, world!'));
    }

    public function test_it_preserves_mixed_unicode_and_ascii(): void
    {
        $input = 'Hello 世界 foo';
        $this->assertSame($input, InputSanitizer::sanitize($input));
    }

    public function test_it_strips_lone_invalid_byte_sequence(): void
    {
        // \x80 is not valid UTF-8 as a standalone byte
        $result = InputSanitizer::sanitize("before\x80after");
        $this->assertStringNotContainsString("\x80", $result);
        $this->assertStringContainsString('before', $result);
        $this->assertStringContainsString('after', $result);
    }
}
