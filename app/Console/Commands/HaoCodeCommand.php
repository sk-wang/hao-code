<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\MessageHistory;
use App\Services\Buddy\BuddyManager;
use App\Services\Api\ApiErrorException;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Git\GitContext;
use App\Services\Hooks\HookExecutor;
use App\Services\Mcp\McpConnectionManager;
use App\Services\Mcp\McpServerConfigManager;
use App\Services\Memory\SessionMemory;
use App\Services\OutputStyle\OutputStyleLoader;
use App\Services\Permissions\PermissionMode;
use App\Services\Session\AwaySummaryService;
use App\Services\Session\SessionManager;
use App\Services\Session\SessionStatsService;
use App\Services\Session\SessionTitleService;
use App\Services\Settings\SettingsManager;
use App\Services\Task\TaskManager;
use App\Support\Terminal\Autocomplete\AutocompleteEngine;
use App\Support\Terminal\DraftInputBuffer;
use App\Support\Terminal\DockedPromptScreen;
use App\Support\Terminal\Autocomplete\SlashCommandCatalog;
use App\Support\Terminal\InputSanitizer;
use App\Support\Terminal\MarkdownRenderer;
use App\Support\Terminal\PromptHudState;
use App\Support\Terminal\ReplFormatter;
use App\Support\Terminal\StreamingMarkdownOutput;
use App\Support\Terminal\TranscriptBuffer;
use App\Support\Terminal\TranscriptRenderer;
use App\Support\Terminal\ImagePaste;
use App\Support\Terminal\ToolResultRenderer;
use App\Support\Terminal\TurnStatusRenderer;
use App\Tools\Bash\BashTool;
use App\Tools\Config\ConfigTool;
use App\Tools\Mcp\McpDynamicTool;
use App\Tools\Skill\SkillLoader;
use App\Tools\ToolRegistry;
use App\Tools\ToolUseContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;

class HaoCodeCommand extends Command
{
    protected $signature = 'hao-code
        {promptText? : Optional prompt text for print mode}
        {--prompt= : Deprecated alias for --print}
        {--p|print= : Print response and exit}
        {--c|continue : Continue the most recent conversation}
        {--r|resume= : Resume a saved session by ID}
        {--fork-session : Fork into a new session when resuming or continuing}
        {--name= : Set a display name for this session}
        {--system-prompt= : Replace the default system prompt for this session}
        {--append-system-prompt= : Append extra system prompt instructions for this session}
        {--model= : Override the default model}
        {--permission-mode= : Override the permission mode}
    ';

    protected $description = 'Hao Code - Interactive CLI Coding Agent';

    private bool $shouldExit = false;

    private bool $fastMode = false;

    private bool $titleGenerated = false;

    private ?ReplFormatter $replFormatter = null;

    private ?DockedPromptScreen $dockedPromptScreen = null;

    private string $lastTranscriptQuery = '';

    /** @var array<int, string> */
    private array $sessionAllowRules = [];

    private ?string $previousPermissionMode = null;

    public function handle(): int
    {
        $settings = app(SettingsManager::class);
        $this->applyStartupOverrides($settings);

        if (empty($settings->getApiKey())) {
            $this->error('ANTHROPIC_API_KEY is not set. Please set it in your environment, ~/.haocode/settings.json, or .haocode/settings.json');

            return 1;
        }

        $prompt = $this->resolveStartupPrompt();

        if ($prompt === null) {
            $this->printBanner();
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

        if (! $this->initializeSessionFromStartupOptions($agent, $prompt !== null)) {
            return 1;
        }

        $name = trim((string) ($this->option('name') ?? ''));
        if ($name !== '') {
            $agent->getSessionManager()->setTitle($name);
        }

        $this->refreshPromptHudState($agent);
        $this->connectMcpServers();

        if ($prompt !== null) {
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

    private function applyStartupOverrides(SettingsManager $settings): void
    {
        $model = $this->option('model');
        if (is_string($model) && trim($model) !== '') {
            $settings->set('model', trim($model));
        }

        $permissionMode = $this->option('permission-mode');
        if (is_string($permissionMode) && trim($permissionMode) !== '') {
            $settings->set('permission_mode', trim($permissionMode));
        }

        $systemPrompt = $this->option('system-prompt');
        if (is_string($systemPrompt) && trim($systemPrompt) !== '') {
            $settings->set('system_prompt', $systemPrompt);
        }

        $appendSystemPrompt = $this->option('append-system-prompt');
        if (is_string($appendSystemPrompt) && trim($appendSystemPrompt) !== '') {
            $settings->set('append_system_prompt', $appendSystemPrompt);
        }
    }

    private function resolveStartupPrompt(): ?string
    {
        return $this->chooseStartupPrompt(
            $this->option('print'),
            $this->option('prompt'),
            $this->argument('promptText'),
        );
    }

    private function runRepl(AgentLoop $agent): int
    {
        // Load input history
        $historyFile = storage_path('app/haocode/input_history.json');
        $legacyHistoryFile = storage_path('app/haocode/input_history');
        $readlineHistoryFile = storage_path('app/haocode/input_history.readline');
        $history = $this->loadInputHistory($historyFile, $legacyHistoryFile);
        $historyPtr = count($history);

        if ($this->supportsReadline()) {
            $nativeReadlineHistoryFile = file_exists($readlineHistoryFile) ? $readlineHistoryFile : $legacyHistoryFile;
            if (file_exists($nativeReadlineHistoryFile)) {
                @readline_read_history($nativeReadlineHistoryFile);
            }
        }

        // Register readline tab completion for slash commands and @file paths
        if ($this->supportsReadline()) {
            readline_completion_function(function (string $input, int $index): array {
                $engine = app(AutocompleteEngine::class);
                $suggestions = $engine->getSuggestions($input);

                return array_map(fn (array $s) => $s['label'], $suggestions);
            });
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
            $this->saveInputHistory($historyFile, $history);

            if ($this->supportsReadline()) {
                if (! str_contains($input, "\n")) {
                    readline_add_history($input);
                    @readline_write_history($readlineHistoryFile);
                }
            }

            // Handle slash commands
            if ($this->shouldHandleSlashCommand($input)) {
                $this->handleSlashCommand($input, $agent);

                continue;
            }

            // Process input: detect and attach pasted images
            $processedInput = $this->processUserInputForImages($input);
            $this->runAgentTurn($agent, $processedInput);
        }

        // Clean up MCP connections
        app(McpConnectionManager::class)->disconnectAll();

        return 0;
    }

    private function shouldHandleSlashCommand(string $input): bool
    {
        return ! str_contains($input, "\n")
            && str_starts_with($input, '/');
    }

    /**
     * Process user input text to detect and attach images.
     *
     * Handles:
     * 1. Pasted image file paths (e.g., /path/to/screenshot.png)
     * 2. Clipboard images (when input is empty-ish but clipboard has an image)
     *
     * @return string|array String for text-only, array of content blocks for text+images
     */
    private function processUserInputForImages(string $input): string|array
    {
        $images = [];
        $textParts = [];

        // 1. Check for image file paths in the input
        $imagePaths = ImagePaste::extractImagePaths($input);

        if (!empty($imagePaths)) {
            // Remove image paths from the text
            $remainingText = $input;
            foreach ($imagePaths as $path) {
                $remainingText = str_replace($path, '', $remainingText);
            }
            $remainingText = trim(preg_replace('/\s+/', ' ', $remainingText));

            // Read each image file
            foreach ($imagePaths as $path) {
                $imageData = ImagePaste::readImageFile($path);
                if ($imageData !== null) {
                    $images[] = $imageData;
                    $this->line("  <fg=cyan>📎 Attached image:</> <fg=gray>" . basename($path) . " (" . $imageData['media_type'] . ")</>");
                } else {
                    $this->line("  <fg=yellow>⚠ Could not read image:</> <fg=gray>{$path}</>");
                }
            }

            if ($remainingText !== '') {
                $textParts[] = $remainingText;
            }
        } else {
            $textParts[] = $input;
        }

        // 2. If no text and no images, check clipboard for an image
        // This handles Cmd+V / Ctrl+V paste of a screenshot
        if (empty($images) && trim($input) === '' ) {
            // Don't check clipboard on empty input - that's just an Enter press
            return $input;
        }

        // 3. Build the result
        if (empty($images)) {
            return $input; // No images found, return as plain text
        }

        // Build multi-content-block message
        $contentBlocks = [];

        // Add text block first (if any)
        $text = implode(' ', $textParts);
        if ($text !== '') {
            $contentBlocks[] = ['type' => 'text', 'text' => $text];
        } else {
            // API requires at least one text block
            $imageNames = array_map(fn($img) => $img['source'], $images);
            $contentBlocks[] = ['type' => 'text', 'text' => 'Here ' . (count($images) === 1 ? 'is an image' : 'are ' . count($images) . ' images') . ': ' . implode(', ', $imageNames)];
        }

        // Add image blocks
        foreach ($images as $img) {
            $contentBlocks[] = ImagePaste::buildImageBlock($img['base64'], $img['media_type']);
        }

        return $contentBlocks;
    }

    private function runSinglePrompt(AgentLoop $agent, string $prompt): int
    {
        $streamTextOutput = $this->shouldStreamAssistantText();
        $renderedLiveText = false;
        $markdownRenderer = app(MarkdownRenderer::class);
        $markdownOutput = $this->createStreamingMarkdownOutput($markdownRenderer);
        $turnStatus = $this->createTurnStatusRenderer($prompt);
        $previousAlarmHandler = $this->startTurnStatusTicker($turnStatus);
        $toolResultRenderer = new ToolResultRenderer();
        $lastToolInput = [];

        try {
            $this->recordTurnHudEvent('turn.started', $this->summarizeTurnDetail($prompt));
            $turnStatus->start();

            $response = $agent->run(
                userInput: $prompt,
                onTextDelta: function (string $text) use (&$renderedLiveText, $turnStatus, $markdownOutput, $streamTextOutput) {
                    $turnStatus->recordTextDelta($text);

                    if (! $streamTextOutput) {
                        return;
                    }

                    $turnStatus->pause();
                    $renderedLiveText = true;
                    $markdownOutput->append($text);
                },
                onToolStart: function (string $toolName, array $toolInput) use ($turnStatus, $markdownOutput, $streamTextOutput, &$lastToolInput) {
                    $lastToolInput = ['name' => $toolName, 'input' => $toolInput];
                    $turnStatus->pause();
                    if ($streamTextOutput) {
                        $markdownOutput->finalize();
                    }
                    $args = $this->summarizeToolInput($toolName, $toolInput);
                    $this->recordTurnHudEvent('tool.started', $this->summarizeTurnDetail(trim($toolName.($args !== '' ? ': '.$args : ''))));
                    $activityDesc = $this->getActivityDescription($toolName, $toolInput);
                    $this->line("\n".$this->formatter()->toolCall($toolName, $args));
                    $turnStatus->setPhaseLabel($activityDesc ?? $toolName);
                    $turnStatus->resume();
                },
                onToolComplete: function (string $toolName, $result) use ($turnStatus, $toolResultRenderer, &$lastToolInput) {
                    $turnStatus->pause();
                    $turnStatus->setPhaseLabel(null);
                    $event = in_array($toolName, ['TodoWrite', 'TaskCreate', 'TaskUpdate', 'TaskStop'], true)
                        ? 'plan.updated'
                        : 'tool.completed';
                    $detail = $toolName;
                    $input = ($lastToolInput['name'] ?? '') === $toolName ? ($lastToolInput['input'] ?? []) : [];
                    $rendered = $toolResultRenderer->render($toolName, $input, (string) $result->output, $result->isError);
                    if ($rendered !== null) {
                        $this->line($rendered);
                    } elseif ($result->isError) {
                        $message = trim((string) $result->output);
                        $detail = trim($toolName.' · '.($message === '' ? 'Unknown error' : $message));
                        $this->line($this->formatter()->toolFailure($toolName, $message === '' ? 'Unknown error' : $message));
                    }
                    $this->recordTurnHudEvent($event, $this->summarizeTurnDetail($detail));
                    $turnStatus->resume();
                },
            );

            $turnStatus->pause();
            if ($streamTextOutput) {
                $markdownOutput->finalize();
            }
            if ($response === '(aborted)') {
                $this->recordTurnHudEvent('turn.failed', 'aborted');
                if ($renderedLiveText) {
                    $this->line('');
                }
                $this->line($this->formatter()->interruptedStatus());
                return 130;
            }
            $this->recordTurnHudEvent('turn.completed', $this->summarizeTurnDetail($response));
            if (! $renderedLiveText && $response !== '') {
                $this->line($markdownRenderer->render($response));
            } elseif ($renderedLiveText) {
                $this->line('');
            }
            $this->printUsageStats($agent);
        } catch (ApiErrorException $e) {
            $turnStatus->pause();
            if ($streamTextOutput) {
                $markdownOutput->finalize();
            }
            if ($agent->isAborted()) {
                $this->recordTurnHudEvent('turn.failed', 'aborted');
                if ($renderedLiveText) {
                    $this->line('');
                }
                $this->line($this->formatter()->interruptedStatus());
                return 130;
            }
            $this->recordTurnHudEvent('turn.failed', $this->summarizeTurnDetail($e->getMessage()));
            if ($renderedLiveText) {
                $this->line('');
            }
            $this->line("  <fg=red>API Error ({$e->getErrorType()}): {$e->getMessage()}</>");
            return 1;
        } catch (\Throwable $e) {
            $turnStatus->pause();
            if ($streamTextOutput) {
                $markdownOutput->finalize();
            }
            if ($agent->isAborted()) {
                $this->recordTurnHudEvent('turn.failed', 'aborted');
                if ($renderedLiveText) {
                    $this->line('');
                }
                $this->line($this->formatter()->interruptedStatus());
                return 130;
            }
            $this->recordTurnHudEvent('turn.failed', $this->summarizeTurnDetail($e->getMessage()));
            if ($renderedLiveText) {
                $this->line('');
            }
            $this->line("  <fg=red>Error: {$e->getMessage()}</>");
            if (config('app.debug')) {
                $this->line("  <fg=gray>{$e->getFile()}:{$e->getLine()}</>");
            }
            return 1;
        } finally {
            $this->stopTurnStatusTicker($turnStatus, $previousAlarmHandler);
            $this->refreshPromptHudState($agent);
        }

        return 0;
    }

    private function readInput(AgentLoop $agent, array &$history, int &$historyPtr): ?string
    {
        if ($this->supportsRawInput()) {
            return $this->readInputRaw($agent, $history, $historyPtr, useDockedHud: true);
        }

        $this->renderPromptFooter($agent);

        if ($this->supportsReadline()) {
            return $this->readInputWithReadline();
        }

        return $this->readInputRaw($agent, $history, $historyPtr, useDockedHud: false);
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

    /**
     * @return array<int, string>
     */
    private function loadInputHistory(string $historyFile, string $legacyHistoryFile): array
    {
        if (file_exists($historyFile)) {
            try {
                $decoded = json_decode((string) file_get_contents($historyFile), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return array_values(array_filter(
                        $decoded,
                        static fn (mixed $entry): bool => is_string($entry) && $entry !== '',
                    ));
                }
            } catch (\JsonException) {
                // Fall through to the legacy plain-text history file.
            }
        }

        if (! file_exists($legacyHistoryFile)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($legacyHistoryFile))));
    }

    /**
     * @param array<int, string> $history
     */
    private function saveInputHistory(string $historyFile, array $history): void
    {
        $directory = dirname($historyFile);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        @file_put_contents($historyFile, json_encode(
            array_values($history),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ));
    }

    private function readInputRaw(AgentLoop $agent, array &$history, int &$historyPtr, bool $useDockedHud = true): ?string
    {
        $cwd = basename(getcwd());
        $autocomplete = app(AutocompleteEngine::class);

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

        $draft = new DraftInputBuffer;
        $liveSuggestions = [];
        $selectedSuggestionIndex = 0;
        $historyDraftSnapshot = null;
        $bracketedPasteEnabled = $sttyMode !== '';

        if ($bracketedPasteEnabled) {
            $this->writeRaw("\033[?2004h");
        }

        $this->redrawActiveRawInput(
            agent: $agent,
            useDockedHud: $useDockedHud,
            cwd: $cwd,
            draft: $draft,
            autocomplete: $autocomplete,
            suggestions: $liveSuggestions,
            selectedSuggestionIndex: $selectedSuggestionIndex,
        );

        try {
            while (true) {
                $char = $this->readRawCharacter($handle);
                if ($char === false || $char === '') {
                    continue;
                }

                if ($char === "\r" || $char === "\n") {
                    $selectedSuggestion = $liveSuggestions[$selectedSuggestionIndex] ?? null;
                    if ($selectedSuggestion !== null
                        && $this->shouldApplySelectedSuggestionOnSubmit($draft, $selectedSuggestion)
                        && $this->applySelectedSuggestion($autocomplete, $draft, $selectedSuggestion['label'])) {
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );

                        continue;
                    }

                    if ($draft->commitContinuationLine()) {
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );

                        continue;
                    }

                    if ($useDockedHud) {
                        $this->clearDockedPromptScreen();
                    } elseif ($sttyMode) {
                        echo "\r\n";
                    }

                    break;
                }

                if ($char === "\x03") { // Ctrl+C
                    if ($useDockedHud) {
                        $this->clearDockedPromptScreen();
                    } elseif ($sttyMode) {
                        echo "\r\n";
                    }

                    return null;
                }

                if ($char === "\x0f") { // Ctrl+O
                    if ($useDockedHud) {
                        $this->clearDockedPromptScreen();
                        $this->resetDockedPromptScreen();
                    } elseif ($sttyMode) {
                        echo "\r\n";
                    }

                    $this->openTranscriptMode($agent, alreadyRaw: true, inputHandle: $handle);
                    $this->redrawActiveRawInput(
                        agent: $agent,
                        useDockedHud: $useDockedHud,
                        cwd: $cwd,
                        draft: $draft,
                        autocomplete: $autocomplete,
                        suggestions: $liveSuggestions,
                        selectedSuggestionIndex: $selectedSuggestionIndex,
                    );

                    continue;
                }

                if ($char === "\x12") { // Ctrl+R
                    if ($useDockedHud) {
                        $this->clearDockedPromptScreen();
                    }
                    $match = $this->readReverseHistorySearch($handle, $history, $draft->text(), $cwd);
                    if ($match !== null) {
                        $draft->replaceWith($match);
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );
                    }
                    continue;
                }

                if ($char === "\x04") { // Ctrl+D
                    if ($useDockedHud) {
                        $this->clearDockedPromptScreen();
                    } elseif ($sttyMode) {
                        echo "\r\n";
                    }
                    $this->shouldExit = true;

                    return null;
                }

                if ($char === "\x1b") { // Escape sequence
                    $seq = $this->readEscapeSequence($handle);
                    if ($seq === false || $seq === '') {
                        continue;
                    }

                    if ($seq === '[200~') {
                        $draft->paste($this->readBracketedPaste($handle));
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );

                        continue;
                    }

                    if ($seq === '[A') { // Up arrow
                        if ($historyPtr < count($history)) {
                            $state = $this->navigateInputHistory($draft, $history, $historyPtr, $historyDraftSnapshot, -1);
                            $historyPtr = $state['historyPtr'];
                            $historyDraftSnapshot = $state['historyDraftSnapshot'];
                            if ($state['changed']) {
                                [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                                $this->redrawActiveRawInput(
                                    agent: $agent,
                                    useDockedHud: $useDockedHud,
                                    cwd: $cwd,
                                    draft: $draft,
                                    autocomplete: $autocomplete,
                                    suggestions: $liveSuggestions,
                                    selectedSuggestionIndex: $selectedSuggestionIndex,
                                );
                            }

                            continue;
                        }

                        if ($liveSuggestions !== []) {
                            $selectedSuggestionIndex = $this->wrapSuggestionIndex($selectedSuggestionIndex - 1, count($liveSuggestions));
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );

                            continue;
                        }

                        if ($draft->moveUp()) {
                            [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );

                            continue;
                        }

                        $state = $this->navigateInputHistory($draft, $history, $historyPtr, $historyDraftSnapshot, -1);
                        $historyPtr = $state['historyPtr'];
                        $historyDraftSnapshot = $state['historyDraftSnapshot'];
                        if ($state['changed']) {
                            [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );
                        }

                        continue;
                    }
                    if ($seq === '[B') { // Down arrow
                        if ($historyPtr < count($history)) {
                            $state = $this->navigateInputHistory($draft, $history, $historyPtr, $historyDraftSnapshot, 1);
                            $historyPtr = $state['historyPtr'];
                            $historyDraftSnapshot = $state['historyDraftSnapshot'];
                            if ($state['changed']) {
                                [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                                $this->redrawActiveRawInput(
                                    agent: $agent,
                                    useDockedHud: $useDockedHud,
                                    cwd: $cwd,
                                    draft: $draft,
                                    autocomplete: $autocomplete,
                                    suggestions: $liveSuggestions,
                                    selectedSuggestionIndex: $selectedSuggestionIndex,
                                );
                            }

                            continue;
                        }

                        if ($liveSuggestions !== []) {
                            $selectedSuggestionIndex = $this->wrapSuggestionIndex($selectedSuggestionIndex + 1, count($liveSuggestions));
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );

                            continue;
                        }

                        if ($draft->moveDown()) {
                            [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );

                            continue;
                        }

                        $state = $this->navigateInputHistory($draft, $history, $historyPtr, $historyDraftSnapshot, 1);
                        $historyPtr = $state['historyPtr'];
                        $historyDraftSnapshot = $state['historyDraftSnapshot'];
                        if ($state['changed']) {
                            [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );
                        }

                        continue;
                    }

                    if ($seq === '[D' || $seq === '[1;2D') {
                        if ($draft->moveLeft()) {
                            [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );
                        }

                        continue;
                    }

                    if ($seq === '[C' || $seq === '[1;2C') {
                        if ($draft->moveRight()) {
                            [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );
                        }

                        continue;
                    }

                    if (in_array($seq, ['[H', '[1~', 'OH'], true)) {
                        $draft->moveHome();
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );

                        continue;
                    }

                    if (in_array($seq, ['[F', '[4~', 'OF'], true)) {
                        $draft->moveEnd();
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );

                        continue;
                    }

