<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\MessageHistory;
use App\Services\Api\ApiErrorException;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Memory\SessionMemory;
use App\Services\OutputStyle\OutputStyleLoader;
use App\Services\Session\AwaySummaryService;
use App\Services\Session\SessionManager;
use App\Services\Session\SessionTitleService;
use App\Services\Settings\SettingsManager;
use App\Support\Terminal\InputSanitizer;
use App\Support\Terminal\MarkdownRenderer;
use App\Support\Terminal\ReplFormatter;
use App\Support\Terminal\StreamingMarkdownOutput;
use App\Support\Terminal\TranscriptBuffer;
use App\Support\Terminal\TranscriptRenderer;
use App\Support\Terminal\TurnStatusRenderer;
use App\Tools\Bash\BashTool;
use App\Tools\Skill\SkillLoader;
use App\Tools\ToolRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Terminal;

class HaoCodeCommand extends Command
{
    protected $signature = 'hao-code
        {--prompt= : Non-interactive mode: execute a single prompt}
        {--model= : Override the default model}
        {--permission-mode= : Override the permission mode}
    ';

    protected $description = 'Hao Code - Interactive CLI Coding Agent';

    private bool $shouldExit = false;

    private bool $fastMode = false;

    private bool $titleGenerated = false;

    private ?ReplFormatter $replFormatter = null;

    private string $lastTranscriptQuery = '';

    /** @var array<int, string> */
    private array $sessionAllowRules = [];

