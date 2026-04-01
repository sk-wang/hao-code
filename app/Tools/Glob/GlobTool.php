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
        $pattern = $input['pattern'];
        $path = $input['path'] ?? $context->workingDirectory;

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

        $output = "Found " . count($matches) . " file(s) matching '{$pattern}':\n\n";
        foreach ($matches as $match) {
            $relative = str_replace($path . '/', '', $match);
            $output .= "  {$relative}\n";
        }

        return ToolResult::success($output);
    }

    private function globRecursive(string $dir, string $pattern, array &$matches): void
    {
        // Handle ** patterns by using recursive iterator
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $regexPattern = $this->globToRegex($pattern);

        foreach ($iterator as $file) {
            $relativePath = str_replace($dir . '/', '', $file->getPathname());
            if (preg_match($regexPattern, $relativePath)) {
                $matches[] = $file->getPathname();
            }
        }
    }

    private function globToRegex(string $pattern): string
    {
        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\*\*', '.*', $regex);
        $regex = str_replace('\*', '[^/]*', $regex);
        $regex = str_replace('\?', '.', $regex);
        return '/^' . $regex . '$/';
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
