<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Api\StreamingClient;
use App\Services\Settings\SettingsManager;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

class HaoCodeOfflineE2ETest extends TestCase
{
    private string $tempRoot;

    private string $homeDir;

    private string $projectDir;

    private string $sessionDir;

    private string $storageDir;

    private string $originalHome = '';

    private string|false $originalCwd = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/haocode-offline-e2e-'.bin2hex(random_bytes(4));
        $this->homeDir = $this->tempRoot.'/home';
        $this->projectDir = $this->tempRoot.'/project';
        $this->sessionDir = $this->homeDir.'/.haocode/storage/app/haocode/sessions';
        $this->storageDir = $this->tempRoot.'/laravel-storage';
        $this->originalHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        $this->originalCwd = getcwd();

        mkdir($this->homeDir.'/.haocode', 0755, true);
        mkdir($this->projectDir, 0755, true);
        mkdir($this->sessionDir, 0755, true);
        mkdir($this->storageDir, 0755, true);

        $this->setHomeDirectory($this->homeDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }

        $this->setHomeDirectory($this->originalHome);
        $this->removeDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function test_print_mode_write_flow_creates_file_and_replays_tool_result(): void
    {
        $prompt = 'Create generated/mock_write.txt with the text offline write e2e.';

        $run = $this->runHaoCodeCommand([
            '--print' => $prompt,
        ], [
            MockAnthropicSse::toolUseResponse('toolu_write_1', 'Write', [
                'file_path' => 'generated/mock_write.txt',
                'content' => "offline write e2e\n",
            ]),
            function (array $payload, int $requestNumber): MockResponse {
                $this->assertSame(2, $requestNumber);
                $this->assertTrue(MockAnthropicSse::hasToolResult($payload));

                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('Successfully created', $toolResult);
                $this->assertStringContainsString('generated/mock_write.txt', $toolResult);

                return MockAnthropicSse::textResponse('Write flow completed.');
            },
        ]);

        $filePath = $this->projectDir.'/generated/mock_write.txt';

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(2, $run['requests']);
        $this->assertSame($prompt, MockAnthropicSse::lastUserText($run['requests'][0]['payload']));
        $this->assertFileExists($filePath);
        $this->assertSame("offline write e2e\n", file_get_contents($filePath));
        $this->assertStringContainsString('Write flow completed.', $run['output']);
    }

    public function test_print_mode_bash_flow_creates_artifact_and_returns_summary(): void
    {
        $command = "mkdir -p generated && printf 'offline bash e2e\\n' > generated/mock_bash.txt && ls generated";

        $run = $this->runHaoCodeCommand([
            '--print' => 'Create a bash-generated artifact.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_bash_1', 'Bash', [
                'command' => $command,
                'description' => 'Create bash e2e artifact',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('mock_bash.txt', $toolResult);

                return MockAnthropicSse::textResponse('Bash flow completed.');
            },
        ]);

        $filePath = $this->projectDir.'/generated/mock_bash.txt';

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(2, $run['requests']);
        $this->assertFileExists($filePath);
        $this->assertSame("offline bash e2e\n", file_get_contents($filePath));
        $this->assertStringContainsString('Bash flow completed.', $run['output']);
    }

