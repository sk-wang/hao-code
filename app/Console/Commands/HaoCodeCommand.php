<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentLoop;
use App\Services\Compact\ContextCompactor;
use App\Services\Settings\SettingsManager;
use App\Tools\Bash\BashTool;
use Illuminate\Console\Command;

class HaoCodeCommand extends Command
{
    protected $signature = 'hao-code
        {--prompt= : Non-interactive mode: execute a single prompt}
        {--model= : Override the default model}
        {--permission-mode= : Override the permission mode}
    ';

    protected $description = 'Hao Code - Interactive CLI Coding Agent';

    private bool $shouldExit = false;

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
                $this->line("\n<fg=yellow>Aborting... (Ctrl+C again to force exit)</>");
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
        $this->line("\n  <fg=cyan;bold>╔═══════════════════════════════════════╗</>");
        $this->line(  "  <fg=cyan;bold>║</> <fg=white;bold>Hao Code</> <fg=gray>CLI Coding Agent</>           <fg=cyan;bold>║</>");
        $this->line(  "  <fg=cyan;bold>║</> <fg=gray>PHP " . PHP_VERSION . " · Laravel Framework</>    <fg=cyan;bold>║</>");
        $this->line(  "  <fg=cyan;bold>╚═══════════════════════════════════════╝</>");
        $this->line(  "  <fg=gray>Type '/help' for commands, '/exit' to quit</>\n");
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

        while (!$this->shouldExit) {
            $input = $this->readInput($history, $historyPtr);

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
            @file_put_contents($historyFile, implode("\n", $history));

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
        $response = $agent->run(
            userInput: $prompt,
            onTextDelta: fn(string $text) => $this->output->write($text),
        );

        $this->line($response);
        $this->printUsageStats($agent);
        return 0;
    }

    private function readInput(array &$history, int &$historyPtr): ?string
    {
        $cwd = basename(getcwd());
        $this->output->write("<fg=green>{$cwd}</> <fg=cyan>❯</> ");

        $handle = fopen('php://stdin', 'r');

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
                    if ($sttyMode) echo "\r\n";

                    // Check for backslash continuation
                    if (str_ends_with(rtrim($currentLine), '\\')) {
                        $currentLine = rtrim($currentLine, ' \\');
                        $lines[] = $currentLine;
                        $currentLine = '';
                        $this->output->write("<fg=gray>…</> ");
                        continue;
                    }

                    $lines[] = $currentLine;
                    break;
                }

                if ($char === "\x03") { // Ctrl+C
                    if ($sttyMode) echo "\r\n";
                    return null;
                }

