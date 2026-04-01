<?php

namespace App\Services\Agent;

use App\Services\Hooks\HookExecutor;
use App\Services\Permissions\PermissionChecker;
use App\Tools\ToolRegistry;
use App\Tools\ToolUseContext;
use App\Tools\ToolResult;

class ToolOrchestrator
{
    private $permissionPromptHandler = null;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly PermissionChecker $permissionChecker,
        private readonly HookExecutor $hookExecutor,
    ) {}

    public function setPermissionPromptHandler(callable $handler): void
    {
        $this->permissionPromptHandler = $handler;
    }

    /**
     * Execute a single tool block (public entry point for streaming executor).
     */
    public function executeToolBlock(
        array $block,
        ToolUseContext $context,
        ?callable $onStart = null,
        ?callable $onComplete = null,
    ): array {
        return $this->executeSingleTool($block, $context, $onStart, $onComplete);
    }

    /**
     * Execute a set of tool_use blocks from the API response.
     * Parallelizes execution of concurrency-safe (read-only) tools.
     *
     * @param array $toolUseBlocks Array of {id, name, input} from API
     * @return array Array of API-format tool_result blocks
     */
    public function executeTools(
        array $toolUseBlocks,
        ToolUseContext $context,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
    ): array {
        if (count($toolUseBlocks) <= 1) {
            // Single tool: no need for parallelism
            $results = [];
            foreach ($toolUseBlocks as $block) {
                $results[] = $this->executeSingleTool($block, $context, $onToolStart, $onToolComplete);
            }
            return $results;
        }

        // Partition into safe (parallelizable) and unsafe (sequential)
        $safeBlocks = [];
        $unsafeBlocks = [];

        foreach ($toolUseBlocks as $block) {
            $tool = $this->toolRegistry->getTool($block['name']);
            if ($tool && $tool->isConcurrencySafe($block['input'] ?? []) && $tool->isReadOnly($block['input'] ?? [])) {
                $safeBlocks[] = $block;
            } else {
                $unsafeBlocks[] = $block;
            }
        }

        $results = [];

        // Execute safe tools in parallel using child processes
        if (!empty($safeBlocks)) {
            $parallelResults = $this->executeInParallel($safeBlocks, $context, $onToolStart, $onToolComplete);
            foreach ($parallelResults as $idx => $result) {
                $results[$idx] = $result;
            }
        }

        // Execute unsafe tools sequentially
        foreach ($unsafeBlocks as $block) {
            $results[] = $this->executeSingleTool($block, $context, $onToolStart, $onToolComplete);
        }

        return $results;
    }

    /**
     * Execute safe tools in parallel using proc_open.
     */
    private function executeInParallel(
        array $blocks,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        // For small counts, just run concurrently with non-blocking approach
        // PHP doesn't have native async, so use fork-based parallelism when available
        if (!function_exists('pcntl_fork')) {
            // Fallback to sequential
            $results = [];
            foreach ($blocks as $block) {
                $results[] = $this->executeSingleTool($block, $context, $onStart, $onComplete);
            }
            return $results;
        }

        // Use temp files for IPC
        $tempFiles = [];
        $pids = [];

        foreach ($blocks as $idx => $block) {
            $tempFile = sys_get_temp_dir() . '/haocode_tool_' . $idx . '_' . getmypid();
            $tempFiles[$idx] = $tempFile;

            $pid = pcntl_fork();
            if ($pid === -1) {
                // Fork failed, execute inline
                $results[$idx] = $this->executeSingleTool($block, $context, $onStart, $onComplete);
                unset($tempFiles[$idx]);
                continue;
            }

            if ($pid === 0) {
                // Child process
                $result = $this->executeSingleTool($block, $context, null, null);
                file_put_contents($tempFile, serialize($result));
                exit(0);
            }

            // Parent
            $pids[$idx] = $pid;
            if ($onStart) {
                $onStart($block['name'], $block['input'] ?? []);
            }
        }

        // Wait for all children
        $results = [];
        foreach ($pids as $idx => $pid) {
            pcntl_waitpid($pid, $status);
            if (isset($tempFiles[$idx]) && file_exists($tempFiles[$idx])) {
                $data = @unserialize(file_get_contents($tempFiles[$idx]));
                $results[$idx] = $data ?: [
                    'tool_use_id' => $blocks[$idx]['id'],
                    'content' => 'Failed to read parallel result',
                    'is_error' => true,
                ];
                @unlink($tempFiles[$idx]);
            }
            if ($onComplete) {
                $toolName = $blocks[$idx]['name'];
                $result = ToolResult::success($results[$idx]['content'] ?? '');
                $onComplete($toolName, $result);
            }
        }

        // Re-index sequentially
        return array_values($results);
    }

    private function executeSingleTool(
        array $block,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $toolUseId = $block['id'];
        $toolName = $block['name'];
        $input = $block['input'] ?? [];

        $tool = $this->toolRegistry->getTool($toolName);

        if ($tool === null) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => "Unknown tool: {$toolName}",
                'is_error' => true,
            ];
        }

        // Stage 1: Schema validation
        try {
            $input = $tool->inputSchema()->validate($input);
        } catch (\InvalidArgumentException $e) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => '<tool_use_error>InputValidationError: ' . $e->getMessage() . '</tool_use_error>',
                'is_error' => true,
            ];
        }

        // Stage 2: Tool-specific semantic validation
        $validationError = $tool->validateInput($input, $context);
        if ($validationError !== null) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => '<tool_use_error>Validation: ' . $validationError . '</tool_use_error>',
                'is_error' => true,
            ];
        }

        // Stage 3: PreToolUse hooks
        $hookResult = $this->hookExecutor->execute('PreToolUse', [
            'tool' => $toolName,
            'input' => $input,
        ]);

        if (!$hookResult->allowed) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => 'Blocked by hook: ' . $hookResult->output,
                'is_error' => true,
            ];
        }

        if ($hookResult->modifiedInput !== null) {
            $input = $hookResult->modifiedInput;
        }

        // Stage 4: Permission check
        $decision = $this->permissionChecker->check($tool, $input, $context);

        if (!$decision->allowed) {
            if ($this->permissionPromptHandler) {
                $userApproved = ($this->permissionPromptHandler)($toolName, $input);
                if (!$userApproved) {
                    return [
                        'tool_use_id' => $toolUseId,
                        'content' => 'Permission denied by user',
                        'is_error' => true,
                    ];
                }
            } else {
                return [
                    'tool_use_id' => $toolUseId,
                    'content' => "Permission denied: " . ($decision->reason ?? 'Not allowed'),
                    'is_error' => true,
                ];
            }
        }

        if ($onStart) {
            $onStart($toolName, $input);
        }

        // Execute the tool
        try {
            $result = $tool->call($input, $context);

            // PostToolUse hooks (success path)
            $postHookResult = $this->hookExecutor->execute('PostToolUse', [
                'tool' => $toolName,
                'input' => $input,
                'output' => $result->output,
                'isError' => $result->isError,
            ]);

            if ($postHookResult->output) {
                $result = new ToolResult(
                    output: $result->output . "\n[Hook] " . $postHookResult->output,
                    isError: $result->isError,
                    metadata: $result->metadata,
                );
            }
        } catch (\Throwable $e) {
            $result = ToolResult::error("Tool execution error: " . $e->getMessage());

            // PostToolUseFailure hooks (error path)
            $failHookResult = $this->hookExecutor->execute('PostToolUseFailure', [
                'tool' => $toolName,
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            if ($failHookResult->output) {
                $result = new ToolResult(
                    output: $result->output . "\n[Hook] " . $failHookResult->output,
                    isError: true,
                    metadata: $result->metadata,
                );
            }
        }

        // Truncate large results
        $maxChars = $tool->maxResultSizeChars();
        if ($maxChars < PHP_INT_MAX && mb_strlen($result->output) > $maxChars) {
            $previewSize = 2000;
            $totalKb = round(mb_strlen($result->output) / 1024);
            $preview = mb_substr($result->output, 0, $previewSize);
            $result = new ToolResult(
                output: "<persisted-output>\nOutput too large ({$totalKb} KB). Showing first 2KB preview:\n\n{$preview}\n...(truncated)\n</persisted-output>",
                isError: $result->isError,
                metadata: $result->metadata,
            );
        }

        if ($onComplete) {
            $onComplete($toolName, $result);
        }

        return $result->toApiFormat($toolUseId);
    }
}
