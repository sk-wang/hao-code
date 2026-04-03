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
        return "  <fg=gray>/help commands · Ctrl+O transcript · Ctrl+R history · Ctrl+C interrupt · Ctrl+D exit · \\ multiline</>";
    }

    public function readlinePrompt(string $cwd): string
    {
        return $this->wrapAnsiForReadline($this->outputFormatter->format($this->prompt($cwd)));
    }

    public function readlineContinuationPrompt(): string
    {
        return $this->wrapAnsiForReadline($this->outputFormatter->format($this->continuationPrompt()));
    }

    public function promptFooter(
        string $model,
        int $messageCount,
        string $permissionMode,
        bool $fastMode = false,
        ?string $title = null,
    ): string {
        $parts = [];

        if ($title !== null && trim($title) !== '') {
            $parts[] = $this->truncate($title, 24);
        }

        $parts[] = $this->truncate($model, 26);
        $parts[] = $messageCount . ' msgs';
        $parts[] = $permissionMode;

        if ($fastMode) {
            $parts[] = 'fast';
        }

        $parts[] = 'Ctrl+O transcript';
        $parts[] = 'Ctrl+R history';

        return '  <fg=gray>' . $this->escape(implode(' · ', $parts)) . '</>';
    }

    public function transcriptFooter(
        int $line,
        int $totalLines,
        ?string $query = null,
        int $currentMatch = 0,
        int $matchCount = 0,
        ?string $status = null,
    ): string {
        $parts = [];
        $percent = $totalLines > 0 ? (int) round(($line / max(1, $totalLines)) * 100) : 100;
        $parts[] = "Line {$line}/{$totalLines}";
        $parts[] = "{$percent}%";

        if ($query !== null && trim($query) !== '') {
            $parts[] = "Search: {$query}";
            $parts[] = "{$currentMatch}/{$matchCount}";
        }

        if ($status !== null && trim($status) !== '') {
            $parts[] = $status;
        }

        $parts[] = 'j/k move';
        $parts[] = 'space/b page';
        $parts[] = '/ search';
        $parts[] = 'q quit';

        return '  <fg=gray>' . $this->escape(implode(' · ', $parts)) . '</>';
    }

    public function reverseSearchStatus(string $query, string $match, int $current, int $total): string
    {
        $label = $query === '' ? '(type to search)' : $query;
        $display = $match === '' ? '(no match)' : $this->truncate($match, 120);
        $suffix = $total > 0 ? " {$current}/{$total}" : '';

        return '<fg=yellow>(reverse-i-search)</> <fg=gray>`' . $this->escape($label) . '`:</>' .
            ' <fg=white>' . $this->escape($display) . '</>' .
            '<fg=gray>' . $this->escape($suffix) . '</>';
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

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public function panel(string $title, array $lines, string $color = 'cyan'): array
    {
        $plainTitle = $title;
        $contentWidth = $this->displayWidth($plainTitle);

        foreach ($lines as $line) {
            $contentWidth = max($contentWidth, $this->displayWidth($this->stripMarkup($line)));
        }

        $border = str_repeat('─', $contentWidth + 2);
        $rendered = ["  <fg={$color};bold>╭{$border}╮</>"];
        $rendered[] = sprintf(
            '  <fg=%1$s;bold>│</> <fg=white;bold>%2$s</>%3$s<fg=%1$s;bold>│</>',
            $color,
            $this->escape($plainTitle),
            str_repeat(' ', max(0, $contentWidth - $this->displayWidth($plainTitle) + 1)),
        );

        foreach ($lines as $line) {
            $plain = $this->stripMarkup($line);
            $padding = str_repeat(' ', max(0, $contentWidth - $this->displayWidth($plain)));
            $rendered[] = sprintf(
                '  <fg=%1$s;bold>│</> %2$s%3$s <fg=%1$s;bold>│</>',
                $color,
                $line,
                $padding,
            );
        }

        $rendered[] = "  <fg={$color};bold>╰{$border}╯</>";

        return $rendered;
    }

    public function keyValue(string $label, string $value, string $labelColor = 'gray', string $valueColor = 'white'): string
    {
        return sprintf(
            '<fg=%s>%s:</> <fg=%s>%s</>',
            $labelColor,
            $this->escape($label),
            $valueColor,
            $this->escape($value),
        );
    }

    /**
     * @return array<int, string>
     */
    public function permissionPromptPanel(string $toolName, string $summary): array
    {
        $toolLine = $summary === ''
            ? $this->keyValue('Tool', $toolName)
            : $this->keyValue('Tool', "{$toolName} ({$summary})");

        return $this->panel('Permission required', [
            $toolLine,
            '<fg=green>[y]</> allow once  <fg=green>[a]</> allow session  <fg=red>[n]</> deny',
        ], 'yellow');
    }

    public function loadingStatus(string $verb, int $elapsedSeconds, ?int $approxTokens = null): string
    {
        $details = "{$elapsedSeconds}s";

        if ($approxTokens !== null && $approxTokens > 0) {
            $details .= " · ↓ {$approxTokens} tokens";
        }

        return '  <fg='.self::LOADING_COLOR.'>✻ '.$this->escape($verb).'...</> <fg=gray>('.$details.')</>';
    }

    public function interruptedStatus(): string
    {
        return '  <fg=yellow>⏸ Interrupted</> <fg=gray>(ready for your next prompt)</>';
    }

    public function abortingStatus(): string
    {
        return '  <fg=yellow>⏸ Aborting...</> <fg=gray>(Ctrl+C again to force exit)</>';
    }

    public function runningToolStatus(string $toolName, int $elapsedSeconds): string
    {
        return '  <fg='.self::LOADING_COLOR.'>✻ Running '.$this->escape($toolName).'...</> <fg=gray>('.$elapsedSeconds.'s)</>';
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

    private function truncate(string $text, int $max): string
    {
        if ($this->displayWidth($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, max(1, $max - 1)) . '…';
    }

    private function stripMarkup(string $text): string
    {
        return preg_replace('/<[^>]+>/', '', $text) ?? $text;
    }
}
