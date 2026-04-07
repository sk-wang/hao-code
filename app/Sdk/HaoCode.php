<?php

namespace App\Sdk;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\AgentLoopFactory;
use App\Services\Api\StreamingClient;
use App\Services\Session\SessionManager;

/**
 * HaoCode SDK — programmatic access to the agent's capabilities.
 *
 * Six entry points covering the full spectrum from simple to advanced:
 *
 *   // 1. One-shot query
 *   $result = HaoCode::query('Explain this codebase');
 *   echo $result;        // Stringable
 *   echo $result->cost;  // plus metadata
 *
 *   // 2. Streaming messages
 *   foreach (HaoCode::stream('Build a REST API') as $msg) { ... }
 *
 *   // 3. Multi-turn conversation
 *   $conv = HaoCode::conversation();
 *   $conv->send('Create a User model');
 *
 *   // 4. Resume a previous session
 *   $conv = HaoCode::resume('20260407_abc123');
 *
 *   // 5. Structured output
 *   $data = HaoCode::structured('Classify this ticket', $schema);
 *   echo $data->category;
 *
 *   // 6. Custom tools
 *   HaoCode::query('Look up order #123', new HaoCodeConfig(
 *       tools: [new LookupOrderTool()],
 *   ));
 */
class HaoCode
{
    /**
     * Execute a one-shot query and return a QueryResult.
     *
     * QueryResult implements Stringable, so `echo HaoCode::query(...)` works.
     * But it also carries usage, cost, sessionId, and turnsUsed metadata.
     */
    public static function query(string $prompt, ?HaoCodeConfig $config = null): QueryResult
    {
        $config ??= new HaoCodeConfig();

        // Redirect to resume/continue if configured
        if ($config->sessionId !== null) {
            $conv = self::resume($config->sessionId, $config);
            return $conv->send($prompt);
        }
        if ($config->continueSession) {
            $conv = self::continueLatest($config->cwd, $config);
            return $conv->send($prompt);
        }

        $loop = self::createLoop($config);

        $response = $loop->run(
            userInput: $prompt,
            onTextDelta: $config->onText,
            onToolStart: $config->onToolStart,
            onToolComplete: $config->onToolComplete,
            onTurnStart: $config->onTurnStart,
        );

        return new QueryResult(
            text: $response,
            usage: self::extractUsage($loop),
            cost: $loop->getEstimatedCost(),
            sessionId: $loop->getSessionManager()->getSessionId(),
        );
    }

    /**
     * Execute a query and yield streaming Message objects.
     *
     * @return \Generator<int, Message>
     */
    public static function stream(string $prompt, ?HaoCodeConfig $config = null): \Generator
    {
        $config ??= new HaoCodeConfig();

        // Redirect to conversation stream if resuming
        if ($config->sessionId !== null) {
            return self::resume($config->sessionId, $config)->stream($prompt);
        }
        if ($config->continueSession) {
            return self::continueLatest($config->cwd, $config)->stream($prompt);
        }

        $loop = self::createLoop($config);

        $messages = [];

        $onText = function (string $delta) use (&$messages, $config) {
            $messages[] = Message::text($delta);
            if ($config->onText) {
                ($config->onText)($delta);
            }
        };

        $onToolStart = function (string $name, array $input) use (&$messages, $config) {
            $messages[] = Message::toolStart($name, $input);
            if ($config->onToolStart) {
                ($config->onToolStart)($name, $input);
            }
        };

        $onToolComplete = function (string $name, $result) use (&$messages, $config) {
            $messages[] = Message::toolResult($name, $result->output, $result->isError);
            if ($config->onToolComplete) {
                ($config->onToolComplete)($name, $result);
            }
        };

        try {
            $response = $loop->run(
                userInput: $prompt,
                onTextDelta: $onText,
                onToolStart: $onToolStart,
                onToolComplete: $onToolComplete,
                onTurnStart: $config->onTurnStart,
            );

            foreach ($messages as $msg) {
                yield $msg;
            }

            yield Message::result(
                text: $response,
                usage: self::extractUsage($loop),
                cost: $loop->getEstimatedCost(),
                sessionId: $loop->getSessionManager()->getSessionId(),
            );
        } catch (\Throwable $e) {
            yield Message::error($e->getMessage());
        }
    }

    /**
     * Create a multi-turn conversation.
     */
    public static function conversation(?HaoCodeConfig $config = null): Conversation
    {
        $config ??= new HaoCodeConfig();

        /** @var AgentLoopFactory $factory */
        $factory = app(AgentLoopFactory::class);

        return new Conversation($config, $factory, self::buildStreamingClient($config));
    }

    /**
     * Resume a previous session by ID.
     *
     * Returns a Conversation pre-loaded with the session's message history.
     *
     * @example
     *   $conv = HaoCode::resume('20260407_143022_a1b2c3d4');
     *   $conv->send('Continue where we left off');
     */
    public static function resume(string $sessionId, ?HaoCodeConfig $config = null): Conversation
    {
        $config ??= new HaoCodeConfig();

        /** @var AgentLoopFactory $factory */
        $factory = app(AgentLoopFactory::class);

        $conv = new Conversation($config, $factory, self::buildStreamingClient($config));
        $conv->loadSession($sessionId);

        return $conv;
    }

