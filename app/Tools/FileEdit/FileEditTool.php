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
                    'default' => false,
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

        // Enforce read-before-write
        if (!$context->wasFileRead($filePath)) {
            return ToolResult::error(
                "Read tool first: {$filePath} must be read before editing. " .
                "Next step: call Read on this exact path, then retry Edit."
            );
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

        // Detect line ending style for preservation
        $lineEnding = $this->detectLineEnding($content);

        // Try finding old_string with curly quote normalization fallback
        $actualOldString = QuoteNormalizer::findActualString($content, $oldString);
        if ($actualOldString === null) {
            return ToolResult::error("old_string not found in file: {$filePath}");
        }

        // Preserve file's curly quote style in replacement
        $actualNewString = QuoteNormalizer::preserveQuoteStyle($oldString, $actualOldString, $newString);

        // Count occurrences (using the actual string from the file)
        $count = substr_count($content, $actualOldString);
        if ($count === 0) {
            // Fallback: count via normalization
            $count = QuoteNormalizer::countOccurrences($content, $oldString);
        }

        if (!$replaceAll && $count > 1) {
            return ToolResult::error(
                "old_string is not unique in the file (found {$count} occurrences). " .
                "Either provide a larger string with more surrounding context to make it unique, " .
                "or use `replace_all: true` to change every instance."
            );
        }

        // Apply the edit
        $originalContent = $content;
        if ($replaceAll) {
            $newContent = str_replace($actualOldString, $actualNewString, $content);
        } else {
            $pos = strpos($content, $actualOldString);
            if ($pos !== false) {
                $newContent = substr($content, 0, $pos) . $actualNewString . substr($content, $pos + strlen($actualOldString));
            } else {
                $newContent = $content;
            }
        }

        // Preserve line ending style
        if ($lineEnding !== "\n") {
            $newContent = $this->normalizeLineEndings($newContent, $lineEnding);
        }

        $result = file_put_contents($filePath, $newContent);
        if ($result === false) {
            return ToolResult::error("Failed to write file: {$filePath}");
        }

        // Generate diff output
        $changeSummary = DiffGenerator::changeSummary($originalContent, $newContent);
        $output = "Successfully edited {$filePath} ({$changeSummary})";

        // Append snippet diff for visibility
        $snippet = $this->generateSnippetDiff($oldString, $newString, $replaceAll, $count);
        if ($snippet !== '') {
            $output .= "\n" . $snippet;
        }

        // Try git diff
        $gitDiff = DiffGenerator::gitDiff($filePath);
        if ($gitDiff !== '') {
            $output .= "\n\nGit diff:\n" . $gitDiff;
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(array $input): bool
    {
        return false;
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
        return 100000;
    }

    public function getActivityDescription(array $input): ?string
    {
        return 'Editing ' . basename($input['file_path'] ?? 'file');
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

        $filePath = $this->normalizeFileReferencePath($filePath, $context->workingDirectory);
        $filePath = $this->resolvePath($filePath, $context->workingDirectory);

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

    /**
     * Detect the dominant line ending style in content.
     */
    private function detectLineEnding(string $content): string
    {
        $crlf = substr_count($content, "\r\n");
        $lf = substr_count($content, "\n") - $crlf;
        $cr = substr_count($content, "\r") - $crlf;

        if ($crlf > $lf && $crlf > $cr) {
            return "\r\n";
        }
        if ($cr > $lf) {
            return "\r";
        }

        return "\n";
    }

    /**
     * Normalize line endings in content to the target style.
     */
    private function normalizeLineEndings(string $content, string $target): string
    {
        // First normalize everything to \n, then convert to target
        $normalized = str_replace("\r\n", "\n", $content);
        $normalized = str_replace("\r", "\n", $normalized);

        if ($target === "\n") {
            return $normalized;
        }

        return str_replace("\n", $target, $normalized);
    }

    /**
     * Generate a compact snippet showing the replacement.
     */
    private function generateSnippetDiff(string $oldString, string $newString, bool $replaceAll, int $count): string
    {
        $oldPreview = $this->truncate($oldString, 200);
        $newPreview = $this->truncate($newString, 200);
        $suffix = $replaceAll && $count > 1 ? " ({$count} occurrences)" : '';

        return "Replaced{$suffix}:\n- {$oldPreview}\n+ {$newPreview}";
    }

    private function truncate(string $str, int $maxLen): string
    {
        $singleLine = str_replace(["\n", "\r"], ['\\n', '\\r'], $str);
        if (mb_strlen($singleLine) > $maxLen) {
            return mb_substr($singleLine, 0, $maxLen) . '...';
        }

        return $singleLine;
    }
}
