<?php

declare(strict_types=1);

namespace App\Support\Terminal;

class DraftInputBuffer
{
    private const COLLAPSED_PASTE_THRESHOLD = 800;

    /** @var array<int, string> */
    private array $lines = [''];

    private int $currentLineIndex = 0;

    private int $cursorPosition = 0;

    /**
     * @var array{
     *   prefix: string,
     *   suffix: string,
     *   char_count: int,
     *   line_count: int,
     *   byte_count: int
     * }|null
     */
    private ?array $collapsedPastePreview = null;

    public function __construct(string $text = '')
    {
        $this->replaceWith($text);
    }

    public function replaceWith(string $text): void
    {
        $this->clearCollapsedPastePreview();

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $this->lines = explode("\n", $normalized);
        if ($this->lines === []) {
            $this->lines = [''];
        }

        $this->currentLineIndex = count($this->lines) - 1;
        $this->cursorPosition = $this->length($this->currentLine());
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
        return array_slice($this->lines, 0, $this->currentLineIndex);
    }

    public function currentLine(): string
    {
        return $this->lines[$this->currentLineIndex] ?? '';
    }

    public function replaceCurrentLine(string $text): void
    {
        $this->clearCollapsedPastePreview();
        $this->lines[$this->currentLineIndex] = $text;
        $this->cursorPosition = $this->length($text);
    }

    /**
     * @return array<int, string>
     */
    public function visibleLines(): array
    {
        return $this->lines;
    }

    public function currentLineIndex(): int
    {
        return $this->currentLineIndex;
    }

    public function cursorPosition(): int
    {
        return $this->cursorPosition;
    }

    /**
     * @return array{
     *   prefix: string,
     *   suffix: string,
     *   char_count: int,
     *   line_count: int,
     *   byte_count: int
     * }|null
     */
    public function collapsedPastePreview(): ?array
    {
        return $this->collapsedPastePreview;
    }

    public function clearCollapsedPastePreview(): void
    {
        $this->collapsedPastePreview = null;
    }

    public function moveLeft(): bool
    {
        $this->clearCollapsedPastePreview();

        if ($this->cursorPosition > 0) {
            $this->cursorPosition--;

            return true;
        }

        if ($this->currentLineIndex <= 0) {
            return false;
        }

        $this->currentLineIndex--;
        $this->cursorPosition = $this->length($this->currentLine());

        return true;
    }

    public function moveRight(): bool
    {
        $this->clearCollapsedPastePreview();

        if ($this->cursorPosition < $this->length($this->currentLine())) {
            $this->cursorPosition++;

            return true;
        }

        if ($this->currentLineIndex >= count($this->lines) - 1) {
            return false;
        }

        $this->currentLineIndex++;
        $this->cursorPosition = 0;

        return true;
    }

    public function moveUp(): bool
    {
        $this->clearCollapsedPastePreview();

        if ($this->currentLineIndex <= 0) {
            return false;
        }

        $this->currentLineIndex--;
        $this->cursorPosition = min($this->cursorPosition, $this->length($this->currentLine()));

        return true;
    }

    public function moveDown(): bool
    {
        $this->clearCollapsedPastePreview();

        if ($this->currentLineIndex >= count($this->lines) - 1) {
            return false;
        }

        $this->currentLineIndex++;
        $this->cursorPosition = min($this->cursorPosition, $this->length($this->currentLine()));

        return true;
    }

    public function moveHome(): void
    {
        $this->clearCollapsedPastePreview();
        $this->cursorPosition = 0;
    }

    public function moveEnd(): void
    {
        $this->clearCollapsedPastePreview();
        $this->cursorPosition = $this->length($this->currentLine());
    }

    public function isCursorAtEnd(): bool
    {
        return $this->currentLineIndex === count($this->lines) - 1
            && $this->cursorPosition >= $this->length($this->currentLine());
    }

    public function insert(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->clearCollapsedPastePreview();
        $this->lines[$this->currentLineIndex] = $this->beforeCursor() . $text . $this->afterCursor();
        $this->cursorPosition += $this->length($text);
    }

