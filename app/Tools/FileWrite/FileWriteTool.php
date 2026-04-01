<?php

namespace App\Tools\FileWrite;

use App\Services\Security\SecretScanner;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class FileWriteTool extends BaseTool
{
    public function name(): string
    {
        return 'Write';
    }

    public function description(): string
    {
        return <<<DESC
Writes a file to the local filesystem.

Usage:
- This tool will overwrite the existing file if there is one at the provided path.
- If this is an existing file, you MUST use the Read tool first to read the file's contents.
- Only use emojis if the user explicitly requests it.
- NEVER create documentation files (*.md) or README files unless explicitly requested.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to write (must be absolute, not relative)',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to write to the file',
                ],
            ],
            'required' => ['file_path', 'content'],
        ], [
            'file_path' => 'required|string',
            'content' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $filePath = $input['file_path'];
        $content = $input['content'];

        // Record file history before overwriting
        if (file_exists($filePath)) {
            try {
                app(\App\Services\FileHistory\FileHistoryManager::class)
                    ->recordBefore($filePath);
            } catch (\Throwable) {}
        }

        // Ensure parent directory exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        // Check if file already exists
        $existed = file_exists($filePath);

        $result = file_put_contents($filePath, $content);

        if ($result === false) {
            return ToolResult::error("Failed to write file: {$filePath}");
        }

        $action = $existed ? 'overwritten' : 'created';
        $lines = count(explode("\n", $content));
        $bytes = strlen($content);

        // Scan for secrets and warn
        $scanner = new SecretScanner();
        $secrets = $scanner->scan($content);
        $warning = '';
        if (!empty($secrets)) {
            $types = array_map(fn($s) => $s['type'], $secrets);
            $uniqueTypes = array_unique($types);
            $warning = "\n\n⚠ WARNING: Potential secrets detected: " . implode(', ', $uniqueTypes)
                . ". Consider using environment variables instead of hardcoding credentials.";
        }

        return ToolResult::success("Successfully {$action} {$filePath} ({$lines} lines, {$bytes} bytes){$warning}");
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
}
