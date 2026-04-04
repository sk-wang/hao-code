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
        return "  <fg=gray>/help commands · Tab/↑↓ autocomplete · Ctrl+O transcript · Ctrl+R history · Ctrl+C interrupt · Ctrl+D exit · \\ multiline</>";
    }

    public function readlinePrompt(string $cwd): string
    {
        return $this->wrapAnsiForReadline($this->outputFormatter->format($this->prompt($cwd)));
    }

    public function readlineContinuationPrompt(): string
    {
        return $this->wrapAnsiForReadline($this->outputFormatter->format($this->continuationPrompt()));
    }

    /**
     * @param  array{
     *   model: string,
     *   message_count: int,
     *   permission_mode: string,
     *   fast_mode?: bool,
     *   layout?: string,
     *   title?: string|null,
     *   project?: string|null,
     *   branch?: string|null,
     *   git_dirty?: bool,
     *   context_percent?: float,
     *   context_tokens?: int,
     *   context_limit?: int,
     *   context_state?: string,
     *   cost?: float,
     *   cost_warn?: float,
     *   show_tools?: bool,
     *   show_agents?: bool,
     *   show_todos?: bool,
     *   tools?: array{
     *     running?: array<int, array{name: string, target: string|null}>,
     *     completed?: array<int, array{name: string, count: int}>
     *   },
     *   agents?: array{
     *     bash_tasks?: int,
     *     entries?: array<int, array{
     *       status: string,
     *       agent_type: string,
     *       description?: string|null,
     *       elapsed_seconds?: int,
     *       pending_messages?: int
     *     }>
     *   },
     *   todo?: array{
     *     current: string|null,
     *     completed: int,
     *     total: int,
     *     all_completed: bool
     *   }|null
     * }  $snapshot
     */
    public function promptFooter(array $snapshot): string
    {
        return implode("\n", $this->promptFooterLines($snapshot));
    }

    /**
     * @param  array{
     *   model: string,
     *   message_count: int,
     *   permission_mode: string,
     *   fast_mode?: bool,
     *   layout?: string,
     *   title?: string|null,
     *   project?: string|null,
     *   branch?: string|null,
     *   git_dirty?: bool,
     *   context_percent?: float,
     *   context_tokens?: int,
     *   context_limit?: int,
     *   context_state?: string,
     *   cost?: float,
     *   cost_warn?: float,
     *   show_tools?: bool,
     *   show_agents?: bool,
     *   show_todos?: bool,
     *   tools?: array{
     *     running?: array<int, array{name: string, target: string|null}>,
     *     completed?: array<int, array{name: string, count: int}>
     *   },
     *   agents?: array{
     *     bash_tasks?: int,
     *     entries?: array<int, array{
     *       status: string,
     *       agent_type: string,
     *       description?: string|null,
     *       elapsed_seconds?: int,
     *       pending_messages?: int
     *     }>
     *   },
     *   todo?: array{
     *     current: string|null,
     *     completed: int,
     *     total: int,
     *     all_completed: bool
     *   }|null
     * }  $snapshot
     * @return array<int, string>
     */
    public function promptFooterLines(array $snapshot): array
    {
        $layout = strtolower((string) ($snapshot['layout'] ?? 'expanded'));
        if ($layout === 'compact') {
            return $this->compactPromptFooterLines($snapshot);
        }

        $identitySegments = [];
        $model = $this->truncate((string) ($snapshot['model'] ?? 'unknown'), 28);
        $identitySegments[] = '<fg=cyan>['.$this->escape($model).']</>';

        $title = trim((string) ($snapshot['title'] ?? ''));
        if ($title !== '') {
            $identitySegments[] = '<fg=white>'.$this->escape($this->truncate($title, 24)).'</>';
        }

        $project = trim((string) ($snapshot['project'] ?? ''));
        $branch = trim((string) ($snapshot['branch'] ?? ''));
        if ($project !== '' || $branch !== '') {
            $projectSegment = $project !== ''
                ? '<fg=yellow>'.$this->escape($this->truncate($project, 24)).'</>'
                : '';
            $gitSegment = $branch !== ''
                ? '<fg=magenta>git:(</><fg=cyan>'.$this->escape($this->truncate(
                    $branch.((bool) ($snapshot['git_dirty'] ?? false) ? '*' : ''),
                    24,
                )).'</><fg=magenta>)</>'
                : '';

            $identitySegments[] = trim($projectSegment.' '.$gitSegment);
        }

        $meta = [
            ((int) ($snapshot['message_count'] ?? 0)).' msgs',
            (string) ($snapshot['permission_mode'] ?? 'default'),
        ];

        if ((bool) ($snapshot['fast_mode'] ?? false)) {
            $meta[] = 'fast';
        }

        $identitySegments[] = '<fg=gray>'.$this->escape(implode(' · ', $meta)).'</>';

        $contextPercent = max(0.0, min(100.0, (float) ($snapshot['context_percent'] ?? 0.0)));
        $contextState = (string) ($snapshot['context_state'] ?? 'normal');
        $contextColor = $this->contextColor($contextState);
        $contextLine = implode(' <fg=gray>·</> ', array_filter([
            '<fg=gray>Context</> <fg='.$contextColor.'>'.$this->contextBar($contextPercent).'</> '
                .'<fg='.$contextColor.'>'.$this->escape(
                    sprintf(
                        '%s (%s/%s)',
                        (string) ((int) round($contextPercent)).'%',
                        $this->formatCompactNumber((int) ($snapshot['context_tokens'] ?? 0)),
                        $this->formatCompactNumber((int) ($snapshot['context_limit'] ?? 180000)),
                    ),
                ).'</>',
            '<fg=gray>Cost</> <fg='.$this->costColor(
                (float) ($snapshot['cost'] ?? 0.0),
                (float) ($snapshot['cost_warn'] ?? 0.0),
            ).'>$'.$this->formatCost((float) ($snapshot['cost'] ?? 0.0)).'</>'
                .'<fg=gray>/'.$this->escape('$'.$this->formatCost((float) ($snapshot['cost_warn'] ?? 0.0)).' warn').'</>',
            '<fg=gray>Ctrl+O transcript · Ctrl+R history</>',
        ]));

        $lines = [
            '  '.implode(' <fg=gray>·</> ', array_filter($identitySegments, static fn (string $segment): bool => $segment !== '')),
            '  '.$contextLine,
        ];

        if ((bool) ($snapshot['show_tools'] ?? true)) {
            $toolParts = [];
            foreach (($snapshot['tools']['running'] ?? []) as $tool) {
                $toolParts[] = '<fg=yellow>◐</> <fg=cyan>'.$this->escape($this->truncate((string) ($tool['name'] ?? 'tool'), 16)).'</>'
                    .($this->stringValue($tool['target'] ?? null) !== null
                        ? '<fg=gray>: '.$this->escape($this->truncate((string) $tool['target'], 24)).'</>'
                        : '');
            }
            foreach (($snapshot['tools']['completed'] ?? []) as $tool) {
                $toolParts[] = '<fg=green>✓</> <fg=cyan>'.$this->escape($this->truncate((string) ($tool['name'] ?? 'tool'), 16)).'</>'
                    .'<fg=gray>×'.$this->escape((string) ($tool['count'] ?? 0)).'</>';
            }
            if ($toolParts !== []) {
                $lines[] = '  <fg=gray>Tools</> '.implode(' <fg=gray>·</> ', $toolParts);
            }
        }

        if ((bool) ($snapshot['show_agents'] ?? true)) {
            $agentParts = [];
            $bashTasks = (int) ($snapshot['agents']['bash_tasks'] ?? 0);
            if ($bashTasks > 0) {
                $agentParts[] = '<fg=yellow>◐</> <fg=cyan>bash</><fg=gray>×'.$this->escape((string) $bashTasks).'</>';
            }

            foreach (($snapshot['agents']['entries'] ?? []) as $agent) {
                $agentParts[] = $this->formatAgentFooterEntry($agent);
            }
            if ($agentParts !== []) {
                $lines[] = '  <fg=gray>Background</> '.implode(' <fg=gray>·</> ', $agentParts);
            }
        }

        $todo = $snapshot['todo'] ?? null;
        if ((bool) ($snapshot['show_todos'] ?? true) && is_array($todo) && ((int) ($todo['total'] ?? 0)) > 0) {
            $progress = '<fg=gray>('.$this->escape((string) ($todo['completed'] ?? 0).'/'.(string) ($todo['total'] ?? 0)).')</>';

            if ((bool) ($todo['all_completed'] ?? false)) {
                $lines[] = '  <fg=gray>Todo</> <fg=green>✓</> <fg=white>all complete</> '.$progress;
            } elseif ($this->stringValue($todo['current'] ?? null) !== null) {
                $lines[] = '  <fg=gray>Todo</> <fg=yellow>▸</> <fg=white>'.$this->escape(
                    $this->truncate((string) $todo['current'], 52),
                ).'</> '.$progress;
            }
        }

        return $lines;
    }

    /**
     * @param  array{
     *   model: string,
     *   message_count: int,
     *   permission_mode: string,
     *   fast_mode?: bool,
     *   title?: string|null,
     *   project?: string|null,
     *   branch?: string|null,
     *   git_dirty?: bool,
     *   context_percent?: float,
     *   context_tokens?: int,
     *   context_limit?: int,
     *   cost?: float,
     *   show_tools?: bool,
     *   show_agents?: bool,
     *   show_todos?: bool,
     *   tools?: array{
     *     running?: array<int, array{name: string, target: string|null}>,
     *     completed?: array<int, array{name: string, count: int}>
     *   },
     *   agents?: array{
     *     bash_tasks?: int,
     *     entries?: array<int, array{
     *       status: string,
     *       agent_type: string,
     *       description?: string|null,
     *       elapsed_seconds?: int,
     *       pending_messages?: int
     *     }>
     *   },
     *   todo?: array{
     *     current: string|null,
     *     completed: int,
     *     total: int,
     *     all_completed: bool
     *   }|null
     * }  $snapshot
     * @return array<int, string>
     */
    private function compactPromptFooterLines(array $snapshot): array
    {
        $contextColor = $this->contextColor((string) ($snapshot['context_state'] ?? 'normal'));
        $segments = [];
        $segments[] = '<fg=cyan>['.$this->escape($this->truncate((string) ($snapshot['model'] ?? 'unknown'), 18)).']</>';

        $title = $this->stringValue($snapshot['title'] ?? null);
        if ($title !== null) {
            $segments[] = '<fg=white>'.$this->escape($this->truncate($title, 18)).'</>';
        }

        $project = $this->stringValue($snapshot['project'] ?? null);
        if ($project !== null) {
            $git = $this->stringValue($snapshot['branch'] ?? null);
            $projectText = $project;
            if ($git !== null) {
                $projectText .= ' git:('.$git.((bool) ($snapshot['git_dirty'] ?? false) ? '*' : '').')';
            }
            $segments[] = '<fg=yellow>'.$this->escape($this->truncate($projectText, 28)).'</>';
        }

        $meta = ((int) ($snapshot['message_count'] ?? 0)).' msgs/'.(string) ($snapshot['permission_mode'] ?? 'default');
        if ((bool) ($snapshot['fast_mode'] ?? false)) {
            $meta .= '/fast';
        }
        $segments[] = '<fg=gray>'.$this->escape($meta).'</>';
        $segments[] = '<fg=gray>ctx</> <fg='.$contextColor.'>'.$this->contextBar((float) ($snapshot['context_percent'] ?? 0.0), 6).'</> <fg='.$contextColor.'>'.$this->escape((string) ((int) round((float) ($snapshot['context_percent'] ?? 0.0))).'%').'</>';
        $segments[] = '<fg=gray>$'.$this->formatCost((float) ($snapshot['cost'] ?? 0.0)).'</>';

        $lines = [
            '  '.implode(' <fg=gray>·</> ', $segments),
        ];

        $activity = [];

        $todo = $snapshot['todo'] ?? null;
        if ((bool) ($snapshot['show_todos'] ?? true) && is_array($todo) && ((int) ($todo['total'] ?? 0)) > 0) {
            if ((bool) ($todo['all_completed'] ?? false)) {
                $activity[] = '<fg=green>todo</> <fg=gray>'.$this->escape((string) ($todo['completed'] ?? 0).'/'.(string) ($todo['total'] ?? 0)).'</>';
            } elseif ($this->stringValue($todo['current'] ?? null) !== null) {
                $activity[] = '<fg=yellow>todo</> <fg=white>'.$this->escape(
                    $this->truncate((string) $todo['current'], 28),
                ).'</> <fg=gray>'.$this->escape((string) ($todo['completed'] ?? 0).'/'.(string) ($todo['total'] ?? 0)).'</>';
            }
        }

        if ((bool) ($snapshot['show_agents'] ?? true)) {
            $bashTasks = (int) ($snapshot['agents']['bash_tasks'] ?? 0);
            if ($bashTasks > 0) {
                $activity[] = '<fg=yellow>bg</> <fg=cyan>bash×'.$this->escape((string) $bashTasks).'</>';
            }

            $agent = ($snapshot['agents']['entries'][0] ?? null);
            if (is_array($agent)) {
                $activity[] = '<fg=yellow>bg</> <fg=magenta>'.$this->escape($this->truncate((string) ($agent['agent_type'] ?? 'agent'), 14)).'</> <fg=gray>'.$this->escape(
                    $this->formatElapsed((int) ($agent['elapsed_seconds'] ?? 0)),
                ).'</>';
            }
        }

        if ((bool) ($snapshot['show_tools'] ?? true)) {
            $tools = [];
            foreach (array_slice(($snapshot['tools']['completed'] ?? []), 0, 2) as $tool) {
                if (! is_array($tool)) {
                    continue;
                }
                $tools[] = $this->truncate((string) ($tool['name'] ?? 'tool'), 12).'×'.(string) ($tool['count'] ?? 0);
            }
            if ($tools !== []) {
                $activity[] = '<fg=yellow>tools</> <fg=cyan>'.$this->escape(implode(' ', $tools)).'</>';
            }
        }

        if ($activity !== []) {
            $lines[] = '  '.implode(' <fg=gray>·</> ', $activity);
        }

        return $lines;
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

    private function contextBar(float $percent, int $width = 10): string
    {
        $filled = max(0, min($width, (int) round(($percent / 100) * $width)));

        return str_repeat('█', $filled).str_repeat('░', $width - $filled);
    }

    private function contextColor(string $state): string
    {
        return match ($state) {
            'critical' => 'red',
            'warning' => 'yellow',
            default => 'green',
        };
    }

    private function costColor(float $cost, float $warn): string
    {
        if ($warn > 0 && $cost >= $warn) {
            return 'yellow';
        }

        return 'gray';
    }

    private function formatCompactNumber(int $value): string
    {
        if ($value >= 1_000_000) {
            return number_format($value / 1_000_000, 1).'m';
        }

        if ($value >= 1_000) {
            return number_format($value / 1_000, $value >= 10_000 ? 0 : 1).'k';
        }

        return (string) $value;
    }

    /**
     * @param  array{
     *   status: string,
     *   agent_type: string,
     *   description?: string|null,
     *   elapsed_seconds?: int,
     *   pending_messages?: int
     * }  $agent
     */
    private function formatAgentFooterEntry(array $agent): string
    {
        $status = (string) ($agent['status'] ?? 'running');
        $icon = match ($status) {
            'completed' => '<fg=green>✓</>',
            'error' => '<fg=red>✗</>',
            default => '<fg=yellow>◐</>',
        };

        $parts = [
            $icon.' <fg=magenta>'.$this->escape($this->truncate((string) ($agent['agent_type'] ?? 'agent'), 16)).'</>',
        ];

        $description = $this->stringValue($agent['description'] ?? null);
        if ($description !== null) {
            $parts[] = '<fg=gray>: '.$this->escape($this->truncate($description, 28)).'</>';
        }

        $elapsed = max(0, (int) ($agent['elapsed_seconds'] ?? 0));
        $meta = $this->formatElapsed($elapsed);
        $pending = (int) ($agent['pending_messages'] ?? 0);
        if ($pending > 0) {
            $meta .= ' · '.$pending.' queued';
        }

        $parts[] = '<fg=gray>('.$this->escape($meta).')</>';

        return implode('', $parts);
    }

    private function formatElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $minutes.'m '.$remainingSeconds.'s';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours.'h '.$remainingMinutes.'m';
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
