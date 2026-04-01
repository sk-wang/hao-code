<?php

namespace App\Tools\FileEdit;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class FileEditTool extends BaseTool
{
    public function name(): string
    {
        return 'Edit';
    }

    public function description(): string
    {
        return <<<DESC
Performs exact string replacements in files.

Usage:
- You must use the `Read` tool at least once in the conversation before editing.
- The edit will FAIL if `old_string` is not unique in the file.
- Use `replace_all` for replacing and renaming strings across the file.
- Only use emojis if the user explicitly requests it.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to modify',
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => 'The text to replace',
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => 'The text to replace it with (must be different from old_string)',
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences of old_string (default false)',
                ],
            ],
            'required' => ['file_path', 'old_string', 'new_string'],
        ], [
            'file_path' => 'required|string',
            'old_string' => 'required|string',
            'new_string' => 'required|string',
            'replace_all' => 'nullable|boolean',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $filePath = $input['file_path'];
        $oldString = $input['old_string'];
        $newString = $input['new_string'];
        $replaceAll = $input['replace_all'] ?? false;

        if (!file_exists($filePath)) {
            return ToolResult::error("File does not exist: {$filePath}");
        }

        if (!is_writable($filePath)) {
            return ToolResult::error("File is not writable: {$filePath}");
        }

        // Record file history before editing
        try {
            app(\App\Services\FileHistory\FileHistoryManager::class)
                ->recordBefore($filePath);
        } catch (\Throwable) {}

        $content = file_get_contents($filePath);

        if ($oldString === $newString) {
            return ToolResult::error("old_string and new_string are identical. No changes needed.");
        }

        if (!str_contains($content, $oldString)) {
            return ToolResult::error("old_string not found in file: {$filePath}");
        }

        if (!$replaceAll) {
            // Check uniqueness
            $count = substr_count($content, $oldString);
            if ($count > 1) {
                return ToolResult::error(
                    "old_string is not unique in the file (found {$count} occurrences). " .
                    "Either provide a larger string with more surrounding context to make it unique, " .
                    "or use `replace_all: true` to change every instance."
                );
            }
        }

        if ($replaceAll) {
            $newContent = str_replace($oldString, $newString, $content);
        } else {
            $newContent = preg_replace('/' . preg_quote($oldString, '/') . '/', $newString, $content, 1);
        }

        $result = file_put_contents($filePath, $newContent);

        if ($result === false) {
            return ToolResult::error("Failed to write file: {$filePath}");
        }

        return ToolResult::success("Successfully edited {$filePath}");
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['file_path'])) {
            $input['file_path'] = $this->resolvePath($input['file_path'], $context->workingDirectory);
        }
        return $input;
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $filePath = $input['file_path'] ?? '';

        // Block editing sensitive files
        $sensitivePatterns = [
            '/\.env$/',
            '/\.env\./',
            '/credentials\.json$/i',
            '/\.ssh\//',
            '/\.gnupg\//',
            '/id_rsa$/',
            '/id_ed25519$/',
            '/\.pem$/',
            '/\.key$/',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $filePath)) {
                return "Editing sensitive file detected: {$filePath}. Secret files should not be edited with the Edit tool.";
            }
        }

        // Warn about very large file edits
        if (file_exists($filePath)) {
            $size = filesize($filePath);
            if ($size > 1_000_000) { // 1MB
                return "File is very large (" . round($size / 1024 / 1024, 1) . " MB). Consider using more targeted edits.";
            }
        }

        return null;
    }
}
