<?php

namespace App\Sdk;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\AgentLoopFactory;
use App\Services\Api\StreamingClient;
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
        ?StreamingClient $streamingClient = null,
    ) {
        $this->loop = $factory->createIsolated(
            toolFilter: $config->toolFilter(),
            workingDirectory: $config->cwd,
            additionalTools: $config->tools,
            streamingClient: $streamingClient,
        );

        $this->loop->setPermissionPromptHandler(fn () => true);
        $this->loop->setMaxTurns($config->maxTurns);

        if ($config->maxBudgetUsd !== null) {
            $this->loop->getCostTracker()->setThresholds(
                warn: $config->maxBudgetUsd * 0.8,
                stop: $config->maxBudgetUsd,
            );
        }

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
     * Send a message and yield streaming Message objects in real time.
     *
     * Uses a PHP Fiber so each text delta / tool event is yielded to the caller
     * as it arrives from the API, rather than being buffered until the full
     * response completes.
     *
     * @return \Generator<int, Message>
     */
    public function stream(string $prompt): \Generator
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }

        $this->turnCount++;

        $queue = new \SplQueue();

        // These callbacks are exclusively invoked from within the Fiber below.
        // Fiber::getCurrent()?->suspend() uses the nullable operator as a defensive
        // guard; in practice getCurrent() will always return the active Fiber here.
        $onText = function (string $delta) use ($queue): void {
            $queue->enqueue(Message::text($delta));
            if ($this->config->onText) {
                ($this->config->onText)($delta);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onToolStart = function (string $name, array $input) use ($queue): void {
            $queue->enqueue(Message::toolStart($name, $input));
            if ($this->config->onToolStart) {
                ($this->config->onToolStart)($name, $input);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $onToolComplete = function (string $name, $result) use ($queue): void {
            $queue->enqueue(Message::toolResult($name, $result->output, $result->isError));
            if ($this->config->onToolComplete) {
                ($this->config->onToolComplete)($name, $result);
            }
            \Fiber::getCurrent()?->suspend();
        };

        $response = null;
        $thrownException = null;

        $fiber = new \Fiber(function () use ($prompt, $onText, $onToolStart, $onToolComplete, &$response, &$thrownException): void {
            try {
                $response = $this->loop->run(
                    userInput: $prompt,
                    onTextDelta: $onText,
                    onToolStart: $onToolStart,
                    onToolComplete: $onToolComplete,
                    onTurnStart: $this->config->onTurnStart,
                );
            } catch (\Throwable $e) {
                $thrownException = $e;
            }
        });

        $fiber->start();

        while (! $fiber->isTerminated()) {
            while (! $queue->isEmpty()) {
                yield $queue->dequeue();
            }
            if (! $fiber->isTerminated()) {
                $fiber->resume();
            }
        }

        // Drain any messages enqueued before the fiber's final termination
        while (! $queue->isEmpty()) {
            yield $queue->dequeue();
        }

        if ($thrownException !== null) {
            yield Message::error($thrownException->getMessage());

            return;
        }

        yield Message::result(
            text: $response ?? '',
            usage: [
                'input_tokens' => $this->loop->getTotalInputTokens(),
                'output_tokens' => $this->loop->getTotalOutputTokens(),
                'cache_creation_tokens' => $this->loop->getCacheCreationTokens(),
                'cache_read_tokens' => $this->loop->getCacheReadTokens(),
            ],
            cost: $this->loop->getEstimatedCost(),
            sessionId: $this->loop->getSessionManager()->getSessionId(),
        );
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
                if (! empty($entry['tool_results'])) {
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
