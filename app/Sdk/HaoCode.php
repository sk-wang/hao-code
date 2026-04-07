<?php

namespace App\Sdk;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\AgentLoopFactory;

/**
 * HaoCode SDK — programmatic access to the agent's capabilities.
 *
 * Inspired by Claude Agent SDK's `query()` function. Provides three
 * levels of abstraction:
 *
 *   // 1. One-shot query (simplest)
 *   $result = HaoCode::query('Explain this codebase');
 *
 *   // 2. Query with streaming messages
 *   foreach (HaoCode::stream('Build a REST API') as $msg) {
 *       if ($msg->type === 'text') echo $msg->text;
 *   }
 *
 *   // 3. Multi-turn conversation
 *   $conv = HaoCode::conversation();
 *   $conv->send('Create a User model');
 *   $conv->send('Add email validation');
 *
 * All methods accept an optional HaoCodeConfig for customization.
 */
class HaoCode
{
    /**
     * Execute a one-shot query and return the final response text.
     *
     * This is the simplest entry point — equivalent to `--print` mode.
     *
     * @param  string  $prompt  The task or question for the agent.
     * @param  HaoCodeConfig|null  $config  Optional configuration.
     * @return string  The agent's final response.
     *
     * @throws \Throwable  If the agent encounters an unrecoverable error.
     *
     * @example
     *   $response = HaoCode::query('What files are in this directory?');
     *
     * @example
     *   $response = HaoCode::query('Fix the bug in auth.php', new HaoCodeConfig(
     *       allowedTools: ['Read', 'Edit', 'Bash'],
     *       maxTurns: 10,
     *   ));
     */
    public static function query(string $prompt, ?HaoCodeConfig $config = null): string
    {
        $config ??= new HaoCodeConfig();
        $loop = self::createLoop($config);

        return $loop->run(
            userInput: $prompt,
            onTextDelta: $config->onText,
            onToolStart: $config->onToolStart,
            onToolComplete: $config->onToolComplete,
            onTurnStart: $config->onTurnStart,
        );
    }

    /**
     * Execute a query and yield streaming Message objects.
     *
     * Returns a Generator of typed Message objects as they arrive:
     * - Message::text($delta)       — streaming text chunk
     * - Message::toolStart(...)     — tool execution began
     * - Message::toolResult(...)    — tool execution completed
     * - Message::result(...)        — final result with usage/cost
     * - Message::error(...)         — an error occurred
     *
     * @param  string  $prompt  The task or question.
     * @param  HaoCodeConfig|null  $config  Optional configuration.
     * @return \Generator<int, Message>
     *
     * @example
     *   foreach (HaoCode::stream('Build a REST API') as $msg) {
     *       match ($msg->type) {
     *           'text' => print($msg->text),
     *           'tool_start' => print("Running {$msg->toolName}..."),
     *           'result' => print("\nCost: \${$msg->cost}"),
     *           default => null,
     *       };
     *   }
     */
    public static function stream(string $prompt, ?HaoCodeConfig $config = null): \Generator
    {
        $config ??= new HaoCodeConfig();
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
                usage: [
                    'input_tokens' => $loop->getTotalInputTokens(),
                    'output_tokens' => $loop->getTotalOutputTokens(),
                    'cache_creation_tokens' => $loop->getCacheCreationTokens(),
                    'cache_read_tokens' => $loop->getCacheReadTokens(),
                ],
                cost: $loop->getEstimatedCost(),
                sessionId: $loop->getSessionManager()->getSessionId(),
            );
        } catch (\Throwable $e) {
            yield Message::error($e->getMessage());
        }
    }

    /**
     * Create a multi-turn conversation.
     *
     * Returns a Conversation object that maintains persistent context
     * across multiple send() calls — like Python's ClaudeSDKClient.
     *
     * @param  HaoCodeConfig|null  $config  Optional configuration.
     * @return Conversation
     *
     * @example
     *   $conv = HaoCode::conversation(new HaoCodeConfig(
     *       allowedTools: ['Read', 'Write', 'Edit', 'Bash'],
     *   ));
     *   $conv->send('Create a User model with name and email');
     *   $conv->send('Add password hashing to the model');
     *   $conv->send('Write a test for the User model');
     *   $conv->close();
     */
    public static function conversation(?HaoCodeConfig $config = null): Conversation
    {
        $config ??= new HaoCodeConfig();

        /** @var AgentLoopFactory $factory */
        $factory = app(AgentLoopFactory::class);

        return new Conversation($config, $factory);
    }

    /**
     * Create a configured AgentLoop for one-shot usage.
     */
    private static function createLoop(HaoCodeConfig $config): AgentLoop
    {
        /** @var AgentLoopFactory $factory */
        $factory = app(AgentLoopFactory::class);

        $loop = $factory->createIsolated(
            toolFilter: $config->toolFilter(),
            workingDirectory: $config->cwd,
        );

        $loop->setPermissionPromptHandler(fn () => true);
        $loop->setMaxTurns($config->maxTurns);

        return $loop;
    }
}
