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
            "  <fg=gray>/help commands · Ctrl+O transcript · Ctrl+R history · Ctrl+C interrupt · Ctrl+D exit · \\ multiline</>",
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

    public function test_it_formats_an_aborting_status_line(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=yellow>⏸ Aborting...</> <fg=gray>(Ctrl+C again to force exit)</>',
            $formatter->abortingStatus(),
        );
    }

    public function test_it_formats_an_interrupted_status_line(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=yellow>⏸ Interrupted</> <fg=gray>(ready for your next prompt)</>',
            $formatter->interruptedStatus(),
        );
    }

    public function test_it_formats_a_running_tool_status_line(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=yellow>✻ Running Bash...</> <fg=gray>(3s)</>',
            $formatter->runningToolStatus('Bash', 3),
        );
    }

    public function test_it_formats_a_prompt_footer(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=gray>Fix interrupt flow · claude-sonnet-4-20250514 · 12 msgs · default · Ctrl+O transcript · Ctrl+R history</>',
            $formatter->promptFooter(
                model: 'claude-sonnet-4-20250514',
                messageCount: 12,
                permissionMode: 'default',
                fastMode: false,
                title: 'Fix interrupt flow',
            ),
        );
    }

    public function test_prompt_footer_includes_fast_badge_when_enabled(): void
    {
        $formatter = new ReplFormatter;

        $footer = $formatter->promptFooter(
            model: 'claude-haiku-4-20250514',
            messageCount: 3,
            permissionMode: 'acceptEdits',
            fastMode: true,
        );

        $this->assertStringContainsString('fast', $footer);
    }

    public function test_it_formats_a_transcript_footer(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=gray>Line 42/100 · 42% · Search: abort · 2/5 · j/k move · space/b page · / search · q quit</>',
            $formatter->transcriptFooter(
                line: 42,
                totalLines: 100,
                query: 'abort',
                currentMatch: 2,
                matchCount: 5,
            ),
        );
    }

    public function test_it_formats_reverse_search_status(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '<fg=yellow>(reverse-i-search)</> <fg=gray>`git`:</> <fg=white>git diff --stat</><fg=gray> 1/2</>',
            $formatter->reverseSearchStatus('git', 'git diff --stat', 1, 2),
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

    public function test_it_formats_a_panel_with_markup_aware_padding(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->panel('Status', [
            '<fg=green>✓</> <fg=gray>Ready</>',
            '<fg=yellow>Model</> <fg=white>sonnet</>',
        ]);

        $this->assertCount(5, $lines);
        $this->assertStringContainsString('╭', $lines[0]);
        $this->assertStringContainsString('Status', $lines[1]);
        $this->assertStringContainsString('Ready', $lines[2]);
        $this->assertStringContainsString('sonnet', $lines[3]);
        $this->assertStringContainsString('╰', $lines[4]);
    }

    public function test_it_formats_the_permission_prompt_panel(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->permissionPromptPanel('Write', 'README.md');

        $this->assertCount(5, $lines);
        $this->assertStringContainsString('╭', $lines[0]);
        $this->assertStringContainsString('Permission required', $lines[1]);
        $this->assertStringContainsString('Tool', $lines[2]);
        $this->assertStringContainsString('Write (README.md)', $lines[2]);
        $this->assertStringContainsString('allow session', $lines[3]);
        $this->assertStringContainsString('╰', $lines[4]);
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
