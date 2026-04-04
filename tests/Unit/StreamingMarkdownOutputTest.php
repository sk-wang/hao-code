<?php

namespace Tests\Unit;

use App\Support\Terminal\MarkdownRenderer;
use App\Support\Terminal\StreamingMarkdownOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class StreamingMarkdownOutputTest extends TestCase
{
    private function make(bool $liveRepaint, BufferedOutput $output): StreamingMarkdownOutput
    {
        return new StreamingMarkdownOutput(
            output: $output,
            renderer: new MarkdownRenderer(40),
            terminalWidth: 40,
            liveRepaint: $liveRepaint,
            minRenderIntervalMs: 0,
        );
    }

    private function buffered(): BufferedOutput
    {
        return new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
    }

    // ─── hasReceivedContent ───────────────────────────────────────────────

    public function test_has_received_content_starts_false(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $this->assertFalse($stream->hasReceivedContent());
    }

    public function test_has_received_content_true_after_append(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->append('hello');
        $this->assertTrue($stream->hasReceivedContent());
    }

    public function test_has_received_content_reset_after_finalize(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->append('hello');
        $stream->finalize();
        $this->assertFalse($stream->hasReceivedContent());
    }

    public function test_empty_append_does_not_set_has_received_content(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->append('');
        $this->assertFalse($stream->hasReceivedContent());
    }

    public function test_default_live_repaint_interval_is_less_aggressive(): void
    {
        $stream = new StreamingMarkdownOutput(
            output: $this->buffered(),
            renderer: new MarkdownRenderer(40),
            terminalWidth: 40,
            liveRepaint: true,
        );

        $ref = new \ReflectionClass($stream);
        $prop = $ref->getProperty('minRenderIntervalMs');
        $prop->setAccessible(true);

        $this->assertSame(120, $prop->getValue($stream));
    }

    // ─── finalize with no content ─────────────────────────────────────────

    public function test_finalize_with_no_content_writes_nothing(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->finalize();
        $this->assertSame('', $out->fetch());
    }

    public function test_second_finalize_after_reset_writes_nothing(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->append('text');
        $stream->finalize();
        $out->fetch(); // drain

        $stream->finalize(); // second call — no content
        $this->assertSame('', $out->fetch());
    }

    // ─── liveRepaint=false (deferred rendering) ───────────────────────────

    public function test_no_output_before_finalize_when_live_repaint_disabled(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->append('# Hello');
        $stream->append(' world');
        $this->assertSame('', $out->fetch());
    }

    public function test_finalize_writes_accumulated_content_when_live_repaint_disabled(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->append('# Hello');
        $stream->finalize();
        $display = $out->fetch();
        $this->assertStringContainsString('Hello', $display);
        $this->assertStringNotContainsString("\r\033[2K", $display);
    }

    public function test_multiple_appends_accumulate_before_finalize(): void
    {
        $out = $this->buffered();
        $stream = $this->make(false, $out);
        $stream->append('Hello');
        $stream->append(' world');
        $stream->finalize();
        $display = $out->fetch();
        $this->assertStringContainsString('Hello', $display);
        $this->assertStringContainsString('world', $display);
    }

    public function test_finalize_with_empty_render_writes_nothing(): void
    {
        $out = $this->buffered();
        // Append whitespace-only which renders to empty
        $stream = new StreamingMarkdownOutput(
            output: $out,
            renderer: new class(40) extends MarkdownRenderer {
                public function render(string $markdown): string { return ''; }
            },
            terminalWidth: 40,
            liveRepaint: false,
        );
        $stream->append('   ');
        $stream->finalize();
        $this->assertSame('', $out->fetch());
    }

    // ─── liveRepaint=true (live rendering) ────────────────────────────────

    public function test_it_repaints_the_current_markdown_block_in_place(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $stream = new StreamingMarkdownOutput(
            output: $output,
            renderer: new MarkdownRenderer(40),
            terminalWidth: 40,
            liveRepaint: true,
        );

        $stream->append('# Hi');
        $stream->append("\n\nWorld");
        $stream->finalize();

        $display = $output->fetch();

        $this->assertStringContainsString('Hi', $display);
        $this->assertStringContainsString('World', $display);
        $this->assertStringContainsString("\r\033[2K", $display);
    }

    public function test_live_repaint_writes_immediately_on_append(): void
    {
        $out = $this->buffered();
        $stream = $this->make(true, $out);
        $stream->append('Hello');
        // Should have written something immediately
        $this->assertNotSame('', $out->fetch());
    }

    public function test_live_repaint_second_append_clears_first(): void
    {
        $out = $this->buffered();
        $stream = $this->make(true, $out);
        $stream->append('Hello');
        $out->fetch(); // drain first write
        $stream->append(' world');
        $second = $out->fetch();
        // Second write must include a clear sequence
        $this->assertStringContainsString("\r\033[2K", $second);
    }

    // ─── liveRepaint=false ────────────────────────────────────────────────

    public function test_it_flushes_buffered_markdown_when_live_repaint_is_disabled(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $stream = new StreamingMarkdownOutput(
            output: $output,
            renderer: new MarkdownRenderer(40),
            terminalWidth: 40,
            liveRepaint: false,
        );

        $stream->append('# Hi');

        $this->assertSame('', $output->fetch());

        $stream->finalize();

        $display = $output->fetch();

        $this->assertStringContainsString('Hi', $display);
        $this->assertStringNotContainsString("\r\033[2K", $display);
    }

    public function test_it_throttles_repaints_and_flushes_the_latest_buffer_on_finalize(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $timestamps = [0.0, 0.01, 0.03];
        $stream = new StreamingMarkdownOutput(
            output: $output,
            renderer: new MarkdownRenderer(40),
            terminalWidth: 40,
            liveRepaint: true,
            minRenderIntervalMs: 40,
            timeProvider: function () use (&$timestamps): float {
                return array_shift($timestamps) ?? 0.03;
            },
        );

        $stream->append('# Hi');
        $firstPaint = $output->fetch();

        $stream->append("\n\nthere");
        $this->assertSame('', $output->fetch());

        $stream->finalize();
        $finalPaint = $output->fetch();

        $this->assertStringContainsString('Hi', $firstPaint);
        $this->assertStringContainsString('there', $finalPaint);
        $this->assertStringContainsString("\r\033[2K", $finalPaint);
    }
}
