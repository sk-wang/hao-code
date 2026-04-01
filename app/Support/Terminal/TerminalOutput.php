<?php

namespace App\Support\Terminal;

class TerminalOutput
{
    private bool $supportsColor;

    public function __construct()
    {
        $this->supportsColor = $this->detectColorSupport();
    }

    public function info(string $text): string
    {
        return $this->color($text, '36'); // cyan
    }

    public function success(string $text): string
    {
        return $this->color($text, '32'); // green
    }

    public function error(string $text): string
    {
        return $this->color($text, '31'); // red
    }

    public function warning(string $text): string
    {
        return $this->color($text, '33'); // yellow
    }

    public function dim(string $text): string
    {
        return $this->color($text, '2'); // dim
    }

    public function bold(string $text): string
    {
        return $this->color($text, '1');
    }

    public function header(string $text): string
    {
        return $this->bold($this->info($text));
    }

    private function color(string $text, string $code): string
    {
        if (!$this->supportsColor) {
            return $text;
        }
        return "\033[{$code}m{$text}\033[0m";
    }

    private function detectColorSupport(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('TERM') === 'dumb') {
            return false;
        }

        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    public function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    public function writeLine(string $text = ''): void
    {
        $this->write($text . "\n");
    }

    public function clearLine(): void
    {
        if ($this->supportsColor) {
            fwrite(STDOUT, "\r\033[2K");
        }
    }
}
