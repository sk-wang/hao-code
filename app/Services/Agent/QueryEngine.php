<?php

namespace App\Services\Agent;

use App\Services\Api\StreamingClient;
use App\Tools\ToolRegistry;

class QueryEngine
{
    public function __construct(
        private readonly StreamingClient $streamingClient,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    /**
     * Execute a query against the Anthropic API with streaming.
     */
    public function query(
        array $systemPrompt,
        array $messages,
        ?callable $onTextDelta = null,
        ?callable $onToolBlockComplete = null,
        ?callable $onThinkingDelta = null,
        ?callable $shouldAbort = null,
    ): StreamProcessor {
        $tools = $this->toolRegistry->toApiTools();
        $processor = new StreamProcessor();

        if ($onToolBlockComplete) {
            $processor->setOnToolBlockComplete($onToolBlockComplete);
        }
        if ($onThinkingDelta) {
            $processor->setOnThinkingDelta($onThinkingDelta);
        }

        foreach ($this->streamingClient->streamMessages(
            systemPrompt: $systemPrompt,
            messages: $messages,
            tools: $tools,
            shouldAbort: $shouldAbort,
        ) as $event) {
            $processor->processEvent($event);

            // Stream text deltas to the caller for real-time display
            if ($onTextDelta && $event->type === 'content_block_delta') {
                $delta = $event->data['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta' && isset($delta['text'])) {
                    $onTextDelta($delta['text']);
                }
            }
        }

        return $processor;
    }
}
