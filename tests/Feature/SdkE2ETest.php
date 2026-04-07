<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Sdk\Conversation;
use App\Sdk\HaoCode;
use App\Sdk\HaoCodeConfig;
use App\Sdk\Message;
use App\Services\Agent\AgentLoopFactory;
use App\Services\Api\StreamingClient;
use App\Services\Settings\SettingsManager;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

/**
 * E2E tests for the HaoCode PHP SDK.
 *
 * Tests HaoCode::query(), HaoCode::stream(), and HaoCode::conversation()
 * using mock API responses. Verifies the SDK facade correctly wires
 * into AgentLoop and returns typed Message objects.
 */
class SdkE2ETest extends TestCase
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

        $this->tempRoot = sys_get_temp_dir().'/haocode-sdk-e2e-'.bin2hex(random_bytes(4));
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

    // ──────────────────────────────────────────────────────────────
    //  Test 1: HaoCode::query() — simple one-shot
    // ──────────────────────────────────────────────────────────────

    public function test_query_returns_final_response_text(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Hello from the SDK! The answer is 42.'),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('What is the answer to life?');

        $this->assertStringContainsString('42', $result);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 2: HaoCode::query() with tool use
    // ──────────────────────────────────────────────────────────────

    public function test_query_executes_tools_and_returns_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                'file_path' => 'hello.txt',
                'content' => "Hello from SDK!\n",
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('hello.txt', $toolResult);

                return MockAnthropicSse::textResponse('File created successfully.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Create hello.txt');

        $this->assertStringContainsString('File created', $result);
        $this->assertFileExists($this->projectDir.'/hello.txt');
        $this->assertSame("Hello from SDK!\n", file_get_contents($this->projectDir.'/hello.txt'));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 3: HaoCode::query() with config options
    // ──────────────────────────────────────────────────────────────

    public function test_query_with_config_respects_options(): void
    {
        $textChunks = [];

        $this->bootWithMock([
            MockAnthropicSse::textResponse('Configured response.'),
        ]);

        chdir($this->projectDir);

        $config = new HaoCodeConfig(
            maxTurns: 5,
            onText: function (string $delta) use (&$textChunks) {
                $textChunks[] = $delta;
            },
        );

        $result = HaoCode::query('Test', $config);

        $this->assertStringContainsString('Configured response', $result);
        $this->assertNotEmpty($textChunks);
        $this->assertSame('Configured response.', implode('', $textChunks));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 4: HaoCode::stream() yields typed Message objects
    // ──────────────────────────────────────────────────────────────

    public function test_stream_yields_text_and_result_messages(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Streamed answer.'),
        ]);

        chdir($this->projectDir);

        $messages = [];
        foreach (HaoCode::stream('Stream test') as $msg) {
            $messages[] = $msg;
        }

        // Should have at least a text message and a result message
        $this->assertNotEmpty($messages);

        $textMessages = array_filter($messages, fn (Message $m) => $m->type === 'text');
        $resultMessages = array_filter($messages, fn (Message $m) => $m->type === 'result');

        $this->assertNotEmpty($textMessages);
        $this->assertCount(1, $resultMessages);

        $result = array_values($resultMessages)[0];
        $this->assertStringContainsString('Streamed answer', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertArrayHasKey('input_tokens', $result->usage);
        $this->assertArrayHasKey('output_tokens', $result->usage);
        $this->assertIsFloat($result->cost);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 5: HaoCode::stream() with tool use yields tool messages
    // ──────────────────────────────────────────────────────────────

    public function test_stream_yields_tool_messages(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                'command' => 'echo "SDK streaming"',
                'description' => 'Echo test',
            ]),
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Bash executed.');
            },
        ]);

        chdir($this->projectDir);

        $types = [];
        foreach (HaoCode::stream('Run a command') as $msg) {
            $types[] = $msg->type;
        }

        $this->assertContains('tool_start', $types);
        $this->assertContains('tool_result', $types);
        $this->assertContains('result', $types);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 6: HaoCode::conversation() — multi-turn
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_maintains_context_across_turns(): void
    {
        $requestPayloads = [];

        $this->bootWithMock([
            // Turn 1 response
            MockAnthropicSse::textResponse('I created the variable x = 10.'),
            // Turn 2 response — should see previous context
            function (array $payload) use (&$requestPayloads): MockResponse {
                $requestPayloads[] = $payload;

                return MockAnthropicSse::textResponse('The value of x is 10.');
            },
        ], $requestPayloads);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();

        $r1 = $conv->send('Set x = 10');
        $this->assertStringContainsString('x = 10', $r1);
        $this->assertSame(1, $conv->getTurnCount());

        $r2 = $conv->send('What is x?');
        $this->assertStringContainsString('10', $r2);
        $this->assertSame(2, $conv->getTurnCount());

        // Verify the second request included conversation history
        $this->assertNotEmpty($requestPayloads);
        $lastPayload = end($requestPayloads);
        // Should have 3 messages: user1 + assistant1 + user2
        $this->assertSame(3, MockAnthropicSse::messageCount($lastPayload));

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 7: Conversation::stream() yields messages per turn
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_stream_yields_messages(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('First turn response.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();

        $messages = [];
        foreach ($conv->stream('Hello') as $msg) {
            $messages[] = $msg;
        }

        $resultMsgs = array_filter($messages, fn (Message $m) => $m->isResult());
        $this->assertCount(1, $resultMsgs);

        $result = array_values($resultMsgs)[0];
        $this->assertStringContainsString('First turn', $result->text);

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 8: Conversation throws after close
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_throws_after_close(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('OK.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();
        $conv->send('Hello');
        $conv->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed');
        $conv->send('This should fail');
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 9: HaoCodeConfig::make() factory
    // ──────────────────────────────────────────────────────────────

    public function test_config_make_creates_minimal_config(): void
    {
        $config = HaoCodeConfig::make('test-key', 'claude-haiku');
        $this->assertSame('test-key', $config->apiKey);
        $this->assertSame('claude-haiku', $config->model);
        $this->assertSame(['*'], $config->allowedTools);
        $this->assertSame(50, $config->maxTurns);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 10: HaoCodeConfig tool filter
    // ──────────────────────────────────────────────────────────────

    public function test_config_tool_filter_respects_allow_and_deny(): void
    {
        $config = new HaoCodeConfig(
            allowedTools: ['Read', 'Write', 'Bash'],
            disallowedTools: ['Bash'],
        );

        $filter = $config->toolFilter();
        $this->assertNotNull($filter);
        $this->assertTrue($filter('Read'));
        $this->assertTrue($filter('Write'));
        $this->assertFalse($filter('Bash'));   // Denied
        $this->assertFalse($filter('Agent'));  // Not in allowed list
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 11: Message factory methods
    // ──────────────────────────────────────────────────────────────

    public function test_message_factory_methods_produce_correct_types(): void
    {
        $text = Message::text('hello');
        $this->assertSame('text', $text->type);
        $this->assertSame('hello', $text->text);

        $toolStart = Message::toolStart('Bash', ['command' => 'ls']);
        $this->assertSame('tool_start', $toolStart->type);
        $this->assertSame('Bash', $toolStart->toolName);

        $toolResult = Message::toolResult('Bash', 'file.txt', false);
        $this->assertSame('tool_result', $toolResult->type);
        $this->assertFalse($toolResult->toolIsError);

        $result = Message::result('done', ['input_tokens' => 100], 0.01, 'sess_123');
        $this->assertTrue($result->isResult());
        $this->assertSame(0.01, $result->cost);
        $this->assertSame('sess_123', $result->sessionId);

        $error = Message::error('boom');
        $this->assertTrue($error->isError());
        $this->assertSame('boom', $error->error);
    }

    // ══════════════════════════════════════════════════════════════
    //  Infrastructure
    // ══════════════════════════════════════════════════════════════

    private function bootWithMock(array $responses, ?array &$capturedRequests = null): void
    {
        $requests = [];
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

        if ($capturedRequests !== null) {
            $capturedRequests = &$requests;
        }
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
