<?php

namespace App\Support\Terminal;

use Symfony\Component\Console\Formatter\OutputFormatter;

class ReplFormatter
{
    private const LOADING_COLOR = 'yellow';

    private OutputFormatter $outputFormatter;

    public function __construct()
    {
        $this->outputFormatter = new OutputFormatter(true);
    }

    public function prompt(string $cwd): string
    {
        return '<fg=green>'.$this->escape($cwd).'</> <fg=cyan>❯</> ';
    }

    public function continuationPrompt(): string
    {
        return '<fg=gray>…</> ';
    }

    /**
     * @return array<int, string>
     */
    public function banner(string $phpVersion, string $framework = 'Laravel Framework'): array
    {
        $title = 'Hao Code CLI Coding Agent';
        $subtitle = "PHP {$phpVersion} · {$framework}";
        $contentWidth = max($this->displayWidth($title), $this->displayWidth($subtitle));
        $border = str_repeat('─', $contentWidth + 2);

        return [
            '',
            "  <fg=cyan;bold>╭{$border}╮</>",
            sprintf(
                "  <fg=cyan;bold>│</>%s<fg=white;bold>%s</>%s<fg=cyan;bold>│</>",
                $this->leadingPaddingFor($title, $contentWidth),
                $this->escape($title),
                $this->trailingPaddingFor($title, $contentWidth),
            ),
            sprintf(
                "  <fg=cyan;bold>│</>%s<fg=gray>%s</>%s<fg=cyan;bold>│</>",
                $this->leadingPaddingFor($subtitle, $contentWidth),
                $this->escape($subtitle),
                $this->trailingPaddingFor($subtitle, $contentWidth),
            ),
            "  <fg=cyan;bold>╰{$border}╯</>",
        ];
    }

    public function helpHint(): string
    {
        return "  <fg=gray>Type '/help' for commands, '/exit' to quit</>";
    }

    public function readlinePrompt(string $cwd): string
    {
        return $this->wrapAnsiForReadline($this->outputFormatter->format($this->prompt($cwd)));
    }

    public function readlineContinuationPrompt(): string
    {
        return $this->wrapAnsiForReadline($this->outputFormatter->format($this->continuationPrompt()));
    }

    public function toolCall(string $toolName, string $summary = ''): string
    {
        $label = '  <fg=magenta>⚙ '.$this->escape($toolName).'</>';

        if ($summary === '') {
            return $label;
        }

        return $label.'<fg=gray>('.$this->escape($summary).')</>';
    }

    public function toolFailure(string $toolName, string $message): string
    {
        return '  <fg=red>✗ '.$this->escape($toolName).' failed:</> <fg=gray>'.$this->escape($message).'</>';
    }

    public function loadingStatus(string $verb, int $elapsedSeconds, ?int $approxTokens = null): string
    {
        $details = "{$elapsedSeconds}s";

        if ($approxTokens !== null && $approxTokens > 0) {
            $details .= " · ↓ {$approxTokens} tokens";
        }

        return '  <fg='.self::LOADING_COLOR.'>✻ '.$this->escape($verb).'...</> <fg=gray>('.$details.')</>';
    }

    public function usageFooter(
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens,
        float $cost,
    ): string {
        $parts = [$inputTokens.'in', $outputTokens.'out'];

        if ($cacheReadTokens > 0) {
            $parts[] = $cacheReadTokens.'cache';
        }

        return '<fg=gray>  ['.implode('/', $parts).' tokens · $'.$this->formatCost($cost).']</>';
    }

    private function formatCost(float $cost): string
    {
        $formatted = number_format($cost, 6, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }

    private function leadingPaddingFor(string $text, int $width): string
    {
        $total = max(0, $width - $this->displayWidth($text));
        $left = intdiv($total, 2);

        return ' ' . str_repeat(' ', $left);
    }

    private function trailingPaddingFor(string $text, int $width): string
    {
        $total = max(0, $width - $this->displayWidth($text));
        $right = $total - intdiv($total, 2);

        return str_repeat(' ', $right) . ' ';
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

    private function wrapAnsiForReadline(string $text): string
    {
        return preg_replace('/(\e\[[0-9;]*m)/', "\001$1\002", $text) ?? $text;
    }
}
