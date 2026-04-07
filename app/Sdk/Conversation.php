<?php

namespace App\Sdk;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\AgentLoopFactory;
use App\Services\Session\SessionManager;

/**
 * Multi-turn conversation handle.
 *
 * Maintains a persistent AgentLoop so subsequent send() calls
 * share the same message history and session.
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
            additionalTools: $config->tools,
        );

        $this->loop->setPermissionPromptHandler(fn () => true);
        $this->loop->setMaxTurns($config->maxTurns);

        if ($config->abortController !== null) {
            $config->abortController->onAbort(fn () => $this->loop->abort());
        }
    }

    /**
     * Send a message and get the agent's response as a QueryResult.
     */
    public function send(string $prompt): QueryResult
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        $this->turnCount++;

        $response = $this->loop->run(
            userInput: $prompt,
            onTextDelta: $this->config->onText,
            onToolStart: $this->config->onToolStart,
            onToolComplete: $this->config->onToolComplete,
            onTurnStart: $this->config->onTurnStart,
        );

        return new QueryResult(
            text: $response,
            usage: [
                'input_tokens' => $this->loop->getTotalInputTokens(),
                'output_tokens' => $this->loop->getTotalOutputTokens(),
                'cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
                'cache_read_tokens' => $this->loop->getCacheReadTokens(),
            ],
            cost: $this->loop->getEstimatedCost(),
            sessionId: $this->loop->getSessionManager()->getSessionId(),
            turnsUsed: $this->turnCount,
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

            foreach ($messages as $msg) {
                yield $msg;
            }

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
     * Load a previous session's message history into this conversation.
     */
    public function loadSession(string $sessionId): void
    {
        /** @var SessionManager $sessionManager */
        $sessionManager = app(SessionManager::class);
        $entries = $sessionManager->loadSession($sessionId);

        if ($entries === null || $entries === []) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        $history = $this->loop->getMessageHistory();

        foreach ($entries as $entry) {
            $type = $entry['type'] ?? null;

            if ($type === 'user_message') {
                $history->addUserMessage($entry['content'] ?? '');
            } elseif ($type === 'assistant_turn' && isset($entry['message'])) {
                $history->addAssistantMessage($entry['message']);
                if (!empty($entry['tool_results'])) {
                    $history->addToolResultMessage($entry['tool_results']);
                }
            }
        }

        // Point session manager to the loaded session
        $this->loop->getSessionManager()->switchToSession($sessionId);
    }

    public function getLoop(): AgentLoop
    {
        return $this->loop;
    }

    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    public function getSessionId(): ?string
    {
        return $this->loop->getSessionManager()->getSessionId();
    }

    public function getCost(): float
    {
        return $this->loop->getEstimatedCost();
    }

    public function abort(): void
    {
        $this->loop->abort();
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