                if ($char === "\x04") { // Ctrl+D
                    if ($sttyMode) echo "\r\n";
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
                            echo $prompt . $history[$historyPtr];
                            $currentLine = $history[$historyPtr];
                        }
                        continue;
                    }
                    if ($seq === '[B') { // Down arrow
                        if ($historyPtr < count($history) - 1) {
                            $historyPtr++;
                            echo "\r\033[K";
                            $prompt = "\e[32m{$cwd}\e[0m \e[36m❯\e[0m ";
                            echo $prompt . $history[$historyPtr];
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

        $fullInput = implode("\n", $lines);
        return trim($fullInput) !== '' ? trim($fullInput) : '';
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
            '/tasks' => $this->handleTasks(),
            '/resume' => $this->handleResume($agent, $args),
            '/diff' => $this->handleDiff(),
            '/memory' => $this->handleMemory($args),
            '/rewind' => $this->handleRewind(),
            '/context' => $this->handleContext($agent),
            '/doctor' => $this->handleDoctor(),
            '/theme' => $this->handleTheme($args),
            '/skills' => $this->handleSkills(),
            default => $this->line("<fg=yellow>Unknown command: {$command}</>. Type <fg=cyan>/help</> for available commands."),
        };
    }

    private function handleExit(): void
    {
        $this->line("<fg=gray>Goodbye!</>");
        $this->shouldExit = true;
    }

    private function handleHelp(): void
    {
        $this->line("\n  <fg=cyan;bold>Available Commands:</>");
        $this->line("  <fg=green>/help</>      Show this help message");
        $this->line("  <fg=green>/exit</>      Exit the REPL");
        $this->line("  <fg=green>/clear</>     Clear conversation history");
        $this->line("  <fg=green>/compact</>   Compact conversation context");
        $this->line("  <fg=green>/cost</>       Show token usage and cost");
        $this->line("  <fg=green>/history</>   Show message count");
        $this->line("  <fg=green>/model</>     Show/set current model");
        $this->line("  <fg=green>/status</>    Show session status");
        $this->line("  <fg=green>/tasks</>     List background tasks");
        $this->line("  <fg=green>/resume</>    Resume a previous session");
        $this->line("  <fg=green>/diff</>      Show uncommitted changes");
        $this->line("  <fg=green>/memory</>    View/edit session memory");
        $this->line("  <fg=green>/context</>   Show context usage");
        $this->line("  <fg=green>/rewind</>    Undo last change");
        $this->line("  <fg=green>/doctor</>    Run diagnostics");
        $this->line("  <fg=green>/skills</>    List available skills");
        $this->line("  <fg=green>/theme</>     Toggle color theme\n");
    }

    private function handleClear(AgentLoop $agent): void
    {
        $agent->getMessageHistory()->clear();
        $this->line("<fg=gray>Conversation history cleared.</>");
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
            $this->line("<fg=gray>No background tasks running.</>");
            return;
        }

        $this->line("\n  <fg=cyan;bold>Background Tasks:</>");
        foreach ($tasks as $id => $task) {
            $elapsed = round(microtime(true) - $task['startTime'], 1);
            $this->line("  <fg=yellow>{$id}</> PID:<fg=white>{$task['pid']}</> <fg=gray>({$elapsed}s)</> <fg=gray>{$this->truncate($task['command'], 50)}</>");
        }
        $this->line('');
    }

    private function handleResume(AgentLoop $agent, string $args): void
    {
        $args = trim($args);

        if ($args === 'list' || $args === '') {
            $this->listSessions();
            return;
        }

        $sessionPath = config('haocode.session_path', storage_path('app/haocode/sessions'));

        // Find session file matching the partial ID
        $pattern = $sessionPath . '/' . $args . '*.jsonl';
        $files = glob($pattern);

        if (empty($files)) {
            // Try partial match
            $pattern = $sessionPath . '/*' . $args . '*.jsonl';
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
            $this->line("<fg=red>Session is empty.</>");
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
                if (!empty($entry['tool_results'])) {
                    $history->addToolResultMessage($entry['tool_results']);
                }
            }
        }

        $sessionId = basename($file, '.jsonl');
        $this->line("<fg=green>Resumed session:</> <fg=white>{$sessionId}</> ({$restored} messages restored)");
    }

    private function listSessions(): void
    {
        $sessionPath = config('haocode.session_path', storage_path('app/haocode/sessions'));

        if (!is_dir($sessionPath)) {
            $this->line("<fg=gray>No sessions found.</>");
            return;
        }

        $files = glob($sessionPath . '/*.jsonl');

        if (empty($files)) {
            $this->line("<fg=gray>No sessions found.</>");
            return;
        }

        // Sort by modification time, newest first
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $this->line("\n  <fg=cyan;bold>Recent Sessions:</>");
        $count = 0;
        foreach ($files as $file) {
            if ($count++ >= 10) break;
            $id = basename($file, '.jsonl');
            $lines = count(file($file));
            $time = date('Y-m-d H:i', filemtime($file));
            $this->line("  <fg=yellow>{$id}</> <fg=gray>{$time} · {$lines} entries</>");
        }
        $this->line("\n  <fg=gray>Use /resume <session_id> to restore a session</>");
    }

    private function handleModel(string $args = ''): void
    {
        $settings = app(SettingsManager::class);
        $args = trim($args);

        if ($args === '') {
            $this->line("<fg=gray>Current model: <fg=white>{$settings->getModel()}</></>");
            $available = SettingsManager::getAvailableModels();
            $this->line("<fg=gray>Available: " . implode(', ', $available) . "</>");
            return;
        }

        $available = SettingsManager::getAvailableModels();
        if (in_array($args, $available)) {
            $settings->set('model', $args);
            $this->line("<fg=green>Model set to:</> <fg=white>{$args}</>");
        } else {
            $this->line("<fg=red>Unknown model: {$args}</>");
            $this->line("<fg=gray>Available: " . implode(', ', $available) . "</>");
        }
    }

    private function handleDiff(): void
    {
        $output = shell_exec('git diff --stat 2>/dev/null');
        if (empty(trim($output ?? ''))) {
            $this->line("<fg=gray>No uncommitted changes.</>");
            return;
        }
        $this->line("\n  <fg=cyan;bold>Uncommitted Changes:</>");
        $this->line($output);

        // Show full diff if small enough
        $fullDiff = shell_exec('git diff 2>/dev/null');
        if ($fullDiff && mb_strlen($fullDiff) < 5000) {
            $this->line($fullDiff);
        } else {
            $this->line("  <fg=gray>Use git diff to see the full changes.</>");
        }
    }

    private function handleMemory(string $args): void
    {
        $args = trim($args);
        /** @var \App\Services\Memory\SessionMemory $memory */
        $memory = app(\App\Services\Memory\SessionMemory::class);

        if ($args === '' || $args === 'list') {
            $memories = $memory->list();
            if (empty($memories)) {
                $this->line("<fg=gray>No memories stored. Use: /memory set <key> <value></>");
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
                $this->line("<fg=red>Usage: /memory set <key> <value></>");
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

        $this->line("<fg=yellow>Usage:</> /memory [list|set <key> <value>|delete <key>|search <query>]");
    }

    private function handleRewind(): void
    {
        // Undo the last conversation turn by popping the last user + assistant messages
        $history = app(\App\Services\Agent\MessageHistory::class);
        $messages = $history->getMessagesForApi();
        $count = count($messages);

        if ($count < 2) {
            $this->line("<fg=gray>Nothing to rewind.</>");
            return;
        }

        // Remove the last assistant message and its preceding tool results/user message
        $removed = 0;
        $popped = array_pop($messages); // assistant
        $removed++;

        // Pop tool result messages (user role with tool_result blocks)
        while (!empty($messages)) {
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
        if (!empty($messages) && ($messages[count($messages) - 1]['role'] ?? '') === 'user') {
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
                        array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_result')
                    );
                }
            } elseif ($role === 'assistant') {
                $history->addAssistantMessage($msg);
            }
        }

        $this->line("<fg=green>Rewound:</> removed {$removed} messages. History now has " . count($messages) . " messages.");
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
        $this->line("  Input tokens: <fg=white>" . number_format($in) . "</> / " . number_format($contextLimit));
        $this->line("  Output tokens:<fg=white>" . number_format($out) . "</>");

        // Visual bar
        $barWidth = 40;
        $filled = (int) round(($usagePercent / 100) * $barWidth);
        $filled = min($filled, $barWidth);
        $bar = str_repeat('█', $filled) . str_repeat('░', $barWidth - $filled);

        $color = $usagePercent < 50 ? 'green' : ($usagePercent < 80 ? 'yellow' : 'red');
        $this->line("  <fg={$color}>{$bar}</> {$usagePercent}%");

        if ($usagePercent > 70) {
            $this->line("  <fg=yellow>⚠ Context is getting large. Consider using /compact to reduce it.</>");
        }
        $this->line('');
    }

    private function handleStatus(AgentLoop $agent): void
    {
        $settings = app(SettingsManager::class);
        $costTracker = app(\App\Services\Cost\CostTracker::class);
        $this->line("\n  <fg=cyan;bold>Session Status:</>");
        $this->line("  Session: <fg=white>{$agent->getSessionManager()->getSessionId()}</>");
        $this->line("  Model: <fg=white>{$settings->getModel()}</>");
        $this->line("  Messages: <fg=white>{$agent->getMessageHistory()->count()}</>");
        $this->line("  Permission mode: <fg=white>{$settings->getPermissionMode()->value}</>");
        $this->line("  " . $costTracker->getSummary());
        $this->printUsageStats($agent);
    }

    private function handleDoctor(): void
    {
        $this->line("\n  <fg=cyan;bold>Running Diagnostics...</>\n");

        $checks = [
            ['PHP Version', PHP_VERSION, true],
            ['PHP CLI', PHP_SAPI === 'cli' ? 'OK' : 'Not CLI (' . PHP_SAPI . ')', PHP_SAPI === 'cli'],
            ['pcntl extension', extension_loaded('pcntl') ? 'Available' : 'Not available', extension_loaded('pcntl')],
            ['posix extension', extension_loaded('posix') ? 'Available' : 'Not available', extension_loaded('posix')],
            ['curl extension', extension_loaded('curl') ? 'Available' : 'Not available', extension_loaded('curl')],
            ['json extension', extension_loaded('json') ? 'Available' : 'Not available', extension_loaded('json')],
            ['mbstring extension', extension_loaded('mbstring') ? 'Available' : 'Not available', extension_loaded('mbstring')],
        ];

        // Check API key
        $settings = app(SettingsManager::class);
        $apiKey = $settings->getApiKey();
        $checks[] = ['API Key', $apiKey ? 'Configured (' . mb_substr($apiKey, 0, 10) . '...)' : 'NOT SET', !empty($apiKey)];

        // Check API base URL
        $baseUrl = $settings->getBaseUrl();
        $checks[] = ['API Base URL', $baseUrl ?: 'Not set (using default)', true];

        // Check git
        $gitVersion = shell_exec('git --version 2>/dev/null');
        $checks[] = ['Git', trim($gitVersion ?? 'Not found'), !empty($gitVersion)];

        // Check rg (ripgrep)
        $rgVersion = shell_exec('rg --version 2>/dev/null');
        $checks[] = ['ripgrep', $rgVersion ? trim(explode("\n", $rgVersion)[0]) : 'Not installed', !empty($rgVersion)];

        // Check session storage
        $sessionPath = storage_path('app/haocode/sessions');
        $checks[] = ['Session Storage', is_dir($sessionPath) ? 'OK' : 'Not created yet', true];

        // Check settings files
        $home = $_SERVER['HOME'] ?? '~';
        $globalSettings = "{$home}/.haocode/settings.json";
        $checks[] = ['Global Settings', file_exists($globalSettings) ? $globalSettings : 'Not found', true];

        foreach ($checks as [$label, $value, $ok]) {
            $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$icon} <fg=white>{$label}:</> <fg=gray>{$value}</>");
        }

        $toolCount = count(app(\App\Tools\ToolRegistry::class)->getAllTools());
        $this->line("  <fg=green>✓</> <fg=white>Tools:</> <fg=gray>{$toolCount} registered</>");

        $allOk = count(array_filter($checks, fn($c) => !$c[2])) === 0;
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
            $this->line("\n  <fg=cyan;bold>Themes:</>");
            $this->line("  <fg=green>dark</>     Default dark theme (current)");
            $this->line("  <fg=green>light</>    Light terminal theme");
            $this->line("  <fg=green>ansi</>     Basic ANSI (no truecolor)");
            $this->line("\n  <fg=gray>Use /theme <name> to switch</>");
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
        /** @var \App\Tools\Skill\SkillLoader $skillLoader */
        $skillLoader = app(\App\Tools\Skill\SkillLoader::class);
        $skills = $skillLoader->listSkills();

        if (empty($skills)) {
            $this->line("<fg=gray>No skills found. Create skills in ~/.haocode/skills/ or .haocode/skills/</>");
            return;
        }

        $this->line("\n  <fg=cyan;bold>Available Skills:</>");
        foreach ($skills as $skill) {
            $name = $skill['name'] ?? 'unknown';
            $desc = $skill['description'] ?? '';
            $userInvocable = ($skill['user_invocable'] ?? false) ? '<fg=green>/</>' : '<fg=gray>auto</>';
            $this->line("  {$userInvocable} <fg=yellow>{$name}</> <fg=gray>{$desc}</>");
        }
        $this->line('');
    }

    private function runAgentTurn(AgentLoop $agent, string $input): void
    {
        $this->line('');

        try {
            $response = $agent->run(
                userInput: $input,
                onTextDelta: function (string $text) {
                    $this->output->write($text);
                },
                onToolStart: function (string $toolName, array $toolInput) {
                    $args = $this->summarizeToolInput($toolName, $toolInput);
                    $this->line("\n  <fg=magenta>⚙ {$toolName}</><fg=gray>({$args})</>");
                },
                onToolComplete: function (string $toolName, $result) {
                    $status = $result->isError ? '<fg=red>✗</>' : '<fg=green>✓</>';
                    $this->line(" {$status}");
                },
            );

            $this->line("\n");

            // Show cost after each turn
            $cost = $agent->getEstimatedCost();
            $inTokens = $agent->getTotalInputTokens();
            $outTokens = $agent->getTotalOutputTokens();
            $cacheRead = $agent->getCacheReadTokens();
            $this->line("<fg=gray>  [{$inTokens}in/{$outTokens}out" . ($cacheRead > 0 ? "/{$cacheRead}cache" : '') . " tokens · \${$cost}]</>");

        } catch (\App\Services\Api\ApiErrorException $e) {
            $this->line("\n  <fg=red>API Error ({$e->getErrorType()}): {$e->getMessage()}</>\n");
        } catch (\Throwable $e) {
            $this->line("\n  <fg=red>Error: {$e->getMessage()}</>\n");
            if (config('app.debug')) {
                $this->line("  <fg=gray>{$e->getFile()}:{$e->getLine()}</>\n");
            }
        }
    }

    private function promptToolPermission(string $toolName, array $input): bool
    {
        $args = $this->summarizeToolInput($toolName, $input);

        $this->line("\n  <fg=yellow>⚡ Tool permission required:</> <fg=white>{$toolName}</>(<fg=gray>{$args}</>)");
        $this->line("  <fg=green>[y]</> Yes (this time)  <fg=green>[a]</> Always for session  <fg=red>[n]</> No");

        $handle = fopen('php://stdin', 'r');
        $answer = strtolower(trim(fgets($handle)));
        fclose($handle);

        return in_array($answer, ['y', 'yes', 'a', 'always', '']);
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
            'TodoWrite' => count($input['todos'] ?? []) . ' items',
            default => $this->truncate(json_encode($input), 60),
        };
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) > $max) {
            return mb_substr($str, 0, $max - 3) . '...';
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
        $this->line("  Input tokens:       <fg=white>" . number_format($in) . "</>");
        $this->line("  Output tokens:      <fg=white>" . number_format($out) . "</>");
        if ($cacheWrite > 0) {
            $this->line("  Cache write tokens: <fg=white>" . number_format($cacheWrite) . "</>");
        }
        if ($cacheRead > 0) {
            $this->line("  Cache read tokens:  <fg=white>" . number_format($cacheRead) . "</>");
        }
        $this->line("  Est. cost:          <fg=white>\${$cost}</>");
        $this->line("  Messages:           <fg=white>{$msgs}</>\n");
    }
}
