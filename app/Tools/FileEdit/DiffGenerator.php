<?php

namespace App\Tools\FileEdit;

/**
 * Generates unified diffs and structured patches for file edits,
 * matching claude-code's diff output format.
 */
class DiffGenerator
{
    /**
     * Generate a unified diff between old and new content.
     * Uses the system `diff` command for accuracy, falls back to PHP.
     */
    public static function unifiedDiff(string $oldContent, string $newContent, string $filePath = 'file'): string
    {
        $oldFile = tempnam(sys_get_temp_dir(), 'haocode_diff_old_');
        $newFile = tempnam(sys_get_temp_dir(), 'haocode_diff_new_');

        try {
            file_put_contents($oldFile, $oldContent);
            file_put_contents($newFile, $newContent);

            $escapedOld = escapeshellarg($oldFile);
            $escapedNew = escapeshellarg($newFile);
            $label = escapeshellarg($filePath);

            $output = shell_exec("diff -u --label {$label} --label {$label} {$escapedOld} {$escapedNew} 2>/dev/null");

            return $output ?? '';
        } finally {
            @unlink($oldFile);
            @unlink($newFile);
        }
    }

    /**
     * Generate a git diff if the file is in a git repository.
     * Returns empty string if not in a git repo or git is unavailable.
     */
    public static function gitDiff(string $filePath): string
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            return '';
        }

        $escaped = escapeshellarg($filePath);
        $escapedDir = escapeshellarg($dir);

        // Check if we're in a git repo
        $inGit = trim(shell_exec("cd {$escapedDir} && git rev-parse --is-inside-work-tree 2>/dev/null") ?? '');
        if ($inGit !== 'true') {
            return '';
        }

        $diff = shell_exec("cd {$escapedDir} && git diff -- {$escaped} 2>/dev/null");

        return trim($diff ?? '');
    }

    /**
     * Generate a structured patch (array of hunks) from old and new content.
     *
     * Each hunk: ['oldStart' => int, 'oldLines' => int, 'newStart' => int, 'newLines' => int, 'lines' => string[]]
     *
     * @return array<int, array{oldStart: int, oldLines: int, newStart: int, newLines: int, lines: string[]}>
     */
    public static function structuredPatch(string $oldContent, string $newContent, string $filePath = 'file'): array
    {
        $diff = self::unifiedDiff($oldContent, $newContent, $filePath);
        if ($diff === '') {
            return [];
        }

        $lines = explode("\n", $diff);
        $hunks = [];
        $currentHunk = null;

        foreach ($lines as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                if ($currentHunk !== null) {
                    $hunks[] = $currentHunk;
                }
                $currentHunk = [
                    'oldStart' => (int) $m[1],
                    'oldLines' => isset($m[2]) ? (int) $m[2] : 1,
                    'newStart' => (int) $m[3],
                    'newLines' => isset($m[4]) ? (int) $m[4] : 1,
                    'lines' => [],
                ];
                continue;
            }

            if ($currentHunk !== null && ($line === '' || $line[0] === ' ' || $line[0] === '+' || $line[0] === '-')) {
                $currentHunk['lines'][] = $line;
            }
        }

        if ($currentHunk !== null) {
            $hunks[] = $currentHunk;
        }

        return $hunks;
    }

    /**
     * Format a compact summary of changes (additions/deletions).
     */
    public static function changeSummary(string $oldContent, string $newContent): string
    {
        $oldLines = substr_count($oldContent, "\n") + ($oldContent !== '' ? 1 : 0);
        $newLines = substr_count($newContent, "\n") + ($newContent !== '' ? 1 : 0);
        $added = max(0, $newLines - $oldLines);
        $removed = max(0, $oldLines - $newLines);

        $parts = [];
        if ($added > 0) {
            $parts[] = "+{$added}";
        }
        if ($removed > 0) {
            $parts[] = "-{$removed}";
        }
        if ($parts === []) {
            $parts[] = '~modified';
        }

        return implode(' ', $parts) . ' lines';
    }
}
