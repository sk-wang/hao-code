<?php

namespace App\Support\Terminal;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Renders tool execution results for TUI display, matching claude-code's
 * rich tool result rendering.
 *
 * Formats colored diffs for file edits, truncated output for bash,
 * file info for reads, match counts for search tools, etc.
 */
class ToolResultRenderer
{
    private bool $supportsColor;
    private int $termWidth;

    public function __construct(?int $termWidth = null)
    {
        $this->supportsColor = $this->detectColor();
        $this->termWidth = $termWidth ?? $this->detectTermWidth();
    }

    /**
     * Render a tool result for display. Returns formatted string or null to skip.
     */
    public function render(string $toolName, array $input, string $output, bool $isError): ?string
    {
        if ($isError) {
            return $this->renderError($toolName, $output);
        }

        return match ($toolName) {
            'Edit' => $this->renderEdit($input, $output),
            'Write' => $this->renderWrite($input, $output),
            'Bash' => $this->renderBash($input, $output),
            'Read' => $this->renderRead($input, $output),
            'Glob' => $this->renderGlob($input, $output),
            'Grep' => $this->renderGrep($input, $output),
            'Agent' => $this->renderAgent($input, $output),
            'WebFetch' => $this->renderWebFetch($input, $output),
            'WebSearch' => $this->renderWebSearch($input, $output),
            default => null, // No special rendering for other tools
        };
    }

    private function renderError(string $toolName, string $output): string
    {
        $message = $this->truncate($this->firstMeaningfulLine($output), 120);

        return $this->red("  ✗ {$toolName}: {$message}");
    }

