<?php

namespace App\Support\Terminal;

use Symfony\Component\Console\Output\OutputInterface;

class DockedPromptScreen
{
    /** @var array<int, string> */
    private array $renderedLines = [];

    /** @var callable():int|null */
    private $heightProvider;

    /**
     * @param callable():int|null $heightProvider
     */
    public function __construct(
        private readonly OutputInterface $output,
        ?callable $heightProvider = null,
    ) {
        $this->heightProvider = $heightProvider;
    }

    /**
     * @param array<int, string> $suggestionLines
     * @param array<int, string> $promptLines
     * @param array<int, string> $hudLines
     */
    public function render(
        array $suggestionLines,
        array $promptLines,
        int $cursorLineIndex,
        int $cursorColumn,
        array $hudLines,
    ): void
    {
        $terminalHeight = $this->terminalHeight();
        $promptLines = $promptLines === [] ? [''] : $promptLines;
        $cursorLineIndex = max(0, min($cursorLineIndex, count($promptLines) - 1));
        $reservedHeight = max(1, count($suggestionLines) + count($promptLines) + count($hudLines));
        $line = max(1, $terminalHeight - $reservedHeight + 1);
        $nextFrame = [];

        foreach ($suggestionLines as $suggestionLine) {
            $nextFrame[$line++] = $suggestionLine;
        }

        $promptStartLine = $line;
        foreach ($promptLines as $promptLine) {
            $nextFrame[$line++] = $promptLine;
        }

        $hudStartLine = max($line, $terminalHeight - count($hudLines) + 1);
        foreach ($hudLines as $index => $hudLine) {
            $nextFrame[$hudStartLine + $index] = $hudLine;
        }

        $linesToUpdate = array_unique(array_merge(
            array_keys($this->renderedLines),
            array_keys($nextFrame),
        ));
        sort($linesToUpdate, SORT_NUMERIC);

        foreach ($linesToUpdate as $lineNumber) {
            $previous = $this->renderedLines[$lineNumber] ?? null;
            $current = $nextFrame[$lineNumber] ?? null;

            if ($previous === $current) {
                continue;
            }

            $this->clearLineAt($lineNumber);
            if ($current !== null) {
                $this->output->write($current, false);
            }
        }

        $this->writeRaw(sprintf("\033[%d;%dH", $promptStartLine + $cursorLineIndex, max(1, $cursorColumn + 1)));
        $this->renderedLines = $nextFrame;
    }

    public function clear(): void
    {
        if ($this->renderedLines === []) {
            return;
        }

        $lines = array_keys($this->renderedLines);
        sort($lines, SORT_NUMERIC);

        foreach ($lines as $line) {
            $this->clearLineAt($line);
        }

        $this->renderedLines = [];
    }

    public function reset(): void
    {
        $this->renderedLines = [];
    }

    private function terminalHeight(): int
    {
        $height = $this->heightProvider !== null
            ? (int) (($this->heightProvider)())
            : (new \Symfony\Component\Console\Terminal)->getHeight();

        return max(1, $height);
    }

    private function clearLineAt(int $line): void
    {
        $this->writeRaw(sprintf("\033[%d;1H\033[2K", max(1, $line)));
    }

    private function writeRaw(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }
}