    public function backspace(): bool
    {
        $this->clearCollapsedPastePreview();

        if ($this->cursorPosition > 0) {
            $this->lines[$this->currentLineIndex] = $this->substring($this->currentLine(), 0, $this->cursorPosition - 1)
                . $this->afterCursor();
            $this->cursorPosition--;

            return true;
        }

        if ($this->currentLineIndex <= 0) {
            return false;
        }

        $previousIndex = $this->currentLineIndex - 1;
        $previousLine = $this->lines[$previousIndex];
        $this->lines[$previousIndex] .= $this->currentLine();
        array_splice($this->lines, $this->currentLineIndex, 1);
        $this->currentLineIndex = $previousIndex;
        $this->cursorPosition = $this->length($previousLine);

        return true;
    }

    public function delete(): bool
    {
        $this->clearCollapsedPastePreview();

        if ($this->cursorPosition < $this->length($this->currentLine())) {
            $this->lines[$this->currentLineIndex] = $this->beforeCursor()
                . $this->substring($this->currentLine(), $this->cursorPosition + 1);

            return true;
        }

        if ($this->currentLineIndex >= count($this->lines) - 1) {
            return false;
        }

        $this->lines[$this->currentLineIndex] .= $this->lines[$this->currentLineIndex + 1];
        array_splice($this->lines, $this->currentLineIndex + 1, 1);

        return true;
    }

    public function paste(string $text): void
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        if ($normalized === '') {
            return;
        }

        $fullTextBefore = $this->text();
        $absoluteOffset = $this->absoluteCursorOffset();
        $prefix = $this->substring($fullTextBefore, 0, $absoluteOffset);
        $suffix = $this->substring($fullTextBefore, $absoluteOffset);
        $this->clearCollapsedPastePreview();

        if (! str_contains($normalized, "\n")) {
            $this->insert($normalized);

            if ($this->shouldCollapsePastedText($normalized)) {
                $this->collapsedPastePreview = $this->makeCollapsedPastePreview($normalized, $prefix, $suffix);
            }

            return;
        }

        $before = $this->beforeCursor();
        $after = $this->afterCursor();
        $parts = explode("\n", $normalized);
        $firstLine = $before . array_shift($parts);
        $lastFragment = array_pop($parts) ?? '';
        $replacement = [$firstLine, ...$parts, $lastFragment . $after];

        array_splice($this->lines, $this->currentLineIndex, 1, $replacement);
        $this->currentLineIndex += count($replacement) - 1;
        $this->cursorPosition = $this->length($lastFragment);

        if ($this->shouldCollapsePastedText($normalized)) {
            $this->collapsedPastePreview = $this->makeCollapsedPastePreview($normalized, $prefix, $suffix);
        }
    }

    public function commitContinuationLine(): bool
    {
        $this->clearCollapsedPastePreview();

        if (! $this->isCursorAtEnd()) {
            return false;
        }

        if (! str_ends_with(rtrim($this->currentLine()), '\\')) {
            return false;
        }

        $this->lines[$this->currentLineIndex] = rtrim($this->currentLine(), ' \\');
        array_splice($this->lines, $this->currentLineIndex + 1, 0, ['']);
        $this->currentLineIndex++;
        $this->cursorPosition = 0;

        return true;
    }

    public function beforeCursor(): string
    {
        return $this->substring($this->currentLine(), 0, $this->cursorPosition);
    }

    public function afterCursor(): string
    {
        return $this->substring($this->currentLine(), $this->cursorPosition);
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

    private function shouldCollapsePastedText(string $text): bool
    {
        return $this->length($text) >= self::COLLAPSED_PASTE_THRESHOLD;
    }

    /**
     * @return array{
     *   prefix: string,
     *   suffix: string,
     *   char_count: int,
     *   line_count: int,
     *   byte_count: int
     * }
     */
    private function makeCollapsedPastePreview(string $text, string $prefix, string $suffix): array
    {
        return [
            'prefix' => $prefix,
            'suffix' => $suffix,
            'char_count' => $this->length($text),
            'line_count' => substr_count($text, "\n"),
            'byte_count' => strlen($text),
        ];
    }

    private function absoluteCursorOffset(): int
    {
        $offset = 0;

        for ($index = 0; $index < $this->currentLineIndex; $index++) {
            $offset += $this->length($this->lines[$index] ?? '') + 1;
        }

        return $offset + $this->cursorPosition;
    }
}
