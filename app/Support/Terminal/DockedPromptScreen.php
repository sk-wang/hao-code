<?php

namespace App\Support\Terminal;

use Symfony\Component\Console\Output\OutputInterface;

class DockedPromptScreen
{
    private int $lastReservedHeight = 0;

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
     * @param array<int, string> $hudLines
     */
    public function render(array $suggestionLines, string $promptLine, int $cursorColumn, array $hudLines): void
    {
        $terminalHeight = $this->terminalHeight();
        $reservedHeight = max(1, count($suggestionLines) + 1 + count($hudLines));
        $clearHeight = max($reservedHeight, $this->lastReservedHeight);
        $clearFromLine = max(1, $terminalHeight - $clearHeight + 1);

        $this->writeRaw(sprintf("\033[%d;1H\033[J", $clearFromLine));

        $line = max(1, $terminalHeight - $reservedHeight + 1);
        foreach ($suggestionLines as $suggestionLine) {
            $this->writeLineAt($line++, $suggestionLine);
        }

        $promptLineNumber = $line;
        $this->writeLineAt($promptLineNumber, $promptLine);

        $hudStartLine = max($promptLineNumber + 1, $terminalHeight - count($hudLines) + 1);
        foreach ($hudLines as $index => $hudLine) {
            $this->writeLineAt($hudStartLine + $index, $hudLine);
        }

        $this->writeRaw(sprintf("\033[%d;%dH", $promptLineNumber, max(1, $cursorColumn + 1)));
        $this->lastReservedHeight = $reservedHeight;
    }

    public function clear(): void
    {
        if ($this->lastReservedHeight <= 0) {
            return;
        }

        $clearFromLine = max(1, $this->terminalHeight() - $this->lastReservedHeight + 1);
        $this->writeRaw(sprintf("\033[%d;1H\033[J", $clearFromLine));
        $this->lastReservedHeight = 0;
    }

    public function reset(): void
    {
        $this->lastReservedHeight = 0;
    }

    private function terminalHeight(): int
    {
        $height = $this->heightProvider !== null
            ? (int) (($this->heightProvider)())
            : (new \Symfony\Component\Console\Terminal)->getHeight();

        return max(1, $height);
    }

    private function writeLineAt(int $line, string $content): void
    {
        $this->writeRaw(sprintf("\033[%d;1H\033[2K", max(1, $line)));
        $this->output->write($content, false);
    }

    private function writeRaw(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }
}
