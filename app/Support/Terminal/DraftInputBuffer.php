<?php

declare(strict_types=1);

namespace App\Support\Terminal;

class DraftInputBuffer
{
    /** @var array<int, string> */
    private array $committedLines = [];

    private string $currentLine = '';

    private int $cursorPosition = 0;

    public function __construct(string $text = '')
    {
        $this->replaceWith($text);
    }

    public function replaceWith(string $text): void
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = explode("\n", $normalized);

        $this->currentLine = array_pop($parts) ?? '';
        $this->committedLines = $parts;
        $this->cursorPosition = $this->length($this->currentLine);
    }

    public function text(): string
    {
        return implode("\n", $this->visibleLines());
    }

    /**
     * @return array<int, string>
     */
    public function committedLines(): array
    {
        return $this->committedLines;
    }

    public function currentLine(): string
    {
        return $this->currentLine;
    }

    public function replaceCurrentLine(string $text): void
    {
        $this->currentLine = $text;
        $this->cursorPosition = $this->length($text);
    }

    /**
     * @return array<int, string>
     */
    public function visibleLines(): array
    {
        return [...$this->committedLines, $this->currentLine];
    }

    public function currentLineIndex(): int
    {
        return count($this->committedLines);
    }

    public function cursorPosition(): int
    {
        return $this->cursorPosition;
    }

    public function moveLeft(): bool
    {
        if ($this->cursorPosition <= 0) {
            return false;
        }

        $this->cursorPosition--;

        return true;
    }

    public function moveRight(): bool
    {
        if ($this->cursorPosition >= $this->length($this->currentLine)) {
            return false;
        }

        $this->cursorPosition++;

        return true;
    }

    public function moveHome(): void
    {
        $this->cursorPosition = 0;
    }

    public function moveEnd(): void
    {
        $this->cursorPosition = $this->length($this->currentLine);
    }

    public function isCursorAtEnd(): bool
    {
        return $this->cursorPosition >= $this->length($this->currentLine);
    }

    public function insert(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->currentLine = $this->beforeCursor() . $text . $this->afterCursor();
        $this->cursorPosition += $this->length($text);
    }

    public function backspace(): bool
    {
        if ($this->cursorPosition <= 0) {
            return false;
        }

        $this->currentLine = $this->substring($this->currentLine, 0, $this->cursorPosition - 1)
            . $this->afterCursor();
        $this->cursorPosition--;

        return true;
    }

    public function delete(): bool
    {
        if ($this->cursorPosition >= $this->length($this->currentLine)) {
            return false;
        }

        $this->currentLine = $this->beforeCursor()
            . $this->substring($this->currentLine, $this->cursorPosition + 1);

        return true;
    }

    public function paste(string $text): void
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        if ($normalized === '') {
            return;
        }

        if (! str_contains($normalized, "\n")) {
            $this->insert($normalized);

            return;
        }

        $before = $this->beforeCursor();
        $after = $this->afterCursor();
        $parts = explode("\n", $normalized);
        $firstLine = $before . array_shift($parts);
        $lastFragment = array_pop($parts) ?? '';

        $this->committedLines[] = $firstLine;
        foreach ($parts as $line) {
            $this->committedLines[] = $line;
        }

        $this->currentLine = $lastFragment . $after;
        $this->cursorPosition = $this->length($lastFragment);
    }

    public function commitContinuationLine(): bool
    {
        if (! $this->isCursorAtEnd()) {
            return false;
        }

        if (! str_ends_with(rtrim($this->currentLine), '\\')) {
            return false;
        }

        $this->committedLines[] = rtrim($this->currentLine, ' \\');
        $this->currentLine = '';
        $this->cursorPosition = 0;

        return true;
    }

    public function beforeCursor(): string
    {
        return $this->substring($this->currentLine, 0, $this->cursorPosition);
    }

    public function afterCursor(): string
    {
        return $this->substring($this->currentLine, $this->cursorPosition);
    }

    private function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function substring(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($text, $start, null, 'UTF-8')
                : mb_substr($text, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }
}
