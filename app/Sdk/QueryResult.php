<?php

namespace App\Sdk;

/**
 * Result of an SDK query — carries the response text plus usage metadata.
 *
 * Implements Stringable so it works as a drop-in replacement for string:
 *   echo HaoCode::query('...');  // still works
 *   $result->cost;               // but also carries metadata
 */
class QueryResult implements \Stringable
{
    public function __construct(
        public readonly string $text,
        public readonly array $usage,
        public readonly float $cost,
        public readonly ?string $sessionId = null,
        public readonly int $turnsUsed = 0,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }

    public function inputTokens(): int
    {
        return $this->usage['input_tokens'] ?? 0;
    }

    public function outputTokens(): int
    {
        return $this->usage['output_tokens'] ?? 0;
    }
}
