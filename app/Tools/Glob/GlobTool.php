<?php

namespace App\Tools\Glob;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class GlobTool extends BaseTool
{
    public function name(): string
    {
        return 'Glob';
    }

    public function description(): string
    {
        return <<<DESC
Fast file pattern matching tool that works with any codebase size.

- Supports glob patterns like "**/*.js" or "src/**/*.ts"
- Returns matching file paths sorted by modification time
- Use this tool when you need to find files by name patterns
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The glob pattern to match files against (e.g., "**/*.js")',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory to search in. Defaults to current working directory.',
                ],
            ],
            'required' => ['pattern'],
        ], [
            'pattern' => 'required|string',
            'path' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $pattern = $this->normalizePattern($input['pattern']);
        $path = $input['path'] ?? $context->workingDirectory;

        if (!is_dir($path)) {
            return ToolResult::error("Directory does not exist: {$path}");
        }

        // Use recursive glob
        $matches = [];
        $this->globRecursive($path, $pattern, $matches);

        if (empty($matches)) {
            return ToolResult::success("No files matched pattern: {$pattern}");
        }

        // Sort by modification time (most recent first)
        usort($matches, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $totalCount = count($matches);
        $maxResults = 100;
        $truncated = $totalCount > $maxResults;

        if ($truncated) {
            $matches = array_slice($matches, 0, $maxResults);
        }

        $output = "Found {$totalCount} file(s) matching '{$pattern}'";
        if ($truncated) {
            $output .= " (showing first {$maxResults})";
        }
        $output .= ":\n\n";

        foreach ($matches as $match) {
            $relative = str_replace($path . '/', '', $match);
            $output .= "  {$relative}\n";
        }

        if ($truncated) {
            $output .= "\n[" . ($totalCount - $maxResults) . " more files not shown. Narrow your pattern to see more.]";
        }

        return ToolResult::success($output);
    }

    private function globRecursive(string $dir, string $pattern, array &$matches): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Handle ** patterns by using recursive iterator
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $regexPatterns = array_map(
            fn (string $expandedPattern): string => $this->globToRegex($expandedPattern),
            $this->expandBracePatterns($pattern),
        );

        foreach ($iterator as $file) {
            $relativePath = str_replace($dir . '/', '', $file->getPathname());
            foreach ($regexPatterns as $regexPattern) {
                if (preg_match($regexPattern, $relativePath)) {
                    $matches[] = $file->getPathname();
                    break;
                }
            }
        }
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if (str_starts_with($pattern, './')) {
            return substr($pattern, 2);
        }

        return $pattern;
    }

    private function globToRegex(string $pattern): string
    {
        // Use '#' as delimiter so '/' can appear unescaped inside character classes
        $regex = preg_quote($pattern, '#');

        $regex = str_replace('\*\*/', '__DOUBLE_STAR_SLASH__', $regex);
        $regex = str_replace('\*\*', '__DOUBLE_STAR__', $regex);
        $regex = str_replace('\*', '[^/]*', $regex);
        $regex = str_replace('\?', '[^/]', $regex);
        $regex = str_replace('__DOUBLE_STAR_SLASH__', '(?:.*/)?', $regex);
        $regex = str_replace('__DOUBLE_STAR__', '.*', $regex);

        return '#^' . $regex . '$#';
    }

    /**
     * @return array<int, string>
     */
    private function expandBracePatterns(string $pattern): array
    {
        if (! preg_match('/\{([^{}]+)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
            return [$pattern];
        }

        $brace = $matches[0][0];
        $braceOffset = $matches[0][1];
        $options = explode(',', $matches[1][0]);
        $prefix = substr($pattern, 0, $braceOffset);
        $suffix = substr($pattern, $braceOffset + strlen($brace));

        $expanded = [];
        foreach ($options as $option) {
            foreach ($this->expandBracePatterns($prefix . $option . $suffix) as $variant) {
                $expanded[] = $variant;
            }
        }

        return array_values(array_unique($expanded));
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function getActivityDescription(array $input): ?string
    {
        return 'Searching for ' . ($input['pattern'] ?? 'files');
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return ['isSearch' => true, 'isRead' => false, 'isList' => true];
    }
}
