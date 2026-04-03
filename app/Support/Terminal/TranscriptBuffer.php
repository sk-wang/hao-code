<?php

namespace App\Support\Terminal;

class TranscriptBuffer
{
    /** @var array<int, string> */
    private array $lines;

    public function __construct(string $transcript)
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $transcript);
        $this->lines = explode("\n", $normalized);
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    /**
     * @return array<int, string>
     */
    public function slice(int $offset, int $height): array
    {
        return array_slice($this->lines, max(0, $offset), max(0, $height));
    }

    public function clampOffset(int $offset, int $height): int
    {
        $maxOffset = max(0, $this->lineCount() - max(1, $height));

        return min(max(0, $offset), $maxOffset);
    }

    /**
     * @return array<int, int>
     */
    public function findMatches(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $matches = [];
        foreach ($this->lines as $index => $line) {
            if (mb_stripos($line, $query) !== false) {
                $matches[] = $index;
            }
        }

        return $matches;
    }

    public function pageOffsetForLine(int $lineIndex, int $height): int
    {
        return $this->clampOffset($lineIndex, $height);
    }

    public function highlight(string $line, string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return $line;
        }

        $quoted = preg_quote($query, '/');

        return preg_replace(
            "/({$quoted})/iu",
            '<fg=black;bg=yellow>$1</>',
            $line,
        ) ?? $line;
    }
}