                    if ($seq === '[3~') {
                        if ($draft->delete()) {
                            [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                            $this->redrawActiveRawInput(
                                agent: $agent,
                                useDockedHud: $useDockedHud,
                                cwd: $cwd,
                                draft: $draft,
                                autocomplete: $autocomplete,
                                suggestions: $liveSuggestions,
                                selectedSuggestionIndex: $selectedSuggestionIndex,
                            );
                        }

                        continue;
                    }

                    continue;
                }

                if ($char === "\x7f" || $char === "\x08") { // Backspace
                    if ($draft->backspace()) {
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );
                    }

                    continue;
                }

                if ($char === "\t") { // Tab - autocomplete
                    if ($liveSuggestions !== []) {
                        $draft->replaceCurrentLine($autocomplete->acceptSuggestion($draft->currentLine(), $liveSuggestions[$selectedSuggestionIndex]['label']));
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );

                        continue;
                    }

                    $suggestions = $draft->isCursorAtEnd() ? $autocomplete->getSuggestions($draft->currentLine()) : [];

                    if (count($suggestions) === 1) {
                        // Single match: auto-complete
                        $draft->replaceCurrentLine($autocomplete->acceptSuggestion($draft->currentLine(), $suggestions[0]['label']));
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );
                    } elseif (count($suggestions) > 1) {
                        $liveSuggestions = $suggestions;
                        $selectedSuggestionIndex = 0;
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );
                    }

                    continue;
                }

                if ($char === "\x0c") { // Ctrl+L
                    echo "\033[H\033[2J";
                    $this->resetDockedPromptScreen();
                    $this->redrawActiveRawInput(
                        agent: $agent,
                        useDockedHud: $useDockedHud,
                        cwd: $cwd,
                        draft: $draft,
                        autocomplete: $autocomplete,
                        suggestions: $liveSuggestions,
                        selectedSuggestionIndex: $selectedSuggestionIndex,
                    );

                    continue;
                }

                if ($char === "\x01") { // Ctrl+A
                    $draft->moveHome();
                    [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                    $this->redrawActiveRawInput(
                        agent: $agent,
                        useDockedHud: $useDockedHud,
                        cwd: $cwd,
                        draft: $draft,
                        autocomplete: $autocomplete,
                        suggestions: $liveSuggestions,
                        selectedSuggestionIndex: $selectedSuggestionIndex,
                    );

                    continue;
                }

                if ($char === "\x05") { // Ctrl+E
                    $draft->moveEnd();
                    [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                    $this->redrawActiveRawInput(
                        agent: $agent,
                        useDockedHud: $useDockedHud,
                        cwd: $cwd,
                        draft: $draft,
                        autocomplete: $autocomplete,
                        suggestions: $liveSuggestions,
                        selectedSuggestionIndex: $selectedSuggestionIndex,
                    );

                    continue;
                }

                if ($char === "\x02") { // Ctrl+B
                    if ($draft->moveLeft()) {
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );
                    }

                    continue;
                }

                if ($char === "\x06") { // Ctrl+F
                    if ($draft->moveRight()) {
                        [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft, $selectedSuggestionIndex);
                        $this->redrawActiveRawInput(
                            agent: $agent,
                            useDockedHud: $useDockedHud,
                            cwd: $cwd,
                            draft: $draft,
                            autocomplete: $autocomplete,
                            suggestions: $liveSuggestions,
                            selectedSuggestionIndex: $selectedSuggestionIndex,
                        );
                    }

                    continue;
                }

                // Normal printable character
                if (ord($char) >= 32) {
                    $draft->insert($char);
                    [$liveSuggestions, $selectedSuggestionIndex] = $this->refreshLiveSuggestions($autocomplete, $draft);
                    $this->redrawActiveRawInput(
                        agent: $agent,
                        useDockedHud: $useDockedHud,
                        cwd: $cwd,
                        draft: $draft,
                        autocomplete: $autocomplete,
                        suggestions: $liveSuggestions,
                        selectedSuggestionIndex: $selectedSuggestionIndex,
                    );
                }
            }
        } finally {
            if ($bracketedPasteEnabled) {
                $this->writeRaw("\033[?2004l");
            }
            if ($sttyMode) {
                shell_exec("stty {$sttyMode} 2>/dev/null");
            }
            fclose($handle);
        }

        $fullInput = InputSanitizer::sanitize($draft->text());
        $fullInput = rtrim(str_replace(["\r\n", "\r"], "\n", $fullInput), "\n");

        return preg_match('/\S/u', $fullInput) === 1 ? $fullInput : '';
    }

    private function supportsReadline(): bool
    {
        return function_exists('readline') && function_exists('readline_add_history') && stream_isatty(STDIN);
    }

    private function supportsRawInput(): bool
    {
        if (! stream_isatty(STDIN) || ! function_exists('shell_exec')) {
            return false;
        }

        return trim((string) shell_exec('stty -g 2>/dev/null')) !== '';
    }

    private function handleSlashCommand(string $input, AgentLoop $agent): void
    {
        $parts = explode(' ', $input, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';
        $resolved = app(SlashCommandCatalog::class)->resolve($command);

        match ($resolved['name'] ?? null) {
            'exit' => $this->handleExit(),
            'help' => $this->handleHelp(),
            'clear' => $this->handleClear($agent),
            'history' => $this->handleHistory($agent),
            'compact' => $this->handleCompact($agent, $args),
            'config' => $this->handleConfig($args),
            'model' => $this->handleModel($args),
            'provider' => $this->handleProvider($args),
            'cost' => $this->printUsageStats($agent),
            'hooks' => $this->handleHooks(),
            'files' => $this->handleFiles($agent),
            'mcp' => $this->handleMcp($args),
            'plan' => $this->handlePlan($agent, $args),
            'review' => $this->handleReview($agent, $args),
            'status' => $this->handleStatus($agent),
            'statusline' => $this->handleStatusline($args, $agent),
            'stats' => $this->handleStats($agent),
            'transcript', 'search' => $this->handleTranscript($agent, $args),
            'tasks' => $this->handleTasks(),
            'resume' => $this->handleResume($agent, $args),
            'branch' => $this->handleBranch($agent, $args),
            'commit' => $this->handleCommit($agent, $args),
            'diff' => $this->handleDiff(),
            'memory' => $this->handleMemory($args),
            'rewind' => $this->handleRewind(),
            'context' => $this->handleContext($agent),
            'doctor' => $this->handleDoctor(),
            'theme' => $this->handleTheme($args),
            'skills' => $this->handleSkills(),
            'permissions' => $this->handlePermissions($args),
            'fast' => $this->handleFast(),
            'snapshot' => $this->handleSnapshot($agent, $args),
            'init' => $this->handleInit($args),
            'export' => $this->handleExport($args),
            'loop' => $this->handleLoop($args),
            'version' => $this->handleVersion(),
            'output-style' => $this->handleOutputStyle($args),
            'dream' => $this->handleDream($args),
            'buddy' => $this->handleBuddy($args),
            'rename' => $this->handleRename($agent, $args),
            'effort' => $this->handleEffort($args),
            'vim' => $this->handleVim(),
            'copy' => $this->handleCopy($agent),
            'env' => $this->handleEnv(),
            'release-notes' => $this->handleReleaseNotes(),
            'upgrade' => $this->handleUpgrade(),
            'session' => $this->handleSession($agent),
            'add-dir' => $this->handleAddDir($args),
            'pr-comments' => $this->handlePrComments($agent, $args),
            'agents' => $this->handleAgents(),
            'feedback' => $this->handleFeedback($args),
            'login' => $this->handleLogin(),
            'logout' => $this->handleLogout(),
            'keybindings' => $this->handleKeybindings(),
            'paste-image' => $this->handlePasteImage($agent, $args),
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
        $lines = array_map(
            static fn (array $command): string => sprintf(
                '<fg=green>/%s</> %s',
                $command['name'],
                $command['help'] ?? $command['description'],
            ),
            app(SlashCommandCatalog::class)->all(),
        );

        $lines[] = '';
        $lines[] = '<fg=gray>Keybindings:</>  Ctrl+C = cancel  Ctrl+D = exit  ↑/↓ = suggestions/history/multiline  Tab = autocomplete';
        $lines[] = '<fg=gray>Docs:</>         https://github.com/sk-wang/hao-code';

        $this->renderPanel('Available commands', $lines);
    }

    private function handleClear(AgentLoop $agent): void
    {
        // Execute SessionEnd hooks before clearing
        app(HookExecutor::class)->execute('SessionEnd', []);

        $agent->getMessageHistory()->clear();
        $agent->resetSessionMetrics();

        // Generate new session ID
        $sm = $agent->getSessionManager();
        $oldId = $sm->getSessionId();
        $sm->switchToSession(date('Ymd_His').'_'.bin2hex(random_bytes(3)));

        // Execute SessionStart hooks after clearing
        app(HookExecutor::class)->execute('SessionStart', []);

        $this->line('<fg=gray>Conversation cleared.</> <fg=gray>(previous session: '.substr($oldId, 0, 16).'…)</>');
    }

    private function handleHistory(AgentLoop $agent): void
    {
        $count = $agent->getMessageHistory()->count();
        $this->line("<fg=gray>Message count: {$count}</>");
    }

    private function handleCompact(AgentLoop $agent, string $args = ''): void
    {
        $history = $agent->getMessageHistory();
        $beforeCount = $history->count();

        $compactor = app(ContextCompactor::class);
        $instructions = trim($args) !== '' ? trim($args) : null;
        $result = $compactor->compact($history, customInstructions: $instructions);

        $afterCount = $history->count();
        $this->line("<fg=gray>{$result}</>");

        if ($beforeCount > $afterCount) {
            $saved = $beforeCount - $afterCount;
            $this->line("<fg=gray>Compacted {$saved} messages (from {$beforeCount} to {$afterCount}).</>");
        }
    }

    private function handleConfig(string $args): void
    {
        $settings = app(SettingsManager::class);
        $configTool = app(ConfigTool::class);
        $context = new ToolUseContext(
            workingDirectory: getcwd(),
            sessionId: 'repl',
        );
        $args = trim($args);

        if ($args === '' || in_array(strtolower($args), ['list', 'ls'], true)) {
            $all = $settings->all();
            $lines = [
                $this->formatter()->keyValue('Model', $this->formatSettingValue($all['model_identifier'] ?? $all['model'] ?? null)),
                $this->formatter()->keyValue('Provider', $this->formatSettingValue($all['active_provider'] ?? null)),
                $this->formatter()->keyValue('Permission mode', $this->formatSettingValue($all['permission_mode'] ?? null)),
                $this->formatter()->keyValue('Theme', $this->formatSettingValue($all['theme'] ?? null)),
                $this->formatter()->keyValue('Output style', $this->formatSettingValue($all['output_style'] ?? null)),
                $this->formatter()->keyValue('Stream output', $this->formatSettingValue($all['stream_output'] ?? null)),
                $this->formatter()->keyValue('API base URL', $this->formatSettingValue($all['api_base_url'] ?? null), 'gray', 'gray'),
                $this->formatter()->keyValue('Max tokens', $this->formatSettingValue($all['max_tokens'] ?? null), 'gray', 'gray'),
                $this->formatter()->keyValue('API key', ! empty($all['api_key_set']) ? 'configured' : 'missing', 'gray', 'gray'),
                $this->formatter()->keyValue('Configured providers', $this->formatSettingValue($all['configured_providers'] ?? []), 'gray', 'gray'),
                '',
                '<fg=gray>Use /config &lt;key&gt; to inspect or /config &lt;key&gt; &lt;value&gt; to change.</>',
                '<fg=gray>Keys: model, active_provider, permission_mode, theme, output_style, stream_output, api_base_url, max_tokens</>',
            ];

            $this->renderPanel('Runtime config', $lines);

            return;
        }

        $parts = preg_split('/\s+/', $args, 3) ?: [];
        $verb = strtolower($parts[0] ?? '');

        if (in_array($verb, ['get', 'show'], true)) {
            $key = $this->normalizeConfigKey($parts[1] ?? '');
            if ($key === null) {
                $this->line('<fg=red>Unknown config key.</> Supported keys: model, active_provider, permission_mode, theme, output_style, stream_output, api_base_url, max_tokens');

                return;
            }

            $result = $configTool->call(['key' => $key], $context);
            $this->line($result->isError ? "<fg=red>{$result->output}</>" : "<fg=gray>{$result->output}</>");

            return;
        }

        if ($verb === 'set') {
            $key = $this->normalizeConfigKey($parts[1] ?? '');
            $value = $parts[2] ?? null;
        } else {
            $key = $this->normalizeConfigKey($parts[0] ?? '');
            $value = $parts[1] ?? null;
        }

        if ($key === null) {
            $this->line('<fg=red>Unknown config key.</> Supported keys: model, active_provider, permission_mode, theme, output_style, stream_output, api_base_url, max_tokens');

            return;
        }

        if ($value === null || trim($value) === '') {
            $result = $configTool->call(['key' => $key], $context);
            $this->line($result->isError ? "<fg=red>{$result->output}</>" : "<fg=gray>{$result->output}</>");

            return;
        }

        $normalizedValue = trim($value);
        if (in_array($key, ['output_style', 'active_provider'], true) && in_array(strtolower($normalizedValue), ['off', 'none', 'clear'], true)) {
            $normalizedValue = null;
        }

        if ($key === 'permission_mode' && $normalizedValue === PermissionMode::Plan->value) {
            $this->previousPermissionMode ??= $settings->getPermissionMode()->value;
        }

        if ($key === 'permission_mode' && $normalizedValue !== PermissionMode::Plan->value) {
            $this->previousPermissionMode = null;
        }

        $result = $configTool->call([
            'key' => $key,
            'value' => $normalizedValue,
        ], $context);

        $this->line($result->isError ? "<fg=red>{$result->output}</>" : "<fg=green>{$result->output}</>");
    }

    private function handleHooks(): void
    {
        $hooks = $this->loadConfiguredHooks();

        if ($hooks === []) {
            $paths = array_map(
                fn (array $entry): string => '<fg=gray>'.$entry['path'].'</>',
                $this->configuredSettingsPaths(),
            );

            $this->renderPanel('Hook configuration', [
                '<fg=gray>No hooks configured.</>',
                '<fg=gray>Add hooks under the "hooks" key in:</>',
                ...$paths,
            ]);

            return;
        }

        $lines = [];
        foreach ($hooks as $index => $hook) {
            $scopeColor = $hook['scope'] === 'project' ? 'green' : 'yellow';
            $summary = "<fg=cyan>{$hook['event']}</> <fg={$scopeColor}>{$hook['scope']}</>";
            if ($hook['matcher'] !== null && $hook['matcher'] !== '') {
                $summary .= " <fg=magenta>{$hook['matcher']}</>";
            }

            $lines[] = $summary;
            $lines[] = '<fg=white>'.$this->truncate($hook['command'], 96).'</>';
            $lines[] = '<fg=gray>'.$hook['path'].'</>';

            if ($index !== array_key_last($hooks)) {
                $lines[] = '';
            }
        }

        $this->renderPanel('Hook configuration', $lines);
    }

    private function handleFiles(AgentLoop $agent): void
    {
        $files = $this->extractContextFiles($agent->getMessageHistory()->getMessages());

        if ($files === []) {
            $this->line('<fg=gray>No files in context.</>');

            return;
        }

        $lines = array_map(
            fn (string $file): string => '<fg=white>'.$file.'</>',
            $files,
        );

        $this->renderPanel('Files in context', $lines);
    }

    private function handleMcp(string $args): void
    {
        $manager = app(McpServerConfigManager::class);
        $tokens = $this->tokenizeArguments($args);
        $subcommand = strtolower($tokens[0] ?? 'list');

        if ($subcommand === 'status') {
            $cm = app(McpConnectionManager::class);
            $connected = $cm->getConnectedClients();
            $failures = $cm->getFailures();

            if (empty($connected) && empty($failures)) {
                $this->line('<fg=yellow>No MCP servers have been connected yet.</>');
                return;
            }

            $lines = [];
            foreach ($connected as $name => $client) {
                $toolCount = count($client->listTools(useCache: true));
                $resourceCount = $client->supportsResources() ? count($client->listResources(useCache: true)) : 0;
                $info = $client->getServerInfo();
                $version = $info ? " v{$info['version']}" : '';
                $lines[] = "<fg=green>●</> <fg=white>{$name}</>{$version} — {$toolCount} tools, {$resourceCount} resources";
            }
            foreach ($failures as $name => $error) {
                $lines[] = "<fg=red>✗</> <fg=white>{$name}</> — {$error->getMessage()}";
            }
            $this->renderPanel('MCP server status', $lines);
            return;
        }

        if ($subcommand === 'reconnect') {
            $targetName = $tokens[1] ?? null;
            $cm = app(McpConnectionManager::class);
            $registry = app(ToolRegistry::class);

            if ($targetName !== null) {
                // Reconnect a specific server
                $cm->disconnect($targetName);
                try {
                    $client = $cm->connectByName($targetName);
                    $tools = $client->listTools();
                    foreach ($tools as $tool) {
                        $qn = McpConnectionManager::buildToolName($targetName, $tool['name']);
                        $registry->register(new McpDynamicTool(
                            qualifiedName: $qn,
                            serverName: $targetName,
                            toolName: $tool['name'],
                            toolDescription: $tool['description'],
                            inputJsonSchema: $tool['inputSchema'],
                            annotations: $tool['annotations'] ?? [],
                            connectionManager: $cm,
                        ));
                    }
                    $this->line("<fg=green>Reconnected to {$targetName}, " . count($tools) . " tools registered</>");
                } catch (\Throwable $e) {
                    $this->line("<fg=red>Failed to reconnect {$targetName}:</> {$e->getMessage()}");
                }
            } else {
                // Reconnect all
                $cm->disconnectAll();
                $this->connectMcpServers();
            }
            return;
        }

        if ($subcommand === 'paths') {
            $paths = $manager->paths();
            $this->renderPanel('MCP config paths', [
                $this->formatter()->keyValue('Global', $paths['global'], 'gray', 'white'),
                $this->formatter()->keyValue('Project', $paths['project'], 'gray', 'white'),
            ], addSpacing: false);

            return;
        }

        if ($subcommand === 'show') {
            $name = $tokens[1] ?? null;
            if ($name === null) {
                $this->line('<fg=red>Usage:</> /mcp show <name>');

                return;
            }

            $server = $manager->getServer($name);
            if ($server === null) {
                $this->line("<fg=red>MCP server not found:</> <fg=white>{$name}</>");

                return;
            }

            $lines = [
                $this->formatter()->keyValue('Name', $server['name']),
                $this->formatter()->keyValue('Scope', $server['scope'], 'gray', 'gray'),
                $this->formatter()->keyValue('Transport', $server['transport'], 'gray', 'gray'),
                $this->formatter()->keyValue('Enabled', $server['enabled'] ? 'yes' : 'no', 'gray', $server['enabled'] ? 'green' : 'yellow'),
                $this->formatter()->keyValue('Source', $server['path'], 'gray', 'gray'),
            ];

            if ($server['url'] !== null) {
                $lines[] = $this->formatter()->keyValue('URL', $server['url'], 'gray', 'white');
            }

            if ($server['command'] !== null) {
                $command = $server['command'];
                if ($server['args'] !== []) {
                    $command .= ' '.implode(' ', $server['args']);
                }
                $lines[] = $this->formatter()->keyValue('Command', $command, 'gray', 'white');
            }

            if ($server['env'] !== []) {
                $lines[] = $this->formatter()->keyValue('Env', json_encode($server['env'], JSON_UNESCAPED_SLASHES) ?: '{}', 'gray', 'gray');
            }

            if ($server['headers'] !== []) {
                $lines[] = $this->formatter()->keyValue('Headers', json_encode($server['headers'], JSON_UNESCAPED_SLASHES) ?: '{}', 'gray', 'gray');
            }

            $this->renderPanel('MCP server', $lines, addSpacing: false);

            return;
        }

        if ($subcommand === 'add') {
            [$options, $positionals] = $this->parseLongOptions(array_slice($tokens, 1));
            $name = $positionals[0] ?? null;
            $target = $positionals[1] ?? null;

            if ($name === null || $target === null) {
                $this->line('<fg=red>Usage:</> /mcp add <name> <command-or-url> [args...] [--scope project|global] [--transport stdio|http|sse]');

                return;
            }

            $transport = strtolower((string) ($options['transport'][0] ?? ''));
            $scope = strtolower((string) ($options['scope'][0] ?? 'project'));
            if (! in_array($scope, ['project', 'global'], true)) {
                $this->line('<fg=red>Invalid scope.</> Use project or global.');

                return;
            }

            if ($transport === '') {
                $transport = preg_match('#^https?://#i', $target) === 1 ? 'http' : 'stdio';
            }

            if (! in_array($transport, ['stdio', 'http', 'sse'], true)) {
                $this->line('<fg=red>Invalid transport.</> Use stdio, http, or sse.');

                return;
            }

            $definition = [
                'transport' => $transport,
                'enabled' => true,
            ];

            if ($transport === 'stdio') {
                $definition['command'] = $target;
                $definition['args'] = array_slice($positionals, 2);
            } else {
                $definition['url'] = $target;
            }

            $env = $this->parseKeyValueOptions($options['env'] ?? []);
            $headers = $this->parseHeaderOptions($options['header'] ?? []);
            if ($env !== []) {
                $definition['env'] = $env;
            }
            if ($headers !== []) {
                $definition['headers'] = $headers;
            }

            $existing = $manager->getServer($name);
            $manager->addServer($name, $definition, $scope);

            $verb = $existing === null ? 'Added' : 'Updated';
            $summary = $transport === 'stdio'
                ? $target.($definition['args'] !== [] ? ' '.implode(' ', $definition['args']) : '')
                : $target;

            $this->line("<fg=green>{$verb} MCP server:</> <fg=white>{$name}</> <fg=gray>({$scope} · {$transport})</>");
            $this->line("<fg=gray>{$summary}</>");

            return;
        }

        if ($subcommand === 'remove') {
            [$options, $positionals] = $this->parseLongOptions(array_slice($tokens, 1));
            $name = $positionals[0] ?? null;
            $scope = strtolower((string) ($options['scope'][0] ?? 'all'));

            if ($name === null) {
                $this->line('<fg=red>Usage:</> /mcp remove <name> [--scope project|global|all]');

                return;
            }

            $removed = $manager->removeServer($name, $scope);
            if ($removed === 0) {
                $this->line("<fg=yellow>No MCP server removed:</> <fg=white>{$name}</>");

                return;
            }

            $this->line("<fg=green>Removed MCP server:</> <fg=white>{$name}</> <fg=gray>({$removed} scope".($removed === 1 ? '' : 's').')</>');

            return;
        }

        if (in_array($subcommand, ['enable', 'disable'], true)) {
            [$options, $positionals] = $this->parseLongOptions(array_slice($tokens, 1));
            $name = $positionals[0] ?? 'all';
            $scope = strtolower((string) ($options['scope'][0] ?? 'all'));
            $enabled = $subcommand === 'enable';
            $updated = $manager->setEnabled($name, $enabled, $scope);

            if ($updated === 0) {
                $this->line("<fg=yellow>No MCP servers updated.</> <fg=gray>Target: {$name}</>");

                return;
            }

            $action = $enabled ? 'Enabled' : 'Disabled';
            $this->line("<fg=green>{$action}</> <fg=white>{$updated}</> <fg=gray>MCP server".($updated === 1 ? '' : 's')."</> <fg=gray>({$scope})</>");

            return;
        }

        $servers = $manager->listServers();
        if ($servers === []) {
            $paths = $manager->paths();
            $this->renderPanel('MCP servers', [
                '<fg=gray>No MCP servers configured.</>',
                $this->formatter()->keyValue('Global config', $paths['global'], 'gray', 'gray'),
                $this->formatter()->keyValue('Project config', $paths['project'], 'gray', 'gray'),
                '<fg=gray>Use /mcp add <name> <command-or-url> to register one.</>',
            ]);

            return;
        }

        $cm = app(McpConnectionManager::class);
        $lines = [];
        foreach ($servers as $index => $server) {
            $statusColor = $server['enabled'] ? 'green' : 'yellow';
            $status = $server['enabled'] ? 'enabled' : 'disabled';

            // Show connection status if connected
            $client = $cm->getClient($server['name']);
            if ($client !== null && $client->isConnected()) {
                $toolCount = count($client->listTools(useCache: true));
                $status = "connected · {$toolCount} tools";
                $statusColor = 'green';
            } elseif (isset($cm->getFailures()[$server['name']])) {
                $status = 'failed';
                $statusColor = 'red';
            }

            $target = $server['url'] ?? $server['command'] ?? 'unknown';
            if ($server['url'] === null && $server['args'] !== []) {
                $target .= ' '.implode(' ', $server['args']);
            }

            $lines[] = "<fg=yellow>{$server['name']}</> <fg=gray>{$server['scope']} · {$server['transport']} · </><fg={$statusColor}>{$status}</>";
            $lines[] = '<fg=white>'.$this->truncate($target, 90).'</>';
            $lines[] = '<fg=gray>'.$server['path'].'</>';

            if ($index < count($servers) - 1) {
                $lines[] = '';
            }
        }

        $this->renderPanel('MCP servers', $lines);
    }

    private function handlePlan(AgentLoop $agent, string $args): void
    {
        $settings = app(SettingsManager::class);
        $args = trim($args);
        $mode = $settings->getPermissionMode();

        if (in_array(strtolower($args), ['off', 'exit', 'disable'], true)) {
            if ($mode !== PermissionMode::Plan) {
                $this->line('<fg=gray>Plan mode is already off.</>');

                return;
            }

            $restoreMode = $this->previousPermissionMode ?? config('haocode.permission_mode', PermissionMode::Default->value);
            $settings->set('permission_mode', $restoreMode);
            $this->previousPermissionMode = null;
            $this->line("<fg=green>Plan mode disabled.</> <fg=gray>Restored {$restoreMode}.</>");

            return;
        }

        if ($mode !== PermissionMode::Plan) {
            $this->previousPermissionMode = $mode->value;
            $settings->set('permission_mode', PermissionMode::Plan->value);

            if ($args === '') {
                $this->renderPanel('Plan mode', [
                    $this->formatter()->keyValue('Mode', 'plan'),
                    $this->formatter()->keyValue('Previous mode', $mode->value, 'gray', 'gray'),
                    '<fg=gray>Ask a task with /plan &lt;request&gt; to generate an implementation plan without writing files.</>',
                    '<fg=gray>Use /plan off to leave plan mode.</>',
                ], addSpacing: false);

                return;
            }

            $this->line('<fg=green>Plan mode enabled.</>');
        }

        if ($args === '') {
            $this->renderPanel('Plan mode', [
                $this->formatter()->keyValue('Mode', 'plan'),
                '<fg=gray>Planning prompts stay read-only. Use /plan &lt;request&gt; to explore and design changes.</>',
                '<fg=gray>Use /plan off to leave plan mode.</>',
            ], addSpacing: false);

            return;
        }

        $planningPrompt = "Plan mode is active. Explore the codebase and produce a concrete implementation plan without making file changes.\n\nTask:\n{$args}";
        $this->runAgentTurn($agent, $planningPrompt);
    }

    private function handleReview(AgentLoop $agent, string $args): void
    {
        if (! app(GitContext::class)->isGitRepo()) {
            $this->line('<fg=red>/review requires a git repository.</>');

            return;
        }

        $prompt = $this->buildReviewPrompt($args);
        $gitReadRules = [
            'Bash(git status:*)',
            'Bash(git diff:*)',
            'Bash(git log:*)',
            'Bash(git branch:*)',
            'Bash(git show:*)',
            'Bash(git rev-parse:*)',
        ];

        $this->runAgentTurnWithSessionRules($agent, $prompt, $gitReadRules);
    }

    private function handleTasks(): void
    {
        $tasks = BashTool::listTasks();
        $agentTasks = array_filter(
            app(TaskManager::class)->list(),
            fn ($task) => str_starts_with($task->id, 'agent_') && $task->status !== 'completed'
        );
        $backgroundAgents = app(BackgroundAgentManager::class);

        if (empty($tasks) && empty($agentTasks)) {
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

        foreach ($agentTasks as $task) {
            $elapsed = max(0, time() - $task->createdAt);
            $agent = $backgroundAgents->get($task->id);
            $agentStatus = $agent['status'] ?? 'unknown';
            $pending = (int) ($agent['pending_messages'] ?? 0);
            $pid = $agent['pid'] ?? '?';

            $detailParts = ["PID {$pid}", "{$elapsed}s", $agentStatus];
            if ($pending > 0) {
                $detailParts[] = "{$pending} msg queued";
            }
            if (! empty($agent['stop_requested'])) {
                $detailParts[] = 'stop requested';
            }

            $lines[] = sprintf(
                '<fg=cyan>%s</> <fg=gray>%s · %s</>',
                $task->id,
                implode(' · ', $detailParts),
                $this->truncate($task->subject, 50),
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

        $this->restoreSession($agent, $args, announce: true);
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

    /**
     * Connect to all enabled MCP servers and register their tools dynamically.
     */
    private function connectMcpServers(): void
    {
        $connectionManager = app(McpConnectionManager::class);
        $registry = app(ToolRegistry::class);

        $connectionManager->connectAll(function (string $name, string $status, ?string $error) {
            match ($status) {
                'connecting' => $this->line("<fg=gray>  MCP: connecting to {$name}...</>"),
                'connected' => $this->line("<fg=green>  MCP: {$name} connected</>"),
                'failed' => $this->line("<fg=red>  MCP: {$name} failed — {$error}</>"),
                'disabled' => null,
                default => null,
            };
        });

        // Register dynamically discovered MCP tools
        $tools = $connectionManager->discoverAllTools();
        foreach ($tools as $tool) {
            $dynamicTool = new McpDynamicTool(
                qualifiedName: $tool['qualifiedName'],
                serverName: $tool['serverName'],
                toolName: $tool['toolName'],
                toolDescription: $tool['description'],
                inputJsonSchema: $tool['inputSchema'],
                annotations: $tool['annotations'],
                connectionManager: $connectionManager,
            );
            $registry->register($dynamicTool);
        }

        if (!empty($tools)) {
            $this->line('<fg=gray>  MCP: registered ' . count($tools) . ' tools</>');
        }
    }

    private function initializeSessionFromStartupOptions(AgentLoop $agent, bool $quiet = false): bool
    {
        $resume = $this->option('resume');
        $continue = (bool) $this->option('continue');

        if ($resume !== null && $continue) {
            $this->error('Cannot use --resume and --continue together.');

            return false;
        }

        $restored = false;
        if (is_string($resume) && trim($resume) !== '') {
            $restored = $this->restoreSession($agent, trim($resume), announce: ! $quiet);
            if (! $restored) {
                $this->error("No session found matching: {$resume}");

                return false;
            }
        } elseif ($continue) {
            $sessionId = app(SessionManager::class)->findMostRecentSessionId(getcwd() ?: null);
            if ($sessionId !== null) {
                $restored = $this->restoreSession($agent, $sessionId, announce: ! $quiet);
            } elseif (! $quiet) {
                $this->line('<fg=gray>No previous session found. Starting a new conversation.</>');
            }
        }

        if ($restored && $this->option('fork-session')) {
            $branch = $agent->getSessionManager()->branchSession();
            $agent->resetSessionMetrics();

            if (! $quiet) {
                $this->line("<fg=green>Forked resumed session:</> <fg=white>{$branch['session_id']}</>");
            }
        }

        return true;
    }

    private function restoreSession(AgentLoop $agent, string $reference, bool $announce = true): bool
    {
        $entries = $this->loadSessionEntriesByReference($reference);
        if ($entries === [] || ($entries['entries'] ?? []) === []) {
            return false;
        }

        $sessionEntries = $entries['entries'];
        $history = $agent->getMessageHistory();
        $history->clear();

        foreach ($sessionEntries as $entry) {
            $type = $entry['type'] ?? '';
            if ($type === 'user_message') {
                $history->addUserMessage($entry['content'] ?? '');
            } elseif ($type === 'assistant_turn') {
                if (isset($entry['message'])) {
                    $history->addAssistantMessage($entry['message']);
                }
                if (! empty($entry['tool_results'])) {
                    $history->addToolResultMessage($entry['tool_results']);
                }
            }
        }
        $restored = $history->count();

        $sessionId = $entries['session_id'];
        $title = SessionManager::extractTitleFromEntries($sessionEntries);
        $agent->getSessionManager()->switchToSession($sessionId, $title);
        $agent->resetSessionMetrics();
        app(PromptHudState::class)->hydrateFromSessionEntries($sessionEntries);

        if ($announce) {
            $this->line("<fg=green>Resumed session:</> <fg=white>{$sessionId}</> ({$restored} messages restored)");

            $awaySummary = app(AwaySummaryService::class)->generateSummary($sessionEntries);
            if ($awaySummary) {
                $this->renderPanel('While you were away', [
                    $this->formatter()->keyValue('Summary', $awaySummary, 'gray', 'gray'),
                ]);
            }
        }

        return true;
    }

    /**
     * @return array{session_id: string, entries: array<int, array<string, mixed>>}|array{}
     */
    private function loadSessionEntriesByReference(string $reference): array
    {
        $sessionPath = config('haocode.session_path', storage_path('app/haocode/sessions'));
        $safeArg = str_replace(['*', '?', '[', ']'], '', trim($reference));
        if ($safeArg === '') {
            return [];
        }

        $patterns = [
            $sessionPath.'/'.$safeArg.'*.jsonl',
            $sessionPath.'/*'.$safeArg.'*.jsonl',
        ];

        $file = null;
        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (! empty($files)) {
                $file = $files[0];
                break;
            }
        }

        if ($file === null) {
            return [];
        }

        $entries = [];
        foreach (file($file) ?: [] as $line) {
            if (! trim($line)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        if ($entries === []) {
            return [];
        }

        return [
            'session_id' => basename($file, '.jsonl'),
            'entries' => $entries,
        ];
    }

    private function handleBranch(AgentLoop $agent, string $args): void
    {
        try {
            $branch = $agent->getSessionManager()->branchSession(trim($args) !== '' ? trim($args) : null);
            $agent->resetSessionMetrics();
            $this->refreshPromptHudState($agent);

            $this->renderPanel('Conversation branched', [
                $this->formatter()->keyValue('New session', $branch['session_id']),
                $this->formatter()->keyValue('From session', $branch['source_session_id'], 'gray', 'gray'),
                $this->formatter()->keyValue('Title', $branch['title'], 'gray', 'white'),
                '<fg=gray>You are now continuing in the new branch.</>',
                "<fg=gray>Use /resume {$branch['source_session_id']} to return to the original transcript.</>",
            ], 'green', addSpacing: false);
        } catch (\RuntimeException $e) {
            $this->line('<fg=red>'.$e->getMessage().'</>');
        }
    }

    private function handleModel(string $args = ''): void
    {
        $settings = app(SettingsManager::class);
        $args = trim($args);
        $available = $this->availableModelChoices($settings);

        // Resolve model aliases
        $aliases = [
            'sonnet' => 'claude-sonnet-4-20250514',
            'opus' => 'claude-opus-4-20250514',
            'haiku' => 'claude-haiku-4-20250514',
            'sonnet-3.5' => 'claude-3-5-sonnet-20241022',
            'haiku-3.5' => 'claude-3-5-haiku-20241022',
        ];

        if ($args === '') {
            $lines = [
                $this->formatter()->keyValue('Current', $settings->getResolvedModelIdentifier()),
                $this->formatter()->keyValue('Provider', $this->formatSettingValue($settings->getActiveProviderName()), 'gray', 'gray'),
                '',
                '<fg=gray>Available models:</>',
            ];
            foreach ($available as $model) {
                $alias = array_search($model, $aliases, true);
                $label = $alias !== false ? "<fg=cyan>{$model}</> <fg=gray>({$alias})</>" : "<fg=cyan>{$model}</>";
                $lines[] = "  {$label}";
            }
            $lines[] = '';
            $lines[] = '<fg=gray>Aliases: '.implode(', ', array_keys($aliases)).'</>';
            $this->renderPanel('Models', $lines);

            return;
        }

        $resolved = $aliases[strtolower($args)] ?? $args;
        $settings->set('model', $resolved);

        $display = $settings->getResolvedModelIdentifier();
        if ($resolved !== $args) {
            $this->line("<fg=green>Model set to:</> <fg=white>{$display}</> <fg=gray>(alias: {$args})</>");
        } elseif (in_array($resolved, $available, true)) {
            $this->line('<fg=green>Model set to:</> <fg=white>'.$display.'</>');
        } else {
            $this->line("<fg=green>Model override set to:</> <fg=white>{$args}</>");
            if ($available !== []) {
                $this->line('<fg=gray>Known choices: '.implode(', ', $available).'</>');
            }
        }
    }

    private function handleProvider(string $args = ''): void
    {
        $settings = app(SettingsManager::class);
        $providers = $settings->getConfiguredProviders();
        $tokens = $this->tokenizeArguments($args);
        $subcommand = strtolower($tokens[0] ?? 'list');

        if ($providers === []) {
            $paths = array_map(
                fn (array $entry): string => '<fg=gray>'.$entry['path'].'</>',
                $this->configuredSettingsPaths(),
            );

            $this->renderPanel('Providers', [
                '<fg=gray>No providers configured.</>',
                '<fg=gray>Add a "provider" object to one of these files:</>',
                ...$paths,
                '<fg=gray>Example: {"active_provider":"zai","provider":{"zai":{"api_key":"...","api_base_url":"https://api.z.ai/api/anthropic","model":"glm-5.1"}}}</>',
            ]);

            return;
        }

        if (count($tokens) === 1 && array_key_exists($subcommand, $providers)) {
            $tokens = ['use', $subcommand];
            $subcommand = 'use';
        }

        if (in_array($subcommand, ['list', 'ls', 'show'], true)) {
            $current = $settings->getActiveProviderName();
            $lines = [
                $this->formatter()->keyValue('Current', $this->formatSettingValue($current)),
            ];

            foreach ($providers as $name => $provider) {
                $marker = $name === $current ? '<fg=green>✓</>' : '<fg=gray>·</>';
                $lines[] = "{$marker} <fg=yellow>{$name}</>";
                $lines[] = $this->formatter()->keyValue('Model', $this->formatSettingValue($provider['model'] ?? null), 'gray', 'gray');
                $lines[] = $this->formatter()->keyValue('Base URL', $this->formatSettingValue($provider['api_base_url'] ?? null), 'gray', 'gray');
                $lines[] = $this->formatter()->keyValue('API key', ! empty($provider['api_key']) ? 'configured' : 'missing', 'gray', 'gray');
                if ($name !== array_key_last($providers)) {
                    $lines[] = '';
                }
            }

            $lines[] = '';
            $lines[] = '<fg=gray>Use /provider use &lt;name&gt; to switch for this session.</>';
            $lines[] = '<fg=gray>Use /provider clear to fall back to default resolution.</>';

            $this->renderPanel('Providers', $lines);

            return;
        }

        if (in_array($subcommand, ['clear', 'off', 'unset'], true)) {
            $settings->set('active_provider', null);
            $this->line('<fg=green>Provider override cleared.</> <fg=gray>Using default resolution.</>');

            return;
        }

        if (in_array($subcommand, ['use', 'set'], true)) {
            $name = $tokens[1] ?? null;
            if ($name === null || ! array_key_exists($name, $providers)) {
                $this->line('<fg=red>Unknown provider.</> Available: '.implode(', ', array_keys($providers)));

                return;
            }

            $settings->set('active_provider', $name);
            $settings->set('model', null);

            $label = $providers[$name]['model'] !== null
                ? $name.'/'.$providers[$name]['model']
                : $name;

            $this->line("<fg=green>Provider set to:</> <fg=white>{$name}</>");
            $this->line("<fg=gray>Resolved model:</> <fg=white>{$label}</>");

            return;
        }

        $this->line('<fg=red>Usage:</> <fg=white>/provider</>, <fg=white>/provider use &lt;name&gt;</>, <fg=white>/provider clear</>');
    }

    private function handleDiff(): void
    {
        $stagedStat = trim(shell_exec('git diff --cached --stat 2>/dev/null') ?? '');
        $unstagedStat = trim(shell_exec('git diff --stat 2>/dev/null') ?? '');

        if ($stagedStat === '' && $unstagedStat === '') {
            $this->line('<fg=gray>No uncommitted changes.</>');

            return;
        }

        if ($stagedStat !== '') {
            $this->line("\n  <fg=green;bold>Staged Changes:</>");
            $this->line("<fg=gray>{$stagedStat}</>");
        }

        if ($unstagedStat !== '') {
            $this->line("\n  <fg=yellow;bold>Unstaged Changes:</>");
            $this->line("<fg=gray>{$unstagedStat}</>");
        }

        // Show colored full diff if not too large
        $fullDiff = '';
        if ($stagedStat !== '') {
            $fullDiff .= shell_exec('git diff --cached 2>/dev/null') ?? '';
        }
        if ($unstagedStat !== '') {
            $fullDiff .= shell_exec('git diff 2>/dev/null') ?? '';
        }

        if (mb_strlen($fullDiff) > 8000) {
            $this->line('  <fg=gray>(diff too large to display — run `git diff` in your terminal)</>');

            return;
        }

        if (trim($fullDiff) === '') {
            return;
        }

        $this->line('');
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

    private function handleCommit(AgentLoop $agent, string $args): void
    {
        $git = app(GitContext::class);
        if (! $git->isGitRepo()) {
            $this->line('<fg=red>/commit requires a git repository.</>');

            return;
        }

        $status = trim((string) shell_exec('git status --porcelain 2>/dev/null'));
        if ($status === '') {
            $this->line('<fg=gray>No changes to commit.</>');

            return;
        }

        $prompt = $this->buildCommitPrompt($args);
        $rules = [
            'Bash(git status:*)',
            'Bash(git diff:*)',
            'Bash(git log:*)',
            'Bash(git branch:*)',
            'Bash(git add:*)',
            'Bash(git commit:*)',
        ];

        $this->runAgentTurnWithSessionRules($agent, $prompt, $rules);
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
        $model = app(SettingsManager::class)->getModel();
        $contextLimit = $this->contextWindowForModel($model);
        $usagePercent = $contextLimit > 0 ? round(($in / $contextLimit) * 100, 1) : 0;

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
        $lines[] = $this->formatter()->keyValue('Model', $settings->getResolvedModelIdentifier());
        $lines[] = $this->formatter()->keyValue('Provider', $this->formatSettingValue($settings->getActiveProviderName()), 'gray', 'gray');
        $lines[] = $this->formatter()->keyValue('Messages', (string) $agent->getMessageHistory()->count());
        $lines[] = $this->formatter()->keyValue('Permission mode', $settings->getPermissionMode()->value);
        $lines[] = $this->formatter()->keyValue('Cost', $costTracker->getSummary(), 'gray', 'gray');

        $this->renderPanel('Session status', $lines, addSpacing: false);
        $this->printUsageStats($agent);
    }

    private function handleStatusline(string $args, AgentLoop $agent): void
    {
        $settings = app(SettingsManager::class);
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($args)) ?: [], static fn (string $token): bool => $token !== ''));
        $mode = strtolower($tokens[0] ?? '');

        if (in_array($mode, ['on', 'enable'], true)) {
            $settings->setStatuslineEnabled(true);
            $settings->set('statusline_enabled', true);
            $this->line('<fg=green>Status line enabled.</>');

            return;
        }

        if (in_array($mode, ['off', 'disable'], true)) {
            $settings->setStatuslineEnabled(false);
            $settings->set('statusline_enabled', false);
            $this->line('<fg=green>Status line disabled.</>');

            return;
        }

        if (in_array($mode, ['compact', 'expanded'], true)) {
            $settings->setStatuslineLayout($mode);
            $this->line('<fg=green>Status line layout set to</> <fg=white>'.$mode.'</>.');

            return;
        }

        if ($mode === 'layout') {
            $layout = strtolower($tokens[1] ?? '');
            if (! in_array($layout, ['compact', 'expanded'], true)) {
                $this->line('<fg=red>Usage: /statusline layout compact|expanded</>');

                return;
            }

            $settings->setStatuslineLayout($layout);
            $this->line('<fg=green>Status line layout set to</> <fg=white>'.$layout.'</>.');

            return;
        }

        if (in_array($mode, ['paths', 'path', 'levels'], true)) {
            $rawLevels = $tokens[1] ?? null;
            if ($rawLevels === null || ! ctype_digit($rawLevels)) {
                $this->line('<fg=red>Usage: /statusline paths 1|2|3</>');

                return;
            }

            $levels = max(1, min(3, (int) $rawLevels));
            $settings->setStatuslinePathLevels($levels);
            $this->line('<fg=green>Status line path depth set to</> <fg=white>'.$levels.'</>.');

            return;
        }

        if (in_array($mode, ['tools', 'agents', 'todos'], true)) {
            $enabled = $this->parseStatuslineToggle($tokens[1] ?? null);
            if ($enabled === null) {
                $this->line('<fg=red>Usage: /statusline '.$mode.' on|off</>');

                return;
            }

            $settings->setStatuslineSectionVisibility($mode, $enabled);
            $this->line('<fg=green>Status line '.$mode.' '.($enabled ? 'enabled' : 'disabled').'.</>');

            return;
        }

        if ($mode === 'reset') {
            $settings->resetStatuslineConfig();
            $this->line('<fg=green>Status line settings reset to defaults.</>');

            return;
        }

        $statusline = $settings->getStatuslineConfig();
        $preview = $this->formatter()->promptFooterLines($this->buildPromptHudSnapshot($agent));

        $this->renderPanel('Status line', [
            $this->formatter()->keyValue('Enabled', $statusline['enabled'] ? 'yes' : 'no', 'gray', $statusline['enabled'] ? 'green' : 'yellow'),
            $this->formatter()->keyValue('Layout', $statusline['layout'], 'gray', 'gray'),
            $this->formatter()->keyValue('Path levels', (string) $statusline['path_levels'], 'gray', 'gray'),
            $this->formatter()->keyValue('Tools', $statusline['show_tools'] ? 'on' : 'off', 'gray', $statusline['show_tools'] ? 'green' : 'yellow'),
            $this->formatter()->keyValue('Agents', $statusline['show_agents'] ? 'on' : 'off', 'gray', $statusline['show_agents'] ? 'green' : 'yellow'),
            $this->formatter()->keyValue('Todos', $statusline['show_todos'] ? 'on' : 'off', 'gray', $statusline['show_todos'] ? 'green' : 'yellow'),
            '<fg=gray>The status line is docked at the bottom in raw-terminal mode; non-raw modes fall back to an inline footer.</>',
            '<fg=gray>Commands: /statusline on|off · /statusline layout compact|expanded · /statusline paths 1|2|3</>',
            '<fg=gray>Commands: /statusline tools on|off · /statusline agents on|off · /statusline todos on|off · /statusline reset</>',
            '',
            '<fg=white>Preview:</>',
            ...$preview,
        ]);
    }

    private function handleStats(AgentLoop $agent): void
    {
        $stats = app(SessionStatsService::class);
        $currentSessionId = $agent->getSessionManager()->getSessionId();
        $overview = $stats->getOverview($currentSessionId);
        $current = $stats->getSession($currentSessionId);

        $currentLines = [
            $this->formatter()->keyValue('Session', $currentSessionId),
            $this->formatter()->keyValue('Title', $current['title'] ?? 'untitled', 'gray', 'white'),
            $this->formatter()->keyValue('Messages in memory', (string) $agent->getMessageHistory()->count(), 'gray', 'gray'),
            $this->formatter()->keyValue('User turns', (string) $current['user_messages'], 'gray', 'gray'),
            $this->formatter()->keyValue('Assistant turns', (string) $current['assistant_turns'], 'gray', 'gray'),
            $this->formatter()->keyValue('Tool results', (string) $current['tool_results'], 'gray', 'gray'),
            $this->formatter()->keyValue('Input tokens', number_format($agent->getTotalInputTokens()), 'gray', 'gray'),
            $this->formatter()->keyValue('Output tokens', number_format($agent->getTotalOutputTokens()), 'gray', 'gray'),
            $this->formatter()->keyValue('Est. cost', '$'.number_format($agent->getEstimatedCost(), 4), 'gray', 'gray'),
        ];

        if ($current['branch_source'] !== null) {
            $currentLines[] = $this->formatter()->keyValue('Branched from', $current['branch_source'], 'gray', 'gray');
        }
        if ($current['first_activity'] !== null) {
            $currentLines[] = $this->formatter()->keyValue('First activity', $this->formatIsoTimestamp($current['first_activity']), 'gray', 'gray');
        }
        if ($current['last_activity'] !== null) {
            $currentLines[] = $this->formatter()->keyValue('Last activity', $this->formatIsoTimestamp($current['last_activity']), 'gray', 'gray');
            $currentLines[] = $this->formatter()->keyValue('Duration', $this->formatDuration((int) $current['duration_seconds']), 'gray', 'gray');
        }

        $this->renderPanel('Current session stats', $currentLines, addSpacing: false);

        $overviewLines = [
            $this->formatter()->keyValue('Sessions tracked', (string) $overview['sessions_count']),
            $this->formatter()->keyValue('Sessions today', (string) $overview['sessions_today'], 'gray', 'gray'),
            $this->formatter()->keyValue('Active days', (string) $overview['active_days'], 'gray', 'gray'),
            $this->formatter()->keyValue('User turns', (string) $overview['total_user_messages'], 'gray', 'gray'),
            $this->formatter()->keyValue('Assistant turns', (string) $overview['total_assistant_turns'], 'gray', 'gray'),
            $this->formatter()->keyValue('Tool results', (string) $overview['total_tool_results'], 'gray', 'gray'),
        ];

        if ($overview['latest_activity'] !== null) {
            $overviewLines[] = $this->formatter()->keyValue('Latest activity', $this->formatIsoTimestamp($overview['latest_activity']), 'gray', 'gray');
        }

        $this->renderPanel('Overall stats', $overviewLines, addSpacing: false);

        if ($overview['sessions'] !== []) {
            $recentLines = [];
            $recentSessions = array_slice($overview['sessions'], 0, 5);
            foreach ($recentSessions as $index => $session) {
                $label = $session['title'] ?: $session['session_id'];
                $meta = "{$session['user_messages']}u · {$session['assistant_turns']}a · {$session['tool_results']} tools";
                $activity = $session['last_activity'] !== null ? $this->formatIsoTimestamp($session['last_activity']) : 'no activity';
                $recentLines[] = "<fg=yellow>{$label}</> <fg=gray>· {$activity}</>";
                $recentLines[] = "<fg=gray>{$session['session_id']} · {$meta}</>";
                if ($index < count($recentSessions) - 1) {
                    $recentLines[] = '';
                }
            }

            $this->renderPanel('Recent sessions', $recentLines);
        }
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
        $checks[] = ['Provider', $settings->getActiveProviderName() ?? 'default', true];

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
        $current = $settings->getTheme();

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
            $source = isset($skill['source']) ? basename(dirname($skill['source'])) : '';
            $argHint = $skill['argument_hint'] ?? null;

            $line = "{$userInvocable} <fg=yellow>{$name}</>";
            if ($argHint) {
                $line .= " <fg=white>{$argHint}</>";
            }
            if ($desc !== '') {
                $line .= " <fg=gray>— {$desc}</>";
            }
            if ($source !== '') {
                $line .= " <fg=gray>[{$source}]</>";
            }
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '<fg=gray>/ = user-invocable (/skill-name), auto = triggered automatically</>';
        $lines[] = '<fg=gray>Skill directories: ~/.haocode/skills/, .haocode/skills/</>';
        $this->renderPanel('Available skills ('.count($skills).')', $lines);
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

    private function handleLoop(string $args): void
    {
        $args = trim($args);
        if ($args === '') {
            $this->line('<fg=yellow>Usage:</> /loop <interval> <prompt>');
            $this->line('<fg=gray>Examples: /loop 10m check deploy, /loop 1h run tests</>');
            return;
        }

        // Parse interval and prompt
        $interval = '10m';
        $prompt = $args;

        // Try leading interval token
        $parts = explode(' ', $args, 2);
        if (preg_match('/^\d+[smhd]$/', $parts[0] ?? '')) {
            $interval = $parts[0];
            $prompt = $parts[1] ?? '';
        } elseif (preg_match('/every\s+(\d+[smhd])\s*$/i', $args, $m)) {
            $interval = $m[1];
            $prompt = trim(substr($args, 0, -strlen($m[0])));
        }

        if ($prompt === '') {
            $this->line('<fg=red>Error:</> No prompt provided.');
            $this->line('<fg=gray>Usage: /loop <interval> <prompt></>');
            return;
        }

        // Convert interval to cron
        $cron = match (true) {
            preg_match('/^(\d+)s$/', $interval, $m) => '*/' . max(1, (int) ceil((int)$m[1] / 60)) . ' * * * *',
            preg_match('/^(\d+)m$/', $interval, $m) && (int)$m[1] <= 59 => '*/' . $m[1] . ' * * * *',
            preg_match('/^(\d+)m$/', $interval, $m) => '0 */' . ((int)$m[1] / 60) . ' * * *',
            preg_match('/^(\d+)h$/', $interval, $m) && (int)$m[1] <= 23 => '0 */' . $m[1] . ' * * *',
            preg_match('/^(\d+)d$/', $interval, $m) => '0 0 */' . $m[1] . ' * *',
            default => '*/10 * * * *',
        };

        $scheduler = app(\App\Tools\Cron\CronScheduler::class);
        $job = [
            'id' => uniqid('loop_'),
            'cron' => $cron,
            'prompt' => $prompt,
            'recurring' => true,
            'status' => 'active',
            'created_at' => date('c'),
            'last_fired' => null,
            'fire_count' => 0,
            'durable' => false,
        ];
        $scheduler::addJob($job);

        $this->line("<fg=green>Scheduled loop:</> <fg=white>{$prompt}</>");
        $this->line("<fg=gray>Interval:</> <fg=cyan>{$interval}</> <fg=gray>({$cron})</>");
        $this->line("<fg=gray>Job ID:</> <fg=yellow>{$job['id']}</>");
        $this->line("<fg=gray>Use /tasks to view scheduled jobs</>");
    }

    private function handleExport(string $args): void
    {
        $agent = app(AgentLoop::class);
        $messages = $agent->getMessageHistory()->getMessages();

        if (empty($messages)) {
            $this->line('<fg=gray>Nothing to export — conversation is empty.</>');
            return;
        }

        $args = trim($args);
        $timestamp = date('Y-m-d_H-i-s');
        $filename = $args ?: "export_{$timestamp}.md";
        if (! str_ends_with($filename, '.md')) {
            $filename .= '.md';
        }

        $exportDir = storage_path('app/haocode/exports');
        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename;

        $md = "# Hao Code Export\n\n";
        $md .= '**Date:** ' . date('Y-m-d H:i:s') . "\n";
        $md .= '**Session:** ' . $agent->getSessionManager()->getSessionId() . "\n";
        $md .= '**Model:** ' . app(SettingsManager::class)->getModel() . "\n\n---\n\n";

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
            } elseif ($role === 'user' && is_array($content)) {
                // Tool results
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $contentText = is_string($block['content'] ?? '') ? $block['content'] : json_encode($block['content'] ?? '');
                        $toolUseId = $block['tool_use_id'] ?? 'unknown';
                        $md .= "## Tool Result ({$toolUseId})\n\n{$contentText}\n\n";
                    }
                }
            }
        }

        file_put_contents($filepath, $md);
        $this->line("<fg=green>Exported to:</> <fg=white>{$filepath}</>");
    }

    private function handleInit(string $args): void
    {
        $projectPath = getcwd() . '/.haocode';
        $force = trim($args) === '--force';

        if (is_dir($projectPath) && ! $force) {
            $this->line("<fg=yellow>.haocode already exists at:</> <fg=white>{$projectPath}</>");
            $this->line('<fg=gray>Use /init --force to overwrite.</>');
            return;
        }

        if (! is_dir($projectPath)) {
            mkdir($projectPath, 0755, true);
        }

        // Detect project info
        $projectInfo = $this->detectProjectInfo();

        // Write settings.json
        $settingsPath = $projectPath . '/settings.json';
        $defaults = [
            'model' => config('haocode.model', 'claude-sonnet-4-20250514'),
            'permissions' => [
                'allow' => [],
                'deny' => [],
            ],
        ];
        file_put_contents($settingsPath, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Write HAOCODE.md if not exists or forced
        $haocodePath = $projectPath . '/HAOCODE.md';
        if (! file_exists($haocodePath) || $force) {
            $haocodeContent = $this->generateHAOCODEContent($projectInfo);
            file_put_contents($haocodePath, $haocodeContent);
        }

        // Create skills directory
        $skillsDir = $projectPath . '/skills';
        if (! is_dir($skillsDir)) {
            mkdir($skillsDir, 0755, true);
        }

        // Create output-styles directory
        $stylesDir = $projectPath . '/output-styles';
        if (! is_dir($stylesDir)) {
            mkdir($stylesDir, 0755, true);
        }

        $this->line("<fg=green>Initialized project at:</> <fg=white>{$projectPath}</>");
        if (isset($projectInfo['framework'])) {
            $this->line("<fg=gray>Detected framework:</> <fg=cyan>{$projectInfo['framework']}</>");
        }
        if (isset($projectInfo['test_command'])) {
            $this->line("<fg=gray>Detected test command:</> <fg=cyan>{$projectInfo['test_command']}</>");
        }
        $this->line('<fg=gray>Created: settings.json, HAOCODE.md, skills/, output-styles/</>');
    }

    /**
     * Detect project characteristics by scanning common files.
     */
    private function detectProjectInfo(): array
    {
        $cwd = getcwd();

        $backend = $this->detectProjectInfoCandidate(
            $cwd,
            ['.', 'backend', 'api', 'server'],
            fn (array $info): bool => $this->isBackendFramework($info['framework'] ?? 'Unknown'),
        );
        $frontend = $this->detectProjectInfoCandidate(
            $cwd,
            ['frontend', 'web', 'ui', 'client'],
            fn (array $info): bool => $this->isFrontendFramework($info['framework'] ?? 'Unknown'),
        );

        if ($backend !== null && $frontend !== null) {
            return $this->combineFullStackProjectInfo($cwd, $backend, $frontend);
        }

        if ($backend !== null) {
            return $backend['info'];
        }

        if ($frontend !== null) {
            return $frontend['info'];
        }

        return $this->detectProjectInfoForPath($cwd);
    }

    /**
     * Detect project characteristics for a specific directory.
     */
    private function detectProjectInfoForPath(string $cwd): array
    {
        $info = [];

        // Framework detection
        if (file_exists($cwd . '/artisan')) {
            $info['framework'] = 'Laravel';
            $info['test_command'] = 'php artisan test';
        } elseif (file_exists($cwd . '/composer.json')) {
            $composer = json_decode(file_get_contents($cwd . '/composer.json'), true);
            $require = array_keys($composer['require'] ?? []);
            if (in_array('laravel/framework', $require)) {
                $info['framework'] = 'Laravel';
            } else {
                $info['framework'] = 'PHP';
            }
            $info['test_command'] = file_exists($cwd . '/phpunit.xml') ? './vendor/bin/phpunit' : 'php test';
        } elseif (file_exists($cwd . '/package.json')) {
            $package = json_decode(file_get_contents($cwd . '/package.json'), true);
            $deps = array_keys(($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []));
            if (in_array('react', $deps) || in_array('next', $deps)) {
                $info['framework'] = 'React/Next.js';
            } elseif (in_array('vue', $deps)) {
                $info['framework'] = 'Vue';
            } elseif (in_array('@angular/core', $deps)) {
                $info['framework'] = 'Angular';
            } elseif (in_array('svelte', $deps) || in_array('@sveltejs/kit', $deps)) {
                $info['framework'] = 'Svelte';
            } else {
                $info['framework'] = 'Node.js';
            }
            $packageManager = $this->detectNodePackageManager($cwd);
            $info['test_command'] = match ($packageManager) {
                'pnpm' => 'pnpm test',
                'Yarn' => 'yarn test',
                default => 'npm test',
            };
        } elseif (file_exists($cwd . '/Cargo.toml')) {
            $info['framework'] = 'Rust';
            $info['test_command'] = 'cargo test';
        } elseif (file_exists($cwd . '/go.mod')) {
            $info['framework'] = 'Go';
            $info['test_command'] = 'go test ./...';
        } elseif (file_exists($cwd . '/pyproject.toml') || file_exists($cwd . '/requirements.txt')) {
            $info['framework'] = 'Python';
            $info['test_command'] = 'pytest';
        } else {
            $info['framework'] = 'Unknown';
            $info['test_command'] = '';
        }

        // Package manager detection
        if (file_exists($cwd . '/composer.json')) {
            $info['package_manager'] = 'Composer';
        }
        if (file_exists($cwd . '/package.json')) {
            $info['package_manager'] = $this->detectNodePackageManager($cwd);
        }
        if (file_exists($cwd . '/Pipfile')) {
            $info['package_manager'] = 'Pipenv';
        } elseif (file_exists($cwd . '/poetry.lock')) {
            $info['package_manager'] = 'Poetry';
        } elseif (file_exists($cwd . '/requirements.txt')) {
            $info['package_manager'] = $info['package_manager'] ?? 'pip';
        }
        if (file_exists($cwd . '/go.sum')) {
            $info['package_manager'] = 'Go Modules';
        }
        if (file_exists($cwd . '/Cargo.lock')) {
            $info['package_manager'] = 'Cargo';
        }
        if (file_exists($cwd . '/pom.xml')) {
            $info['package_manager'] = 'Maven';
            $info['framework'] = $info['framework'] ?? 'Java';
            $info['test_command'] = $info['test_command'] ?? 'mvn test';
        }
        if (file_exists($cwd . '/build.gradle') || file_exists($cwd . '/build.gradle.kts')) {
            $info['package_manager'] = 'Gradle';
            $info['framework'] = $info['framework'] ?? 'Java';
            $info['test_command'] = $info['test_command'] ?? './gradlew test';
        }

        // Lint/format detection
        $linters = [];
        if (file_exists($cwd . '/.php-cs-fixer.php') || file_exists($cwd . '/.php-cs-fixer.dist.php')) {
            $linters[] = 'PHP-CS-Fixer';
        }
        if (file_exists($cwd . '/pint.json')) {
            $linters[] = 'Laravel Pint';
        }
        if (file_exists($cwd . '/.eslintrc') || file_exists($cwd . '/.eslintrc.js') || file_exists($cwd . '/eslint.config.js') || file_exists($cwd . '/.eslintrc.json')) {
            $linters[] = 'ESLint';
        }
        if (file_exists($cwd . '/.prettierrc') || file_exists($cwd . '/.prettierrc.json') || file_exists($cwd . '/prettier.config.js')) {
            $linters[] = 'Prettier';
        }
        if (file_exists($cwd . '/biome.json') || file_exists($cwd . '/biome.jsonc')) {
            $linters[] = 'Biome';
        }
        if (file_exists($cwd . '/rustfmt.toml') || file_exists($cwd . '/.rustfmt.toml')) {
            $linters[] = 'rustfmt';
        }
        if (file_exists($cwd . '/pyproject.toml')) {
            $pyproject = file_get_contents($cwd . '/pyproject.toml');
            if (str_contains($pyproject, '[tool.ruff]') || str_contains($pyproject, 'ruff')) {
                $linters[] = 'Ruff';
            }
            if (str_contains($pyproject, '[tool.black]') || str_contains($pyproject, 'black')) {
                $linters[] = 'Black';
            }
        }
        if ($linters !== []) {
            $info['linter'] = implode(', ', $linters);
        }

        // CI detection
        if (is_dir($cwd . '/.github/workflows')) {
            $info['ci'] = 'GitHub Actions';
        } elseif (file_exists($cwd . '/.gitlab-ci.yml')) {
            $info['ci'] = 'GitLab CI';
        } elseif (file_exists($cwd . '/Jenkinsfile')) {
            $info['ci'] = 'Jenkins';
        } elseif (file_exists($cwd . '/.circleci/config.yml')) {
            $info['ci'] = 'CircleCI';
        }

        // Monorepo detection
        if (file_exists($cwd . '/lerna.json') || file_exists($cwd . '/pnpm-workspace.yaml')) {
            $info['structure'] = 'monorepo';
        }

        return $info;
    }

    /**
     * @param array<int, string> $relativePaths
     * @param callable(array): bool $accept
     * @return array{path: string, relative_path: string, info: array}|null
     */
    private function detectProjectInfoCandidate(string $root, array $relativePaths, callable $accept): ?array
    {
        foreach ($relativePaths as $relativePath) {
            $path = $relativePath === '.' ? $root : $root . '/' . $relativePath;

            if (!is_dir($path)) {
                continue;
            }

            $info = $this->detectProjectInfoForPath($path);
            if (($info['framework'] ?? 'Unknown') === 'Unknown') {
                continue;
            }

            if ($accept($info)) {
                return [
                    'path' => $path,
                    'relative_path' => $relativePath === '.' ? '' : $relativePath,
                    'info' => $info,
                ];
            }
        }

        return null;
    }

    private function isBackendFramework(string $framework): bool
    {
        return in_array($framework, ['Laravel', 'PHP', 'Rust', 'Go', 'Python'], true);
    }

    private function isFrontendFramework(string $framework): bool
    {
        return in_array($framework, ['React/Next.js', 'Vue', 'Angular', 'Svelte'], true);
    }

    /**
     * @param array{path: string, relative_path: string, info: array} $backend
     * @param array{path: string, relative_path: string, info: array} $frontend
     */
    private function combineFullStackProjectInfo(string $root, array $backend, array $frontend): array
    {
        $info = [];
        $info['framework'] = sprintf(
            'Full-stack (%s + %s)',
            $backend['info']['framework'] ?? 'Backend',
            $frontend['info']['framework'] ?? 'Frontend',
        );

        $testCommands = array_filter([
            $this->scopeProjectCommand($backend['relative_path'], $backend['info']['test_command'] ?? ''),
            $this->scopeProjectCommand($frontend['relative_path'], $frontend['info']['test_command'] ?? ''),
        ]);
        if ($testCommands !== []) {
            $info['test_command'] = implode(' && ', array_values(array_unique($testCommands)));
        }

        $packageManagers = array_filter([
            $backend['info']['package_manager'] ?? null,
            $frontend['info']['package_manager'] ?? null,
        ]);
        if ($packageManagers !== []) {
            $info['package_manager'] = implode(' + ', array_values(array_unique($packageManagers)));
        }

        $linters = [];
        foreach ([$backend['info']['linter'] ?? '', $frontend['info']['linter'] ?? ''] as $value) {
            foreach (array_map('trim', explode(',', $value)) as $item) {
                if ($item !== '') {
                    $linters[] = $item;
                }
            }
        }
        if ($linters !== []) {
            $info['linter'] = implode(', ', array_values(array_unique($linters)));
        }

        $ci = $this->detectProjectInfoForPath($root)['ci'] ?? ($backend['info']['ci'] ?? $frontend['info']['ci'] ?? null);
        if (is_string($ci) && $ci !== '') {
            $info['ci'] = $ci;
        }

        $rootInfo = $this->detectProjectInfoForPath($root);
        $info['structure'] = ($rootInfo['structure'] ?? null) === 'monorepo'
            ? 'full-stack monorepo'
            : 'full-stack';

        return $info;
    }

    private function scopeProjectCommand(string $relativePath, string $command): string
    {
        if ($command === '') {
            return '';
        }

        if ($relativePath === '') {
            return $command;
        }

        return "(cd {$relativePath} && {$command})";
    }

    private function detectNodePackageManager(string $cwd): string
    {
        if (file_exists($cwd . '/pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if (file_exists($cwd . '/yarn.lock')) {
            return 'Yarn';
        }
        if (file_exists($cwd . '/package-lock.json')) {
            return 'npm';
        }

        return 'npm';
    }

    /**
     * Generate HAOCODE.md content based on detected project info.
     */
    private function generateHAOCODEContent(array $info): string
    {
        $framework = $info['framework'] ?? 'Project';
        $testCmd = $info['test_command'] ?? '';
        $linter = $info['linter'] ?? '';
        $formatter = $info['formatter'] ?? '';
        $ci = $info['ci'] ?? '';
        $structure = $info['structure'] ?? '';
        $packageManager = $info['package_manager'] ?? '';

        $lines = [];
        $lines[] = "# {$framework} Project Instructions";
        $lines[] = '';
        $lines[] = '## Conventions';
        $lines[] = '';
        $lines[] = "- This is a {$framework} project.";
        if ($structure) {
            $lines[] = "- Repository structure: {$structure}.";
        }
        if ($packageManager) {
            $lines[] = "- Package manager(s): {$packageManager}.";
        }
        if ($testCmd) {
            $lines[] = "- Run tests with: `{$testCmd}`";
        }
        if ($linter) {
            $lines[] = "- Use {$linter} for linting.";
        }
        if ($formatter) {
            $lines[] = "- Use {$formatter} for formatting.";
        }
        if ($ci) {
            $lines[] = "- CI/CD runs on {$ci}.";
        }
        $lines[] = '';
        $lines[] = '## Guidelines';
        $lines[] = '';
        $lines[] = '- Do not create files unless absolutely necessary.';
        $lines[] = '- Be careful not to introduce security vulnerabilities.';
        $lines[] = '- Keep responses short and concise.';
        $lines[] = '- Only use emojis if explicitly requested.';

        return implode("\n", $lines) . "\n";
    }

    private function handleVersion(): void
    {
        $version = $this->displayPackageVersion();

        $settings = app(SettingsManager::class);
        $toolCount = count(app(ToolRegistry::class)->getAllTools());

        $this->line("\n  <fg=cyan;bold>Hao Code</> <fg=white>{$version}</>");
        $this->line('  PHP:     <fg=white>'.PHP_VERSION.'</>');
        $this->line('  Laravel: <fg=white>'.app()->version().'</>');
        $this->line('  Provider:<fg=white> '.($settings->getActiveProviderName() ?? 'default').'</>');
        $this->line('  Model:   <fg=white>'.$settings->getResolvedModelIdentifier().'</>');
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

    private function handleBuddy(string $args): void
    {
        $buddy = app(BuddyManager::class);
        $args = trim($args);

        // Default: show card or hatch prompt
        if ($args === '' || $args === 'card' || $args === 'status') {
            if (! $buddy->isHatched()) {
                $this->line('');
                $this->line('  <fg=cyan;bold>Buddy System</>');
                $this->line('  <fg=gray>No companion hatched yet.</>');
                $this->line('  <fg=white>Use: /buddy hatch <name></>');
                $this->line('');
                return;
            }

            $cardLines = $buddy->getCard();
            $this->line('');
            $this->line('  <fg=cyan;bold>Companion Card</>');
            foreach ($cardLines as $line) {
                $this->line("  {$line}");
            }
            $this->line('');
            return;
        }

        if (str_starts_with($args, 'hatch ')) {
            $name = trim(substr($args, 6));
            if ($name === '') {
                $this->line('<fg=red>Please provide a name: /buddy hatch <name></>');
                return;
            }

            if ($buddy->isHatched()) {
                $companion = $buddy->getCompanion();
                $this->line("<fg=yellow>You already have a companion: {$companion['name']} the {$companion['species']}.</>");
                $this->line('<fg=gray>Use /buddy release first, or /buddy rename <name> to rename.</>');
                return;
            }

            $personality = "A loyal {$name} who loves to observe your code and offer unsolicited opinions.";
            $companion = $buddy->hatch($name, $personality);
            $rarity = $companion['rarity'];
            $stars = \App\Services\Buddy\CompanionTypes::RARITY_STARS[$rarity];
            $color = \App\Services\Buddy\CompanionTypes::RARITY_COLORS[$rarity];
            $shiny = $companion['shiny'] ? ' <fg=yellow>✨ SHINY!</>' : '';

            $this->line('');
            $this->line("  <fg=green;bold>🥚 Companion hatched!</>");
            $this->line("  <fg=white>{$name}</> the <fg=white>{$companion['species']}</>");
            $this->line("  <fg={$color}>{$stars}</>{$shiny}");
            $this->line('');

            // Show the sprite
            foreach ($buddy->getFrame(0) as $spriteLine) {
                $this->line("  <fg=white>{$spriteLine}</>");
            }
            $this->line('');
            return;
        }

        if ($args === 'pet') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $buddy->pet();
            $companion = $buddy->getCompanion();
            $this->line('');
            $this->line("  <fg=magenta>You pet {$companion['name']}!</> <fg=gray>They look very happy.</>");
            // Show hearts animation
            foreach (BuddyManager::PET_HEARTS as $heartLine) {
                $this->line("  <fg=magenta>{$heartLine}</>");
            }
            $this->line('');
            return;
        }

        if ($args === 'feed') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $companion = $buddy->getCompanion();
            $treats = ['a tiny pizza', 'a pixel cookie', 'some byte-sized snacks', 'a bowl of ramen', 'a debugging donut'];
            $treat = $treats[array_rand($treats)];
            $buddy->quip('excited');
            $this->line('');
            $this->line("  <fg=green>You feed {$companion['name']} {$treat}!</> <fg=gray>They munch happily.</>");
            $this->line('');
            return;
        }

        if ($args === 'mute') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $buddy->mute();
            $companion = $buddy->getCompanion();
            $this->line("<fg=gray>{$companion['name']} has been muted. Use /buddy unmute to bring them back.</>");
            return;
        }

        if ($args === 'unmute') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $buddy->unmute();
            $companion = $buddy->getCompanion();
            $this->line("<fg=green>{$companion['name']} is back!</>");
            return;
        }

        if ($args === 'release') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>No companion to release.</>');
                return;
            }
            $companion = $buddy->getCompanion();
            $name = $companion['name'];
            $buddy->release();
            $this->line('');
            $this->line("  <fg=yellow>🕊️ {$name} has been released back into the wild.</>");
            $this->line('  <fg=gray>They wave goodbye with a tiny wing/hand/appendage.</>');
            $this->line('');
            return;
        }

        if (str_starts_with($args, 'rename ')) {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $newName = trim(substr($args, 7));
            if ($newName === '') {
                $this->line('<fg=red>Usage: /buddy rename <new_name></>');
                return;
            }
            $companion = $buddy->getCompanion();
            $oldName = $companion['name'];
            $buddy->rename($newName);
            $this->line("<fg=green>{$oldName} is now called {$newName}!</>");
            return;
        }

        if ($args === 'face') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $face = $buddy->getFace();
            $companion = $buddy->getCompanion();
            $this->line("  <fg=white>{$face}</> <fg=cyan>{$companion['name']}</>");
            return;
        }

        if ($args === 'quip') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $buddy->quip($buddy->getCurrentMood());
            $quip = $buddy->getQuip();
            $this->line("  <fg=gray>{$quip}</>");
            return;
        }

        if ($args === 'mood') {
            if (! $buddy->isHatched()) {
                $this->line('<fg=gray>Hatch a companion first: /buddy hatch <name></>');
                return;
            }
            $companion = $buddy->getCompanion();
            $mood = $buddy->getCurrentMood();
            $emoji = $buddy->getMoodEmoji();
            $this->line("  {$emoji} <fg=white>{$companion['name']}</> is feeling <fg=cyan>{$mood}</>.");
            return;
        }

        $this->line('<fg=yellow>Usage:</> /buddy [card|status|hatch <name>|pet|feed|mute|unmute|release|rename <name>|face|quip|mood]');
    }

    private function handleDream(string $args): void
    {
        $consolidator = app(\App\Services\Memory\DreamConsolidator::class);
        $stats = $consolidator->getMemoryStats();

        $lines = [
            $this->formatter()->keyValue('Memories', (string)$stats['count']),
            $this->formatter()->keyValue('Total chars', (string)$stats['total_chars']),
        ];

        if ($stats['last_consolidated'] > 0) {
            $lastDate = date('Y-m-d H:i', (int)($stats['last_consolidated'] / 1000));
            $lines[] = $this->formatter()->keyValue('Last consolidated', $lastDate);
        } else {
            $lines[] = $this->formatter()->keyValue('Last consolidated', 'never');
        }

        $lines[] = '';
        $lines[] = '<fg=gray>Running memory consolidation...</>';

        $this->renderPanel('Dream: Memory Consolidation', $lines);

        // Record the consolidation timestamp
        $consolidator->recordConsolidation();

        // Send consolidation prompt to the agent
        $prompt = $consolidator->buildConsolidationPrompt(
            $consolidator->getMemoryRoot(),
            $consolidator->getTranscriptDir(),
        );

        $this->line('  <fg=cyan>Starting dream consolidation...</>');
        $this->runAgentTurn(app(\App\Services\Agent\AgentLoop::class), $prompt);
    }

    private function runAgentTurn(AgentLoop $agent, string|array $input): void
    {
        $this->line('');
        // Extract text for display purposes (spinner, HUD)
        $inputText = is_string($input) ? $input : $this->extractTextFromContentBlocks($input);
        $streamTextOutput = $this->shouldStreamAssistantText();
        $renderedLiveText = false;
        $markdownRenderer = app(MarkdownRenderer::class);
        $markdownOutput = $this->createStreamingMarkdownOutput($markdownRenderer);
        $turnStatus = $this->createTurnStatusRenderer($inputText);
        $previousAlarmHandler = $this->startTurnStatusTicker($turnStatus);
        $toolResultRenderer = new ToolResultRenderer();
        $lastToolInput = [];

        try {
            $this->recordTurnHudEvent('turn.started', $this->summarizeTurnDetail($inputText));
            $turnStatus->start();

            $response = $agent->run(
                userInput: $input,
                onTextDelta: function (string $text) use (&$renderedLiveText, $turnStatus, $markdownOutput, $streamTextOutput) {
                    $turnStatus->recordTextDelta($text);

                    if (! $streamTextOutput) {
                        return;
                    }

                    $turnStatus->pause();
                    $renderedLiveText = true;
                    $markdownOutput->append($text);
                },
                onToolStart: function (string $toolName, array $toolInput) use ($turnStatus, $markdownOutput, $streamTextOutput, &$lastToolInput) {
                    $lastToolInput = ['name' => $toolName, 'input' => $toolInput];
                    $turnStatus->pause();
                    if ($streamTextOutput) {
                        $markdownOutput->finalize();
                    }
                    $args = $this->summarizeToolInput($toolName, $toolInput);
                    $this->recordTurnHudEvent('tool.started', $this->summarizeTurnDetail(trim($toolName.($args !== '' ? ': '.$args : ''))));
                    $activityDesc = $this->getActivityDescription($toolName, $toolInput);
                    $this->line("\n".$this->formatter()->toolCall($toolName, $args));
                    $turnStatus->setPhaseLabel($activityDesc ?? $toolName);
                    $turnStatus->resume();
                },
                onToolComplete: function (string $toolName, $result) use ($turnStatus, $toolResultRenderer, &$lastToolInput) {
                    $turnStatus->pause();
                    $turnStatus->setPhaseLabel(null);
                    $event = in_array($toolName, ['TodoWrite', 'TaskCreate', 'TaskUpdate', 'TaskStop'], true)
                        ? 'plan.updated'
                        : 'tool.completed';
                    $detail = $toolName;
                    $input = ($lastToolInput['name'] ?? '') === $toolName ? ($lastToolInput['input'] ?? []) : [];
                    $rendered = $toolResultRenderer->render($toolName, $input, (string) $result->output, $result->isError);
                    if ($rendered !== null) {
                        $this->line($rendered);
                    } elseif ($result->isError) {
                        $message = trim((string) $result->output);
                        $detail = trim($toolName.' · '.($message === '' ? 'Unknown error' : $message));
                        $this->line($this->formatter()->toolFailure($toolName, $message === '' ? 'Unknown error' : $message));
                    }
                    $this->recordTurnHudEvent($event, $this->summarizeTurnDetail($detail));
                    $turnStatus->resume();
                },
            );

            $turnStatus->pause();
            if ($streamTextOutput) {
                $markdownOutput->finalize();
            }
            if ($response === '(aborted)') {
                $this->recordTurnHudEvent('turn.failed', 'aborted');
                if ($renderedLiveText) {
                    $this->line('');
                }
                $this->line($this->formatter()->interruptedStatus());
                return;
            }
            $this->recordTurnHudEvent('turn.completed', $this->summarizeTurnDetail($response));
            if (! $renderedLiveText && $response !== '') {
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

            // Auto-dream: background memory consolidation check
            $autoDream = app(\App\Services\Memory\AutoDreamService::class);
            $dreamResult = $autoDream->maybeExecute();
            if ($dreamResult !== null && ($dreamResult['triggered'] ?? false)) {
                $this->line("  <fg=gray>✨ Auto-dream triggered ({$dreamResult['hours_since']}h since last, {$dreamResult['sessions_reviewed']} sessions)</>");
            }

        } catch (ApiErrorException $e) {
            $turnStatus->pause();
            $markdownOutput->finalize();
            if ($agent->isAborted()) {
                $this->recordTurnHudEvent('turn.failed', 'aborted');
                $this->line($this->formatter()->interruptedStatus()."\n");
                return;
            }
            $this->recordTurnHudEvent('turn.failed', $this->summarizeTurnDetail($e->getMessage()));
            $this->line("\n  <fg=red>API Error ({$e->getErrorType()}): {$e->getMessage()}</>\n");
        } catch (\Throwable $e) {
            $turnStatus->pause();
            $markdownOutput->finalize();
            if ($agent->isAborted()) {
                $this->recordTurnHudEvent('turn.failed', 'aborted');
                $this->line($this->formatter()->interruptedStatus()."\n");
                return;
            }
            $this->recordTurnHudEvent('turn.failed', $this->summarizeTurnDetail($e->getMessage()));
            $this->line("\n  <fg=red>Error: {$e->getMessage()}</>\n");
            if (config('app.debug')) {
                $this->line("  <fg=gray>{$e->getFile()}:{$e->getLine()}</>\n");
            }
        } finally {
            $this->stopTurnStatusTicker($turnStatus, $previousAlarmHandler);
            $this->refreshPromptHudState($agent);
        }
    }

    private function promptToolPermission(string $toolName, array $input): bool
    {
        foreach ($this->sessionAllowRules as $rule) {
            if ($this->matchesPermissionRule($rule, $toolName, $input)) {
                return true;
            }
        }

        $lines = $this->buildPermissionPreview($toolName, $input);

        $this->renderPanel(
            'Permission required',
            $lines,
            'yellow',
            addSpacing: false,
        );

        $handle = fopen('php://stdin', 'r');
        if ($handle === false) {
            return false;
        }

        try {
            $answer = $this->readInteractivePermissionPromptAnswer($handle);
        } finally {
            fclose($handle);
        }

        if (in_array($answer, ['a', 'always'], true)) {
            $rule = $this->buildPermissionRule($toolName, $input);
            if ($rule !== null && ! in_array($rule, $this->sessionAllowRules, true)) {
                $this->sessionAllowRules[] = $rule;
            }

            return true;
        }

        return in_array($answer, ['y', 'yes', ''], true);
    }

    /**
     * Build permission preview lines with context-aware content per tool type.
     *
     * @return array<int, string>
     */
    private function buildPermissionPreview(string $toolName, array $input): array
    {
        $lines = [];

        switch ($toolName) {
            case 'Bash':
                $cmd = $input['command'] ?? '';
                $desc = $input['description'] ?? null;
                $lines[] = $this->formatter()->keyValue('Tool', 'Bash');
                if ($desc) {
                    $lines[] = $this->formatter()->keyValue('Action', $desc, 'gray', 'white');
                }
                // Show command preview (truncated, colored)
                $cmdPreview = $this->truncate(str_replace("\n", '\\n', $cmd), 120);
                $lines[] = '<fg=gray>Command:</> <fg=yellow>' . OutputFormatter::escape($cmdPreview) . '</>';
                break;

            case 'Edit':
                $file = $input['file_path'] ?? '';
                $old = $input['old_string'] ?? '';
                $new = $input['new_string'] ?? '';
                $lines[] = $this->formatter()->keyValue('Tool', 'Edit');
                $lines[] = $this->formatter()->keyValue('File', basename($file), 'gray', 'cyan');
                // Show mini diff preview
                if ($old !== '' || $new !== '') {
                    $oldPreview = $this->truncate(str_replace("\n", '\\n', $old), 80);
                    $newPreview = $this->truncate(str_replace("\n", '\\n', $new), 80);
                    $lines[] = '<fg=red>- ' . OutputFormatter::escape($oldPreview) . '</>';
                    $lines[] = '<fg=green>+ ' . OutputFormatter::escape($newPreview) . '</>';
                }
                break;

            case 'Write':
                $file = $input['file_path'] ?? '';
                $content = $input['content'] ?? '';
                $lineCount = substr_count($content, "\n") + 1;
                $bytes = strlen($content);
                $lines[] = $this->formatter()->keyValue('Tool', 'Write');
                $lines[] = $this->formatter()->keyValue('File', basename($file), 'gray', 'cyan');
                $exists = file_exists($file);
                $lines[] = '<fg=gray>' . ($exists ? 'Overwrite' : 'Create') . ": {$lineCount} lines, {$bytes} bytes</>";
                break;

            case 'Agent':
                $type = $input['subagent_type'] ?? 'general-purpose';
                $desc = $input['description'] ?? '';
                $lines[] = $this->formatter()->keyValue('Tool', "Agent ({$type})");
                if ($desc !== '') {
                    $lines[] = $this->formatter()->keyValue('Task', $desc, 'gray', 'white');
                }
                break;

            default:
                $args = $this->summarizeToolInput($toolName, $input);
                $lines[] = $this->formatter()->keyValue('Tool', $toolName);
                if ($args !== '') {
                    $lines[] = $this->formatter()->keyValue('Target', $args, 'gray', 'gray');
                }
                break;
        }

        $lines[] = '<fg=green>[y]</> allow once  <fg=green>[a]</> always allow  <fg=red>[n]</> deny';

        return $lines;
    }

    private function readInteractivePermissionPromptAnswer($handle): string
    {
        if (! stream_isatty(STDIN) || ! function_exists('shell_exec')) {
            return $this->readPermissionPromptAnswer($handle, false);
        }

        $sttyMode = trim((string) shell_exec('stty -g 2>/dev/null'));
        if ($sttyMode === '') {
            return $this->readPermissionPromptAnswer($handle, false);
        }

        shell_exec('stty raw -echo 2>/dev/null');

        try {
            $answer = $this->readPermissionPromptAnswer($handle, true);
        } finally {
            shell_exec("stty {$sttyMode} 2>/dev/null");
        }

        $this->writeRaw(($answer === '' ? '' : $answer) . "\r\n");

        return $answer;
    }

    private function readPermissionPromptAnswer($handle, bool $rawMode): string
    {
        if (! $rawMode) {
            $answer = fgets($handle);

            return $answer === false ? '' : strtolower(trim($answer));
        }

        $answer = $this->readRawCharacter($handle);
        if ($answer === false || $answer === '') {
            return '';
        }

        if ($answer === "\x1b") {
            $sequence = $this->readEscapeSequence($handle);
            $answer = $sequence === false ? '' : $answer . $sequence;
        }

        if ($answer === "\r" || $answer === "\n") {
            return '';
        }

        if ($answer === "\x03") {
            return 'n';
        }

        return strtolower(trim($answer));
    }

    private function buildPermissionRule(string $toolName, array $input): ?string
    {
        $value = match ($toolName) {
            'Bash' => $this->extractBashRulePrefix($input['command'] ?? ''),
            'Read', 'Edit', 'Write' => $this->extractFileRulePattern($input['file_path'] ?? ''),
            'Glob', 'Grep' => $input['pattern'] ?? null,
            'WebFetch' => $input['url'] ?? null,
            'Agent' => $input['subagent_type'] ?? null,
            default => null,
        };

        if (! is_string($value) || trim($value) === '') {
            return $toolName;
        }

        return sprintf('%s(%s)', $toolName, $value);
    }

    /**
     * Extract a permission rule prefix from a bash command.
     * e.g., "git push origin main" → "git:*" to allow all git commands.
     */
    private function extractBashRulePrefix(?string $command): ?string
    {
        if ($command === null || trim($command) === '') {
            return null;
        }

        $words = preg_split('/\s+/', trim($command));
        $base = $words[0] ?? '';

        // For common commands, create a prefix rule
        if (in_array($base, ['git', 'npm', 'yarn', 'pnpm', 'composer', 'php', 'node', 'python', 'pip', 'cargo', 'make', 'go', 'docker'], true)) {
            return $base . ':*';
        }

        return $command;
    }

    /**
     * Extract a file permission rule pattern.
     * e.g., "/Users/foo/project/src/file.php" → "/Users/foo/project/src/*"
     */
    private function extractFileRulePattern(?string $filePath): ?string
    {
        if ($filePath === null || trim($filePath) === '') {
            return null;
        }

        $dir = dirname($filePath);
        if ($dir === '.' || $dir === '') {
            return $filePath;
        }

        return $dir . '/*';
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

    /**
     * @return array<int, string>
     */
    private function extractContextFiles(array $messages): array
    {
        $files = [];
        $cwd = rtrim(str_replace('\\', '/', (string) getcwd()), '/');

        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            $content = $message['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                if (! in_array($block['name'] ?? '', ['Read', 'Edit', 'Write'], true)) {
                    continue;
                }

                $filePath = $block['input']['file_path'] ?? null;
                if (! is_string($filePath) || trim($filePath) === '') {
                    continue;
                }

                $normalized = $this->normalizeContextFilePath($filePath, $cwd);
                $files[$normalized] = $normalized;
            }
        }

        return array_values($files);
    }

    private function normalizeContextFilePath(string $filePath, string $cwd): string
    {
        $normalized = str_replace('\\', '/', trim($filePath));

        if ($normalized === '') {
            return $normalized;
        }

        if ($cwd !== '' && str_starts_with($normalized, $cwd.'/')) {
            return ltrim(substr($normalized, strlen($cwd)), '/');
        }

        return ltrim($normalized, './');
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeArguments(string $args): array
    {
        $args = trim($args);
        if ($args === '') {
            return [];
        }

        preg_match_all('/"((?:\\\\.|[^"])*)"|\'((?:\\\\.|[^\'])*)\'|(\\S+)/', $args, $matches, PREG_SET_ORDER);

        return array_values(array_map(function (array $match): string {
            $value = $match[1] !== ''
                ? stripcslashes($match[1])
                : ($match[2] !== '' ? stripcslashes($match[2]) : ($match[3] ?? ''));

            return trim($value);
        }, $matches));
    }

    private function chooseStartupPrompt(mixed $printPrompt, mixed $deprecatedPrompt, mixed $argumentPrompt): ?string
    {
        foreach ([$printPrompt, $deprecatedPrompt, $argumentPrompt] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $tokens
     * @return array{0: array<string, array<int, string>>, 1: array<int, string>}
     */
    private function parseLongOptions(array $tokens): array
    {
        $options = [];
        $positionals = [];

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if (! str_starts_with($token, '--')) {
                $positionals[] = $token;

                continue;
            }

            $withoutPrefix = substr($token, 2);
            if (str_contains($withoutPrefix, '=')) {
                [$name, $value] = explode('=', $withoutPrefix, 2);
                $options[$name][] = $value;

                continue;
            }

            $name = $withoutPrefix;
            $next = $tokens[$index + 1] ?? null;
            if ($next !== null && ! str_starts_with($next, '--')) {
                $options[$name][] = $next;
                $index++;

                continue;
            }

            $options[$name][] = 'true';
        }

        return [$options, $positionals];
    }

    /**
     * @param array<int, string> $values
     * @return array<string, string>
     */
    private function parseKeyValueOptions(array $values): array
    {
        $parsed = [];

        foreach ($values as $value) {
            if (! str_contains($value, '=')) {
                continue;
            }

            [$key, $entryValue] = explode('=', $value, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $parsed[$key] = $entryValue;
        }

        ksort($parsed);

        return $parsed;
    }

    /**
     * @param array<int, string> $values
     * @return array<string, string>
     */
    private function parseHeaderOptions(array $values): array
    {
        $parsed = [];

        foreach ($values as $value) {
            if (! str_contains($value, ':')) {
                continue;
            }

            [$key, $entryValue] = explode(':', $value, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $parsed[$key] = trim($entryValue);
        }

        ksort($parsed);

        return $parsed;
    }

    private function buildCommitPrompt(string $args): string
    {
        $branch = trim((string) shell_exec('git branch --show-current 2>/dev/null')) ?: 'unknown';
        $status = $this->collectCommandOutput('git status --short --branch 2>/dev/null', 4000);
        $diffStat = $this->collectCommandOutput('git diff --stat HEAD 2>/dev/null', 4000);
        $diff = $this->collectCommandOutput('git diff HEAD --no-color 2>/dev/null | head -250', 12000);
        $recentCommits = $this->collectCommandOutput('git log --oneline -10 2>/dev/null', 4000);
        $extraInstruction = trim($args);

        return <<<PROMPT
Create a git commit for the current repository changes.

Repository context:
- Current branch: {$branch}

Git status:
{$status}

Diff stat:
{$diffStat}

Recent commits:
{$recentCommits}

Working tree diff preview:
{$diff}

Git safety protocol:
- Never run `git commit --amend`.
- Never use `--no-verify`, `--no-gpg-sign`, or interactive git flags unless the user explicitly asked.
- Do not commit secrets such as `.env` files, credentials, or private keys.
- If there is nothing meaningful to commit, stop and explain why.
- Stage only the relevant files for the current change.
- Create exactly one new commit.

Task:
1. Analyze the changes and infer the repository's commit style from recent history.
2. Stage the relevant files by name (avoid `git add -A` or `git add .`).
3. Create a single commit with a concise message focused on the "why" rather than the "what".
4. IMPORTANT: Pass the commit message via a HEREDOC to ensure correct formatting:
   git commit -m "\$(cat <<'EOF'
   <commit message here>

   Co-Authored-By: Claude <noreply@anthropic.com>
   EOF
   )"
5. After the commit succeeds, report the final commit hash and subject line.

Commit message guidelines:
- Follow the repository's commit style (look at recent commits above).
- If no clear style, use conventional commits: type(scope): description
  Types: feat, fix, refactor, docs, test, chore, perf, style, ci
- Summarize the nature of the change in 1-2 sentences.
- Focus on intent and purpose, not what files changed.
- Always add Co-Authored-By attribution as shown above.
PROMPT
        . ($extraInstruction !== '' ? "\n\nExtra user instruction:\n{$extraInstruction}\n" : '');
    }

    private function buildReviewPrompt(string $args): string
    {
        $git = app(GitContext::class);
        $target = trim($args);
        $branch = $git->getCurrentBranch();
        $defaultBranch = $git->getDefaultBranch() ?: 'main';
        $status = $this->collectCommandOutput('git status --short --branch 2>/dev/null', 4000);
        $recentCommits = $this->collectCommandOutput('git log --oneline -10 2>/dev/null', 4000);
        $diffCommand = "git diff --no-color {$defaultBranch}...HEAD 2>/dev/null | head -300";
        $reviewLabel = "current branch against {$defaultBranch}";
        $prContext = '';

        if ($target !== '') {
            $reviewLabel = "PR {$target}";
            $prView = $this->collectCommandOutput('gh pr view '.escapeshellarg($target).' 2>/dev/null', 6000);
            $prDiff = $this->collectCommandOutput('gh pr diff '.escapeshellarg($target).' 2>/dev/null | head -300', 16000);

            if ($prView !== '(no output)' || $prDiff !== '(no output)') {
                $prContext = "PR details:\n{$prView}\n\nPR diff preview:\n{$prDiff}";
            }
        }

        $diff = $prContext !== ''
            ? $prContext
            : "Branch diff preview:\n".$this->collectCommandOutput($diffCommand, 16000);

        return <<<PROMPT
Review {$reviewLabel} like a rigorous code reviewer.

Repository context:
- Current branch: {$branch}
- Default branch: {$defaultBranch}

Git status:
{$status}

Recent commits:
{$recentCommits}

{$diff}

Review instructions:
- Findings come first, ordered by severity.
- Tag each finding with a severity level: [CRITICAL], [HIGH], [MEDIUM], or [LOW].
- Check each of these categories:
  1. Code correctness — logic bugs, off-by-one, null handling, type errors
  2. Security — injection, auth bypass, secrets exposure, OWASP top 10
  3. Performance — N+1 queries, missing indexes, unnecessary allocations
  4. Behavioral regressions — does this change break existing behavior?
  5. Test coverage — are new paths tested? Are edge cases covered?
  6. API compatibility — breaking changes, missing migration, backwards compat
  7. Following project conventions — naming, patterns, style consistency
- Cite concrete files, functions, or code paths when possible.
- If you find no issues, say that explicitly and mention any residual risks or testing gaps.
- Keep the summary brief after the findings.
PROMPT;
    }

    private function collectCommandOutput(string $command, int $maxLength = 8000): string
    {
        $output = trim((string) shell_exec($command));
        if ($output === '') {
            return '(no output)';
        }

        return $this->truncate($output, $maxLength);
    }

    /**
     * @param array<int, string> $rules
     */
    private function runAgentTurnWithSessionRules(AgentLoop $agent, string $input, array $rules): void
    {
        $previousRules = $this->sessionAllowRules;
        foreach ($rules as $rule) {
            if (! in_array($rule, $this->sessionAllowRules, true)) {
                $this->sessionAllowRules[] = $rule;
            }
        }

        try {
            $this->runAgentTurn($agent, $input);
        } finally {
            $this->sessionAllowRules = $previousRules;
        }
    }

    private function formatIsoTimestamp(string $timestamp): string
    {
        $unix = strtotime($timestamp);

        return $unix === false ? $timestamp : date('Y-m-d H:i', $unix);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$hours}h {$minutes}m";
    }

    private function normalizeConfigKey(string $key): ?string
    {
        return match (strtolower(trim($key))) {
            'model' => 'model',
            'active_provider', 'active-provider', 'provider' => 'active_provider',
            'api_base_url', 'api-base-url', 'api', 'base-url' => 'api_base_url',
            'max_tokens', 'max-tokens', 'tokens' => 'max_tokens',
            'permission_mode', 'permission-mode', 'permission', 'permissions' => 'permission_mode',
            'theme' => 'theme',
            'output_style', 'output-style', 'style' => 'output_style',
            'stream_output', 'stream-output', 'stream', 'streaming' => 'stream_output',
            default => null,
        };
    }

    /**
     * @return array<int, array{scope: string, path: string}>
     */
    private function configuredSettingsPaths(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $globalPath = config('haocode.global_settings_path') ?: $home.'/.haocode/settings.json';

        return [
            ['scope' => 'global', 'path' => $globalPath],
            ['scope' => 'project', 'path' => getcwd().'/.haocode/settings.json'],
        ];
    }

    /**
     * @return array<int, array{event: string, command: string, matcher: ?string, scope: string, path: string}>
     */
    private function loadConfiguredHooks(): array
    {
        $hooks = [];

        foreach ($this->configuredSettingsPaths() as $entry) {
            if (! file_exists($entry['path'])) {
                continue;
            }

            $settings = json_decode((string) file_get_contents($entry['path']), true);
            if (! is_array($settings) || ! isset($settings['hooks']) || ! is_array($settings['hooks'])) {
                continue;
            }

            foreach ($settings['hooks'] as $event => $eventHooks) {
                if (! is_array($eventHooks)) {
                    continue;
                }

                foreach ($eventHooks as $hookConfig) {
                    if (! is_array($hookConfig) || ! isset($hookConfig['command']) || ! is_string($hookConfig['command'])) {
                        continue;
                    }

                    $hooks[] = [
                        'event' => (string) $event,
                        'command' => $hookConfig['command'],
                        'matcher' => isset($hookConfig['matcher']) && is_string($hookConfig['matcher']) ? $hookConfig['matcher'] : null,
                        'scope' => $entry['scope'],
                        'path' => $entry['path'],
                    ];
                }
            }
        }

        return $hooks;
    }

    private function formatSettingValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'off';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'unknown';
    }

    /**
     * @return array<int, string>
     */
    private function availableModelChoices(SettingsManager $settings): array
    {
        $choices = SettingsManager::getAvailableModels();

        foreach ($settings->getConfiguredProviders() as $name => $provider) {
            if (is_string($provider['model'] ?? null) && trim($provider['model']) !== '') {
                $choices[] = $name.'/'.trim($provider['model']);
            }
        }

        return array_values(array_unique($choices));
    }

    private function contextWindowForModel(string $model): int
    {
        return match (true) {
            str_contains($model, 'opus') => 200000,
            str_contains($model, 'sonnet-4') => 200000,
            str_contains($model, 'haiku-4') => 200000,
            str_contains($model, 'sonnet-3') => 200000,
            str_contains($model, 'haiku-3') => 200000,
            str_contains($model, 'kimi') => 131072,
            default => 200000,
        };
    }

    private function displayPackageVersion(): string
    {
        $composerJson = base_path('composer.json');
        $version = 'dev';

        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true) ?? [];
            if (is_string($composer['version'] ?? null) && $composer['version'] !== '') {
                $version = $composer['version'];
            }
        }

        return str_starts_with($version, 'v') ? $version : 'v'.$version;
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
            'WebSearch' => $this->truncate($input['query'] ?? '', 60),
            'Agent' => $this->truncate($input['description'] ?? ($input['subagent_type'] ?? 'agent'), 60),
            'TodoWrite' => count($input['todos'] ?? []).' items',
            'TaskCreate' => $this->truncate($input['subject'] ?? '', 60),
            'TaskUpdate' => $this->truncate(($input['taskId'] ?? '') . ' → ' . ($input['status'] ?? ''), 60),
            default => $this->truncate(json_encode($input), 60),
        };
    }

    /**
     * Get a human-readable activity description for the spinner.
     * Delegates to the tool's own getActivityDescription method, with
     * fallback for tools that don't implement it.
     */
    private function getActivityDescription(string $toolName, array $input): ?string
    {
        // Delegate to tool's own method
        $registry = app(\App\Tools\ToolRegistry::class);
        $tool = $registry->getTool($toolName);
        if ($tool !== null) {
            $desc = $tool->getActivityDescription($input);
            if ($desc !== null) {
                return $desc;
            }
        }

        // Fallback for tools without custom descriptions
        return match ($toolName) {
            'WebFetch' => 'Fetching ' . (parse_url($input['url'] ?? '', PHP_URL_HOST) ?: 'page'),
            'WebSearch' => 'Searching ' . $this->truncate($input['query'] ?? '', 30),
            'Agent' => ($input['description'] ?? 'Running agent'),
            'TaskCreate' => 'Creating task',
            'TaskUpdate' => 'Updating task',
            default => null,
        };
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) > $max) {
            return mb_substr($str, 0, $max - 3).'...';
        }

        return $str;
    }

    /**
     * Extract text content from a multi-content-block array (for display/logging).
     */
    private function extractTextFromContentBlocks(array $blocks): string
    {
        $parts = [];
        $imageCount = 0;

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'] ?? '';
            } elseif (($block['type'] ?? '') === 'image') {
                $imageCount++;
            }
        }

        $text = implode(' ', $parts);
        if ($imageCount > 0) {
            $suffix = " [+{$imageCount} image" . ($imageCount > 1 ? 's' : '') . ']';
            $text = trim($text) . $suffix;
        }

        return $text;
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

    private function shouldStreamAssistantText(): bool
    {
        return app(SettingsManager::class)->isStreamOutputEnabled();
    }

    private function createStreamingMarkdownOutput(?MarkdownRenderer $renderer = null): StreamingMarkdownOutput
    {
        return new StreamingMarkdownOutput(
            output: $this->output,
            renderer: $renderer ?? app(MarkdownRenderer::class),
            minRenderIntervalMs: max(40, (int) config('haocode.stream_render_interval_ms', 120)),
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

    private function redrawRawPrompt(string $cwd, DraftInputBuffer $draft): void
    {
        $this->writeRaw("\033[H\033[2J");
        $this->redrawRawEditorLine($cwd, $draft);
    }

    private function redrawActiveRawInput(
        AgentLoop $agent,
        bool $useDockedHud,
        string $cwd,
        DraftInputBuffer $draft,
        ?AutocompleteEngine $autocomplete = null,
        array $suggestions = [],
        int $selectedSuggestionIndex = 0,
    ): void {
        if ($useDockedHud) {
            $this->redrawRawInputScreen(
                agent: $agent,
                cwd: $cwd,
                draft: $draft,
                autocomplete: $autocomplete,
                suggestions: $suggestions,
                selectedSuggestionIndex: $selectedSuggestionIndex,
            );

            return;
        }

        $this->redrawRawEditorLine(
            cwd: $cwd,
            draft: $draft,
            autocomplete: $autocomplete,
            suggestions: $suggestions,
            selectedSuggestionIndex: $selectedSuggestionIndex,
        );
    }

    private function redrawRawInputScreen(
        AgentLoop $agent,
        string $cwd,
        DraftInputBuffer $draft,
        ?AutocompleteEngine $autocomplete = null,
        array $suggestions = [],
        int $selectedSuggestionIndex = 0,
    ): void
    {
        $autocomplete ??= app(AutocompleteEngine::class);
        $ghostText = $this->draftGhostText($autocomplete, $draft);
        $layout = $this->buildDraftPromptLayout($cwd, $draft, $ghostText);

        $suggestionLines = [];
        if ($suggestions !== []) {
            $maxLabelWidth = max(array_map(fn ($s) => mb_strlen($s['label']), $suggestions));
            $labelWidth = max(16, $maxLabelWidth + 2);
            foreach ($suggestions as $index => $suggestion) {
                $suggestionLines[] = $autocomplete->renderSuggestion($suggestion, $index === $selectedSuggestionIndex, $labelWidth);
            }
        }

        $this->renderDockedPromptScreen(
            $this->ensureDockedPromptScreen(),
            $suggestionLines,
            $layout['lines'],
            $layout['cursorLineIndex'],
            $layout['cursorColumn'],
            $this->currentPromptFooterLines($agent),
        );
    }

    private function renderPromptFooter(AgentLoop $agent): void
    {
        $lines = $this->currentPromptFooterLines($agent);
        if ($lines === []) {
            return;
        }

        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    /**
     * @return array<int, string>
     */
    private function currentPromptFooterLines(AgentLoop $agent): array
    {
        $settings = app(SettingsManager::class);

        return $settings->isStatuslineEnabled()
            ? $this->formatter()->promptFooterLines($this->buildPromptHudSnapshot($agent))
            : [];
    }

    /**
     * @return array{
     *   model: string,
     *   message_count: int,
     *   permission_mode: string,
     *   fast_mode: bool,
     *   layout: string,
     *   title: string|null,
     *   project: string,
     *   branch: string|null,
     *   git_dirty: bool,
     *   context_percent: float,
     *   context_tokens: int,
     *   context_limit: int,
     *   context_state: string,
     *   cost: float,
     *   cost_warn: float,
     *   turn: array{event: string, label: string, detail: string|null}|null,
     *   show_tools: bool,
     *   show_agents: bool,
     *   show_todos: bool,
     *   tools: array{
     *     running: array<int, array{name: string, target: string|null}>,
     *     completed: array<int, array{name: string, count: int}>
     *   },
     *   agents: array{
     *     bash_tasks: int,
     *     entries: array<int, array{
     *       status: string,
     *       agent_type: string,
     *       description?: string|null,
     *       elapsed_seconds: int,
     *       pending_messages: int
     *     }>
     *   },
     *   todo: array{
     *     current: string|null,
     *     completed: int,
     *     total: int,
     *     all_completed: bool
     *   }|null
     * }
     */
    private function buildPromptHudSnapshot(AgentLoop $agent): array
    {
        $settings = app(SettingsManager::class);
        $git = app(GitContext::class);
        $compactor = app(ContextCompactor::class);
        $hud = app(PromptHudState::class);
        $statusline = $settings->getStatuslineConfig();

        $lastTurnTokens = $agent->getLastTurnInputTokens();
        $contextState = $compactor->getWarningState($lastTurnTokens);

        return [
            'model' => $settings->getResolvedModelIdentifier(),
            'message_count' => $agent->getMessageHistory()->count(),
            'permission_mode' => $settings->getPermissionMode()->value,
            'fast_mode' => $this->fastMode,
            'layout' => $statusline['layout'],
            'title' => $agent->getSessionManager()->getTitle(),
            'project' => $this->hudProjectLabel($git, $statusline['path_levels']),
            'branch' => $git->isGitRepo() ? $git->getCurrentBranch() : null,
            'git_dirty' => $git->isGitRepo() ? $git->hasUncommittedChanges() : false,
            'context_percent' => (float) ($contextState['percentUsed'] ?? 0.0),
            'context_tokens' => $lastTurnTokens,
            'context_limit' => 180000,
            'context_state' => ($contextState['isError'] ?? false) || ($contextState['isBlocking'] ?? false)
                ? 'critical'
                : (($contextState['isWarning'] ?? false) ? 'warning' : 'normal'),
            'cost' => $agent->getEstimatedCost(),
            'cost_warn' => $agent->getCostTracker()->getWarnThreshold(),
            'turn' => $hud->summarizeTurn(),
            'show_tools' => $statusline['show_tools'],
            'show_agents' => $statusline['show_agents'],
            'show_todos' => $statusline['show_todos'],
            'tools' => $hud->summarizeTools(),
            'agents' => $this->summarizeBackgroundHud(),
            'todo' => $hud->summarizeTodos(),
        ];
    }

    private function refreshPromptHudState(AgentLoop $agent): void
    {
        $hud = app(PromptHudState::class);
        $turn = $hud->summarizeTurn();
        $entries = $agent->getSessionManager()->loadSession($agent->getSessionManager()->getSessionId());
        $hud->hydrateFromSessionEntries($entries);
        $hud->restoreTurnSummary($turn);
    }

    private function recordTurnHudEvent(string $event, ?string $detail = null): void
    {
        app(PromptHudState::class)->recordTurnEvent($event, $detail);
    }

    private function summarizeTurnDetail(string $detail, int $max = 72): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($detail)) ?? '';

        return $normalized === '' ? '' : $this->truncate($normalized, $max);
    }

    private function hudProjectLabel(GitContext $git, int $pathLevels): string
    {
        $cwd = getcwd() ?: '.';

        if (! $git->isGitRepo()) {
            return basename($cwd);
        }

        $root = $git->getGitRoot();
        $relative = ltrim(str_replace($root, '', $cwd), DIRECTORY_SEPARATOR);
        if ($relative === '') {
            return basename($root);
        }

        $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $relative)));
        $tail = array_slice($segments, -max(1, min(3, $pathLevels)));

        return basename($root).'/'.implode('/', $tail);
    }

    private function parseStatuslineToggle(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'on', 'enable', 'enabled', 'true', 'yes' => true,
            'off', 'disable', 'disabled', 'false', 'no' => false,
            default => null,
        };
    }

    /**
     * @return array{
     *   bash_tasks: int,
     *   entries: array<int, array{
     *     status: string,
     *     agent_type: string,
     *     description?: string|null,
     *     elapsed_seconds: int,
     *     pending_messages: int
     *   }>
     * }
     */
    private function summarizeBackgroundHud(): array
    {
        $manager = app(BackgroundAgentManager::class);
        $states = $manager->list();

        $running = array_values(array_filter(
            $states,
            static fn (array $state): bool => in_array($state['status'] ?? 'pending', ['pending', 'running'], true),
        ));
        $recentFinished = array_values(array_filter(
            $states,
            static fn (array $state): bool => in_array($state['status'] ?? '', ['completed', 'error'], true),
        ));

        usort($recentFinished, static fn (array $left, array $right): int => ($right['updated_at'] ?? 0) <=> ($left['updated_at'] ?? 0));

        $entries = [];
        $seen = [];
        foreach (array_merge($running, array_slice($recentFinished, 0, 2)) as $state) {
            $id = (string) ($state['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $createdAt = (int) ($state['created_at'] ?? time());
            $updatedAt = (int) ($state['updated_at'] ?? $createdAt);
            $elapsed = in_array($state['status'] ?? '', ['completed', 'error'], true)
                ? max(0, $updatedAt - $createdAt)
                : max(0, time() - $createdAt);

            $entries[] = [
                'status' => (string) ($state['status'] ?? 'running'),
                'agent_type' => (string) ($state['agent_type'] ?? 'agent'),
                'description' => isset($state['description']) && is_string($state['description']) ? $state['description'] : null,
                'elapsed_seconds' => $elapsed,
                'pending_messages' => (int) ($state['pending_messages'] ?? 0),
            ];

            if (count($entries) >= 3) {
                break;
            }
        }

        return [
            'bash_tasks' => count(BashTool::listTasks()),
            'entries' => $entries,
        ];
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

            $char = $this->readRawCharacter($handle);
            if ($char === false || $char === '') {
                $this->writeRaw("\r\033[2K");

                return $original;
            }

            if ($char === "\r" || $char === "\n") {
                $accepted = $matches[$selectedIndex] ?? $original;
                $this->writeRaw("\r\033[2K");

                return $accepted;
            }

            if ($char === "\x03" || $char === "\x1b") {
                $this->writeRaw("\r\033[2K");

                return $original;
            }

            if ($char === "\x12") {
                if ($matches !== []) {
                    $selectedIndex = ($selectedIndex + 1) % count($matches);
                }
                continue;
            }

            if ($char === "\x7f" || $char === "\x08") {
                $query = $this->trimLastCharacter($query);
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

    /**
     * @param array<int, string> $history
     * @return array{changed: bool, historyPtr: int, historyDraftSnapshot: ?string}
     */
    private function navigateInputHistory(
        DraftInputBuffer $draft,
        array $history,
        int $historyPtr,
        ?string $historyDraftSnapshot,
        int $direction,
    ): array {
        $historyCount = count($history);
        if ($historyCount === 0 || ! in_array($direction, [-1, 1], true)) {
            return [
                'changed' => false,
                'historyPtr' => $historyPtr,
                'historyDraftSnapshot' => $historyDraftSnapshot,
            ];
        }

        if ($direction < 0) {
            if ($historyPtr >= $historyCount) {
                $historyDraftSnapshot = $draft->text();
                $historyPtr = $historyCount - 1;
            } elseif ($historyPtr > 0) {
                $historyPtr--;
            } else {
                return [
                    'changed' => false,
                    'historyPtr' => $historyPtr,
                    'historyDraftSnapshot' => $historyDraftSnapshot,
                ];
            }

            $draft->replaceWith($history[$historyPtr]);

            return [
                'changed' => true,
                'historyPtr' => $historyPtr,
                'historyDraftSnapshot' => $historyDraftSnapshot,
            ];
        }

        if ($historyPtr >= $historyCount) {
            return [
                'changed' => false,
                'historyPtr' => $historyPtr,
                'historyDraftSnapshot' => $historyDraftSnapshot,
            ];
        }

        if ($historyPtr < $historyCount - 1) {
            $historyPtr++;
            $draft->replaceWith($history[$historyPtr]);

            return [
                'changed' => true,
                'historyPtr' => $historyPtr,
                'historyDraftSnapshot' => $historyDraftSnapshot,
            ];
        }

        $historyPtr = $historyCount;
        $draft->replaceWith($historyDraftSnapshot ?? '');

        return [
            'changed' => true,
            'historyPtr' => $historyPtr,
            'historyDraftSnapshot' => null,
        ];
    }

    private function writeRaw(string $text): void
    {
        $this->output->write($text, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
    }

    private function ensureDockedPromptScreen(): DockedPromptScreen
    {
        if ($this->dockedPromptScreen === null) {
            $this->dockedPromptScreen = new DockedPromptScreen(
                output: $this->output,
                heightProvider: static fn (): int => max(1, (new Terminal)->getHeight()),
            );
        }

        return $this->dockedPromptScreen;
    }

    /**
     * @param array<int, string> $suggestionLines
     * @param array<int, string> $hudLines
     */
    private function renderDockedPromptScreen(
        DockedPromptScreen $screen,
        array $suggestionLines,
        array $promptLines,
        int $cursorLineIndex,
        int $cursorColumn,
        array $hudLines,
    ): void {
        $screen->render(
            suggestionLines: $suggestionLines,
            promptLines: $promptLines,
            cursorLineIndex: $cursorLineIndex,
            cursorColumn: $cursorColumn,
            hudLines: $hudLines,
        );
    }

    private function clearDockedPromptScreen(): void
    {
        $this->dockedPromptScreen?->clear();
    }

    private function resetDockedPromptScreen(): void
    {
        $this->dockedPromptScreen?->reset();
    }

    private function redrawRawEditorLine(
        string $cwd,
        DraftInputBuffer $draft,
        ?AutocompleteEngine $autocomplete = null,
        array $suggestions = [],
        int $selectedSuggestionIndex = 0,
    ): void
    {
        $autocomplete ??= app(AutocompleteEngine::class);
        $ghostText = $this->draftGhostText($autocomplete, $draft);
        $layout = $this->buildDraftPromptLayout($cwd, $draft, $ghostText);
        $promptLines = $layout['lines'];

        $this->writeRaw("\033[H\033[2J");
        foreach ($promptLines as $index => $promptLine) {
            if ($index > 0) {
                $this->writeRaw("\r\n");
            }
            $this->output->write($promptLine, false);
        }

        if ($suggestions !== []) {
            $maxLabelWidth = max(array_map(fn ($s) => mb_strlen($s['label']), $suggestions));
            $labelWidth = max(16, $maxLabelWidth + 2);
            $this->writeRaw("\r\n");
            foreach ($suggestions as $index => $suggestion) {
                $this->writeRaw("\r");
                $this->line($autocomplete->renderSuggestion($suggestion, $index === $selectedSuggestionIndex, $labelWidth));
            }
            $this->writeRaw(sprintf("\033[%dA", count($suggestions) + 1 + max(0, count($promptLines) - 1)));
        } elseif (count($promptLines) > 1) {
            $this->writeRaw(sprintf("\033[%dA", count($promptLines) - 1));
        }

        if ($layout['cursorLineIndex'] > 0) {
            $this->writeRaw(sprintf("\033[%dB", $layout['cursorLineIndex']));
        }
        $this->writeRaw("\r");
        if ($layout['cursorColumn'] > 0) {
            $this->writeRaw(sprintf("\033[%dC", $layout['cursorColumn']));
        }
    }

    /**
     * @return array<int, string>
     */
    private function draftPromptLines(string $cwd, DraftInputBuffer $draft, ?string $ghostText = null): array
    {
        return $this->buildDraftPromptLayout($cwd, $draft, $ghostText)['lines'];
    }

    /**
     * @return array{lines: array<int, string>, cursorLineIndex: int, cursorColumn: int}
     */
    private function buildDraftPromptLayout(
        string $cwd,
        DraftInputBuffer $draft,
        ?string $ghostText = null,
        ?int $terminalWidth = null,
    ): array {
        $terminalWidth ??= $this->terminalWidth();
        $promptPrefix = $this->formatter()->prompt($cwd);
        $continuationPrefix = $this->formatter()->continuationPrompt();
        $promptPrefixWidth = $this->rawPromptWidth($cwd, false);
        $continuationPrefixWidth = $this->rawPromptWidth($cwd, true);
        $renderState = $this->draftRenderState($draft);
        $logicalLines = $renderState['logical_lines'];
        $cursorLogicalLines = $renderState['cursor_lines'];
        $cursorLogicalIndex = max(0, count($cursorLogicalLines) - 1);
        $cursorLogicalText = $cursorLogicalLines[$cursorLogicalIndex] ?? '';

        $lines = [];
        $cursorLineIndex = 0;
        $cursorColumn = $promptPrefixWidth;

        foreach ($logicalLines as $logicalIndex => $lineText) {
            $firstPrefix = $logicalIndex === 0 ? $promptPrefix : $continuationPrefix;
            $firstPrefixWidth = $logicalIndex === 0 ? $promptPrefixWidth : $continuationPrefixWidth;
            $segments = $this->wrapPromptText(
                $lineText,
                $terminalWidth,
                $firstPrefixWidth,
                $continuationPrefixWidth,
            );

            if ($logicalIndex === $cursorLogicalIndex) {
                $cursorSegments = $this->wrapPromptText(
                    $cursorLogicalText,
                    $terminalWidth,
                    $firstPrefixWidth,
                    $continuationPrefixWidth,
                );
                $cursorLineIndex = count($lines) + count($cursorSegments) - 1;
                $cursorPrefixWidth = count($cursorSegments) === 1 ? $firstPrefixWidth : $continuationPrefixWidth;
                $cursorColumn = $cursorPrefixWidth + $this->displayWidth($cursorSegments[array_key_last($cursorSegments)]);
            }

            foreach ($segments as $segmentIndex => $segment) {
                $prefix = $segmentIndex === 0 ? $firstPrefix : $continuationPrefix;
                $content = OutputFormatter::escape($segment);

                if ($ghostText !== null
                    && $ghostText !== ''
                    && $logicalIndex === count($logicalLines) - 1
                    && $segmentIndex === count($segments) - 1
                    && count($segments) === 1) {
                    $content .= $ghostText;
                }

                $lines[] = $prefix . $content;
            }
        }

        return [
            'lines' => $lines,
            'cursorLineIndex' => $cursorLineIndex,
            'cursorColumn' => $cursorColumn,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function wrapPromptText(
        string $text,
        int $terminalWidth,
        int $firstPrefixWidth,
        int $continuationPrefixWidth,
    ): array {
        $segments = [];
        $remaining = $text;
        $firstSegment = true;

        do {
            $prefixWidth = $firstSegment ? $firstPrefixWidth : $continuationPrefixWidth;
            [$segment, $remaining] = $this->takeTextByDisplayWidth(
                $remaining,
                max(1, $terminalWidth - $prefixWidth),
            );
            $segments[] = $segment;
            $firstSegment = false;
        } while ($remaining !== '');

        return $segments;
    }

    /**
     * @return array{
     *   logical_lines: array<int, string>,
     *   cursor_lines: array<int, string>
     * }
     */
    private function draftRenderState(DraftInputBuffer $draft): array
    {
        $preview = $draft->collapsedPastePreview();
        if ($preview === null) {
            return [
                'logical_lines' => $draft->visibleLines(),
                'cursor_lines' => [...$draft->committedLines(), $draft->beforeCursor()],
            ];
        }

        return [
            'logical_lines' => [$this->renderCollapsedPastePreview($preview, includeSuffix: true)],
            'cursor_lines' => [$this->renderCollapsedPastePreview($preview, includeSuffix: false)],
        ];
    }

    /**
     * @param array{
     *   prefix: string,
     *   suffix: string,
     *   char_count: int,
     *   line_count: int,
     *   byte_count: int
     * } $preview
     */
    private function renderCollapsedPastePreview(array $preview, bool $includeSuffix): string
    {
        $leadingContext = $this->collapsedPasteContextSnippet($preview['prefix'], 24, fromEnd: true);
        $trailingContext = $includeSuffix
            ? $this->collapsedPasteContextSnippet($preview['suffix'], 24, fromEnd: false)
            : '';

        $placeholder = '[Pasted text';
        $placeholder .= ' · ' . $this->formatCollapsedPasteSize((int) ($preview['byte_count'] ?? 0));
        if (($preview['line_count'] ?? 0) > 0) {
            $placeholder .= ' · +' . $preview['line_count'] . ' lines';
        }
        $placeholder .= ']';

        return trim(implode(' ', array_filter([
            $leadingContext,
            $placeholder,
            $trailingContext,
        ], static fn (?string $part): bool => $part !== null && $part !== '')));
    }

    private function collapsedPasteContextSnippet(string $text, int $maxWidth, bool $fromEnd): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if ($normalized === '') {
            return '';
        }

        if ($this->displayWidth($normalized) <= $maxWidth) {
            return $normalized;
        }

        if ($fromEnd) {
            $suffix = $this->takeTextFromEndByDisplayWidth($normalized, max(1, $maxWidth - 1));

            return '…' . $suffix;
        }

        return $this->takeTextByDisplayWidth($normalized, max(1, $maxWidth - 1))[0] . '…';
    }

    private function formatCollapsedPasteSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            $value = round($bytes / 1024, 1);

            return rtrim(rtrim(sprintf('%.1f', $value), '0'), '.') . ' KB';
        }

        $value = round($bytes / (1024 * 1024), 1);

        return rtrim(rtrim(sprintf('%.1f', $value), '0'), '.') . ' MB';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function takeTextByDisplayWidth(string $text, int $maxWidth): array
    {
        if ($text === '') {
            return ['', ''];
        }

        $characters = $this->textCharacters($text);
        $segment = '';
        $width = 0;

        foreach ($characters as $index => $character) {
            $characterWidth = max(1, $this->displayWidth($character));
            if ($segment !== '' && $width + $characterWidth > $maxWidth) {
                return [$segment, implode('', array_slice($characters, $index))];
            }

            $segment .= $character;
            $width += $characterWidth;
        }

        return [$segment, ''];
    }

    private function takeTextFromEndByDisplayWidth(string $text, int $maxWidth): string
    {
        if ($text === '') {
            return '';
        }

        $characters = array_reverse($this->textCharacters($text));
        $segment = '';
        $width = 0;

        foreach ($characters as $character) {
            $characterWidth = max(1, $this->displayWidth($character));
            if ($segment !== '' && $width + $characterWidth > $maxWidth) {
                break;
            }

            $segment = $character . $segment;
            $width += $characterWidth;
        }

        return $segment;
    }

    /**
     * @return array<int, string>
     */
    private function textCharacters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? str_split($text) : $characters;
    }

    private function draftGhostText(AutocompleteEngine $autocomplete, DraftInputBuffer $draft): ?string
    {
        if (! $draft->isCursorAtEnd()) {
            return null;
        }

        $ghostText = $autocomplete->getGhostText($draft->currentLine());
        if ($ghostText === null || $ghostText === '') {
            return null;
        }

        return $autocomplete->renderGhostText($ghostText);
    }

    private function refreshLiveSuggestions(AutocompleteEngine $autocomplete, DraftInputBuffer $draft, int $selectedSuggestionIndex = 0): array
    {
        if (! $draft->isCursorAtEnd()) {
            return [[], 0];
        }

        $suggestions = $autocomplete->getLiveSuggestions($draft->currentLine());
        if ($suggestions === []) {
            return [[], 0];
        }

        return [$suggestions, min(max(0, $selectedSuggestionIndex), count($suggestions) - 1)];
    }

    /**
     * Enter submits the current draft; live suggestions are accepted explicitly via Tab.
     *
     * @param array{label?: string, type?: string}|null $selectedSuggestion
     */
    private function shouldApplySelectedSuggestionOnSubmit(DraftInputBuffer $draft, ?array $selectedSuggestion): bool
    {
        return false;
    }

    private function wrapSuggestionIndex(int $index, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $wrapped = $index % $count;

        return $wrapped < 0 ? $wrapped + $count : $wrapped;
    }

    private function applySelectedSuggestion(AutocompleteEngine $autocomplete, DraftInputBuffer $draft, string $label): bool
    {
        if (! $draft->isCursorAtEnd()) {
            return false;
        }

        $currentLine = $draft->currentLine();
        if (str_starts_with($currentLine, '/')
            || preg_match('/@(\S*)$/', $currentLine) === 1) {
            $completed = $autocomplete->acceptSuggestion($currentLine, $label);

            if ($completed !== $currentLine) {
                $draft->replaceCurrentLine($completed);

                return true;
            }
        }

        return false;
    }

    private function readRawCharacter($handle): string|false
    {
        $char = fread($handle, 1);
        if ($char === false || $char === '') {
            return $char;
        }

        $byte = ord($char);
        if ($byte < 0x80 || $byte === 0x1b) {
            return $char;
        }

        $expectedBytes = $this->expectedUtf8Bytes($byte);
        if ($expectedBytes === 1) {
            return $char;
        }

        $remaining = '';
        while (strlen($remaining) < $expectedBytes - 1) {
            $chunk = fread($handle, $expectedBytes - 1 - strlen($remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $remaining .= $chunk;
        }

        return InputSanitizer::sanitize($char . $remaining);
    }

    private function readEscapeSequence($handle): string|false
    {
        $prefix = fread($handle, 1);
        if ($prefix === false || $prefix === '') {
            return $prefix;
        }

        if ($prefix !== '[' && $prefix !== 'O') {
            return $prefix;
        }

        $sequence = $prefix;
        while (true) {
            $next = fread($handle, 1);
            if ($next === false || $next === '') {
                break;
            }

            $sequence .= $next;
            if (preg_match('/[A-Za-z~]$/', $sequence) === 1) {
                break;
            }
        }

        return $sequence;
    }

    private function readBracketedPaste($handle): string
    {
        $paste = '';

        while (true) {
            $char = $this->readRawCharacter($handle);
            if ($char === false || $char === '') {
                break;
            }

            if ($char === "\x1b") {
                $sequence = $this->readEscapeSequence($handle);
                if ($sequence === '[201~') {
                    break;
                }

                if ($sequence !== false && $sequence !== '') {
                    $paste .= "\x1b" . $sequence;
                }

                continue;
            }

            $paste .= $char;
        }

        return str_replace(["\r\n", "\r"], "\n", $paste);
    }

    private function expectedUtf8Bytes(int $leadByte): int
    {
        return match (true) {
            ($leadByte & 0xE0) === 0xC0 => 2,
            ($leadByte & 0xF0) === 0xE0 => 3,
            ($leadByte & 0xF8) === 0xF0 => 4,
            default => 1,
        };
    }

    private function trimLastCharacter(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            return mb_substr($text, 0, max(0, mb_strlen($text, 'UTF-8') - 1), 'UTF-8');
        }

        return substr($text, 0, -1);
    }

    private function rawPromptWidth(string $cwd, bool $continuationPrompt): int
    {
        return $this->displayWidth($continuationPrompt ? '… ' : "{$cwd} ❯ ");
    }

    private function terminalWidth(): int
    {
        return max(10, (new Terminal)->getWidth());
    }

    private function displayWidth(string $text): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : strlen($text);
    }

    private function handleRename(AgentLoop $agent, string $args): void
    {
        $args = trim($args);
        $sessionManager = $agent->getSessionManager();

        if ($args !== '') {
            $sessionManager->setTitle($args);
            $this->line('<fg=green>Session renamed to:</> <fg=white>' . $args . '</>');
            return;
        }

        $messages = $agent->getMessageHistory()->getMessages();
        if (empty($messages)) {
            $this->line('<fg=yellow>No conversation yet. Use /rename <title> to set a title.</>');
            return;
        }

        $this->line('<fg=gray>Generating title from conversation...</>');
        $this->runAgentTurn($agent, 'Generate a short, descriptive title (max 6 words) for this conversation. Reply with ONLY the title text, nothing else.');
    }

    private function handleEffort(string $args): void
    {
        $settings = app(SettingsManager::class);
        $args = strtolower(trim($args));
        $validLevels = ['low', 'medium', 'high', 'max', 'auto'];

        if ($args === '') {
            $current = $settings->getEffortLevel();
            $lines = [
                $this->formatter()->keyValue('Current', $current),
                '<fg=gray>Available: ' . implode(', ', $validLevels) . '</>',
                '<fg=gray>low/medium = standard, high = thinking (10K), max = thinking (32K)</>',
            ];
            $this->renderPanel('Reasoning effort', $lines);
            return;
        }

        if (! in_array($args, $validLevels, true)) {
            $this->line('<fg=red>Invalid effort level:</> <fg=white>' . $args . '</>');
            $this->line('<fg=gray>Available: ' . implode(', ', $validLevels) . '</>');
            return;
        }

        $settings->set('effort_level', $args);

        match ($args) {
            'low', 'medium', 'auto' => (function () use ($settings) {
                $settings->set('thinking_enabled', false);
            })(),
            'high' => (function () use ($settings) {
                $settings->set('thinking_enabled', true);
                $settings->set('thinking_budget', 10000);
            })(),
            'max' => (function () use ($settings) {
                $settings->set('thinking_enabled', true);
                $settings->set('thinking_budget', 32000);
            })(),
        };

        $this->line('<fg=green>Effort set to:</> <fg=white>' . $args . '</>');
    }

    private function handleVim(): void
    {
        $settings = app(SettingsManager::class);
        $current = $settings->isVimMode();
        $settings->set('vim_mode', ! $current);

        if (! $current) {
            $this->line('<fg=green>Vim mode enabled</> <fg=gray>— Escape to enter normal mode, i to insert</>');
        } else {
            $this->line('<fg=gray>Vim mode disabled</> — standard editing restored');
        }
    }

    private function handleCopy(AgentLoop $agent): void
    {
        $text = $agent->getMessageHistory()->getLastAssistantText();

        if ($text === null) {
            $this->line('<fg=yellow>No assistant response to copy.</>');
            return;
        }

        if ($this->copyToClipboard($text)) {
            $len = mb_strlen($text);
            $this->line("<fg=green>Copied to clipboard</> <fg=gray>({$len} chars)</>");
        } else {
            $this->line('<fg=red>Failed to copy — no clipboard utility found (pbcopy/xclip/xsel).</>');
        }
    }

    private function copyToClipboard(string $text): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $proc = proc_open('pbcopy', [['pipe', 'r']], $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $text);
                fclose($pipes[0]);
                return proc_close($proc) === 0;
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            foreach (['xclip -selection clipboard', 'xsel --clipboard --input', 'wl-copy'] as $cmd) {
                $binary = explode(' ', $cmd)[0];
                if (shell_exec("which {$binary} 2>/dev/null")) {
                    $proc = proc_open($cmd, [['pipe', 'r']], $pipes);
                    if (is_resource($proc)) {
                        fwrite($pipes[0], $text);
                        fclose($pipes[0]);
                        return proc_close($proc) === 0;
                    }
                }
            }
        }

        if (str_contains(strtolower(php_uname('r')), 'microsoft')) {
            $proc = proc_open('clip.exe', [['pipe', 'r']], $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $text);
                fclose($pipes[0]);
                return proc_close($proc) === 0;
            }
        }

        return false;
    }

    private function handleEnv(): void
    {
        $settings = app(SettingsManager::class);

        $lines = [
            $this->formatter()->keyValue('PHP', PHP_VERSION),
            $this->formatter()->keyValue('Laravel', app()->version()),
            $this->formatter()->keyValue('OS', PHP_OS_FAMILY . ' ' . php_uname('r')),
            $this->formatter()->keyValue('Shell', getenv('SHELL') ?: 'unknown'),
            $this->formatter()->keyValue('Terminal', getenv('TERM') ?: 'unknown'),
            $this->formatter()->keyValue('CWD', (string) getcwd()),
            $this->formatter()->keyValue('Model', $settings->getResolvedModelIdentifier()),
            $this->formatter()->keyValue('Provider', (string) $settings->getActiveProviderName(), 'gray', 'gray'),
            $this->formatter()->keyValue('API base', $settings->getBaseUrl()),
            $this->formatter()->keyValue('Thinking', $settings->isThinkingEnabled() ? 'enabled ('.$settings->getThinkingBudget().' tokens)' : 'disabled', 'gray', 'gray'),
            $this->formatter()->keyValue('Effort', $settings->getEffortLevel()),
            $this->formatter()->keyValue('Stream', $settings->getStreamMode()),
            $this->formatter()->keyValue('Permission', $settings->getPermissionMode()->value),
        ];

        $this->renderPanel('Environment', $lines);
    }

    private function handleReleaseNotes(): void
    {
        $changelogPath = base_path('CHANGELOG.md');

        if (! file_exists($changelogPath)) {
            $this->line('<fg=yellow>No CHANGELOG.md found.</>');
            $this->line('<fg=gray>Visit https://github.com/sk-wang/hao-code/releases for release notes.</>');
            return;
        }

        $content = (string) file_get_contents($changelogPath);
        $lines = explode("\n", $content);
        $output = [];
        $inSection = false;

        foreach ($lines as $line) {
            if (preg_match('/^## /', $line)) {
                if ($inSection) {
                    break;
                }
                $inSection = true;
            }
            if ($inSection) {
                $output[] = $line;
            }
        }

        if ($output === []) {
            $this->line('<fg=gray>No release notes found in CHANGELOG.md</>');
            return;
        }

        $version = $this->displayPackageVersion();
        $this->line('');
        $this->line("  <fg=cyan;bold>Release Notes</> <fg=white>{$version}</>");
        $this->line('');
        foreach (array_slice($output, 0, 40) as $line) {
            $this->line('  ' . $line);
        }
        $this->line('');
    }

    private function handleUpgrade(): void
    {
        $version = $this->displayPackageVersion();
        $lines = [
            $this->formatter()->keyValue('Current version', $version),
            '',
            '<fg=white>To upgrade hao-code:</>',
            '<fg=green>  composer global update sk-wang/hao-code</>',
            '',
            '<fg=gray>Or if installed locally:</>',
            '<fg=green>  composer update sk-wang/hao-code</>',
            '',
            '<fg=gray>Check latest version at:</>',
            '<fg=cyan>  https://github.com/sk-wang/hao-code/releases</>',
        ];

        $this->renderPanel('Upgrade', $lines);
    }

    // ── Batch 2 command handlers ────────────────────────────────────

    private function handleSession(AgentLoop $agent): void
    {
        $sm = $agent->getSessionManager();
        $settings = app(SettingsManager::class);
        $f = $this->formatter();

        $lines = [
            $f->keyValue('Session ID', $sm->getSessionId()),
            $f->keyValue('Title', $sm->getTitle() ?? '<fg=gray>(untitled)</>'),
            $f->keyValue('Model', $settings->getResolvedModelIdentifier()),
            $f->keyValue('Provider', (string) ($settings->getActiveProviderName() ?? 'default')),
            $f->keyValue('Messages', (string) $agent->getMessageHistory()->count()),
            $f->keyValue('Input tokens', number_format($agent->getTotalInputTokens())),
            $f->keyValue('Output tokens', number_format($agent->getTotalOutputTokens())),
            $f->keyValue('Est. cost', '$'.$agent->getEstimatedCost()),
            $f->keyValue('CWD', (string) getcwd()),
            $f->keyValue('Permission', $settings->getPermissionMode()->value),
        ];

        $cacheWrite = $agent->getCacheCreationTokens();
        $cacheRead = $agent->getCacheReadTokens();
        if ($cacheWrite > 0) {
            $lines[] = $f->keyValue('Cache write', number_format($cacheWrite));
        }
        if ($cacheRead > 0) {
            $lines[] = $f->keyValue('Cache read', number_format($cacheRead));
        }

        $this->renderPanel('Session', $lines);
    }

    private function handleAddDir(string $args): void
    {
        $path = trim($args);

        if ($path === '') {
            $this->line('<fg=yellow>Usage:</> /add-dir <path>');
            $this->line('<fg=gray>Adds a directory to the allowed paths for this session.</>');

            return;
        }

        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            $this->line("<fg=red>Directory not found:</> <fg=white>{$path}</>");

            return;
        }

        $settings = app(SettingsManager::class);
        $rule = "Read({$resolved}/*:*)";
        $settings->addAllowRule($rule);

        $bashRule = "Bash({$resolved}:*)";
        $settings->addAllowRule($bashRule);

        $this->line("<fg=green>Added directory:</> <fg=white>{$resolved}</>");
        $this->line("<fg=gray>  + {$rule}</>");
        $this->line("<fg=gray>  + {$bashRule}</>");
        $this->line('<fg=gray>Use /permissions to review all rules.</>');
    }

    private function handlePrComments(AgentLoop $agent, string $args): void
    {
        $prNumber = trim($args);

        $prompt = 'Fetch and summarize the comments on ';
        if ($prNumber !== '' && is_numeric($prNumber)) {
            $prompt .= "PR #{$prNumber}";
        } else {
            $prompt .= 'the current branch\'s open pull request';
        }
        $prompt .= ". Use the Bash tool to run:\n";
        $prompt .= "1. `gh pr view" . ($prNumber !== '' ? " {$prNumber}" : '') . " --json number,title,url,body,author` to get PR info\n";
        $prompt .= "2. `gh api repos/{owner}/{repo}/pulls/{number}/comments` to get review comments\n";
        $prompt .= "3. `gh api repos/{owner}/{repo}/issues/{number}/comments` to get issue-level comments\n";
        $prompt .= "Summarize all comments grouped by file, showing the author, comment body, and any code context (diff_hunk). ";
        $prompt .= "Highlight any unresolved threads or action items.";

        $this->line('<fg=gray>Fetching PR comments…</>');
        $this->runAgentTurn($agent, $prompt);
    }

    private function handleAgents(): void
    {
        $registry = app(\App\Tools\ToolRegistry::class);
        $tools = $registry->getAllTools();

        if ($tools === []) {
            $this->line('<fg=yellow>No tools registered.</>');

            return;
        }

        $lines = [];
        foreach ($tools as $name => $tool) {
            $desc = $tool->description();
            $readOnly = $tool->isReadOnly([]) ? '<fg=green>read-only</>' : '<fg=yellow>write</>';
            $lines[] = "<fg=cyan>{$name}</> [{$readOnly}]";
            if ($desc !== '') {
                $truncated = mb_strlen($desc) > 80 ? mb_substr($desc, 0, 77).'...' : $desc;
                $lines[] = "  <fg=gray>{$truncated}</>";
            }
        }

        $this->renderPanel('Tools & Agents ('.count($tools).')', $lines);
    }

    private function handleFeedback(string $args): void
    {
        $this->line('');
        $this->line('  <fg=cyan;bold>Feedback</>');
        $this->line('');
        $this->line('  <fg=white>Report bugs or request features:</>');
        $this->line('  <fg=cyan>  https://github.com/sk-wang/hao-code/issues</>');
        $this->line('');

        if (trim($args) !== '') {
            $this->line('  <fg=gray>Your feedback:</> <fg=white>'.trim($args).'</>');
            $this->line('  <fg=gray>Please open an issue with the details above.</>');
            $this->line('');
        }
    }

    private function handleLogin(): void
    {
        $settings = app(SettingsManager::class);
        $currentKey = $settings->getApiKey();

        if ($currentKey !== '') {
            $masked = substr($currentKey, 0, 8).'…'.substr($currentKey, -4);
            $this->line("<fg=gray>Current API key:</> <fg=white>{$masked}</>");
            $this->line('');
        }

        $this->line('<fg=cyan;bold>Set API Key</>');
        $this->line('');
        $this->line('<fg=white>Option 1:</> Set via environment variable');
        $this->line('<fg=green>  export ANTHROPIC_API_KEY=your-api-key</>');
        $this->line('');
        $this->line('<fg=white>Option 2:</> Set in global settings');

        $globalPath = ($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir()).'/.haocode/settings.json';
        $this->line("<fg=green>  echo '{\"api_key\": \"your-api-key\"}' > {$globalPath}</>");
        $this->line('');
        $this->line('<fg=white>Option 3:</> Set in project settings');
        $this->line('<fg=green>  echo \'{"api_key": "your-api-key"}\' > .haocode/settings.json</>');
        $this->line('');
        $this->line('<fg=gray>Get your API key at: https://console.anthropic.com/settings/keys</>');
        $this->line('');
    }

    private function handleLogout(): void
    {
        $settings = app(SettingsManager::class);
        $currentKey = $settings->getApiKey();

        if ($currentKey === '') {
            $this->line('<fg=gray>No API key configured — already logged out.</>');

            return;
        }

        $globalPath = ($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir()).'/.haocode/settings.json';

        if (file_exists($globalPath)) {
            $globalSettings = json_decode((string) file_get_contents($globalPath), true) ?? [];
            if (isset($globalSettings['api_key'])) {
                unset($globalSettings['api_key']);
                file_put_contents($globalPath, json_encode($globalSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->line('<fg=green>Removed API key from global settings.</>');
            }
        }

        $projectPath = getcwd().'/.haocode/settings.json';
        if (file_exists($projectPath)) {
            $projectSettings = json_decode((string) file_get_contents($projectPath), true) ?? [];
            if (isset($projectSettings['api_key'])) {
                unset($projectSettings['api_key']);
                file_put_contents($projectPath, json_encode($projectSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->line('<fg=green>Removed API key from project settings.</>');
            }
        }

        $settings->set('model', $settings->getModel());

        $this->line('<fg=gray>Logged out. Unset ANTHROPIC_API_KEY env var if set.</>');
    }

    private function handleKeybindings(): void
    {
        $globalDir = ($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir()).'/.haocode';
        $keybindingsPath = $globalDir.'/keybindings.json';

        if (! file_exists($keybindingsPath)) {
            if (! is_dir($globalDir)) {
                mkdir($globalDir, 0755, true);
            }

            $template = json_encode([
                '_comment' => 'Keybindings for hao-code. Keys use readline-style notation.',
                'bindings' => [
                    ['key' => 'ctrl+a', 'action' => 'beginning-of-line'],
                    ['key' => 'ctrl+e', 'action' => 'end-of-line'],
                    ['key' => 'ctrl+k', 'action' => 'kill-line'],
                    ['key' => 'ctrl+u', 'action' => 'unix-line-discard'],
                    ['key' => 'ctrl+w', 'action' => 'unix-word-rubout'],
                    ['key' => 'ctrl+l', 'action' => 'clear-screen'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            file_put_contents($keybindingsPath, $template);
            $this->line("<fg=green>Created keybindings template:</> <fg=white>{$keybindingsPath}</>");
        } else {
            $this->line("<fg=gray>Keybindings file:</> <fg=white>{$keybindingsPath}</>");
        }

        $editor = getenv('EDITOR') ?: getenv('VISUAL') ?: 'vi';
        $this->line("<fg=gray>Open with:</> <fg=cyan>{$editor} {$keybindingsPath}</>");
    }

    /**
     * /paste-image [prompt] - Grab image from clipboard and send to agent.
     */
    private function handlePasteImage(AgentLoop $agent, string $prompt): void
    {
        if (!ImagePaste::hasClipboardImage()) {
            $this->line('<fg=yellow>No image found in clipboard.</> Copy an image first, then run /paste-image.');
            return;
        }

        $imageData = ImagePaste::getClipboardImage();
        if ($imageData === null) {
            $this->line('<fg=red>Failed to read clipboard image.</>');
            return;
        }

        $this->line("  <fg=cyan>📎 Clipboard image attached</> <fg=gray>(" . $imageData['media_type'] . ", "
            . round(strlen(base64_decode($imageData['base64'])) / 1024, 1) . " KB)</>");

        $textBlock = $prompt !== '' ? $prompt : 'What do you see in this image?';

        $contentBlocks = [
            ['type' => 'text', 'text' => $textBlock],
            ImagePaste::buildImageBlock($imageData['base64'], $imageData['media_type']),
        ];

        $this->runAgentTurn($agent, $contentBlocks);
    }
}
