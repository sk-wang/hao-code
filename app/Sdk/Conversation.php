<?php

namespace App\Sdk;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\AgentLoopFactory;

/**
 * Multi-turn conversation handle.
 *
 * Maintains a persistent AgentLoop so subsequent send() calls
 * share the same message history and session, like Python's ClaudeSDKClient.
 *
 * Usage:
 *   $conv = HaoCode::conversation($config);
 *   $r1 = $conv->send('Create a PHP class for users');
 *   $r2 = $conv->send('Add an email validation method');
 *   $conv->close();
 */
class Conversation
{
    private AgentLoop $loop;

    private bool $closed = false;

    private int $turnCount = 0;

    public function __construct(
        private readonly HaoCodeConfig $config,
        AgentLoopFactory $factory,
    ) {
        $this->loop = $factory->createIsolated(
            toolFilter: $config->toolFilter(),
            workingDirectory: $config->cwd,
        );

        $this->loop->setPermissionPromptHandler(fn () => true);
        $this->loop->setMaxTurns($config->maxTurns);
    }

    /**
     * Send a message and get the agent's response.
     *
     * @return string The agent's final text response for this turn.
     */
    public function send(string $prompt): string
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        $this->turnCount++;

        return $this->loop->run(
            userInput: $prompt,
            onTextDelta: $this->config->onText,
            onToolStart: $this->config->onToolStart,
            onToolComplete: $this->config->onToolComplete,
            onTurnStart: $this->config->onTurnStart,
        );
    }

    /**
     * Send a message and yield streaming Message objects.
     *
     * @return \Generator<int, Message>
     */
    public function stream(string $prompt): \Generator
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        $this->turnCount++;

        $messages = [];

        $onText = function (string $delta) use (&$messages) {
            $messages[] = Message::text($delta);
            if ($this->config->onText) {
                ($this->config->onText)($delta);
            }
        };

        $onToolStart = function (string $name, array $input) use (&$messages) {
            $messages[] = Message::toolStart($name, $input);
            if ($this->config->onToolStart) {
                ($this->config->onToolStart)($name, $input);
            }
        };

        $onToolComplete = function (string $name, $result) use (&$messages) {
            $messages[] = Message::toolResult($name, $result->output, $result->isError);
            if ($this->config->onToolComplete) {
                ($this->config->onToolComplete)($name, $result);
            }
        };

        try {
            $response = $this->loop->run(
                userInput: $prompt,
                onTextDelta: $onText,
                onToolStart: $onToolStart,
                onToolComplete: $onToolComplete,
                onTurnStart: $this->config->onTurnStart,
            );

            // Yield all collected messages
            foreach ($messages as $msg) {
                yield $msg;
            }

            // Yield final result
            yield Message::result(
                text: $response,
                usage: [
                    'input_tokens' => $this->loop->getTotalInputTokens(),
                    'output_tokens' => $this->loop->getTotalOutputTokens(),
                    'cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
                    'cache_read_tokens' => $this->loop->getCacheReadTokens(),
                ],
                cost: $this->loop->getEstimatedCost(),
                sessionId: $this->loop->getSessionManager()->getSessionId(),
            );
        } catch (\Throwable $e) {
            yield Message::error($e->getMessage());
        }
    }

    /**
     * Get the underlying AgentLoop for advanced usage.
     */
    public function getLoop(): AgentLoop
    {
        return $this->loop;
    }

    /**
     * Get total turns executed in this conversation.
     */
    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    /**
     * Abort the current run.
     */
    public function abort(): void
    {
        $this->loop->abort();
    }

    /**
     * Close the conversation. No further sends allowed.
     */
    public function close(): void
    {
        $this->closed = true;
    }
}