    public function handle(): int
    {
        $this->printBanner();

        $settings = app(SettingsManager::class);
        if (empty($settings->getApiKey())) {
            $this->error('ANTHROPIC_API_KEY is not set. Please set it in your environment or .haocode/settings.json');

            return 1;
        }

        /** @var AgentLoop $agent */
        $agent = app(AgentLoop::class);

        // Set up interactive permission prompt handler
        $agent->setPermissionPromptHandler(function (string $toolName, array $input) {
            return $this->promptToolPermission($toolName, $input);
        });

        // Set up Ctrl+C handler for graceful abort (if pcntl available)
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($agent) {
                if ($agent->isAborted()) {
                    $this->line("\n<fg=red>Force exit.</>");
                    exit(1);
                }
                $this->line("\n".$this->formatter()->abortingStatus());
                $agent->abort();
            });
        }

        $prompt = $this->option('prompt');

        if ($prompt) {
            return $this->runSinglePrompt($agent, $prompt);
        }

        return $this->runRepl($agent);
    }

    private function printBanner(): void
    {
        foreach ($this->formatter()->banner(PHP_VERSION) as $line) {
            $this->line($line);
        }
        $this->line($this->formatter()->helpHint());
        $this->line('');
    }

    private function runRepl(AgentLoop $agent): int
    {
        // Load input history
        $historyFile = storage_path('app/haocode/input_history');
        $history = [];
        if (file_exists($historyFile)) {
            $history = array_filter(explode("\n", file_get_contents($historyFile)));
        }
        $historyPtr = count($history);

        if ($this->supportsReadline() && file_exists($historyFile)) {
            @readline_read_history($historyFile);
        }

        while (! $this->shouldExit) {
            $input = $this->readInput($agent, $history, $historyPtr);

            if ($input === null) {
                continue;
            }

            if ($input === '') {
                continue;
            }

            // Save to history
            $history[] = $input;
            $historyPtr = count($history);
            if (count($history) > 500) {
                $history = array_slice($history, -500);
            }
            if ($this->supportsReadline()) {
                readline_add_history($input);
                @readline_write_history($historyFile);
            } else {
                @file_put_contents($historyFile, implode("\n", $history));
            }

            // Handle slash commands
            if (str_starts_with($input, '/')) {
                $this->handleSlashCommand($input, $agent);

                continue;
            }

            $this->runAgentTurn($agent, $input);
        }

        return 0;
    }

    private function runSinglePrompt(AgentLoop $agent, string $prompt): int
    {
        $streamedOutput = false;
        $markdownRenderer = app(MarkdownRenderer::class);
        $markdownOutput = $this->createStreamingMarkdownOutput($markdownRenderer);
        $turnStatus = $this->createTurnStatusRenderer($prompt);
        $previousAlarmHandler = $this->startTurnStatusTicker($turnStatus);

        try {
            $turnStatus->start();

            $response = $agent->run(
                userInput: $prompt,
                onTextDelta: function (string $text) use (&$streamedOutput, $turnStatus, $markdownOutput) {
                    $turnStatus->recordTextDelta($text);
                    $turnStatus->pause();
                    $streamedOutput = true;
                    $markdownOutput->append($text);
                },
                onToolStart: function (string $toolName, array $toolInput) use (&$streamedOutput, $turnStatus, $markdownOutput) {
                    $turnStatus->pause();
                    $streamedOutput = true;
                    $markdownOutput->finalize();
                    $args = $this->summarizeToolInput($toolName, $toolInput);
                    $this->line("\n".$this->formatter()->toolCall($toolName, $args));
                    $turnStatus->setPhaseLabel($toolName);
                    $turnStatus->resume();
                },
                onToolComplete: function (string $toolName, $result) use ($turnStatus) {
                    $turnStatus->pause();
                    $turnStatus->setPhaseLabel(null);
                    if ($result->isError) {
                        $message = trim((string) $result->output);
                        $this->line($this->formatter()->toolFailure($toolName, $message === '' ? 'Unknown error' : $message));
                    }
                    $turnStatus->resume();
                },
            );

            $turnStatus->pause();
            $markdownOutput->finalize();
            if ($response === '(aborted)') {
                if ($streamedOutput) {
                    $this->line('');
                }
                $this->line($this->formatter()->interruptedStatus());
                return 130;
            }
            if (! $streamedOutput && $response !== '') {
                $this->line($markdownRenderer->render($response));
            } else {
                $this->line('');
            }
            $this->printUsageStats($agent);
        } catch (ApiErrorException $e) {
            $turnStatus->pause();
            $markdownOutput->finalize();
            if ($streamedOutput) {
                $this->line('');
            }
            $this->line("  <fg=red>API Error ({$e->getErrorType()}): {$e->getMessage()}</>");
            return 1;
        } catch (\Throwable $e) {
            $turnStatus->pause();
            $markdownOutput->finalize();
            if ($streamedOutput) {
                $this->line('');
            }
            $this->line("  <fg=red>Error: {$e->getMessage()}</>");
            if (config('app.debug')) {
                $this->line("  <fg=gray>{$e->getFile()}:{$e->getLine()}</>");
            }
            return 1;
        } finally {
            $this->stopTurnStatusTicker($turnStatus, $previousAlarmHandler);
        }

        return 0;
    }

    private function readInput(AgentLoop $agent, array &$history, int &$historyPtr): ?string
    {
        $this->renderPromptFooter($agent);

        if ($this->supportsReadline()) {
            return $this->readInputWithReadline();
        }

        return $this->readInputRaw($agent, $history, $historyPtr);
    }

    private function readInputWithReadline(): ?string
    {
        $cwd = basename(getcwd());
        $lines = [];
        $prompt = $this->formatter()->readlinePrompt($cwd);

        while (true) {
            $line = readline($prompt);
            if ($line === false) {
                $this->shouldExit = true;

                return null;
            }

            $line = InputSanitizer::sanitize($line);

            if (str_ends_with(rtrim($line), '\\')) {
                $lines[] = rtrim($line, ' \\');
                $prompt = $this->formatter()->readlineContinuationPrompt();

                continue;
            }

            $lines[] = $line;
            break;
        }

        $fullInput = InputSanitizer::sanitize(implode("\n", $lines));

        return trim($fullInput) !== '' ? trim($fullInput) : '';
    }

    private function readInputRaw(AgentLoop $agent, array &$history, int &$historyPtr): ?string
    {
        $cwd = basename(getcwd());
        $this->output->write($this->formatter()->prompt($cwd));

        $handle = fopen('php://stdin', 'r');
        if ($handle === false) {
            return null;
        }

        // Raw mode for proper key handling
        $sttyMode = '';
        if (function_exists('shell_exec')) {
            $sttyMode = shell_exec('stty -g 2>/dev/null') ?? '';
            if ($sttyMode) {
                shell_exec('stty raw -echo 2>/dev/null');
            }
        }

        $lines = [];
        $currentLine = '';

        try {
            while (true) {
                $char = fread($handle, 1);

                if ($char === "\r" || $char === "\n") {
                    // Enter key
                    if ($sttyMode) {
                        echo "\r\n";
                    }

                    // Check for backslash continuation
                    if (str_ends_with(rtrim($currentLine), '\\')) {
                        $currentLine = rtrim($currentLine, ' \\');
                        $lines[] = $currentLine;
                        $currentLine = '';
                        $this->output->write($this->formatter()->continuationPrompt());

                        continue;
                    }

                    $lines[] = $currentLine;
                    break;
                }

                if ($char === "\x03") { // Ctrl+C
                    if ($sttyMode) {
                        echo "\r\n";
                    }

                    return null;
                }

                if ($char === "\x0f") { // Ctrl+O
                    if ($sttyMode) {
                        echo "\r\n";
                    }

                    $this->openTranscriptMode($agent, alreadyRaw: true, inputHandle: $handle);
                    $this->redrawRawInputScreen($agent, $cwd, $currentLine);

                    continue;
                }

                if ($char === "\x12") { // Ctrl+R
                    $match = $this->readReverseHistorySearch($handle, $history, $currentLine, $cwd);
                    if ($match !== null) {
                        $currentLine = $match;
                    }
                    continue;
                }

                if ($char === "\x04") { // Ctrl+D
                    if ($sttyMode) {
                        echo "\r\n";
                    }
                    $this->shouldExit = true;

                    return null;
                }

                if ($char === "\x1b") { // Escape sequence
                    $seq = fread($handle, 2);
                    if ($seq === '[A') { // Up arrow
                        if ($historyPtr > 0) {
                            $historyPtr--;
                            // Clear current line
                            echo "\r\033[K";
                            $prompt = "\e[32m{$cwd}\e[0m \e[36m❯\e[0m ";
                            echo $prompt.$history[$historyPtr];
                            $currentLine = $history[$historyPtr];
                        }

                        continue;
                    }
                    if ($seq === '[B') { // Down arrow
                        if ($historyPtr < count($history) - 1) {
                            $historyPtr++;
                            echo "\r\033[K";
                            $prompt = "\e[32m{$cwd}\e[0m \e[36m❯\e[0m ";
                            echo $prompt.$history[$historyPtr];
                            $currentLine = $history[$historyPtr];
                        }

                        continue;
                    }

                    // Ignore other escape sequences (arrow left/right, etc.)
                    continue;
                }

                if ($char === "\x7f" || $char === "\x08") { // Backspace
                    if (strlen($currentLine) > 0) {
                        $currentLine = substr($currentLine, 0, -1);
                        echo "\x08 \x08";
                    }

                    continue;
                }

                if ($char === "\x0c") { // Ctrl+L
                    echo "\033[H\033[2J";
                    $this->redrawRawInputScreen($agent, $cwd, $currentLine);

                    continue;
                }

                // Normal printable character
                if (ord($char) >= 32 || $char === "\t") {
                    $currentLine .= $char;
                    echo $char;
                }
            }
        } finally {
            if ($sttyMode) {
                shell_exec("stty {$sttyMode} 2>/dev/null");
            }
            fclose($handle);
        }

        $fullInput = InputSanitizer::sanitize(implode("\n", $lines));

        return trim($fullInput) !== '' ? trim($fullInput) : '';
    }

    private function supportsReadline(): bool
    {
        return function_exists('readline') && function_exists('readline_add_history') && stream_isatty(STDIN);
    }

    private function handleSlashCommand(string $input, AgentLoop $agent): void
    {
        $parts = explode(' ', $input, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        match ($command) {
            '/exit', '/quit', '/q' => $this->handleExit(),
            '/help', '/h', '/?' => $this->handleHelp(),
            '/clear' => $this->handleClear($agent),
            '/history' => $this->handleHistory($agent),
            '/compact' => $this->handleCompact($agent),
            '/model' => $this->handleModel($args),
            '/cost', '/usage' => $this->printUsageStats($agent),
            '/status' => $this->handleStatus($agent),
            '/transcript', '/t' => $this->handleTranscript($agent, $args),
            '/search' => $this->handleTranscript($agent, $args),
            '/tasks' => $this->handleTasks(),
            '/resume' => $this->handleResume($agent, $args),
            '/diff' => $this->handleDiff(),
            '/memory' => $this->handleMemory($args),
            '/rewind' => $this->handleRewind(),
            '/context' => $this->handleContext($agent),
            '/doctor' => $this->handleDoctor(),
            '/theme' => $this->handleTheme($args),
            '/skills' => $this->handleSkills(),
            '/permissions', '/perm' => $this->handlePermissions($args),
            '/fast' => $this->handleFast(),
            '/snapshot' => $this->handleSnapshot($agent, $args),
            '/init' => $this->handleInit($args),
            '/version' => $this->handleVersion(),
            '/output-style' => $this->handleOutputStyle($args),
            default => $this->line("<fg=yellow>Unknown command: {$command}</>. Type <fg=cyan>/help</> for available commands."),
        };
    }

    private function handleExit(): void
    {
        app(HookExecutor::class)->execute('SessionEnd', []);
        $this->line('<fg=gray>Goodbye!</>');
        $this->shouldExit = true;
    }

    private function handleHelp(): void
    {
        $this->renderPanel('Available commands', [
            '<fg=green>/help</> show help',
            '<fg=green>/exit</> exit the REPL',
            '<fg=green>/clear</> clear conversation history',
            '<fg=green>/compact</> compact conversation context',
            '<fg=green>/cost</> show token usage and cost',
            '<fg=green>/history</> show message count',
            '<fg=green>/model</> show or set current model',
            '<fg=green>/status</> show session status',
            '<fg=green>/transcript</> browse transcript mode',
            '<fg=green>/search</> open transcript search',
            '<fg=green>/tasks</> list background tasks',
            '<fg=green>/resume</> resume a previous session',
            '<fg=green>/diff</> show uncommitted changes',
            '<fg=green>/memory</> view or edit session memory',
            '<fg=green>/context</> show context usage',
            '<fg=green>/rewind</> undo last change',
            '<fg=green>/doctor</> run diagnostics',
            '<fg=green>/skills</> list available skills',
            '<fg=green>/theme</> toggle color theme',
            '<fg=green>/permissions</> manage permission rules',
            '<fg=green>/fast</> toggle fast model mode',
            '<fg=green>/snapshot</> export session markdown',
            '<fg=green>/init</> initialize .haocode/settings.json',
            '<fg=green>/version</> show version information',
            '<fg=green>/output-style</> list or set output style',
        ]);
    }

    private function handleClear(AgentLoop $agent): void
    {
        $agent->getMessageHistory()->clear();
        $this->line('<fg=gray>Conversation history cleared.</>');
    }

    private function handleHistory(AgentLoop $agent): void
    {
        $count = $agent->getMessageHistory()->count();
        $this->line("<fg=gray>Message count: {$count}</>");
    }

    private function handleCompact(AgentLoop $agent): void
    {
        $compactor = app(ContextCompactor::class);
        $result = $compactor->compact($agent->getMessageHistory());
        $this->line("<fg=gray>{$result}</>");
    }

    private function handleTasks(): void
    {
        $tasks = BashTool::listTasks();

        if (empty($tasks)) {
            $this->line('<fg=gray>No background tasks running.</>');

            return;
        }

        $lines = [];
        foreach ($tasks as $id => $task) {
            $elapsed = round(microtime(true) - $task['startTime'], 1);
            $lines[] = sprintf(
                '<fg=yellow>%s</> <fg=gray>PID</> <fg=white>%s</> <fg=gray>· %ss · %s</>',
                $id,
                $task['pid'],
                $elapsed,
                $this->truncate($task['command'], 50),
            );
        }
        $this->renderPanel('Background tasks', $lines);
    }

    private function handleResume(AgentLoop $agent, string $args): void
    {
        $args = trim($args);

        if ($args === 'list' || $args === '') {
            $this->listSessions();

            return;
        }

        $sessionPath = config('haocode.session_path', storage_path('app/haocode/sessions'));

        // Sanitize args for glob safety
        $safeArg = str_replace(['*', '?', '[', ']'], '', $args);

        // Find session file matching the partial ID
        $pattern = $sessionPath.'/'.$safeArg.'*.jsonl';
        $files = glob($pattern);

        if (empty($files)) {
            // Try partial match
            $pattern = $sessionPath.'/*'.$safeArg.'*.jsonl';
            $files = glob($pattern);
        }

        if (empty($files)) {
            $this->line("<fg=red>No session found matching: {$args}</>");

            return;
        }

        $file = $files[0];
        $entries = [];
        foreach (file($file) as $line) {
            if (trim($line)) {
                $entries[] = json_decode($line, true);
            }
        }

        if (empty($entries)) {
            $this->line('<fg=red>Session is empty.</>');

            return;
        }

        // Restore messages into history
        $history = $agent->getMessageHistory();
        $history->clear();

        $restored = 0;
        foreach ($entries as $entry) {
            $type = $entry['type'] ?? '';
            if ($type === 'user_message') {
                $history->addUserMessage($entry['content'] ?? '');
                $restored++;
            } elseif ($type === 'assistant_turn') {
                if (isset($entry['message'])) {
                    $history->addAssistantMessage($entry['message']);
                    $restored++;
                }
                if (! empty($entry['tool_results'])) {
                    $history->addToolResultMessage($entry['tool_results']);
                }
            }
        }

        $sessionId = basename($file, '.jsonl');
        $this->line("<fg=green>Resumed session:</> <fg=white>{$sessionId}</> ({$restored} messages restored)");

        // Show away summary
        $awaySummary = app(AwaySummaryService::class)->generateSummary($entries);
        if ($awaySummary) {
            $this->renderPanel('While you were away', [
                $this->formatter()->keyValue('Summary', $awaySummary, 'gray', 'gray'),
            ]);
        }
    }

    private function listSessions(): void
    {
        $sessionPath = config('haocode.session_path', storage_path('app/haocode/sessions'));

        if (! is_dir($sessionPath)) {
            $this->line('<fg=gray>No sessions found.</>');

            return;
        }

        $files = glob($sessionPath.'/*.jsonl');

        if (empty($files)) {
            $this->line('<fg=gray>No sessions found.</>');

            return;
        }

        // Sort by modification time, newest first
        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        $lines = [];
        $count = 0;
        foreach ($files as $file) {
            if ($count++ >= 10) {
                break;
            }
            $id = basename($file, '.jsonl');
            $entryCount = count(file($file));
            $time = date('Y-m-d H:i', filemtime($file));

            // Extract stored title from the session file
            $entries = array_map(fn ($l) => json_decode(trim($l), true) ?: [], file($file));
            $title = SessionManager::extractTitleFromEntries($entries);
            $line = "<fg=yellow>{$id}</> <fg=gray>· {$time} · {$entryCount} entries</>";
            if ($title) {
                $line .= " <fg=white>· {$title}</>";
            }
            $lines[] = $line;
        }
        $lines[] = '<fg=gray>Use /resume &lt;session_id&gt; to restore a session</>';
        $this->renderPanel('Recent sessions', $lines);
    }

    private function handleModel(string $args = ''): void
    {
        $settings = app(SettingsManager::class);
        $args = trim($args);

        if ($args === '') {
            $available = SettingsManager::getAvailableModels();
            $lines = [
                $this->formatter()->keyValue('Current', $settings->getModel()),
                ...array_map(
                    fn (string $model): string => $this->formatter()->keyValue('Model', $model, 'gray', 'gray'),
                    $available,
                ),
            ];
            $this->renderPanel('Models', $lines);

            return;
        }

        $available = SettingsManager::getAvailableModels();
        if (in_array($args, $available)) {
            $settings->set('model', $args);
            $this->line("<fg=green>Model set to:</> <fg=white>{$args}</>");
        } else {
            $this->line("<fg=red>Unknown model: {$args}</>");
            $this->line('<fg=gray>Available: '.implode(', ', $available).'</>');
        }
    }

    private function handleDiff(): void
    {
        $stat = shell_exec('git diff --stat HEAD 2>/dev/null');
        if (empty(trim($stat ?? ''))) {
            // Try staged only
            $stat = shell_exec('git diff --cached --stat 2>/dev/null');
        }

        if (empty(trim($stat ?? ''))) {
            $this->line('<fg=gray>No uncommitted changes.</>');

            return;
        }

        $this->line("\n  <fg=cyan;bold>Uncommitted Changes:</>");
        $this->line("<fg=gray>{$stat}</>");

        // Show colored full diff if not too large
        $fullDiff = shell_exec('git diff HEAD 2>/dev/null') ?: shell_exec('git diff --cached 2>/dev/null') ?: '';

        if (mb_strlen($fullDiff) > 8000) {
            $this->line('  <fg=gray>(diff too large to display — run `git diff` in your terminal)</>');

            return;
        }

        if (trim($fullDiff) === '') {
            return;
        }

        foreach (explode("\n", $fullDiff) as $line) {
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                $this->line("<fg=cyan>{$line}</>");
            } elseif (str_starts_with($line, '@@')) {
                $this->line("<fg=magenta>{$line}</>");
            } elseif (str_starts_with($line, '+')) {
                $this->line("<fg=green>{$line}</>");
            } elseif (str_starts_with($line, '-')) {
                $this->line("<fg=red>{$line}</>");
            } elseif (str_starts_with($line, 'diff ') || str_starts_with($line, 'index ')) {
                $this->line("<fg=yellow>{$line}</>");
            } else {
                $this->line("<fg=gray>{$line}</>");
            }
        }
    }

    private function handleMemory(string $args): void
    {
        $args = trim($args);
        /** @var SessionMemory $memory */
        $memory = app(SessionMemory::class);

        if ($args === '' || $args === 'list') {
            $memories = $memory->list();
            if (empty($memories)) {
                $this->line('<fg=gray>No memories stored. Use: /memory set <key> <value></>');

                return;
            }
            $this->line("\n  <fg=cyan;bold>Session Memories:</>");
            foreach ($memories as $key => $entry) {
                $type = $entry['type'] ?? 'note';
                $updated = $entry['updated_at'] ?? 'unknown';
                $this->line("  <fg=yellow>{$key}</> <fg=gray>[{$type}]</> <fg=white>{$entry['value']}</>");
                $this->line("    <fg=gray>Updated: {$updated}</>");
            }

            return;
        }

        if (str_starts_with($args, 'set ')) {
            $parts = explode(' ', substr($args, 4), 2);
            if (count($parts) < 2) {
                $this->line('<fg=red>Usage: /memory set <key> <value></>');

                return;
            }
            $memory->set($parts[0], $parts[1]);
            $this->line("<fg=green>Memory set:</> <fg=yellow>{$parts[0]}</>");

            return;
        }

        if (str_starts_with($args, 'delete ') || str_starts_with($args, 'del ')) {
            $key = trim(explode(' ', $args, 2)[1] ?? '');
            if ($memory->delete($key)) {
                $this->line("<fg=green>Memory deleted:</> <fg=yellow>{$key}</>");
            } else {
                $this->line("<fg=red>Memory not found:</> {$key}");
            }

            return;
        }

        if (str_starts_with($args, 'search ')) {
            $query = trim(substr($args, 7));
            $results = $memory->search($query);
            if (empty($results)) {
                $this->line("<fg=gray>No memories matching: {$query}</>");

                return;
            }
            foreach ($results as $key => $entry) {
                $this->line("  <fg=yellow>{$key}</>: <fg=white>{$entry['value']}</>");
            }

            return;
        }

        $this->line('<fg=yellow>Usage:</> /memory [list|set <key> <value>|delete <key>|search <query>]');
    }

    private function handleRewind(): void
    {
        // Undo the last conversation turn by popping the last user + assistant messages
        $history = app(MessageHistory::class);
        $messages = $history->getMessagesForApi();
        $count = count($messages);

        if ($count < 2) {
            $this->line('<fg=gray>Nothing to rewind.</>');

            return;
        }

        // Remove the last assistant message and its preceding tool results/user message
        $removed = 0;
        $popped = array_pop($messages); // assistant
        $removed++;

        // Pop tool result messages (user role with tool_result blocks)
        while (! empty($messages)) {
            $last = end($messages);
            $role = $last['role'] ?? '';
            $content = $last['content'] ?? '';
            if ($role === 'user' && is_array($content)) {
                array_pop($messages);
                $removed++;
            } else {
                break;
            }
        }

        // Pop the user message that triggered this turn
        if (! empty($messages) && ($messages[count($messages) - 1]['role'] ?? '') === 'user') {
            array_pop($messages);
            $removed++;
        }

        // Rebuild history
        $history->clear();
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';
            if ($role === 'user') {
                if (is_string($content)) {
                    $history->addUserMessage($content);
                } else {
                    $history->addToolResultMessage(
                        array_filter($content, fn ($b) => ($b['type'] ?? '') === 'tool_result')
                    );
                }
            } elseif ($role === 'assistant') {
                $history->addAssistantMessage($msg);
            }
        }

        $this->line("<fg=green>Rewound:</> removed {$removed} messages. History now has ".count($messages).' messages.');
    }

    private function handleContext(AgentLoop $agent): void
    {
        $in = $agent->getTotalInputTokens();
        $out = $agent->getTotalOutputTokens();
        $msgs = $agent->getMessageHistory()->count();
        $contextLimit = 200000; // Claude context window
        $usagePercent = round(($in / $contextLimit) * 100, 1);

        $this->line("\n  <fg=cyan;bold>Context Usage:</>");
        $this->line("  Messages:     <fg=white>{$msgs}</>");
        $this->line('  Input tokens: <fg=white>'.number_format($in).'</> / '.number_format($contextLimit));
        $this->line('  Output tokens:<fg=white>'.number_format($out).'</>');

        // Visual bar
        $barWidth = 40;
        $filled = (int) round(($usagePercent / 100) * $barWidth);
        $filled = min($filled, $barWidth);
        $bar = str_repeat('█', $filled).str_repeat('░', $barWidth - $filled);

        $color = $usagePercent < 50 ? 'green' : ($usagePercent < 80 ? 'yellow' : 'red');
        $this->line("  <fg={$color}>{$bar}</> {$usagePercent}%");

        if ($usagePercent > 70) {
            $this->line('  <fg=yellow>⚠ Context is getting large. Consider using /compact to reduce it.</>');
        }
        $this->line('');
    }

    private function handleStatus(AgentLoop $agent): void
    {
        $settings = app(SettingsManager::class);
        $costTracker = app(CostTracker::class);
        $title = $agent->getSessionManager()->getTitle();
        $lines = [
            $this->formatter()->keyValue('Session', $agent->getSessionManager()->getSessionId()),
        ];
        if ($title) {
            $lines[] = $this->formatter()->keyValue('Title', $title);
        }
        $lines[] = $this->formatter()->keyValue('Model', $settings->getModel());
        $lines[] = $this->formatter()->keyValue('Messages', (string) $agent->getMessageHistory()->count());
        $lines[] = $this->formatter()->keyValue('Permission mode', $settings->getPermissionMode()->value);
        $lines[] = $this->formatter()->keyValue('Cost', $costTracker->getSummary(), 'gray', 'gray');

        $this->renderPanel('Session status', $lines, addSpacing: false);
        $this->printUsageStats($agent);
    }

    private function handleTranscript(AgentLoop $agent, string $args = ''): void
    {
        $query = trim($args);
        if ($query === '') {
            $query = $this->lastTranscriptQuery;
        }

        $this->openTranscriptMode($agent, $query);
    }

    private function handleDoctor(): void
    {
        $checks = [
            ['PHP Version', PHP_VERSION, true],
            ['PHP CLI', PHP_SAPI === 'cli' ? 'OK' : 'Not CLI ('.PHP_SAPI.')', PHP_SAPI === 'cli'],
            ['pcntl extension', extension_loaded('pcntl') ? 'Available' : 'Not available', extension_loaded('pcntl')],
            ['posix extension', extension_loaded('posix') ? 'Available' : 'Not available', extension_loaded('posix')],
            ['curl extension', extension_loaded('curl') ? 'Available' : 'Not available', extension_loaded('curl')],
            ['json extension', extension_loaded('json') ? 'Available' : 'Not available', extension_loaded('json')],
            ['mbstring extension', extension_loaded('mbstring') ? 'Available' : 'Not available', extension_loaded('mbstring')],
        ];

        // Check API key
        $settings = app(SettingsManager::class);
        $apiKey = $settings->getApiKey();
        $checks[] = ['API Key', $apiKey ? 'Configured ('.mb_substr($apiKey, 0, 10).'...)' : 'NOT SET', ! empty($apiKey)];

        // Check API base URL
        $baseUrl = $settings->getBaseUrl();
        $checks[] = ['API Base URL', $baseUrl ?: 'Not set (using default)', true];

        // Check git
        $gitVersion = shell_exec('git --version 2>/dev/null');
        $checks[] = ['Git', trim($gitVersion ?? 'Not found'), ! empty($gitVersion)];

        // Check rg (ripgrep)
        $rgVersion = shell_exec('rg --version 2>/dev/null');
        $checks[] = ['ripgrep', $rgVersion ? trim(explode("\n", $rgVersion)[0]) : 'Not installed', ! empty($rgVersion)];

        // Check session storage
        $sessionPath = storage_path('app/haocode/sessions');
        $checks[] = ['Session Storage', is_dir($sessionPath) ? 'OK' : 'Not created yet', true];

        // Check settings files
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $globalSettings = "{$home}/.haocode/settings.json";
        $checks[] = ['Global Settings', file_exists($globalSettings) ? $globalSettings : 'Not found', true];

        $toolCount = count(app(ToolRegistry::class)->getAllTools());
        $lines = [];
        foreach ($checks as [$label, $value, $ok]) {
            $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $lines[] = "{$icon} " . $this->formatter()->keyValue($label, (string) $value, 'white', 'gray');
        }
        $lines[] = '<fg=green>✓</> ' . $this->formatter()->keyValue('Tools', "{$toolCount} registered", 'white', 'gray');

        $this->renderPanel('Diagnostics', $lines, addSpacing: false);
        $allOk = count(array_filter($checks, fn ($c) => ! $c[2])) === 0;
        $this->line($allOk
            ? "\n  <fg=green>All checks passed.</>\n"
            : "\n  <fg=yellow>Some checks failed. See above for details.</>\n");
    }

    private function handleTheme(string $args): void
    {
        $args = trim($args);

        $settings = app(SettingsManager::class);
        $current = $settings->all()['theme'] ?? 'dark';

        if ($args === '' || $args === 'list') {
            $themes = ['dark' => 'Default dark theme', 'light' => 'Light terminal theme', 'ansi' => 'Basic ANSI (no truecolor)'];
            $lines = [$this->formatter()->keyValue('Current', $current)];
            foreach ($themes as $name => $desc) {
                $marker = $name === $current ? ' (current)' : '';
                $lines[] = "<fg=green>{$name}</> <fg=gray>· {$desc}{$marker}</>";
            }
            $lines[] = '<fg=gray>Use /theme &lt;name&gt; to switch</>';
            $this->renderPanel('Themes', $lines);

            return;
        }

        if (in_array($args, ['dark', 'light', 'ansi'])) {
            $settings->set('theme', $args);
            $this->line("<fg=green>Theme set to:</> <fg=white>{$args}</>");

            return;
        }

        $this->line("<fg=red>Unknown theme: {$args}</>. Available: dark, light, ansi");
    }

    private function handleSkills(): void
    {
        /** @var SkillLoader $skillLoader */
        $skillLoader = app(SkillLoader::class);
        $skills = $skillLoader->listSkills();

        if (empty($skills)) {
            $this->line('<fg=gray>No skills found. Create skills in ~/.haocode/skills/ or .haocode/skills/</>');

            return;
        }

        $lines = [];
        foreach ($skills as $skill) {
            $name = $skill['name'] ?? 'unknown';
            $desc = $skill['description'] ?? '';
            $userInvocable = ($skill['user_invocable'] ?? false) ? '<fg=green>/</>' : '<fg=gray>auto</>';
            $lines[] = "{$userInvocable} <fg=yellow>{$name}</> <fg=gray>{$desc}</>";
        }
        $this->renderPanel('Available skills', $lines);
    }

    private function handlePermissions(string $args): void
    {
        /** @var SettingsManager $settings */
        $settings = app(SettingsManager::class);
        $mode = $settings->getPermissionMode();
        $allowRules = $settings->getAllowRules();
        $denyRules = $settings->getDenyRules();

        $parts = explode(' ', trim($args), 2);
        $subCommand = $parts[0] ?? '';
        $ruleArg = $parts[1] ?? '';

        // Sub-commands for managing rules
        if ($subCommand === 'allow') {
            if (empty($ruleArg)) {
                $this->line('<fg=red>Usage: /permissions allow <rule></>  e.g. <fg=white>/permissions allow Bash(git push*)</>');

                return;
            }
            $settings->addAllowRule($ruleArg);
            $this->line("<fg=green>Added allow rule:</> <fg=white>{$ruleArg}</>");

            return;
        }

        if ($subCommand === 'deny') {
            if (empty($ruleArg)) {
                $this->line('<fg=red>Usage: /permissions deny <rule></>  e.g. <fg=white>/permissions deny Bash(rm -rf)</>');

                return;
            }
            $settings->addDenyRule($ruleArg);
            $this->line("<fg=red>Added deny rule:</> <fg=white>{$ruleArg}</>");

            return;
        }

        if ($subCommand === 'remove') {
            if (empty($ruleArg)) {
                $this->line('<fg=red>Usage: /permissions remove <rule></>');

                return;
            }
            $settings->removeAllowRule($ruleArg);
            $settings->removeDenyRule($ruleArg);
            $this->line("<fg=gray>Removed rule:</> <fg=white>{$ruleArg}</>");

            return;
        }

        // Default: show current permissions status
        $lines = [
            $this->formatter()->keyValue('Mode', $mode->value, 'gray', 'yellow'),
        ];

        if (! empty($allowRules)) {
            foreach ($allowRules as $rule) {
                $lines[] = "<fg=green>+</> <fg=white>{$rule}</>";
            }
        }

        if (! empty($denyRules)) {
            foreach ($denyRules as $rule) {
                $lines[] = "<fg=red>-</> <fg=white>{$rule}</>";
            }
        }

        if (! empty($this->sessionAllowRules)) {
            foreach ($this->sessionAllowRules as $rule) {
                $lines[] = "<fg=cyan>~</> <fg=white>{$rule}</> <fg=gray>(session)</>";
            }
        }

        if (count($lines) === 1) {
            $lines[] = '<fg=gray>No custom rules configured.</>';
        }

        $lines[] = '<fg=gray>Commands: /permissions allow &lt;rule&gt; | /permissions deny &lt;rule&gt; | /permissions remove &lt;rule&gt;</>';
        $this->renderPanel('Permission settings', $lines);
    }

    private function handleFast(): void
    {
        $settings = app(SettingsManager::class);
        $this->fastMode = ! $this->fastMode;

        if ($this->fastMode) {
            $settings->set('model', 'claude-haiku-4-20250514');
            $this->line('<fg=green>Fast mode ON</> — switched to <fg=white>claude-haiku-4-20250514</> (cheaper & faster)');
        } else {
            $settings->set('model', config('haocode.model', 'claude-sonnet-4-20250514'));
            $this->line('<fg=gray>Fast mode OFF</> — restored <fg=white>'.config('haocode.model', 'claude-sonnet-4-20250514').'</>');
        }
    }

    private function handleSnapshot(AgentLoop $agent, string $args): void
    {
        $messages = $agent->getMessageHistory()->getMessagesForApi();

        if (empty($messages)) {
            $this->line('<fg=gray>Nothing to snapshot — conversation is empty.</>');

            return;
        }

        $args = trim($args);
        $timestamp = date('Y-m-d_H-i-s');
        $filename = $args ?: "snapshot_{$timestamp}.md";
        if (! str_ends_with($filename, '.md')) {
            $filename .= '.md';
        }

        $snapshotDir = storage_path('app/haocode/snapshots');
        if (! is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0755, true);
        }

        $filepath = $snapshotDir.'/'.$filename;

        $md = "# Hao Code Snapshot\n\n";
        $md .= '**Date:** '.date('Y-m-d H:i:s')."\n";
        $md .= '**Session:** '.$agent->getSessionManager()->getSessionId()."\n";
        $md .= '**Model:** '.app(SettingsManager::class)->getModel()."\n\n---\n\n";

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';

            if ($role === 'user' && is_string($content)) {
                $md .= "## User\n\n{$content}\n\n";
            } elseif ($role === 'assistant') {
                $text = '';
                if (is_string($content)) {
                    $text = $content;
                } elseif (is_array($content)) {
                    foreach ($content as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $text .= $block['text'] ?? '';
                        }
                    }
                }
                if ($text) {
                    $md .= "## Assistant\n\n{$text}\n\n";
                }
            }
        }

        file_put_contents($filepath, $md);
        $this->line("<fg=green>Snapshot saved:</> <fg=white>{$filepath}</>");
    }

    private function handleInit(string $args): void
    {
        $projectPath = getcwd().'/.haocode/settings.json';

        if (file_exists($projectPath) && trim($args) !== '--force') {
            $this->line("<fg=yellow>Settings already exist at:</> <fg=white>{$projectPath}</>");
            $this->line('<fg=gray>Use /init --force to overwrite.</>');

            return;
        }

        $dir = dirname($projectPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $defaults = [
            'model' => config('haocode.model', 'claude-sonnet-4-20250514'),
            'permissions' => [
                'allow' => [],
                'deny' => [],
            ],
        ];

        file_put_contents($projectPath, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("<fg=green>Initialized:</> <fg=white>{$projectPath}</>");
        $this->line('<fg=gray>Edit this file to customise model, permissions, and system prompt.</>');
    }

    private function handleVersion(): void
    {
        $composerJson = base_path('composer.json');
        $version = 'dev';
        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true) ?? [];
            $version = $composer['version'] ?? $version;
        }

        $settings = app(SettingsManager::class);
        $toolCount = count(app(ToolRegistry::class)->getAllTools());

        $this->line("\n  <fg=cyan;bold>Hao Code</> <fg=white>{$version}</>");
        $this->line('  PHP:     <fg=white>'.PHP_VERSION.'</>');
        $this->line('  Laravel: <fg=white>'.app()->version().'</>');
        $this->line('  Model:   <fg=white>'.$settings->getModel().'</>');
        $this->line("  Tools:   <fg=white>{$toolCount} registered</>");
        $this->line('  CWD:     <fg=white>'.getcwd()."</>\n");
    }

    private function handleOutputStyle(string $args): void
    {
        /** @var OutputStyleLoader $loader */
        $loader = app(OutputStyleLoader::class);
        $settings = app(SettingsManager::class);
        $args = trim($args);

        $styles = $loader->listStyles();

        if ($args === '' || $args === 'list') {
            if (empty($styles)) {
                $this->line('<fg=gray>No output styles found.</>');
                $this->line('<fg=gray>Create .md files in ~/.haocode/output-styles/ or .haocode/output-styles/</>');

                return;
            }
            $active = $settings->getOutputStyle();
            $this->line("\n  <fg=cyan;bold>Output Styles:</>");
            foreach ($styles as $slug => $style) {
                $marker = ($slug === $active) ? '<fg=green>✓</>' : ' ';
                $this->line("  {$marker} <fg=yellow>{$slug}</> <fg=gray>{$style['description']}</>");
            }
            $this->line("\n  <fg=gray>Use /output-style <slug> to activate, /output-style off to disable\n</>");

            return;
        }

        if ($args === 'off' || $args === 'none') {
            $settings->set('output_style', null);
            $this->line('<fg=gray>Output style disabled.</>');

            return;
        }

        if (isset($styles[$args])) {
            $settings->set('output_style', $args);
            $this->line("<fg=green>Output style set to:</> <fg=white>{$styles[$args]['name']}</>");

            return;
        }

        $this->line("<fg=red>Unknown style: {$args}</>. Available: ".implode(', ', array_keys($styles)));
    }

    private function runAgentTurn(AgentLoop $agent, string $input): void
    {
        $this->line('');
        $streamedOutput = false;
        $markdownRenderer = app(MarkdownRenderer::class);
        $markdownOutput = $this->createStreamingMarkdownOutput($markdownRenderer);
        $turnStatus = $this->createTurnStatusRenderer($input);
        $previousAlarmHandler = $this->startTurnStatusTicker($turnStatus);

        try {
            $turnStatus->start();

            $response = $agent->run(
                userInput: $input,
                onTextDelta: function (string $text) use (&$streamedOutput, $turnStatus, $markdownOutput) {
                    $turnStatus->recordTextDelta($text);
                    $turnStatus->pause();
                    $streamedOutput = true;
                    $markdownOutput->append($text);
                },
                onToolStart: function (string $toolName, array $toolInput) use (&$streamedOutput, $turnStatus, $markdownOutput) {
                    $turnStatus->pause();
                    $streamedOutput = true;
                    $markdownOutput->finalize();
                    $args = $this->summarizeToolInput($toolName, $toolInput);
                    $this->line("\n".$this->formatter()->toolCall($toolName, $args));
                    $turnStatus->setPhaseLabel($toolName);
                    $turnStatus->resume();
                },
                onToolComplete: function (string $toolName, $result) use ($turnStatus) {
                    $turnStatus->pause();
                    $turnStatus->setPhaseLabel(null);
                    if ($result->isError) {
                        $message = trim((string) $result->output);
                        $this->line($this->formatter()->toolFailure($toolName, $message === '' ? 'Unknown error' : $message));
                    }
                    $turnStatus->resume();
                },
            );

            $turnStatus->pause();
            $markdownOutput->finalize();
            if ($response === '(aborted)') {
                if ($streamedOutput) {
                    $this->line('');
                }
                $this->line($this->formatter()->interruptedStatus());
                return;
            }
            if (! $streamedOutput && $response !== '') {
                $this->line($markdownRenderer->render($response));
            }
            $this->line("\n");

            // Generate session title after the first turn (fire-and-forget best-effort)
            if (! $this->titleGenerated && $agent->getSessionManager()->getTitle() === null) {
                $this->titleGenerated = true;
                $messages = $agent->getMessageHistory()->getMessagesForApi();
                $title = app(SessionTitleService::class)->generateTitle($messages);
                if ($title) {
                    $agent->getSessionManager()->setTitle($title);
                    $this->line("  <fg=gray>Session: {$title}</>");
                }
            }

            // Show cost after each turn
            $cost = $agent->getEstimatedCost();
            $inTokens = $agent->getTotalInputTokens();
            $outTokens = $agent->getTotalOutputTokens();
            $cacheRead = $agent->getCacheReadTokens();
            $this->line($this->formatter()->usageFooter($inTokens, $outTokens, $cacheRead, $cost));

            // Show context window warning if applicable
            $compactor = app(ContextCompactor::class);
            $warn = $compactor->getWarningState($inTokens);
            if ($warn['isBlocking']) {
                $this->line("  <fg=red;bold>⚠  {$warn['message']}</>");
            } elseif ($warn['isError']) {
                $this->line("  <fg=red>⚠  {$warn['message']}</>");
            } elseif ($warn['isWarning']) {
                $this->line("  <fg=yellow>⚠  {$warn['message']}</>");
            }

        } catch (ApiErrorException $e) {
            $turnStatus->pause();
            $markdownOutput->finalize();
            $this->line("\n  <fg=red>API Error ({$e->getErrorType()}): {$e->getMessage()}</>\n");
        } catch (\Throwable $e) {
            $turnStatus->pause();
            $markdownOutput->finalize();
            $this->line("\n  <fg=red>Error: {$e->getMessage()}</>\n");
            if (config('app.debug')) {
                $this->line("  <fg=gray>{$e->getFile()}:{$e->getLine()}</>\n");
            }
        } finally {
            $this->stopTurnStatusTicker($turnStatus, $previousAlarmHandler);
        }
    }

    private function promptToolPermission(string $toolName, array $input): bool
    {
        foreach ($this->sessionAllowRules as $rule) {
            if ($this->matchesPermissionRule($rule, $toolName, $input)) {
                return true;
            }
        }

        $args = $this->summarizeToolInput($toolName, $input);

        $this->renderPanel(
            'Permission required',
            [
                $this->formatter()->keyValue('Tool', $toolName),
                $args === ''
                    ? $this->formatter()->keyValue('Target', 'this action', 'gray', 'gray')
                    : $this->formatter()->keyValue('Target', $args, 'gray', 'gray'),
                '<fg=green>[y]</> allow once  <fg=green>[a]</> allow session  <fg=red>[n]</> deny',
            ],
            'yellow',
            addSpacing: false,
        );

        $handle = fopen('php://stdin', 'r');
        if ($handle === false) {
            return false;
        }
        $answer = strtolower(trim(fgets($handle)));
        fclose($handle);

        if (in_array($answer, ['a', 'always'], true)) {
            $rule = $this->buildPermissionRule($toolName, $input);
            if ($rule !== null && ! in_array($rule, $this->sessionAllowRules, true)) {
                $this->sessionAllowRules[] = $rule;
            }

            return true;
        }

        return in_array($answer, ['y', 'yes', ''], true);
    }

    private function buildPermissionRule(string $toolName, array $input): ?string
    {
        $value = match ($toolName) {
            'Bash' => $input['command'] ?? null,
            'Read', 'Edit', 'Write' => $input['file_path'] ?? null,
            'Glob', 'Grep' => $input['pattern'] ?? null,
            'WebFetch' => $input['url'] ?? null,
            default => null,
        };

        if (! is_string($value) || trim($value) === '') {
            return $toolName;
        }

        return sprintf('%s(%s)', $toolName, $value);
    }

    private function matchesPermissionRule(string $rule, string $toolName, array $input): bool
    {
        if (! preg_match('/^(\w+)(?:\((.+)\))?$/', $rule, $matches)) {
            return false;
        }

        if (($matches[1] ?? null) !== $toolName) {
            return false;
        }

        if (! isset($matches[2])) {
            return true;
        }

        $pattern = $matches[2];
        $matchField = match ($toolName) {
            'Bash' => $input['command'] ?? '',
            'Read', 'Edit', 'Write' => $input['file_path'] ?? '',
            'Glob', 'Grep' => $input['pattern'] ?? '',
            'WebFetch' => $input['url'] ?? '',
            default => is_scalar(reset($input)) ? (string) reset($input) : '',
        };

        if (! is_string($matchField)) {
            return false;
        }

        if (str_ends_with($pattern, ':*')) {
            $prefix = substr($pattern, 0, -2);

            return $matchField === $prefix
                || str_starts_with($matchField, $prefix . ' ');
        }

        if (str_contains($pattern, '*')) {
            return fnmatch($pattern, $matchField);
        }

        return $matchField === $pattern;
    }

    private function summarizeToolInput(string $toolName, array $input): string
    {
        return match ($toolName) {
            'Bash' => $this->truncate($input['description'] ?? $input['command'] ?? '', 60),
            'Read' => $this->truncate(basename($input['file_path'] ?? ''), 60),
            'Edit' => $this->truncate(basename($input['file_path'] ?? ''), 60),
            'Write' => $this->truncate(basename($input['file_path'] ?? ''), 60),
            'Glob' => $this->truncate($input['pattern'] ?? '', 60),
            'Grep' => $this->truncate($input['pattern'] ?? '', 60),
            'WebFetch' => $this->truncate($input['url'] ?? '', 60),
            'TodoWrite' => count($input['todos'] ?? []).' items',
            default => $this->truncate(json_encode($input), 60),
        };
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) > $max) {
            return mb_substr($str, 0, $max - 3).'...';
        }

        return $str;
    }

    private function printUsageStats(AgentLoop $agent): void
    {
        $in = $agent->getTotalInputTokens();
        $out = $agent->getTotalOutputTokens();
        $cacheWrite = $agent->getCacheCreationTokens();
        $cacheRead = $agent->getCacheReadTokens();
        $cost = $agent->getEstimatedCost();
        $msgs = $agent->getMessageHistory()->count();

        $this->line("\n  <fg=cyan;bold>Usage:</>");
        $this->line('  Input tokens:       <fg=white>'.number_format($in).'</>');
        $this->line('  Output tokens:      <fg=white>'.number_format($out).'</>');
        if ($cacheWrite > 0) {
            $this->line('  Cache write tokens: <fg=white>'.number_format($cacheWrite).'</>');
        }
        if ($cacheRead > 0) {
            $this->line('  Cache read tokens:  <fg=white>'.number_format($cacheRead).'</>');
        }
        $this->line("  Est. cost:          <fg=white>\${$cost}</>");
        $this->line("  Messages:           <fg=white>{$msgs}</>\n");
    }

    private function formatter(): ReplFormatter
    {
        return $this->replFormatter ??= app(ReplFormatter::class);
    }

    /**
     * @param array<int, string> $lines
     */
    private function renderPanel(string $title, array $lines, string $color = 'cyan', bool $addSpacing = true): void
    {
        $this->line('');
        foreach ($this->formatter()->panel($title, $lines, $color) as $line) {
            $this->line($line);
        }
        if ($addSpacing) {
            $this->line('');
        }
    }

    private function createTurnStatusRenderer(string $input): TurnStatusRenderer
    {
        return new TurnStatusRenderer($this->output, $this->formatter(), $input);
    }

    private function createStreamingMarkdownOutput(?MarkdownRenderer $renderer = null): StreamingMarkdownOutput
    {
        return new StreamingMarkdownOutput(
            output: $this->output,
            renderer: $renderer ?? app(MarkdownRenderer::class),
        );
    }

    private function startTurnStatusTicker(TurnStatusRenderer $turnStatus): mixed
    {
        if (! $turnStatus->isEnabled() || ! function_exists('pcntl_signal') || ! function_exists('pcntl_alarm')) {
            return null;
        }

        $previousHandler = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGALRM)
            : SIG_DFL;

        pcntl_signal(SIGALRM, function () use ($turnStatus) {
            $turnStatus->tick();
            pcntl_alarm(1);
        });

        pcntl_alarm(1);

        return $previousHandler;
    }

    private function stopTurnStatusTicker(TurnStatusRenderer $turnStatus, mixed $previousHandler): void
    {
        $turnStatus->stop();

        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_alarm')) {
            return;
        }

        pcntl_alarm(0);

        if ($previousHandler === null) {
            return;
        }

        if (is_callable($previousHandler) || in_array($previousHandler, [SIG_DFL, SIG_IGN], true)) {
            pcntl_signal(SIGALRM, $previousHandler);

            return;
        }

        pcntl_signal(SIGALRM, SIG_DFL);
    }

    private function openTranscriptMode(
        AgentLoop $agent,
        string $initialQuery = '',
        bool $alreadyRaw = false,
        $inputHandle = null,
    ): void
    {
        $messages = $agent->getMessageHistory()->getMessages();
        if ($messages === []) {
            $this->line('<fg=gray>No transcript yet.</>');

            return;
        }

        $renderer = app(TranscriptRenderer::class);
        $buffer = new TranscriptBuffer($renderer->render($messages));
        $ownsHandle = $inputHandle === null;
        $handle = $inputHandle ?? fopen('php://stdin', 'r');
        if ($handle === false) {
            $this->line('<fg=red>Unable to open transcript mode.</>');

            return;
        }

        $sttyMode = '';
        if (! $alreadyRaw && function_exists('shell_exec')) {
            $sttyMode = shell_exec('stty -g 2>/dev/null') ?? '';
            if ($sttyMode !== '') {
                shell_exec('stty raw -echo 2>/dev/null');
            }
        }

        $terminal = new Terminal;
        $height = max(6, $terminal->getHeight() - 4);
        $offset = $buffer->clampOffset(max(0, $buffer->lineCount() - $height), $height);
        $query = trim($initialQuery);
        $this->lastTranscriptQuery = $query;
        $matches = $buffer->findMatches($query);
        $matchIndex = $matches === [] ? -1 : 0;

        if ($matchIndex >= 0) {
            $offset = $buffer->pageOffsetForLine($matches[$matchIndex], $height);
        }

        try {
            while (true) {
                $this->renderTranscriptScreen($buffer, $offset, $height, $query, $matches, $matchIndex);

                $char = fread($handle, 1);
                if ($char === false || $char === '') {
                    break;
                }

                if ($char === 'q' || $char === "\x03" || $char === "\x0f") {
                    break;
                }

                if ($char === '/') {
                    $query = $this->readTranscriptSearchQuery($handle, $query, $alreadyRaw || $sttyMode !== '');
                    $this->lastTranscriptQuery = $query;
                    $matches = $buffer->findMatches($query);
                    $matchIndex = $matches === [] ? -1 : 0;
                    if ($matchIndex >= 0) {
                        $offset = $buffer->pageOffsetForLine($matches[$matchIndex], $height);
                    }
                    continue;
                }

                if ($char === 'n' && $matches !== []) {
                    $matchIndex = ($matchIndex + 1) % count($matches);
                    $offset = $buffer->pageOffsetForLine($matches[$matchIndex], $height);
                    continue;
                }

                if ($char === 'N' && $matches !== []) {
                    $matchIndex = ($matchIndex - 1 + count($matches)) % count($matches);
                    $offset = $buffer->pageOffsetForLine($matches[$matchIndex], $height);
                    continue;
                }

                if ($char === 'j') {
                    $offset = $buffer->clampOffset($offset + 1, $height);
                    continue;
                }

                if ($char === 'k') {
                    $offset = $buffer->clampOffset($offset - 1, $height);
                    continue;
                }

                if ($char === ' ') {
                    $offset = $buffer->clampOffset($offset + $height, $height);
                    continue;
                }

                if ($char === 'b') {
                    $offset = $buffer->clampOffset($offset - $height, $height);
                    continue;
                }

                if ($char === 'g') {
                    $offset = 0;
                    continue;
                }

                if ($char === 'G') {
                    $offset = $buffer->clampOffset(PHP_INT_MAX, $height);
                    continue;
                }

                if ($char === "\x1b") {
                    $seq = fread($handle, 4);
                    if ($seq === false || $seq === '') {
                        break;
                    }
                    if ($seq === '[A') {
                        $offset = $buffer->clampOffset($offset - 1, $height);
                    } elseif ($seq === '[B') {
                        $offset = $buffer->clampOffset($offset + 1, $height);
                    } elseif ($seq === '[5~') {
                        $offset = $buffer->clampOffset($offset - $height, $height);
                    } elseif ($seq === '[6~') {
                        $offset = $buffer->clampOffset($offset + $height, $height);
                    } elseif ($seq === '[H' || $seq === '[1~') {
                        $offset = 0;
                    } elseif ($seq === '[F' || $seq === '[4~') {
                        $offset = $buffer->clampOffset(PHP_INT_MAX, $height);
                    }
                    continue;
                }
            }
        } finally {
            $this->writeRaw("\033[H\033[2J");
            if (! $alreadyRaw && $sttyMode !== '') {
                shell_exec("stty {$sttyMode} 2>/dev/null");
            }
            if ($ownsHandle) {
                fclose($handle);
            }
        }
    }

    private function renderTranscriptScreen(
        TranscriptBuffer $buffer,
        int $offset,
        int $height,
        string $query,
        array $matches,
        int $matchIndex,
    ): void {
        $page = $buffer->slice($offset, $height);
        $this->writeRaw("\033[H\033[2J");
        $this->line("<fg=cyan;bold>Transcript</> <fg=gray>↑↓/j/k move · PgUp/PgDn/space/b page · g/G ends · / search · n/N nav · q exit</>");

        foreach ($page as $line) {
            $this->line($buffer->highlight($line, $query));
        }

        $remaining = max(0, $height - count($page));
        for ($index = 0; $index < $remaining; $index++) {
            $this->line('');
        }

        $position = $buffer->lineCount() === 0 ? 0 : min($buffer->lineCount(), $offset + 1);
        $count = count($matches);
        $current = $count === 0 || $matchIndex < 0 ? 0 : $matchIndex + 1;
        $status = ($query !== '' && $count === 0) ? 'no matches' : null;

        $this->line($this->formatter()->transcriptFooter(
            line: $position,
            totalLines: $buffer->lineCount(),
            query: $query === '' ? null : $query,
            currentMatch: $current,
            matchCount: $count,
            status: $status,
        ));
    }

    private function readTranscriptSearchQuery($handle, string $initialQuery, bool $rawMode): string
    {
        $query = $initialQuery;

        while (true) {
            $this->writeRaw("\033[999;1H\033[2K");
            $this->output->write("<fg=yellow>/</><fg=gray>{$query}</>", false);

            $char = fread($handle, 1);
            if ($char === false || $char === '') {
                return trim($query);
            }

            if ($char === "\r" || $char === "\n") {
                return trim($query);
            }

            if ($char === "\x03" || $char === "\x07" || $char === "\x1b") {
                return trim($initialQuery);
            }

            if ($char === "\x7f" || $char === "\x08") {
                $query = mb_substr($query, 0, max(0, mb_strlen($query) - 1));
                continue;
            }

            if (! $rawMode && ord($char) < 32) {
                continue;
            }

            if (ord($char) >= 32 || $char === "\t") {
                $query .= $char;
            }
        }
    }

    private function redrawRawPrompt(string $cwd, string $currentLine): void
    {
        $this->writeRaw("\033[H\033[2J");
        $this->output->write($this->formatter()->prompt($cwd));
        $this->writeRaw($currentLine);
    }

    private function redrawRawInputScreen(AgentLoop $agent, string $cwd, string $currentLine): void
    {
        $this->writeRaw("\033[H\033[2J");
        $this->renderPromptFooter($agent);
        $this->output->write($this->formatter()->prompt($cwd));
        $this->writeRaw($currentLine);
    }

    private function renderPromptFooter(AgentLoop $agent): void
    {
        $settings = app(SettingsManager::class);
        $this->line($this->formatter()->promptFooter(
            model: $settings->getModel(),
            messageCount: $agent->getMessageHistory()->count(),
            permissionMode: $settings->getPermissionMode()->value,
            fastMode: $this->fastMode,
            title: $agent->getSessionManager()->getTitle(),
        ));
    }

    private function readReverseHistorySearch($handle, array $history, string $currentLine, string $cwd): ?string
    {
        $query = '';
        $original = $currentLine;
        $matches = $this->findHistoryMatches($history, $query, $original);
        $selectedIndex = 0;

        while (true) {
            $matches = $this->findHistoryMatches($history, $query, $original);
            $match = $matches[$selectedIndex] ?? $original;
            $this->writeRaw("\r\033[2K");
            $this->output->write($this->formatter()->reverseSearchStatus(
                query: $query,
                match: $match,
                current: count($matches) === 0 ? 0 : $selectedIndex + 1,
                total: count($matches),
            ), false);

            $char = fread($handle, 1);
            if ($char === false || $char === '') {
                $this->writeRaw("\r\033[2K");
                $this->output->write($this->formatter()->prompt($cwd));
                $this->writeRaw($original);

                return $original;
            }

            if ($char === "\r" || $char === "\n") {
                $accepted = $matches[$selectedIndex] ?? $original;
                $this->writeRaw("\r\033[2K");
                $this->output->write($this->formatter()->prompt($cwd));
                $this->writeRaw($accepted);

                return $accepted;
            }

            if ($char === "\x03" || $char === "\x1b") {
                $this->writeRaw("\r\033[2K");
                $this->output->write($this->formatter()->prompt($cwd));
                $this->writeRaw($original);

                return $original;
            }

            if ($char === "\x12") {
                if ($matches !== []) {
                    $selectedIndex = ($selectedIndex + 1) % count($matches);
                }
                continue;
            }

            if ($char === "\x7f" || $char === "\x08") {
                $query = mb_substr($query, 0, max(0, mb_strlen($query) - 1));
                $selectedIndex = 0;
                continue;
            }

            if (ord($char) >= 32 || $char === "\t") {
                $query .= $char;
                $selectedIndex = 0;
            }
        }
    }

    private function findHistoryMatch(array $history, string $query, string $fallback = ''): ?string
    {
        return $this->findHistoryMatches($history, $query, $fallback)[0] ?? null;
    }

    private function findHistoryMatches(array $history, string $query, string $fallback = ''): array
    {
        $query = trim($query);
        $candidates = array_values(array_reverse($history));

        if ($query === '') {
            return array_values(array_filter(
                $candidates,
                static fn (string $candidate): bool => $candidate !== $fallback,
            ));
        }

        return array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => mb_stripos($candidate, $query) !== false,
        ));
    }

    private function writeRaw(string $text): void
    {
        $this->output->write($text, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
    }
}
