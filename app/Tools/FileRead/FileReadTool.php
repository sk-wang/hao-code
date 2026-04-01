<?php

namespace App\Tools\FileRead;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class FileReadTool extends BaseTool
{
    public function name(): string
    {
        return 'Read';
    }

    public function description(): string
    {
        return <<<DESC
Reads a file from the local filesystem. You can access any file directly by using this tool.

Usage:
- The file_path parameter must be an absolute path, not a relative path.
- By default, it reads up to 2000 lines starting from the beginning of the file.
- You can optionally specify a line offset and limit.
- Results are returned with line numbers starting at 1.
- This tool can read images (PNG, JPG), PDFs, and Jupyter notebooks.
- If the file does not exist, an error will be returned.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to read',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'The line number to start reading from (1-based)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'The number of lines to read',
                ],
            ],
            'required' => ['file_path'],
        ], [
            'file_path' => 'required|string',
            'offset' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $filePath = $input['file_path'];
        $offset = $input['offset'] ?? 1;
        $limit = $input['limit'] ?? 2000;

        if (!file_exists($filePath)) {
            return ToolResult::error("File does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            return ToolResult::error("File is not readable: {$filePath}");
        }

        if (is_dir($filePath)) {
            return ToolResult::error("Path is a directory, not a file: {$filePath}");
        }

        // Handle binary files (images, PDFs)
        $mimeType = mime_content_type($filePath);
        if ($mimeType && str_starts_with($mimeType, 'image/')) {
            $base64 = base64_encode(file_get_contents($filePath));
            $size = filesize($filePath);
            return ToolResult::success("[Image: {$mimeType}, {$size} bytes, base64 encoded]");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return ToolResult::error("Failed to read file: {$filePath}");
        }

        $totalLines = count($lines);
        $selectedLines = array_slice($lines, $offset - 1, $limit);

        $output = '';
        foreach ($selectedLines as $i => $line) {
            $lineNum = $offset + $i;
            $output .= sprintf("%6d\t%s\n", $lineNum, $line);
        }

        $header = "File: {$filePath} ({$totalLines} lines total)\n";
        if ($offset > 1 || $limit < $totalLines) {
            $header .= "Lines {$offset}-" . ($offset + count($selectedLines) - 1) . "\n";
        }
        $header .= str_repeat('-', 60) . "\n";

        return ToolResult::success($header . $output);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function maxResultSizeChars(): int
    {
        return PHP_INT_MAX; // Never truncate - avoid circular Read->file->Read loop
    }
}
