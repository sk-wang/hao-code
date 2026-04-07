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
- The file_path parameter may be absolute or relative. Relative paths are resolved against the current working directory.
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
                    'description' => 'The file path to read. Relative paths are resolved against the current working directory.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'The line number to start reading from (1-based)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'The number of lines to read',
                ],
                'pages' => [
                    'type' => 'string',
                    'description' => 'Page range for PDF files (e.g., "1-5", "3", "10-20"). Max 20 pages per request.',
                ],
            ],
            'required' => ['file_path'],
        ], [
            'file_path' => 'required|string',
            'offset' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1',
            'pages' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $filePath = $input['file_path'];
        $offset = $input['offset'] ?? 1;
        $limit = $input['limit'] ?? 2000;

        if (file_exists($filePath)) {
            // Track read — content cached below after successful read
            $context->recordFileRead($filePath);
        }

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
            $imageData = file_get_contents($filePath);
            $size = strlen($imageData);

            // Validate image size (max 20MB for API)
            if ($size > 20 * 1024 * 1024) {
                return ToolResult::error("Image too large: " . round($size / 1024 / 1024, 1) . " MB (max 20 MB)");
            }

            $base64 = base64_encode($imageData);
            return ToolResult::success("[Image: {$mimeType}, " . round($size / 1024, 1) . " KB, base64 encoded]\n[data:image/{$this->getImageFormat($mimeType)};base64,{$base64}]");
        }

        // Handle PDF files
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return $this->readPdf($filePath, $input['pages'] ?? null);
        }

        // Handle Jupyter notebooks
        if ($ext === 'ipynb') {
            return $this->readNotebook($filePath);
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return ToolResult::error("Failed to read file: {$filePath}");
        }

        $totalLines = count($lines);

        if ($offset > $totalLines && $totalLines > 0) {
            return ToolResult::error(
                "Offset {$offset} exceeds file length ({$totalLines} lines). " .
                "Valid range: 1-{$totalLines}."
            );
        }

        $selectedLines = array_slice($lines, $offset - 1, $limit);

        $output = '';
        foreach ($selectedLines as $i => $line) {
            $lineNum = $offset + $i;
            $output .= sprintf("%6d\t%s\n", $lineNum, $line);
        }

        // Cache file content in FileStateCache for Edit/Write read-before-write
        $isPartial = ($offset > 1 || $limit < $totalLines);
        $rawContent = implode("\n", $lines);
        $context->recordFileRead($filePath, $rawContent, $offset, $limit, $isPartial);

        $header = "File: {$filePath} ({$totalLines} lines total)\n";
        if ($isPartial) {
            $endLine = $offset + count($selectedLines) - 1;
            $header .= "Lines {$offset}-{$endLine}\n";
        }
        $header .= str_repeat('-', 60) . "\n";

        return ToolResult::success($header . $output);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $filePath = trim((string) ($input['file_path'] ?? ''));
        if ($filePath === '') {
            return 'file_path must not be empty.';
        }

        if ($this->isBareLineReference($filePath)) {
            return 'file_path must include an actual path, not only a line reference like ":12".';
        }

        if (isset($input['pages']) && trim((string) $input['pages']) !== '') {
            $pages = trim((string) $input['pages']);
            if (preg_match('/^\d+(\s*-\s*\d+)?$/', $pages) !== 1) {
                return 'pages must be a page number or range like "3" or "1-5".';
            }
        }

        return null;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['file_path'])) {
            $normalizedPath = $this->normalizeFileReferencePath($input['file_path'], $context->workingDirectory);
            $input['file_path'] = $this->resolvePath($normalizedPath, $context->workingDirectory);
        }
        return $input;
    }

    public function maxResultSizeChars(): int
    {
        return PHP_INT_MAX; // Never truncate/persist - avoid circular Read->file->Read loop
    }

    public function getActivityDescription(array $input): ?string
    {
        return 'Reading ' . basename($input['file_path'] ?? 'file');
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return ['isSearch' => false, 'isRead' => true, 'isList' => false];
    }

    private function getImageFormat(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    private function readPdf(string $filePath, ?string $pageRange = null): ToolResult
    {
        $size = filesize($filePath);
        if ($size > 32 * 1024 * 1024) {
            return ToolResult::error("PDF too large: " . round($size / 1024 / 1024, 1) . " MB (max 32 MB)");
        }

        // Parse page range
        $firstPage = null;
        $lastPage = null;
        if ($pageRange !== null) {
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', trim($pageRange), $m)) {
                $firstPage = (int) $m[1];
                $lastPage = (int) $m[2];
            } elseif (preg_match('/^(\d+)$/', trim($pageRange), $m)) {
                $firstPage = (int) $m[1];
                $lastPage = (int) $m[1];
            }

            if ($firstPage !== null && $lastPage !== null && ($lastPage - $firstPage + 1) > 20) {
                return ToolResult::error("Maximum 20 pages per request. Requested: " . ($lastPage - $firstPage + 1));
            }
        }

        // Try using pdftotext for text extraction
        $pdftotextOutput = shell_exec("which pdftotext 2>/dev/null");
        if (!empty(trim($pdftotextOutput ?? ''))) {
            $cmd = "pdftotext -layout";
            if ($firstPage !== null) {
                $cmd .= " -f {$firstPage}";
            }
            if ($lastPage !== null) {
                $cmd .= " -l {$lastPage}";
            }
            $cmd .= " " . escapeshellarg($filePath) . " - 2>/dev/null";

            $text = shell_exec($cmd);
            if (!empty($text)) {
                $pageCount = shell_exec("pdfinfo " . escapeshellarg($filePath) . " 2>/dev/null | grep Pages | awk '{print $2}'");
                $pages = trim($pageCount ?? 'unknown');
                $rangeInfo = $pageRange !== null ? ", pages {$pageRange}" : '';
                return ToolResult::success("[PDF: {$filePath}, {$pages} total pages{$rangeInfo}, text extracted]\n\n" . $text);
            }
        }

        // Fallback: read as base64 for the API to process
        $data = file_get_contents($filePath);
        $base64 = base64_encode($data);
        return ToolResult::success("[PDF: {$filePath}, " . round($size / 1024, 1) . " KB, base64 encoded]\n[data:application/pdf;base64,{$base64}]");
    }

    private function readNotebook(string $filePath): ToolResult
    {
        $content = file_get_contents($filePath);
        $notebook = json_decode($content, true);

        if (!is_array($notebook) || !isset($notebook['cells'])) {
            return ToolResult::error("Invalid Jupyter notebook format: {$filePath}");
        }

        $output = "[Jupyter Notebook: {$filePath}]\n\n";
        $cellCount = count($notebook['cells']);

        foreach ($notebook['cells'] as $i => $cell) {
            $cellNum = $i + 1;
            $cellType = $cell['cell_type'] ?? 'unknown';
            $source = is_array($cell['source'] ?? null) ? implode('', $cell['source']) : ($cell['source'] ?? '');

            $output .= "--- Cell {$cellNum}/{$cellCount} [{$cellType}] ---\n";

            if ($cellType === 'code') {
                $output .= "```\n{$source}\n```\n";

                // Show outputs if present
                $outputs = $cell['outputs'] ?? [];
                foreach ($outputs as $cellOutput) {
                    $outputType = $cellOutput['output_type'] ?? '';
                    if ($outputType === 'stream') {
                        $text = is_array($cellOutput['text'] ?? null) ? implode('', $cellOutput['text']) : ($cellOutput['text'] ?? '');
                        $output .= "Output:\n{$text}\n";
                    } elseif ($outputType === 'execute_result' || $outputType === 'display_data') {
                        $data = $cellOutput['data'] ?? [];
                        if (isset($data['text/plain'])) {
                            $text = is_array($data['text/plain']) ? implode('', $data['text/plain']) : $data['text/plain'];
                            $output .= "Output:\n{$text}\n";
                        }
                    } elseif ($outputType === 'error') {
                        $ename = $cellOutput['ename'] ?? 'Error';
                        $evalue = $cellOutput['evalue'] ?? '';
                        $output .= "Error: {$ename}: {$evalue}\n";
                    }
                }
            } else {
                $output .= "{$source}\n";
            }

            $output .= "\n";
        }

        return ToolResult::success($output);
    }
}
