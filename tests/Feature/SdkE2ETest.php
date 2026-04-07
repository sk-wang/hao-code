<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Sdk\AbortController;
use App\Sdk\Conversation;
use App\Sdk\HaoCode;
use App\Sdk\HaoCodeConfig;
use App\Sdk\Message;
use App\Sdk\QueryResult;
use App\Sdk\SdkTool;
use App\Sdk\StructuredResult;
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

        $this->assertStringContainsString('42', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertIsFloat($result->cost);
        // Stringable: can still be used as string
        $this->assertStringContainsString('42', (string) $result);
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

        $this->assertStringContainsString('File created', $result->text);
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

        $this->assertStringContainsString('Configured response', $result->text);
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
        $this->assertStringContainsString('x = 10', $r1->text);
        $this->assertSame(1, $conv->getTurnCount());

        $r2 = $conv->send('What is x?');
        $this->assertStringContainsString('10', $r2->text);
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

    // ──────────────────────────────────────────────────────────────
    //  Test 12: QueryResult carries usage metadata
    // ──────────────────────────────────────────────────────────────

    public function test_query_result_carries_usage_and_cost(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Result with metadata.'),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Test metadata');

        $this->assertInstanceOf(QueryResult::class, $result);
        $this->assertSame('Result with metadata.', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertGreaterThanOrEqual(0, $result->inputTokens());
        $this->assertGreaterThanOrEqual(0, $result->outputTokens());
        $this->assertIsFloat($result->cost);
        // Stringable
        $this->assertSame('Result with metadata.', (string) $result);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 13: AbortController
    // ──────────────────────────────────────────────────────────────

    public function test_abort_controller_signals_abort(): void
    {
        $abort = new AbortController();
        $this->assertFalse($abort->isAborted());

        $callbackFired = false;
        $abort->onAbort(function () use (&$callbackFired) {
            $callbackFired = true;
        });

        $abort->abort();
        $this->assertTrue($abort->isAborted());
        $this->assertTrue($callbackFired);

        // Double abort is a no-op
        $abort->abort();

        // Late listener fires immediately
        $lateCallbackFired = false;
        $abort->onAbort(function () use (&$lateCallbackFired) {
            $lateCallbackFired = true;
        });
        $this->assertTrue($lateCallbackFired);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 14: SdkTool — custom tool definition
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_tool_custom_tool_registers_and_works(): void
    {
        $customTool = new class extends SdkTool {
            public function name(): string
            {
                return 'GetWeather';
            }

            public function description(): string
            {
                return 'Get current weather for a city.';
            }

            public function parameters(): array
            {
                return [
                    'city' => ['type' => 'string', 'description' => 'City name', 'required' => true],
                ];
            }

            public function handle(array $input): string
            {
                return "Weather in {$input['city']}: Sunny, 25°C";
            }
        };

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_w1', 'GetWeather', [
                'city' => 'Tokyo',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Tokyo', $toolResult);
                $this->assertStringContainsString('Sunny', $toolResult);
                $this->assertStringContainsString('25', $toolResult);

                return MockAnthropicSse::textResponse('The weather in Tokyo is sunny and 25°C.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query("What's the weather in Tokyo?", new HaoCodeConfig(
            tools: [$customTool],
        ));

        $this->assertStringContainsString('Tokyo', $result->text);
        $this->assertStringContainsString('25', $result->text);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 15: SdkTool input schema generation
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_tool_generates_correct_input_schema(): void
    {
        $tool = new class extends SdkTool {
            public function name(): string
            {
                return 'TestTool';
            }

            public function description(): string
            {
                return 'Test tool.';
            }

            public function parameters(): array
            {
                return [
                    'name' => ['type' => 'string', 'description' => 'User name', 'required' => true],
                    'age' => ['type' => 'integer', 'description' => 'Age'],
                    'role' => ['type' => 'string', 'enum' => ['admin', 'user']],
                ];
            }

            public function handle(array $input): string
            {
                return 'ok';
            }
        };

        $schema = $tool->inputSchema();
        $this->assertNotNull($schema);

        // Tool is read-only by default
        $this->assertTrue($tool->isReadOnly([]));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 16: StructuredResult access patterns
    // ──────────────────────────────────────────────────────────────

    public function test_structured_result_provides_property_and_array_access(): void
    {
        $result = new StructuredResult(
            data: ['category' => 'shipping', 'priority' => 'high', 'score' => 95],
            rawText: '{"category":"shipping","priority":"high","score":95}',
        );

        // Property access
        $this->assertSame('shipping', $result->category);
        $this->assertSame('high', $result->priority);
        $this->assertSame(95, $result->score);
        $this->assertNull($result->nonexistent);

        // Array access
        $this->assertSame('shipping', $result['category']);
        $this->assertTrue(isset($result['priority']));
        $this->assertFalse(isset($result['missing']));

        // toArray / toJson
        $this->assertCount(3, $result->toArray());
        $this->assertStringContainsString('shipping', $result->toJson());

        // Stringable
        $this->assertStringContainsString('shipping', (string) $result);

        // Immutable
        $this->expectException(\RuntimeException::class);
        $result['category'] = 'billing';
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 17: HaoCodeConfig with new options
    // ──────────────────────────────────────────────────────────────

    public function test_config_accepts_new_sdk_options(): void
    {
        $abort = new AbortController();
        $tool = new class extends SdkTool {
            public function name(): string { return 'Noop'; }
            public function description(): string { return 'No-op.'; }
            public function parameters(): array { return []; }
            public function handle(array $input): string { return 'ok'; }
        };

        $config = new HaoCodeConfig(
            tools: [$tool],
            abortController: $abort,
            sessionId: 'test_session_123',
            continueSession: true,
            responseSchema: ['type' => 'object'],
        );

        $this->assertCount(1, $config->tools);
        $this->assertSame($abort, $config->abortController);
        $this->assertSame('test_session_123', $config->sessionId);
        $this->assertTrue($config->continueSession);
        $this->assertSame(['type' => 'object'], $config->responseSchema);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 18: Conversation.send() returns QueryResult
    // ──────────────────────────────────────────────────────────────

    public function test_conversation_send_returns_query_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Conversation result.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation();
        $result = $conv->send('Hello');

        $this->assertInstanceOf(QueryResult::class, $result);
        $this->assertSame('Conversation result.', $result->text);
        $this->assertIsArray($result->usage);
        $this->assertIsFloat($result->cost);
        $this->assertSame(1, $result->turnsUsed);

        $conv->close();
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 19: SDK config overrides reach StreamingClient
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_config_overrides_create_custom_streaming_client(): void
    {
        // Use reflection to test the private buildStreamingClient method
        $method = new \ReflectionMethod(HaoCode::class, 'buildStreamingClient');

        // No overrides → returns null (use container default)
        $defaultConfig = new HaoCodeConfig();
        $this->assertNull($method->invoke(null, $defaultConfig));

        // With apiKey override → returns custom StreamingClient
        $config = new HaoCodeConfig(apiKey: 'sk-custom-key-123');
        $client = $method->invoke(null, $config);
        $this->assertInstanceOf(StreamingClient::class, $client);

        // With model override → returns custom StreamingClient
        $config2 = new HaoCodeConfig(model: 'claude-opus-4-20250514');
        $client2 = $method->invoke(null, $config2);
        $this->assertInstanceOf(StreamingClient::class, $client2);

        // With baseUrl override → returns custom StreamingClient
        $config3 = new HaoCodeConfig(baseUrl: 'https://my-proxy.example.com');
        $client3 = $method->invoke(null, $config3);
        $this->assertInstanceOf(StreamingClient::class, $client3);

        // With maxTokens override → returns custom StreamingClient
        $config4 = new HaoCodeConfig(maxTokens: 8192);
        $client4 = $method->invoke(null, $config4);
        $this->assertInstanceOf(StreamingClient::class, $client4);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 20: SDK query works with default config (no overrides)
    // ──────────────────────────────────────────────────────────────

    public function test_sdk_query_with_default_config_uses_container_client(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Default client response.'),
        ]);

        chdir($this->projectDir);

        // Default config (no apiKey/baseUrl/model overrides) → uses container singleton
        $result = HaoCode::query('Hello', new HaoCodeConfig());

        $this->assertStringContainsString('Default client response', $result->text);
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
