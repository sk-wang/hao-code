<?php

namespace App\Tools\FileWrite;

use App\Services\Security\SecretScanner;
use App\Tools\BaseTool;
use App\Tools\FileEdit\DiffGenerator;
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
- Do not send huge multiline source files in one call. For long or quote-heavy files, write a tiny scaffold first and then use Edit in small chunks.
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

        // Enforce read-before-write for existing files (prevents blind overwrites)
        if (file_exists($filePath) && !$context->wasFileRead($filePath)) {
            return ToolResult::error(
                "Read tool first: {$filePath} already exists and must be read before overwriting. " .
                "Next step: call Read on this exact path, then retry Write."
            );
        }

        // Record file history before overwriting
        $originalContent = null;
        if (file_exists($filePath)) {
            $originalContent = file_get_contents($filePath);
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

        $existed = file_exists($filePath);

        $result = file_put_contents($filePath, $content);

        if ($result === false) {
            return ToolResult::error("Failed to write file: {$filePath}");
        }

        // A successful write gives the agent authoritative knowledge of the file's
        // current contents, so subsequent refinements in the same session should not
        // be blocked by read-before-write.
        $context->recordFileRead($filePath, $content, 1, null, false);

        $action = $existed ? 'updated' : 'created';
        $lines = substr_count($content, "\n") + ($content !== '' ? 1 : 0);
        $bytes = strlen($content);

        $output = "Successfully {$action} {$filePath} ({$lines} lines, {$bytes} bytes)";

        // Show change summary for updates
        if ($existed && $originalContent !== null) {
            $changeSummary = DiffGenerator::changeSummary($originalContent, $content);
            $output .= " [{$changeSummary}]";

            // Try git diff for update
            $gitDiff = DiffGenerator::gitDiff($filePath);
            if ($gitDiff !== '') {
                // Truncate large diffs
                if (mb_strlen($gitDiff) > 3000) {
                    $gitDiff = mb_substr($gitDiff, 0, 3000) . "\n... [diff truncated]";
                }
                $output .= "\n\nGit diff:\n" . $gitDiff;
            }
        }

        // Scan for secrets and warn
        $scanner = new SecretScanner();
        $secrets = $scanner->scan($content);
        if (!empty($secrets)) {
            $types = array_unique(array_map(fn($s) => $s['type'], $secrets));
            $output .= "\n\nWARNING: Potential secrets detected: " . implode(', ', $types)
                . ". Consider using environment variables instead of hardcoding credentials.";
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function maxResultSizeChars(): int
    {
        return 100000;
    }

    public function getActivityDescription(array $input): ?string
    {
        $file = basename($input['file_path'] ?? 'file');
        $exists = file_exists($input['file_path'] ?? '');

        return ($exists ? 'Updating ' : 'Creating ') . $file;
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

        $content = (string) ($input['content'] ?? '');
        if ($content !== '') {
            $newlineCount = substr_count($content, "\n");
            if ($newlineCount > 40 || strlen($content) > 2500) {
                return 'content is too large for a single Write call. Create a tiny scaffold first, then use Edit in small chunks (about 8 lines or 400 characters each).';
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
}
