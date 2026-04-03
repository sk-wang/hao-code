<?php

namespace Tests\Unit;

use App\Support\Terminal\TerminalOutput;
use PHPUnit\Framework\TestCase;

class TerminalOutputTest extends TestCase
{
    private function makeNoColor(): TerminalOutput
    {
        $t = new TerminalOutput;
        $ref = new \ReflectionClass($t);
        $prop = $ref->getProperty('supportsColor');
        $prop->setAccessible(true);
        $prop->setValue($t, false);
        return $t;
    }

    private function makeWithColor(): TerminalOutput
    {
        $t = new TerminalOutput;
        $ref = new \ReflectionClass($t);
        $prop = $ref->getProperty('supportsColor');
        $prop->setAccessible(true);
        $prop->setValue($t, true);
        return $t;
    }

    // ─── no-color mode returns plain text ─────────────────────────────────

    public function test_info_no_color_returns_text(): void
    {
        $this->assertSame('Hello', $this->makeNoColor()->info('Hello'));
    }

    public function test_success_no_color_returns_text(): void
    {
        $this->assertSame('OK', $this->makeNoColor()->success('OK'));
    }

    public function test_error_no_color_returns_text(): void
    {
        $this->assertSame('Fail', $this->makeNoColor()->error('Fail'));
    }

    public function test_warning_no_color_returns_text(): void
    {
        $this->assertSame('Warn', $this->makeNoColor()->warning('Warn'));
    }

    public function test_dim_no_color_returns_text(): void
    {
        $this->assertSame('Dim', $this->makeNoColor()->dim('Dim'));
    }

    public function test_bold_no_color_returns_text(): void
    {
        $this->assertSame('Bold', $this->makeNoColor()->bold('Bold'));
    }

    public function test_header_no_color_returns_text(): void
    {
        $this->assertSame('Title', $this->makeNoColor()->header('Title'));
    }

    // ─── color mode wraps with ANSI escape codes ──────────────────────────

    public function test_info_with_color_wraps_in_cyan(): void
    {
        $result = $this->makeWithColor()->info('Text');
        $this->assertStringContainsString("\033[36m", $result);
        $this->assertStringContainsString('Text', $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function test_success_with_color_wraps_in_green(): void
    {
        $result = $this->makeWithColor()->success('OK');
        $this->assertStringContainsString("\033[32m", $result);
    }

    public function test_error_with_color_wraps_in_red(): void
    {
        $result = $this->makeWithColor()->error('Fail');
        $this->assertStringContainsString("\033[31m", $result);
    }

    public function test_warning_with_color_wraps_in_yellow(): void
    {
        $result = $this->makeWithColor()->warning('Warn');
        $this->assertStringContainsString("\033[33m", $result);
    }

    public function test_dim_with_color_wraps_in_dim_code(): void
    {
        $result = $this->makeWithColor()->dim('Muted');
        $this->assertStringContainsString("\033[2m", $result);
    }

    public function test_bold_with_color_wraps_in_bold_code(): void
    {
        $result = $this->makeWithColor()->bold('Strong');
        $this->assertStringContainsString("\033[1m", $result);
    }

    public function test_header_is_bold_and_cyan(): void
    {
        $result = $this->makeWithColor()->header('Title');
        // Should contain both bold (1) and cyan (36)
        $this->assertStringContainsString("\033[1m", $result);
        $this->assertStringContainsString("\033[36m", $result);
        $this->assertStringContainsString('Title', $result);
    }

    // ─── color detection ──────────────────────────────────────────────────

    public function test_no_color_env_disables_color(): void
    {
        $saved = getenv('NO_COLOR');
        putenv('NO_COLOR=1');

        $t = new TerminalOutput;
        $result = $t->info('text');

        if ($saved === false) {
            putenv('NO_COLOR');
        } else {
            putenv("NO_COLOR={$saved}");
        }

        // NO_COLOR should cause plain output
        $this->assertStringNotContainsString("\033[", $result);
    }

    public function test_dumb_term_disables_color(): void
    {
        $savedTerm = getenv('TERM');
        $savedNoColor = getenv('NO_COLOR');
        putenv('TERM=dumb');
        // Ensure NO_COLOR isn't already set
        putenv('NO_COLOR');

        $t = new TerminalOutput;
        $result = $t->info('text');

        if ($savedTerm === false) {
            putenv('TERM');
        } else {
            putenv("TERM={$savedTerm}");
        }
        if ($savedNoColor !== false) {
            putenv("NO_COLOR={$savedNoColor}");
        }

        $this->assertStringNotContainsString("\033[", $result);
    }
}
