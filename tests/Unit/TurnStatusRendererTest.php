<?php

namespace Tests\Unit;

use App\Support\Terminal\ReplFormatter;
use App\Support\Terminal\TurnStatusRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class TurnStatusRendererTest extends TestCase
{
    public function test_it_renders_a_live_loading_line_with_elapsed_time_and_token_estimate(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $formatter = new ReplFormatter;
        $timestamps = [100.0, 131.0];

        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: $formatter,
            input: '探索一下这个代码库',
            timeProvider: function () use (&$timestamps): float {
                return array_shift($timestamps) ?? 131.0;
            },
            enabled: true,
            verb: 'Spelunking',
        );

        $renderer->start();
        $renderer->recordTextDelta(str_repeat('a', 48));
        $renderer->tick();
        $renderer->stop();

        $display = $output->fetch();

        $this->assertStringContainsString('Spelunking...', $display);
        $this->assertStringContainsString('31s · ↓ 12 tokens', $display);
        $this->assertStringContainsString("\r\033[2K", $display);
    }

    public function test_it_can_pause_and_resume_without_leaving_the_previous_line_on_screen(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $formatter = new ReplFormatter;
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: $formatter,
            input: 'hello',
            timeProvider: static fn (): float => 200.0,
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $renderer->pause();
        $renderer->resume();
        $renderer->stop();

        $display = $output->fetch();

        $this->assertStringContainsString('Thinking...', $display);
        $this->assertGreaterThanOrEqual(2, substr_count($display, "\r\033[2K"));
    }

    public function test_it_picks_a_varied_claude_code_style_verb_by_default(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $formatter = new ReplFormatter;
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: $formatter,
            input: 'hello',
            timeProvider: static fn (): float => 200.0,
            enabled: true,
        );

        $renderer->start();

        $display = $output->fetch();

        $this->assertMatchesRegularExpression(
            '/(Accomplishing|Architecting|Calculating|Cerebrating|Cogitating|Considering|Crafting|Crunching|Deciphering|Spelunking|Discombobulating|Finagling|Forging|Imagining|Improvising|Inferring|Manifesting|Perusing|Pondering|Synthesizing|Thinking|Wrangling|Tinkering)\.\.\./',
            $display,
        );
    }

    // ─── enabled=false ────────────────────────────────────────────────────

    public function test_is_enabled_returns_configured_value(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: static fn (): float => 0.0,
            enabled: false,
        );

        $this->assertFalse($renderer->isEnabled());
    }

    public function test_disabled_renderer_writes_nothing(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: static fn (): float => 0.0,
            enabled: false,
        );

        $renderer->start();
        $renderer->tick();
        $renderer->pause();
        $renderer->resume();
        $renderer->stop();

        $this->assertSame('', $output->fetch());
    }

    // ─── tick ─────────────────────────────────────────────────────────────

    public function test_tick_does_not_re_render_when_elapsed_second_unchanged(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: static fn (): float => 100.0,
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $output->fetch(); // drain start

        $renderer->tick(); // same time → no re-render
        $this->assertSame('', $output->fetch());
    }

    public function test_tick_re_renders_when_second_advances(): void
    {
        $time = 100.0;
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: function () use (&$time): float { return $time; },
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $output->fetch(); // drain

        $time = 101.0;
        $renderer->tick();
        $this->assertNotSame('', $output->fetch());
    }

    public function test_tick_does_nothing_when_paused(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $time = 100.0;
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: function () use (&$time): float { return $time; },
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $renderer->pause();
        $output->fetch(); // drain

        $time = 102.0;
        $renderer->tick(); // paused → no render
        $this->assertSame('', $output->fetch());
    }

    // ─── recordTextDelta token estimate ───────────────────────────────────

    public function test_record_text_delta_accumulates_for_token_display(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $timestamps = [100.0, 105.0];
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: function () use (&$timestamps): float {
                return array_shift($timestamps) ?? 105.0;
            },
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $renderer->recordTextDelta(str_repeat('x', 40)); // 40 chars = ceil(40/4) = 10 tokens
        $renderer->tick();

        $display = $output->fetch();
        $this->assertStringContainsString('10 tokens', $display);
    }

    public function test_empty_record_text_delta_ignored(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $timestamps = [100.0, 105.0];
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: function () use (&$timestamps): float {
                return array_shift($timestamps) ?? 105.0;
            },
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $renderer->recordTextDelta(''); // no chars
        $renderer->tick();

        $display = $output->fetch();
        // No token count should appear when 0 chars recorded
        $this->assertStringNotContainsString('tokens', $display);
    }

    public function test_set_phase_label_switches_status_to_running_tool(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: static fn (): float => 100.0,
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $output->fetch();

        $renderer->setPhaseLabel('Bash');
        $display = $output->fetch();

        $this->assertStringContainsString('Running Bash...', $display);
        $this->assertStringNotContainsString('Thinking...', $display);
    }

    public function test_clearing_phase_label_restores_normal_loading_status(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: static fn (): float => 100.0,
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $renderer->setPhaseLabel('Bash');
        $output->fetch();

        $renderer->setPhaseLabel(null);
        $display = $output->fetch();

        $this->assertStringContainsString('Thinking...', $display);
    }

    // ─── stop ─────────────────────────────────────────────────────────────

    public function test_stop_clears_visible_line(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: 'hi',
            timeProvider: static fn (): float => 0.0,
            enabled: true,
            verb: 'Thinking',
        );

        $renderer->start();
        $renderer->stop();

        $display = $output->fetch();
        $this->assertStringContainsString("\r\033[2K", $display);
    }

    // ─── empty input uses first verb ──────────────────────────────────────

    public function test_empty_input_uses_first_verb(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $renderer = new TurnStatusRenderer(
            output: $output,
            formatter: new ReplFormatter,
            input: '', // empty → always first verb
            timeProvider: static fn (): float => 0.0,
            enabled: true,
        );

        $renderer->start();

        $display = $output->fetch();
        $this->assertStringContainsString('Accomplishing...', $display);
    }
}
