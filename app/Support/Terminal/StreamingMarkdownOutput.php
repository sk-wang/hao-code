<?php

namespace App\Support\Terminal;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class StreamingMarkdownOutput
{
    private string $buffer = '';

    private int $renderedLineCount = 0;

    private bool $hasReceivedContent = false;

    private bool $hasRenderedLiveBlock = false;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly MarkdownRenderer $renderer,
        private readonly ?int $terminalWidth = null,
        private readonly ?bool $liveRepaint = null,
    ) {}

    public function append(string $markdown): void
    {
        if ($markdown === '') {
            return;
        }

        $this->buffer .= $markdown;
        $this->hasReceivedContent = true;

        if (! $this->supportsLiveRepaint()) {
            return;
        }

        $rendered = $this->renderer->render($this->buffer);
        $this->clearLiveBlock();

        if ($rendered === '') {
            return;
        }

        $this->output->write($rendered);
        $this->renderedLineCount = $this->countDisplayLines($rendered);
        $this->hasRenderedLiveBlock = true;
    }

    public function finalize(): void
    {
        if (! $this->hasReceivedContent) {
            return;
        }

        if (! $this->supportsLiveRepaint()) {
            $rendered = $this->renderer->render($this->buffer);
            if ($rendered !== '') {
                $this->output->write($rendered);
            }
        }

        $this->reset();
    }

    public function hasReceivedContent(): bool
    {
        return $this->hasReceivedContent;
    }

    private function reset(): void
    {
        $this->buffer = '';
        $this->renderedLineCount = 0;
        $this->hasReceivedContent = false;
        $this->hasRenderedLiveBlock = false;
    }

    private function clearLiveBlock(): void
    {
        if (! $this->hasRenderedLiveBlock || $this->renderedLineCount < 1) {
            return;
        }

        $this->writeRaw("\r\033[2K");

        for ($index = 1; $index < $this->renderedLineCount; $index++) {
            $this->writeRaw("\033[1A\r\033[2K");
        }

        $this->writeRaw("\r");
        $this->renderedLineCount = 0;
        $this->hasRenderedLiveBlock = false;
    }

    private function countDisplayLines(string $rendered): int
    {
        $formatted = $this->output->getFormatter()->format($rendered);
        $plain = preg_replace('/\e\[[0-9;]*m/', '', $formatted) ?? $formatted;
        $lines = explode("\n", $plain);
        $lineCount = 0;
        $width = $this->resolvedTerminalWidth();

        foreach ($lines as $line) {
            $displayWidth = $this->displayWidth($line);
            $lineCount += max(1, (int) ceil(max(1, $displayWidth) / $width));
        }

        return max(1, $lineCount);
    }

    private function resolvedTerminalWidth(): int
    {
        return max(20, $this->terminalWidth ?? (new Terminal)->getWidth());
    }

    private function supportsLiveRepaint(): bool
    {
        return $this->liveRepaint
            ?? ($this->output->isDecorated() && self::stdoutIsInteractive());
    }

    private function displayWidth(string $text): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($text, 'UTF-8');
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

    private function writeRaw(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }

    private static function stdoutIsInteractive(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }

        return false;
    }
}