    private function renderEdit(array $input, string $output): string
    {
        $file = basename($input['file_path'] ?? 'file');

        // Extract and color the diff if present
        $lines = [];
        $lines[] = $this->green("  ✓ ") . $this->dim("Edit ") . $this->white($file);

        // Parse the output for diff info
        if (preg_match('/\(([^)]+lines)\)/', $output, $m)) {
            $lines[0] .= $this->dim(" ({$m[1]})");
        }

        // Extract git diff from output
        $gitDiff = $this->extractSection($output, 'Git diff:');
        if ($gitDiff !== '') {
            $diffLines = $this->colorDiff($gitDiff);
            if (!empty($diffLines)) {
                $lines[] = '';
                foreach (array_slice($diffLines, 0, 30) as $dl) {
                    $lines[] = "    {$dl}";
                }
                if (count($diffLines) > 30) {
                    $lines[] = $this->dim("    ... " . (count($diffLines) - 30) . " more lines");
                }
            }
        } else {
            // Fallback: show the Replaced: snippet
            $replaced = $this->extractSection($output, 'Replaced');
            if ($replaced !== '') {
                foreach (explode("\n", $replaced) as $rl) {
                    if (str_starts_with($rl, '- ')) {
                        $lines[] = $this->red("    " . $rl);
                    } elseif (str_starts_with($rl, '+ ')) {
                        $lines[] = $this->green("    " . $rl);
                    } else {
                        $lines[] = $this->dim("    " . $rl);
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    private function renderWrite(array $input, string $output): string
    {
        $file = basename($input['file_path'] ?? 'file');
        $isCreate = str_contains($output, 'created');
        $icon = $isCreate ? '+' : '~';
        $verb = $isCreate ? 'Created' : 'Updated';

        $line = $this->green("  ✓ ") . $this->dim("{$verb} ") . $this->white($file);

        // Extract size info
        if (preg_match('/\((\d+ lines?, \d+ bytes)\)/', $output, $m)) {
            $line .= $this->dim(" ({$m[1]})");
        }

        // Show change summary if update
        if (!$isCreate && preg_match('/\[([^\]]+)\]/', $output, $m)) {
            $line .= $this->dim(" [{$m[1]}]");
        }

        return $line;
    }

    private function renderBash(array $input, string $output): string
    {
        $desc = $input['description'] ?? null;
        $cmd = $input['command'] ?? '';

        $label = $desc ?: $this->truncate($cmd, 60);
        $lines = [];
        $lines[] = $this->green("  ✓ ") . $this->dim("Bash ") . $this->white($label);

        // Extract exit code
        $exitCode = 0;
        if (preg_match('/exitCode.*?(\d+)/', $output, $m)) {
            $exitCode = (int) $m[1];
        }

        // Show truncated output (skip if "(no output)")
        $cleanOutput = $output;
        // Strip warnings section
        $cleanOutput = preg_replace('/<warnings>.*?<\/warnings>\s*/s', '', $cleanOutput);
        $cleanOutput = trim($cleanOutput);

        if ($cleanOutput !== '' && $cleanOutput !== '(no output)') {
            $outputLines = explode("\n", $cleanOutput);
            $maxShow = 15;
            $shown = array_slice($outputLines, 0, $maxShow);

            foreach ($shown as $ol) {
                $trimmed = $this->truncate($ol, $this->termWidth - 6);
                $lines[] = $this->dim("    " . $trimmed);
            }

            if (count($outputLines) > $maxShow) {
                $lines[] = $this->dim("    ... " . (count($outputLines) - $maxShow) . " more lines");
            }
        }

        return implode("\n", $lines);
    }

    private function renderRead(array $input, string $output): string
    {
        $file = basename($input['file_path'] ?? 'file');
        $line = $this->green("  ✓ ") . $this->dim("Read ") . $this->white($file);

        // Extract line count
        if (preg_match('/\((\d+) lines total\)/', $output, $m)) {
            $line .= $this->dim(" ({$m[1]} lines)");
        }

        // Show if partial
        if (preg_match('/Lines (\d+-\d+)/', $output, $m)) {
            $line .= $this->dim(" [{$m[1]}]");
        }

        return $line;
    }

    private function renderGlob(array $input, string $output): string
    {
        $pattern = $input['pattern'] ?? '*';
        $trimmedOutput = trim($output);
        $fileCount = 0;

        if (str_starts_with($trimmedOutput, 'No files matched pattern:')) {
            $fileCount = 0;
        } elseif (preg_match('/Found\s+(\d+)\s+file\(s\)/', $trimmedOutput, $matches)) {
            $fileCount = (int) $matches[1];
        } else {
            $lines = explode("\n", $trimmedOutput);
            foreach ($lines as $lineText) {
                $lineText = trim($lineText);
                if ($lineText !== '' && !str_starts_with($lineText, '[')) {
                    $fileCount++;
                }
            }
        }

        // Check for truncation
        $truncated = str_contains(strtolower($output), 'showing first');

        $line = $this->green("  ✓ ") . $this->dim("Glob ") . $this->white($pattern);
        $line .= $this->dim(" ({$fileCount} files" . ($truncated ? ', truncated' : '') . ")");

        return $line;
    }

    private function renderGrep(array $input, string $output): string
    {
        $pattern = $input['pattern'] ?? '';
        $lineCount = substr_count(trim($output), "\n") + 1;

        $line = $this->green("  ✓ ") . $this->dim("Grep ") . $this->white($this->truncate($pattern, 40));
        $line .= $this->dim(" ({$lineCount} result lines)");

        return $line;
    }

    private function renderAgent(array $input, string $output): string
    {
        $desc = $input['description'] ?? ($input['subagent_type'] ?? 'Agent');
        $line = $this->green("  ✓ ") . $this->magenta("Agent ") . $this->white($desc);

        // Show truncated result
        $preview = $this->truncate($this->firstMeaningfulLine($output), 80);
        if ($preview !== '') {
            $line .= "\n" . $this->dim("    " . $preview);
        }

        return $line;
    }

    private function renderWebFetch(array $input, string $output): string
    {
        $url = $input['url'] ?? '';
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return $this->green("  ✓ ") . $this->dim("Fetch ") . $this->white($this->truncate($host, 40));
    }

    private function renderWebSearch(array $input, string $output): string
    {
        $query = $input['query'] ?? '';
        $resultCount = substr_count($output, 'http');

        $line = $this->green("  ✓ ") . $this->dim("Search ") . $this->white($this->truncate($query, 40));
        if ($resultCount > 0) {
            $line .= $this->dim(" ({$resultCount} results)");
        }

        return $line;
    }

    // ─── Diff coloring ─────────────────────────────────────────────────

    /**
     * Color a unified diff with red/green for removed/added lines.
     *
     * @return string[]
     */
    private function colorDiff(string $diff): array
    {
        $lines = [];

        foreach (explode("\n", $diff) as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                $lines[] = $this->dim($line);
            } elseif (str_starts_with($line, '@@')) {
                $lines[] = $this->cyan($line);
            } elseif (str_starts_with($line, '+')) {
                $lines[] = $this->green($line);
            } elseif (str_starts_with($line, '-')) {
                $lines[] = $this->red($line);
            } else {
                $lines[] = $this->dim($line);
            }
        }

        return $lines;
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function extractSection(string $output, string $header): string
    {
        $pos = strpos($output, $header);
        if ($pos === false) {
            return '';
        }

        return trim(substr($output, $pos + strlen($header)));
    }

    private function firstMeaningfulLine(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '<') && !str_starts_with($line, '[')) {
                return $line;
            }
        }

        return trim(substr($text, 0, 120));
    }

    private function truncate(string $text, int $max): string
    {
        $text = str_replace(["\n", "\r"], [' ', ''], $text);
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 1) . '…';
        }

        return $text;
    }

    // ─── ANSI color methods ─────────────────────────────────────────────

    private function green(string $text): string
    {
        return $this->color($text, '32');
    }

    private function red(string $text): string
    {
        return $this->color($text, '31');
    }

    private function cyan(string $text): string
    {
        return $this->color($text, '36');
    }

    private function magenta(string $text): string
    {
        return $this->color($text, '35');
    }

    private function white(string $text): string
    {
        return $this->color($text, '1'); // bold
    }

    private function dim(string $text): string
    {
        return $this->color($text, '2');
    }

    private function color(string $text, string $code): string
    {
        if (!$this->supportsColor) {
            return $text;
        }

        return "\033[{$code}m{$text}\033[0m";
    }

    private function detectColor(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('TERM') === 'dumb') {
            return false;
        }

        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    private function detectTermWidth(): int
    {
        $cols = (int) (getenv('COLUMNS') ?: 0);
        if ($cols > 0) {
            return $cols;
        }

        if (function_exists('exec')) {
            $output = [];
            @exec('tput cols 2>/dev/null', $output);
            $cols = (int) ($output[0] ?? 0);
            if ($cols > 0) {
                return $cols;
            }
        }

        return 120;
    }
}