    /**
     * Continue the most recent session in the working directory.
     *
     * @example
     *   $conv = HaoCode::continueLatest();
     *   $conv->send('What were we working on?');
     */
    public static function continueLatest(?string $cwd = null, ?HaoCodeConfig $config = null): Conversation
    {
        $cwd ??= getcwd() ?: '/';

        /** @var SessionManager $sessionManager */
        $sessionManager = app(SessionManager::class);
        $sessionId = $sessionManager->findMostRecentSessionId($cwd);

        if ($sessionId === null) {
            throw new \RuntimeException("No previous session found in {$cwd}");
        }

        return self::resume($sessionId, $config);
    }

    /**
     * Execute a query and return structured (JSON) output.
     *
     * The agent is instructed to respond with JSON matching the given schema.
     * The result is parsed and wrapped in a StructuredResult with property/array access.
     *
     * @param  string  $prompt  The task or question.
     * @param  array  $jsonSchema  JSON schema defining the expected output structure.
     * @param  HaoCodeConfig|null  $config  Optional configuration.
     *
     * @example
     *   $result = HaoCode::structured('Classify this ticket: "My order is late"', [
     *       'type' => 'object',
     *       'properties' => [
     *           'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical']],
     *           'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
     *       ],
     *       'required' => ['category', 'priority'],
     *   ]);
     *   echo $result->category; // 'shipping'
     */
    public static function structured(string $prompt, array $jsonSchema, ?HaoCodeConfig $config = null): StructuredResult
    {
        $schemaJson = json_encode($jsonSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $structuredPrompt = $prompt . "\n\n" .
            "IMPORTANT: You MUST respond with ONLY a valid JSON object matching this schema. " .
            "No markdown fences, no explanation, no extra text — just the raw JSON.\n\n" .
            "Schema:\n" . $schemaJson;

        $queryResult = self::query($structuredPrompt, $config);
        $text = trim($queryResult->text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "Failed to parse structured response as JSON.\nRaw response: " . mb_substr($text, 0, 500)
            );
        }

        return new StructuredResult($decoded, $queryResult->text, $queryResult);
    }

    /**
     * Create a configured AgentLoop from config.
     */
    private static function createLoop(HaoCodeConfig $config): AgentLoop
    {
        // Apply system prompt / permission mode overrides to SettingsManager
        // before the factory creates ContextBuilder and PermissionChecker
        self::applySettingsOverrides($config);

        /** @var AgentLoopFactory $factory */
        $factory = app(AgentLoopFactory::class);

        // Build a custom StreamingClient when SDK config overrides API settings
        $customClient = self::buildStreamingClient($config);

        $loop = $factory->createIsolated(
            toolFilter: $config->toolFilter(),
            workingDirectory: $config->cwd,
            additionalTools: $config->tools,
            streamingClient: $customClient,
        );

        $loop->setPermissionPromptHandler(fn () => true);
        $loop->setMaxTurns($config->maxTurns);

        // Wire cost budget
        if ($config->maxBudgetUsd !== null) {
            $loop->getCostTracker()->setThresholds(
                warn: $config->maxBudgetUsd * 0.8,
                stop: $config->maxBudgetUsd,
            );
        }

        // Wire abort controller
        if ($config->abortController !== null) {
            $config->abortController->onAbort(fn () => $loop->abort());
        }

        return $loop;
    }

    /**
     * Push SDK config overrides into SettingsManager so that
     * ContextBuilder and PermissionChecker pick them up.
     */
    private static function applySettingsOverrides(HaoCodeConfig $config): void
    {
        $settings = app(\App\Services\Settings\SettingsManager::class);

        if ($config->systemPrompt !== null) {
            $settings->set('system_prompt', $config->systemPrompt);
        }

        if ($config->appendSystemPrompt !== null) {
            $settings->set('append_system_prompt', $config->appendSystemPrompt);
        }

        if ($config->permissionMode !== 'bypass_permissions') {
            $settings->set('permission_mode', $config->permissionMode);
        }
    }

    /**
     * Build a standalone StreamingClient when SDK config overrides API settings.
     *
     * Returns null if no overrides are present (use container default).
     */
    private static function buildStreamingClient(HaoCodeConfig $config): ?StreamingClient
    {
        if ($config->apiKey === null
            && $config->baseUrl === null
            && $config->model === null
            && $config->maxTokens === null) {
            return null;
        }

        return new StreamingClient(
            apiKey: $config->apiKey ?? config('haocode.api_key', ''),
            model: $config->model ?? config('haocode.model', 'claude-sonnet-4-20250514'),
            baseUrl: $config->baseUrl ?? config('haocode.api_base_url', 'https://api.anthropic.com'),
            maxTokens: $config->maxTokens ?? (int) config('haocode.max_tokens', 16384),
            thinkingEnabled: $config->thinkingEnabled,
            thinkingBudget: $config->thinkingBudget,
            settingsManager: null, // SDK controls config, bypass SettingsManager
            idleTimeoutSeconds: (int) config('haocode.api_stream_idle_timeout', 60),
            streamPollTimeoutSeconds: (float) config('haocode.api_stream_poll_timeout', 1.0),
        );
    }

    private static function extractUsage(AgentLoop $loop): array
    {
        return [
            'input_tokens' => $loop->getTotalInputTokens(),
            'output_tokens' => $loop->getTotalOutputTokens(),
            'cache_creation_tokens' => $loop->getCacheCreationTokens(),
            'cache_read_tokens' => $loop->getCacheReadTokens(),
        ];
    }
}
