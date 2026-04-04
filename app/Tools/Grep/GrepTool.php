<?php

namespace App\Tools\Grep;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class GrepTool extends BaseTool
{
    public function name(): string
    {
        return 'Grep';
    }

    public function description(): string
    {
        return <<<DESC
A powerful search tool built on ripgrep.

Usage:
- ALWAYS use Grep for search tasks. NEVER invoke `grep` or `rg` as a Bash command.
- Supports full regex syntax (e.g., "log.*Error", "function\\s+\\w+")
- Filter files with glob parameter (e.g., "*.js", "**/*.tsx") or type parameter (e.g., "php")
- Output modes: "content" shows matching lines, "files_with_matches" shows file paths, "count" shows counts
- Use `-A`, `-B`, `-C` parameters for context lines
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The regular expression pattern to search for',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'File or directory to search in. Defaults to current working directory.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Glob pattern to filter files (e.g., "*.php")',
                ],
                'output_mode' => [
                    'type' => 'string',
                    'enum' => ['content', 'files_with_matches', 'count'],
                    'description' => 'Output mode (default: files_with_matches)',
                ],
                '-A' => [
                    'type' => 'integer',
                    'description' => 'Number of lines after match',
                ],
                '-B' => [
                    'type' => 'integer',
                    'description' => 'Number of lines before match',
                ],
                '-C' => [
                    'type' => 'integer',
                    'description' => 'Context lines before and after match',
                ],
                '-i' => [
                    'type' => 'boolean',
                    'description' => 'Case insensitive search',
                ],
                'head_limit' => [
                    'type' => 'integer',
                    'description' => 'Limit output to first N entries',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'File type to search (e.g., "php", "js", "py", "go", "rust"). Maps to rg --type.',
                ],
                'multiline' => [
                    'type' => 'boolean',
                    'description' => 'Enable multiline mode for cross-line patterns (rg -U). Default: false.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Skip first N entries before applying head_limit. Default: 0.',
                ],
            ],
            'required' => ['pattern'],
        ], [
            'pattern' => 'required|string',
            'path' => 'nullable|string',
            'glob' => 'nullable|string',
            'output_mode' => 'nullable|string|in:content,files_with_matches,count',
            '-A' => 'nullable|integer|min:0',
            '-B' => 'nullable|integer|min:0',
            '-C' => 'nullable|integer|min:0',
            '-i' => 'nullable|boolean',
            'head_limit' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'multiline' => 'nullable|boolean',
            'offset' => 'nullable|integer|min:0',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $pattern = $input['pattern'];
        $path = $input['path'] ?? $context->workingDirectory;
        $outputMode = $input['output_mode'] ?? 'files_with_matches';
        $glob = $input['glob'] ?? null;
        $type = $input['type'] ?? null;
        $caseInsensitive = $input['-i'] ?? false;
        $multiline = $input['multiline'] ?? false;
        $contextLines = $input['-C'] ?? null;
        $afterLines = $contextLines ?? $input['-A'] ?? 0;
        $beforeLines = $contextLines ?? $input['-B'] ?? 0;
        $headLimit = $input['head_limit'] ?? 250;
        $offset = $input['offset'] ?? 0;

        // Try ripgrep first, fallback to PHP implementation
        if ($this->hasRipgrep()) {
            return $this->grepWithRipgrep(
                $pattern, $path, $outputMode, $glob, $type,
                $caseInsensitive, $multiline, $afterLines, $beforeLines, $headLimit, $offset
            );
        }

        return $this->grepWithPhp(
            $pattern, $path, $outputMode, $glob,
            $caseInsensitive, $afterLines, $beforeLines, $headLimit
        );
    }

    private function hasRipgrep(): bool
    {
        $result = exec('which rg 2>/dev/null');
        return !empty($result);
    }

    private function grepWithRipgrep(
        string $pattern, string $path, string $outputMode, ?string $glob, ?string $type,
        bool $caseInsensitive, bool $multiline, int $afterLines, int $beforeLines, int $headLimit, int $offset = 0
    ): ToolResult {
        $cmd = ['rg', '--no-heading'];

        if ($caseInsensitive) {
            $cmd[] = '-i';
        }

        if ($multiline) {
            $cmd[] = '-U';
            $cmd[] = '--multiline-dotall';
        }

        if ($outputMode === 'count') {
            $cmd[] = '--count';
        } elseif ($outputMode === 'files_with_matches') {
            $cmd[] = '-l';
        } else {
            $cmd[] = '--line-number';
            if ($afterLines > 0) $cmd[] = '-A ' . $afterLines;
            if ($beforeLines > 0) $cmd[] = '-B ' . $beforeLines;
        }

        $cmd[] = '--max-count=' . ($headLimit + $offset);

        if ($glob) {
            $cmd[] = '--glob=' . escapeshellarg($glob);
        }

        if ($type) {
            $cmd[] = '--type=' . escapeshellarg($type);
        }

        $cmd[] = '--';
        $cmd[] = escapeshellarg($pattern);
        $cmd[] = escapeshellarg($path);

        $command = implode(' ', $cmd);
        exec($command . ' 2>&1', $output, $exitCode);

        // rg returns 1 when no matches found
        if ($exitCode === 1) {
            return ToolResult::success("No matches found for pattern: {$pattern}");
        }

        if ($exitCode > 1) {
            return ToolResult::error("ripgrep error: " . implode("\n", $output));
        }

        // Apply offset
        if ($offset > 0) {
            $output = array_slice($output, $offset);
        }

        $result = implode("\n", $output);
        if (empty($result)) {
            return ToolResult::success("No matches found for pattern: {$pattern}");
        }

        return ToolResult::success($result);
    }

    private function grepWithPhp(
        string $pattern, string $path, string $outputMode, ?string $glob,
        bool $caseInsensitive, int $afterLines, int $beforeLines, int $headLimit
    ): ToolResult {
        $flags = $caseInsensitive ? 'i' : '';
        // Escape the `/` delimiter so patterns like `app/Services` don't break the regex.
        $safePattern = str_replace('/', '\/', $pattern);
        $regex = '/' . $safePattern . '/' . $flags;

        // Validate regex without emitting a PHP warning (PHPUnit 11 captures them)
        set_error_handler(fn() => true);
        $testResult = preg_match($regex, '');
        restore_error_handler();
        if ($testResult === false) {
            return ToolResult::error("Invalid regex pattern: {$pattern}");
        }

        if (is_file($path)) {
            $files = [$path];
        } else {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            $files = [];
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    if ($glob) {
                        $relPath = ltrim(str_replace($path, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                        if (!fnmatch($glob, $relPath) && !fnmatch($glob, $file->getFilename())) {
                            continue;
                        }
                    }
                    $files[] = $file->getPathname();
                }
            }
        }

        $results = [];
        $fileMatches = [];
        $count = 0;

        foreach ($files as $file) {
            $lines = @file($file);
            if ($lines === false) continue;

            $totalLines = count($lines);
            $printedLines = []; // track already-emitted line indices to avoid duplicates

            foreach ($lines as $num => $line) {
                if (preg_match($regex, $line)) {
                    $count++;
                    $relativePath = str_replace($path . '/', '', $file);

                    if ($outputMode === 'files_with_matches') {
                        $fileMatches[$relativePath] = true;
                    } elseif ($outputMode === 'count') {
                        $fileMatches[$relativePath] = ($fileMatches[$relativePath] ?? 0) + 1;
                    } else {
                        $lineNum = $num + 1;
                        // Emit before-context lines
                        if ($beforeLines > 0) {
                            $start = max(0, $num - $beforeLines);
                            for ($ci = $start; $ci < $num; $ci++) {
                                if (!isset($printedLines[$ci])) {
                                    $results[] = "{$relativePath}:" . ($ci + 1) . '-' . rtrim($lines[$ci]);
                                    $printedLines[$ci] = true;
                                }
                            }
                        }
                        // Emit the matching line
                        if (!isset($printedLines[$num])) {
                            $results[] = "{$relativePath}:{$lineNum}:" . rtrim($line);
                            $printedLines[$num] = true;
                        }
                        // Emit after-context lines
                        if ($afterLines > 0) {
                            $end = min($totalLines - 1, $num + $afterLines);
                            for ($ci = $num + 1; $ci <= $end; $ci++) {
                                if (!isset($printedLines[$ci])) {
                                    $results[] = "{$relativePath}:" . ($ci + 1) . '-' . rtrim($lines[$ci]);
                                    $printedLines[$ci] = true;
                                }
                            }
                        }
                    }

                    if ($count >= $headLimit) break 2;
                }
            }
        }

        if ($outputMode === 'files_with_matches') {
            $output = empty($fileMatches) ? "No matches found" : implode("\n", array_keys($fileMatches));
        } elseif ($outputMode === 'count') {
            $output = empty($fileMatches) ? "No matches found" : implode("\n", array_map(
                fn($f, $c) => "{$f}:{$c}", array_keys($fileMatches), array_values($fileMatches)
            ));
        } else {
            $output = empty($results) ? "No matches found" : implode("\n", $results);
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function maxResultSizeChars(): int
    {
        return 20000;
    }
}