    public function test_print_mode_bash_failure_is_sent_back_to_the_model(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'Run a failing bash command.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_bash_fail_1', 'Bash', [
                'command' => 'haocode_missing_command_for_e2e',
                'description' => 'Trigger bash failure',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('Command exited with code 127', $toolResult);

                return MockAnthropicSse::textResponse('Bash failure captured.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(2, $run['requests']);
        $this->assertStringContainsString('Bash failure captured.', $run['output']);
        $this->assertStringContainsString('Command exited with code 127', $run['output']);
    }

    public function test_continue_reuses_latest_session_context_in_print_mode(): void
    {
        $firstRun = $this->runHaoCodeCommand([
            '--print' => 'Seed the first session.',
        ], [
            MockAnthropicSse::textResponse('Seed response.'),
        ]);

        $this->assertSame(0, $firstRun['exit_code']);
        $this->assertCount(1, $this->listSessionFiles());

        $secondRun = $this->runHaoCodeCommand([
            '--continue' => true,
            '--print' => 'Follow-up after continue.',
        ], [
            function (array $payload): MockResponse {
                $this->assertSame(3, MockAnthropicSse::messageCount($payload));
                $this->assertSame('Follow-up after continue.', MockAnthropicSse::lastUserText($payload));

                return MockAnthropicSse::textResponse('Saw 3 messages after continue.');
            },
        ]);

        $sessionFiles = $this->listSessionFiles();
        $entries = $this->readSessionEntries($sessionFiles[0]);
        $assistantTurns = array_values(array_filter(
            $entries,
            fn (array $entry): bool => ($entry['type'] ?? null) === 'assistant_turn',
        ));

        $this->assertSame(0, $secondRun['exit_code']);
        $this->assertCount(1, $sessionFiles);
        $this->assertCount(2, $assistantTurns);
        $this->assertStringContainsString('Saw 3 messages after continue.', $secondRun['output']);
    }

    public function test_resume_with_fork_session_creates_a_branched_transcript(): void
    {
        $seedRun = $this->runHaoCodeCommand([
            '--print' => 'Seed session for branching.',
        ], [
            MockAnthropicSse::textResponse('Seed response.'),
        ]);

        $this->assertSame(0, $seedRun['exit_code']);

        $originalSessionFile = $this->listSessionFiles()[0];
        $originalSessionId = basename($originalSessionFile, '.jsonl');

        $forkRun = $this->runHaoCodeCommand([
            '--resume' => $originalSessionId,
            '--fork-session' => true,
            '--name' => 'Forked Mock Session',
            '--print' => 'Fork follow-up.',
        ], [
            function (array $payload): MockResponse {
                $this->assertSame(3, MockAnthropicSse::messageCount($payload));
                $this->assertSame('Fork follow-up.', MockAnthropicSse::lastUserText($payload));

                return MockAnthropicSse::textResponse('Forked session continued.');
            },
        ]);

        $sessionFiles = $this->listSessionFiles();
        $branchedSessionFile = array_values(array_filter(
            $sessionFiles,
            fn (string $file): bool => $file !== $originalSessionFile,
        ))[0] ?? null;

        $this->assertSame(0, $forkRun['exit_code']);
        $this->assertCount(2, $sessionFiles);
        $this->assertNotNull($branchedSessionFile);
        $this->assertStringContainsString('Forked session continued.', $forkRun['output']);

        $branchedEntries = $this->readSessionEntries($branchedSessionFile);
        $originalEntries = $this->readSessionEntries($originalSessionFile);

        $this->assertTrue($this->sessionEntriesContain($branchedEntries, function (array $entry) use ($originalSessionId): bool {
            return ($entry['type'] ?? null) === 'session_branch'
                && ($entry['source_session_id'] ?? null) === $originalSessionId;
        }));
        $this->assertTrue($this->sessionEntriesContain($branchedEntries, function (array $entry): bool {
            return ($entry['type'] ?? null) === 'session_title'
                && ($entry['title'] ?? null) === 'Forked Mock Session';
        }));
        $this->assertFalse($this->sessionEntriesContain($originalEntries, function (array $entry): bool {
            return ($entry['type'] ?? null) === 'user_message'
                && ($entry['content'] ?? null) === 'Fork follow-up.';
        }));
    }

    /**
     * @param  array<int, MockResponse|callable(array, int, array): MockResponse>  $responses
     * @return array{
     *   exit_code: int,
     *   output: string,
     *   requests: array<int, array{method: string, url: string, headers: array<string, mixed>, payload: array<string, mixed>}>
     * }
     */
    private function runHaoCodeCommand(array $parameters, array $responses): array
    {
        $requests = [];
        $this->bootFreshApplication($responses, $requests);

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $this->withWorkingDirectory($this->projectDir, fn (): int => $kernel->call('hao-code', $parameters));

        return [
            'exit_code' => $exitCode,
            'output' => $kernel->output(),
            'requests' => $requests,
        ];
    }

    /**
     * @param  array<int, MockResponse|callable(array, int, array): MockResponse>  $responses
     * @param  array<int, array{method: string, url: string, headers: array<string, mixed>, payload: array<string, mixed>}>  $requests
     */
    private function bootFreshApplication(array $responses, array &$requests): void
    {
        $this->refreshApplication();
        $this->app->useStoragePath($this->storageDir);

        $_SERVER['LARAVEL_STORAGE_PATH'] = $this->storageDir;
        putenv('LARAVEL_STORAGE_PATH='.$this->storageDir);

        config([
            'haocode.api_key' => 'test-key',
            'haocode.api_base_url' => 'https://mock.anthropic.test',
            'haocode.model' => 'claude-test',
            'haocode.max_tokens' => 4096,
            'haocode.stream_output' => false,
            'haocode.permission_mode' => 'bypass_permissions',
            'haocode.global_settings_path' => $this->homeDir.'/.haocode/settings.json',
            'haocode.session_path' => $this->sessionDir,
            'haocode.api_stream_idle_timeout' => 2,
            'haocode.api_stream_poll_timeout' => 0.01,
        ]);

        $this->app->singleton(StreamingClient::class, function ($app) use (&$requests, $responses): StreamingClient {
            return new StreamingClient(
                apiKey: 'test-key',
                model: 'claude-test',
                baseUrl: 'https://mock.anthropic.test',
                maxTokens: 4096,
                httpClient: MockAnthropicSse::client($responses, $requests),
                settingsManager: $app->make(SettingsManager::class),
                idleTimeoutSeconds: 2,
                streamPollTimeoutSeconds: 0.01,
            );
        });
    }

    /**
     * @return array<int, string>
     */
    private function listSessionFiles(): array
    {
        $files = glob($this->sessionDir.'/*.jsonl') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readSessionEntries(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function sessionEntriesContain(array $entries, callable $predicate): bool
    {
        foreach ($entries as $entry) {
            if ($predicate($entry)) {
                return true;
            }
        }

        return false;
    }

    private function setHomeDirectory(string $home): void
    {
        if ($home === '') {
            putenv('HOME');
            unset($_SERVER['HOME']);

            return;
        }

        putenv('HOME='.$home);
        $_SERVER['HOME'] = $home;
    }

    private function withWorkingDirectory(string $directory, callable $callback): mixed
    {
        $previous = getcwd();
        chdir($directory);

        try {
            return $callback();
        } finally {
            if ($previous !== false) {
                chdir($previous);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
