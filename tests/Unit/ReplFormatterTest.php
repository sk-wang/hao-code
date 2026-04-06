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
            "  <fg=gray>/help commands · Tab/↑↓ autocomplete · Ctrl+O transcript · Ctrl+R history · Ctrl+C interrupt · Ctrl+D exit · \\ multiline</>",
            $formatter->helpHint(),
        );
    }

    public function test_it_formats_the_buddy_dock_line(): void
    {
        $formatter = new ReplFormatter;

        $this->assertSame(
            '  <fg=gray>Buddy</> <fg=white>(^_^)</> <fg=cyan>Mochi</>',
            $formatter->buddyDockLine('<fg=white>(^_^)</> <fg=cyan>Mochi</>'),
        );
    }

    public function test_it_formats_a_compact_buddy_dock_for_narrow_terminals(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->buddyDockLines([
            'narrow_line' => '<fg=white>(^_^)</> <fg=cyan>Mochi</>',
            'name' => 'Mochi',
            'mood' => 'happy',
            'mood_emoji' => '😊',
            'quip' => null,
            'quip_fading' => false,
            'sprite_lines' => [' /^_^\\\\ '],
        ], 80);

        $this->assertSame([
            '  <fg=gray>Buddy</> <fg=white>(^_^)</> <fg=cyan>Mochi</>',
        ], $lines);
    }

    public function test_it_formats_a_buddy_panel_for_wide_terminals(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->buddyDockLines([
            'narrow_line' => '<fg=white>(^_^)</> <fg=cyan>Mochi</>',
            'name' => 'Mochi',
            'mood' => 'thinking',
            'mood_emoji' => '🤔',
            'quip' => 'Mochi is investigating the bug',
            'quip_fading' => false,
            'sprite_lines' => [' /^_^\\\\ ', ' (  -_-)'],
        ], 120);

        $this->assertGreaterThan(4, count($lines));
        $this->assertStringContainsString('Buddy', $lines[1]);
        $this->assertStringContainsString('🤔 Mochi', implode("\n", $lines));
        $this->assertStringContainsString('investigating the bug', implode("\n", $lines));
        $this->assertStringContainsString('/^_^\\\\', implode("\n", $lines));
    }

    public function test_it_docks_buddy_panel_to_the_right_when_space_allows(): void
    {
        $formatter = new ReplFormatter;

        $left = [
            '  <fg=gray>Context</> <fg=green>██░░</> <fg=green>50%</>',
            '  <fg=gray>Tools</> <fg=cyan>Read×2</>',
        ];
        $right = $formatter->buddyDockLines([
            'narrow_line' => '<fg=white>(^_^)</> <fg=cyan>Mochi</>',
            'name' => 'Mochi',
            'mood' => 'thinking',
            'mood_emoji' => '🤔',
            'quip' => 'Mochi is investigating the bug',
            'quip_fading' => false,
            'sprite_lines' => [' /^_^\\\\ ', ' (  -_-)'],
        ], 120);

        $lines = $formatter->dockRight($left, $right, 120);

        $this->assertCount(count($right), $lines);
        $this->assertStringContainsString('Context', implode("\n", $lines));
        $this->assertStringContainsString('Tools', implode("\n", $lines));
        $this->assertStringContainsString('Buddy', implode("\n", $lines));
        $this->assertStringContainsString('Mochi', implode("\n", $lines));
    }

    public function test_it_falls_back_to_stacked_layout_when_terminal_is_too_narrow(): void
    {
        $formatter = new ReplFormatter;

        $left = [
            '  <fg=gray>Context</> <fg=green>██████░░░░</> <fg=green>60%</>',
            '  <fg=gray>Tools</> <fg=cyan>Read×2 Edit×1 Search×4</>',
        ];
        $right = $formatter->buddyDockLines([
            'narrow_line' => '<fg=white>(^_^)</> <fg=cyan>Mochi</>',
            'name' => 'Mochi',
            'mood' => 'thinking',
            'mood_emoji' => '🤔',
            'quip' => 'Mochi is investigating the bug',
            'quip_fading' => false,
            'sprite_lines' => [' /^_^\\\\ ', ' (  -_-)'],
        ], 120);

        $lines = $formatter->dockRight($left, $right, 50);

        $this->assertSame(array_merge($left, $right), $lines);
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
            '  <fg=yellow>✻ Bash...</> <fg=gray>(3s)</>',
            $formatter->runningToolStatus('Bash', 3),
        );
    }

    public function test_it_formats_a_prompt_footer(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->promptFooterLines([
            'model' => 'claude-sonnet-4-20250514',
            'message_count' => 12,
            'permission_mode' => 'default',
            'fast_mode' => true,
            'title' => 'Fix interrupt flow',
            'project' => 'hao-code/app',
            'branch' => 'main',
            'git_dirty' => true,
            'context_percent' => 42.0,
            'context_tokens' => 76000,
            'context_limit' => 180000,
            'context_state' => 'warning',
            'cost' => 0.034017,
            'cost_warn' => 5.0,
            'tools' => [
                'running' => [],
                'completed' => [
                    ['name' => 'Read', 'count' => 3],
                    ['name' => 'Edit', 'count' => 1],
                ],
            ],
            'agents' => [
                'bash_tasks' => 1,
                'entries' => [
                    [
                        'status' => 'running',
                        'agent_type' => 'Explore',
                        'description' => 'Finding auth code',
                        'elapsed_seconds' => 135,
                        'pending_messages' => 2,
                    ],
                ],
            ],
            'todo' => [
                'current' => 'Add regression tests',
                'completed' => 1,
                'total' => 3,
                'all_completed' => false,
            ],
        ]);

        $this->assertCount(5, $lines);
        $this->assertStringContainsString('[claude-sonnet-4-20250514]', $lines[0]);
        $this->assertStringContainsString('Fix interrupt flow', $lines[0]);
        $this->assertStringContainsString('git:(', $lines[0]);
        $this->assertStringContainsString('fast', $lines[0]);
        $this->assertStringContainsString('Context', $lines[1]);
        $this->assertStringContainsString('42%', $lines[1]);
        $this->assertStringContainsString('Cost', $lines[1]);
        $this->assertStringContainsString('Tools', $lines[2]);
        $this->assertStringContainsString('Read', $lines[2]);
        $this->assertStringContainsString('Background', $lines[3]);
        $this->assertStringContainsString('Explore', $lines[3]);
        $this->assertStringContainsString('Todo', $lines[4]);
        $this->assertStringContainsString('Add regression tests', $lines[4]);
    }

    public function test_prompt_footer_omits_optional_hud_lines_when_empty(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->promptFooterLines([
            'model' => 'claude-haiku-4-20250514',
            'message_count' => 3,
            'permission_mode' => 'acceptEdits',
            'context_percent' => 5.0,
            'context_tokens' => 9000,
            'context_limit' => 180000,
            'context_state' => 'normal',
            'cost' => 0.001,
            'cost_warn' => 5.0,
            'tools' => ['running' => [], 'completed' => []],
            'agents' => ['bash_tasks' => 0, 'entries' => []],
            'todo' => null,
        ]);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('claude-haiku-4-20250514', $lines[0]);
        $this->assertStringContainsString('Context', $lines[1]);
    }

    public function test_prompt_footer_renders_latest_turn_status_when_available(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->promptFooterLines([
            'model' => 'claude-haiku-4-20250514',
            'message_count' => 3,
            'permission_mode' => 'default',
            'context_percent' => 5.0,
            'context_tokens' => 9000,
            'context_limit' => 180000,
            'context_state' => 'normal',
            'cost' => 0.001,
            'cost_warn' => 5.0,
            'tools' => ['running' => [], 'completed' => []],
            'agents' => ['bash_tasks' => 0, 'entries' => []],
            'todo' => null,
            'turn' => [
                'event' => 'plan.updated',
                'label' => 'Plan updated',
                'detail' => 'TodoWrite · 3 items',
            ],
        ]);

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Status', $lines[2]);
        $this->assertStringContainsString('plan.updated', $lines[2]);
        $this->assertStringContainsString('Plan updated', $lines[2]);
    }

    public function test_prompt_footer_supports_compact_layout_and_section_toggles(): void
    {
        $formatter = new ReplFormatter;

        $lines = $formatter->promptFooterLines([
            'model' => 'claude-haiku-4-20250514',
            'message_count' => 4,
            'permission_mode' => 'default',
            'layout' => 'compact',
            'project' => 'hao-code/app',
            'branch' => 'feature/hud',
            'git_dirty' => false,
            'context_percent' => 18.0,
            'context_tokens' => 32000,
            'context_limit' => 180000,
            'cost' => 0.0123,
            'show_tools' => false,
            'show_agents' => true,
            'show_todos' => true,
            'tools' => [
                'running' => [],
                'completed' => [
                    ['name' => 'Read', 'count' => 2],
                ],
            ],
            'agents' => [
                'bash_tasks' => 1,
                'entries' => [
                    [
                        'status' => 'running',
                        'agent_type' => 'Plan',
                        'elapsed_seconds' => 61,
                        'pending_messages' => 0,
                    ],
                ],
            ],
            'todo' => [
                'current' => 'Wire compact HUD',
                'completed' => 1,
                'total' => 2,
                'all_completed' => false,
            ],
        ]);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('ctx', $lines[0]);
        $this->assertStringContainsString('git:(', $lines[0]);
        $this->assertStringContainsString('todo', $lines[1]);
        $this->assertStringContainsString('bg', $lines[1]);
        $this->assertStringNotContainsString('tools', $lines[1]);
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
