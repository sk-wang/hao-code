<?php

namespace App\Tools\Bash;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class BashTool extends BaseTool
{
    /** @var array<string, array{pid: int, outFile: string, startTime: float}> */
    private static array $backgroundTasks = [];

    public function name(): string
    {
        return 'Bash';
    }

    public function description(): string
    {
        return <<<DESC
Executes a given bash command and returns its output.

The working directory persists between commands, but shell state does not.
Always quote file paths that contain spaces with double quotes.

IMPORTANT: Avoid using this tool to run `find`, `grep`, `cat`, `head`, `tail`, `sed`, `awk`, or `echo` commands, unless explicitly instructed or after you have verified that a dedicated tool cannot accomplish your task.

Usage notes:
 - If your command will create new directories or files, first use this tool to run `ls` to verify the parent directory exists.
 - Always quote file paths that contain spaces with double quotes.
 - Try to maintain your current working directory throughout the session by using absolute paths.
 - You may specify an optional timeout in milliseconds (up to 600000ms / 10 minutes). Default timeout is 120000ms (2 minutes).
 - Use the `run_in_background` parameter to run the command in the background.
 - Write a clear, concise description of what your command does.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The command to execute',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear, concise description of what this command does (5-10 words)',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Optional timeout in milliseconds (max 600000)',
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Run the command in the background',
                ],
            ],
            'required' => ['command'],
        ], [
            'command' => 'required|string',
            'description' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1000|max:600000',
            'run_in_background' => 'nullable|boolean',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $command = $input['command'];
        $background = $input['run_in_background'] ?? false;

        // Check for dangerous patterns
        $warnings = $this->detectDangerousPatterns($command);

        if ($background) {
            return $this->runInBackground($command, $context->workingDirectory, $warnings);
        }

        $timeout = ($input['timeout'] ?? 120000) / 1000;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $context->workingDirectory;

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $cwd,
            ['TERM' => 'xterm-256color'] + $_ENV,
        );

        if (!is_resource($process)) {
            return ToolResult::error("Failed to execute command: {$command}");
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = '';
        if (!empty($stdout)) {
            $output .= $stdout;
        }
        if (!empty($stderr)) {
            if (!empty($output)) {
                $output .= "\n";
            }
            $output .= $stderr;
        }

        if (empty($output)) {
            $output = '(no output)';
        }

        // Truncate very long output
        if (mb_strlen($output) > 100000) {
            $output = mb_substr($output, 0, 100000) . "\n\n[Output truncated at 100,000 characters]";
        }

        // Prepend warnings
        if (!empty($warnings)) {
            $warningText = "<warnings>\n" . implode("\n", $warnings) . "\n</warnings>\n\n";
            $output = $warningText . $output;
        }

        if ($exitCode !== 0) {
            // Check if this exit code is semantically non-error for the command
            $exitContext = $this->interpretExitCode($command, $exitCode, $output);

            if ($exitContext['isExpected'] ?? false) {
                // Not a real error, just a semantic exit (e.g., grep found no matches)
                return ToolResult::success(
                    $output . "\n" . ($exitContext['note'] ?? ''),
                    ['exitCode' => $exitCode]
                );
            }

            return ToolResult::error(
                "Command exited with code {$exitCode}\n{$output}" . ($exitContext['note'] ? "\n" . $exitContext['note'] : ''),
                ['exitCode' => $exitCode]
            );
        }

        return ToolResult::success($output, ['exitCode' => $exitCode]);
    }

    /**
     * Run a command in the background.
     */
    private function runInBackground(string $command, string $cwd, array $warnings): ToolResult
    {
        $taskId = 'bg_' . bin2hex(random_bytes(4));
        $outFile = sys_get_temp_dir() . '/haocode_' . $taskId . '.out';

        $fullCommand = "cd " . escapeshellarg($cwd) . " && {$command} > " . escapeshellarg($outFile) . " 2>&1 & echo $!";

        $output = shell_exec($fullCommand);
        $pid = (int) trim($output ?? '0');

        if ($pid <= 0) {
            return ToolResult::error("Failed to start background command: {$command}");
        }

        self::$backgroundTasks[$taskId] = [
            'pid' => $pid,
            'outFile' => $outFile,
            'startTime' => microtime(true),
            'command' => $command,
        ];

        $result = "Background task started: {$taskId} (PID: {$pid})\n";
        $result .= "Command: {$command}\n";
        $result .= "Use /tasks to check status.";

        if (!empty($warnings)) {
            $result = "<warnings>\n" . implode("\n", $warnings) . "\n</warnings>\n\n" . $result;
        }

        return ToolResult::success($result, ['taskId' => $taskId, 'pid' => $pid]);
    }

    /**
     * Check if a background task has completed.
     */
    public static function checkTask(string $taskId): ?ToolResult
    {
        $task = self::$backgroundTasks[$taskId] ?? null;
        if ($task === null) {
            return ToolResult::error("Unknown background task: {$taskId}");
        }

        // Check if process is still running
        $running = posix_kill($task['pid'], 0);

        if ($running) {
            $elapsed = round(microtime(true) - $task['startTime'], 1);
            return ToolResult::success("Task {$taskId} still running (PID: {$task['pid']}, {$elapsed}s elapsed)");
        }

        // Process finished - read output
        $output = file_exists($task['outFile']) ? file_get_contents($task['outFile']) : '(no output)';
        @unlink($task['outFile']);
        unset(self::$backgroundTasks[$taskId]);

        return ToolResult::success("Task {$taskId} completed:\n{$output}");
    }

    /**
     * Check all background tasks.
     * @return array<string, ToolResult>
     */
    public static function checkAllTasks(): array
    {
        $results = [];
        foreach (array_keys(self::$backgroundTasks) as $taskId) {
            $results[$taskId] = self::checkTask($taskId);
        }
        return $results;
    }

    /**
     * List all running background tasks.
     */
    public static function listTasks(): array
    {
        return self::$backgroundTasks;
    }

    /**
     * Detect dangerous command patterns and return warnings.
     * @return string[]
     */
    private function detectDangerousPatterns(string $command): array
    {
        $warnings = [];

        // Patterns: [regex, warning message]
        $patterns = [
            '/\brm\s+(-[a-zA-Z]*f[a-zA-Z]*\s+|.*--recursive\b)/i' => 'WARNING: Recursive/force delete detected. Ensure paths are correct.',
            '/\bgit\s+push\s+.*--force/i' => 'WARNING: Force push can overwrite remote history. Consider --force-with-lease.',
            '/\bgit\s+reset\s+--hard/i' => 'WARNING: Hard reset will discard uncommitted changes.',
            '/\bgit\s+checkout\s+\./' => 'WARNING: This will discard all working directory changes.',
            '/\bgit\s+clean\s+(-[a-zA-Z]*f|-fd)/i' => 'WARNING: This will permanently delete untracked files.',
            '/\bDROP\s+(TABLE|DATABASE|SCHEMA)/i' => 'WARNING: Destructive SQL operation detected.',
            '/\bsudo\s+/' => 'WARNING: Command requires elevated privileges.',
            '/\bchmod\s+(000|777)\b/' => 'WARNING: Insecure file permissions.',
            '/\bdd\s+/' => 'WARNING: dd command can destroy data.',
            '/\b(:\(\)\{.*;\}\s*;)/' => 'WARNING: Potential fork bomb detected.',
            '/\b>\s*\/dev\/(s|h)d/' => 'WARNING: Writing directly to disk device.',
            '/\bcurl\s+.*\|\s*(ba)?sh/' => 'WARNING: Piping curl output to shell is potentially dangerous.',
            '/\brm\s+--no-preserve-root/' => 'WARNING: Attempting to remove root filesystem.',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $command)) {
                $warnings[] = $message;
            }
        }

        return $warnings;
    }

    /**
     * Interpret exit code semantics for known commands.
     * Returns context about whether the exit code is expected or has special meaning.
     */
    private function interpretExitCode(string $command, int $exitCode, string $output): array
    {
        // Extract the base command (first word)
        $baseCommand = preg_split('/\s+/', ltrim($command))[0] ?? '';
        $baseCommand = basename($baseCommand);

        // grep/rg exit code 1 = no matches found (not an error)
        if (in_array($baseCommand, ['grep', 'rg']) && $exitCode === 1) {
            return [
                'isExpected' => true,
                'note' => '[Note: Exit code 1 means no matches were found — this is not an error.]',
            ];
        }

        // diff exit code 1 = files differ (not an error)
        if ($baseCommand === 'diff' && $exitCode === 1) {
            return [
                'isExpected' => true,
                'note' => '[Note: Exit code 1 means the files differ — this is not an error.]',
            ];
        }

        // test exit code 1 = condition evaluated to false
        if ($baseCommand === 'test' && $exitCode === 1) {
            return [
                'isExpected' => true,
                'note' => '[Note: Exit code 1 means the test condition was false — this is not an error.]',
            ];
        }

        // which/where/whereis exit code 1 = command not found
        if (in_array($baseCommand, ['which', 'where', 'whereis', 'command']) && $exitCode === 1) {
            return [
                'isExpected' => false,
                'note' => '[Note: The command was not found in PATH.]',
            ];
        }

        // git merge exit code 1 = merge conflict
        if ($baseCommand === 'git' && $exitCode === 1) {
            if (preg_match('/\bmerge\b/', $command)) {
                return [
                    'isExpected' => false,
                    'note' => '[Note: Merge conflict detected. Resolve conflicts and commit.]',
                ];
            }
        }

        // timeout exit code 124 = timed out
        if ($baseCommand === 'timeout' && $exitCode === 124) {
            return [
                'isExpected' => false,
                'note' => '[Note: Command timed out.]',
            ];
        }

        // curl exit code 7 = connection refused, 22 = HTTP error
        if ($baseCommand === 'curl' && $exitCode === 7) {
            return [
                'isExpected' => false,
                'note' => '[Note: Connection refused. Is the server running?]',
            ];
        }

        return ['isExpected' => false, 'note' => null];
    }

    /**
     * Detect if a command is read-only (safe for auto-approval).
     */
    public function isReadOnlyCommand(string $command): bool
    {
        $readOnlyPatterns = [
            '/^\s*(cat|head|tail|less|more|wc|sort|uniq|cut|tr|tee)\b/',
            '/^\s*(ls|find|locate|which|whereis|file|stat|du|df)\b/',
            '/^\s*(grep|rg|ag|ack|fgrep|egrep)\b/',
            '/^\s*(git\s+(status|log|diff|branch|tag|remote|show|blame|rev-parse|ls-files|ls-tree))\b/',
            '/^\s*(echo|printenv|env|print|printf)\b/',
            '/^\s*(php\s+(-v|-m|-i|-r\s+echo|artisan\s+(--version|about|env|list|route:list)))\b/',
            '/^\s*(composer\s+(show|info|outdated|check))\b/',
            '/^\s*(node\s+(-v|--version))\b/',
            '/^\s*(npm\s+(list|ls|view|info|outdated))\b/',
            '/^\s*(curl\s+-s\b.*\b(-I|--head|-o\s*\/dev\/null))\b/',
            '/^\s*(date|uname|hostname|whoami|id|pwd|basename|dirname|realpath)\b/',
            '/^\s*(test\s)/',
        ];

        foreach ($readOnlyPatterns as $pattern) {
            if (preg_match($pattern . 'i', $command)) {
                return true;
            }
        }

        return false;
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return false;
    }

    public function maxResultSizeChars(): int
    {
        return 30000;
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $command = $input['command'] ?? '';

        // Git safety: prevent force push to main/master
        if (preg_match('/\bgit\s+push\b.*(--force\b|-f\b)/i', $command)) {
            // Check if targeting main/master
            if (preg_match('/\b(main|master)\b/i', $command)) {
                return 'Force-pushing to main/master is not allowed. This can overwrite upstream history and affect other developers.';
            }

            // Suggest --force-with-lease
            if (!preg_match('/--force-with-lease/i', $command)) {
                return 'Consider using --force-with-lease instead of --force for safer force pushing.';
            }
        }

        // Git safety: prevent pushing directly to main/master (even without force)
        if (preg_match('/\bgit\s+push\b/i', $command)) {
            if (preg_match('/\borigin\s+(main|master)\b/i', $command) ||
                preg_match('/\borigin\b.*\b(main|master)\b/i', $command)) {
                // Allow if there's an explicit branch reference that's not main
                if (!preg_match('/\bHEAD:/i', $command)) {
                    // Soft warning - just a note, not blocking
                    return null;
                }
            }
        }

        // Git safety: prevent destructive clean on entire repo
        if (preg_match('/\bgit\s+clean\s+(-[a-zA-Z]*f[a-zA-Z]*|--force)/i', $command)) {
            if (!preg_match('/(-e\b|--exclude\b|-n\b|--dry-run\b)/i', $command)) {
                return 'git clean with force will permanently delete untracked files. Add -e to exclude important files, or use -n for dry-run first.';
            }
        }

        // Git safety: prevent reset --hard HEAD~ on public branches
        if (preg_match('/\bgit\s+reset\s+--hard\b/i', $command)) {
            return 'Hard reset will discard all uncommitted changes. Consider --soft or --mixed first, or make a backup branch.';
        }

        return null;
    }

    public function userFacingName(array $input): string
    {
        return $input['description'] ?? $input['command'] ?? 'Bash';
    }
}
