<?php

namespace Tests\Unit;

use App\Support\Terminal\ReplFormatter;
use PHPUnit\Framework\TestCase;

class ReplFormatterTest extends TestCase
{
    public function test_it_formats_the_prompt_like_the_main_repl(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '<fg=green>hao-code</> <fg=cyan>❯</> ',
            $formatter->prompt('hao-code'),
        );
    }

    public function test_it_formats_a_readline_safe_prompt_with_ansi_wrapped_as_invisible_sequences(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            "\001\e[32m\002hao-code\001\e[39m\002 \001\e[36m\002❯\001\e[39m\002 ",
            $formatter->readlinePrompt('hao-code'),
        );
    }

    public function test_it_formats_a_readline_safe_continuation_prompt(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            "\001\e[90m\002…\001\e[39m\002 ",
            $formatter->readlineContinuationPrompt(),
        );
    }

    public function test_it_formats_a_neatly_aligned_banner(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame([
            '',
            '  <fg=cyan;bold>╭────────────────────────────────╮</>',
            '  <fg=cyan;bold>│</>   <fg=white;bold>Hao Code CLI Coding Agent</>    <fg=cyan;bold>│</>',
            '  <fg=cyan;bold>│</> <fg=gray>PHP 8.4.10 · Laravel Framework</> <fg=cyan;bold>│</>',
            '  <fg=cyan;bold>╰────────────────────────────────╯</>',
        ], $formatter->banner('8.4.10'));
    }

    public function test_it_formats_the_help_hint_below_the_banner(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            "  <fg=gray>Type '/help' for commands, '/exit' to quit</>",
            $formatter->helpHint(),
        );
    }

    public function test_it_formats_tool_calls_in_a_compact_claude_code_style(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=magenta>⚙ Glob</><fg=gray>(**/*.php)</>',
            $formatter->toolCall('Glob', '**/*.php'),
        );
    }

    public function test_it_formats_the_compact_usage_footer(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '<fg=gray>  [11054in/57out/9cache tokens · $0.034017]</>',
            $formatter->usageFooter(
                inputTokens: 11054,
                outputTokens: 57,
                cacheReadTokens: 9,
                cost: 0.034017,
            ),
        );
    }

    public function test_it_formats_tool_failures_without_a_success_tick_line(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=red>✗ Glob failed:</> <fg=gray>Permission denied</>',
            $formatter->toolFailure('Glob', 'Permission denied'),
        );
    }

    public function test_it_formats_a_live_loading_status_line(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=yellow>✻ Spelunking...</> <fg=gray>(31s · ↓ 336 tokens)</>',
            $formatter->loadingStatus('Spelunking', 31, 336),
        );
    }

    public function test_it_omits_token_details_when_no_live_token_estimate_is_available(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=yellow>✻ Thinking...</> <fg=gray>(4s)</>',
            $formatter->loadingStatus('Thinking', 4),
        );
    }

    public function test_it_omits_token_details_when_approx_tokens_is_zero(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=yellow>✻ Thinking...</> <fg=gray>(5s)</>',
            $formatter->loadingStatus('Thinking', 5, 0),
        );
    }

    public function test_it_formats_continuation_prompt(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame('<fg=gray>…</> ', $formatter->continuationPrompt());
    }

    public function test_tool_call_without_summary_omits_parentheses(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=magenta>⚙ Bash</>',
            $formatter->toolCall('Bash'),
        );
    }

    public function test_tool_call_with_empty_summary_omits_parentheses(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=magenta>⚙ Read</>',
            $formatter->toolCall('Read', ''),
        );
    }

    public function test_usage_footer_omits_cache_when_zero(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '<fg=gray>  [100in/50out tokens · $0.001]</>',
            $formatter->usageFooter(
                inputTokens: 100,
                outputTokens: 50,
                cacheReadTokens: 0,
                cost: 0.001,
            ),
        );
    }

    public function test_banner_uses_custom_framework_string(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->banner('8.3.0', 'Custom Framework');

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Custom Framework')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
}
